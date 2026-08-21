<?php
namespace Kitgenix\MultiStore\Integration;

use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Sync\StorePolicy;

final class Registry {
    public function __construct( private Logger $logger ) {}

    public function hooks(): void {
        add_filter( 'kitgenix_multistore/product/meta_keys', array( $this, 'meta_keys' ), 20 );
        add_filter( 'kitgenix_multistore/product/payload', array( $this, 'product_payload' ), 20, 2 );
        add_action( 'kitgenix_multistore/product_applied', array( $this, 'product_applied' ), 20, 2 );
        add_filter( 'kitgenix_multistore/order/payload', array( $this, 'order_payload' ), 20, 2 );
        add_filter( 'wpo_wcpdf_document_link_access_type', array( $this, 'pdf_access_type' ), 9999, 1 );
    }

    public function meta_keys( array $keys ): array {
        $keys = array_merge( $keys, array(
            '_multi_inventory', '_inventory_sorting_mode', '_inventory_iteration', '_expirable_inventories', '_price_per_inventory',
            '_wc_cog_cost', '_per_product_shipping', '_per_product_shipping_add_to_all', 'yikes_woo_products_tabs',
            '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_wp_page_template',
            '_elementor_data', '_elementor_controls_usage',
            '_wpm_gtin_code', '_wpm_gtin_code_label',
            'wooct_active', 'wooct_style', 'wooct_time_start', 'wooct_time_end', 'wooct_text_above', 'wooct_text_under', 'wooct_text_ended',
        ) );
        if ( function_exists( 'alg_get_user_roles' ) ) {
            $keys[] = '_alg_wc_price_by_user_role_per_product_settings_enabled';
            foreach ( (array) alg_get_user_roles() as $role_key => $_role ) {
                $role_key = sanitize_key( (string) $role_key );
                foreach ( array( 'regular_price', 'sale_price', 'empty_price' ) as $kind ) $keys[] = '_alg_wc_price_by_user_role_' . $kind . '_' . $role_key;
            }
        }
        return array_values( array_unique( $keys ) );
    }

