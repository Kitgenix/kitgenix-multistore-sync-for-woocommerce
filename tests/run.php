<?php
/**
 * Lightweight dependency-free tests for security primitives.
 * Run: php tests/run.php
 */

declare(strict_types=1);

define( 'AUTH_KEY', 'kitgenix-multistore-sync-for-woocommerce_test-auth-key-do-not-use-in-production' );
define( 'SECURE_AUTH_KEY', 'kitgenix-multistore-sync-for-woocommerce_test-secure-auth-key-do-not-use-in-production' );

require_once dirname( __DIR__ ) . '/src/Http/Signer.php';
require_once dirname( __DIR__ ) . '/src/Support/Crypto.php';

use Kitgenix\MultiStore\Http\Signer;
use Kitgenix\MultiStore\Support\Crypto;

$tests = 0;
$failed = 0;

$assert = static function ( bool $condition, string $label ) use ( &$tests, &$failed ): void {
    ++$tests;
    if ( $condition ) {
        echo "PASS: {$label}\n";
        return;
    }
    ++$failed;
    fwrite( STDERR, "FAIL: {$label}\n" );
};

$secret = base64_encode( random_bytes( 32 ) );
$method = 'POST';
$route = '/events';
$timestamp = '1786950000';
$nonce = 'test-nonce-12345678901234567890';
$body = '{"event_uuid":"123","quantity":42}';
$sig = Signer::sign( $secret, $method, $route, $timestamp, $nonce, $body );

$assert( 64 === strlen( $sig ), 'HMAC signature is 64 hex characters' );
$assert( Signer::verify( $sig, $secret, $method, $route, $timestamp, $nonce, $body ), 'Valid HMAC request verifies' );
$assert( ! Signer::verify( $sig, $secret, $method, $route, $timestamp, $nonce, $body . 'x' ), 'Tampered body is rejected' );
$assert( ! Signer::verify( $sig, $secret, 'GET', $route, $timestamp, $nonce, $body ), 'Tampered method is rejected' );
$assert( ! Signer::verify( $sig, $secret, $method, '/orders/refund', $timestamp, $nonce, $body ), 'Tampered route is rejected' );

$plaintext = base64_encode( random_bytes( 48 ) );
$ciphertext = Crypto::encrypt( $plaintext );
$assert( $ciphertext !== $plaintext, 'Credential is encrypted at rest' );
$assert( Crypto::decrypt( $ciphertext ) === $plaintext, 'Encrypted credential round-trips' );
$raw = base64_decode( $ciphertext, true );
if ( false !== $raw && strlen( $raw ) > 30 ) {
    $raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
    $tampered = base64_encode( $raw );
    $assert( '' === Crypto::decrypt( $tampered ), 'AES-GCM rejects tampered ciphertext' );
} else {
    $assert( false, 'Encrypted payload can be decoded for tamper test' );
}

$assert( '' === Crypto::decrypt( 'not-valid-base64***' ), 'Malformed ciphertext fails closed' );

echo "\n{$tests} tests, {$failed} failures.\n";
exit( $failed > 0 ? 1 : 0 );
