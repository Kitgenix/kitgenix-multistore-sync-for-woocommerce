<?php
namespace Kitgenix\MultiStore\Http;

final class Signer {
    public static function canonical( string $method, string $route, string $timestamp, string $nonce, string $body ): string {
        return strtoupper( $method ) . "\n" . $route . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );
    }
    public static function sign( string $secret, string $method, string $route, string $timestamp, string $nonce, string $body ): string {
        return hash_hmac( 'sha256', self::canonical( $method, $route, $timestamp, $nonce, $body ), $secret );
    }
    public static function verify( string $signature, string $secret, string $method, string $route, string $timestamp, string $nonce, string $body ): bool {
        return hash_equals( self::sign( $secret, $method, $route, $timestamp, $nonce, $body ), $signature );
    }
}
