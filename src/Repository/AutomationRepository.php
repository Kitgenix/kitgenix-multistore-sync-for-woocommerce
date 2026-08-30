<?php
namespace Kitgenix\MultiStore\Repository;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// -- Every query here interpolates only this class's own fixed, developer-controlled table-name
// property (set once in the constructor from $wpdb->prefix, never from user input); every actual
// value is passed through $wpdb->prepare() with %s/%d/%f placeholders. This is a plugin-owned
// custom table with no WordPress core object-cache group; a direct $wpdb call is the only way
// to interact with it.

final class AutomationRepository {
    private string $table;
    public function __construct(){global $wpdb;$this->table=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_automations';}
    public function all():array{global $wpdb;return$wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id DESC",ARRAY_A)?:array();}
    public function enabled(string $trigger):array{global $wpdb;return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE enabled=1 AND trigger_type=%s",$trigger),ARRAY_A)?:array();}
    public function create(string $name,string $trigger,array $tc,string $action,array $ac):void{global$wpdb;$now=current_time('mysql',true);$wpdb->insert($this->table,array('name'=>sanitize_text_field($name),'enabled'=>1,'trigger_type'=>sanitize_key($trigger),'trigger_config'=>wp_json_encode($tc),'action_type'=>sanitize_key($action),'action_config'=>wp_json_encode($ac),'created_at'=>$now,'updated_at'=>$now));}
    public function delete(int $id):void{global$wpdb;$wpdb->delete($this->table,array('id'=>$id));}
    public function touch(int $id):void{global$wpdb;$wpdb->update($this->table,array('last_run'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)),array('id'=>$id));}
}
