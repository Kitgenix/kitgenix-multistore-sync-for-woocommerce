<?php
/**
 * Dependency-light tests for the percentage pricing engine.
 * Run: php tests/pricing.php
 */

declare(strict_types=1);

function wc_get_price_decimals(): int { return 2; }
function wc_format_decimal( mixed $number, mixed $dp = false, bool $trim_zeros = false ): string {
    if ( null === $number || '' === $number || ! is_numeric( $number ) ) return '';
    $decimals = false === $dp ? 6 : (int) $dp;
    $formatted = number_format( (float) $number, $decimals, '.', '' );
    return $trim_zeros ? rtrim( rtrim( $formatted, '0' ), '.' ) : $formatted;
}

require_once dirname( __DIR__ ) . '/src/Pricing/PriceCalculator.php';

use Kitgenix\MultiStore\Pricing\PriceCalculator;

$tests = 0;
$failed = 0;
$assert = static function ( bool $condition, string $label ) use ( &$tests, &$failed ): void {
    ++$tests;
    if ( $condition ) { echo "PASS: {$label}\n"; return; }
    ++$failed;
    fwrite( STDERR, "FAIL: {$label}\n" );
};

$assert( '125.00' === PriceCalculator::adjust( '100', 25 ), '25 percent markup' );
$assert( '130.00' === PriceCalculator::adjust( '100', 30 ), '30 percent markup' );
$assert( '90.00' === PriceCalculator::adjust( '100', -10 ), 'negative percentage reduction' );
$assert( '99.94' === PriceCalculator::adjust( '79.95', 25 ), 'percentage pricing rounds to WooCommerce decimals' );
$assert( '' === PriceCalculator::adjust( '', 25 ), 'empty sale price remains empty' );
$assert( '0.00' === PriceCalculator::adjust( '100', -100 ), '100 percent reduction floors at zero' );
$assert( PriceCalculator::rule_enabled( array( 'enabled' => 'yes', 'percentage' => 27.5 ) ), 'enabled numeric rule accepted' );
$assert( ! PriceCalculator::rule_enabled( array( 'enabled' => 'no', 'percentage' => 27.5 ) ), 'disabled rule ignored' );

$assert( '137.50' === PriceCalculator::adjust( '110', 25 ), 'variation-style price adjustment' );


$payload = array(
    'regular_price' => '100',
    'sale_price' => '80',
    'variations' => array(
        array( 'regular_price' => '20', 'sale_price' => '15' ),
        array( 'regular_price' => '40', 'sale_price' => '' ),
    ),
    'store_pricing_rules' => array( 'dummy' => array() ),
    'store_overrides' => array( 'dummy' => array() ),
);
$priced = PriceCalculator::apply_for_store(
    $payload,
    array( 'enabled' => 'yes', 'percentage' => 25 ),
    array(),
    '11111111-1111-4111-8111-111111111111'
);
$assert( '125.00' === $priced['regular_price'], 'destination payload regular price is finalised before transport' );
$assert( '100.00' === $priced['sale_price'], 'destination payload sale price is finalised before transport' );
$assert( '25.00' === $priced['variations'][0]['regular_price'], 'variation regular price is finalised before transport' );
$assert( '18.75' === $priced['variations'][0]['sale_price'], 'variation sale price is finalised before transport' );
$assert( '' === $priced['variations'][1]['sale_price'], 'empty variation sale remains empty' );
$assert( ! isset( $priced['store_pricing_rules'], $priced['store_overrides'] ), 'raw pricing rules removed after destination pricing is resolved' );
$assert( true === $priced['_pricing']['rule_enabled'], 'pricing audit metadata records applied rule' );
$assert( '125.00' === $priced['_pricing']['final_regular_price'], 'pricing audit metadata records final price' );

$manual = PriceCalculator::apply_for_store(
    $payload,
    array( 'enabled' => 'yes', 'percentage' => 25 ),
    array( 'regular_price' => '149.95', 'sale_price' => '' ),
    '11111111-1111-4111-8111-111111111111'
);
$assert( '149.95' === $manual['regular_price'], 'manual store regular-price override wins over percentage rule' );
$assert( '' === $manual['sale_price'], 'manual blank sale-price override clears automatic sale price' );

echo "\n{$tests} tests, {$failed} failures.\n";
exit( $failed > 0 ? 1 : 0 );
