<?php
namespace Kitgenix\MultiStore\Support;

final class Crypto {
    private static function key(): string {
        $material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . '|kitgenix-multistore';
        return hash( 'sha256', $material, true );
    }

    public static function encrypt( string $plaintext ): string {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            throw new \RuntimeException( 'OpenSSL is required to securely store MultiStore credentials.' );
        }
        $iv  = random_bytes( 12 );
        $tag = '';
        $enc = openssl_encrypt( $plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $enc ) {
            throw new \RuntimeException( 'Unable to encrypt MultiStore credential.' );
        }
        return base64_encode( $iv . $tag . $enc );
    }

    public static function decrypt( string $ciphertext ): string {
        if ( '' === $ciphertext || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $raw = base64_decode( $ciphertext, true );
        if ( false === $raw || strlen( $raw ) < 29 ) {
            return '';
        }
        $iv  = substr( $raw, 0, 12 );
        $tag = substr( $raw, 12, 16 );
        $enc = substr( $raw, 28 );
        $out = openssl_decrypt( $enc, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        return false === $out ? '' : $out;
    }
}
