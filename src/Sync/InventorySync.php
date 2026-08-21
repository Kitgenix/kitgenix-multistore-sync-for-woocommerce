<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Repository\InventoryRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Revision;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class InventorySync {
    private array $before=array();
    /** @var array<int,bool> Stock changes currently flowing through wc_update_product_stock(). */
    private array $native_stock_update=array();
    /** @var array<int,bool> Product IDs already handled by WooCommerce's standard stock hook in this save cycle. */
    private array $native_stock_handled=array();
    /** @var array<int,string> Last fallback direct-save stock fingerprint handled in this request. */
    private array $direct_save_fingerprints=array();
    public function __construct(private InventoryRepository$inventory,private MappingRepository$mappings,private Dispatcher$dispatcher,private StoreRepository$stores,private Client$client,private Logger$logger,private Settings$settings){}
    public function hooks():void{add_action('woocommerce_product_before_set_stock',array($this,'before'),10,1);add_action('woocommerce_variation_before_set_stock',array($this,'before'),10,1);add_action('woocommerce_product_set_stock',array($this,'after'),20,1);add_action('woocommerce_variation_set_stock',array($this,'after'),20,1);add_action('woocommerce_product_object_updated_props',array($this,'after_object_save'),30,2);add_action('woocommerce_check_cart_items',array($this,'validate_cart_inventory'),50);}
    public function before(\WC_Product$product):void{if(Context::is_remote()||'yes'!==$this->settings->get('sync_inventory','yes'))return;$id=$product->get_id();$this->native_stock_update[$id]=true;$raw=get_post_meta($id,'_stock',true);$this->before[$id]=(''===$raw||null===$raw)?null:(float)$raw;}
    public function after(\WC_Product$product):void{if(Context::is_remote()||'yes'!==$this->settings->get('sync_inventory','yes'))return;$id=$product->get_id();$old=$this->before[$id]??null;unset($this->before[$id],$this->native_stock_update[$id]);$this->native_stock_handled[$id]=true;$new=$product->get_stock_quantity();if(null===$new)return;$delta=(float)$new-(float)($old??$new);if(abs($delta)<0.00001&&null!==$old)return;$type=$product->is_type('variation')?'variation':'product';$network=$this->mappings->network_id($type,$id,(string)$product->get_sku());$base=array('network_id'=>$network,'sku'=>$product->get_sku(),'is_variation'=>$product->is_type('variation'),'stock_status'=>$product->get_stock_status(),'reference'=>$this->stock_reference());
        if($this->settings->is_control()){
            $status=(float)$new>0?'instock':($product->backorders_allowed()?'onbackorder':'outofstock');
            if('square'===$base['reference']){
                // Square has already calculated the final stock count. Preserve it
                // as an absolute authoritative SET rather than re-applying a delta
                // to potentially stale network state.
                $result=$this->inventory->apply_set($network,(float)$new,$status,Uuid::site(),Uuid::make(),'square_inventory_set','square');
                $quantity=(float)$result['quantity'];$revision=(int)$result['revision'];$status=(string)$result['stock_status'];
            }
            elseif(null!==$old){$result=$this->inventory->apply_delta($network,$delta,(float)$old,$product->get_stock_status(),Uuid::site(),Uuid::make(),'local_stock_change',$base['reference']);$quantity=(float)$result['quantity'];$revision=(int)$result['revision'];$status=$quantity>0?'instock':($product->backorders_allowed()?'onbackorder':'outofstock');if($status!==(string)$result['stock_status'])$this->inventory->set($network,$quantity,$status,Uuid::site(),$revision);}
            else{$revision=Revision::next();$quantity=(float)$new;$this->inventory->set($network,$quantity,$status,Uuid::site(),$revision);}
            $payload=array_merge($base,array('quantity'=>$quantity,'stock_status'=>$status,'revision'=>$revision));$this->dispatcher->dispatch('inventory_set',$type,$network,$payload,array(),Uuid::site(),null,$revision);do_action('kitgenix_multistore/inventory_authoritative_changed',$network,$quantity,$status);
        }
        else{$payload=array_merge($base,array('delta'=>$delta,'resulting_quantity'=>(float)$new));$this->dispatcher->dispatch('inventory_delta',$type,$network,$payload);}
    }
    /**
     * Catch inventory changes made via WC_Product setters followed by save().
     *
     * WooCommerce Square 5.x pulls inventory by calling set_stock_quantity()
     * and save() directly. Current WooCommerce product data stores emit the
     * normal *_set_stock hooks for that save, which is our primary path. This
     * lower-level updated-props listener is retained as a safe fallback for
     * integrations/data stores that persist stock-related props differently.
     *
     * On the Control Store a direct save is treated as an authoritative SET,
     * not a delta: Square has already resolved the final quantity and we fan
     * that exact quantity out to every connected store.
     *
     * @param \WC_Product $product       Saved WooCommerce product/variation.
     * @param array        $updated_props Properties persisted by WooCommerce.
     */
    public function after_object_save(\WC_Product$product,array$updated_props):void{
        if(Context::is_remote()||'yes'!==$this->settings->get('sync_inventory','yes')||!$this->settings->is_control())return;
        $id=$product->get_id();
        // wc_update_product_stock() already has before/after hooks with delta-safe
        // handling. Ignore its internal product->save() to prevent a duplicate SET.
        if(!empty($this->native_stock_update[$id]))return;
        // WooCommerce fires *_set_stock before product_object_updated_props for
        // ordinary WC_Product stock saves. after() already dispatched that
        // authoritative change, so consume the marker and do not dispatch twice.
        if(!empty($this->native_stock_handled[$id])){unset($this->native_stock_handled[$id]);return;}
        $stock_props=array_intersect(array_map('strval',$updated_props),array('stock_quantity','stock_status','manage_stock'));
        if(!$stock_props)return;
        if(!$product->get_manage_stock()||null===$product->get_stock_quantity()){
            // Square can also disable tracking. A subsequent product sync will
            // carry manage_stock/status; there is no numeric authoritative
            // quantity to put in the inventory ledger in that state.
            $this->logger->info('External inventory save changed stock tracking without a numeric quantity.',array('product_id'=>$id,'source'=>$this->direct_save_source(),'updated_props'=>array_values($stock_props)));
            return;
        }
        $quantity=(float)$product->get_stock_quantity();
        $status=$quantity>0?'instock':($product->backorders_allowed()?'onbackorder':'outofstock');
        $fingerprint=$quantity.'|'.$status.'|'.implode(',',array_values($stock_props));
        if(($this->direct_save_fingerprints[$id]??'')===$fingerprint)return;
        $this->direct_save_fingerprints[$id]=$fingerprint;

        $type=$product->is_type('variation')?'variation':'product';
        $network=$this->mappings->network_id($type,$id,(string)$product->get_sku());
        $source=$this->direct_save_source();
        $result=$this->inventory->apply_set($network,$quantity,$status,Uuid::site(),Uuid::make(),'external_inventory_set',$source);
        $quantity=(float)$result['quantity'];$revision=(int)$result['revision'];$status=(string)$result['stock_status'];
        $payload=array(
            'network_id'=>$network,
            'sku'=>$product->get_sku(),
            'is_variation'=>$product->is_type('variation'),
            'quantity'=>$quantity,
            'stock_status'=>$status,
            'revision'=>$revision,
            'reference'=>$source,
        );
        $this->dispatcher->dispatch('inventory_set',$type,$network,$payload,array(),Uuid::site(),null,$revision);
        $this->logger->info('External inventory source updated authoritative network stock.',array('product_id'=>$id,'network_id'=>$network,'quantity'=>$quantity,'source'=>$source,'revision'=>$revision));
        do_action('kitgenix_multistore/inventory_authoritative_changed',$network,$quantity,$status);
        do_action('kitgenix_multistore/external_inventory_captured',$product,$quantity,$source,$revision);
    }

    private function direct_save_source():string{
        if(defined('DOING_SQUARE_SYNC')&&true===DOING_SQUARE_SYNC)return'square';
        if(function_exists('wc_square'))return'woocommerce_direct_save';
        return'external_direct_save';
    }

    public function seed_product(\WC_Product$product):void{if(!$this->settings->is_control())return;$items=array($product);if($product instanceof \WC_Product_Variable){foreach($product->get_children()as$id){$v=wc_get_product($id);if($v)$items[]=$v;}}foreach($items as$p){if(!$p->get_manage_stock()||null===$p->get_stock_quantity())continue;$type=$p->is_type('variation')?'variation':'product';$network=$this->mappings->network_id($type,$p->get_id(),(string)$p->get_sku());if(!$this->inventory->get($network))$this->inventory->set($network,(float)$p->get_stock_quantity(),$p->get_stock_status(),Uuid::site());}}
    public function receive_delta(array$p,string$source,string$event_uuid):array|\WP_Error{if(!$this->settings->is_control())return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_inventory_topology','Inventory deltas must be applied by the control store.');$network=sanitize_text_field((string)($p['network_id']??''));$local=$this->mappings->local_id($network,!empty($p['is_variation'])?'variation':'product');if(!$local&&!empty($p['sku']))$local=(int)wc_get_product_id_by_sku(wc_clean($p['sku']));$product=$local?wc_get_product($local):false;if(!$product)return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_inventory_mapping','Inventory mapping is missing.',array('status'=>409));$delta=(float)($p['delta']??0);$base=$product->get_stock_quantity();$result=$this->inventory->apply_delta($network,$delta,null===$base?0:(float)$base,(string)($p['stock_status']??$product->get_stock_status()),$source,$event_uuid,'stock_change',(string)($p['reference']??''));$authoritative_status=(float)$result['quantity']>0?'instock':($product->backorders_allowed()?'onbackorder':'outofstock');if($authoritative_status!==$result['stock_status']){$this->inventory->set($network,(float)$result['quantity'],$authoritative_status,$source,(int)$result['revision']);$result['stock_status']=$authoritative_status;}Context::remote(function()use($product,$result){$product->set_manage_stock(true);$product->set_stock_quantity($result['quantity']);$product->set_stock_status($result['stock_status']);$product->save();});$targets=$this->dispatcher->targets_for('');$payload=array('network_id'=>$network,'sku'=>$product->get_sku(),'is_variation'=>$product->is_type('variation'),'quantity'=>(float)$result['quantity'],'stock_status'=>$result['stock_status'],'revision'=>(int)$result['revision']);$this->dispatcher->dispatch('inventory_set',$product->is_type('variation')?'variation':'product',$network,$payload,$targets,$source,$event_uuid,(int)$result['revision']);do_action('kitgenix_multistore/inventory_authoritative_changed',$network,(float)$result['quantity'],$result['stock_status']);return array('local_id'=>$product->get_id(),'quantity'=>(float)$result['quantity'],'revision'=>(int)$result['revision']);}
    public function receive_set(array$p,int$event_revision):array|\WP_Error{$network=sanitize_text_field((string)($p['network_id']??''));$type=!empty($p['is_variation'])?'variation':'product';$local=$this->mappings->local_id($network,$type);if(!$local&&!empty($p['sku']))$local=(int)wc_get_product_id_by_sku(wc_clean($p['sku']));$product=$local?wc_get_product($local):false;if(!$product)return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_inventory_mapping','Inventory mapping is missing.',array('status'=>409));$revision=max($event_revision,(int)($p['revision']??0));$current=$this->inventory->get($network);if($current&&(int)$current['revision']>$revision)return array('local_id'=>$product->get_id(),'skipped'=>'older_revision');Context::remote(function()use($product,$p){$product->set_manage_stock(true);$product->set_stock_quantity(wc_stock_amount($p['quantity']??0));$product->set_stock_status((string)($p['stock_status']??'instock'));$product->save();});$this->inventory->set($network,(float)($p['quantity']??0),(string)($p['stock_status']??'instock'),Uuid::site(),$revision);return array('local_id'=>$product->get_id(),'quantity'=>(float)($p['quantity']??0));}

    public function validate_cart_inventory():void{
        if(Context::is_remote()||!$this->settings->is_connected()||'yes'!==$this->settings->get('sync_inventory','yes')||'yes'!==$this->settings->get('checkout_inventory_validation','yes')||!function_exists('WC')||!WC()->cart)return;
        $control=$this->stores->control();if(!$control)return;$items=array();
        foreach(WC()->cart->get_cart() as $cart_item){$product=$cart_item['data']??null;if(!$product instanceof \WC_Product||!$product->managing_stock())continue;$managed_id=method_exists($product,'get_stock_managed_by_id')?(int)$product->get_stock_managed_by_id():$product->get_id();$managed=wc_get_product($managed_id);if(!$managed)continue;$type=$managed->is_type('variation')?'variation':'product';$network=$this->mappings->network_id($type,$managed->get_id(),(string)$managed->get_sku());if(!isset($items[$network]))$items[$network]=0;$items[$network]+=(float)($cart_item['quantity']??0);}
        if(!$items)return;$payload=array('items'=>array_map(static fn($network,$qty)=>array('network_id'=>$network,'quantity'=>$qty),array_keys($items),array_values($items)));$r=$this->client->request($control['uuid'],'POST','/inventory/availability',$payload);
        if(is_wp_error($r)){ $this->logger->warning('Authoritative checkout inventory validation failed.',array('error'=>$r->get_error_message())); if('yes'===$this->settings->get('inventory_fail_closed','no'))wc_add_notice(__('We could not verify network stock availability. Please try checkout again.','kitgenix-multistore'),'error'); return; }
        foreach((array)($r['unavailable']??array()) as $row){$name=sanitize_text_field((string)($row['name']??__('an item','kitgenix-multistore')));$available=(float)($row['available']??0);wc_add_notice(sprintf(__('Only %1$s of %2$s is currently available across the store network.','kitgenix-multistore'),wc_format_localized_decimal($available),$name),'error');}
    }
    private function stock_reference():string{if(defined('DOING_SQUARE_SYNC')&&true===DOING_SQUARE_SYNC)return'square';if(function_exists('is_admin')&&is_admin())return'admin';return function_exists('WC')&&WC()&&WC()->session?'checkout_or_frontend':'system';}
}
