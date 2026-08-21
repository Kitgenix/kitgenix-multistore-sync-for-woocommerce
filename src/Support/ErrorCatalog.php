<?php
namespace Kitgenix\MultiStore\Support;

final class ErrorCatalog {
    private const RECOVERABLE_CATEGORIES = array(
        'auth-replay-or-clock-skew',
        'pairing-rate-limited',
        'network-error',
        'remote-conflict',
    );

    /**
     * Recoverable/transient failures (network blips, replay, clock skew, remote
     * congestion) should not count toward "genuine" failure signals such as
     * NetworkDoctor's queue health check or the failure-spike style alerts —
     * mirrors Turnstile's is_retry_codes() pattern.
     */
    public static function is_recoverable( string $code ): bool {
        if ( '' === $code ) {
            return false;
        }

        return in_array( self::get_category_and_note( $code )['category'], self::RECOVERABLE_CATEGORIES, true );
    }

    /**
     * Map a WP_Error code (or a Kitgenix log context code) to a short category
     * slug and a plain-English note, distinguishing genuine problems that need
     * action from likely false positives / routine friction that usually don't.
     *
     * @return array{category: string, note: string}
     */
    public static function get_category_and_note( string $code ): array {
        if ( '' === $code ) {
            return array(
                'category' => 'info',
                'note'     => __( 'Informational log entry — not an error.', 'kitgenix-multistore' ),
            );
        }

        if ( str_starts_with( $code, 'http_' ) ) {
            return array(
                'category' => 'network-error',
                'note'     => __( 'WordPress could not reach the other store over HTTPS (connection refused, DNS failure, or timeout at the transport layer). Usually a transient network issue — the delivery will retry automatically.', 'kitgenix-multistore' ),
            );
        }

        if ( preg_match( '/^kitgenix_multistore_sync_for_woocommerce_remote_http_(\d+)$/', $code, $m ) ) {
            $status = (int) $m[1];
            if ( $status >= 500 ) {
                return array(
                    'category' => 'network-error',
                    /* translators: %d: HTTP status code returned by the remote store */
                    'note'     => sprintf( __( 'The other store\'s server returned a temporary error (HTTP %d). Usually resolves on its own — only investigate if it persists across many retries.', 'kitgenix-multistore' ), $status ),
                );
            }
            if ( 429 === $status ) {
                return array(
                    'category' => 'network-error',
                    'note'     => __( 'The other store rate-limited this request (HTTP 429, too many requests). The delivery will retry automatically after a short backoff.', 'kitgenix-multistore' ),
                );
            }
            if ( 409 === $status ) {
                return array(
                    'category' => 'remote-conflict',
                    'note'     => __( 'The other store was already processing this event (HTTP 409 conflict). Usually resolves itself on the next retry.', 'kitgenix-multistore' ),
                );
            }
            return array(
                'category' => 'remote-rejected',
                /* translators: %d: HTTP status code returned by the remote store */
                'note'     => sprintf( __( 'The other store rejected this request as invalid, unauthorised, or not found (HTTP %d). Check that the pairing secret and store role are still correct on both ends.', 'kitgenix-multistore' ), $status ),
            );
        }

        $map = self::map();

        return $map[ $code ] ?? array(
            'category' => 'unmapped',
            'note'     => __( 'Failed — reason not mapped to a known category.', 'kitgenix-multistore' ),
        );
    }

