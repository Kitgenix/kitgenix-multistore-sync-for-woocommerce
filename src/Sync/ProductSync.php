<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;

final class ProductSync {
    /**
     * Props that represent a pure stock movement, already carried by
     * InventorySync's own woocommerce_*_set_stock / woocommerce_product_object_updated_props
     * hooks. A save whose ONLY updated props fall in this set does not also
     * need a full product payload sync. manage_stock and backorders are
     * deliberately excluded - they are product configuration, not a stock
     * quantity movement, and must still trigger a full sync.
     */
    private const STOCK_ONLY_PROPS = array( 'stock_quantity', 'stock_status' );

    private array $pending = array();
    /** @var array<int,array<int,string>> Updated-prop lists captured per product/variation ID for the current save cycle. */
    private array $updated_props = array();

    public function __construct(
        private MappingRepository $mappings,
        private ProductPayload $payload,
        private Dispatcher $dispatcher,
        private InventorySync $inventory,
        private Settings $settings,
        private Logger $logger
    ) {}

    public function hooks(): void {
        add_action( 'woocommerce_product_object_updated_props', array( $this, 'capture_updated_props' ), 5, 2 );
        add_action( 'woocommerce_new_product', array( $this, 'queue_product' ), 20, 1 );
        add_action( 'woocommerce_update_product', array( $this, 'queue_product' ), 20, 1 );
        add_action( 'woocommerce_new_product_variation', array( $this, 'queue_variation' ), 20, 1 );
        add_action( 'woocommerce_update_product_variation', array( $this, 'queue_variation' ), 20, 1 );
        add_action( 'trashed_post', array( $this, 'trash' ), 20, 1 );
        add_action( 'shutdown', array( $this, 'flush' ), 20 );
        add_action( 'kitgenix_multistore_sync_for_woocommerce_sync_product_job', array( $this, 'sync_job' ), 10, 1 );
    }

    /**
     * WooCommerce fires this before woocommerce_update_product /
     * woocommerce_update_product_variation in WC_Product_Data_Store_CRUD::update(),
     * so it is always available by the time queue_product()/queue_variation()
     * inspect it for the same save.
     */
    public function capture_updated_props( \WC_Product $product, array $updated_props ): void {
        $this->updated_props[ $product->get_id() ] = array_map( 'strval', $updated_props );
    }

    private function is_stock_only_change( int $id ): bool {
        $props = $this->updated_props[ $id ] ?? null; unset( $this->updated_props[ $id ] );
        return null !== $props && array() === array_diff( $props, self::STOCK_ONLY_PROPS );
    }

    public function queue_product( int $id ): void {
        if ( Context::is_remote() || 'yes' !== $this->settings->get( 'sync_products', 'yes' ) ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST && 'yes' !== $this->settings->get( 'sync_rest_products', 'yes' ) ) return;
        if ( $this->settings->is_connected() && 'bidirectional' !== $this->settings->get( 'product_direction', 'control_only' ) ) return;
        if ( 'product' !== get_post_type( $id ) ) return;
        // A stock-quantity/status-only save is already carried by InventorySync's
        // dedicated pathway; queuing a full product sync for it too would be
        // exactly the redundant-save pattern this hardening exists to prevent.
        if ( $this->is_stock_only_change( $id ) ) return;
        $this->pending[ $id ] = true;
    }

    public function queue_variation( int $id ): void {
        $parent = wp_get_post_parent_id( $id ); if ( ! $parent ) return;
        if ( $this->is_stock_only_change( $id ) ) return;
        $this->queue_product( (int) $parent );
    }
    public function flush(): void { foreach ( array_keys( $this->pending ) as $id ) $this->sync_now( (int) $id ); $this->pending = array(); }
    public function sync_job( int $id ): void { $this->sync_now( $id ); }

