<?php
namespace Kitgenix\MultiStore\Support;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// -- Atomic, cross-process revision counter: the WP Options API has no atomic
// increment-and-read primitive, so a direct query against wp_options
// (LAST_INSERT_ID(...) trick) is the only way to hand out a strictly increasing
// value under concurrent access; caching would defeat the point of a fresh read.
final class Revision {
    public static function next(): int {
        global $wpdb;
        $key = 'kitgenix_multistore_sync_for_woocommerce_revision';
        if ( false === get_option( $key, false ) ) {
            add_option( $key, '1000', '', false );
        }
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s",
                $key
            )
        );
        $revision = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
        wp_cache_delete( $key, 'options' );
        return $revision > 0 ? $revision : (int) get_option( $key, 1001 );
    }
}
