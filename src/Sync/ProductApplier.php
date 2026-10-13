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
            if ( $id && $revision > 0 ) {
                // Two events for the same product can arrive out of order (retry backoff,
                // concurrent deliveries). last_revision is already tracked monotonically by
                // MappingRepository::upsert() (GREATEST()); if it is ahead of this event's
                // revision, a newer state has already been applied, so this older delivery
                // must not overwrite it - no product is loaded, no setter runs, no save happens.
                $mapping = $this->mappings->get( $network, 'product', Uuid::site() );
                if ( $mapping && (int) $mapping['last_revision'] > $revision ) {
                    $this->logger->info( 'Skipped stale out-of-order product delivery.', array( 'network_id' => $network, 'event_revision' => $revision, 'current_revision' => (int) $mapping['last_revision'] ) );
                    return array( 'local_id' => $id, 'skipped' => 'older_revision' );
                }
            }
            $product = $id ? wc_get_product( $id ) : false; $type = sanitize_key( (string) ( $p['type'] ?? 'simple' ) ); $is_new = ! $product;
            if ( ! $product ) { $product = $this->new_product( $type ); if ( is_wp_error( $product ) ) return $product; }
            elseif ( $product->get_type() !== $type && 'variation' !== $type ) { $product = $this->convert_product_type( $product, $type ); if ( is_wp_error( $product ) ) return $product; }
            $local_overrides = (array) get_post_meta( $product->get_id(), '_kitgenix_multistore_sync_for_woocommerce_override_fields', true );
            $allowed = fn( string $field, string $legacy = '' ): bool => StorePolicy::allows( $p, $field ) && ! in_array( $field, $local_overrides, true ) && ( ! $legacy || ! in_array( $legacy, $local_overrides, true ) );
            try {
                // Every field below is gated on a genuine value difference against the
                // already-loaded product object (the most authoritative and always-
                // recoverable source of "current state" - there is no cache/hash to go
                // stale). $changed only becomes true when something this plugin manages
                // actually differs, so a repeat delivery of identical data reaches the
                // bottom of this block having called no WooCommerce setter at all, and
                // the single save() below never fires. This is what makes synchronising
                // an already-identical product a true no-op.
                $changed = $is_new; // A brand-new product has no post ID yet; WooCommerce requires one save() to create it before terms/attributes/meta can be attached.
                // When the dedicated inventory ledger (InventorySync) is active, it is the
                // sole authority for stock on existing products - it applies deltas/sets
                // under its own revision, atomically, against the network-wide authoritative
                // quantity. A general product_upsert's 'stock_quantity' is only a snapshot of
                // this store's product at build time, carries the shared cross-event-type
                // revision counter (not the ledger's), and can arrive after an in-flight order
                // elsewhere has already moved the authoritative count - applying it here would
                // silently revert that correct, more current stock back to a stale value. Still
                // apply it once, unconditionally, to bootstrap a brand-new product's initial
                // stock before InventorySync has any record of it.
                $dedicated_inventory_sync = 'yes' === $this->settings->get( 'sync_inventory', 'yes' );

                if ( $allowed( 'name' ) ) { $v = wp_strip_all_tags( (string) ( $p['name'] ?? '' ) ); if ( $is_new || ! $this->eq( $product->get_name(), $v ) ) { $product->set_name( $v ); $changed = true; } }
                if ( $allowed( 'slug' ) ) { $v = sanitize_title( (string) ( $p['slug'] ?? '' ) ); if ( $is_new || ! $this->eq( $product->get_slug(), $v ) ) { $product->set_slug( $v ); $changed = true; } }
                if ( $allowed( 'status' ) ) {
                    $status = sanitize_key( (string) ( $p['status'] ?? 'draft' ) ); if ( $is_new && 'yes' !== $this->settings->get( 'auto_publish_new_products', 'yes' ) && 'publish' === $status ) $status = 'draft';
                    if ( $is_new || ! $this->eq( $product->get_status(), $status ) ) { $product->set_status( $status ); $changed = true; }
                }
                if ( $allowed( 'featured' ) ) { $v = ! empty( $p['featured'] ); if ( $is_new || ! $this->eq( $product->get_featured(), $v ) ) { $product->set_featured( $v ); $changed = true; } }
                if ( $allowed( 'catalog_visibility' ) ) { $v = (string) ( $p['catalog_visibility'] ?? 'visible' ); if ( $is_new || ! $this->eq( $product->get_catalog_visibility(), $v ) ) { $product->set_catalog_visibility( $v ); $changed = true; } }
                if ( $allowed( 'description' ) ) { $v = wp_kses_post( (string) ( $p['description'] ?? '' ) ); if ( $is_new || ! $this->eq( $product->get_description(), $v ) ) { $product->set_description( $v ); $changed = true; } }
                if ( $allowed( 'short_description' ) ) { $v = wp_kses_post( (string) ( $p['short_description'] ?? '' ) ); if ( $is_new || ! $this->eq( $product->get_short_description(), $v ) ) { $product->set_short_description( $v ); $changed = true; } }
                if ( $allowed( 'sku' ) ) {
                    $sku = wc_clean( (string) ( $p['sku'] ?? '' ) );
                    if ( ( ! $sku || wc_product_has_unique_sku( $product->get_id(), $sku ) ) && ( $is_new || ! $this->eq( $product->get_sku(), $sku ) ) ) { $product->set_sku( $sku ); $changed = true; }
                }
                if ( method_exists( $product, 'set_global_unique_id' ) && $allowed( 'global_unique_id' ) ) {
                    $v = wc_clean( (string) ( $p['global_unique_id'] ?? '' ) ); if ( $is_new || ! $this->eq( $product->get_global_unique_id(), $v ) ) { $product->set_global_unique_id( $v ); $changed = true; }
                }
                if ( $allowed( 'price', 'price' ) && ( $is_new || $this->price_group_differs( $product, $p ) ) ) {
                    $product->set_regular_price( wc_format_decimal( $p['regular_price'] ?? '' ) ); $product->set_sale_price( wc_format_decimal( $p['sale_price'] ?? '' ) );
                    $product->set_date_on_sale_from( ! empty( $p['date_on_sale_from'] ) ? $p['date_on_sale_from'] : null ); $product->set_date_on_sale_to( ! empty( $p['date_on_sale_to'] ) ? $p['date_on_sale_to'] : null );
                    $changed = true;
                }
                if ( $allowed( 'tax', 'tax' ) && ( $is_new || ! $this->eq( $product->get_tax_status(), (string) ( $p['tax_status'] ?? 'taxable' ) ) || ! $this->eq( $product->get_tax_class(), (string) ( $p['tax_class'] ?? '' ) ) ) ) {
                    $product->set_tax_status( (string) ( $p['tax_status'] ?? 'taxable' ) ); $product->set_tax_class( (string) ( $p['tax_class'] ?? '' ) ); $changed = true;
                }
                if ( $allowed( 'inventory', 'inventory' ) && ( $is_new || ( ! $dedicated_inventory_sync && $this->inventory_group_differs( $product, $p ) ) ) ) {
                    $product->set_manage_stock( ! empty( $p['manage_stock'] ) );
                    if ( ! empty( $p['manage_stock'] ) ) $product->set_stock_quantity( null === ( $p['stock_quantity'] ?? null ) ? null : wc_stock_amount( $p['stock_quantity'] ) );
                    $product->set_stock_status( (string) ( $p['stock_status'] ?? 'instock' ) ); $changed = true;
                }
                if ( $allowed( 'backorders', 'inventory' ) ) { $v = (string) ( $p['backorders'] ?? 'no' ); if ( $is_new || ! $this->eq( $product->get_backorders(), $v ) ) { $product->set_backorders( $v ); $changed = true; } }
                if ( $allowed( 'low_stock', 'inventory' ) ) { $v = $p['low_stock_amount'] ?? ''; if ( $is_new || ! $this->eq( $product->get_low_stock_amount(), $v ) ) { $product->set_low_stock_amount( $v ); $changed = true; } }
                if ( $allowed( 'sold_individually', 'inventory' ) ) { $v = ! empty( $p['sold_individually'] ); if ( $is_new || ! $this->eq( $product->get_sold_individually(), $v ) ) { $product->set_sold_individually( $v ); $changed = true; } }
                if ( $allowed( 'shipping', 'shipping' ) && ( $is_new || $this->shipping_group_differs( $product, $p ) ) ) { $this->set_shipping( $product, $p ); $changed = true; }
                if ( $allowed( 'reviews_allowed', 'misc' ) ) { $v = ! empty( $p['reviews_allowed'] ); if ( $is_new || ! $this->eq( $product->get_reviews_allowed(), $v ) ) { $product->set_reviews_allowed( $v ); $changed = true; } }
                if ( $allowed( 'purchase_note', 'misc' ) ) { $v = wp_kses_post( (string) ( $p['purchase_note'] ?? '' ) ); if ( $is_new || ! $this->eq( $product->get_purchase_note(), $v ) ) { $product->set_purchase_note( $v ); $changed = true; } }
                if ( $allowed( 'downloads', 'downloads' ) && ( $is_new || $this->downloadable_group_differs( $product, $p ) ) ) {
                    $product->set_virtual( ! empty( $p['virtual'] ) ); $product->set_downloadable( ! empty( $p['downloadable'] ) ); $product->set_download_limit( (int) ( $p['download_limit'] ?? -1 ) ); $product->set_download_expiry( (int) ( $p['download_expiry'] ?? -1 ) );
                    $changed = true;
                }
                $menu_order = (int) ( $p['menu_order'] ?? 0 ); if ( $is_new || $product->get_menu_order() !== $menu_order ) { $product->set_menu_order( $menu_order ); $changed = true; }
                if ( $product instanceof \WC_Product_External && $allowed( 'integration_data', 'external' ) ) {
                    $url = esc_url_raw( (string) ( $p['product_url'] ?? '' ) ); $button = wp_strip_all_tags( (string) ( $p['button_text'] ?? '' ) );
                    if ( $is_new || ! $this->eq( $product->get_product_url(), $url ) || ! $this->eq( $product->get_button_text(), $button ) ) { $product->set_product_url( $url ); $product->set_button_text( $button ); $changed = true; }
                }

                if ( $allowed( 'attributes', 'attributes' ) && $this->sync_attributes( $product, (array) ( $p['attributes'] ?? array() ) ) ) $changed = true;
                if ( 'yes' === $this->settings->get( 'sync_images', 'yes' ) && ! empty( $p['images_included'] ) && $allowed( 'images', 'images' ) && $this->sync_images( $product, (array) ( $p['images'] ?? array() ) ) ) $changed = true;
                if ( $allowed( 'downloads', 'downloads' ) && $this->sync_downloads( $product, (array) ( $p['downloads'] ?? array() ) ) ) $changed = true;
                if ( $allowed( 'linked_products', 'linked_products' ) && $this->sync_links( $product, $p ) ) $changed = true;

                // A single primary save() for everything set above. New products need
                // the early save (see $changed initialisation); for an existing product
                // this only runs when a genuine difference was found, which is what
                // prevents an unchanged remote payload from ever touching post_modified,
                // re-triggering the WooCommerce attribute lookup table rebuild, or
                // queuing wc_regenerate_attribute_variation_summaries.
                if ( $changed ) $product->save();
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
                $this->apply_meta( $product->get_id(), $p, $allowed );
                if ( $product instanceof \WC_Product_Variable && $allowed( 'variations', 'variations' ) ) $this->apply_variations( $product, (array) ( $p['variations'] ?? array() ), $revision, $p, $local_overrides, $is_new, $dedicated_inventory_sync );
                if ( 'yes' === $this->settings->get( 'sync_reviews', 'no' ) && $allowed( 'reviews', 'reviews' ) ) $this->apply_reviews( $product->get_id(), (array) ( $p['reviews'] ?? array() ) );
                if ( $changed ) wc_delete_product_transients( $product->get_id() );
                do_action( 'kitgenix_multistore/product_applied', $product, $p, $revision, $changed );
                return array( 'local_id' => $product->get_id(), 'object_key' => $product->get_sku(), 'unchanged' => ! $changed );
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

    /* ---------------------------------------------------------------------
     * Field-group difference checks. Each mirrors one of the setter groups
     * above so a delivery with no real change in that group never calls the
     * corresponding WooCommerce setters.
     * ------------------------------------------------------------------- */

    private function price_group_differs( \WC_Product $product, array $p ): bool {
        if ( ! $this->eq_amount( $product->get_regular_price(), $p['regular_price'] ?? '' ) ) return true;
        if ( ! $this->eq_amount( $product->get_sale_price(), $p['sale_price'] ?? '' ) ) return true;
        if ( ! $this->eq_date( $product->get_date_on_sale_from(), $p['date_on_sale_from'] ?? null ) ) return true;
        if ( ! $this->eq_date( $product->get_date_on_sale_to(), $p['date_on_sale_to'] ?? null ) ) return true;
        return false;
    }

    private function inventory_group_differs( \WC_Product $product, array $p ): bool {
        if ( ! $this->eq( $product->get_manage_stock(), ! empty( $p['manage_stock'] ) ) ) return true;
        if ( ! empty( $p['manage_stock'] ) ) {
            $incoming_qty = null === ( $p['stock_quantity'] ?? null ) ? null : wc_stock_amount( $p['stock_quantity'] );
            if ( ! $this->eq_amount_nullable( $product->get_stock_quantity(), $incoming_qty ) ) return true;
        }
        if ( ! $this->eq( $product->get_stock_status(), (string) ( $p['stock_status'] ?? 'instock' ) ) ) return true;
        return false;
    }

    private function shipping_group_differs( \WC_Product $product, array $p ): bool {
        foreach ( array( 'weight', 'length', 'width', 'height' ) as $field ) {
            if ( ! $this->eq_amount( $product->{ 'get_' . $field }(), $p[ $field ] ?? '' ) ) return true;
        }
        return $product->get_shipping_class_id() !== $this->shipping_class_id( (string) ( $p['shipping_class'] ?? '' ) );
    }

    private function downloadable_group_differs( \WC_Product $product, array $p ): bool {
        if ( ! $this->eq( $product->get_virtual(), ! empty( $p['virtual'] ) ) ) return true;
        if ( ! $this->eq( $product->get_downloadable(), ! empty( $p['downloadable'] ) ) ) return true;
        if ( (int) $product->get_download_limit() !== (int) ( $p['download_limit'] ?? -1 ) ) return true;
        if ( (int) $product->get_download_expiry() !== (int) ( $p['download_expiry'] ?? -1 ) ) return true;
        return false;
    }

    /* ---------------------------------------------------------------------
     * Value comparison helpers. Normalise before comparing so equivalent
     * representations (bool vs '1'/'', "10" vs 10 vs 10.0, null vs '') never
     * register as a false-positive difference.
     * ------------------------------------------------------------------- */

    private function eq( mixed $a, mixed $b ): bool {
        if ( is_bool( $a ) || is_bool( $b ) ) return (bool) $a === (bool) $b;
        if ( null === $a ) $a = ''; if ( null === $b ) $b = '';
        if ( is_numeric( $a ) && is_numeric( $b ) ) return $this->num( $a ) === $this->num( $b );
        return (string) $a === (string) $b;
    }

    private function eq_amount( mixed $a, mixed $b ): bool { return $this->num( wc_format_decimal( (string) $a ) ) === $this->num( wc_format_decimal( (string) $b ) ); }

    private function eq_amount_nullable( mixed $a, mixed $b ): bool { if ( null === $a || null === $b ) return $a === $b; return $this->eq_amount( $a, $b ); }

    private function eq_date( ?\WC_DateTime $current, mixed $incoming ): bool {
        $current_ts = $current ? $current->getTimestamp() : null;
        $incoming_ts = empty( $incoming ) ? null : ( is_string( $incoming ) ? strtotime( $incoming ) : null );
        if ( false === $incoming_ts ) $incoming_ts = null;
        return $current_ts === $incoming_ts;
    }

    private function num( mixed $v ): string {
        if ( '' === $v || null === $v ) return '';
        $formatted = sprintf( '%.6F', (float) $v );
        $formatted = rtrim( rtrim( $formatted, '0' ), '.' );
        return '' === $formatted || '-0' === $formatted ? '0' : $formatted;
    }

    private function ids_differ( array $a, array $b ): bool {
        $a = array_map( 'intval', $a ); $b = array_map( 'intval', $b ); sort( $a ); sort( $b ); return $a !== $b;
    }

    private function apply_terms( int $id, string $tax, array $items ): void {
        $ids = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue; $parent = 0;
            foreach ( (array) ( $item['ancestors'] ?? array() ) as $a ) { $parent = $this->upsert_term( $tax, (array) $a, $parent ); }
            $term_id = $this->upsert_term( $tax, $item, $parent ); if ( $term_id ) $ids[] = $term_id;
        }
        // upsert_term() above is already a no-op for any term whose name/parent/
        // description already matches, but the product<->term relationship write
        // is separate: only touch it when the resulting term set actually differs
        // from what the product already has, so an unchanged category/tag list
        // never generates a redundant term-relationship update.
        $current_ids = array_map( 'intval', (array) wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) ) );
        if ( $this->ids_differ( $current_ids, $ids ) ) wp_set_object_terms( $id, $ids, $tax, false );
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
            if ( 'thumbnail' === $key ) { $attachment = $this->media->import( is_array( $value ) ? $value : (string) $value, 0 ); if ( $attachment && (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) !== $attachment ) update_term_meta( $term->term_id, 'thumbnail_id', $attachment ); continue; }
            if ( ! $this->eq( get_term_meta( $term->term_id, $key, true ), $value ) ) update_term_meta( $term->term_id, $key, $value );
        }
        return (int) $term->term_id;
    }

    private function ids_by_sku( array $skus ): array { $ids = array(); foreach ( $skus as $sku ) { $id = (int) wc_get_product_id_by_sku( wc_clean( $sku ) ); if ( $id ) $ids[] = $id; } return $ids; }

    /**
     * Whether the product's current attributes already match the incoming
     * structural definition (taxonomy vs custom, IDs/slugs, options, visible,
     * variation, position). Deliberately does not resolve or create any
     * taxonomy term - a term that does not exist locally yet cannot match a
     * real term_id on the current side, so it correctly registers as "changed"
     * without this comparison itself writing anything.
     */
    private function attributes_unchanged( \WC_Product $product, array $items ): bool {
        $current = array();
        foreach ( $product->get_attributes() as $a ) {
            if ( ! $a instanceof \WC_Product_Attribute ) continue;
            $key = sanitize_title( $a->get_name() ); if ( ! $key ) continue;
            $opts = array();
            if ( $a->is_taxonomy() ) { foreach ( $a->get_terms() ?: array() as $t ) $opts[] = (int) $t->term_id; }
            else foreach ( $a->get_options() as $o ) $opts[] = sanitize_text_field( (string) $o );
            $current[ $key ] = array( 'taxonomy' => $a->is_taxonomy(), 'options' => $opts, 'visible' => (bool) $a->get_visible(), 'variation' => (bool) $a->get_variation(), 'position' => (int) $a->get_position() );
        }
        $incoming = array();
        foreach ( $items as $item ) {
            $name = wc_clean( (string) ( $item['name'] ?? '' ) ); if ( ! $name ) continue;
            $key = sanitize_title( $name ); $wants_taxonomy = ! empty( $item['taxonomy'] ); $taxonomy = $wants_taxonomy && taxonomy_exists( $name );
            $opts = array();
            if ( $taxonomy ) {
                foreach ( (array) ( $item['options'] ?? array() ) as $o ) {
                    $slug = sanitize_title( (string) ( is_array( $o ) ? ( $o['slug'] ?? $o['name'] ?? '' ) : $o ) ); if ( ! $slug ) continue;
                    $term = get_term_by( 'slug', $slug, $name ); $opts[] = ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
                }
            } else foreach ( (array) ( $item['options'] ?? array() ) as $o ) $opts[] = sanitize_text_field( is_array( $o ) ? (string) ( $o['name'] ?? '' ) : (string) $o );
            $incoming[ $key ] = array( 'taxonomy' => $taxonomy, 'options' => $opts, 'visible' => ! empty( $item['visible'] ), 'variation' => ! empty( $item['variation'] ), 'position' => (int) ( $item['position'] ?? 0 ) );
        }
        ksort( $current ); ksort( $incoming );
        return $current === $incoming;
    }

    /**
     * Applies incoming attributes to the in-memory product object (does not
     * save). Returns whether anything actually changed. Global attribute
     * taxonomies are only ever created when genuinely missing (never updated
     * merely because a delivery references an existing one) - see the retry
     * throw below, which is the only path that calls wc_create_attribute().
     */
    private function sync_attributes( \WC_Product $product, array $items ): bool {
        foreach ( $items as $item ) {
            $name = wc_clean( (string) ( $item['name'] ?? '' ) ); if ( ! $name ) continue;
            if ( empty( $item['taxonomy'] ) || taxonomy_exists( $name ) ) continue;
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
                if ( is_wp_error( $created ) ) throw new \RuntimeException( 'Could not create global WooCommerce attribute: ' . esc_html( $created->get_error_message() ) );
                delete_transient( 'wc_attribute_taxonomies' );
            }
            // WooCommerce registers newly-created attribute taxonomies on the next request.
            throw new \RuntimeException( sprintf( 'Global WooCommerce attribute %s was created; this delivery will retry after the taxonomy is registered.', esc_html( $name ) ) );
        }

        if ( $this->attributes_unchanged( $product, $items ) ) return false;

        $attrs = array();
        foreach ( $items as $item ) {
            $name = wc_clean( (string) ( $item['name'] ?? '' ) ); if ( ! $name ) continue;
            $a = new \WC_Product_Attribute(); $taxonomy = ! empty( $item['taxonomy'] ) && taxonomy_exists( $name );
            $a->set_id( $taxonomy ? wc_attribute_taxonomy_id_by_name( $name ) : 0 ); $a->set_name( $name ); $opts = array();
            if ( $taxonomy ) foreach ( (array) ( $item['options'] ?? array() ) as $o ) { $tid = $this->upsert_term( $name, is_array( $o ) ? $o : array( 'name' => $o ), 0 ); if ( $tid ) $opts[] = $tid; }
            else foreach ( (array) ( $item['options'] ?? array() ) as $o ) $opts[] = sanitize_text_field( is_array( $o ) ? (string) ( $o['name'] ?? '' ) : (string) $o );
            $a->set_options( $opts ); $a->set_position( (int) ( $item['position'] ?? 0 ) ); $a->set_visible( ! empty( $item['visible'] ) ); $a->set_variation( ! empty( $item['variation'] ) ); $attrs[ sanitize_title( $name ) ] = $a;
        }
        $product->set_attributes( $attrs );
        return true;
    }

    /** Sets featured/gallery images on the in-memory product (does not save). Returns whether anything changed. */
    private function sync_images( \WC_Product $product, array $images ): bool {
        $current_featured = ( $fid = $product->get_image_id() ) ? (string) wp_get_attachment_url( $fid ) : '';
        $incoming_featured_asset = $images['featured'] ?? '';
        $incoming_featured = is_array( $incoming_featured_asset ) ? (string) ( $incoming_featured_asset['url'] ?? '' ) : (string) $incoming_featured_asset;

        $current_gallery = array_map( static fn( $gid ) => (string) wp_get_attachment_url( (int) $gid ), $product->get_gallery_image_ids() );
        $incoming_gallery_assets = array_slice( (array) ( $images['gallery'] ?? array() ), 0, 50 );
        $incoming_gallery = array_map( static fn( $asset ) => is_array( $asset ) ? (string) ( $asset['url'] ?? '' ) : (string) $asset, $incoming_gallery_assets );

        // Gallery order is customer-visible display order, so it is compared
        // sequentially (not as a set); featured image is a single value.
        if ( $current_featured === $incoming_featured && $current_gallery === $incoming_gallery ) return false;

        // NetworkMedia::import() looks up existing attachments by source URL
        // before creating anything, so resolving already-present images here is
        // an idempotent read, not a new write.
        $product->set_image_id( $this->media->import( $incoming_featured_asset, $product->get_id() ) );
        $gallery = array();
        foreach ( $incoming_gallery_assets as $asset ) { $gid = $this->media->import( $asset, $product->get_id() ); if ( $gid ) $gallery[] = $gid; }
        $product->set_gallery_image_ids( $gallery );
        return true;
    }

    /** Sets downloadable files on the in-memory product/variation (does not save). Returns whether anything changed. */
    private function sync_downloads( \WC_Product $product, array $downloads ): bool {
        $normalize = static fn( string $id, string $name, string $file ): string => $id . '|' . $name . '|' . $file;
        $current = array();
        foreach ( $product->get_downloads() as $d ) $current[] = $normalize( (string) $d->get_id(), (string) $d->get_name(), (string) $d->get_file() );
        $incoming_keys = array();
        foreach ( $downloads as $d ) {
            if ( ! is_array( $d ) ) continue;
            $incoming_keys[] = $normalize( sanitize_text_field( (string) ( $d['id'] ?? md5( (string) ( $d['file'] ?? '' ) ) ) ), sanitize_text_field( (string) ( $d['name'] ?? '' ) ), wc_clean( (string) ( $d['file'] ?? '' ) ) );
        }
        $current_sorted = $current; $incoming_sorted = $incoming_keys; sort( $current_sorted ); sort( $incoming_sorted );
        if ( $current_sorted === $incoming_sorted ) return false;

        $out = array();
        foreach ( $downloads as $d ) {
            if ( ! is_array( $d ) ) continue;
            $file = new \WC_Product_Download(); $file->set_id( sanitize_text_field( (string) ( $d['id'] ?? md5( (string) ( $d['file'] ?? '' ) ) ) ) ); $file->set_name( sanitize_text_field( (string) ( $d['name'] ?? '' ) ) ); $file->set_file( wc_clean( (string) ( $d['file'] ?? '' ) ) );
            $out[ $file->get_id() ] = $file;
        }
        $product->set_downloads( $out );
        return true;
    }

    /** Sets upsells/cross-sells/grouped children on the in-memory product (does not save). Returns whether anything changed. */
    private function sync_links( \WC_Product $product, array $p ): bool {
        $changed = false;
        $upsells = $this->ids_by_sku( (array) ( $p['upsell_skus'] ?? array() ) );
        if ( $this->ids_differ( $product->get_upsell_ids(), $upsells ) ) { $product->set_upsell_ids( $upsells ); $changed = true; }
        $cross = $this->ids_by_sku( (array) ( $p['cross_sell_skus'] ?? array() ) );
        if ( $this->ids_differ( $product->get_cross_sell_ids(), $cross ) ) { $product->set_cross_sell_ids( $cross ); $changed = true; }
        if ( $product instanceof \WC_Product_Grouped ) {
            $children = array(); foreach ( (array) ( $p['children_skus'] ?? array() ) as $sku ) { $cid = (int) wc_get_product_id_by_sku( wc_clean( $sku ) ); if ( $cid ) $children[] = $cid; }
            if ( $this->ids_differ( $product->get_children(), $children ) ) { $product->set_children( $children ); $changed = true; }
        }
        return $changed;
    }

    private function apply_meta( int $product_id, array $p, callable $allowed ): void {
        $meta = (array) ( $p['meta'] ?? array() );
        foreach ( (array) ( $p['managed_meta_keys'] ?? array_keys( $meta ) ) as $key ) {
            $key = sanitize_key( (string) $key ); if ( ! $key ) continue;
            $group = ( str_starts_with( $key, '_rank_math_' ) || str_starts_with( $key, 'rank_math_' ) || str_starts_with( $key, '_yoast_' ) ) ? 'seo' : ( $this->integration_meta( $key ) ? 'integration_data' : 'custom_meta' );
            if ( ! $allowed( $group, 'seo' === $group ? 'seo' : $group ) ) continue;
            if ( array_key_exists( $key, $meta ) ) {
                if ( $this->eq( get_post_meta( $product_id, $key, true ), $meta[ $key ] ) ) continue;
                update_post_meta( $product_id, $key, wp_slash( $meta[ $key ] ) );
            } elseif ( metadata_exists( 'post', $product_id, $key ) ) {
                delete_post_meta( $product_id, $key );
            }
        }
    }

    private function integration_meta( string $key ): bool {
        foreach ( array( '_multi_inventory','_inventory_sorting_mode','_inventory_iteration','_expirable_inventories','_price_per_inventory','_wc_cog_cost','_per_product_shipping','_per_product_shipping_add_to_all','yikes_woo_products_tabs','_elementor_','_wp_page_template','_alg_wc_price_by_user_role_','_wpm_gtin_','wooct_' ) as $prefix ) if ( str_starts_with( $key, $prefix ) ) return true;
        return false;
    }

    private function apply_variations( \WC_Product_Variable $parent, array $items, int $revision, array $parent_payload, array $local_overrides, bool $parent_is_new, bool $dedicated_inventory_sync = false ): void {
        $incoming = array(); $structure_changed = $parent_is_new;
        foreach ( $items as $v ) {
            if ( ! is_array( $v ) ) continue; $network = sanitize_text_field( (string) ( $v['network_id'] ?? '' ) ); if ( ! $network ) continue; $incoming[] = $network;
            $id = $this->mappings->local_id( $network, 'variation' ); if ( ! $id && 'yes' === $this->settings->get( 'sku_assisted_linking', 'yes' ) && ! empty( $v['sku'] ) ) $id = (int) wc_get_product_id_by_sku( wc_clean( $v['sku'] ) );
            $variation = $id ? wc_get_product( $id ) : false; $is_new_variation = ! $variation instanceof \WC_Product_Variation;
            if ( $is_new_variation ) { $variation = new \WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); }
            $changed = $is_new_variation;

            if ( StorePolicy::allows( $parent_payload, 'status' ) && ! in_array( 'status', $local_overrides, true ) ) {
                $status = sanitize_key( (string) ( $v['status'] ?? 'publish' ) ); if ( $is_new_variation || ! $this->eq( $variation->get_status(), $status ) ) { $variation->set_status( $status ); $changed = true; }
            }
            if ( StorePolicy::allows( $parent_payload, 'sku' ) && ! in_array( 'sku', $local_overrides, true ) ) {
                $sku = wc_clean( (string) ( $v['sku'] ?? '' ) );
                if ( ( ! $sku || wc_product_has_unique_sku( $variation->get_id(), $sku ) ) && ( $is_new_variation || ! $this->eq( $variation->get_sku(), $sku ) ) ) { $variation->set_sku( $sku ); $changed = true; }
            }
            if ( method_exists( $variation, 'set_global_unique_id' ) && StorePolicy::allows( $parent_payload, 'global_unique_id' ) && ! in_array( 'global_unique_id', $local_overrides, true ) ) {
                $v_guid = wc_clean( (string) ( $v['global_unique_id'] ?? '' ) ); if ( $is_new_variation || ! $this->eq( $variation->get_global_unique_id(), $v_guid ) ) { $variation->set_global_unique_id( $v_guid ); $changed = true; }
            }
            if ( StorePolicy::allows( $parent_payload, 'price' ) && ! in_array( 'price', $local_overrides, true ) && ( $is_new_variation || $this->price_group_differs( $variation, $v ) ) ) {
                $variation->set_regular_price( wc_format_decimal( $v['regular_price'] ?? '' ) ); $variation->set_sale_price( wc_format_decimal( $v['sale_price'] ?? '' ) );
                $variation->set_date_on_sale_from( ! empty( $v['date_on_sale_from'] ) ? $v['date_on_sale_from'] : null ); $variation->set_date_on_sale_to( ! empty( $v['date_on_sale_to'] ) ? $v['date_on_sale_to'] : null );
                $changed = true;
            }
            if ( StorePolicy::allows( $parent_payload, 'tax' ) ) { $tax_class = (string) ( $v['tax_class'] ?? '' ); if ( $is_new_variation || ! $this->eq( $variation->get_tax_class(), $tax_class ) ) { $variation->set_tax_class( $tax_class ); $changed = true; } }
            if ( StorePolicy::allows( $parent_payload, 'inventory' ) && ! in_array( 'inventory', $local_overrides, true ) && ( $is_new_variation || ( ! $dedicated_inventory_sync && $this->inventory_group_differs( $variation, $v ) ) ) ) {
                $variation->set_manage_stock( ! empty( $v['manage_stock'] ) );
                if ( ! empty( $v['manage_stock'] ) ) $variation->set_stock_quantity( null === ( $v['stock_quantity'] ?? null ) ? null : wc_stock_amount( $v['stock_quantity'] ) );
                $variation->set_stock_status( (string) ( $v['stock_status'] ?? 'instock' ) ); $changed = true;
            }
            if ( StorePolicy::allows( $parent_payload, 'backorders' ) && ! in_array( 'backorders', $local_overrides, true ) && ! in_array( 'inventory', $local_overrides, true ) ) {
                $backorders = (string) ( $v['backorders'] ?? 'no' ); if ( $is_new_variation || ! $this->eq( $variation->get_backorders(), $backorders ) ) { $variation->set_backorders( $backorders ); $changed = true; }
            }
            if ( StorePolicy::allows( $parent_payload, 'low_stock' ) && ! in_array( 'low_stock', $local_overrides, true ) && ! in_array( 'inventory', $local_overrides, true ) ) {
                $low_stock = $v['low_stock_amount'] ?? ''; if ( $is_new_variation || ! $this->eq( $variation->get_low_stock_amount(), $low_stock ) ) { $variation->set_low_stock_amount( $low_stock ); $changed = true; }
            }
            if ( StorePolicy::allows( $parent_payload, 'shipping' ) && ! in_array( 'shipping', $local_overrides, true ) && ( $is_new_variation || $this->shipping_group_differs( $variation, $v ) ) ) {
                $variation->set_weight( wc_format_decimal( $v['weight'] ?? '' ) ); $variation->set_length( wc_format_decimal( $v['length'] ?? '' ) ); $variation->set_width( wc_format_decimal( $v['width'] ?? '' ) ); $variation->set_height( wc_format_decimal( $v['height'] ?? '' ) );
                $variation->set_shipping_class_id( $this->shipping_class_id( (string) ( $v['shipping_class'] ?? '' ) ) ); $changed = true;
            }
            if ( StorePolicy::allows( $parent_payload, 'downloads' ) ) {
                if ( $is_new_variation || $this->downloadable_group_differs( $variation, $v ) ) {
                    $variation->set_virtual( ! empty( $v['virtual'] ) ); $variation->set_downloadable( ! empty( $v['downloadable'] ) ); $variation->set_download_limit( (int) ( $v['download_limit'] ?? -1 ) ); $variation->set_download_expiry( (int) ( $v['download_expiry'] ?? -1 ) ); $changed = true;
                }
                if ( $this->sync_downloads( $variation, (array) ( $v['downloads'] ?? array() ) ) ) $changed = true;
            }
            if ( StorePolicy::allows( $parent_payload, 'attributes' ) && ! in_array( 'attributes', $local_overrides, true ) ) {
                $incoming_attrs = array_map( 'wc_clean', (array) ( $v['attributes'] ?? array() ) ); ksort( $incoming_attrs );
                $current_attrs = array_map( 'wc_clean', $variation->get_attributes() ); ksort( $current_attrs );
                if ( $is_new_variation || $current_attrs !== $incoming_attrs ) { $variation->set_attributes( $incoming_attrs ); $changed = true; }
            }
            if ( StorePolicy::allows( $parent_payload, 'images' ) && array_key_exists( 'image', $v ) ) {
                $current_image_url = ( $vid = $variation->get_image_id() ) ? (string) wp_get_attachment_url( $vid ) : '';
                $incoming_image_url = is_array( $v['image'] ) ? (string) ( $v['image']['url'] ?? '' ) : (string) $v['image'];
                if ( $is_new_variation || $current_image_url !== $incoming_image_url ) { $variation->set_image_id( $this->media->import( $v['image'], $parent->get_id() ) ); $changed = true; }
            }

            if ( $changed ) {
                $variation->save();
                update_post_meta( $variation->get_id(), '_kitgenix_multistore_sync_for_woocommerce_network_id', $network );
                $structure_changed = true;
            } elseif ( (string) get_post_meta( $variation->get_id(), '_kitgenix_multistore_sync_for_woocommerce_network_id', true ) !== $network ) {
                update_post_meta( $variation->get_id(), '_kitgenix_multistore_sync_for_woocommerce_network_id', $network );
            }
            $this->mappings->upsert( $network, 'variation', Uuid::site(), $variation->get_id(), (string) $variation->get_sku(), $revision );
            if ( ! empty( $v['meta'] ) ) { $vp = $parent_payload; $vp['meta'] = (array) $v['meta']; $vp['managed_meta_keys'] = array_keys( $v['meta'] ); $this->apply_meta( $variation->get_id(), $vp, fn( $field ) => StorePolicy::allows( $parent_payload, $field ) ); }
        }
        $trashed = false;
        foreach ( $parent->get_children() as $child_id ) { $nid = (string) get_post_meta( $child_id, '_kitgenix_multistore_sync_for_woocommerce_network_id', true ); if ( $nid && ! in_array( $nid, $incoming, true ) ) { wp_trash_post( $child_id ); $trashed = true; } }
        // WC_Product_Variable::sync() recalculates parent price range/stock and
        // regenerates variation summaries - exactly the operation this hardening
        // exists to avoid triggering unnecessarily, so it only runs when a
        // variation was actually created, updated, or removed.
        if ( $structure_changed || $trashed ) \WC_Product_Variable::sync( $parent->get_id() );
    }

    private function apply_reviews( int $product_id, array $reviews ): void {
        $incoming = array();
        foreach ( array_slice( $reviews, 0, 500 ) as $review ) {
            if ( ! is_array( $review ) ) continue; $uuid = sanitize_text_field( (string) ( $review['network_id'] ?? '' ) ); if ( ! wp_is_uuid( $uuid ) ) continue; $incoming[] = $uuid;
            $comments = get_comments( array( 'post_id' => $product_id, 'meta_key' => '_kitgenix_multistore_sync_for_woocommerce_review_network_id', 'meta_value' => $uuid, 'number' => 1, 'status' => 'all' ) );  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- get_comments() has no other way to look up a review by this plugin's own network-UUID comment meta; scope is already bounded to a single product (post_id) and number=>1.
            $data = array( 'comment_post_ID' => $product_id, 'comment_author' => sanitize_text_field( (string) ( $review['author'] ?? 'Customer' ) ), 'comment_content' => wp_kses_post( (string) ( $review['content'] ?? '' ) ), 'comment_type' => 'review', 'comment_approved' => sanitize_text_field( (string) ( $review['approved'] ?? '1' ) ), 'comment_date_gmt' => sanitize_text_field( (string) ( $review['date_gmt'] ?? current_time( 'mysql', true ) ) ), 'comment_date' => get_date_from_gmt( sanitize_text_field( (string) ( $review['date_gmt'] ?? current_time( 'mysql', true ) ) ) ) );
            if ( $comments ) { $data['comment_ID'] = $comments[0]->comment_ID; wp_update_comment( wp_slash( $data ) ); $cid = (int) $comments[0]->comment_ID; }
            else { $cid = (int) wp_insert_comment( wp_slash( $data ) ); update_comment_meta( $cid, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', $uuid ); }
            if ( $cid ) { update_comment_meta( $cid, 'rating', max( 0, min( 5, (int) ( $review['rating'] ?? 0 ) ) ) ); update_comment_meta( $cid, 'verified', ! empty( $review['verified'] ) ? 1 : 0 ); }
        }
        $network_comments = get_comments( array( 'post_id' => $product_id, 'meta_key' => '_kitgenix_multistore_sync_for_woocommerce_review_network_id', 'number' => 0, 'status' => 'all' ) );  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- get_comments() has no other way to enumerate this plugin's own network-synced reviews for reconciliation; scope is already bounded to a single product (post_id).
        foreach ( $network_comments as $network_comment ) {
            $uuid = (string) get_comment_meta( $network_comment->comment_ID, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', true );
            if ( wp_is_uuid( $uuid ) && ! in_array( $uuid, $incoming, true ) ) wp_delete_comment( $network_comment->comment_ID, true );
        }
        \WC_Comments::clear_transients( $product_id );
    }
}
