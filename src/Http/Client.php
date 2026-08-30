<?php
namespace Kitgenix\MultiStore\Http;

use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Support\Environment;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class Client {
    public function __construct( private StoreRepository $stores, private Logger $logger ) {}

    public function request( string $store_uuid, string $method, string $route, array $data = array(), bool $allow_nonprod = false ): array|\WP_Error {
        $store = $this->stores->get( $store_uuid );
        if ( ! $store ) { return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_store_missing', 'Connected store was not found.' ); }
        if ( Environment::is_non_production() && ! $allow_nonprod ) {
            $settings = new Settings();
            if ( 'yes' !== $settings->get( 'allow_non_production_outbound', 'no' ) ) {
                return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_staging_protection', 'Outbound network writes are disabled on non-production environments.' );
            }
        }
        $secret = $this->stores->secret( $store_uuid );
        if ( ! $secret ) { return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_secret_missing', 'Connection credential is unavailable.' ); }
        $body      = empty( $data ) ? '{}' : wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
        $timestamp = (string) time();
        $nonce     = wp_generate_password( 32, false, false );
        $signature = Signer::sign( $secret, $method, $route, $timestamp, $nonce, $body );
        $url       = trailingslashit( $store['url'] ) . 'wp-json/kitgenix-multistore-sync-for-woocommerce/v1' . $route;
        $settings = new Settings();
        $timeout = max( 5, min( 60, (int) $settings->get( 'request_timeout', 20 ) ) );
        $args = array(
            'method'      => strtoupper( $method ),
            'timeout'     => $timeout,
            'redirection' => 0,
            'sslverify'   => Environment::should_verify_ssl( $url ),
            'headers'     => array(
                'Content-Type'          => 'application/json',
                'X-Kitgenix-Store'      => Uuid::site(),
                'X-Kitgenix-Timestamp'  => $timestamp,
                'X-Kitgenix-Nonce'      => $nonce,
                'X-Kitgenix-Signature'  => $signature,
                'X-Kitgenix-Version'    => KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION,
            ),
            'body' => $body,
        );
        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) { return $response; }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $json ) ? (string) ( $json['message'] ?? $json['code'] ?? 'Remote request failed.' ) : 'Remote request failed.';
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_remote_http_' . $code, $message, array( 'status' => $code ) );
        }
        return is_array( $json ) ? $json : array( 'success' => true );
    }

    public function pair( string $url, string $code ): array|\WP_Error {
        $url = esc_url_raw( untrailingslashit( $url ) );
        if ( ! Environment::validate_store_url( $url ) ) { return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_invalid_url', 'Enter a valid store URL. HTTPS is required in production; recognised local development URLs are supported.' ); }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( 'https' !== $scheme && ! Environment::is_non_production() ) {
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_https_required', 'HTTPS is required when pairing production stores.' );
        }
        $secret = base64_encode( random_bytes( 32 ) );
        $payload = array(
            'code' => sanitize_text_field( $code ),
            'secret' => $secret,
            'store' => array(
                'uuid' => Uuid::site(),
                'name' => get_bloginfo( 'name' ),
                'url'  => home_url(),
                'role' => 'control',
            ),
        );
        $response = wp_remote_post(
            trailingslashit( $url ) . 'wp-json/kitgenix-multistore-sync-for-woocommerce/v1/pair',
            array( 'timeout' => 20, 'sslverify' => Environment::should_verify_ssl( $url ), 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) )
        );
        if ( is_wp_error( $response ) ) { return $response; }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $status || ! is_array( $data ) || empty( $data['store']['uuid'] ) ) {
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_pair_failed', is_array( $data ) ? (string) ( $data['message'] ?? 'Pairing failed.' ) : 'Pairing failed.' );
        }
        $capabilities = (array) ( $data['capabilities'] ?? array() );
        $capabilities['kitgenix'] = (string) ( $data['store']['version'] ?? '' );
        $saved = $this->stores->save( array_merge( $data['store'], array( 'role' => 'connected', 'status' => 'active', 'capabilities' => $capabilities ) ), $secret );
        return is_wp_error( $saved ) ? $saved : $data;
    }
}
