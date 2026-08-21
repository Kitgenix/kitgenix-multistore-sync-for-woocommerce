<?php
namespace Kitgenix\MultiStore\Multisite;

use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class NativeNetwork {
    public function __construct( private StoreRepository $stores, private Settings $settings, private Logger $logger ) {}

    public function hooks(): void {
        add_action( 'init', array( $this, 'discover' ), 2 );
        add_action( 'wp_initialize_site', array( $this, 'rediscover_later' ), 50 );
        add_action( 'wp_uninitialize_site', array( $this, 'rediscover_later' ), 50 );
    }

    public function rediscover_later(): void { update_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation', (int) get_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation', 1 ) + 1 ); }

    public function discover(): void {
        if ( ! is_multisite() ) return;
        $enabled = 'yes' === (string) get_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_enabled', 'no' ) || 'native_multisite' === $this->settings->get( 'network_mode', 'paired' );
        if ( ! $enabled ) return;
        $control_blog = absint( get_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_control_blog_id', 0 ) ) ?: absint( $this->settings->get( 'native_control_blog_id', 0 ) ) ?: (int) get_main_site_id();
        $current = get_current_blog_id();
        $this->settings->set( array( 'network_mode' => 'native_multisite', 'native_control_blog_id' => $control_blog, 'role' => $current === $control_blog ? 'control' : 'connected' ) );
        $generation = max( 1, (int) get_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation', 1 ) );
        if ( (int) get_option( 'kitgenix_multistore_sync_for_woocommerce_native_seen_generation', 0 ) === $generation ) return;
        $secret = (string) get_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_secret', '' );
        if ( strlen( $secret ) < 48 ) { $secret = bin2hex( random_bytes( 32 ) ); update_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_secret', $secret ); }
        $sites = get_sites( array( 'number' => 0 ) );
        foreach ( $sites as $site ) {
            $blog_id = (int) $site->blog_id; if ( $blog_id === $current ) continue;
            switch_to_blog( $blog_id );
            $uuid = Uuid::site(); $name = get_bloginfo( 'name' ) ?: 'WooCommerce Store'; $url = home_url();
            restore_current_blog();
            $saved = $this->stores->save( array(
                'uuid' => $uuid, 'name' => $name, 'url' => $url, 'role' => $blog_id === $control_blog ? 'control' : 'connected', 'status' => 'active',
                'capabilities' => array( 'native_multisite' => true, 'blog_id' => $blog_id ),
            ), $secret );
            if ( is_wp_error( $saved ) ) $this->logger->warning( 'Native multisite store discovery failed.', array( 'blog_id' => $blog_id, 'error' => $saved->get_error_message() ) );
        }
        update_option( 'kitgenix_multistore_sync_for_woocommerce_native_seen_generation', $generation, false );
    }

    public static function enable( int $control_blog_id ): void {
        if ( ! is_multisite() ) return;
        update_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_enabled', 'yes' ); update_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_control_blog_id', $control_blog_id ); update_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation', (int) get_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation', 1 ) + 1 );
    }

    public static function disable(): void { if ( is_multisite() ) { update_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_enabled', 'no' ); update_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation', (int) get_site_option( 'kitgenix_multistore_sync_for_woocommerce_native_generation', 1 ) + 1 ); } }
}
