<?php
namespace Kitgenix\MultiStore\Repository;

use Kitgenix\MultiStore\Support\ErrorCatalog;
use Kitgenix\MultiStore\Support\Uuid;

final class ConflictRepository {
    private string $table;
    public function __construct(){global $wpdb;$this->table=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_conflicts';}

    public function record(string $event_uuid,string $entity_type,string $network_id,string $store_uuid,string $type,string $message,array $payload=array()):string{
        global $wpdb;
        $event_uuid=sanitize_text_field($event_uuid);$store_uuid=sanitize_text_field($store_uuid);$type=sanitize_key($type);
        $existing=(string)$wpdb->get_var($wpdb->prepare("SELECT conflict_uuid FROM {$this->table} WHERE event_uuid=%s AND store_uuid=%s AND conflict_type=%s AND status='open' ORDER BY id DESC LIMIT 1",$event_uuid,$store_uuid,$type));
        $data=array('entity_type'=>sanitize_key($entity_type),'network_id'=>sanitize_text_field($network_id),'message'=>wp_strip_all_tags($message),'payload'=>wp_json_encode($payload,JSON_UNESCAPED_SLASHES),'created_at'=>current_time('mysql',true));
        if($existing){$wpdb->update($this->table,$data,array('conflict_uuid'=>$existing));return$existing;}
        $uuid=Uuid::make();$wpdb->insert($this->table,array_merge($data,array('conflict_uuid'=>$uuid,'event_uuid'=>$event_uuid,'store_uuid'=>$store_uuid,'conflict_type'=>$type,'status'=>'open')));return$uuid;
    }
    public function recent(int $limit=100,string $status=''):array{global $wpdb;$limit=max(1,min(500,$limit));if($status){return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE status=%s ORDER BY id DESC LIMIT %d",sanitize_key($status),$limit),ARRAY_A)?:array();}return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d",$limit),ARRAY_A)?:array();}
    public function open_count():int{global $wpdb;return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status='open'");}
    /**
     * Open conflicts whose conflict_type is NOT a recoverable/transient code —
     * i.e. deliveries that failed for a genuine reason, not a network blip or
     * remote race. Used by NetworkDoctor so the queue health check doesn't
     * flag temporary hiccups as unhealthy. See ErrorCatalog::is_recoverable().
     */
    public function genuine_open_count():int{
        global $wpdb;$types=$wpdb->get_col("SELECT conflict_type FROM {$this->table} WHERE status='open'");
        $count=0;foreach($types as $type){if(!ErrorCatalog::is_recoverable((string)$type))$count++;}return $count;
    }
    public function resolve(string $uuid,string $status='resolved'):bool{global $wpdb;$status=in_array($status,array('resolved','ignored'),true)?$status:'resolved';return false!==$wpdb->update($this->table,array('status'=>$status,'resolved_at'=>current_time('mysql',true)),array('conflict_uuid'=>sanitize_text_field($uuid)));}
    public function resolve_for_event(string $event_uuid,string $store_uuid=''):void{global $wpdb;$where="event_uuid=%s AND status='open'";$args=array(sanitize_text_field($event_uuid));if($store_uuid){$where.=' AND store_uuid=%s';$args[]=sanitize_text_field($store_uuid);}$sql="UPDATE {$this->table} SET status='resolved', resolved_at=%s WHERE {$where}";array_unshift($args,current_time('mysql',true));$wpdb->query($wpdb->prepare($sql,...$args));}
}
