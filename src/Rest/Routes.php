<?php
namespace Kitgenix\MultiStore\Rest;

use Kitgenix\MultiStore\Diagnostics\NetworkDoctor;
use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Orders\OrderNumbers;
use Kitgenix\MultiStore\Repository\InventoryRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Repository\OrderRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Uuid;
use Kitgenix\MultiStore\Sync\EventProcessor;
use Kitgenix\MultiStore\Sync\OrderSync;

final class Routes {
    private Auth $auth;
    public function __construct(
        private StoreRepository $stores,
        private Client $client,
        private EventProcessor $processor,
        private InventoryRepository $inventory,
        private MappingRepository $mappings,
        private OrderRepository $order_repository,
        private OrderSync $orders,
        private OrderNumbers $order_numbers,
        private NetworkDoctor $doctor,
        private Logger $logger
    ) { $this->auth = new Auth( $stores, $logger ); }

    public function hooks(): void { add_action( 'rest_api_init', array( $this, 'register' ) ); }

    public function register(): void {
        $ns='kitgenix-multistore/v1';
        register_rest_route($ns,'/pair',array('methods'=>'POST','callback'=>array($this,'pair'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/health',array('methods'=>array('GET','POST'),'callback'=>array($this,'health'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/events',array('methods'=>'POST','callback'=>array($this,'events'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/inventory/snapshot',array('methods'=>'POST','callback'=>array($this,'snapshot'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/inventory/availability',array('methods'=>'POST','callback'=>array($this,'availability'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/orders/status',array('methods'=>'POST','callback'=>array($this,'order_status'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/orders/note',array('methods'=>'POST','callback'=>array($this,'order_note'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/orders/refund',array('methods'=>'POST','callback'=>array($this,'order_refund'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/orders/next-number',array('methods'=>'POST','callback'=>array($this,'order_next_number'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/orders/customer-history',array('methods'=>'POST','callback'=>array($this,'customer_history'),'permission_callback'=>array($this->auth,'verify')));
        register_rest_route($ns,'/diagnostics',array('methods'=>'POST','callback'=>array($this,'diagnostics'),'permission_callback'=>array($this->auth,'verify')));
    }

    public function pair(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        $ip=sanitize_text_field($_SERVER['REMOTE_ADDR']??'unknown'); $rate='kitgenix_multistore_sync_for_woocommerce_pair_rate_'.md5($ip); $count=(int)get_transient($rate); if($count>15){return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_pair_rate','Too many pairing attempts.',array('status'=>429));} set_transient($rate,$count+1,10*MINUTE_IN_SECONDS);
        $p=(array)$request->get_json_params(); $code=sanitize_text_field($p['code']??''); $expected=(string)get_transient('kitgenix_multistore_sync_for_woocommerce_pair_code');
        if(!$expected||!hash_equals($expected,$code)){return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_pair_code','Pairing code is invalid or expired.',array('status'=>403));}
        $secret=(string)($p['secret']??'');$store=(array)($p['store']??array()); if(strlen($secret)<32||empty($store['uuid'])||empty($store['url'])){return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_pair_payload','Invalid pairing payload.',array('status'=>400));}
        $saved=$this->stores->save(array_merge($store,array('role'=>'control','status'=>'active')),$secret); if(is_wp_error($saved)){return $saved;} delete_transient('kitgenix_multistore_sync_for_woocommerce_pair_code');
        update_option('kitgenix_multistore_sync_for_woocommerce_settings',array_merge((array)get_option('kitgenix_multistore_sync_for_woocommerce_settings',array()),array('role'=>'connected')),false);
        return new \WP_REST_Response(array('success'=>true,'store'=>$this->site_data(),'capabilities'=>$this->capabilities()),200);
    }
    public function health():\WP_REST_Response{return new \WP_REST_Response(array('success'=>true,'store'=>$this->site_data(),'capabilities'=>$this->capabilities(),'time'=>time()),200);}
    public function events(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{$event=(array)$request->get_json_params();$source=$this->source($request);$result=$this->processor->receive($event,$source);return is_wp_error($result)?$result:new \WP_REST_Response(array_merge(array('success'=>true),$result),200);}
    public function snapshot(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{ $allowed=$this->require_peer_role($request,'control');if(is_wp_error($allowed))return$allowed;$ids=array_slice(array_filter(array_map('sanitize_text_field',(array)($request->get_json_params()['network_ids']??array()))),0,250);$rows=array();foreach($ids as$id){$local=$this->mappings->local_id($id,'product')?:$this->mappings->local_id($id,'variation');$product=$local?wc_get_product($local):false;if($product){$rows[$id]=array('quantity'=>$product->get_stock_quantity(),'stock_status'=>$product->get_stock_status(),'local_id'=>$local);}}return new \WP_REST_Response(array('success'=>true,'inventory'=>$rows),200);}

    public function availability(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        $allowed=$this->require_peer_role($request,'connected');if(is_wp_error($allowed))return$allowed;$items=array_slice((array)($request->get_json_params()['items']??array()),0,100);$unavailable=array();$availability=array();
        foreach($items as$item){$network=sanitize_text_field((string)($item['network_id']??''));$requested=max(0,(float)($item['quantity']??0));if(!$network)continue;$row=$this->inventory->get($network);if(!$row||null===$row['quantity']){$availability[$network]=array('available'=>null,'requested'=>$requested);continue;}$available=(float)$row['quantity'];$availability[$network]=array('available'=>$available,'requested'=>$requested,'stock_status'=>$row['stock_status']);if($available<$requested&&'onbackorder'!==$row['stock_status']){$local=$this->mappings->local_id($network,'product')?:$this->mappings->local_id($network,'variation');$p=$local?wc_get_product($local):false;$unavailable[]=array('network_id'=>$network,'name'=>$p?$p->get_name():$network,'available'=>$available,'requested'=>$requested);}}
        return new \WP_REST_Response(array('success'=>true,'availability'=>$availability,'unavailable'=>$unavailable),200);
    }
    public function order_status(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{$allowed=$this->require_peer_role($request,'control');if(is_wp_error($allowed))return$allowed;$p=(array)$request->get_json_params();$r=$this->orders->apply_status((string)($p['network_id']??''),(int)($p['order_id']??0),(string)($p['status']??''));return is_wp_error($r)?$r:new \WP_REST_Response(array('success'=>true,'order_id'=>$r),200);}
    public function order_note(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{$allowed=$this->require_peer_role($request,'control');if(is_wp_error($allowed))return$allowed;$p=(array)$request->get_json_params();$r=$this->orders->apply_note((string)($p['network_id']??''),(int)($p['order_id']??0),(string)($p['note']??''),!empty($p['customer_note']));return is_wp_error($r)?$r:new \WP_REST_Response(array_merge(array('success'=>true),$r),200);}
    public function order_refund(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{$allowed=$this->require_peer_role($request,'control');if(is_wp_error($allowed))return$allowed;$r=$this->orders->apply_refund((array)$request->get_json_params());return is_wp_error($r)?$r:new \WP_REST_Response(array_merge(array('success'=>true),$r),200);}
    public function order_next_number(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{$allowed=$this->require_peer_role($request,'connected');if(is_wp_error($allowed))return$allowed;$settings=(array)get_option('kitgenix_multistore_sync_for_woocommerce_settings',array());if('control'!==(string)($settings['role']??''))return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_order_number_topology','Order numbers are allocated by the control store only.',array('status'=>403));if('yes'!==(string)($settings['sequential_order_numbers']??'no'))return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_order_numbers_disabled','Network sequential order numbers are disabled.',array('status'=>409));return new \WP_REST_Response(array('success'=>true,'number'=>$this->order_numbers->next_number()),200);}
    public function customer_history(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        $allowed=$this->require_peer_role($request,'connected');if(is_wp_error($allowed))return$allowed;
        $settings=(array)get_option('kitgenix_multistore_sync_for_woocommerce_settings',array());if('control'!==(string)($settings['role']??''))return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_order_history_topology','Customer history is served by the control store only.',array('status'=>403));
        if('yes'!==(string)($settings['network_my_account_orders']??'no'))return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_order_history_disabled','Network My Account order history is disabled.',array('status'=>409));
        $email=sanitize_email((string)($request->get_json_params()['email']??''));if(!$email)return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_order_history_email','A valid customer email is required.',array('status'=>400));
        $source=$this->source($request);$rows=$this->order_repository->by_email($email,100);$out=array();
        foreach($rows as$row){if((string)$row['store_uuid']===$source)continue;$store=$this->stores->get((string)$row['store_uuid']);$out[]=array('network_id'=>(string)$row['network_id'],'order_number'=>(string)$row['order_number'],'status'=>(string)$row['status'],'currency'=>(string)$row['currency'],'total'=>(float)$row['total'],'order_created_at'=>(string)($row['order_created_at']??''),'synced_at'=>(string)($row['synced_at']??''),'store_name'=>(string)($store['name']??'Store'));}
        return new \WP_REST_Response(array('success'=>true,'orders'=>$out),200);
    }
    public function diagnostics():\WP_REST_Response{return new \WP_REST_Response(array('success'=>true,'checks'=>$this->doctor->local_checks()),200);}

    private function source(\WP_REST_Request $request):string{return sanitize_text_field((string)$request->get_header('x-kitgenix-store'));}
    private function require_peer_role(\WP_REST_Request $request,string $role):true|\WP_Error{$source=$this->source($request);$peer=$source?$this->stores->get($source):null;if(!$peer||$role!==(string)($peer['role']??''))return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_route_topology','The authenticated store is not authorised for this network operation.',array('status'=>403));return true;}
    private function site_data():array{$settings=(array)get_option('kitgenix_multistore_sync_for_woocommerce_settings',array());return array('uuid'=>Uuid::site(),'name'=>get_bloginfo('name'),'url'=>home_url(),'role'=>(string)($settings['role']??'connected'),'version'=>KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION);}
    private function capabilities():array{return array('wordpress'=>get_bloginfo('version'),'woocommerce'=>defined('WC_VERSION')?WC_VERSION:'','php'=>PHP_VERSION,'hpos'=>class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')?\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled():false,'action_scheduler'=>function_exists('as_enqueue_async_action'),'openssl'=>function_exists('openssl_encrypt'),'currency'=>function_exists('get_woocommerce_currency')?get_woocommerce_currency():'','price_decimals'=>function_exists('wc_get_price_decimals')?wc_get_price_decimals():2);}
}
