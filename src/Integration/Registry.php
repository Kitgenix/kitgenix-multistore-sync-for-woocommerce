<?php
namespace Kitgenix\MultiStore\Integration;

use Kitgenix\MultiStore\Support\Environment;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Sync\StorePolicy;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
// -- Every query here targets a fixed, hardcoded third-party (Per Product Shipping)
// table name, never user input; this table belongs to another plugin and has no
// WordPress core object-cache group, so a direct $wpdb call is the only way to
// interact with it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// -- wpml_post_language_details / wpml_element_type / wpml_set_element_language_details
// are WPML's own documented integration hooks, not hooks this plugin defines.
final class Registry {
    private const TABS_META_KEY = 'kitgenix_custom_tabs_for_woocommerce_tabs';

    public function __construct( private Logger $logger, private Settings $settings ) {}

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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, hardcoded third-party (Per Product Shipping) table name, not user input.
            $integration['per_product_shipping_rules'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id=%d", $product->get_id() ), ARRAY_A ) ?: array();
        }
        $data['integration_data'] = $integration;

        // Kitgenix Custom Tabs / Kitgenix Document Manager bridge. Gated by a global
        // setting (this parses tab HTML on every product, so it stays free for sites
        // not using either sibling plugin) - per-store delivery is then controlled by
        // the 'custom_tabs' / 'custom_tab_documents' StorePolicy fields, exactly like
        // every other synced field.
        if ( 'yes' === $this->settings->get( 'sync_custom_tabs', 'no' ) && defined( 'KITGENIX_CUSTOM_TABS_FOR_WOOCOMMERCE_VERSION' ) ) {
            $tabs = get_post_meta( $product->get_id(), self::TABS_META_KEY, true );
            $tabs = is_array( $tabs ) ? array_values( $tabs ) : array();
            // Always present (even empty) once the feature is on, so a tab removed at
            // the source is reflected as removed at the destination too - the same
            // authoritative-full-state convention used by categories/attributes/etc.
            $data['kitgenix_custom_tabs'] = $tabs;
            if ( $tabs && defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ) {
                $documents = $this->documents_referenced_by_tabs( $tabs );
                if ( $documents ) $data['kitgenix_custom_tab_documents'] = $documents;
            }
        }

