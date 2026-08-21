<?php
namespace Kitgenix\MultiStore\Integration;

use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Settings;

final class ImportBridge {
    public function __construct( private Settings $settings ) {}

    public function hooks(): void {
        add_action( 'pmxi_saved_post', array( $this, 'wp_all_import_saved' ), 50, 1 );
    }

    public function wp_all_import_saved( int $post_id ): void {
        if ( Context::is_remote() || 'yes' !== $this->settings->get( 'sync_products', 'yes' ) || 'product' !== get_post_type( $post_id ) ) return;
        if ( $this->settings->is_connected() && 'bidirectional' !== $this->settings->get( 'product_direction', 'control_only' ) ) return;
        if ( function_exists( 'as_enqueue_async_action' ) ) as_enqueue_async_action( 'kitgenix_multistore_sync_for_woocommerce_sync_product_job', array( $post_id ), 'kitgenix-multistore', true );
        elseif ( ! wp_next_scheduled( 'kitgenix_multistore_sync_for_woocommerce_sync_product_job', array( $post_id ) ) ) wp_schedule_single_event( time() + 5, 'kitgenix_multistore_sync_for_woocommerce_sync_product_job', array( $post_id ) );
    }
}
