<?php
namespace Kitgenix\MultiStore\Support;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// -- Every query here targets this plugin's own fixed log table name built from
// $wpdb->prefix, never user input, via $wpdb->insert() or $wpdb->prepare(); this
// is a plugin-owned custom table with no WordPress core object-cache group, so a
// direct $wpdb call is the only way to interact with it.
final class Logger {
    public function log( string $level, string $message, array $context = array() ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'kitgenix_multistore_sync_for_woocommerce_logs';
        $wpdb->insert(
            $table,
            array(
                'level'      => sanitize_key( $level ),
                'message'    => wp_strip_all_tags( $message ),
                'context'    => wp_json_encode( $context, JSON_UNESCAPED_SLASHES ),
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%s', '%s' )
        );
        if ( function_exists( 'wc_get_logger' ) && in_array( $level, array( 'error', 'critical' ), true ) ) {
            wc_get_logger()->log( $level, $message, array_merge( array( 'source' => 'kitgenix-multistore-sync-for-woocommerce' ), $context ) );
        }
    }

    public function cleanup( int $days = 30 ): void { global $wpdb; $days = max( 1, $days ); $cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_logs WHERE created_at < %s", $cutoff ) ); }

    public function clear(): void { global $wpdb; $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_logs" ); }

    public function info( string $message, array $context = array() ): void { $this->log( 'info', $message, $context ); }
    public function warning( string $message, array $context = array() ): void { $this->log( 'warning', $message, $context ); }
    public function error( string $message, array $context = array() ): void { $this->log( 'error', $message, $context ); }
}
