<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\EventRepository;
use Kitgenix\MultiStore\Repository\ConflictRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;

final class EventProcessor {
    public function __construct(private EventRepository$events,private ConflictRepository$conflicts,private StoreRepository$stores,private ProductApplier$products,private InventorySync$inventory,private OrderSync$orders,private CouponSync$coupons,private CustomerSync$customers,private ReviewSync$reviews,private ProductSync$product_sync,private Dispatcher$dispatcher,private Settings$settings,private Logger$logger){}
    public function receive(array$event,string$authenticated_source):array|\WP_Error{
        foreach(array('event_uuid','entity_type','network_id','event_type','source_store_uuid','payload')as$k){if(!isset($event[$k]))return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_payload','Malformed sync event.',array('status'=>400));}
        if((string)$event['source_store_uuid']!==$authenticated_source)return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_source','Authenticated store does not match event source.',array('status'=>403));
        $type=sanitize_key((string)$event['event_type']);$authorized=$this->authorize_event($type,$authenticated_source);if(is_wp_error($authorized))return$authorized;
        $uuid=sanitize_text_field((string)$event['event_uuid']);$existing=$this->events->get($uuid);if($existing&&'completed'===$existing['status']){$map=new \Kitgenix\MultiStore\Repository\MappingRepository();$local=$map->local_id((string)$event['network_id'],(string)$event['entity_type']);$obj=$local&&in_array((string)$event['entity_type'],array('product','variation'),true)?wc_get_product($local):false;return array('status'=>'duplicate','duplicate'=>true,'local_id'=>$local,'object_key'=>$obj?$obj->get_sku():'');}if($existing&&'failed'!==$existing['status'])return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_in_progress','Event is already being processed.',array('status'=>409));
        if(!$existing&&!$this->events->record_received($event))return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_race','Event was received concurrently; retry later.',array('status'=>409));if($existing)$this->events->mark_event($uuid,'received');
        $payload=(array)$event['payload'];$revision=(int)($event['revision']??0);$result=null;
        try{
            $result=match($type){
                'product_upsert'=>$this->products->apply($payload,$revision),
                'product_trash'=>$this->trash_product((string)$event['network_id']),
                'inventory_delta'=>$this->inventory->receive_delta($payload,$authenticated_source,$uuid),
                'inventory_set'=>$this->inventory->receive_set($payload,$revision),
                'order_upsert'=>$this->orders->receive($payload,$authenticated_source),
                'coupon_upsert'=>$this->coupons->receive($payload,$revision),
                'coupon_trash'=>$this->coupons->trash((string)$event['network_id']),
                'customer_upsert'=>$this->customers->receive($payload,$authenticated_source),
                'review_upsert'=>$this->reviews->receive_upsert($payload,$authenticated_source),
                'review_delete'=>$this->reviews->receive_delete($payload,$authenticated_source),
                default=>new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_type','Unsupported event type: '.$type,array('status'=>400)),
            };
        }catch(\Throwable$e){$result=new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_exception',$e->getMessage(),array('status'=>500));}
        if(is_wp_error($result)){$this->events->mark_event($uuid,'failed');$this->conflicts->record($uuid,(string)$event['entity_type'],(string)$event['network_id'],$authenticated_source,$result->get_error_code(),$result->get_error_message(),$payload);$this->logger->error('Incoming network event failed.',array('event'=>$uuid,'type'=>$type,'error'=>$result->get_error_message()));return$result;}
        $this->events->mark_event($uuid,'completed');$this->conflicts->resolve_for_event($uuid,$authenticated_source);
        // Reuses the single log row this event already produced (no added log volume) to carry
        // the applied outcome - e.g. unchanged/skipped=older_revision - so a repeated identical
        // sync is visibly distinguishable in the Logs tab from a genuine create/update.
        $this->logger->info('Incoming network event applied.',array('event'=>$uuid,'type'=>$type,'source'=>$authenticated_source,'result'=>is_array($result)?array_intersect_key($result,array_flip(array('unchanged','skipped','local_id'))):array()));
        if('product_upsert'===$type&&$this->settings->is_control()&&'bidirectional'===$this->settings->get('product_direction','control_only')){$targets=$this->dispatcher->targets_for($authenticated_source);$this->dispatcher->dispatch('product_upsert','product',(string)$event['network_id'],$payload,$targets,$authenticated_source,$uuid);}
        return array_merge(array('status'=>'applied'),is_array($result)?$result:array());
    }

    private function authorize_event(string $type,string $source):true|\WP_Error{
        $peer=$this->stores->get($source);if(!$peer)return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_peer','Authenticated store is not a current network peer.',array('status'=>403));
        $peer_role=(string)($peer['role']??'');
        if($this->settings->is_control()){
            if(in_array($type,array('inventory_delta','order_upsert','customer_upsert'),true)&&'connected'===$peer_role)return true;
            if(in_array($type,array('review_upsert','review_delete'),true)&&'connected'===$peer_role&&'yes'===$this->settings->get('sync_reviews','no')&&'network'===$this->settings->get('review_direction','network'))return true;
            if(in_array($type,array('product_upsert','product_trash'),true)&&'connected'===$peer_role&&'bidirectional'===$this->settings->get('product_direction','control_only'))return true;
            return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_topology','This event type is not authorised from the sending store in the current network topology.',array('status'=>403));
        }
        if($this->settings->is_connected()&&'control'===$peer_role&&in_array($type,array('product_upsert','product_trash','inventory_set','coupon_upsert','coupon_trash','review_upsert','review_delete'),true))return true;
        return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_topology','This event type is not authorised from the sending store in the current network topology.',array('status'=>403));
    }
    private function trash_product(string$network):array|\WP_Error{return Context::remote(function()use($network){$id=(new \Kitgenix\MultiStore\Repository\MappingRepository())->local_id($network,'product');if(!$id)return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_product_mapping','Product mapping is missing.',array('status'=>404));wp_trash_post($id);return array('local_id'=>$id);});}
}
