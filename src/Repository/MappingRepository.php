<?php
namespace Kitgenix\MultiStore\Repository;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// -- Every query here interpolates only this class's own fixed, developer-controlled table-name
// property (set once in the constructor from $wpdb->prefix, never from user input); every actual
// value is passed through $wpdb->prepare() with %s/%d/%f placeholders. This is a plugin-owned
// custom table with no WordPress core object-cache group; a direct $wpdb call is the only way
// to interact with it.

use Kitgenix\MultiStore\Support\Uuid;

final class MappingRepository {
    private string $table;
    public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'kitgenix_multistore_sync_for_woocommerce_mappings'; }

    public function network_id( string $type, int $object_id, string $object_key = '' ): string {
        global $wpdb;
        $site = Uuid::site();
        $network = (string) $wpdb->get_var( $wpdb->prepare( "SELECT network_id FROM {$this->table} WHERE entity_type=%s AND store_uuid=%s AND object_id=%d LIMIT 1", $type, $site, $object_id ) );
        if ( ! $network && in_array( $type, array( 'product', 'variation', 'coupon' ), true ) ) {
            $network = (string) get_post_meta( $object_id, '_kitgenix_multistore_sync_for_woocommerce_network_id', true );
        }
        if ( ! $network ) {
            $network = Uuid::make();
            if ( in_array( $type, array( 'product', 'variation', 'coupon' ), true ) ) {
                update_post_meta( $object_id, '_kitgenix_multistore_sync_for_woocommerce_network_id', $network );
            }
            $this->upsert( $network, $type, $site, $object_id, $object_key, 0 );
        }
        return $network;
    }

    public function upsert( string $network_id, string $type, string $store_uuid, int $object_id, string $object_key = '', int $revision = 0 ): void {
        global $wpdb;
        // A local WooCommerce object must belong to exactly one network entity per type/store.
        // This also safely re-homes an SKU-matched object when adopting an authoritative network ID.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table} WHERE entity_type=%s AND store_uuid=%s AND object_id=%d AND network_id<>%s",
            $type, $store_uuid, $object_id, $network_id
        ) );
        $sql = "INSERT INTO {$this->table} (network_id,entity_type,store_uuid,object_id,object_key,last_revision,updated_at)
                VALUES (%s,%s,%s,%d,%s,%d,%s)
                ON DUPLICATE KEY UPDATE network_id=VALUES(network_id), object_id=VALUES(object_id), object_key=VALUES(object_key), last_revision=GREATEST(last_revision,VALUES(last_revision)), updated_at=VALUES(updated_at)";
        $wpdb->query( $wpdb->prepare( $sql, $network_id, $type, $store_uuid, $object_id, $object_key, $revision, current_time( 'mysql', true ) ) );
    }

    public function get( string $network_id, string $type, ?string $store_uuid = null ): ?array {
        global $wpdb;
        $store_uuid ??= Uuid::site();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE network_id=%s AND entity_type=%s AND store_uuid=%s LIMIT 1", $network_id, $type, $store_uuid ), ARRAY_A );
        return $row ?: null;
    }

    public function local_id( string $network_id, string $type ): int {
        $row = $this->get( $network_id, $type, Uuid::site() );
        return $row ? (int) $row['object_id'] : 0;
    }

    public function for_store( string $store_uuid, string $type = 'product', int $limit = 500, int $offset = 0 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE store_uuid=%s AND entity_type=%s ORDER BY id DESC LIMIT %d OFFSET %d", $store_uuid, $type, $limit, $offset ), ARRAY_A ) ?: array();
    }

    public function count( ?string $type = null ): int {
        global $wpdb;
        if ( $type ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE entity_type=%s AND store_uuid=%s", $type, Uuid::site() ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE store_uuid=%s", Uuid::site() ) );
    }
}
