<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\InventoryRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Support\Logger;

/**
 * Resumable, progress-tracked bulk operations: a full product re-sync or a
 * full inventory reconciliation, either of which can involve more products
 * or stores than fit comfortably in a single request on a large catalogue.
 *
 * State lives in one small option (never autoloaded) rather than a new
 * table – a single admin-triggered job at a time is the realistic case here,
 * and this mirrors how the rest of the plugin already favours plain options
 * and Action Scheduler over introducing new infrastructure.
 *
 * This plugin has no AJAX transport dependency anywhere: progress is shown
 * by re-rendering the persisted job state on each page load, with the Sync
 * Centre page auto-refreshing itself while a job is running.
 */
final class BulkJob {
    private const OPTION = 'kitgenix_multistore_sync_for_woocommerce_bulk_job';
    private const GROUP  = 'kitgenix-multistore-sync-for-woocommerce';
    private const HOOK   = 'kitgenix_multistore_sync_for_woocommerce_bulk_job_tick';
    private const FALLBACK_HOOK = 'kitgenix_multistore_sync_for_woocommerce_bulk_job_tick_fallback';
    private const CHUNK_PRODUCTS = 50;
    private const CHUNK_MAPPINGS = 100;
    private const MAX_ERRORS = 50;
    private const STALL_SECONDS = 90;
    private const RECENT_LIMIT = 50;

    /** @var int Set immediately before use by tick_product_sync(); consumed by filter_where_id_greater_than(). */
    private static int $where_cursor = 0;

    public function __construct(
        private ProductSync $product_sync,
        private InventoryRepository $inventory,
        private MappingRepository $mappings,
        private StoreRepository $stores,
        private Client $client,
        private Dispatcher $dispatcher,
        private Logger $logger
    ) {}

    public static function filter_where_id_greater_than( string $where ): string {
        global $wpdb;
        return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", self::$where_cursor );
    }

    public function hooks(): void {
        add_action( self::HOOK, array( $this, 'tick' ) );
        add_action( self::FALLBACK_HOOK, array( $this, 'tick' ) );
    }

    /** @return array<string,mixed> */
    public static function default_state(): array {
        return array(
            'type'       => '',
            'status'     => 'idle', // idle|running|paused|completed
            'dry_run'    => false,
            'total'      => 0,
            'processed'  => 0,
            'repaired'   => 0,
            'drift'      => 0,
            'excluded'   => 0,
            'errors'     => array(),
            'cursor'     => array(),
            'current'    => null, // array{id:int,label:string}|null - item in progress right now, for live UI.
            'recent'     => array(), // array<int,array{id:int,label:string,image:string,price:string,outcome:string}> - most-recent-first, capped; survives across batches so the live feed never goes blank between ticks.
            'started_at' => 0,
            'updated_at' => 0,
        );
    }

    /** @return array<string,mixed> */
    public function state(): array {
        $state = get_option( self::OPTION, null );
        return array_merge( self::default_state(), is_array( $state ) ? $state : array() );
    }

    private function save( array $state ): void {
        $state['updated_at'] = time();
        update_option( self::OPTION, $state, false );
    }

    /**
     * @param string   $type          'product_sync' or 'reconcile'.
     * @param bool     $dry_run       Count/report what would happen without changing anything.
     * @param int[]    $product_ids   Optional explicit set of products (product_sync only); empty = all.
     * @return array<string,mixed>
     */
    public function start( string $type, bool $dry_run = false, array $product_ids = array() ): array {
        if ( ! in_array( $type, array( 'product_sync', 'reconcile' ), true ) ) {
            $type = 'product_sync';
        }

        $state             = self::default_state();
        $state['type']      = $type;
        $state['status']    = 'running';
        $state['dry_run']   = $dry_run;
        $state['started_at']= time();

        if ( 'product_sync' === $type ) {
            $product_ids   = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
            $state['cursor'] = array( 'last_id' => 0, 'product_ids' => $product_ids );
            $state['total']  = $product_ids ? count( $product_ids ) : $this->count_all_products();
        } else {
            $state['cursor'] = array( 'store_index' => 0, 'entity_type' => 'product', 'offset' => 0 );
            $state['total']  = $this->count_reconcile_targets();
        }

        $this->save( $state );
        $this->schedule_tick();
        return $state;
    }

    public function pause(): array {
        $state = $this->state();
        if ( 'running' === $state['status'] ) {
            $state['status'] = 'paused';
            $this->save( $state );
        }
        return $state;
    }

    public function resume(): array {
        $state = $this->state();
        if ( 'paused' === $state['status'] ) {
            $state['status'] = 'running';
            $this->save( $state );
            $this->schedule_tick();
        }
        return $state;
    }

    public function stop(): array {
        $state = self::default_state();
        $this->save( $state );
        return $state;
    }

