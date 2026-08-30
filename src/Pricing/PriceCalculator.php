<?php
namespace Kitgenix\MultiStore\Pricing;

final class PriceCalculator {

    public const MODES     = array( 'percentage', 'fixed', 'exchange' );
    public const ROUNDINGS = array( 'none', 'nearest_whole', 'nearest_0_50', 'charm_95', 'charm_99' );
    public const SALE_MODES = array( 'same_adjustment', 'maintain_discount_percent', 'source', 'clear' );

    /**
     * Apply a percentage adjustment to a WooCommerce decimal price.
     * Positive values increase the price; negative values decrease it.
     */
    public static function adjust( mixed $price, float $percentage ): string {
        if ( null === $price || '' === $price ) {
            return '';
        }

        $decimal = wc_format_decimal( $price );
        if ( '' === $decimal || ! is_numeric( $decimal ) ) {
            return '';
        }

        $percentage = max( -100.0, min( 1000.0, $percentage ) );
        $adjusted   = (float) $decimal * ( 1 + ( $percentage / 100 ) );
        $decimals   = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

        return wc_format_decimal( max( 0, $adjusted ), $decimals );
    }

    /** Add (or, for a negative amount, subtract) a flat currency amount. Floors at zero. */
    public static function adjust_fixed( mixed $price, float $amount ): string {
        if ( null === $price || '' === $price ) {
            return '';
        }
        $decimal = wc_format_decimal( $price );
        if ( '' === $decimal || ! is_numeric( $decimal ) ) {
            return '';
        }
        $amount   = max( -1000000.0, min( 1000000.0, $amount ) );
        $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
        return wc_format_decimal( max( 0, (float) $decimal + $amount ), $decimals );
    }

    /**
     * Multiply the raw price by a factor – e.g. a currency exchange rate
     * (1.15) or a straight markup expressed as a multiplier rather than a
     * percentage delta. A multiplier of 1.0 leaves the price unchanged.
     */
    public static function apply_multiplier( mixed $price, float $multiplier ): string {
        if ( null === $price || '' === $price ) {
            return '';
        }
        $decimal = wc_format_decimal( $price );
        if ( '' === $decimal || ! is_numeric( $decimal ) ) {
            return '';
        }
        $multiplier = max( 0.0, min( 100.0, $multiplier ) );
        $decimals   = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
        return wc_format_decimal( max( 0, (float) $decimal * $multiplier ), $decimals );
    }

    /**
     * Apply a rounding/psychological-pricing strategy to an already-adjusted price.
     * Never used to compute the adjustment itself – only to reshape the final figure.
     */
    public static function round_price( string $price, string $strategy ): string {
        if ( '' === $price || ! is_numeric( $price ) ) {
            return $price;
        }
        $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
        $value    = (float) $price;

        // charm_95/charm_99 round to the nearest whole unit, then land just
        // below it (X.95 / X.99) – the common "psychological pricing" convention.
        $rounded = match ( in_array( $strategy, self::ROUNDINGS, true ) ? $strategy : 'none' ) {
            'nearest_whole' => round( $value ),
            'nearest_0_50'  => round( $value * 2 ) / 2,
            'charm_95'      => max( 0.0, round( $value ) - 0.05 ),
            'charm_99'      => max( 0.0, round( $value ) - 0.01 ),
            default         => $value,
        };

        return wc_format_decimal( max( 0, $rounded ), $decimals );
    }

    public static function rule_enabled( array $rule ): bool {
        if ( 'yes' !== (string) ( $rule['enabled'] ?? 'no' ) ) {
            return false;
        }
        return match ( self::mode( $rule ) ) {
            'fixed'    => is_numeric( $rule['fixed_amount'] ?? null ),
            'exchange' => is_numeric( $rule['multiplier'] ?? null ),
            default    => is_numeric( $rule['percentage'] ?? null ),
        };
    }

    public static function mode( array $rule ): string {
        $mode = (string) ( $rule['mode'] ?? 'percentage' );
        return in_array( $mode, self::MODES, true ) ? $mode : 'percentage';
    }

    public static function rounding( array $rule ): string {
        $rounding = (string) ( $rule['rounding'] ?? 'none' );
        return in_array( $rounding, self::ROUNDINGS, true ) ? $rounding : 'none';
    }

    public static function sale_mode( array $rule ): string {
        $sale_mode = (string) ( $rule['sale_mode'] ?? 'same_adjustment' );
        return in_array( $sale_mode, self::SALE_MODES, true ) ? $sale_mode : 'same_adjustment';
    }

    /**
     * Apply one rule's adjustment + rounding to a single raw price value.
     * The single place that actually performs a price-rule calculation, so
     * every call site (regular price, sale price, every variation) goes
     * through identical logic.
     */
    private static function apply_rule_to_price( mixed $price, array $rule ): string {
        $adjusted = match ( self::mode( $rule ) ) {
            'fixed'    => self::adjust_fixed( $price, (float) ( $rule['fixed_amount'] ?? 0 ) ),
            'exchange' => self::apply_multiplier( $price, (float) ( $rule['multiplier'] ?? 1 ) ),
            default    => self::adjust( $price, (float) ( $rule['percentage'] ?? 0 ) ),
        };
        if ( '' === $adjusted ) {
            return $adjusted;
        }
        return self::round_price( $adjusted, self::rounding( $rule ) );
    }

