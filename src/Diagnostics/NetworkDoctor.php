<?php
namespace Kitgenix\MultiStore\Diagnostics;

use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Repository\ConflictRepository;
use Kitgenix\MultiStore\Repository\EventRepository;
use Kitgenix\MultiStore\Repository\InventoryRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Environment;
use Kitgenix\MultiStore\Support\Logger;

final class NetworkDoctor {
    public function __construct(private StoreRepository$stores,private Client$client,private EventRepository$events,private InventoryRepository$inventory,private Logger$logger,private ConflictRepository$conflicts){}
    public function local_checks():array{
        // Recoverable failures (network blips, remote races) are excluded here —
        // a queue that is only retrying transient errors should still read "ok".
        global$wpdb;$failed=$this->conflicts->genuine_open_count();$checks=array(
            'wordpress'=>array('ok'=>version_compare(get_bloginfo('version'),'6.8','>='),'value'=>get_bloginfo('version')),
            'woocommerce'=>array('ok'=>defined('WC_VERSION')&&version_compare(WC_VERSION,'9.8','>='),'value'=>defined('WC_VERSION')?WC_VERSION:'missing'),
            'php'=>array('ok'=>version_compare(PHP_VERSION,'8.1','>='),'value'=>PHP_VERSION),
            'https'=>array('ok'=>'https'===wp_parse_url(home_url(),PHP_URL_SCHEME),'value'=>home_url()),
            'rest'=>array('ok'=>!empty(get_option('permalink_structure')),'value'=>rest_url()),
            'openssl'=>array('ok'=>function_exists('openssl_encrypt'),'value'=>function_exists('openssl_encrypt')?'available':'missing'),
            'action_scheduler'=>array('ok'=>function_exists('as_enqueue_async_action'),'value'=>function_exists('as_enqueue_async_action')?'available':'cron fallback'),
            'hpos'=>array('ok'=>true,'value'=>class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')?(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'enabled':'disabled'):'unknown'),
            'queue'=>array('ok'=>0===$failed,'value'=>$failed.' failed deliveries'),
            'environment'=>array('ok'=>true,'value'=>Environment::is_non_production()?'non-production':'production'),
            'db'=>array('ok'=>(bool)$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_events'"),'value'=>'schema'),
        );return$checks;
    }
    public function network_checks():array{$out=array('local'=>$this->local_checks(),'stores'=>array());foreach($this->stores->active()as$s){$r=$this->client->request($s['uuid'],'POST','/diagnostics',array(),true);$out['stores'][$s['uuid']]=is_wp_error($r)?array('error'=>$r->get_error_message()):(array)($r['checks']??array());}return$out;}
    public function score(array$checks):int{$ok=0;$total=0;foreach($checks as$c){if(is_array($c)&&array_key_exists('ok',$c)){$total++;if($c['ok'])$ok++;}}return$total?(int)round($ok/$total*100):0;}
}
