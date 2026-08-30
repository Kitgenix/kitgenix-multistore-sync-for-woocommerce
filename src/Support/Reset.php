<?php
namespace Kitgenix\MultiStore\Support;

use Kitgenix\MultiStore\Database\Schema;
use Kitgenix\MultiStore\Multisite\NativeNetwork;

final class Reset {
    /**
     * Wipes every trace of this plugin from the current site - the same tables,
     * postmeta, transients, options, capability and cron/Action Scheduler cleanup
     * as uninstall.php's opt-in "Remove all MultiStore data on uninstall" path -
     * then immediately re-runs Schema::install() so the plugin keeps working with
     * a fresh site identifier and default settings, as though just activated.
     * Lets a site owner clear a stale store connection without having to flip
     * that setting, delete the plugin, and reinstall it.
     */
    public static function factory_reset(): void {
        global $wpdb;
        $p = $wpdb->prefix . 'kitgenix_multistore_sync_for_woocommerce_';

        foreach ( array( 'stores', 'mappings', 'events', 'deliveries', 'inventory', 'inv_ledger', 'remote_orders', 'customers', 'conflicts', 'automations', 'sequences', 'logs' ) as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$p}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- admin-triggered factory reset of this plugin's own tables; a direct query is the only option and caching is not applicable to a DROP TABLE.
        }

        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_kitgenix_multistore_sync_for_woocommerce_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-triggered factory reset of this plugin's own postmeta; a direct query is the only option and the data is being permanently deleted, not cached.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kitgenix_multistore_sync_for_woocommerce_%' OR option_name LIKE '_transient_timeout_kitgenix_multistore_sync_for_woocommerce_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-triggered factory reset of this plugin's own transients; a direct query is the only option and the data is being permanently deleted, not cached.

        foreach ( array( 'kitgenix_multistore_sync_for_woocommerce_settings', 'kitgenix_multistore_sync_for_woocommerce_site_uuid', 'kitgenix_multistore_sync_for_woocommerce_revision', 'kitgenix_multistore_sync_for_woocommerce_db_version', 'kitgenix_multistore_sync_for_woocommerce_woomultistore_migrated_at', 'kitgenix_multistore_sync_for_woocommerce_native_seen_generation' ) as $option ) {
            delete_option( $option );
        }

        foreach ( wp_roles()->roles as $role_key => $_role_data ) { $role = get_role( $role_key ); if ( $role ) $role->remove_cap( Access::CAP ); }

        NativeNetwork::disable();
        Schema::deactivate();
        Schema::install();
    }
}
