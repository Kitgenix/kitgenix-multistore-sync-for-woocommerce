<?php
namespace Kitgenix\MultiStore\Pricing;

final class PriceCalculator {
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

    public static function rule_enabled( array $rule ): bool {
        return 'yes' === (string) ( $rule['enabled'] ?? 'no' ) && is_numeric( $rule['percentage'] ?? null );
    }

    /**
     * Finalise product pricing for one destination store before transport.
     *
     * The Control Store knows the destination UUID at queue time, so pricing
     * is calculated here rather than relying on the receiving store to look
     * itself up inside a network rule array. This also makes the transport
     * payload auditable: the prices in the packet are the prices to be saved.
     */
    public static function apply_for_store( array $payload, array $rule = array(), array $override = array(), string $store_uuid = '' ): array {
        $source_regular = (string) ( $payload['regular_price'] ?? '' );
        $source_sale    = (string) ( $payload['sale_price'] ?? '' );
        $percentage     = self::rule_enabled( $rule ) ? (float) $rule['percentage'] : 0.0;
        $rule_applied   = self::rule_enabled( $rule );

        if ( $rule_applied ) {
            $payload['regular_price'] = self::adjust( $source_regular, $percentage );
            $payload['sale_price']    = self::adjust( $source_sale, $percentage );

            foreach ( (array) ( $payload['variations'] ?? array() ) as $index => $variation ) {
                if ( ! is_array( $variation ) ) {
                    continue;
                }
                $variation['regular_price'] = self::adjust( $variation['regular_price'] ?? '', $percentage );
                $variation['sale_price']    = self::adjust( $variation['sale_price'] ?? '', $percentage );
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
            'percentage'          => $rule_applied ? $percentage : 0.0,
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
}