        return $data;
    }

    /**
     * Documents are standalone Kitgenix Document Manager entities referenced by
     * slug from a [kitgenix_document_manager_document]/[kitgenix_document_manager_link]
     * shortcode inside tab content - not something this plugin's core product model
     * owns. Only documents actually embedded in a tab being synced are collected
     * ("certain ones"), never the whole document library.
     *
     * @param array<int,array<string,mixed>> $tabs
     * @return array<int,array<string,mixed>>
     */
    private function documents_referenced_by_tabs( array $tabs ): array {
        $slugs = array();
        foreach ( $tabs as $tab ) {
            if ( ! is_array( $tab ) ) continue;
            $content = (string) ( $tab['content'] ?? '' );
            if ( '' === $content ) continue;
            if ( preg_match_all( '/\[kitgenix_document_manager_(?:document|link)\b([^\]]*)\]/i', $content, $matches ) ) {
                foreach ( $matches[1] as $attrs_raw ) {
                    $atts = shortcode_parse_atts( $attrs_raw );
                    if ( ! is_array( $atts ) ) continue;
                    if ( ! empty( $atts['slug'] ) ) $slugs[ sanitize_title_with_dashes( (string) $atts['slug'] ) ] = true;
                    if ( ! empty( $atts['slugs'] ) ) {
                        foreach ( preg_split( '/\s*,\s*/', trim( (string) $atts['slugs'] ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $s ) {
                            $slugs[ sanitize_title_with_dashes( (string) $s ) ] = true;
                        }
                    }
                }
            }
        }
        $slugs = array_keys( array_filter( $slugs, static fn( $slug ) => '' !== $slug, ARRAY_FILTER_USE_KEY ) );
        if ( ! $slugs ) return array();

        $out = array();
        foreach ( $slugs as $slug ) {
            $post = get_page_by_path( $slug, OBJECT, \KitgenixDocumentManager\Core\CPT::POST_TYPE );
            if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) continue;
            $attachment_id = (int) get_post_meta( $post->ID, '_kitgenix_document_manager_current_attachment_id', true );
            if ( $attachment_id <= 0 ) continue;
            $url = wp_get_attachment_url( $attachment_id );
            if ( ! $url ) continue;
            $path = get_attached_file( $attachment_id );
            $filename = $path ? wp_basename( (string) $path ) : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
            $terms = wp_get_post_terms( $post->ID, \KitgenixDocumentManager\Core\CPT::TAXONOMY_CATEGORY );
            $categories = array();
            if ( ! is_wp_error( $terms ) ) foreach ( $terms as $t ) $categories[] = array( 'name' => $t->name, 'slug' => $t->slug );
            $out[] = array(
                'slug' => $slug,
                'title' => get_the_title( $post ),
                'description' => (string) get_post_meta( $post->ID, '_kitgenix_document_manager_description', true ),
                'categories' => $categories,
                'file' => array( 'url' => $url, 'filename' => sanitize_file_name( $filename ), 'mime_type' => (string) get_post_mime_type( $attachment_id ) ),
            );
        }
        return $out;
    }

    public function product_applied( \WC_Product $product, array $data ): void {
        $integration = (array) ( $data['integration_data'] ?? array() );
        $allow_language = StorePolicy::allows( $data, 'language' );
        $allow_integration = StorePolicy::allows( $data, 'integration_data' );

        // Each sibling-plugin bridge below is isolated in its own try/catch. These
        // integrations run against other plugins that are updated independently,
        // possibly on a different schedule per site across the network - a stale
        // sibling plugin on one destination must never fail (and endlessly retry)
        // the whole product apply; it should just skip that one integration.
        try {
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
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Polylang/WPML integration failed while applying product.', array( 'product_id' => $product->get_id(), 'error' => $e->getMessage() ) );
        }

        try {
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
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Per Product Shipping integration failed while applying product.', array( 'product_id' => $product->get_id(), 'error' => $e->getMessage() ) );
        }

        try {
            if ( defined( 'KITGENIX_CUSTOM_TABS_FOR_WOOCOMMERCE_VERSION' ) && StorePolicy::allows( $data, 'custom_tabs' ) && array_key_exists( 'kitgenix_custom_tabs', $data ) ) {
                $this->apply_custom_tabs( $product->get_id(), (array) $data['kitgenix_custom_tabs'] );
            }
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Custom Tabs integration failed while applying product.', array( 'product_id' => $product->get_id(), 'error' => $e->getMessage() ) );
        }

        try {
            if ( defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) && StorePolicy::allows( $data, 'custom_tab_documents' ) && ! empty( $data['kitgenix_custom_tab_documents'] ) ) {
                $this->apply_custom_tab_documents( (array) $data['kitgenix_custom_tab_documents'] );
            }
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Document Manager integration failed while applying product.', array( 'product_id' => $product->get_id(), 'error' => $e->getMessage() ) );
        }
    }

    /**
     * Runs incoming tab rows through Kitgenix Custom Tabs' own sanitizer
     * (Settings::sanitize_tabs_rows) - the same routine its own save handler
     * uses - so this can never write anything that bypasses its HTML sanitisation
     * (wp_kses_post) or its own configured max-tabs limit. The stored value is run
     * through the same sanitizer before comparing, so a delivery that would produce
     * an identical stored value is a true no-op (no update_post_meta call).
     */
    private function apply_custom_tabs( int $product_id, array $incoming ): void {
        if ( ! class_exists( '\KitgenixCustomTabsForWooCommerce\Core\Settings' ) ) return;
        // is_callable() (unlike class_exists()) also checks method visibility from
        // this call site, so an older Custom Tabs build where sanitize_tabs_rows()
        // was still private is skipped with a clear log instead of a fatal Error.
        if ( ! is_callable( array( '\KitgenixCustomTabsForWooCommerce\Core\Settings', 'sanitize_tabs_rows' ) )
            || ! is_callable( array( '\KitgenixCustomTabsForWooCommerce\Core\Settings', 'max_tabs' ) ) ) {
            $this->logger->warning( 'Skipped syncing custom tabs: the active Kitgenix Custom Tabs for WooCommerce version on this site is incompatible.', array( 'product_id' => $product_id ) );
            return;
        }
        $max = \KitgenixCustomTabsForWooCommerce\Core\Settings::max_tabs();
        $sanitized = \KitgenixCustomTabsForWooCommerce\Core\Settings::sanitize_tabs_rows( $incoming, $max, 'product' );
        $current = get_post_meta( $product_id, self::TABS_META_KEY, true );
        $current = \KitgenixCustomTabsForWooCommerce\Core\Settings::sanitize_tabs_rows( is_array( $current ) ? $current : array(), $max, 'product' );
        if ( $current === $sanitized ) return;
        if ( $sanitized ) update_post_meta( $product_id, self::TABS_META_KEY, $sanitized );
        else delete_post_meta( $product_id, self::TABS_META_KEY );
        $this->logger->info( 'Custom tabs synced from network product.', array( 'product_id' => $product_id, 'tab_count' => count( $sanitized ) ) );
    }

    /**
     * Upserts each referenced Document Manager document by slug. Additive only:
     * a document is created/updated here, never trashed - it may be shared by
     * several products/tabs, and this integration only ever adds a document it
     * has current data for, never removes one. Access-control, visibility,
     * scheduling and analytics are deliberately never touched - those are the
     * destination site's own security policy and usage data, not portable content.
     *
     * @param array<int,array<string,mixed>> $documents
     */
    private function apply_custom_tab_documents( array $documents ): void {
        if ( ! class_exists( '\KitgenixDocumentManager\Core\CPT' ) ) return;
        $post_type = \KitgenixDocumentManager\Core\CPT::POST_TYPE;
        $allowed_mimes = function_exists( '\KitgenixDocumentManager\Core\kitgenix_document_manager_allowed_mimes' ) ? \KitgenixDocumentManager\Core\kitgenix_document_manager_allowed_mimes() : array();
        if ( ! $allowed_mimes ) {
            // An empty override array makes wp_handle_sideload() fall back to WordPress'
            // site-wide default mime allow-list rather than "allow nothing" - which could
            // be broader than what this site's Document Manager settings actually permit.
            // Treat "no allowed types configured" as "refuse to sideload" instead.
            $this->logger->warning( 'Skipped syncing document files: no allowed file types are configured in Document Manager settings.', array() );
            return;
        }

        foreach ( $documents as $doc ) {
            if ( ! is_array( $doc ) ) continue;
            $slug = sanitize_title_with_dashes( (string) ( $doc['slug'] ?? '' ) );
            if ( ! $slug ) continue;
            $title = sanitize_text_field( (string) ( $doc['title'] ?? $slug ) );
            $description = sanitize_textarea_field( (string) ( $doc['description'] ?? '' ) );

            $post = get_page_by_path( $slug, OBJECT, $post_type );
            $document_id = $post instanceof \WP_Post ? (int) $post->ID : 0;

            if ( ! $document_id ) {
                $document_id = wp_insert_post( array(
                    'post_type' => $post_type, 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish',
                ), true );
                if ( is_wp_error( $document_id ) ) {
                    $this->logger->warning( 'Could not create synced document.', array( 'slug' => $slug, 'error' => $document_id->get_error_message() ) );
                    continue;
                }
                $this->logger->info( 'Document created from network product tab.', array( 'slug' => $slug, 'document_id' => $document_id ) );
            } elseif ( $post->post_title !== $title ) {
                wp_update_post( array( 'ID' => $document_id, 'post_title' => $title ) );
            }

            if ( $description !== (string) get_post_meta( $document_id, '_kitgenix_document_manager_description', true ) ) {
                update_post_meta( $document_id, '_kitgenix_document_manager_description', $description );
            }

            $this->apply_document_categories( $document_id, (array) ( $doc['categories'] ?? array() ) );
            $this->apply_document_file( $document_id, (array) ( $doc['file'] ?? array() ), $allowed_mimes );
        }
    }

    private function apply_document_categories( int $document_id, array $categories ): void {
        $tax = \KitgenixDocumentManager\Core\CPT::TAXONOMY_CATEGORY;
        $ids = array();
        foreach ( $categories as $cat ) {
            if ( ! is_array( $cat ) ) continue;
            $slug = sanitize_title( (string) ( $cat['slug'] ?? $cat['name'] ?? '' ) ); if ( ! $slug ) continue;
            $term = get_term_by( 'slug', $slug, $tax );
            if ( ! $term ) {
                $made = wp_insert_term( sanitize_text_field( (string) ( $cat['name'] ?? $slug ) ), $tax, array( 'slug' => $slug ) );
                if ( is_wp_error( $made ) ) continue;
                $ids[] = (int) $made['term_id'];
            } else {
                $ids[] = (int) $term->term_id;
            }
        }
        $current_ids = array_map( 'intval', (array) wp_get_object_terms( $document_id, $tax, array( 'fields' => 'ids' ) ) );
        $sorted_ids = $ids; sort( $sorted_ids ); $sorted_current = $current_ids; sort( $sorted_current );
        if ( $sorted_ids !== $sorted_current ) wp_set_object_terms( $document_id, $ids, $tax, false );
    }

    /**
     * Only sideloads the file when its source URL differs from what is already
     * attached (tracked via the same '_kitgenix_multistore_sync_for_woocommerce_source_url'
     * meta NetworkMedia uses for product images) - a repeat delivery of the same
     * document is a no-op here too, not a re-download.
     *
     * @param array<string,mixed> $file
     * @param array<string,string> $allowed_mimes
     */
    private function apply_document_file( int $document_id, array $file, array $allowed_mimes ): void {
        $url = esc_url_raw( (string) ( $file['url'] ?? '' ) );
        if ( '' === $url ) return;

        $current_attachment_id = \KitgenixDocumentManager\Core\Uploader::get_current_attachment_id( $document_id );
        $current_source = $current_attachment_id ? (string) get_post_meta( $current_attachment_id, '_kitgenix_multistore_sync_for_woocommerce_source_url', true ) : '';
        if ( $current_source === $url ) return;

        $new_attachment_id = $this->sideload_document_file( $url, $document_id, $allowed_mimes, sanitize_file_name( (string) ( $file['filename'] ?? '' ) ) );
        if ( is_wp_error( $new_attachment_id ) ) {
            $this->logger->warning( 'Could not sync document file.', array( 'document_id' => $document_id, 'error' => $new_attachment_id->get_error_message() ) );
            return;
        }

        $keep_history = function_exists( '\KitgenixDocumentManager\Core\kitgenix_document_manager_is_versioning_enabled' ) && \KitgenixDocumentManager\Core\kitgenix_document_manager_is_versioning_enabled( $document_id );
        \KitgenixDocumentManager\Core\Uploader::set_current_attachment( $document_id, $new_attachment_id );
        if ( $keep_history && function_exists( '\KitgenixDocumentManager\Core\kitgenix_document_manager_get_versions' ) && function_exists( '\KitgenixDocumentManager\Core\kitgenix_document_manager_set_versions' ) ) {
            $versions = \KitgenixDocumentManager\Core\kitgenix_document_manager_get_versions( $document_id );
            if ( $current_attachment_id > 0 && ! in_array( $current_attachment_id, $versions, true ) ) $versions[] = $current_attachment_id;
            if ( ! in_array( $new_attachment_id, $versions, true ) ) $versions[] = $new_attachment_id;
            \KitgenixDocumentManager\Core\kitgenix_document_manager_set_versions( $document_id, $versions );
        } elseif ( $current_attachment_id > 0 && $current_attachment_id !== $new_attachment_id ) {
            wp_delete_attachment( $current_attachment_id, true );
        }

        $this->logger->info( 'Document file synced from network product tab.', array( 'document_id' => $document_id, 'attachment_id' => $new_attachment_id ) );
    }

    /**
     * Sideloads a document file from a paired store. Security posture mirrors
     * every other outbound/inbound HTTP path in this plugin:
     *  - the URL is SSRF-checked via Environment::validate_store_url() (same guard
     *    used for store pairing and product image sync);
     *  - the file type is validated against the DESTINATION site's own configured
     *    allowed extensions (kitgenix_document_manager_allowed_mimes()), not the
     *    source's, so a compromised peer cannot push a type the destination itself
     *    would refuse;
     *  - the file lands in Document Manager's own protected upload subdirectory
     *    (its .htaccess/web.config/index.php hardening applies identically to a
     *    manually-uploaded document);
     *  - this path only runs for an event already authenticated as a paired store
     *    (see EventProcessor::authorize_event()), never for arbitrary input.
     *
     * @param array<string,string> $allowed_mimes
     */
    private function sideload_document_file( string $url, int $document_id, array $allowed_mimes, string $suggested_filename ): int|\WP_Error {
        if ( '' === $url || ! Environment::validate_store_url( $url ) ) {
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_document_url', 'Invalid document file URL.' );
        }
        if ( ! function_exists( '\KitgenixDocumentManager\Core\kitgenix_document_manager_ensure_upload_dir' ) || ! \KitgenixDocumentManager\Core\kitgenix_document_manager_ensure_upload_dir() ) {
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_document_dir', 'Document upload directory is not available.' );
        }
        if ( ! function_exists( 'download_url' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = Environment::with_local_http_access( $url, static fn() => download_url( $url, 60 ) );
        if ( is_wp_error( $tmp ) ) return $tmp;

        $filename = '' !== $suggested_filename ? $suggested_filename : sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        if ( '' === $filename ) $filename = 'document';

        $dir_filter = static function ( array $dirs ): array {
            $subdir = '/' . \KitgenixDocumentManager\Core\kitgenix_document_manager_upload_subdir();
            $dirs['subdir'] = $subdir; $dirs['path'] = rtrim( (string) $dirs['basedir'], '/' ) . $subdir; $dirs['url'] = rtrim( (string) $dirs['baseurl'], '/' ) . $subdir;
            return $dirs;
        };
        add_filter( 'upload_dir', $dir_filter );
        try {
            $sideloaded = wp_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp ), array( 'test_form' => false, 'mimes' => $allowed_mimes ) );
        } finally {
            remove_filter( 'upload_dir', $dir_filter );
        }

        if ( ! is_array( $sideloaded ) || ! empty( $sideloaded['error'] ) ) {
            if ( file_exists( $tmp ) ) wp_delete_file( $tmp );
            return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_document_sideload', is_array( $sideloaded ) ? (string) $sideloaded['error'] : 'Document file could not be processed.' );
        }

        $attach_id = (int) wp_insert_attachment( array(
            'post_mime_type' => (string) ( $sideloaded['type'] ?? '' ),
            'post_title' => sanitize_text_field( wp_basename( (string) $sideloaded['file'] ) ),
            'post_content' => '', 'post_status' => 'inherit', 'guid' => (string) $sideloaded['url'],
        ), (string) $sideloaded['file'], $document_id );
        if ( $attach_id <= 0 ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_document_attach', 'Document attachment could not be created.' );

        $meta = wp_generate_attachment_metadata( $attach_id, (string) $sideloaded['file'] );
        if ( is_array( $meta ) ) wp_update_attachment_metadata( $attach_id, $meta );
        update_post_meta( $attach_id, '_kitgenix_multistore_sync_for_woocommerce_source_url', $url );

        return $attach_id;
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only bearer-token access check (verified against a stored transient below), not a nonce-appropriate action; the link may be opened from a different session than the one that generated it.
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
            'Kitgenix Custom Tabs' => array( 'detected' => defined( 'KITGENIX_CUSTOM_TABS_FOR_WOOCOMMERCE_VERSION' ), 'integrated' => true, 'note' => 'Enable "Sync Custom Tabs" in Settings, then allow the Custom tabs field per store.' ),
            'Kitgenix Document Manager' => array( 'detected' => defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ), 'integrated' => true, 'note' => 'Documents referenced by slug inside a synced custom tab are synced when the Custom tab documents field is allowed per store.' ),
        );
    }

    private function table_exists( string $suffix ): bool {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
