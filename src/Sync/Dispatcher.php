<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\EventRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Environment;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class Dispatcher {
    public function __construct(private EventRepository $events,private StoreRepository $stores,private Queue $queue,private Settings $settings,private Logger $logger){}
    public function dispatch(string $event_type,string $entity_type,string $network_id,array $payload,array $targets=array(),?string $source=null,?string $parent=null,?int $revision=null):array|\WP_Error{
        if(Environment::is_non_production()&&'yes'!==$this->settings->get('allow_non_production_outbound','no')){return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_staging_protection','Outbound sync is disabled by staging protection.');}
        $origin=$source;
        if($origin&&$origin!==Uuid::site()){$payload['_origin_store_uuid']=$origin;}
        $event=$this->events->create($event_type,$entity_type,$network_id,$payload,Uuid::site(),$parent,$revision);if(!$event){return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_event_create','Unable to create sync event.');}
        $target_uuids=$targets?:$this->targets_for($origin?:Uuid::site());$target_uuids=array_values(array_unique(array_filter($target_uuids,static fn($uuid)=>is_string($uuid)&&''!==$uuid&&$uuid!==Uuid::site())));
        foreach($target_uuids as$uuid){$this->queue->enqueue($event['event_uuid'],$uuid);}
        if(!$target_uuids){$this->events->mark_event($event['event_uuid'],'completed');}
        do_action('kitgenix_multistore/event_created',$event,$target_uuids);
        return $event;
    }
    public function targets_for(string $source):array{
        if($this->settings->is_connected()){$control=$this->stores->control();return$control?array($control['uuid']):array();}
        $out=array();foreach($this->stores->active() as$s){if($s['uuid']!==$source&&'connected'===$s['role']){$out[]=$s['uuid'];}}return$out;
    }
}
