<?php
/**
 * Static release gates that do not require WordPress.
 * Run: php tests/static-audit.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$php_files = array();
$all = '';
foreach ( $iterator as $file ) {
    if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
        $path = $file->getPathname();
        if ( str_contains( $path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) continue;
        $php_files[] = $path;
        $all .= "\n" . file_get_contents( $path );
    }
}

$tests = array(
    'No unauthenticated WordPress AJAX endpoints' => ! str_contains( $all, 'wp_ajax_nopriv_' ),
    'No admin-ajax transport dependency' => ! str_contains( $all, 'admin-ajax.php' ),
    'No debug var_dump calls' => ! preg_match( '/\bvar_dump\s*\(/', $all ),
    'No debug print_r calls' => ! preg_match( '/\bprint_r\s*\(/', $all ),
    'HMAC transport is present' => str_contains( $all, 'hash_hmac' ) && str_contains( $all, 'X-Kitgenix-Signature' ),
    'Replay protection is present' => str_contains( $all, 'kitgenix_multistore_sync_for_woocommerce_nonce_' ) && str_contains( $all, 'kitgenix_multistore_sync_for_woocommerce_auth_replay' ),
    'Production HTTPS pairing gate is present' => str_contains( $all, 'kitgenix_multistore_sync_for_woocommerce_https_required' ),
    'Order endpoint topology authorization is present' => str_contains( $all, "require_peer_role(\$request,'control')" ),
    'Event topology authorization is present' => str_contains( $all, 'authorize_event' ) && str_contains( $all, 'kitgenix_multistore_sync_for_woocommerce_event_topology' ),
    'Inventory event idempotency is present' => str_contains( $all, 'UNIQUE KEY event_uuid (event_uuid)' ),
    'HPOS compatibility declaration is present' => str_contains( $all, "declare_compatibility( 'custom_order_tables'" ),
    'Gateway refunds default to disabled' => preg_match( "/'allow_remote_gateway_refunds'\s*=>\s*'no'/", $all ) === 1,
    'Staging protection defaults to disabled outbound writes' => str_contains( $all, "'allow_non_production_outbound' => 'no'" ),
    'Direct-save inventory capture is present' => str_contains( $all, 'woocommerce_product_object_updated_props' ) && str_contains( $all, 'after_object_save' ),
    'WooCommerce Square inventory source detection is present' => str_contains( $all, 'DOING_SQUARE_SYNC' ) && str_contains( $all, "return'square'" ),
    'Native stock duplicate suppression is present' => str_contains( $all, 'native_stock_update' ),
    'Square inventory uses absolute authoritative set' => str_contains( $all, "'square'===\$base['reference']" ) && str_contains( $all, 'apply_set(' ) && str_contains( $all, 'square_inventory_set' ),
);

$failed = 0;
foreach ( $tests as $label => $ok ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
    if ( ! $ok ) ++$failed;
}

echo "\n" . count( $tests ) . " static gates, {$failed} failures across " . count( $php_files ) . " production PHP files.\n";
exit( $failed > 0 ? 1 : 0 );
