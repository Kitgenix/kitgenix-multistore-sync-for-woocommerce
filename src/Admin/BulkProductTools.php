<?php
namespace Kitgenix\MultiStore\Admin;

use Kitgenix\MultiStore\Sync\ProductSync;

final class BulkProductTools {
    public function __construct( private ProductSync $sync ) {}

    public function hooks(): void {
        add_action( 'woocommerce_product_quick_edit_start', array( $this, 'quick_fields' ) );
        add_action( 'woocommerce_product_quick_edit_save', array( $this, 'quick_save' ), 20, 1 );
        add_action( 'woocommerce_product_bulk_edit_start', array( $this, 'bulk_fields' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_save' ), 20, 1 );
        add_filter( 'manage_edit-product_columns', array( $this, 'column' ), 30 );
        add_action( 'manage_product_posts_custom_column', array( $this, 'column_value' ), 30, 2 );
    }

    public function quick_fields(): void { $this->field( '_kitgenix_multistore_sync_for_woocommerce_quick_sync', __( 'Kitgenix MultiStore', 'kitgenix-multistore-sync-for-woocommerce' ) ); }
    public function bulk_fields(): void { $this->field( '_kitgenix_multistore_sync_for_woocommerce_bulk_sync', __( 'Kitgenix MultiStore', 'kitgenix-multistore-sync-for-woocommerce' ) ); }

    private function field( string $name, string $label ): void {
        echo '<div class="inline-edit-group"><label class="alignleft"><span class="title">' . esc_html( $label ) . '</span><span class="input-text-wrap"><select name="' . esc_attr( $name ) . '"><option value="">– No change –</option><option value="yes">Enable network sync</option><option value="no">Disable network sync</option><option value="sync">Enable and sync now</option></select></span></label></div>';
    }

    public function quick_save( \WC_Product $product ): void { $this->save( $product, '_kitgenix_multistore_sync_for_woocommerce_quick_sync' ); }
    public function bulk_save( \WC_Product $product ): void { $this->save( $product, '_kitgenix_multistore_sync_for_woocommerce_bulk_sync' ); }

    private function save( \WC_Product $product, string $key ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- fired from WooCommerce's own woocommerce_product_{quick,bulk}_edit_save hooks, which only run after WooCommerce's quick/bulk-edit AJAX handlers have already verified their own nonce; capability is still checked here.
        if ( ! current_user_can( 'edit_post', $product->get_id() ) || ! isset( $_REQUEST[ $key ] ) ) return;
        $value = sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ); if ( ! in_array( $value, array( 'yes','no','sync' ), true ) ) return;
        // phpcs:enable
        update_post_meta( $product->get_id(), '_kitgenix_multistore_sync_for_woocommerce_sync_enabled', 'no' === $value ? 'no' : 'yes' );
        if ( 'sync' === $value ) $this->sync->sync_now( $product->get_id() );
    }

    public function column( array $columns ): array { $columns['kitgenix_multistore_sync_for_woocommerce_multistore'] = __( 'MultiStore', 'kitgenix-multistore-sync-for-woocommerce' ); return $columns; }
    public function column_value( string $column, int $post_id ): void {
        if ( 'kitgenix_multistore_sync_for_woocommerce_multistore' !== $column ) return; $enabled = 'no' !== get_post_meta( $post_id, '_kitgenix_multistore_sync_for_woocommerce_sync_enabled', true ); $network = (string) get_post_meta( $post_id, '_kitgenix_multistore_sync_for_woocommerce_network_id', true );
        echo '<span class="kitgenix-multistore-sync-for-woocommerce-pill is-' . ( $enabled ? 'active' : 'disabled' ) . '">' . esc_html( $enabled ? 'Sync on' : 'Sync off' ) . '</span>'; if ( $network ) echo '<br><code title="' . esc_attr( $network ) . '">' . esc_html( substr( $network, 0, 8 ) ) . '…</code>';
    }
}
