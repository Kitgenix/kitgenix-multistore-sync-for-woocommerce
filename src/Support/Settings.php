<?php
namespace Kitgenix\MultiStore\Support;

final class Settings {
    private const KEY = 'kitgenix_multistore_sync_for_woocommerce_settings';

    public function all(): array {
        $defaults = array(
            'role' => 'control',
            'network_mode' => 'paired',
            'native_control_blog_id' => 0,
            'product_direction' => 'control_only',
            'sync_products' => 'yes',
            'sync_inventory' => 'yes',
            'sync_orders' => 'yes',
            'sync_coupons' => 'yes',
            'sync_customers' => 'no',
            'sync_images' => 'yes',
            'media_mode' => 'local_copy',
            'sync_reviews' => 'no',
            'review_direction' => 'network',
            'sync_rest_products' => 'yes',
            'sku_assisted_linking' => 'yes',
            'checkout_inventory_validation' => 'yes',
            'inventory_fail_closed' => 'no',
            'auto_publish_new_products' => 'yes',
            'allow_non_production_outbound' => 'no',
            'max_retries' => 8,
            'request_timeout' => 20,
            'log_retention_days' => 30,
            'allow_remote_gateway_refunds' => 'no',
            'store_groups' => array(),
            'store_pricing_rules' => array(),
            'store_policies' => array(),
            'custom_meta_keys' => array(),
            'custom_taxonomies' => array(),
            'term_meta_keys' => array( 'display_type' ),
            'network_my_account_orders' => 'no',
            'sequential_order_numbers' => 'no',
            'order_number_prefix' => '',
            'order_number_start' => 1000,
            'order_import_mode' => 'snapshot',
            'clone_products_on_order_import' => 'no',
            'allowed_roles' => array( 'administrator', 'shop_manager' ),
        );
        return wp_parse_args( (array) get_option( self::KEY, array() ), $defaults );
    }

    public function get( string $key, mixed $default = null ): mixed {
        $all = $this->all();
        return $all[ $key ] ?? $default;
    }

    public function set( array $values ): void {
        update_option( self::KEY, array_merge( $this->all(), $values ), false );
    }

    public function groups(): array {
        $groups = (array) $this->get( 'store_groups', array() );
        $out = array();
        foreach ( $groups as $id => $group ) {
            if ( ! is_array( $group ) ) continue;
            $id = sanitize_key( (string) $id );
            if ( ! $id ) continue;
            $out[ $id ] = array(
                'name' => sanitize_text_field( (string) ( $group['name'] ?? $id ) ),
                'stores' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $group['stores'] ?? array() ) ) ) ) ),
            );
        }
        return $out;
    }

    public function save_groups( array $groups ): void { $this->set( array( 'store_groups' => $groups ) ); }

    public function pricing_rules(): array {
        $rules = (array) $this->get( 'store_pricing_rules', array() );
        $out = array();
        foreach ( $rules as $uuid => $rule ) {
            $uuid = sanitize_text_field( (string) $uuid );
            if ( ! wp_is_uuid( $uuid ) || ! is_array( $rule ) ) continue;
            $percentage = is_numeric( $rule['percentage'] ?? null ) ? (float) $rule['percentage'] : 0.0;
            $out[ $uuid ] = array(
                'enabled' => 'yes' === (string) ( $rule['enabled'] ?? 'no' ) ? 'yes' : 'no',
                'percentage' => max( -100.0, min( 1000.0, $percentage ) ),
            );
        }
        return $out;
    }

    public function pricing_rule( string $uuid ): array {
        return $this->pricing_rules()[ $uuid ] ?? array( 'enabled' => 'no', 'percentage' => 0.0 );
    }

    public function save_pricing_rules( array $rules ): void { $this->set( array( 'store_pricing_rules' => $rules ) ); }

    public function policies(): array {
        $stored = (array) $this->get( 'store_policies', array() );
        $out = array();
        foreach ( $stored as $uuid => $policy ) {
            if ( ! wp_is_uuid( (string) $uuid ) || ! is_array( $policy ) ) continue;
            $out[ $uuid ] = $this->sanitize_policy( $policy );
        }
        return $out;
    }

    public function policy( string $uuid ): array {
        $policies = $this->policies();
        return $policies[ $uuid ] ?? $this->default_policy();
    }

    public function save_policies( array $policies ): void { $this->set( array( 'store_policies' => $policies ) ); }

    public function default_policy(): array {
        $fields = array();
        foreach ( self::policy_fields() as $key => $_label ) {
            $fields[ $key ] = array( 'publish' => 'yes', 'update' => 'yes' );
        }
        return array( 'fields' => $fields );
    }

    public static function policy_fields(): array {
        return array(
            'name' => 'Product name', 'slug' => 'Slug', 'status' => 'Status', 'featured' => 'Featured',
            'catalog_visibility' => 'Catalog visibility', 'description' => 'Description', 'short_description' => 'Short description',
            'sku' => 'SKU', 'global_unique_id' => 'GTIN / unique ID', 'price' => 'Regular & sale prices', 'tax' => 'Tax',
            'inventory' => 'Inventory', 'backorders' => 'Backorders', 'low_stock' => 'Low-stock threshold', 'sold_individually' => 'Sold individually',
            'shipping' => 'Weight, dimensions & shipping class', 'reviews_allowed' => 'Reviews enabled', 'reviews' => 'Product reviews',
            'purchase_note' => 'Purchase note', 'downloads' => 'Downloads', 'categories' => 'Categories', 'tags' => 'Tags', 'brands' => 'Brands',
            'custom_taxonomies' => 'Custom taxonomies', 'attributes' => 'Attributes & terms', 'images' => 'Images & image metadata',
            'linked_products' => 'Upsells, cross-sells & grouped children', 'variations' => 'Variations', 'seo' => 'SEO metadata',
            'custom_meta' => 'Custom metadata', 'language' => 'WPML / Polylang language', 'integration_data' => 'Integration-specific data',
        );
    }

    private function sanitize_policy( array $policy ): array {
        $defaults = $this->default_policy();
        foreach ( self::policy_fields() as $key => $_label ) {
            $row = (array) ( $policy['fields'][ $key ] ?? array() );
            $defaults['fields'][ $key ] = array(
                'publish' => 'yes' === (string) ( $row['publish'] ?? 'yes' ) ? 'yes' : 'no',
                'update' => 'yes' === (string) ( $row['update'] ?? 'yes' ) ? 'yes' : 'no',
            );
        }
        return $defaults;
    }

    public function custom_meta_keys(): array {
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $this->get( 'custom_meta_keys', array() ) ) ) ) );
    }

    public function custom_taxonomies(): array {
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $this->get( 'custom_taxonomies', array() ) ) ) ) );
    }

    public function term_meta_keys(): array {
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $this->get( 'term_meta_keys', array() ) ) ) ) );
    }

    public function allowed_roles(): array {
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $this->get( 'allowed_roles', array( 'administrator', 'shop_manager' ) ) ) ) ) );
    }

    public function role(): string { return (string) $this->get( 'role', 'control' ); }
    public function is_control(): bool { return 'control' === $this->role(); }
    public function is_connected(): bool { return 'connected' === $this->role(); }
}
