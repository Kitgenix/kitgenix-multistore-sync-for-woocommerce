<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Two independent, deliberate ways to opt in to data removal – neither is the
 * default. The wp-config.php constant suits automation/CI; the in-plugin
 * "Remove all MultiStore data on uninstall" setting suits a site owner who
 * wants a clean removal without editing wp-config.php. Either one is enough;
 * the default (both absent/off) is to keep everything, since these tables
 * can hold cross-store order/customer history that is expensive to rebuild.
 * Neither path ever touches actual WooCommerce products/orders – only this
 * plugin's own tables, options, and postmeta.
 */
$kitgenix_multistore_sync_for_woocommerce_settings = (array) get_option( 'kitgenix_multistore_sync_for_woocommerce_settings', array() );
$kitgenix_multistore_sync_for_woocommerce_opt_in   = 'yes' === (string) ( $kitgenix_multistore_sync_for_woocommerce_settings['remove_data_on_uninstall'] ?? 'no' );
$kitgenix_multistore_sync_for_woocommerce_const_in = defined( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA' ) && true === KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA;

if ( ! $kitgenix_multistore_sync_for_woocommerce_opt_in && ! $kitgenix_multistore_sync_for_woocommerce_const_in ) {
    return;
}

/**
 * Remove Kitgenix MultiStore data from the current site.
 */
$kitgenix_multistore_sync_for_woocommerce_remove_site_data = static function (): void {
    global $wpdb;

    // 'inv_ledger' (renamed from 'inventory_ledger' - see Schema::install()'s
    // self_heal_ledger_table_rename() - the old name is over MySQL's 64-char
    // identifier limit on sites with a longer table prefix). Every site is on
    // the new name after one page load post-update, so the old literal name
    // is never a real table any more; only the current name needs dropping.
    foreach ( array( 'stores', 'mappings', 'events', 'deliveries', 'inventory', 'inv_ledger', 'remote_orders', 'customers', 'conflicts', 'automations', 'sequences', 'logs' ) as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall-time schema removal of this plugin's own tables; a direct query is the only option and caching is not applicable to a DROP TABLE.
    }

    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_kitgenix_multistore_sync_for_woocommerce_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-time bulk cleanup of this plugin's own postmeta; a direct query is the only option and the data is being permanently deleted, not cached.
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kitgenix_multistore_sync_for_woocommerce_%' OR option_name LIKE '_transient_timeout_kitgenix_multistore_sync_for_woocommerce_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-time bulk cleanup of this plugin's own transients; a direct query is the only option and the data is being permanently deleted, not cached.

    foreach ( array( 'kitgenix_multistore_sync_for_woocommerce_settings', 'kitgenix_multistore_sync_for_woocommerce_site_uuid', 'kitgenix_multistore_sync_for_woocommerce_revision', 'kitgenix_multistore_sync_for_woocommerce_db_version', 'kitgenix_multistore_sync_for_woocommerce_woomultistore_migrated_at', 'kitgenix_multistore_sync_for_woocommerce_native_seen_generation'  ) as $option ) {
        delete_option( $option );
    }

    foreach ( wp_roles()->roles as $role_key => $_role_data ) { $role = get_role( $role_key ); if ( $role ) $role->remove_cap( 'kitgenix_multistore_sync_for_woocommerce_manage_multistore' ); }
};

if ( is_multisite() ) {
    $kitgenix_multistore_sync_for_woocommerce_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $kitgenix_multistore_sync_for_woocommerce_site_ids as $kitgenix_multistore_sync_for_woocommerce_site_id ) {
        switch_to_blog( (int) $kitgenix_multistore_sync_for_woocommerce_site_id );
        $kitgenix_multistore_sync_for_woocommerce_remove_site_data();
        restore_current_blog();
    }
} else {
    $kitgenix_multistore_sync_for_woocommerce_remove_site_data();
}

if ( is_multisite() ) { delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_enabled' ); delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_control_blog_id' ); delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_secret' ); delete_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation' ); }