    private function schedule_tick(): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::HOOK, array(), self::GROUP, true ); // $unique=true already de-dupes repeated calls.
        } elseif ( ! wp_next_scheduled( self::FALLBACK_HOOK ) ) {
            wp_schedule_single_event( time() + 1, self::FALLBACK_HOOK );
        }
    }

    /**
     * Defends against a job stuck showing 'running' forever because its next
     * tick was scheduled but never actually fired - a backlogged Action
     * Scheduler queue, WP-Cron disabled with no real system cron hitting
     * wp-cron.php, or outbound loopback requests blocked on the host all
     * produce this with no error visible anywhere in the plugin. Call on
     * every admin page render (the same "no AJAX, re-render on reload"
     * pattern the rest of this job already uses) so a stalled job can
     * self-heal just by someone viewing the Sync page.
     */
    public function nudge_if_stalled(): void {
        $state = $this->state();
        if ( 'running' !== $state['status'] ) {
            return;
        }
        if ( time() - (int) $state['updated_at'] < self::STALL_SECONDS ) {
            return;
        }
        $this->schedule_tick();
    }

    /**
     * Called by the live-progress AJAX poll on every poll while a job is
     * running (every ~1.5s) rather than waiting for nudge_if_stalled()'s
     * 90-second threshold, so batches keep advancing back-to-back as long as
     * someone has the Sync page open. Safe to call this often: Action
     * Scheduler's $unique=true (or the wp_next_scheduled() guard on the
     * WP-Cron fallback) means a tick already pending/in-progress is never
     * duplicated.
     */
    public function poke(): void {
        if ( 'running' === $this->state()['status'] ) {
            $this->schedule_tick();
        }
    }

    /**
     * Process one bounded chunk of work. Safe to call repeatedly (Action
     * Scheduler / WP-Cron) – it always re-reads persisted state first, so a
     * pause/stop between ticks is always honoured.
     *
     * @param bool $reschedule Whether to enqueue the next tick automatically.
     *                         WP-CLI drives its own tight loop and passes
     *                         false here, so a fast CLI run doesn't also pile
     *                         up redundant (harmlessly no-op, but wasteful)
     *                         Action Scheduler/WP-Cron entries behind it.
     */
    public function tick( bool $reschedule = true ): void {
        $state = $this->state();
        if ( 'running' !== $state['status'] ) {
            return;
        }

        $state = 'reconcile' === $state['type'] ? $this->tick_reconcile( $state ) : $this->tick_product_sync( $state );

        if ( 'running' === $state['status'] ) {
            $this->save( $state );
            if ( $reschedule ) {
                $this->schedule_tick();
            }
            return;
        }

        $this->save( $state );
        $this->logger->info( 'Bulk job finished.', array(
            'type' => $state['type'], 'dry_run' => $state['dry_run'], 'processed' => $state['processed'],
            'total' => $state['total'], 'repaired' => $state['repaired'], 'drift' => $state['drift'], 'excluded' => $state['excluded'],
        ) );
    }

    // -------------------------------------------------------------------------
    // Product sync
    // -------------------------------------------------------------------------

    private function count_all_products(): int {
        $counts = wp_count_posts( 'product' );
        $total  = 0;
        foreach ( array( 'publish', 'draft', 'private', 'pending' ) as $status ) {
            $total += (int) ( $counts->$status ?? 0 );
        }
        return $total;
    }

    /** @param array<string,mixed> $state */
    private function tick_product_sync( array $state ): array {
        $cursor       = (array) $state['cursor'];
        $explicit_ids = (array) ( $cursor['product_ids'] ?? array() );
        $last_id      = (int) ( $cursor['last_id'] ?? 0 );

        if ( $explicit_ids ) {
            $batch = array_values( array_filter( $explicit_ids, static fn( $id ) => $id > $last_id ) );
            sort( $batch );
            $batch = array_slice( $batch, 0, self::CHUNK_PRODUCTS );
        } else {
            self::$where_cursor = $last_id;
            add_filter( 'posts_where', array( self::class, 'filter_where_id_greater_than' ) );
            $query = new \WP_Query( array(
                'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
                'posts_per_page' => self::CHUNK_PRODUCTS, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids',
                'no_found_rows' => true,
            ) );
            remove_filter( 'posts_where', array( self::class, 'filter_where_id_greater_than' ) );
            $batch = array_map( 'absint', (array) $query->posts );
        }

        if ( empty( $batch ) ) {
            $state['status'] = 'completed';
            return $state;
        }

        foreach ( $batch as $product_id ) {
            $last_id = max( $last_id, (int) $product_id );

            $title = get_the_title( $product_id );
            $title = '' !== $title ? $title : ( 'Product #' . $product_id );
            $image = get_the_post_thumbnail_url( $product_id, 'thumbnail' ) ?: '';
            $product_obj = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            $price = '';
            if ( $product_obj && '' !== $product_obj->get_price() ) {
                $price = html_entity_decode( wp_strip_all_tags( wc_price( (float) $product_obj->get_price() ) ), ENT_QUOTES );
            }

            // Saved before sync_now() (which makes outbound HTTP calls to
            // every target store, the slow part) runs, so the live-progress
            // poll can show which product is in flight right now, not just
            // which one finished last.
            $state['current'] = array( 'id' => (int) $product_id, 'label' => $title, 'image' => $image, 'price' => $price );
            $state['cursor']  = array( 'last_id' => $last_id, 'product_ids' => $explicit_ids );
            $this->save( $state );

            ++$state['processed'];
            $outcome = 'repaired';

            // Each product is isolated: an uncaught Throwable from one bad
            // product (a malformed variation, an unexpected null feeding a
            // date cast, etc.) must never kill the rest of this batch - before
            // this was added, that would silently freeze the whole job at
            // whatever the last fully-completed batch had processed, with no
            // error visible anywhere in the plugin's own UI.
            try {
                if ( $state['dry_run'] ) {
                    if ( 'no' === get_post_meta( $product_id, '_kitgenix_multistore_sync_for_woocommerce_sync_enabled', true ) ) {
                        ++$state['excluded'];
                        $outcome = 'excluded';
                    }
                } else {
                    $result = $this->product_sync->sync_now( (int) $product_id );
                    if ( is_wp_error( $result ) ) {
                        $this->push_error( $state, 'Product #' . $product_id . ': ' . $result->get_error_message() );
                        $outcome = 'error';
                    } elseif ( is_array( $result ) && ! empty( $result['skipped'] ) ) {
                        ++$state['excluded'];
                        $outcome = 'excluded';
                    } else {
                        ++$state['repaired'];
                    }
                }
            } catch ( \Throwable $e ) {
                $this->push_error( $state, 'Product #' . $product_id . ': ' . $e->getMessage() );
                $this->logger->error( 'Bulk product sync: unexpected error on one product, skipped and continued.', array(
                    'product_id' => $product_id, 'error' => $e->getMessage(),
                ) );
                $outcome = 'error';
            }

            // Rolled into a small most-recent-first feed, capped at
            // RECENT_LIMIT and never cleared between batches - unlike
            // 'current' (which blanks out the moment a batch finishes), this
            // is what the live product card actually renders, so it always
            // has something to show even when a 50-product batch processes
            // faster than the browser polls for it.
            array_unshift( $state['recent'], array(
                'id' => (int) $product_id, 'label' => $title, 'image' => $image, 'price' => $price, 'outcome' => $outcome,
            ) );
            if ( count( $state['recent'] ) > self::RECENT_LIMIT ) {
                $state['recent'] = array_slice( $state['recent'], 0, self::RECENT_LIMIT );
            }

            // Persisted after every product, not just once per 50-product batch,
            // so the admin page's Processed counter moves in real time and a
            // problem on a later product in this batch can never erase progress
            // already made on earlier ones in the same batch.
            $state['cursor'] = array( 'last_id' => $last_id, 'product_ids' => $explicit_ids );
            $this->save( $state );
        }

        $state['cursor'] = array( 'last_id' => $last_id, 'product_ids' => $explicit_ids );
        if ( $state['total'] > 0 && $state['processed'] >= $state['total'] ) {
            $state['status'] = 'completed';
        }
        $state['current'] = null;
        return $state;
    }

    // -------------------------------------------------------------------------
    // Reconcile
    // -------------------------------------------------------------------------

    private function count_reconcile_targets(): int {
        $total = 0;
        foreach ( $this->stores->active() as $store ) {
            if ( 'connected' !== $store['role'] ) {
                continue;
            }
            foreach ( array( 'product', 'variation' ) as $entity_type ) {
                $total += count( $this->mappings->for_store( $store['uuid'], $entity_type, 10000, 0 ) );
            }
        }
        return $total;
    }

    /** @param array<string,mixed> $state */
    private function tick_reconcile( array $state ): array {
        $stores = array_values( array_filter( $this->stores->active(), static fn( $s ) => 'connected' === $s['role'] ) );
        $cursor = (array) $state['cursor'];
        $store_index  = (int) ( $cursor['store_index'] ?? 0 );
        $entity_type  = (string) ( $cursor['entity_type'] ?? 'product' );
        $offset       = (int) ( $cursor['offset'] ?? 0 );

        if ( $store_index >= count( $stores ) ) {
            $state['status']  = 'completed';
            $state['current'] = null;
            return $state;
        }

        $store = $stores[ $store_index ];
        $chunk = $this->mappings->for_store( $store['uuid'], $entity_type, self::CHUNK_MAPPINGS, $offset );

        if ( empty( $chunk ) ) {
            // Advance to the next entity type, or the next store.
            if ( 'product' === $entity_type ) {
                $state['cursor'] = array( 'store_index' => $store_index, 'entity_type' => 'variation', 'offset' => 0 );
            } else {
                $state['cursor'] = array( 'store_index' => $store_index + 1, 'entity_type' => 'product', 'offset' => 0 );
            }
            return $state;
        }

        $ids = array_values( array_unique( array_column( $chunk, 'network_id' ) ) );
        $response = $ids ? $this->client->request( $store['uuid'], 'POST', '/inventory/snapshot', array( 'network_ids' => $ids ) ) : array( 'inventory' => array() );

        if ( is_wp_error( $response ) ) {
            $this->push_error( $state, $store['name'] . ': ' . $response->get_error_message() );
            $state['cursor'] = array( 'store_index' => $store_index, 'entity_type' => $entity_type, 'offset' => $offset + count( $chunk ) );
            return $state;
        }

        $remote = (array) ( $response['inventory'] ?? array() );
        foreach ( $chunk as $mapping ) {
            $state['current'] = array( 'id' => 0, 'label' => $store['name'] . ' – ' . $mapping['entity_type'] . ' ' . substr( (string) $mapping['network_id'], 0, 8 ) );
            ++$state['processed'];
            try {
                $auth = $this->inventory->get( $mapping['network_id'] );
                if ( ! $auth || null === $auth['quantity'] || ! isset( $remote[ $mapping['network_id'] ] ) ) {
                    continue;
                }
                $remote_row = $remote[ $mapping['network_id'] ];
                $drift = null === $remote_row['quantity']
                    || abs( (float) $remote_row['quantity'] - (float) $auth['quantity'] ) > 0.0001
                    || (string) $remote_row['stock_status'] !== (string) $auth['stock_status'];
                if ( ! $drift ) {
                    continue;
                }
                ++$state['drift'];

                if ( ! $this->store_target_allowed( $mapping, $store['uuid'] ) ) {
                    ++$state['excluded'];
                    continue;
                }

                if ( $state['dry_run'] ) {
                    continue; // Counted in drift/excluded above; no dispatch in a dry run.
                }

                $event = $this->dispatcher->dispatch(
                    'inventory_set', $mapping['entity_type'], $mapping['network_id'],
                    array(
                        'network_id' => $mapping['network_id'], 'is_variation' => 'variation' === $mapping['entity_type'],
                        'quantity' => (float) $auth['quantity'], 'stock_status' => $auth['stock_status'], 'revision' => (int) $auth['revision'],
                    ),
                    array( $store['uuid'] ), null, null, (int) $auth['revision']
                );
                if ( ! is_wp_error( $event ) ) {
                    ++$state['repaired'];
                }
            } catch ( \Throwable $e ) {
                $this->push_error( $state, $mapping['entity_type'] . ' ' . $mapping['network_id'] . ': ' . $e->getMessage() );
                $this->logger->error( 'Bulk reconcile: unexpected error on one mapping, skipped and continued.', array(
                    'network_id' => $mapping['network_id'], 'error' => $e->getMessage(),
                ) );
            }

            // Persisted after every mapping so Processed moves in real time
            // and a later failure in this chunk can't erase earlier progress.
            $state['cursor'] = array( 'store_index' => $store_index, 'entity_type' => $entity_type, 'offset' => $offset + count( $chunk ) );
            $this->save( $state );
        }

        $state['cursor'] = array( 'store_index' => $store_index, 'entity_type' => $entity_type, 'offset' => $offset + count( $chunk ) );
        return $state;
    }

    private function store_target_allowed( array $mapping, string $store_uuid ): bool {
        $local_id = $this->mappings->local_id( (string) $mapping['network_id'], (string) $mapping['entity_type'] );
        if ( ! $local_id ) {
            return true;
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

    /** @param array<string,mixed> $state */
    private function push_error( array &$state, string $message ): void {
        $state['errors'][] = array( 'time' => time(), 'message' => $message );
        if ( count( $state['errors'] ) > self::MAX_ERRORS ) {
            $state['errors'] = array_slice( $state['errors'], -self::MAX_ERRORS );
        }
    }

    /** @return array<string,mixed> */
    public function progress(): array {
        $state = $this->state();
        $total = max( 0, (int) $state['total'] );
        $done  = (int) $state['processed'];
        return array_merge( $state, array(
            'percent' => $total > 0 ? min( 100, (int) round( ( $done / $total ) * 100 ) ) : ( 'completed' === $state['status'] ? 100 : 0 ),
        ) );
    }
}
