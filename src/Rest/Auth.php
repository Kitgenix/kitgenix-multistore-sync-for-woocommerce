<?php
namespace Kitgenix\MultiStore\Rest;

use Kitgenix\MultiStore\Http\Signer;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\ErrorCatalog;
use Kitgenix\MultiStore\Support\Logger;

final class Auth {
    public function __construct( private StoreRepository $stores, private Logger $logger ) {}

    public function verify( \WP_REST_Request $request ): bool|\WP_Error {
        $store_uuid = sanitize_text_field( (string) $request->get_header( 'x-kitgenix-store' ) );
        $timestamp  = sanitize_text_field( (string) $request->get_header( 'x-kitgenix-timestamp' ) );
        $nonce      = sanitize_text_field( (string) $request->get_header( 'x-kitgenix-nonce' ) );
        $signature  = sanitize_text_field( (string) $request->get_header( 'x-kitgenix-signature' ) );
        if ( ! wp_is_uuid( $store_uuid ) || ! ctype_digit( $timestamp ) || strlen( $nonce ) < 16 || strlen( $signature ) !== 64 ) {
            return $this->reject( 'kitgenix_multistore_sync_for_woocommerce_auth_headers', 'Invalid Kitgenix authentication headers.', $store_uuid, $request );
        }
        if ( abs( time() - (int) $timestamp ) > 300 ) {
            return $this->reject( 'kitgenix_multistore_sync_for_woocommerce_auth_expired', 'Signed request has expired.', $store_uuid, $request );
        }
        $secret = $this->stores->secret( $store_uuid );
        if ( ! $secret ) { return $this->reject( 'kitgenix_multistore_sync_for_woocommerce_auth_store', 'Store is not paired.', $store_uuid, $request ); }
        $nonce_key = 'kitgenix_multistore_sync_for_woocommerce_nonce_' . md5( $store_uuid . '|' . $nonce );
        if ( get_transient( $nonce_key ) ) { return $this->reject( 'kitgenix_multistore_sync_for_woocommerce_auth_replay', 'Request nonce has already been used.', $store_uuid, $request ); }
        $body  = $request->get_body();
        if ( '' === $body ) { $body = '{}'; }
        $route = preg_replace( '#^/kitgenix-multistore-sync-for-woocommerce/v1#', '', $request->get_route() );
        if ( ! Signer::verify( $signature, $secret, $request->get_method(), $route, $timestamp, $nonce, $body ) ) {
            return $this->reject( 'kitgenix_multistore_sync_for_woocommerce_auth_signature', 'Request signature is invalid.', $store_uuid, $request );
        }
        set_transient( $nonce_key, 1, 10 * MINUTE_IN_SECONDS );
        $this->stores->touch( $store_uuid, 'active' );
        $request->set_param( '_kitgenix_multistore_sync_for_woocommerce_source_store', $store_uuid );
        return true;
    }

    private function reject( string $code, string $message, string $store_uuid, \WP_REST_Request $request ): \WP_Error {
        $recoverable = ErrorCatalog::is_recoverable( $code );
        $this->logger->log(
            $recoverable ? 'warning' : 'error',
            'Rejected incoming Kitgenix REST request: ' . $message,
            array(
                'code'  => $code,
                'store' => $store_uuid,
                'route' => $request->get_route(),
            )
        );

        return new \WP_Error( $code, $message, array( 'status' => 'kitgenix_multistore_sync_for_woocommerce_auth_replay' === $code ? 409 : 401 ) );
    }
}
