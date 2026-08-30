<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Orders\OrderImporter;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Repository\OrderRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class OrderSync {
    private array $pending = array();

    public function __construct(
        private OrderRepository $orders,
        private MappingRepository $mappings,
        private Dispatcher $dispatcher,
        private StoreRepository $stores,
        private Client $client,
        private Settings $settings,
        private Logger $logger,
        private OrderImporter $importer
    ) {}

    public function hooks(): void {
        add_action( 'woocommerce_new_order', array( $this, 'queue' ), 30, 1 );
        add_action( 'woocommerce_update_order', array( $this, 'queue' ), 30, 1 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 30, 4 );
        add_action( 'shutdown', array( $this, 'flush' ), 30 );
        add_action( 'kitgenix_multistore_sync_for_woocommerce_index_order_job', array( $this, 'sync_now' ), 10, 1 );
    }

    public function queue( int $order_id ): void {
        if ( Context::is_remote() || 'yes' !== $this->settings->get( 'sync_orders', 'yes' ) ) return;
        $order = wc_get_order( $order_id );
        if ( $order && 'yes' === $order->get_meta( '_kitgenix_multistore_sync_for_woocommerce_imported_order', true ) ) return;
        $this->pending[ $order_id ] = true;
    }

    public function status_changed( int $order_id, string $from, string $to, \WC_Order $order ): void { $this->queue( $order_id ); }

    public function flush(): void {
        foreach ( array_keys( $this->pending ) as $id ) $this->sync_now( (int) $id );
        $this->pending = array();
    }

    public function schedule_backfill( int $days = 90 ): int {
        $days=max(1,min(3650,$days));$page=1;$count=0;$after=gmdate('Y-m-d',time()-DAY_IN_SECONDS*$days);
        do{$result=wc_get_orders(array('limit'=>100,'page'=>$page,'paginate'=>true,'return'=>'ids','date_created'=>'>='.$after,'orderby'=>'date','order'=>'ASC'));$ids=is_object($result)?(array)$result->orders:array();foreach($ids as$id){if(function_exists('as_enqueue_async_action'))as_enqueue_async_action('kitgenix_multistore_sync_for_woocommerce_index_order_job',array((int)$id),'kitgenix-multistore-sync-for-woocommerce',false);else wp_schedule_single_event(time()+$count,'kitgenix_multistore_sync_for_woocommerce_index_order_job',array((int)$id));$count++;}$page++;$max=is_object($result)?(int)$result->max_num_pages:0;}while($ids&&$page<=$max);return$count;
    }

    public function sync_now( int $order_id ): array|\WP_Error {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_missing', 'Order was not found.' );
        $network = $this->mappings->network_id( 'order', $order_id, (string) $order->get_order_number() );
        $payload = $this->payload( $order, $network );
        if ( $this->settings->is_control() ) { $this->orders->upsert( $payload, Uuid::site() ); return array( 'local_id' => $order_id, 'network_id' => $network ); }
        return $this->dispatcher->dispatch( 'order_upsert', 'order', $network, $payload );
    }

    public function receive( array $payload, string $source ): array|\WP_Error {
        if ( ! $this->settings->is_control() ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_topology', 'Order snapshots are accepted by the control store only.' );
        if ( empty( $payload['network_id'] ) || empty( $payload['order_id'] ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_payload', 'Invalid order payload.' );
        $this->orders->upsert( $payload, $source );
        $imported = $this->importer->import( $payload, $source );
        if ( is_wp_error( $imported ) ) return $imported;
        do_action( 'kitgenix_multistore/order_received', $payload, $source, $imported );
        return array( 'local_id' => (int) $payload['order_id'], 'object_key' => (string) ( $payload['number'] ?? '' ), 'imported_order_id' => (int) $imported );
    }

    public function update_remote_status( string $network_id, string $status ): array|\WP_Error {
        $row = $this->orders->get( $network_id );
        if ( ! $row ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_remote_order_missing', 'Remote order was not found.' );
        $status = preg_replace( '/^wc-/', '', sanitize_key( $status ) );
        if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_status', 'Invalid WooCommerce order status.' );
        if ( $row['store_uuid'] === Uuid::site() ) { $r=$this->apply_status($network_id,(int)$row['remote_order_id'],$status); return is_wp_error($r)?$r:array('success'=>true,'order_id'=>$r); }
        return $this->client->request( $row['store_uuid'], 'POST', '/orders/status', array( 'network_id' => $network_id, 'order_id' => (int) $row['remote_order_id'], 'status' => $status ) );
    }

    public function update_remote_note( string $network_id, string $note, bool $customer_note = false ): array|\WP_Error {
        $row = $this->orders->get( $network_id );
        if ( ! $row ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_remote_order_missing', 'Remote order was not found.' );
        $note = trim( wp_strip_all_tags( $note ) );
        if ( '' === $note ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_note', 'Order note cannot be empty.' );
        if ( $row['store_uuid'] === Uuid::site() ) return $this->apply_note($network_id,(int)$row['remote_order_id'],$note,$customer_note);
        return $this->client->request( $row['store_uuid'], 'POST', '/orders/note', array( 'network_id' => $network_id, 'order_id' => (int) $row['remote_order_id'], 'note' => $note, 'customer_note' => $customer_note ) );
    }

    public function update_remote_refund( string $network_id, string $amount, string $reason, bool $refund_payment = false, bool $restock_items = false, array $line_items = array() ): array|\WP_Error {
        $row = $this->orders->get( $network_id );
        if ( ! $row ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_remote_order_missing', 'Remote order was not found.' );
        $amount = wc_format_decimal( $amount );
        if ( (float) $amount <= 0 ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_refund_amount', 'Refund amount must be greater than zero.' );
        if ( $refund_payment && 'yes' !== $this->settings->get( 'allow_remote_gateway_refunds', 'no' ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_gateway_refund_disabled', 'Remote payment-gateway refunds are disabled in MultiStore settings.' );
        $packet=array('network_id'=>$network_id,'order_id'=>(int)$row['remote_order_id'],'amount'=>$amount,'reason'=>sanitize_text_field($reason),'refund_payment'=>$refund_payment,'restock_items'=>$restock_items,'line_items'=>$this->sanitize_refund_lines($line_items));
        if ( $row['store_uuid'] === Uuid::site() ) return $this->apply_refund($packet);
        return $this->client->request( $row['store_uuid'], 'POST', '/orders/refund', $packet );
    }

    public function apply_status( string $network_id, int $order_id, string $status ): int|\WP_Error {
        $status = preg_replace( '/^wc-/', '', sanitize_key( $status ) );
        $order = $this->resolve_local_order( $network_id, $order_id );
        if ( is_wp_error( $order ) ) return $order;
        if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_status', 'Invalid order status.', array( 'status' => 400 ) );
        Context::remote( fn() => $order->update_status( $status, __( 'Status updated from Kitgenix MultiStore control store.', 'kitgenix-multistore-sync-for-woocommerce' ), false ) );
        $this->queue( $order->get_id() );
        return $order->get_id();
    }

    public function apply_note( string $network_id, int $order_id, string $note, bool $customer_note = false ): array|\WP_Error {
        $order = $this->resolve_local_order( $network_id, $order_id );
        if ( is_wp_error( $order ) ) return $order;
        $note = trim( wp_strip_all_tags( $note ) );
        if ( '' === $note ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_note', 'Order note cannot be empty.', array( 'status' => 400 ) );
        $note_id = Context::remote( fn() => $order->add_order_note( $note, $customer_note, true ) );
        $this->queue( $order->get_id() );
        return array( 'order_id' => $order->get_id(), 'note_id' => (int) $note_id );
    }

    public function apply_refund( array $payload ): array|\WP_Error {
        $network_id = sanitize_text_field( (string) ( $payload['network_id'] ?? '' ) );
        $order_id = (int) ( $payload['order_id'] ?? 0 );
        $order = $this->resolve_local_order( $network_id, $order_id );
        if ( is_wp_error( $order ) ) return $order;
        $amount = wc_format_decimal( $payload['amount'] ?? '' );
        if ( (float) $amount <= 0 ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_refund_amount', 'Refund amount must be greater than zero.', array( 'status' => 400 ) );
        $remaining = (float) $order->get_remaining_refund_amount();
        if ( (float) $amount > $remaining + 0.0001 ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_refund_exceeds_remaining', 'Refund amount exceeds the remaining refundable amount.', array( 'status' => 400 ) );
        $refund_payment = ! empty( $payload['refund_payment'] );
        if ( $refund_payment && 'yes' !== $this->settings->get( 'allow_remote_gateway_refunds', 'no' ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_gateway_refund_disabled', 'Remote payment-gateway refunds are disabled on the originating store.', array( 'status' => 403 ) );
        $args = array(
            'order_id' => $order->get_id(),
            'amount' => $amount,
            'reason' => sanitize_text_field( (string) ( $payload['reason'] ?? '' ) ),
            'line_items' => $this->sanitize_refund_lines( (array) ( $payload['line_items'] ?? array() ) ),
            'refund_payment' => $refund_payment,
            'restock_items' => ! empty( $payload['restock_items'] ),
        );
        $refund = Context::remote( fn() => wc_create_refund( $args ) );
        if ( is_wp_error( $refund ) ) return $refund;
        if ( ! $refund instanceof \WC_Order_Refund ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_refund_failed', 'WooCommerce did not create the refund.', array( 'status' => 500 ) );
        $this->queue( $order->get_id() );
        return array( 'order_id' => $order->get_id(), 'refund_id' => $refund->get_id(), 'amount' => $refund->get_amount() );
    }

    private function resolve_local_order( string $network_id, int $order_id ): \WC_Order|\WP_Error {
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order && $network_id ) {
            $local = $this->mappings->local_id( $network_id, 'order' );
            $order = $local ? wc_get_order( $local ) : false;
        }
        return $order ?: new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_mapping', 'Order mapping is missing.', array( 'status' => 404 ) );
    }

    private function sanitize_refund_lines( array $lines ): array {
        $out = array();
        foreach ( array_slice( $lines, 0, 250, true ) as $item_id => $line ) {
            $item_id = absint( $item_id );
            if ( ! $item_id || ! is_array( $line ) ) continue;
            $tax = array();
            foreach ( (array) ( $line['refund_tax'] ?? array() ) as $tax_id => $total ) $tax[ absint( $tax_id ) ] = wc_format_decimal( $total );
            $out[ $item_id ] = array( 'qty' => max( 0, (float) ( $line['qty'] ?? 0 ) ), 'refund_total' => wc_format_decimal( $line['refund_total'] ?? 0 ), 'refund_tax' => $tax );
        }
        return $out;
    }

    private function payload( \WC_Order $order, string $network ): array {
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = array(
                'item_id' => $item->get_id(), 'name' => $item->get_name(), 'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(), 'subtotal_tax' => $item->get_subtotal_tax(), 'total' => $item->get_total(), 'total_tax' => $item->get_total_tax(), 'taxes' => $item->get_taxes(),
                'sku' => $product ? $product->get_sku() : '',
                'product_network_id' => $product ? $this->mappings->network_id( $product->is_type( 'variation' ) ? 'variation' : 'product', $product->get_id(), (string) $product->get_sku() ) : '',
            );
        }
        $shipping_lines = array();
        foreach ( $order->get_items( 'shipping' ) as $item ) $shipping_lines[] = array( 'name' => $item->get_name(), 'method_id' => $item->get_method_id(), 'instance_id' => $item->get_instance_id(), 'total' => $item->get_total(), 'taxes' => $item->get_taxes() );
        $fee_lines = array();
        foreach ( $order->get_items( 'fee' ) as $item ) $fee_lines[] = array( 'name' => $item->get_name(), 'amount' => $item->get_amount(), 'total' => $item->get_total(), 'total_tax' => $item->get_total_tax(), 'tax_class' => $item->get_tax_class(), 'tax_status' => $item->get_tax_status(), 'taxes' => $item->get_taxes() );
        $billing = array(); $shipping = array();
        foreach ( array( 'first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone' ) as $field ) {
            $getter = 'get_billing_' . $field; if ( method_exists( $order, $getter ) ) $billing[ $field ] = $order->{$getter}();
            $getter = 'get_shipping_' . $field; if ( method_exists( $order, $getter ) ) $shipping[ $field ] = $order->{$getter}();
        }
        $refunds = array();
        foreach ( $order->get_refunds() as $refund ) $refunds[] = array( 'id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'reason' => $refund->get_reason(), 'date' => $refund->get_date_created() ? $refund->get_date_created()->date( 'Y-m-d H:i:s' ) : null );
        return apply_filters( 'kitgenix_multistore/order/payload', array(
            'network_id' => $network, 'order_id' => $order->get_id(), 'number' => $order->get_order_number(), 'status' => $order->get_status(),
            'currency' => $order->get_currency(), 'total' => $order->get_total(), 'refunded_total' => $order->get_total_refunded(),
            'shipping_total' => $order->get_shipping_total(), 'shipping_tax' => $order->get_shipping_tax(), 'cart_tax' => $order->get_cart_tax(), 'discount_total' => $order->get_discount_total(), 'discount_tax' => $order->get_discount_tax(), 'tax_total' => $order->get_total_tax(),
            'payment_method' => $order->get_payment_method(), 'payment_method_title' => $order->get_payment_method_title(), 'customer_id' => $order->get_customer_id(),
            'billing' => $billing, 'shipping' => $shipping, 'items' => $items, 'shipping_lines' => $shipping_lines, 'fee_lines' => $fee_lines, 'coupon_lines' => array_values( array_map( static fn( $coupon ) => array( 'code' => $coupon->get_code(), 'discount' => $coupon->get_discount(), 'discount_tax' => $coupon->get_discount_tax() ), $order->get_items( 'coupon' ) ) ), 'coupon_codes' => $order->get_coupon_codes(), 'customer_note' => $order->get_customer_note(), 'refunds' => $refunds,
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
            'date_modified' => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d H:i:s' ) : null, 'created_via' => $order->get_created_via(),
        ), $order );
    }
}
