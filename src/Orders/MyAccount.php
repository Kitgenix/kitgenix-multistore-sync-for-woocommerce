<?php
namespace Kitgenix\MultiStore\Orders;

use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Repository\OrderRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class MyAccount {
    public function __construct(
        private OrderRepository $orders,
        private StoreRepository $stores,
        private Settings $settings,
        private Client $client
    ) {}

    public function hooks(): void {
        add_action( 'woocommerce_after_account_orders', array( $this, 'render' ), 20, 1 );
    }

    public function render( bool $has_orders ): void {
        if ( 'yes' !== $this->settings->get( 'network_my_account_orders', 'no' ) || ! is_user_logged_in() ) return;
        $user = wp_get_current_user();
        $email = sanitize_email( (string) $user->user_email );
        if ( ! $email ) return;

        $rows = $this->network_rows( $email );
        if ( ! $rows ) return;

        echo '<section class="woocommerce-order-details kitgenix-multistore-sync-for-woocommerce-network-orders"><h2>' . esc_html__( 'Orders from our other stores', 'kitgenix-multistore' ) . '</h2><table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive"><thead><tr><th>' . esc_html__( 'Order', 'kitgenix-multistore' ) . '</th><th>' . esc_html__( 'Store', 'kitgenix-multistore' ) . '</th><th>' . esc_html__( 'Date', 'kitgenix-multistore' ) . '</th><th>' . esc_html__( 'Status', 'kitgenix-multistore' ) . '</th><th>' . esc_html__( 'Total', 'kitgenix-multistore' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $date = (string) ( $row['order_created_at'] ?? $row['synced_at'] ?? '' );
            echo '<tr><td data-title="Order">#' . esc_html( (string) ( $row['order_number'] ?? '' ) ) . '</td><td data-title="Store">' . esc_html( (string) ( $row['store_name'] ?? 'Store' ) ) . '</td><td data-title="Date">' . esc_html( $date ? mysql2date( get_option( 'date_format' ), $date ) : '—' ) . '</td><td data-title="Status">' . esc_html( wc_get_order_status_name( (string) ( $row['status'] ?? '' ) ) ) . '</td><td data-title="Total">' . wp_kses_post( wc_price( (float) ( $row['total'] ?? 0 ), array( 'currency' => (string) ( $row['currency'] ?? get_woocommerce_currency() ) ) ) ) . '</td></tr>';
        }
        echo '</tbody></table><p class="woocommerce-info">' . esc_html__( 'These orders are read-only here and remain managed by the store where they were placed.', 'kitgenix-multistore' ) . '</p></section>';
    }

    private function network_rows( string $email ): array {
        if ( $this->settings->is_control() ) {
            $rows = $this->orders->by_email( $email, 100 );
            $out = array();
            foreach ( $rows as $row ) {
                if ( (string) $row['store_uuid'] === Uuid::site() ) continue;
                $store = $this->stores->get( (string) $row['store_uuid'] );
                $row['store_name'] = (string) ( $store['name'] ?? 'Store' );
                $out[] = $this->public_row( $row );
            }
            return $out;
        }

        $control = $this->stores->control();
        if ( ! $control ) return array();
        $response = $this->client->request( (string) $control['uuid'], 'POST', '/orders/customer-history', array( 'email' => $email ), true );
        if ( is_wp_error( $response ) ) return array();
        return array_values( array_filter( array_map( fn( $row ) => is_array( $row ) ? $this->public_row( $row ) : array(), (array) ( $response['orders'] ?? array() ) ) ) );
    }

    private function public_row( array $row ): array {
        return array(
            'network_id' => sanitize_text_field( (string) ( $row['network_id'] ?? '' ) ),
            'order_number' => sanitize_text_field( (string) ( $row['order_number'] ?? '' ) ),
            'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ),
            'currency' => sanitize_text_field( (string) ( $row['currency'] ?? '' ) ),
            'total' => (float) ( $row['total'] ?? 0 ),
            'order_created_at' => sanitize_text_field( (string) ( $row['order_created_at'] ?? '' ) ),
            'synced_at' => sanitize_text_field( (string) ( $row['synced_at'] ?? '' ) ),
            'store_name' => sanitize_text_field( (string) ( $row['store_name'] ?? 'Store' ) ),
        );
    }
}
