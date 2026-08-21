<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Repository\InventoryRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Logger;

final class Reconciler {
    public function __construct(
        private InventoryRepository $inventory,
        private MappingRepository $mappings,
        private StoreRepository $stores,
        private Client $client,
        private Dispatcher $dispatcher,
        private Logger $logger
    ) {}

    public function hooks(): void {
        add_action( 'kitgenix_multistore_sync_for_woocommerce_reconcile', array( $this, 'run' ) );
        add_action( 'kitgenix_multistore_sync_for_woocommerce_fallback_reconcile', array( $this, 'run' ) );
        add_action( 'init', array( $this, 'ensure_schedule' ), 31 );
    }

    public function ensure_schedule(): void {
        if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
            if ( ! as_has_scheduled_action( 'kitgenix_multistore_sync_for_woocommerce_reconcile', array(), 'kitgenix-multistore' ) ) {
                as_schedule_recurring_action( time() + 900, HOUR_IN_SECONDS, 'kitgenix_multistore_sync_for_woocommerce_reconcile', array(), 'kitgenix-multistore', true );
            }
        } elseif ( ! wp_next_scheduled( 'kitgenix_multistore_sync_for_woocommerce_fallback_reconcile' ) ) {
            wp_schedule_event( time() + 900, 'hourly', 'kitgenix_multistore_sync_for_woocommerce_fallback_reconcile' );
        }
    }

    public function run(): array {
        $summary = array( 'checked' => 0, 'drift' => 0, 'repaired' => 0, 'errors' => array() );

        foreach ( $this->stores->active() as $store ) {
            if ( 'connected' !== $store['role'] ) {
                continue;
            }
            $rows = array();
            foreach ( array( 'product', 'variation' ) as $entity_type ) {
                $offset = 0;
                do {
                    $page = $this->mappings->for_store( $store['uuid'], $entity_type, 250, $offset );
                    $rows = array_merge( $rows, $page );
                    $offset += count( $page );
                } while ( 250 === count( $page ) );
            }
            foreach ( array_chunk( $rows, 100 ) as $chunk ) {
                $ids = array_values( array_unique( array_column( $chunk, 'network_id' ) ) );
                if ( ! $ids ) {
                    continue;
                }
                $response = $this->client->request( $store['uuid'], 'POST', '/inventory/snapshot', array( 'network_ids' => $ids ) );
                if ( is_wp_error( $response ) ) {
                    $summary['errors'][] = $store['name'] . ': ' . $response->get_error_message();
                    continue;
                }
                $remote = (array) ( $response['inventory'] ?? array() );
                foreach ( $chunk as $mapping ) {
                    $auth = $this->inventory->get( $mapping['network_id'] );
                    if ( ! $auth || null === $auth['quantity'] || ! isset( $remote[ $mapping['network_id'] ] ) ) {
                        continue;
                    }
                    ++$summary['checked'];
                    $remote_row = $remote[ $mapping['network_id'] ];
                    $drift = null === $remote_row['quantity']
                        || abs( (float) $remote_row['quantity'] - (float) $auth['quantity'] ) > 0.0001
                        || (string) $remote_row['stock_status'] !== (string) $auth['stock_status'];
                    if ( ! $drift ) {
                        continue;
                    }
                    ++$summary['drift'];
                    $event = $this->dispatcher->dispatch(
                        'inventory_set',
                        $mapping['entity_type'],
                        $mapping['network_id'],
                        array(
                            'network_id'   => $mapping['network_id'],
                            'is_variation' => 'variation' === $mapping['entity_type'],
                            'quantity'     => (float) $auth['quantity'],
                            'stock_status' => $auth['stock_status'],
                            'revision'     => (int) $auth['revision'],
                        ),
                        array( $store['uuid'] ),
                        null,
                        null,
                        (int) $auth['revision']
                    );
                    if ( ! is_wp_error( $event ) ) {
                        ++$summary['repaired'];
                    }
                }
            }
        }

        $this->logger->info( 'Inventory reconciliation completed.', $summary );
        return $summary;
    }
}
