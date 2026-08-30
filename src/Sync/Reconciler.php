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
        private Logger $logger,
        private ProductSync $product_sync
    ) {}

    public function hooks(): void {
        add_action( 'kitgenix_multistore_sync_for_woocommerce_reconcile', array( $this, 'run' ) );
        add_action( 'kitgenix_multistore_sync_for_woocommerce_fallback_reconcile', array( $this, 'run' ) );
        add_action( 'init', array( $this, 'ensure_schedule' ), 31 );
    }

    public function ensure_schedule(): void {
        if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
            if ( ! as_has_scheduled_action( 'kitgenix_multistore_sync_for_woocommerce_reconcile', array(), 'kitgenix-multistore-sync-for-woocommerce' ) ) {
                as_schedule_recurring_action( time() + 900, HOUR_IN_SECONDS, 'kitgenix_multistore_sync_for_woocommerce_reconcile', array(), 'kitgenix-multistore-sync-for-woocommerce', true );
            }
        } elseif ( ! wp_next_scheduled( 'kitgenix_multistore_sync_for_woocommerce_fallback_reconcile' ) ) {
            wp_schedule_event( time() + 900, 'hourly', 'kitgenix_multistore_sync_for_woocommerce_fallback_reconcile' );
        }
    }

    public function run(): array {
        $summary = array( 'checked' => 0, 'drift' => 0, 'repaired' => 0, 'excluded' => 0, 'errors' => array() );

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

                    // A store this product was deliberately excluded from (sync
                    // disabled, or excluded from its explicit target stores/groups)
                    // must stay excluded through reconciliation, not get "repaired"
                    // back onto the network.
                    if ( ! $this->store_is_allowed_for_mapping( $mapping, $store['uuid'] ) ) {
                        ++$summary['excluded'];
                        continue;
                    }

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

    /**
     * Whether the given store is still an allowed sync target for the local
     * product behind this mapping row. Variation mappings are checked against
     * their parent product, since exclusion settings live on the parent post.
     *
     * @param array<string,mixed> $mapping
     */
    private function store_is_allowed_for_mapping( array $mapping, string $store_uuid ): bool {
        $local_id = $this->mappings->local_id( (string) $mapping['network_id'], (string) $mapping['entity_type'] );
        if ( ! $local_id ) {
            return true; // Nothing local to check exclusions against; let the normal repair flow handle it.
        }

        $product_id = $local_id;
        if ( 'variation' === (string) $mapping['entity_type'] ) {
            $parent_id = wp_get_post_parent_id( $local_id );
            if ( $parent_id ) {
                $product_id = $parent_id;
            }
        }

        return $this->product_sync->is_target_allowed( $product_id, $store_uuid );
    }
}