    /**
     * Finalise product pricing for one destination store before transport.
     *
     * The Control Store knows the destination UUID at queue time, so pricing
     * is calculated here rather than relying on the receiving store to look
     * itself up inside a network rule array. This also makes the transport
     * payload auditable: the prices in the packet are the prices to be saved.
     *
     * Every adjustment is calculated fresh from the payload's current source
     * regular/sale price on every call – never from a previously-adjusted
     * destination value – so re-syncing a product never compounds the rule.
     */
    public static function apply_for_store( array $payload, array $rule = array(), array $override = array(), string $store_uuid = '' ): array {
        $source_regular = (string) ( $payload['regular_price'] ?? '' );
        $source_sale    = (string) ( $payload['sale_price'] ?? '' );
        $rule_applied   = self::rule_enabled( $rule );
        $sale_mode      = self::sale_mode( $rule );

        // Original discount ratio, from the SOURCE prices only – used by
        // maintain_discount_percent so a destination's sale keeps the same
        // percentage-off relationship even though its regular price differs.
        $source_discount_ratio = null;
        if ( '' !== $source_sale && is_numeric( $source_sale ) && is_numeric( $source_regular ) && (float) $source_regular > 0 && (float) $source_sale < (float) $source_regular ) {
            $source_discount_ratio = 1 - ( (float) $source_sale / (float) $source_regular );
        }

        if ( $rule_applied ) {
            $payload['regular_price'] = self::apply_rule_to_price( $source_regular, $rule );
            $payload['sale_price']    = self::resolve_sale_price( $source_sale, $payload['regular_price'], $rule, $sale_mode, $source_discount_ratio );

            foreach ( (array) ( $payload['variations'] ?? array() ) as $index => $variation ) {
                if ( ! is_array( $variation ) ) {
                    continue;
                }
                $v_source_regular = (string) ( $variation['regular_price'] ?? '' );
                $v_source_sale    = (string) ( $variation['sale_price'] ?? '' );
                $v_discount_ratio = null;
                if ( '' !== $v_source_sale && is_numeric( $v_source_sale ) && is_numeric( $v_source_regular ) && (float) $v_source_regular > 0 && (float) $v_source_sale < (float) $v_source_regular ) {
                    $v_discount_ratio = 1 - ( (float) $v_source_sale / (float) $v_source_regular );
                }
                $variation['regular_price'] = self::apply_rule_to_price( $v_source_regular, $rule );
                $variation['sale_price']    = self::resolve_sale_price( $v_source_sale, $variation['regular_price'], $rule, $sale_mode, $v_discount_ratio );
                $payload['variations'][ $index ] = $variation;
            }
        }

        // Explicit product/store prices always win over an automatic rule.
        if ( array_key_exists( 'regular_price', $override ) && '' !== (string) $override['regular_price'] ) {
            $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
            $payload['regular_price'] = wc_format_decimal( $override['regular_price'], $decimals );
        }
        if ( array_key_exists( 'sale_price', $override ) ) {
            $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
            $payload['sale_price'] = wc_format_decimal( $override['sale_price'], $decimals );
        }

        $payload['_pricing'] = array(
            'calculated_by'       => 'control',
            'destination_store'   => $store_uuid,
            'rule_enabled'        => $rule_applied,
            'mode'                => self::mode( $rule ),
            'rounding'            => self::rounding( $rule ),
            'sale_mode'           => $sale_mode,
            'percentage'          => $rule_applied ? (float) ( $rule['percentage'] ?? 0 ) : 0.0,
            'fixed_amount'        => $rule_applied ? (float) ( $rule['fixed_amount'] ?? 0 ) : 0.0,
            'multiplier'          => $rule_applied ? (float) ( $rule['multiplier'] ?? 1 ) : 1.0,
            'source_regular_price'=> $source_regular,
            'source_sale_price'   => $source_sale,
            'final_regular_price' => (string) ( $payload['regular_price'] ?? '' ),
            'final_sale_price'    => (string) ( $payload['sale_price'] ?? '' ),
            'manual_override'     => ! empty( $override ),
        );

        // Pricing has already been resolved for this destination. Removing
        // these prevents a receiver from applying the same adjustment twice.
        unset( $payload['store_pricing_rules'], $payload['store_overrides'] );

        return $payload;
    }

    /**
     * Resolve the destination sale price per the rule's sale-price handling mode.
     *
     * @param string      $source_sale     Raw source sale price (may be '').
     * @param string      $new_regular     Already-adjusted destination regular price.
     * @param array       $rule            The store's pricing rule.
     * @param string      $sale_mode       One of self::SALE_MODES.
     * @param float|null  $discount_ratio  Source discount ratio (0-1), or null if no source sale.
     */
    private static function resolve_sale_price( string $source_sale, string $new_regular, array $rule, string $sale_mode, ?float $discount_ratio ): string {
        return match ( $sale_mode ) {
            'clear'  => '',
            // Rule adjusts regular price only; sale price passes through unadjusted
            // (still normalised to the store's decimal precision for consistency).
            'source' => '' === $source_sale ? '' : wc_format_decimal( $source_sale, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 ),
            'maintain_discount_percent' => ( null === $discount_ratio || '' === $new_regular || ! is_numeric( $new_regular ) )
                ? '' // No source sale to preserve the ratio of, or nothing to base it on.
                : self::round_price( wc_format_decimal( max( 0, (float) $new_regular * ( 1 - $discount_ratio ) ), function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 ), self::rounding( $rule ) ),
            default => self::apply_rule_to_price( $source_sale, $rule ), // same_adjustment
        };
    }
}
