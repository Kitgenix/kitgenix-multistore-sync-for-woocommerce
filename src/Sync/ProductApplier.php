<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Media\NetworkMedia;
use Kitgenix\MultiStore\Pricing\PriceCalculator;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class ProductApplier {
    public function __construct( private MappingRepository $mappings, private Settings $settings, private Logger $logger, private NetworkMedia $media ) {}

    public function apply( array $p, int $revision ): array|\WP_Error {
        if ( empty( $p['network_id'] ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_product_network_id', 'Product payload is missing a network ID.' );
        return Context::remote( function() use ( $p, $revision ) {
            $store_uuid = Uuid::site();
            if ( empty( $p['_pricing']['calculated_by'] ) ) {
                $rules = (array) ( $p['store_pricing_rules'] ?? array() ); $overrides = (array) ( $p['store_overrides'] ?? array() );
                $p = PriceCalculator::apply_for_store( $p, (array) ( $rules[ $store_uuid ] ?? array() ), (array) ( $overrides[ $store_uuid ] ?? array() ), $store_uuid );
                $p['_pricing']['calculated_by'] = 'receiver_legacy_fallback';
            }
            $network = sanitize_text_field( (string) $p['network_id'] );
            $id = $this->mappings->local_id( $network, 'product' );
            if ( ! $id && 'yes' === $this->settings->get( 'sku_assisted_linking', 'yes' ) && ! empty( $p['sku'] ) ) $id = (int) wc_get_product_id_by_sku( wc_clean( $p['sku'] ) );
            $product = $id ? wc_get_product( $id ) : false; $type = sanitize_key( (string) ( $p['type'] ?? 'simple' ) ); $is_new = ! $product;
            if ( ! $product ) { $product = $this->new_product( $type ); if ( is_wp_error( $product ) ) return $product; }
            elseif ( $product->get_type() !== $type && 'variation' !== $type ) { $product = $this->convert_product_type( $product, $type ); if ( is_wp_error( $product ) ) return $product; }
            $local_overrides = (array) get_post_meta( $product->get_id(), '_kitgenix_multistore_sync_for_woocommerce_override_fields', true );
            $allowed = fn( string $field, string $legacy = '' ): bool => StorePolicy::allows( $p, $field ) && ! in_array( $field, $local_overrides, true ) && ( ! $legacy || ! in_array( $legacy, $local_overrides, true ) );
            try {
                if ( $allowed( 'name' ) ) $product->set_name( wp_strip_all_tags( (string) ( $p['name'] ?? '' ) ) );
                if ( $allowed( 'slug' ) ) $product->set_slug( sanitize_title( (string) ( $p['slug'] ?? '' ) ) );
                if ( $allowed( 'status' ) ) { $status = sanitize_key( (string) ( $p['status'] ?? 'draft' ) ); if ( $is_new && 'yes' !== $this->settings->get( 'auto_publish_new_products', 'yes' ) && 'publish' === $status ) $status = 'draft'; $product->set_status( $status ); }
                if ( $allowed( 'featured' ) ) $product->set_featured( ! empty( $p['featured'] ) );
                if ( $allowed( 'catalog_visibility' ) ) $product->set_catalog_visibility( (string) ( $p['catalog_visibility'] ?? 'visible' ) );
                if ( $allowed( 'description' ) ) $product->set_description( wp_kses_post( (string) ( $p['description'] ?? '' ) ) );
                if ( $allowed( 'short_description' ) ) $product->set_short_description( wp_kses_post( (string) ( $p['short_description'] ?? '' ) ) );
                if ( $allowed( 'sku' ) ) { $sku = wc_clean( (string) ( $p['sku'] ?? '' ) ); if ( ! $sku || wc_product_has_unique_sku( $product->get_id(), $sku ) ) $product->set_sku( $sku ); }
                if ( method_exists( $product, 'set_global_unique_id' ) && $allowed( 'global_unique_id' ) ) $product->set_global_unique_id( wc_clean( (string) ( $p['global_unique_id'] ?? '' ) ) );
                if ( $allowed( 'price', 'price' ) ) { $product->set_regular_price( wc_format_decimal( $p['regular_price'] ?? '' ) ); $product->set_sale_price( wc_format_decimal( $p['sale_price'] ?? '' ) ); $product->set_date_on_sale_from( ! empty( $p['date_on_sale_from'] ) ? $p['date_on_sale_from'] : null ); $product->set_date_on_sale_to( ! empty( $p['date_on_sale_to'] ) ? $p['date_on_sale_to'] : null ); }
                if ( $allowed( 'tax', 'tax' ) ) { $product->set_tax_status( (string) ( $p['tax_status'] ?? 'taxable' ) ); $product->set_tax_class( (string) ( $p['tax_class'] ?? '' ) ); }
                if ( $allowed( 'inventory', 'inventory' ) ) { $product->set_manage_stock( ! empty( $p['manage_stock'] ) ); if ( ! empty( $p['manage_stock'] ) ) $product->set_stock_quantity( null === ( $p['stock_quantity'] ?? null ) ? null : wc_stock_amount( $p['stock_quantity'] ) ); $product->set_stock_status( (string) ( $p['stock_status'] ?? 'instock' ) ); }
                if ( $allowed( 'backorders', 'inventory' ) ) $product->set_backorders( (string) ( $p['backorders'] ?? 'no' ) );
                if ( $allowed( 'low_stock', 'inventory' ) ) $product->set_low_stock_amount( $p['low_stock_amount'] ?? '' );
                if ( $allowed( 'sold_individually', 'inventory' ) ) $product->set_sold_individually( ! empty( $p['sold_individually'] ) );
                if ( $allowed( 'shipping', 'shipping' ) ) $this->set_shipping( $product, $p );
                if ( $allowed( 'reviews_allowed', 'misc' ) ) $product->set_reviews_allowed( ! empty( $p['reviews_allowed'] ) );
                if ( $allowed( 'purchase_note', 'misc' ) ) $product->set_purchase_note( wp_kses_post( (string) ( $p['purchase_note'] ?? '' ) ) );
                if ( $allowed( 'downloads', 'downloads' ) ) { $product->set_virtual( ! empty( $p['virtual'] ) ); $product->set_downloadable( ! empty( $p['downloadable'] ) ); $product->set_download_limit( (int) ( $p['download_limit'] ?? -1 ) ); $product->set_download_expiry( (int) ( $p['download_expiry'] ?? -1 ) ); }
                $product->set_menu_order( (int) ( $p['menu_order'] ?? 0 ) );
                if ( $product instanceof \WC_Product_External && $allowed( 'integration_data', 'external' ) ) { $product->set_product_url( esc_url_raw( (string) ( $p['product_url'] ?? '' ) ) ); $product->set_button_text( wp_strip_all_tags( (string) ( $p['button_text'] ?? '' ) ) ); }
                $product->save();
                update_post_meta( $product->get_id(), '_kitgenix_multistore_sync_for_woocommerce_network_id', $network );
                $this->mappings->upsert( $network, 'product', Uuid::site(), $product->get_id(), (string) $product->get_sku(), $revision );

                if ( $allowed( 'categories', 'categories' ) ) $this->apply_terms( $product->get_id(), 'product_cat', (array) ( $p['categories'] ?? array() ) );
                if ( $allowed( 'tags', 'tags' ) ) $this->apply_terms( $product->get_id(), 'product_tag', (array) ( $p['tags'] ?? array() ) );
                if ( taxonomy_exists( 'product_brand' ) && $allowed( 'brands', 'brands' ) ) $this->apply_terms( $product->get_id(), 'product_brand', (array) ( $p['brands'] ?? array() ) );
                if ( $allowed( 'custom_taxonomies', 'custom_taxonomies' ) ) {
                    foreach ( (array) ( $p['custom_taxonomies'] ?? array() ) as $tax => $terms ) {
                        $tax = sanitize_key( (string) $tax );
                        if ( ! taxonomy_exists( $tax ) ) throw new \RuntimeException( sprintf( 'Custom taxonomy %s is not registered on the destination store.', $tax ) );
                        $this->apply_terms( $product->get_id(), $tax, (array) $terms );
                    }
                }
                if ( $allowed( 'attributes', 'attributes' ) ) $this->apply_attributes( $product, (array) ( $p['attributes'] ?? array() ) );
                if ( 'yes' === $this->settings->get( 'sync_images', 'yes' ) && ! empty( $p['images_included'] ) && $allowed( 'images', 'images' ) ) $this->apply_images( $product, (array) ( $p['images'] ?? array() ) );
                if ( $allowed( 'downloads', 'downloads' ) ) $this->apply_downloads( $product, (array) ( $p['downloads'] ?? array() ) );
                $this->apply_meta( $product->get_id(), $p, $allowed );
                if ( $allowed( 'linked_products', 'linked_products' ) ) $this->apply_links( $product, $p );
                if ( $product instanceof \WC_Product_Variable && $allowed( 'variations', 'variations' ) ) $this->apply_variations( $product, (array) ( $p['variations'] ?? array() ), $revision, $p, $local_overrides );
                if ( 'yes' === $this->settings->get( 'sync_reviews', 'no' ) && $allowed( 'reviews', 'reviews' ) ) $this->apply_reviews( $product->get_id(), (array) ( $p['reviews'] ?? array() ) );
                wc_delete_product_transients( $product->get_id() );
                do_action( 'kitgenix_multistore/product_applied', $product, $p, $revision );
                return array( 'local_id' => $product->get_id(), 'object_key' => $product->get_sku() );
            } catch ( \Throwable $e ) {
                $this->logger->error( 'Product apply failed.', array( 'network_id' => $network, 'error' => $e->getMessage() ) );
                return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_product_apply', $e->getMessage() );
            }
        } );
    }

    private function new_product( string $type ): \WC_Product|\WP_Error { return match ( $type ) { 'simple' => new \WC_Product_Simple(), 'variable' => new \WC_Product_Variable(), 'external' => new \WC_Product_External(), 'grouped' => new \WC_Product_Grouped(), default => new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_product_type', 'Unsupported product type: ' . $type ) }; }

    private function convert_product_type( \WC_Product $product, string $type ): \WC_Product|\WP_Error {
        if ( ! in_array( $type, array( 'simple','variable','external','grouped' ), true ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_product_type', 'Unsupported product type: ' . $type );
        $id = $product->get_id(); $old = $product->get_type();
        if ( $product instanceof \WC_Product_Variable && 'variable' !== $type ) foreach ( $product->get_children() as $child ) if ( get_post_meta( (int) $child, '_kitgenix_multistore_sync_for_woocommerce_network_id', true ) ) wp_trash_post( (int) $child );
        $set = wp_set_object_terms( $id, $type, 'product_type', false ); if ( is_wp_error( $set ) ) return $set;
        clean_post_cache( $id ); wc_delete_product_transients( $id );
        $converted = match ( $type ) { 'simple' => new \WC_Product_Simple( $id ), 'variable' => new \WC_Product_Variable( $id ), 'external' => new \WC_Product_External( $id ), 'grouped' => new \WC_Product_Grouped( $id ) };
        $this->logger->info( 'Product type converted during network sync.', array( 'product_id' => $id, 'from' => $old, 'to' => $type ) ); return $converted;
    }

    private function set_shipping( \WC_Product $product, array $p ): void {
        $product->set_weight( wc_format_decimal( $p['weight'] ?? '' ) ); $product->set_length( wc_format_decimal( $p['length'] ?? '' ) ); $product->set_width( wc_format_decimal( $p['width'] ?? '' ) ); $product->set_height( wc_format_decimal( $p['height'] ?? '' ) );
        $product->set_shipping_class_id( $this->shipping_class_id( (string) ( $p['shipping_class'] ?? '' ) ) );
    }

    private function shipping_class_id( string $slug ): int {
        $slug = sanitize_title( $slug ); if ( ! $slug ) return 0; $term = get_term_by( 'slug', $slug, 'product_shipping_class' );
        if ( ! $term ) { $made = wp_insert_term( $slug, 'product_shipping_class', array( 'slug' => $slug ) ); if ( ! is_wp_error( $made ) ) $term = get_term( (int) $made['term_id'], 'product_shipping_class' ); }
        return $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
    }

    private function apply_terms( int $id, string $tax, array $items ): void {
        $ids = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue; $parent = 0;
            foreach ( (array) ( $item['ancestors'] ?? array() ) as $a ) { $parent = $this->upsert_term( $tax, (array) $a, $parent ); }
            $term_id = $this->upsert_term( $tax, $item, $parent ); if ( $term_id ) $ids[] = $term_id;
        }
        wp_set_object_terms( $id, $ids, $tax, false );
    }

    private function upsert_term( string $tax, array $item, int $parent ): int {
        $slug = sanitize_title( (string) ( $item['slug'] ?? $item['name'] ?? '' ) ); if ( ! $slug ) return $parent;
        $term = get_term_by( 'slug', $slug, $tax );
        $args = array( 'slug' => $slug, 'parent' => $parent, 'description' => wp_kses_post( (string) ( $item['description'] ?? '' ) ) );
        if ( ! $term ) { $made = wp_insert_term( sanitize_text_field( (string) ( $item['name'] ?? $slug ) ), $tax, $args ); if ( ! is_wp_error( $made ) ) $term = get_term( (int) $made['term_id'], $tax ); }
        elseif ( (int) $term->parent !== $parent || $term->description !== (string) ( $item['description'] ?? '' ) || $term->name !== (string) ( $item['name'] ?? $term->name ) ) {
            wp_update_term( $term->term_id, $tax, array_merge( $args, array( 'name' => sanitize_text_field( (string) ( $item['name'] ?? $term->name ) ) ) ) );
            $term = get_term( $term->term_id, $tax );
        }
        if ( ! $term || is_wp_error( $term ) ) return $parent;
        foreach ( (array) ( $item['meta'] ?? array() ) as $key => $value ) {
            $key = sanitize_key( (string) $key ); if ( ! $key ) continue;
            if ( 'thumbnail' === $key ) { $attachment = $this->media->import( is_array( $value ) ? $value : (string) $value, 0 ); if ( $attachment ) update_term_meta( $term->term_id, 'thumbnail_id', $attachment ); continue; }
            update_term_meta( $term->term_id, $key, $value );
        }
        return (int) $term->term_id;
    }

    private function ids_by_sku( array $skus ): array { $ids = array(); foreach ( $skus as $sku ) { $id = (int) wc_get_product_id_by_sku( wc_clean( $sku ) ); if ( $id ) $ids[] = $id; } return $ids; }

    private function apply_attributes( \WC_Product $product, array $items ): void {
        $attrs = array();
        foreach ( $items as $item ) {
            $name = wc_clean( (string) ( $item['name'] ?? '' ) ); if ( ! $name ) continue;
            $wants_taxonomy = ! empty( $item['taxonomy'] );
            if ( $wants_taxonomy && ! taxonomy_exists( $name ) ) {
                $attribute_id = (int) wc_attribute_taxonomy_id_by_name( $name );
                if ( ! $attribute_id ) {
                    $slug = preg_replace( '/^pa_/', '', sanitize_title( $name ) );
                    $created = wc_create_attribute( array(
                        'name' => sanitize_text_field( (string) ( $item['label'] ?? ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) ) ),
                        'slug' => $slug,
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    ) );
                    if ( is_wp_error( $created ) ) throw new \RuntimeException( 'Could not create global WooCommerce attribute: ' . $created->get_error_message() );
                    delete_transient( 'wc_attribute_taxonomies' );
                }
                // WooCommerce registers newly-created attribute taxonomies on the next request.
                throw new \RuntimeException( sprintf( 'Global WooCommerce attribute %s was created; this delivery will retry after the taxonomy is registered.', $name ) );
            }
            $a = new \WC_Product_Attribute(); $taxonomy = $wants_taxonomy && taxonomy_exists( $name );
            $a->set_id( $taxonomy ? wc_attribute_taxonomy_id_by_name( $name ) : 0 ); $a->set_name( $name ); $opts = array();
            if ( $taxonomy ) foreach ( (array) ( $item['options'] ?? array() ) as $o ) { $tid = $this->upsert_term( $name, is_array( $o ) ? $o : array( 'name' => $o ), 0 ); if ( $tid ) $opts[] = $tid; }
            else foreach ( (array) ( $item['options'] ?? array() ) as $o ) $opts[] = sanitize_text_field( is_array( $o ) ? (string) ( $o['name'] ?? '' ) : (string) $o );
            $a->set_options( $opts ); $a->set_position( (int) ( $item['position'] ?? 0 ) ); $a->set_visible( ! empty( $item['visible'] ) ); $a->set_variation( ! empty( $item['variation'] ) ); $attrs[ sanitize_title( $name ) ] = $a;
        }
        $product->set_attributes( $attrs ); $product->save();
    }

    private function apply_images( \WC_Product $product, array $images ): void {
        $product->set_image_id( $this->media->import( $images['featured'] ?? '', $product->get_id() ) ); $gallery = array();
        foreach ( array_slice( (array) ( $images['gallery'] ?? array() ), 0, 50 ) as $asset ) { $id = $this->media->import( $asset, $product->get_id() ); if ( $id ) $gallery[] = $id; }
        $product->set_gallery_image_ids( $gallery ); $product->save();
    }

    private function apply_downloads( \WC_Product $product, array $downloads ): void {
        $out = array(); foreach ( $downloads as $d ) { if ( ! is_array( $d ) ) continue; $file = new \WC_Product_Download(); $file->set_id( sanitize_text_field( (string) ( $d['id'] ?? md5( (string) ( $d['file'] ?? '' ) ) ) ) ); $file->set_name( sanitize_text_field( (string) ( $d['name'] ?? '' ) ) ); $file->set_file( wc_clean( (string) ( $d['file'] ?? '' ) ) ); $out[ $file->get_id() ] = $file; }
        $product->set_downloads( $out ); $product->save();
    }

    private function apply_meta( int $product_id, array $p, callable $allowed ): void {
        $meta = (array) ( $p['meta'] ?? array() );
        foreach ( (array) ( $p['managed_meta_keys'] ?? array_keys( $meta ) ) as $key ) {
            $key = sanitize_key( (string) $key ); if ( ! $key ) continue;
            $group = ( str_starts_with( $key, '_rank_math_' ) || str_starts_with( $key, 'rank_math_' ) || str_starts_with( $key, '_yoast_' ) ) ? 'seo' : ( $this->integration_meta( $key ) ? 'integration_data' : 'custom_meta' );
            if ( ! $allowed( $group, 'seo' === $group ? 'seo' : $group ) ) continue;
            if ( array_key_exists( $key, $meta ) ) update_post_meta( $product_id, $key, wp_slash( $meta[ $key ] ) ); else delete_post_meta( $product_id, $key );
        }
    }

    private function integration_meta( string $key ): bool {
        foreach ( array( '_multi_inventory','_inventory_sorting_mode','_inventory_iteration','_expirable_inventories','_price_per_inventory','_wc_cog_cost','_per_product_shipping','_per_product_shipping_add_to_all','yikes_woo_products_tabs','_elementor_','_wp_page_template','_alg_wc_price_by_user_role_','_wpm_gtin_','wooct_' ) as $prefix ) if ( str_starts_with( $key, $prefix ) ) return true;
        return false;
    }

    private function apply_links( \WC_Product $product, array $p ): void {
        $product->set_upsell_ids( $this->ids_by_sku( (array) ( $p['upsell_skus'] ?? array() ) ) ); $product->set_cross_sell_ids( $this->ids_by_sku( (array) ( $p['cross_sell_skus'] ?? array() ) ) );
        if ( $product instanceof \WC_Product_Grouped ) { $children = array(); foreach ( (array) ( $p['children_skus'] ?? array() ) as $sku ) { $cid = (int) wc_get_product_id_by_sku( wc_clean( $sku ) ); if ( $cid ) $children[] = $cid; } $product->set_children( $children ); }
        $product->save();
    }

    private function apply_variations( \WC_Product_Variable $parent, array $items, int $revision, array $parent_payload, array $local_overrides ): void {
        $incoming = array();
        foreach ( $items as $v ) {
            if ( ! is_array( $v ) ) continue; $network = sanitize_text_field( (string) ( $v['network_id'] ?? '' ) ); if ( ! $network ) continue; $incoming[] = $network;
            $id = $this->mappings->local_id( $network, 'variation' ); if ( ! $id && 'yes' === $this->settings->get( 'sku_assisted_linking', 'yes' ) && ! empty( $v['sku'] ) ) $id = (int) wc_get_product_id_by_sku( wc_clean( $v['sku'] ) );
            $variation = $id ? wc_get_product( $id ) : false; if ( ! $variation instanceof \WC_Product_Variation ) { $variation = new \WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); }
            if ( StorePolicy::allows( $parent_payload, 'status' ) && ! in_array( 'status', $local_overrides, true ) ) $variation->set_status( sanitize_key( (string) ( $v['status'] ?? 'publish' ) ) );
            if ( StorePolicy::allows( $parent_payload, 'sku' ) && ! in_array( 'sku', $local_overrides, true ) ) { $sku = wc_clean( (string) ( $v['sku'] ?? '' ) ); if ( ! $sku || wc_product_has_unique_sku( $variation->get_id(), $sku ) ) $variation->set_sku( $sku ); }
            if ( method_exists( $variation, 'set_global_unique_id' ) && StorePolicy::allows( $parent_payload, 'global_unique_id' ) && ! in_array( 'global_unique_id', $local_overrides, true ) ) $variation->set_global_unique_id( wc_clean( (string) ( $v['global_unique_id'] ?? '' ) ) );
            if ( StorePolicy::allows( $parent_payload, 'price' ) && ! in_array( 'price', $local_overrides, true ) ) { $variation->set_regular_price( wc_format_decimal( $v['regular_price'] ?? '' ) ); $variation->set_sale_price( wc_format_decimal( $v['sale_price'] ?? '' ) ); $variation->set_date_on_sale_from( ! empty( $v['date_on_sale_from'] ) ? $v['date_on_sale_from'] : null ); $variation->set_date_on_sale_to( ! empty( $v['date_on_sale_to'] ) ? $v['date_on_sale_to'] : null ); }
            if ( StorePolicy::allows( $parent_payload, 'tax' ) ) $variation->set_tax_class( (string) ( $v['tax_class'] ?? '' ) );
            if ( StorePolicy::allows( $parent_payload, 'inventory' ) && ! in_array( 'inventory', $local_overrides, true ) ) { $variation->set_manage_stock( ! empty( $v['manage_stock'] ) ); if ( ! empty( $v['manage_stock'] ) ) $variation->set_stock_quantity( null === ( $v['stock_quantity'] ?? null ) ? null : wc_stock_amount( $v['stock_quantity'] ) ); $variation->set_stock_status( (string) ( $v['stock_status'] ?? 'instock' ) ); }
            if ( StorePolicy::allows( $parent_payload, 'backorders' ) && ! in_array( 'backorders', $local_overrides, true ) && ! in_array( 'inventory', $local_overrides, true ) ) $variation->set_backorders( (string) ( $v['backorders'] ?? 'no' ) );
            if ( StorePolicy::allows( $parent_payload, 'low_stock' ) && ! in_array( 'low_stock', $local_overrides, true ) && ! in_array( 'inventory', $local_overrides, true ) ) $variation->set_low_stock_amount( $v['low_stock_amount'] ?? '' );
            if ( StorePolicy::allows( $parent_payload, 'shipping' ) && ! in_array( 'shipping', $local_overrides, true ) ) { $variation->set_weight( wc_format_decimal( $v['weight'] ?? '' ) ); $variation->set_length( wc_format_decimal( $v['length'] ?? '' ) ); $variation->set_width( wc_format_decimal( $v['width'] ?? '' ) ); $variation->set_height( wc_format_decimal( $v['height'] ?? '' ) ); $variation->set_shipping_class_id( $this->shipping_class_id( (string) ( $v['shipping_class'] ?? '' ) ) ); }
            if ( StorePolicy::allows( $parent_payload, 'downloads' ) ) { $variation->set_virtual( ! empty( $v['virtual'] ) ); $variation->set_downloadable( ! empty( $v['downloadable'] ) ); $variation->set_download_limit( (int) ( $v['download_limit'] ?? -1 ) ); $variation->set_download_expiry( (int) ( $v['download_expiry'] ?? -1 ) ); $this->apply_downloads( $variation, (array) ( $v['downloads'] ?? array() ) ); }
            if ( StorePolicy::allows( $parent_payload, 'attributes' ) && ! in_array( 'attributes', $local_overrides, true ) ) $variation->set_attributes( array_map( 'wc_clean', (array) ( $v['attributes'] ?? array() ) ) );
            if ( StorePolicy::allows( $parent_payload, 'images' ) && array_key_exists( 'image', $v ) ) $variation->set_image_id( $this->media->import( $v['image'], $parent->get_id() ) );
            $variation->save(); update_post_meta( $variation->get_id(), '_kitgenix_multistore_sync_for_woocommerce_network_id', $network ); $this->mappings->upsert( $network, 'variation', Uuid::site(), $variation->get_id(), (string) $variation->get_sku(), $revision );
            if ( ! empty( $v['meta'] ) ) { $vp = $parent_payload; $vp['meta'] = (array) $v['meta']; $vp['managed_meta_keys'] = array_keys( $v['meta'] ); $this->apply_meta( $variation->get_id(), $vp, fn( $field ) => StorePolicy::allows( $parent_payload, $field ) ); }
        }
        foreach ( $parent->get_children() as $child_id ) { $nid = (string) get_post_meta( $child_id, '_kitgenix_multistore_sync_for_woocommerce_network_id', true ); if ( $nid && ! in_array( $nid, $incoming, true ) ) wp_trash_post( $child_id ); }
        \WC_Product_Variable::sync( $parent->get_id() );
    }

    private function apply_reviews( int $product_id, array $reviews ): void {
        $incoming = array();
        foreach ( array_slice( $reviews, 0, 500 ) as $review ) {
            if ( ! is_array( $review ) ) continue; $uuid = sanitize_text_field( (string) ( $review['network_id'] ?? '' ) ); if ( ! wp_is_uuid( $uuid ) ) continue; $incoming[] = $uuid;
            $comments = get_comments( array( 'post_id' => $product_id, 'meta_key' => '_kitgenix_multistore_sync_for_woocommerce_review_network_id', 'meta_value' => $uuid, 'number' => 1, 'status' => 'all' ) );
            $data = array( 'comment_post_ID' => $product_id, 'comment_author' => sanitize_text_field( (string) ( $review['author'] ?? 'Customer' ) ), 'comment_content' => wp_kses_post( (string) ( $review['content'] ?? '' ) ), 'comment_type' => 'review', 'comment_approved' => sanitize_text_field( (string) ( $review['approved'] ?? '1' ) ), 'comment_date_gmt' => sanitize_text_field( (string) ( $review['date_gmt'] ?? current_time( 'mysql', true ) ) ), 'comment_date' => get_date_from_gmt( sanitize_text_field( (string) ( $review['date_gmt'] ?? current_time( 'mysql', true ) ) ) ) );
            if ( $comments ) { $data['comment_ID'] = $comments[0]->comment_ID; wp_update_comment( wp_slash( $data ) ); $cid = (int) $comments[0]->comment_ID; }
            else { $cid = (int) wp_insert_comment( wp_slash( $data ) ); update_comment_meta( $cid, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', $uuid ); }
            if ( $cid ) { update_comment_meta( $cid, 'rating', max( 0, min( 5, (int) ( $review['rating'] ?? 0 ) ) ) ); update_comment_meta( $cid, 'verified', ! empty( $review['verified'] ) ? 1 : 0 ); }
        }
        $network_comments = get_comments( array( 'post_id' => $product_id, 'meta_key' => '_kitgenix_multistore_sync_for_woocommerce_review_network_id', 'number' => 0, 'status' => 'all' ) );
        foreach ( $network_comments as $network_comment ) {
            $uuid = (string) get_comment_meta( $network_comment->comment_ID, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', true );
            if ( wp_is_uuid( $uuid ) && ! in_array( $uuid, $incoming, true ) ) wp_delete_comment( $network_comment->comment_ID, true );
        }
        wc_update_product_rating_counts( $product_id ); wc_update_product_review_count( $product_id ); wc_update_product_average_rating( $product_id );
    }
}
