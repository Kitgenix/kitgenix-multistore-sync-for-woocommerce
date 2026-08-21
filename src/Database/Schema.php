<?php
namespace Kitgenix\MultiStore\Database;

use Kitgenix\MultiStore\Support\Uuid;

final class Schema {
    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            $sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
            foreach ( $sites as $blog_id ) {
                switch_to_blog( (int) $blog_id );
                self::install();
                restore_current_blog();
            }
        } else {
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'kitgenix_multistore_sync_for_woocommerce_';

        $sql = array();
        $sql[] = "CREATE TABLE {$p}stores (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            name varchar(191) NOT NULL,
            url varchar(255) NOT NULL,
            role varchar(32) NOT NULL DEFAULT 'connected',
            secret_enc longtext NULL,
            status varchar(32) NOT NULL DEFAULT 'unknown',
            capabilities longtext NULL,
            last_seen datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}mappings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            network_id char(36) NOT NULL,
            entity_type varchar(32) NOT NULL,
            store_uuid char(36) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_key varchar(191) NOT NULL DEFAULT '',
            last_revision bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entity_store (network_id, entity_type, store_uuid),
            UNIQUE KEY local_object (entity_type, store_uuid, object_id),
            KEY object_key (entity_type, object_key)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_uuid char(36) NOT NULL,
            parent_event_uuid char(36) NULL,
            entity_type varchar(32) NOT NULL,
            network_id char(36) NOT NULL,
            event_type varchar(64) NOT NULL,
            source_store_uuid char(36) NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_uuid (event_uuid),
            KEY status (status),
            KEY entity (entity_type, network_id),
            KEY created_at (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}deliveries (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_uuid char(36) NOT NULL,
            store_uuid char(36) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            next_attempt datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_store (event_uuid, store_uuid),
            KEY status (status),
            KEY next_attempt (next_attempt)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}inventory (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            network_id char(36) NOT NULL,
            quantity decimal(19,4) NULL,
            stock_status varchar(32) NOT NULL DEFAULT 'instock',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            source_store_uuid char(36) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY network_id (network_id),
            KEY revision (revision)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}inventory_ledger (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_uuid char(36) NOT NULL,
            network_id char(36) NOT NULL,
            event_uuid char(36) NOT NULL,
            source_store_uuid char(36) NOT NULL,
            delta decimal(19,4) NOT NULL DEFAULT 0,
            quantity_after decimal(19,4) NULL,
            reason varchar(64) NOT NULL DEFAULT 'adjustment',
            reference varchar(191) NOT NULL DEFAULT '',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entry_uuid (entry_uuid),
            KEY network_id (network_id),
            UNIQUE KEY event_uuid (event_uuid)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}remote_orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            network_id char(36) NOT NULL,
            store_uuid char(36) NOT NULL,
            remote_order_id bigint(20) unsigned NOT NULL,
            order_number varchar(64) NOT NULL DEFAULT '',
            status varchar(64) NOT NULL DEFAULT '',
            currency varchar(12) NOT NULL DEFAULT '',
            total decimal(19,4) NOT NULL DEFAULT 0,
            customer_email varchar(191) NOT NULL DEFAULT '',
            customer_name varchar(191) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            order_created_at datetime NULL,
            order_updated_at datetime NULL,
            synced_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY network_id (network_id),
            KEY store_order (store_uuid, remote_order_id),
            KEY status (status),
            KEY synced_at (synced_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}customers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            network_id char(36) NOT NULL,
            store_uuid char(36) NOT NULL,
            remote_customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            email varchar(191) NOT NULL DEFAULT '',
            display_name varchar(191) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            synced_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY network_store (network_id, store_uuid),
            KEY email (email)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}conflicts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conflict_uuid char(36) NOT NULL,
            event_uuid char(36) NOT NULL DEFAULT '',
            entity_type varchar(32) NOT NULL DEFAULT '',
            network_id char(36) NOT NULL DEFAULT '',
            store_uuid char(36) NOT NULL DEFAULT '',
            conflict_type varchar(64) NOT NULL DEFAULT 'sync_error',
            message text NOT NULL,
            payload longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            resolved_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY conflict_uuid (conflict_uuid),
            KEY status (status),
            KEY network_id (network_id),
            KEY created_at (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}automations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            trigger_type varchar(64) NOT NULL,
            trigger_config longtext NOT NULL,
            action_type varchar(64) NOT NULL,
            action_config longtext NOT NULL,
            last_run datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY enabled_trigger (enabled, trigger_type)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}sequences (
            sequence_key varchar(64) NOT NULL,
            current_value bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (sequence_key)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset};";

        foreach ( $sql as $statement ) {
            dbDelta( $statement );
        }

        Uuid::site();
        if ( false === get_option( 'kitgenix_multistore_sync_for_woocommerce_settings', false ) ) {
            add_option( 'kitgenix_multistore_sync_for_woocommerce_settings', array(), '', false );
        }
        update_option( 'kitgenix_multistore_sync_for_woocommerce_db_version', KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_DB_VERSION, false );

    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'kitgenix_multistore_sync_for_woocommerce_fallback_health_check' );
        wp_clear_scheduled_hook( 'kitgenix_multistore_sync_for_woocommerce_fallback_reconcile' );
        wp_clear_scheduled_hook( 'kitgenix_multistore_sync_for_woocommerce_fallback_cleanup' );
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            foreach ( array( 'kitgenix_multistore_sync_for_woocommerce_health_check', 'kitgenix_multistore_sync_for_woocommerce_reconcile', 'kitgenix_multistore_sync_for_woocommerce_cleanup' ) as $hook ) {
                as_unschedule_all_actions( $hook, array(), 'kitgenix-multistore' );
            }
        }
    }
}
