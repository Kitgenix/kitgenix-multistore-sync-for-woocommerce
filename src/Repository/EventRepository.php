<?php
namespace Kitgenix\MultiStore\Repository;

use Kitgenix\MultiStore\Support\Revision;
use Kitgenix\MultiStore\Support\Uuid;

final class EventRepository {
    private string $events;
    private string $deliveries;
    public function __construct() { global $wpdb; $this->events=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_events'; $this->deliveries=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_deliveries'; }

    public function create( string $event_type, string $entity_type, string $network_id, array $payload, ?string $source_uuid = null, ?string $parent = null, ?int $revision = null ): array {
        global $wpdb;
        $event_uuid = Uuid::make();
        $revision ??= Revision::next();
        $wpdb->insert( $this->events, array(
            'event_uuid'=>$event_uuid, 'parent_event_uuid'=>$parent, 'entity_type'=>$entity_type, 'network_id'=>$network_id,
            'event_type'=>$event_type, 'source_store_uuid'=>$source_uuid ?: Uuid::site(), 'revision'=>$revision,
            'payload'=>wp_json_encode($payload, JSON_UNESCAPED_SLASHES), 'status'=>'pending', 'created_at'=>current_time('mysql',true)
        ) );
        return $this->get( $event_uuid ) ?? array();
    }

    public function record_received( array $event ): bool {
        global $wpdb;
        if ( $this->exists( (string) $event['event_uuid'] ) ) { return false; }
        $ok = $wpdb->insert( $this->events, array(
            'event_uuid'=>sanitize_text_field($event['event_uuid']), 'parent_event_uuid'=>sanitize_text_field($event['parent_event_uuid'] ?? '' ) ?: null,
            'entity_type'=>sanitize_key($event['entity_type']), 'network_id'=>sanitize_text_field($event['network_id']), 'event_type'=>sanitize_key($event['event_type']),
            'source_store_uuid'=>sanitize_text_field($event['source_store_uuid']), 'revision'=>(int)($event['revision']??0),
            'payload'=>wp_json_encode($event['payload']??array(), JSON_UNESCAPED_SLASHES), 'status'=>'received', 'created_at'=>current_time('mysql',true)
        ) );
        return false !== $ok;
    }

    public function exists( string $uuid ): bool { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->events} WHERE event_uuid=%s LIMIT 1",$uuid)); }
    public function get( string $uuid ): ?array { global $wpdb; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->events} WHERE event_uuid=%s LIMIT 1",$uuid),ARRAY_A); if($r){$r['payload']=json_decode((string)$r['payload'],true)?:array();} return $r?:null; }
    public function mark_event( string $uuid, string $status ): void { global $wpdb; $d=array('status'=>$status); if('completed'===$status){$d['completed_at']=current_time('mysql',true);} $wpdb->update($this->events,$d,array('event_uuid'=>$uuid)); }

    public function add_delivery( string $event_uuid, string $store_uuid ): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT INTO {$this->deliveries} (event_uuid,store_uuid,status,attempts,updated_at) VALUES (%s,%s,'pending',0,%s) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)",$event_uuid,$store_uuid,current_time('mysql',true)));
    }
    public function delivery( string $event_uuid, string $store_uuid ): ?array { global $wpdb; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->deliveries} WHERE event_uuid=%s AND store_uuid=%s LIMIT 1",$event_uuid,$store_uuid),ARRAY_A); return $r?:null; }
    public function mark_delivery( string $event_uuid, string $store_uuid, string $status, string $error = '', ?int $next_timestamp = null ): void {
        global $wpdb; $row=$this->delivery($event_uuid,$store_uuid); $attempts=(int)($row['attempts']??0); if('processing'===$status){$attempts++;}
        $wpdb->update($this->deliveries,array('status'=>$status,'attempts'=>$attempts,'last_error'=>$error,'next_attempt'=>$next_timestamp?gmdate('Y-m-d H:i:s',$next_timestamp):null,'updated_at'=>current_time('mysql',true)),array('event_uuid'=>$event_uuid,'store_uuid'=>$store_uuid));
    }
    public function reset_delivery( string $event_uuid, string $store_uuid ): void { global $wpdb; $wpdb->update($this->deliveries,array('status'=>'pending','attempts'=>0,'last_error'=>'','next_attempt'=>null,'updated_at'=>current_time('mysql',true)),array('event_uuid'=>sanitize_text_field($event_uuid),'store_uuid'=>sanitize_text_field($store_uuid))); $wpdb->update($this->events,array('status'=>'pending','completed_at'=>null),array('event_uuid'=>sanitize_text_field($event_uuid))); }
    public function recent( int $limit = 50 ): array { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->events} ORDER BY id DESC LIMIT %d",$limit),ARRAY_A)?:array(); }
    public function recent_deliveries( int $limit=100 ): array { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->deliveries} ORDER BY id DESC LIMIT %d",$limit),ARRAY_A)?:array(); }
    public function paused_for_store( string $store_uuid, int $limit = 5000 ): array { global $wpdb; $limit=max(1,min(10000,$limit)); return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->deliveries} WHERE store_uuid=%s AND status='paused' ORDER BY id ASC LIMIT %d",sanitize_text_field($store_uuid),$limit),ARRAY_A)?:array(); }
    public function counts(): array { global $wpdb; $rows=$wpdb->get_results("SELECT status,COUNT(*) c FROM {$this->deliveries} GROUP BY status",ARRAY_A)?:array(); $out=array(); foreach($rows as $r){$out[$r['status']]=(int)$r['c'];} return $out; }
}