    /**
     * Category summaries for the settings-page reference table: one row per
     * category rather than per code, matching the Turnstile plugin's table.
     *
     * @return array<int,array{category:string,meaning:string,action:string}>
     */
    public static function category_reference(): array {
        return array(
            array(
                'category' => 'auth-rejected',
                'meaning'  => __( 'An incoming request from another store failed Kitgenix signature/header authentication.', 'kitgenix-multistore' ),
                'action'   => __( 'If this store should be paired, reconnect it from Stores. Repeated attempts from an unknown store may be a scan — no action required unless it persists.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'auth-replay-or-clock-skew',
                'meaning'  => __( 'A request nonce was already used, or the request timestamp was outside the 5-minute signing window.', 'kitgenix-multistore' ),
                'action'   => __( 'Usually not a problem — a duplicate delivery retry or a few seconds of clock drift between servers. Only investigate if it happens on every request from one store (check NTP/server time sync).', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'config-error',
                'meaning'  => __( 'A store connection, pairing secret, or URL is missing, invalid, or the local encryption key could not be used.', 'kitgenix-multistore' ),
                'action'   => __( 'Go to Stores and reconnect the affected store, or re-run pairing.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'pairing-rate-limited',
                'meaning'  => __( 'Too many pairing attempts were made from the same IP address in a short time.', 'kitgenix-multistore' ),
                'action'   => __( 'Not a problem by itself — a protective limit. Wait a few minutes and try pairing again.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'network-error',
                'meaning'  => __( 'The remote store could not be reached, or its server returned a temporary error or rate limit.', 'kitgenix-multistore' ),
                'action'   => __( 'Usually transient. The delivery retries automatically with backoff — only investigate if a specific store fails consistently.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'remote-conflict',
                'meaning'  => __( 'The same event was already being processed, or arrived at the remote store at the same time as another attempt.', 'kitgenix-multistore' ),
                'action'   => __( 'Usually not a problem — resolves itself on the next automatic retry.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'remote-rejected',
                'meaning'  => __( 'The remote store rejected the request outright (bad credentials, missing route, or malformed request).', 'kitgenix-multistore' ),
                'action'   => __( 'Check that both stores are running compatible plugin versions and the pairing secret matches.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'topology-mismatch',
                'meaning'  => __( 'The event or request is not allowed under the current network role/direction settings (e.g. a connected store sending something only the control store accepts).', 'kitgenix-multistore' ),
                'action'   => __( 'Review Settings → sync direction and store roles. If this is expected (e.g. staging protection), no action is needed.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'malformed-payload',
                'meaning'  => __( 'A sync event or request was missing required fields or contained data in an unexpected shape.', 'kitgenix-multistore' ),
                'action'   => __( 'Usually indicates a version mismatch between stores. Update Kitgenix MultiStore on both ends.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'mapping-missing',
                'meaning'  => __( 'The network object (product, order, coupon, review) has no corresponding local object on this store — it may have been deleted locally.', 'kitgenix-multistore' ),
                'action'   => __( 'Re-sync the item from its source store, or recreate the mapping via Products/Sync Centre.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'validation-error',
                'meaning'  => __( 'The request contained a value WooCommerce or Kitgenix rejected — an invalid status, an empty note, a refund amount out of range, or a disabled feature.', 'kitgenix-multistore' ),
                'action'   => __( 'Check the originating action on the source store. If refunds are involved, confirm remote gateway refunds are enabled in Settings if that is intended.', 'kitgenix-multistore' ),
            ),
            array(
                'category' => 'application-error',
                'meaning'  => __( 'WooCommerce or PHP raised an unexpected exception while applying a change (creating an order, saving a product, processing a refund).', 'kitgenix-multistore' ),
                'action'   => __( 'Check the Log for the underlying message. If it repeats, it usually points to a plugin conflict or a data issue worth reporting to support.', 'kitgenix-multistore' ),
            ),
        );
    }

    /**
     * @return array<string,array{category:string,note:string}>
     */
    private static function map(): array {
        return array(
            // Auth — genuine rejections
            'kitgenix_multistore_sync_for_woocommerce_auth_headers'   => array(
                'category' => 'auth-rejected',
                'note'     => __( 'The request was missing one or more required Kitgenix signing headers, so it was rejected before authentication could be attempted.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_auth_store'     => array(
                'category' => 'auth-rejected',
                'note'     => __( 'The request identified a store UUID this site has not paired with.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_auth_signature' => array(
                'category' => 'auth-rejected',
                'note'     => __( 'The request signature did not match — the shared secret may be out of date, or the request was tampered with in transit.', 'kitgenix-multistore' ),
            ),

            // Auth — recoverable friction
            'kitgenix_multistore_sync_for_woocommerce_auth_replay'  => array(
                'category' => 'auth-replay-or-clock-skew',
                'note'     => __( 'This exact request nonce was already used. Usually a duplicate delivery retry (e.g. after a network blip) rather than a genuine replay attack.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_auth_expired' => array(
                'category' => 'auth-replay-or-clock-skew',
                'note'     => __( 'The signed request arrived outside the 5-minute signing window. Usually clock drift between the two servers or a slow network hop, not an attack.', 'kitgenix-multistore' ),
            ),

            // Config / connection errors
            'kitgenix_multistore_sync_for_woocommerce_store_missing'     => array(
                'category' => 'config-error',
                'note'     => __( 'The store this delivery targets is no longer in the local connections list.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_secret_missing'    => array(
                'category' => 'config-error',
                'note'     => __( 'The stored connection secret for this store could not be read or decrypted.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_crypto_error'      => array(
                'category' => 'config-error',
                'note'     => __( 'The connection secret could not be encrypted or decrypted on this server (missing OpenSSL, or the encryption key changed).', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_invalid_store_uuid' => array(
                'category' => 'config-error',
                'note'     => __( 'The remote store UUID supplied during pairing was not a valid UUID.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_invalid_store_url'  => array(
                'category' => 'config-error',
                'note'     => __( 'The remote store URL supplied during pairing was not a usable URL.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_invalid_url'        => array(
                'category' => 'config-error',
                'note'     => __( 'The store URL entered for pairing is not a valid, reachable address.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_https_required'    => array(
                'category' => 'config-error',
                'note'     => __( 'HTTPS is required to pair or connect production stores; the URL supplied used plain HTTP.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_pair_failed'       => array(
                'category' => 'config-error',
                'note'     => __( 'The remote store rejected the pairing attempt.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_pair_code'         => array(
                'category' => 'config-error',
                'note'     => __( 'The one-time pairing code entered was wrong or has expired (codes last 10 minutes).', 'kitgenix-multistore' ),
            ),

            // Pairing rate limiting — recoverable
            'kitgenix_multistore_sync_for_woocommerce_pair_rate' => array(
                'category' => 'pairing-rate-limited',
                'note'     => __( 'More than 15 pairing attempts were made from the same IP within 10 minutes.', 'kitgenix-multistore' ),
            ),

            // Remote conflicts — recoverable
            'kitgenix_multistore_sync_for_woocommerce_event_in_progress' => array(
                'category' => 'remote-conflict',
                'note'     => __( 'This event is already being processed by this store. Usually resolves itself once the in-flight attempt finishes.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_event_race'        => array(
                'category' => 'remote-conflict',
                'note'     => __( 'Two deliveries for the same event arrived at the same moment. The losing request is retried automatically.', 'kitgenix-multistore' ),
            ),

            // Topology / network-role mismatches
            'kitgenix_multistore_sync_for_woocommerce_event_topology'          => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'This event type is not authorised from the sending store under the current network role/direction settings.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_event_peer'              => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'The authenticated store is no longer a recognised peer in this network.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_topology'          => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'Order snapshots are only accepted by the control store, and this store is not currently the control store.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_customer_topology'       => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'Customer snapshots are only accepted by the control store.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_inventory_topology'      => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'Inventory deltas are only applied by the control store.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_history_topology'  => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'Network customer order history is only served by the control store.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_number_topology'   => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'Sequential order numbers are only allocated by the control store.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_route_topology'          => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'The authenticated store\'s role does not permit this REST operation.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_staging_protection'      => array(
                'category' => 'topology-mismatch',
                'note'     => __( 'Outbound network writes are disabled because this environment was detected as non-production (staging protection).', 'kitgenix-multistore' ),
            ),

            // Malformed payloads / version mismatches
            'kitgenix_multistore_sync_for_woocommerce_event_payload'      => array(
                'category' => 'malformed-payload',
                'note'     => __( 'An incoming sync event was missing one or more required fields.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_event_source'      => array(
                'category' => 'malformed-payload',
                'note'     => __( 'The authenticated store does not match the source store declared inside the event payload.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_event_type'        => array(
                'category' => 'malformed-payload',
                'note'     => __( 'The event declared a type this store version does not recognise — likely a version mismatch between stores.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_payload'     => array(
                'category' => 'malformed-payload',
                'note'     => __( 'An order snapshot was missing its network ID or order ID.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_coupon_payload'    => array(
                'category' => 'malformed-payload',
                'note'     => __( 'A coupon event was missing its network ID or code.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_review_payload'    => array(
                'category' => 'malformed-payload',
                'note'     => __( 'A review event was missing a valid review or product network ID.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_product_network_id' => array(
                'category' => 'malformed-payload',
                'note'     => __( 'A product payload was missing its network ID.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_import_order_network' => array(
                'category' => 'malformed-payload',
                'note'     => __( 'An order import payload had an invalid network ID.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_pair_payload'      => array(
                'category' => 'malformed-payload',
                'note'     => __( 'The pairing request was missing a usable secret or store details.', 'kitgenix-multistore' ),
            ),

            // Mapping missing — the local counterpart of a network object is gone
            'kitgenix_multistore_sync_for_woocommerce_inventory_mapping'     => array(
                'category' => 'mapping-missing',
                'note'     => __( 'No local product could be matched to this network inventory update (by mapping or SKU).', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_product_mapping'      => array(
                'category' => 'mapping-missing',
                'note'     => __( 'No local product mapping exists for this network product, so it could not be trashed.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_product_missing'      => array(
                'category' => 'mapping-missing',
                'note'     => __( 'The local product for this sync could not be found — it may have been deleted on this store.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_coupon_mapping'       => array(
                'category' => 'mapping-missing',
                'note'     => __( 'No local coupon mapping exists for this network coupon, so it could not be trashed.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_mapping'        => array(
                'category' => 'mapping-missing',
                'note'     => __( 'No local order mapping exists for this network order reference.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_missing'        => array(
                'category' => 'mapping-missing',
                'note'     => __( 'The local order for this action could not be found.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_remote_order_missing' => array(
                'category' => 'mapping-missing',
                'note'     => __( 'No remote order snapshot exists on the control store for this network order yet.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_review_product'       => array(
                'category' => 'mapping-missing',
                'note'     => __( 'The product a synced review belongs to has no local mapping on this store.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_number_control' => array(
                'category' => 'mapping-missing',
                'note'     => __( 'No control store is currently connected to allocate the next sequential order number.', 'kitgenix-multistore' ),
            ),

            // Validation errors — bad input data or a disabled feature
            'kitgenix_multistore_sync_for_woocommerce_order_status'             => array(
                'category' => 'validation-error',
                'note'     => __( 'The order status supplied is not a valid WooCommerce order status.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_note'               => array(
                'category' => 'validation-error',
                'note'     => __( 'An order note action was submitted with empty note text.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_refund_amount'            => array(
                'category' => 'validation-error',
                'note'     => __( 'A refund was requested with an amount of zero or less.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_refund_exceeds_remaining' => array(
                'category' => 'validation-error',
                'note'     => __( 'A refund was requested for more than the order\'s remaining refundable amount.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_product_type'             => array(
                'category' => 'validation-error',
                'note'     => __( 'The synced product declared a product type this store does not support.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_history_email'      => array(
                'category' => 'validation-error',
                'note'     => __( 'A customer order history lookup was requested without a valid email address.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_numbers_disabled'   => array(
                'category' => 'validation-error',
                'note'     => __( 'Network sequential order numbers were requested but are turned off in Settings.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_history_disabled'   => array(
                'category' => 'validation-error',
                'note'     => __( 'Network My Account order history was requested but is turned off in Settings.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_gateway_refund_disabled'  => array(
                'category' => 'validation-error',
                'note'     => __( 'A refund tried to charge back the original payment gateway, but remote gateway refunds are turned off in Settings.', 'kitgenix-multistore' ),
            ),

            // Application-level runtime errors
            'kitgenix_multistore_sync_for_woocommerce_event_exception'         => array(
                'category' => 'application-error',
                'note'     => __( 'Applying this event raised an unexpected PHP exception. See the Log for the underlying message.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_event_create'           => array(
                'category' => 'application-error',
                'note'     => __( 'The local sync event record could not be created (database write failure).', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_product_apply'          => array(
                'category' => 'application-error',
                'note'     => __( 'WooCommerce raised an exception while applying the synced product data. See the Log for the underlying message.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_refund_failed'          => array(
                'category' => 'application-error',
                'note'     => __( 'WooCommerce accepted the refund request but did not create a refund object.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_import_order_create'    => array(
                'category' => 'application-error',
                'note'     => __( 'WooCommerce could not create the local order while importing a network order snapshot.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_order_date_exception'   => array(
                'category' => 'application-error',
                'note'     => __( 'The imported order\'s creation date could not be parsed, so the default order date was kept. Non-blocking — the order itself still imported.', 'kitgenix-multistore' ),
            ),
            'kitgenix_multistore_sync_for_woocommerce_square_status_exception' => array(
                'category' => 'application-error',
                'note'     => __( 'Reading the WooCommerce Square integration\'s system-of-record setting raised an exception. Non-blocking — the Integrations tab shows Square as detected without that extra detail.', 'kitgenix-multistore' ),
            ),
        );
    }
}