    public function schedule_all(): int {
        $count = 0; $page = 1;
        do {
            $query = new \WP_Query( array( 'post_type' => 'product', 'post_status' => array( 'publish','draft','private','pending' ), 'posts_per_page' => 250, 'paged' => $page, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => false ) );
            $ids = array_map( 'absint', (array) $query->posts );
            foreach ( $ids as $id ) {
                if ( function_exists( 'as_enqueue_async_action' ) ) as_enqueue_async_action( 'kitgenix_multistore_sync_for_woocommerce_sync_product_job', array( $id ), 'kitgenix-multistore-sync-for-woocommerce', true );
                else wp_schedule_single_event( time() + min( $count, 300 ), 'kitgenix_multistore_sync_for_woocommerce_sync_product_job', array( $id ) );
                ++$count;
            }
            ++$page;
        } while ( $ids && $page <= (int) $query->max_num_pages );
        return $count;
    }

    public function sync_now( int $id, array $force_targets = array() ): array|\WP_Error {
        $product = wc_get_product( $id );
        if ( ! $product || $product->is_type( 'variation' ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_product_missing', 'Product was not found.' );
        if ( 'no' === get_post_meta( $id, '_kitgenix_multistore_sync_for_woocommerce_sync_enabled', true ) ) return array( 'skipped' => true, 'reason' => 'sync_disabled' );

        $target_info = $this->targets_for_product( $id, $force_targets );
        if ( $target_info['explicit'] && ! $target_info['targets'] ) return array( 'skipped' => true, 'reason' => 'no_valid_targets' );

        $payload = $this->payload->build( $product );
        $event = $this->dispatcher->dispatch( 'product_upsert', 'product', $payload['network_id'], $payload, $target_info['targets'] );
        if ( ! is_wp_error( $event ) ) {
            $this->inventory->seed_product( $product );
            $this->logger->info( 'Product queued for network sync.', array( 'product_id' => $id, 'network_id' => $payload['network_id'] ) );
        }
        return $event;
    }

    public function trash( int $id ): void {
        if ( Context::is_remote() || 'product' !== get_post_type( $id ) ) return;
        if ( $this->settings->is_connected() && 'bidirectional' !== $this->settings->get( 'product_direction', 'control_only' ) ) return;
        $network = (string) get_post_meta( $id, '_kitgenix_multistore_sync_for_woocommerce_network_id', true );
        if ( ! $network ) return;
        $target_info = $this->targets_for_product( $id );
        if ( $target_info['explicit'] && ! $target_info['targets'] ) return;
        $this->dispatcher->dispatch( 'product_trash', 'product', $network, array( 'network_id' => $network ), $target_info['targets'] );
    }

    /**
     * Whether a given store is allowed to receive this product per its own
     * sync-enabled flag and (if configured) explicit target stores/groups.
     * Used by Reconciler so drift repairs never re-add a store a product was
     * deliberately excluded from.
     */
    public function is_target_allowed( int $product_id, string $store_uuid ): bool {
        if ( 'no' === get_post_meta( $product_id, '_kitgenix_multistore_sync_for_woocommerce_sync_enabled', true ) ) return false;
        $info = $this->targets_for_product( $product_id );
        if ( ! $info['explicit'] ) return true;
        return in_array( $store_uuid, $info['targets'], true );
    }

    /** @return array{targets:array<int,string>,explicit:bool} */
    private function targets_for_product( int $id, array $force_targets = array() ): array {
        if ( $force_targets ) return array( 'targets' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $force_targets ) ) ) ), 'explicit' => true );
        $direct = array_values( array_filter( (array) get_post_meta( $id, '_kitgenix_multistore_sync_for_woocommerce_target_stores', true ) ) );
        $selected_groups = array_values( array_filter( (array) get_post_meta( $id, '_kitgenix_multistore_sync_for_woocommerce_target_groups', true ) ) );
        $targets = $direct;
        $groups = $this->settings->groups();
        foreach ( $selected_groups as $group_id ) if ( isset( $groups[ $group_id ] ) ) $targets = array_merge( $targets, (array) $groups[ $group_id ]['stores'] );
        return array( 'targets' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $targets ) ) ) ), 'explicit' => (bool) ( $direct || $selected_groups ) );
    }
}
