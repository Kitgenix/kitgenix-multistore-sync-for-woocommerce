<?php
namespace Kitgenix\MultiStore\Repository;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// -- Every query here interpolates only this class's own fixed, developer-controlled table-name
// property (set once in the constructor from $wpdb->prefix, never from user input); every actual
// value is passed through $wpdb->prepare() with %s/%d/%f placeholders. This is a plugin-owned
// custom table with no WordPress core object-cache group; a direct $wpdb call is the only way
// to interact with it.

use Kitgenix\MultiStore\Support\Crypto;
use Kitgenix\MultiStore\Support\Environment;
use Kitgenix\MultiStore\Support\Uuid;

final class StoreRepository {
    private string $table;
    public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'kitgenix_multistore_sync_for_woocommerce_stores'; }

    public function all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY name ASC", ARRAY_A ) ?: array();
    }

    public function active(): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE status != %s ORDER BY name ASC", 'disabled' ), ARRAY_A ) ?: array();
    }

    public function get( string $uuid ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE uuid = %s LIMIT 1", $uuid ), ARRAY_A );
        return $row ?: null;
    }

    public function control(): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE role = %s AND status != %s ORDER BY id ASC LIMIT 1", 'control', 'disabled' ), ARRAY_A );
        return $row ?: null;
    }

    public function save( array $data, string $secret = '' ): array|\WP_Error {
        global $wpdb;
        $uuid = sanitize_text_field( $data['uuid'] ?? '' );
        if ( ! wp_is_uuid( $uuid ) || $uuid === Uuid::site() ) {
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_invalid_store_uuid', 'Invalid remote store UUID.' );
        }
        $remote_url = esc_url_raw( untrailingslashit( $data['url'] ?? '' ) );
        if ( ! $remote_url || ! Environment::validate_store_url( $remote_url ) ) {
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_invalid_store_url', 'Invalid remote store URL.' );
        }
        $scheme = strtolower( (string) wp_parse_url( $remote_url, PHP_URL_SCHEME ) );
        if ( 'https' !== $scheme && ! Environment::is_non_production() ) {
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_https_required', 'HTTPS is required for production store connections.' );
        }
        $existing = $this->get( $uuid );
        $now = current_time( 'mysql', true );
        $row = array(
            'uuid'         => $uuid,
            'name'         => sanitize_text_field( $data['name'] ?? 'WooCommerce Store' ),
            'url'          => $remote_url,
            'role'         => sanitize_key( $data['role'] ?? 'connected' ),
            'status'       => sanitize_key( $data['status'] ?? 'active' ),
            'capabilities' => wp_json_encode( $data['capabilities'] ?? array() ),
            'last_seen'    => $data['last_seen'] ?? $now,
            'updated_at'   => $now,
        );
        if ( $secret ) {
            try { $row['secret_enc'] = Crypto::encrypt( $secret ); }
            catch ( \Throwable $e ) { return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_crypto_error', $e->getMessage() ); }
        } elseif ( $existing ) {
            $row['secret_enc'] = $existing['secret_enc'];
        }
        if ( $existing ) {
            $wpdb->update( $this->table, $row, array( 'uuid' => $uuid ) );
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( $this->table, $row );
        }
        return $this->get( $uuid ) ?? $row;
    }

    public function secret( string $uuid ): string {
        $store = $this->get( $uuid );
        return $store ? Crypto::decrypt( (string) $store['secret_enc'] ) : '';
    }

    public function touch( string $uuid, string $status = 'active', ?array $capabilities = null ): void {
        global $wpdb;
        $data = array( 'status' => sanitize_key( $status ), 'last_seen' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) );
        if ( null !== $capabilities ) { $data['capabilities'] = wp_json_encode( $capabilities ); }
        $wpdb->update( $this->table, $data, array( 'uuid' => $uuid ) );
    }

    public function mark_offline( string $uuid ): void {
        global $wpdb;
        $wpdb->update( $this->table, array( 'status' => 'offline', 'updated_at' => current_time( 'mysql', true ) ), array( 'uuid' => $uuid ) );
    }

    public function capabilities( string|array $store ): array {
        if ( is_string( $store ) ) $store = $this->get( $store ) ?: array();
        $caps = $store['capabilities'] ?? array();
        if ( is_string( $caps ) ) $caps = json_decode( $caps, true ) ?: array();
        return is_array( $caps ) ? $caps : array();
    }

    /**
     * Recognised connection-health statuses, shown distinctly in the admin
     * Stores view: Connected (active), Warning, Offline, Authentication
     * Failed, and Version Incompatible – plus the admin-controlled Disabled
     * (paused) state, which is never set by a health check.
     */
    public const STATUSES = array( 'active', 'warning', 'offline', 'auth_failed', 'version_incompatible', 'disabled', 'unknown' );

    public function set_status( string $uuid, string $status ): void {
        global $wpdb;
        $status = in_array( $status, self::STATUSES, true ) ? $status : 'unknown';
        $wpdb->update( $this->table, array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'uuid' => $uuid ) );
    }

    public function delete( string $uuid ): void {
        global $wpdb;
        $wpdb->delete( $this->table, array( 'uuid' => $uuid ) );
    }
}
