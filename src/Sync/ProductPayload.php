<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Settings;

final class ProductPayload {
    public function __construct( private MappingRepository $mappings, private Settings $settings ) {}

    public function build( \WC_Product $product ): array {
        $type = $product->is_type( 'variation' ) ? 'variation' : 'product';
        $network = $this->mappings->network_id( $type, $product->get_id(), (string) $product->get_sku() );
        $p = array(
            'network_id' => $network,
            'product_id' => $product->get_id(),
            'type' => $product->get_type(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'status' => $product->get_status(),
            'featured' => $product->get_featured(),
            'catalog_visibility' => $product->get_catalog_visibility(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku' => $product->get_sku(),
            'global_unique_id' => method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id() : '',
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'date_on_sale_from' => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date( 'c' ) : null,
            'date_on_sale_to' => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date( 'c' ) : null,
            'tax_status' => $product->get_tax_status(), 'tax_class' => $product->get_tax_class(),
            'manage_stock' => $product->get_manage_stock(), 'stock_quantity' => $product->get_stock_quantity(), 'stock_status' => $product->get_stock_status(),
            'backorders' => $product->get_backorders(), 'low_stock_amount' => $product->get_low_stock_amount(), 'sold_individually' => $product->get_sold_individually(),
            'weight' => $product->get_weight(), 'length' => $product->get_length(), 'width' => $product->get_width(), 'height' => $product->get_height(),
            'shipping_class' => $product->get_shipping_class(), 'upsell_skus' => $this->skus( $product->get_upsell_ids() ), 'cross_sell_skus' => $this->skus( $product->get_cross_sell_ids() ),
            'reviews_allowed' => $product->get_reviews_allowed(), 'purchase_note' => $product->get_purchase_note(), 'menu_order' => $product->get_menu_order(),
            'virtual' => $product->get_virtual(), 'downloadable' => $product->get_downloadable(), 'download_limit' => $product->get_download_limit(), 'download_expiry' => $product->get_download_expiry(),
            'categories' => $this->terms( $product->get_id(), 'product_cat' ), 'tags' => $this->terms( $product->get_id(), 'product_tag' ),
            'brands' => taxonomy_exists( 'product_brand' ) ? $this->terms( $product->get_id(), 'product_brand' ) : array(),
            'custom_taxonomies' => $this->custom_taxonomies( $product->get_id() ),
            'attributes' => $this->attributes( $product ),
            'images_included' => 'yes' === $this->settings->get( 'sync_images', 'yes' ), 'images' => $this->images( $product ),
            'downloads' => $this->downloads( $product ),
            'meta' => $this->whitelisted_meta( $product->get_id() ), 'managed_meta_keys' => $this->meta_keys(),
            'reviews' => 'yes' === $this->settings->get( 'sync_reviews', 'no' ) ? $this->reviews( $product->get_id() ) : array(),
            'store_overrides' => (array) get_post_meta( $product->get_id(), '_kitgenix_multistore_sync_for_woocommerce_store_overrides', true ),
            'store_pricing_rules' => $this->settings->is_control() ? $this->settings->pricing_rules() : array(),
        );
        if ( $product instanceof \WC_Product_External ) { $p['product_url'] = $product->get_product_url(); $p['button_text'] = $product->get_button_text(); }
        if ( $product instanceof \WC_Product_Grouped ) $p['children_skus'] = array_values( array_filter( array_map( static fn( $id ) => ( $c = wc_get_product( $id ) ) ? $c->get_sku() : '', $product->get_children() ) ) );
        if ( $product instanceof \WC_Product_Variable ) {
            $p['variations'] = array();
            foreach ( $product->get_children() as $vid ) { $v = wc_get_product( $vid ); if ( $v instanceof \WC_Product_Variation ) $p['variations'][] = $this->variation( $v ); }
        }
        return apply_filters( 'kitgenix_multistore/product/payload', $p, $product );
    }

    private function variation( \WC_Product_Variation $v ): array {
        $n = $this->mappings->network_id( 'variation', $v->get_id(), (string) $v->get_sku() );
        return array(
            'network_id' => $n, 'sku' => $v->get_sku(), 'global_unique_id' => method_exists( $v, 'get_global_unique_id' ) ? $v->get_global_unique_id() : '', 'status' => $v->get_status(),
            'regular_price' => $v->get_regular_price(), 'sale_price' => $v->get_sale_price(),
            'date_on_sale_from' => $v->get_date_on_sale_from() ? $v->get_date_on_sale_from()->date( 'c' ) : null, 'date_on_sale_to' => $v->get_date_on_sale_to() ? $v->get_date_on_sale_to()->date( 'c' ) : null,
            'tax_class' => $v->get_tax_class(), 'manage_stock' => $v->get_manage_stock(), 'stock_quantity' => $v->get_stock_quantity(), 'stock_status' => $v->get_stock_status(),
            'backorders' => $v->get_backorders(), 'low_stock_amount' => $v->get_low_stock_amount(), 'weight' => $v->get_weight(), 'length' => $v->get_length(), 'width' => $v->get_width(), 'height' => $v->get_height(),
            'shipping_class' => $v->get_shipping_class(), 'virtual' => $v->get_virtual(), 'downloadable' => $v->get_downloadable(), 'download_limit' => $v->get_download_limit(), 'download_expiry' => $v->get_download_expiry(),
            'downloads' => $this->downloads( $v ), 'attributes' => $v->get_attributes(), 'image' => $this->attachment( $v->get_image_id() ), 'meta' => $this->whitelisted_meta( $v->get_id() ),
        );
    }

    private function terms( int $id, string $tax ): array {
        $out = array(); $terms = wp_get_post_terms( $id, $tax ); if ( is_wp_error( $terms ) ) return $out;
        foreach ( $terms as $t ) {
            $ancestors = array();
            foreach ( array_reverse( get_ancestors( $t->term_id, $tax, 'taxonomy' ) ) as $aid ) { $a = get_term( $aid, $tax ); if ( $a && ! is_wp_error( $a ) ) $ancestors[] = $this->term_data( $a, $tax ); }
            $row = $this->term_data( $t, $tax ); $row['ancestors'] = $ancestors; $out[] = $row;
        }
        return $out;
    }

    private function term_data( \WP_Term $term, string $tax ): array {
        $meta = array();
        foreach ( $this->settings->term_meta_keys() as $key ) { $value = get_term_meta( $term->term_id, $key, true ); if ( '' !== $value ) $meta[ $key ] = $value; }
        if ( 'product_cat' === $tax ) { $thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true ); if ( $thumb ) $meta['thumbnail'] = $this->attachment( $thumb ); }
        return array( 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'meta' => $meta );
    }

    private function custom_taxonomies( int $product_id ): array {
        $out = array();
        foreach ( $this->settings->custom_taxonomies() as $tax ) {
            if ( ! taxonomy_exists( $tax ) || in_array( $tax, array( 'product_cat','product_tag','product_brand' ), true ) ) continue;
            $out[ $tax ] = $this->terms( $product_id, $tax );
        }
        return $out;
    }

    private function skus( array $ids ): array { return array_values( array_filter( array_map( static fn( $id ) => ( $p = wc_get_product( $id ) ) ? $p->get_sku() : '', $ids ) ) ); }

    private function attributes( \WC_Product $p ): array {
        $out = array();
        foreach ( $p->get_attributes() as $a ) {
            if ( ! $a instanceof \WC_Product_Attribute ) continue;
            $opts = array();
            if ( $a->is_taxonomy() ) { foreach ( $a->get_terms() ?: array() as $t ) $opts[] = $this->term_data( $t, $a->get_name() ); }
            else $opts = array_values( $a->get_options() );
            $out[] = array( 'name' => $a->get_name(), 'label' => $a->is_taxonomy() ? wc_attribute_label( $a->get_name() ) : $a->get_name(), 'taxonomy' => $a->is_taxonomy(), 'visible' => $a->get_visible(), 'variation' => $a->get_variation(), 'position' => $a->get_position(), 'options' => $opts );
        }
        return $out;
    }

    private function attachment( int $id ): array|string {
        if ( ! $id ) return '';
        $url = wp_get_attachment_url( $id ); if ( ! $url ) return '';
        $post = get_post( $id );
        return array(
            'url' => $url, 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'title' => $post ? $post->post_title : '', 'caption' => $post ? $post->post_excerpt : '', 'description' => $post ? $post->post_content : '',
            'mime_type' => get_post_mime_type( $id ) ?: '',
        );
    }

    private function images( \WC_Product $p ): array {
        if ( 'yes' !== $this->settings->get( 'sync_images', 'yes' ) ) return array();
        $out = array( 'featured' => '', 'gallery' => array() );
        if ( $p->get_image_id() ) $out['featured'] = $this->attachment( $p->get_image_id() );
        foreach ( $p->get_gallery_image_ids() as $id ) { $img = $this->attachment( (int) $id ); if ( $img ) $out['gallery'][] = $img; }
        return $out;
    }

    private function downloads( \WC_Product $p ): array { $out = array(); foreach ( $p->get_downloads() as $d ) $out[] = array( 'id' => $d->get_id(), 'name' => $d->get_name(), 'file' => $d->get_file() ); return $out; }

    private function meta_keys(): array {
        $base = array( '_rank_math_title','_rank_math_description','rank_math_focus_keyword','_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw' );
        $base = array_merge( $base, $this->settings->custom_meta_keys() );
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'kitgenix_multistore/product/meta_keys', $base ) ) ) ) );
    }

    private function whitelisted_meta( int $id ): array {
        $out = array();
        foreach ( $this->meta_keys() as $k ) { $v = get_post_meta( $id, $k, true ); if ( '' !== $v && null !== $v ) $out[ $k ] = $v; }
        return $out;
    }

    private function reviews( int $product_id ): array {
        $out = array();
        $comments = get_comments( array( 'post_id' => $product_id, 'type' => 'review', 'status' => 'all', 'orderby' => 'comment_ID', 'order' => 'ASC', 'number' => 500 ) );
        foreach ( $comments as $comment ) {
            $uuid = (string) get_comment_meta( $comment->comment_ID, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', true );
            if ( ! wp_is_uuid( $uuid ) ) { $uuid = \Kitgenix\MultiStore\Support\Uuid::make(); update_comment_meta( $comment->comment_ID, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', $uuid ); }
            $out[] = array(
                'network_id' => $uuid, 'author' => $comment->comment_author, 'content' => $comment->comment_content,
                'date_gmt' => $comment->comment_date_gmt, 'approved' => $comment->comment_approved,
                'rating' => (int) get_comment_meta( $comment->comment_ID, 'rating', true ), 'verified' => (int) get_comment_meta( $comment->comment_ID, 'verified', true ),
            );
        }
        return $out;
    }
}
