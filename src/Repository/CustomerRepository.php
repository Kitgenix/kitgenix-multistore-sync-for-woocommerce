<?php
namespace Kitgenix\MultiStore\Repository;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// -- Every query here interpolates only this class's own fixed, developer-controlled table-name
// property (set once in the constructor from $wpdb->prefix, never from user input); every actual
// value is passed through $wpdb->prepare() with %s/%d/%f placeholders. This is a plugin-owned
// custom table with no WordPress core object-cache group; a direct $wpdb call is the only way
// to interact with it.

final class CustomerRepository {
    private string $table;
    public function __construct(){global $wpdb;$this->table=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_customers';}
    public function upsert(array $p,string $store_uuid):void{
        global $wpdb;
        $sql="INSERT INTO {$this->table} (network_id,store_uuid,remote_customer_id,email,display_name,payload,synced_at) VALUES (%s,%s,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE remote_customer_id=VALUES(remote_customer_id),email=VALUES(email),display_name=VALUES(display_name),payload=VALUES(payload),synced_at=VALUES(synced_at)";
        $wpdb->query($wpdb->prepare($sql,$p['network_id'],$store_uuid,(int)($p['customer_id']??0),(string)($p['email']??''),(string)($p['display_name']??''),wp_json_encode($p,JSON_UNESCAPED_SLASHES),current_time('mysql',true)));
    }
    public function count():int{global $wpdb;return(int)$wpdb->get_var("SELECT COUNT(DISTINCT network_id) FROM {$this->table}");}
}
