<?php
namespace Kitgenix\MultiStore\Support;

final class Environment {
    public static function is_non_production(): bool {
        if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
            return true;
        }
        $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        foreach ( array( 'staging', 'stage', 'dev.', '.dev', 'local', 'test.', '.test' ) as $needle ) {
            if ( str_contains( $host, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine whether a URL clearly points at a local/private development host.
     *
     * This is intentionally conservative. It exists so Local/LocalWP and similar
     * environments can communicate without weakening production URL validation.
     */
    public static function is_local_url( string $url ): bool {
        $host = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );
        if ( '' === $host ) {
            return false;
        }

        if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' ) ) {
            return true;
        }

        // IPv6 loopback.
        if ( '::1' === trim( $host, '[]' ) ) {
            return true;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $long = ip2long( $host );
            if ( false === $long ) {
                return false;
            }
            $long = sprintf( '%u', $long );
            $ranges = array(
                array( ip2long( '10.0.0.0' ), ip2long( '10.255.255.255' ) ),
                array( ip2long( '127.0.0.0' ), ip2long( '127.255.255.255' ) ),
                array( ip2long( '172.16.0.0' ), ip2long( '172.31.255.255' ) ),
                array( ip2long( '192.168.0.0' ), ip2long( '192.168.255.255' ) ),
            );
            foreach ( $ranges as [ $start, $end ] ) {
                if ( (float) $long >= (float) sprintf( '%u', $start ) && (float) $long <= (float) sprintf( '%u', $end ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate a MultiStore base URL.
     *
     * Production retains WordPress' strict safe-URL validation (including SSRF
     * protection). Recognised local/private URLs receive syntactic validation
     * only, and only while the current site is itself non-production.
     */
    public static function validate_store_url( string $url ): bool {
        $url = trim( $url );
        if ( '' === $url ) {
            return false;
        }

        $parts = wp_parse_url( $url );
        if ( false === $parts || ! is_array( $parts ) ) {
            return false;
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = (string) ( $parts['host'] ?? '' );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
            return false;
        }

        // Store URLs are origins, not credentialed/fragmented URLs.
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }

        if ( self::is_non_production() && self::is_local_url( $url ) ) {
            return true;
        }

        return (bool) wp_http_validate_url( $url );
    }

    /**
     * LocalWP uses development/self-signed certificates. Browser/Keychain trust
     * does not guarantee the PHP/WordPress CA bundle trusts the same certificate.
     * Verification is relaxed only for clearly-local targets from non-production.
     */
    public static function should_verify_ssl( string $url ): bool {
        $verify = ! ( self::is_non_production() && self::is_local_url( $url ) );
        return (bool) apply_filters( 'kitgenix_multistore_sync_for_woocommerce_sslverify', $verify, $url );
    }

    /**
     * Run one WordPress HTTP operation with narrowly-scoped LocalWP allowances.
     * No production or external host has its URL/SSL protections relaxed.
     */
    public static function with_local_http_access( string $url, callable $callback ): mixed {
        if ( ! self::is_non_production() || ! self::is_local_url( $url ) ) {
            return $callback();
        }

        $target_host = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );

        $host_filter = static function ( bool $external, string $host, string $request_url ) use ( $target_host ): bool {
            unset( $request_url );
            return strtolower( rtrim( $host, '.' ) ) === $target_host ? true : $external;
        };
        $args_filter = static function ( array $args, string $request_url ) use ( $target_host ): array {
            $request_host = strtolower( rtrim( (string) wp_parse_url( $request_url, PHP_URL_HOST ), '.' ) );
            if ( $request_host === $target_host ) {
                $args['sslverify'] = false;
            }
            return $args;
        };

        add_filter( 'http_request_host_is_external', $host_filter, 10, 3 );
        add_filter( 'http_request_args', $args_filter, 10, 2 );
        try {
            return $callback();
        } finally {
            remove_filter( 'http_request_host_is_external', $host_filter, 10 );
            remove_filter( 'http_request_args', $args_filter, 10 );
        }
    }

}
