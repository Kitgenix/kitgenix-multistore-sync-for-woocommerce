<?php
namespace Kitgenix\MultiStore\Orders;

use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class OrderImporter {
    public function __construct( private MappingRepository $mappings, private Settings $settings, private Logger $logger ) {}

    public function hooks(): void {
        foreach ( array( 'new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note' ) as $email ) {
            add_filter( 'woocommerce_email_enabled_' . $email, array( $this, 'email_enabled' ), 20, 2 );
        }
        add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'can_change_stock' ), 20, 2 );
        add_filter( 'woocommerce_can_restore_order_stock', array( $this, 'can_change_stock' ), 20, 2 );
    }

    public function email_enabled( bool $enabled, mixed $object ): bool {
        $order = $object instanceof \WC_Order ? $object : ( is_numeric( $object ) ? wc_get_order( (int) $object ) : false );
        return $order && 'yes' === $order->get_meta( '_kitgenix_multistore_sync_for_woocommerce_imported_order', true ) ? false : $enabled;
    }

    public function can_change_stock( bool $allowed, \WC_Order $order ): bool {
        return 'yes' === $order->get_meta( '_kitgenix_multistore_sync_for_woocommerce_imported_order', true ) ? false : $allowed;
    }

    public function import( array $payload, string $source_store ): int|\WP_Error {
        if ( 'woocommerce' !== $this->settings->get( 'order_import_mode', 'snapshot' ) ) return 0;
        $network = sanitize_text_field( (string) ( $payload['network_id'] ?? '' ) );
        if ( ! wp_is_uuid( $network ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_import_order_network', 'Order network ID is invalid.' );
        $existing = $this->mappings->local_id( $network, 'order_import' );
        $existing_order = $existing ? wc_get_order( $existing ) : false;

        return Context::remote( function() use ( $payload, $source_store, $network, $existing_order ) {
            $order = $existing_order ?: wc_create_order( array( 'status' => 'pending', 'created_via' => 'kitgenix-multistore-sync-for-woocommerce' ) );
            if ( is_wp_error( $order ) ) return $order;
            if ( ! $order instanceof \WC_Order ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_import_order_create', 'WooCommerce could not create the imported order.' );

            // Tag the object before any status save so stock/email filters see the import marker.
            $order->update_meta_data( '_kitgenix_multistore_sync_for_woocommerce_imported_order', 'yes' );
            $order->update_meta_data( '_kitgenix_multistore_sync_for_woocommerce_origin_store_uuid', sanitize_text_field( $source_store ) );
            $order->update_meta_data( '_kitgenix_multistore_sync_for_woocommerce_origin_order_id', (int) ( $payload['order_id'] ?? 0 ) );
            $order->update_meta_data( '_kitgenix_multistore_sync_for_woocommerce_network_id', $network );
            if ( ! empty( $payload['number'] ) ) $order->update_meta_data( '_kitgenix_multistore_sync_for_woocommerce_origin_order_number', sanitize_text_field( (string) $payload['number'] ) );

            $status = sanitize_key( (string) ( $payload['status'] ?? 'pending' ) );
            if ( array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) $order->set_status( $status );
            $order->set_currency( sanitize_text_field( (string) ( $payload['currency'] ?? get_woocommerce_currency() ) ) );
            if ( ! empty( $payload['payment_method'] ) ) $order->set_payment_method( sanitize_text_field( (string) $payload['payment_method'] ) );
            $order->set_payment_method_title( sanitize_text_field( (string) ( $payload['payment_method_title'] ?? '' ) ) );
            $order->set_customer_note( sanitize_textarea_field( (string) ( $payload['customer_note'] ?? '' ) ) );
            if ( ! empty( $payload['date_created'] ) ) {
                try {
                    $order->set_date_created( (string) $payload['date_created'] );
                } catch ( \Throwable $e ) {
                    $this->logger->warning( 'Imported order date could not be parsed; kept default order date.', array(
                        'code'  => 'kitgenix_multistore_sync_for_woocommerce_order_date_exception',
                        'error' => $e->getMessage(),
                        'value' => (string) $payload['date_created'],
                    ) );
                }
            }

            foreach ( array( 'billing','shipping' ) as $address_type ) {
                foreach ( (array) ( $payload[ $address_type ] ?? array() ) as $field => $value ) {
                    $method = 'set_' . $address_type . '_' . sanitize_key( $field );
                    if ( method_exists( $order, $method ) ) $order->{$method}( sanitize_text_field( (string) $value ) );
                }
            }

            foreach ( $order->get_items( array( 'line_item','shipping','fee','coupon' ) ) as $item_id => $_item ) $order->remove_item( $item_id );

            foreach ( (array) ( $payload['items'] ?? array() ) as $line ) {
                if ( ! is_array( $line ) ) continue;
                $product_id = ! empty( $line['sku'] ) ? (int) wc_get_product_id_by_sku( wc_clean( $line['sku'] ) ) : 0;
                if ( ! $product_id && 'yes' === $this->settings->get( 'clone_products_on_order_import', 'no' ) ) $product_id = $this->placeholder_product( $line );
                $item = new \WC_Order_Item_Product();
                if ( $product_id ) $item->set_product( wc_get_product( $product_id ) );
                else $item->set_name( sanitize_text_field( (string) ( $line['name'] ?? 'Product' ) ) );
                $item->set_quantity( (float) ( $line['quantity'] ?? 1 ) );
                $item->set_subtotal( wc_format_decimal( $line['subtotal'] ?? 0 ) );
                $item->set_subtotal_tax( wc_format_decimal( $line['subtotal_tax'] ?? 0 ) );
                $item->set_total( wc_format_decimal( $line['total'] ?? 0 ) );
                $item->set_total_tax( wc_format_decimal( $line['total_tax'] ?? 0 ) );
                if ( ! empty( $line['taxes'] ) && is_array( $line['taxes'] ) ) $item->set_taxes( $line['taxes'] );
                $order->add_item( $item );
            }

            foreach ( (array) ( $payload['shipping_lines'] ?? array() ) as $line ) {
                if ( ! is_array( $line ) ) continue;
                $item = new \WC_Order_Item_Shipping();
                $item->set_method_title( sanitize_text_field( (string) ( $line['name'] ?? 'Shipping' ) ) );
                $item->set_method_id( sanitize_key( (string) ( $line['method_id'] ?? '' ) ) );
                if ( method_exists( $item, 'set_instance_id' ) ) $item->set_instance_id( absint( $line['instance_id'] ?? 0 ) );
                $item->set_total( wc_format_decimal( $line['total'] ?? 0 ) );
                if ( ! empty( $line['taxes'] ) && is_array( $line['taxes'] ) ) $item->set_taxes( $line['taxes'] );
                $order->add_item( $item );
            }

            foreach ( (array) ( $payload['fee_lines'] ?? array() ) as $line ) {
                if ( ! is_array( $line ) ) continue;
                $item = new \WC_Order_Item_Fee();
                $item->set_name( sanitize_text_field( (string) ( $line['name'] ?? 'Fee' ) ) );
                $item->set_amount( wc_format_decimal( $line['amount'] ?? 0 ) );
                $item->set_total( wc_format_decimal( $line['total'] ?? 0 ) );
                $item->set_total_tax( wc_format_decimal( $line['total_tax'] ?? 0 ) );
                $item->set_tax_class( sanitize_text_field( (string) ( $line['tax_class'] ?? '' ) ) );
                $item->set_tax_status( sanitize_key( (string) ( $line['tax_status'] ?? 'none' ) ) );
                if ( ! empty( $line['taxes'] ) && is_array( $line['taxes'] ) ) $item->set_taxes( $line['taxes'] );
                $order->add_item( $item );
            }

            $coupon_lines = (array) ( $payload['coupon_lines'] ?? array() );
            if ( $coupon_lines ) {
                foreach ( $coupon_lines as $line ) {
                    if ( ! is_array( $line ) ) continue;
                    $code = wc_format_coupon_code( (string) ( $line['code'] ?? '' ) ); if ( ! $code ) continue;
                    $item = new \WC_Order_Item_Coupon(); $item->set_code( $code );
                    $item->set_discount( wc_format_decimal( $line['discount'] ?? 0 ) );
                    $item->set_discount_tax( wc_format_decimal( $line['discount_tax'] ?? 0 ) );
                    $order->add_item( $item );
                }
            } else {
                foreach ( (array) ( $payload['coupon_codes'] ?? array() ) as $code ) {
                    $code = wc_format_coupon_code( (string) $code ); if ( ! $code ) continue;
                    $item = new \WC_Order_Item_Coupon(); $item->set_code( $code ); $order->add_item( $item );
                }
            }

            $order->set_shipping_total( wc_format_decimal( $payload['shipping_total'] ?? 0 ) );
            $order->set_shipping_tax( wc_format_decimal( $payload['shipping_tax'] ?? 0 ) );
            $order->set_discount_total( wc_format_decimal( $payload['discount_total'] ?? 0 ) );
            $order->set_discount_tax( wc_format_decimal( $payload['discount_tax'] ?? 0 ) );
            $order->set_cart_tax( wc_format_decimal( $payload['cart_tax'] ?? ( $payload['tax_total'] ?? 0 ) ) );
            $order->set_total( wc_format_decimal( $payload['total'] ?? 0 ) );
            $order->save();
            $this->mappings->upsert( $network, 'order_import', Uuid::site(), $order->get_id(), (string) $order->get_order_number(), 0 );
            return $order->get_id();
        } );
    }

    private function placeholder_product( array $line ): int {
        $product = new \WC_Product_Simple();
        $product->set_name( sanitize_text_field( (string) ( $line['name'] ?? 'Imported product' ) ) );
        $product->set_status( 'draft' );
        $sku = wc_clean( (string) ( $line['sku'] ?? '' ) );
        if ( $sku && wc_product_has_unique_sku( 0, $sku ) ) $product->set_sku( $sku );
        $product->set_catalog_visibility( 'hidden' );
        $id = $product->save();
        update_post_meta( $id, '_kitgenix_multistore_sync_for_woocommerce_order_import_placeholder', 'yes' );
        return (int) $id;
    }
}
