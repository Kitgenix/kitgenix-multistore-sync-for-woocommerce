<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA' ) || true !== KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA ) {
    return;
}

/**
 * Remove Kitgenix MultiStore data from the current site.
 */
$kitgenix_multistore_sync_for_woocommerce_remove_site_data = static function (): void {
    global $wpdb;

    foreach ( array( 'stores', 'mappings', 'events', 'deliveries', 'inventory', 'inventory_ledger', 'remote_orders', 'customers', 'conflicts', 'automations', 'sequences', 'logs' ) as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_kitgenix_multistore_sync_for_woocommerce_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kitgenix_multistore_sync_for_woocommerce_%' OR option_name LIKE '_transient_timeout_kitgenix_multistore_sync_for_woocommerce_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    foreach ( array( 'kitgenix_multistore_sync_for_woocommerce_settings', 'kitgenix_multistore_sync_for_woocommerce_site_uuid', 'kitgenix_multistore_sync_for_woocommerce_revision', 'kitgenix_multistore_sync_for_woocommerce_db_version', 'kitgenix_multistore_sync_for_woocommerce_woomultistore_migrated_at', 'kitgenix_multistore_sync_for_woocommerce_native_seen_generation'  ) as $option ) {
        delete_option( $option );
    }

    foreach ( wp_roles()->roles as $role_key => $_role_data ) { $role = get_role( $role_key ); if ( $role ) $role->remove_cap( 'kitgenix_multistore_sync_for_woocommerce_manage_multistore' ); }
};

if ( is_multisite() ) {
    $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( (int) $site_id );
        $kitgenix_multistore_sync_for_woocommerce_remove_site_data();
        restore_current_blog();
    }
} else {
    $kitgenix_multistore_sync_for_woocommerce_remove_site_data();
}

if ( is_multisite() ) { delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_enabled' ); delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_control_blog_id' ); delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_secret' ); delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation' ); }
