<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Pricing\PriceCalculator;
use Kitgenix\MultiStore\Repository\EventRepository;
use Kitgenix\MultiStore\Repository\ConflictRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\ErrorCatalog;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;

final class Queue {
    private const GROUP='kitgenix-multistore-sync-for-woocommerce';
    public function __construct(private EventRepository $events,private ConflictRepository $conflicts,private StoreRepository $stores,private Client $client,private MappingRepository $mappings,private Logger $logger,private Settings $settings,private StorePolicy $policy){}
    public function hooks():void{
        add_action('kitgenix_multistore_sync_for_woocommerce_process_delivery',array($this,'process'),10,2);
        add_action('kitgenix_multistore_sync_for_woocommerce_health_check',array($this,'health_check'));
        add_action('kitgenix_multistore_sync_for_woocommerce_fallback_health_check',array($this,'health_check'));add_action('kitgenix_multistore_sync_for_woocommerce_cleanup',array($this,'cleanup'));add_action('kitgenix_multistore_sync_for_woocommerce_fallback_cleanup',array($this,'cleanup'));add_filter('cron_schedules',array($this,'cron_schedules'));
        add_action('init',array($this,'ensure_schedules'),30);
    }
    public function ensure_schedules():void{
        if(function_exists('as_has_scheduled_action')&&function_exists('as_schedule_recurring_action')){
            if(!as_has_scheduled_action('kitgenix_multistore_sync_for_woocommerce_health_check',array(),self::GROUP)){as_schedule_recurring_action(time()+300,300,'kitgenix_multistore_sync_for_woocommerce_health_check',array(),self::GROUP,true);}
            if(!as_has_scheduled_action('kitgenix_multistore_sync_for_woocommerce_cleanup',array(),self::GROUP)){as_schedule_recurring_action(time()+HOUR_IN_SECONDS,DAY_IN_SECONDS,'kitgenix_multistore_sync_for_woocommerce_cleanup',array(),self::GROUP,true);}
        }else{if(!wp_next_scheduled('kitgenix_multistore_sync_for_woocommerce_fallback_health_check'))wp_schedule_event(time()+300,'kitgenix_multistore_sync_for_woocommerce_five_minutes','kitgenix_multistore_sync_for_woocommerce_fallback_health_check');if(!wp_next_scheduled('kitgenix_multistore_sync_for_woocommerce_fallback_cleanup'))wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','kitgenix_multistore_sync_for_woocommerce_fallback_cleanup');}
    }
    public function enqueue(string $event_uuid,string $store_uuid,int $delay=0):void{
        $this->events->add_delivery($event_uuid,$store_uuid);$ts=time()+max(0,$delay);
        $hook='kitgenix_multistore_sync_for_woocommerce_process_delivery';$args=array($event_uuid,$store_uuid);
        // unique=true (Action Scheduler) / wp_next_scheduled() guard (cron fallback): a retry or a
        // near-simultaneous second dispatch for the same event+store must not queue a second job.
        if(function_exists('as_schedule_single_action')){as_schedule_single_action($ts,$hook,$args,self::GROUP,true);}
        elseif(!wp_next_scheduled($hook,$args)){wp_schedule_single_event($ts,$hook,$args);}
    }
    public function retry(string $event_uuid,string $store_uuid):void{$this->events->reset_delivery($event_uuid,$store_uuid);$this->enqueue($event_uuid,$store_uuid,0);}
    public function resume_store(string $store_uuid):int{$rows=$this->events->paused_for_store($store_uuid);$count=0;foreach($rows as$row){$event=(string)($row['event_uuid']??'');if(!$event)continue;$this->events->reset_delivery($event,$store_uuid);$this->enqueue($event,$store_uuid,0);$count++;}return$count;}
    public function process(string $event_uuid,string $store_uuid):void{
        $event=$this->events->get($event_uuid);$store=$this->stores->get($store_uuid);if(!$event||!$store){return;}
        if('disabled'===(string)($store['status']??'')){ $this->events->mark_delivery($event_uuid,$store_uuid,'paused','Store is paused.'); return; }
        $this->events->mark_delivery($event_uuid,$store_uuid,'processing');
        $payload=$event['payload'];
        if(in_array((string)$event['event_type'],array('review_upsert','review_delete'),true)){
            $policy=$this->settings->policy($store_uuid);
            if('yes'!==(string)($policy['fields']['reviews']['update']??'yes')){
                $this->events->mark_delivery($event_uuid,$store_uuid,'completed','Skipped by store review policy.');
                $this->maybe_complete_event($event_uuid);
                return;
            }
        }
        if('product_upsert'===$event['event_type']){
            $all_overrides=(array)($payload['store_overrides']??array());
            $all_rules=(array)($payload['store_pricing_rules']??array());
            $override=isset($all_overrides[$store_uuid])&&is_array($all_overrides[$store_uuid])?$all_overrides[$store_uuid]:array();
            $rule=isset($all_rules[$store_uuid])&&is_array($all_rules[$store_uuid])?$all_rules[$store_uuid]:array();
            $payload=PriceCalculator::apply_for_store($payload,$rule,$override,$store_uuid);
            $payload=$this->policy->prepare($payload,$store_uuid,(string)$event['network_id'],(string)$event['entity_type']);
            if(!empty($payload['_pricing']['rule_enabled'])){
                $this->logger->info('Store pricing rule applied to product delivery.',array(
                    'event'=>$event_uuid,
                    'store'=>$store_uuid,
                    'percentage'=>$payload['_pricing']['percentage'],
                    'source_regular_price'=>$payload['_pricing']['source_regular_price'],
                    'final_regular_price'=>$payload['_pricing']['final_regular_price'],
                ));
            }
        }
        $packet=array('event_uuid'=>$event['event_uuid'],'parent_event_uuid'=>$event['parent_event_uuid'],'entity_type'=>$event['entity_type'],'network_id'=>$event['network_id'],'event_type'=>$event['event_type'],'source_store_uuid'=>$event['source_store_uuid'],'revision'=>(int)$event['revision'],'payload'=>$payload);
        $response=$this->client->request($store_uuid,'POST','/events',$packet);
        if(is_wp_error($response)){
            $row=$this->events->delivery($event_uuid,$store_uuid);$attempts=(int)($row['attempts']??1);$max=(int)$this->settings->get('max_retries',8);
            if($attempts>=$max){
                $error_code=$response->get_error_code();
                // Retries are exhausted either way, but a still-recoverable code (network
                // blip, remote conflict) shouldn't read the same as a genuine rejection –
                // see ErrorCatalog::is_recoverable() and NetworkDoctor's queue health check.
                $recoverable=ErrorCatalog::is_recoverable($error_code);
                $this->events->mark_delivery($event_uuid,$store_uuid,'failed',$response->get_error_message());$this->conflicts->record($event_uuid,(string)$event['entity_type'],(string)$event['network_id'],$store_uuid,$error_code,$response->get_error_message(),array('event_type'=>$event['event_type'],'attempts'=>$attempts));$this->logger->log($recoverable?'warning':'error','Network delivery failed permanently.',array('event'=>$event_uuid,'store'=>$store_uuid,'code'=>$error_code,'error'=>$response->get_error_message()));$this->maybe_complete_event($event_uuid);return;
            }
            $delay=min(3600,60*(2**max(0,$attempts-1)));$this->events->mark_delivery($event_uuid,$store_uuid,'retrying',$response->get_error_message(),time()+$delay);$this->enqueue($event_uuid,$store_uuid,$delay);return;
        }
        if(!empty($response['local_id'])){$this->mappings->upsert($event['network_id'],$event['entity_type'],$store_uuid,(int)$response['local_id'],(string)($response['object_key']??''),(int)$event['revision']);}
        $this->events->mark_delivery($event_uuid,$store_uuid,'completed');$this->conflicts->resolve_for_event($event_uuid,$store_uuid);
        $this->maybe_complete_event($event_uuid);
    }
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is a fixed, developer-controlled table name built from $wpdb->prefix, not user input; this is a plugin-owned custom table with no WordPress core object-cache group, so a direct $wpdb call is the only way to interact with it.
    private function maybe_complete_event(string $event_uuid):void{global$wpdb;$table=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_deliveries';$open=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_uuid=%s AND status NOT IN ('completed','failed')",$event_uuid));if(0!==$open)return;$failed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_uuid=%s AND status='failed'",$event_uuid));$this->events->mark_event($event_uuid,$failed?'failed':'completed');}
    // phpcs:enable
    public function cron_schedules(array$schedules):array{$schedules['kitgenix_multistore_sync_for_woocommerce_five_minutes']=array('interval'=>300,'display'=>'Every five minutes (Kitgenix MultiStore)');return$schedules;}
    public function cleanup():void{$this->logger->cleanup((int)$this->settings->get('log_retention_days',30));}
    public function health_check():void{
        foreach($this->stores->active() as $store){
            $r=$this->client->request($store['uuid'],'POST','/health',array(),true);
            if(is_wp_error($r)){
                $error_data=(array)$r->get_error_data();
                $status=in_array((int)($error_data['status']??0),array(401,403),true)?'auth_failed':'offline';
                $this->stores->set_status($store['uuid'],$status);
                continue;
            }
            $capabilities=(array)($r['capabilities']??array());
            $capabilities['kitgenix']=(string)($r['store']['version']??'');
            $this->stores->touch($store['uuid'],$this->health_status_for($r,$capabilities),$capabilities);
        }
    }

    /**
     * Distinguish "reachable but something needs attention" (Warning /
     * Version Incompatible) from a clean Connected state, so the admin Stores
     * view can show the right one rather than a blanket "active" for anything
     * that merely responded.
     *
     * @param array<string,mixed> $response
     * @param array<string,mixed> $capabilities
     */
    private function health_status_for(array $response,array $capabilities):string{
        $peer_version=(string)($response['store']['version']??'');
        if($peer_version){
            $our_major=(int)strtok(KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION,'.');
            $peer_major=(int)strtok($peer_version,'.');
            if($peer_major!==$our_major)return'version_incompatible';
        }

        $peer_wp=(string)($capabilities['wordpress']??'');
        $peer_wc=(string)($capabilities['woocommerce']??'');
        if(($peer_wp&&version_compare($peer_wp,'6.8','<'))||($peer_wc&&version_compare($peer_wc,'10.0','<'))){
            return'warning';
        }
        if(empty($capabilities['action_scheduler'])){
            return'warning';
        }

        return'active';
    }
}
