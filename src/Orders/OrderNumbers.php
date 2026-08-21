<?php
namespace Kitgenix\MultiStore\Orders;

use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;

final class OrderNumbers {
    public function __construct( private Settings $settings, private StoreRepository $stores, private Client $client, private Logger $logger ) {}

    public function hooks(): void {
        add_action( 'woocommerce_new_order', array( $this, 'assign' ), 2, 1 );
        add_filter( 'woocommerce_order_number', array( $this, 'display' ), 20, 2 );
        add_action( 'kitgenix_multistore_sync_for_woocommerce_assign_order_number', array( $this, 'assign' ), 10, 1 );
    }

    public function assign( int $order_id ): void {
        if ( Context::is_remote() || 'yes' !== $this->settings->get( 'sequential_order_numbers', 'no' ) ) return;
        $order = wc_get_order( $order_id ); if ( ! $order || $order->get_meta( '_kitgenix_multistore_sync_for_woocommerce_network_order_number', true ) ) return;
        $number = $this->settings->is_control() ? $this->next_number() : $this->remote_number();
        if ( is_wp_error( $number ) ) {
            $attempts = (int) $order->get_meta( '_kitgenix_multistore_sync_for_woocommerce_order_number_attempts', true ) + 1;
            $order->update_meta_data( '_kitgenix_multistore_sync_for_woocommerce_order_number_attempts', $attempts ); $order->save_meta_data();
            $this->logger->warning( 'Could not allocate network order number.', array( 'order_id' => $order_id, 'attempt' => $attempts, 'error' => $number->get_error_message() ) );
            if ( $attempts < 8 ) {
                $delay = min( 3600, 60 * ( 2 ** max( 0, $attempts - 1 ) ) );
                if ( function_exists( 'as_schedule_single_action' ) ) as_schedule_single_action( time() + $delay, 'kitgenix_multistore_sync_for_woocommerce_assign_order_number', array( $order_id ), 'kitgenix-multistore', false );
                else wp_schedule_single_event( time() + $delay, 'kitgenix_multistore_sync_for_woocommerce_assign_order_number', array( $order_id ) );
            }
            return;
        }
        $order->update_meta_data( '_kitgenix_multistore_sync_for_woocommerce_network_order_number', (string) $number ); $order->delete_meta_data( '_kitgenix_multistore_sync_for_woocommerce_order_number_attempts' ); $order->save_meta_data();
    }

    public function display( string|int $number, \WC_Order $order ): string|int {
        if ( 'yes' !== $this->settings->get( 'sequential_order_numbers', 'no' ) ) return $number;
        $network = (string) $order->get_meta( '_kitgenix_multistore_sync_for_woocommerce_network_order_number', true ); return $network ?: $number;
    }

    public function next_number(): string {
        global $wpdb; $table = $wpdb->prefix . 'kitgenix_multistore_sync_for_woocommerce_sequences'; $start = max( 1, (int) $this->settings->get( 'order_number_start', 1000 ) );
        $wpdb->query( 'START TRANSACTION' );
        try {
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (sequence_key,current_value,updated_at) VALUES ('orders',%d,%s) ON DUPLICATE KEY UPDATE current_value=LAST_INSERT_ID(current_value+1),updated_at=VALUES(updated_at)", $start, current_time( 'mysql', true ) ) );
            $value = (int) $wpdb->get_var( "SELECT current_value FROM {$table} WHERE sequence_key='orders' FOR UPDATE" );
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
        return sanitize_text_field( (string) $this->settings->get( 'order_number_prefix', '' ) ) . $value;
    }

    private function remote_number(): string|\WP_Error {
        $control = $this->stores->control(); if ( ! $control ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_order_number_control', 'Control store is not connected.' );
        $result = $this->client->request( (string) $control['uuid'], 'POST', '/orders/next-number', array() );
        if ( is_wp_error( $result ) ) return $result; return sanitize_text_field( (string) ( $result['number'] ?? '' ) );
    }
}