    public function product_payload( array $data, \WC_Product $product ): array {
        $integration = array();
        if ( function_exists( 'pll_get_post_language' ) ) {
            $lang = pll_get_post_language( $product->get_id(), 'slug' );
            if ( $lang ) $integration['polylang_language'] = sanitize_key( (string) $lang );
        }
        if ( has_filter( 'wpml_post_language_details' ) ) {
            $details = apply_filters( 'wpml_post_language_details', null, $product->get_id() );
            if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
                $integration['wpml'] = array(
                    'language_code' => sanitize_key( (string) $details['language_code'] ),
                    'source_language_code' => sanitize_key( (string) ( $details['source_language_code'] ?? '' ) ),
                );
            }
        }
        global $wpdb;
        $table = $wpdb->prefix . 'innozilla_per_product_shipping_rules_woo';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $integration['per_product_shipping_rules'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id=%d", $product->get_id() ), ARRAY_A ) ?: array();
        }
        $data['integration_data'] = $integration;
        return $data;
    }

    public function product_applied( \WC_Product $product, array $data ): void {
        $integration = (array) ( $data['integration_data'] ?? array() );
        $allow_language = StorePolicy::allows( $data, 'language' );
        $allow_integration = StorePolicy::allows( $data, 'integration_data' );
        if ( $allow_language && ! empty( $integration['polylang_language'] ) && function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $product->get_id(), sanitize_key( (string) $integration['polylang_language'] ) );
        }
        if ( $allow_language && ! empty( $integration['wpml']['language_code'] ) && has_action( 'wpml_set_element_language_details' ) ) {
            $type = apply_filters( 'wpml_element_type', 'post_product' );
            do_action( 'wpml_set_element_language_details', array(
                'element_id' => $product->get_id(), 'element_type' => $type,
                'trid' => false, 'language_code' => sanitize_key( (string) $integration['wpml']['language_code'] ),
                'source_language_code' => sanitize_key( (string) ( $integration['wpml']['source_language_code'] ?? '' ) ) ?: null,
            ) );
        }
        if ( $allow_integration && array_key_exists( 'per_product_shipping_rules', $integration ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'innozilla_per_product_shipping_rules_woo';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
                $wpdb->delete( $table, array( 'product_id' => $product->get_id() ) );
                foreach ( (array) $integration['per_product_shipping_rules'] as $rule ) {
                    if ( ! is_array( $rule ) ) continue;
                    unset( $rule['iz_rule_id'], $rule['product_id'] );
                    $rule = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $rule );
                    $rule['product_id'] = $product->get_id();
                    $wpdb->insert( $table, $rule );
                }
            }
        }
    }

    public function order_payload( array $data, \WC_Order $order ): array {
        if ( function_exists( 'wcpdf_get_document' ) && function_exists( 'WPO_WCPDF' ) ) {
            $docs = array();
            try {
                $plugin = WPO_WCPDF();
                $definitions = is_object( $plugin ) && isset( $plugin->documents ) && method_exists( $plugin->documents, 'get_documents' ) ? (array) $plugin->documents->get_documents() : array();
                foreach ( $definitions as $definition ) {
                    if ( ! is_object( $definition ) || ! method_exists( $definition, 'get_type' ) ) continue;
                    $type = sanitize_key( (string) $definition->get_type() ); if ( ! $type ) continue;
                    $document = wcpdf_get_document( $type, $order ); if ( ! $document ) continue;
                    $token = hash_hmac( 'sha256', $order->get_id() . '|' . $type . '|' . $order->get_order_key(), wp_salt( 'auth' ) );
                    set_transient( 'kitgenix_multistore_sync_for_woocommerce_pdf_' . hash( 'sha256', $token ), array( 'order_id' => $order->get_id(), 'type' => $type ), 30 * DAY_IN_SECONDS );
                    $url = isset( $plugin->endpoint ) && method_exists( $plugin->endpoint, 'get_document_link' ) ? $plugin->endpoint->get_document_link( $order, $type, array( 'kitgenix_multistore' => $token ) ) : '';
                    $docs[ $type ] = array(
                        'title' => method_exists( $document, 'get_title' ) ? sanitize_text_field( (string) $document->get_title() ) : ucfirst( str_replace( '-', ' ', $type ) ),
                        'exists' => method_exists( $document, 'exists' ) ? (bool) $document->exists() : true,
                        'url' => esc_url_raw( (string) $url ),
                    );
                }
            } catch ( \Throwable $e ) { $this->logger->warning( 'PDF document integration could not build order links.', array( 'order_id' => $order->get_id(), 'error' => $e->getMessage() ) ); }
            if ( $docs ) $data['pdf_documents'] = $docs;
        }
        return $data;
    }

    public function pdf_access_type( string $access_type ): string {
        $token = isset( $_GET['kitgenix_multistore'] ) ? sanitize_text_field( wp_unslash( $_GET['kitgenix_multistore'] ) ) : '';
        if ( ! $token ) return $access_type;
        $record = get_transient( 'kitgenix_multistore_sync_for_woocommerce_pdf_' . hash( 'sha256', $token ) );
        return is_array( $record ) && ! empty( $record['order_id'] ) ? 'full' : $access_type;
    }

    public function statuses(): array {
        return array(
            'WooCommerce Square' => array( 'detected' => defined( 'WC_SQUARE_PLUGIN_VERSION' ), 'integrated' => true ),
            'Rank Math' => array( 'detected' => defined( 'RANK_MATH_VERSION' ), 'integrated' => true ),
            'Yoast SEO' => array( 'detected' => defined( 'WPSEO_VERSION' ), 'integrated' => true ),
            'WPML' => array( 'detected' => defined( 'ICL_SITEPRESS_VERSION' ), 'integrated' => true ),
            'Polylang' => array( 'detected' => function_exists( 'pll_get_post_language' ), 'integrated' => true ),
            'ATUM Multi-Inventory' => array( 'detected' => defined( 'ATUM_VERSION' ), 'integrated' => true ),
            'WooCommerce Cost of Goods' => array( 'detected' => class_exists( 'WC_COG' ) || defined( 'WC_COG_VERSION' ), 'integrated' => true ),
            'PDF Invoices & Packing Slips' => array( 'detected' => function_exists( 'wcpdf_get_document' ), 'integrated' => true ),
            'Price by User Role' => array( 'detected' => function_exists( 'alg_get_user_roles' ), 'integrated' => true ),
            'Per Product Shipping' => array( 'detected' => $this->table_exists( 'innozilla_per_product_shipping_rules_woo' ), 'integrated' => true ),
            'Custom Product Tabs / WP All Import' => array( 'detected' => defined( 'YIKES_WC_CUSTOM_PRODUCT_TABS_VERSION' ) || class_exists( 'RapidAddon' ), 'integrated' => true ),
            'Elementor Pro' => array( 'detected' => defined( 'ELEMENTOR_PRO_VERSION' ), 'integrated' => true ),
            'Divi Shop Builder' => array( 'detected' => defined( 'ET_CORE_VERSION' ) || defined( 'DIVI_SHOP_BUILDER_VERSION' ), 'integrated' => true, 'note' => 'No browser-side stock workaround required.' ),
        );
    }

    private function table_exists( string $suffix ): bool {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
