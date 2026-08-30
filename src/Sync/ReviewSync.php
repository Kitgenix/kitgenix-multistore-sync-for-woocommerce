<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Uuid;

final class ReviewSync {
    public function __construct( private MappingRepository $mappings, private Dispatcher $dispatcher, private Settings $settings, private Logger $logger ) {}

    public function hooks(): void {
        add_action( 'comment_post', array( $this, 'comment_created' ), 30, 3 );
        add_action( 'edit_comment', array( $this, 'comment_changed' ), 30, 1 );
        add_action( 'transition_comment_status', array( $this, 'status_changed' ), 30, 3 );
        add_action( 'deleted_comment', array( $this, 'comment_deleted' ), 30, 2 );
    }

    public function comment_created( int $comment_id, string|int $approved, array $data ): void { $this->comment_changed( $comment_id ); }
    public function status_changed( string $new, string $old, \WP_Comment $comment ): void { if ( $new !== $old ) $this->comment_changed( (int) $comment->comment_ID ); }

    public function comment_changed( int $comment_id ): void {
        if ( Context::is_remote() || 'yes' !== $this->settings->get( 'sync_reviews', 'no' ) ) return;
        $comment = get_comment( $comment_id ); if ( ! $comment || 'review' !== $comment->comment_type || 'product' !== get_post_type( $comment->comment_post_ID ) ) return;
        if ( $this->settings->is_connected() && 'network' !== $this->settings->get( 'review_direction', 'network' ) ) return;
        $product_network = (string) get_post_meta( $comment->comment_post_ID, '_kitgenix_multistore_sync_for_woocommerce_network_id', true );
        if ( ! wp_is_uuid( $product_network ) ) $product_network = $this->mappings->network_id( 'product', (int) $comment->comment_post_ID, (string) ( wc_get_product( $comment->comment_post_ID )?->get_sku() ?? '' ) );
        $review_network = (string) get_comment_meta( $comment_id, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', true );
        if ( ! wp_is_uuid( $review_network ) ) { $review_network = Uuid::make(); update_comment_meta( $comment_id, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', $review_network ); }
        $payload = array(
            'network_id' => $review_network, 'product_network_id' => $product_network,
            'author' => $comment->comment_author, 'content' => $comment->comment_content, 'date_gmt' => $comment->comment_date_gmt,
            'approved' => $comment->comment_approved, 'rating' => (int) get_comment_meta( $comment_id, 'rating', true ), 'verified' => (int) get_comment_meta( $comment_id, 'verified', true ),
        );
        $this->dispatcher->dispatch( 'review_upsert', 'review', $review_network, $payload );
    }

    public function comment_deleted( int $comment_id, \WP_Comment $comment ): void {
        if ( Context::is_remote() || 'yes' !== $this->settings->get( 'sync_reviews', 'no' ) || 'review' !== $comment->comment_type ) return;
        if ( $this->settings->is_connected() && 'network' !== $this->settings->get( 'review_direction', 'network' ) ) return;
        $review_network = (string) get_comment_meta( $comment_id, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', true );
        $product_network = (string) get_post_meta( $comment->comment_post_ID, '_kitgenix_multistore_sync_for_woocommerce_network_id', true );
        if ( wp_is_uuid( $review_network ) && wp_is_uuid( $product_network ) ) $this->dispatcher->dispatch( 'review_delete', 'review', $review_network, array( 'network_id' => $review_network, 'product_network_id' => $product_network ) );
    }

    public function receive_upsert( array $payload, string $source ): array|\WP_Error {
        if ( 'yes' !== $this->settings->get( 'sync_reviews', 'no' ) ) return array( 'skipped' => true );
        $review_network = sanitize_text_field( (string) ( $payload['network_id'] ?? '' ) ); $product_network = sanitize_text_field( (string) ( $payload['product_network_id'] ?? '' ) );
        if ( ! wp_is_uuid( $review_network ) || ! wp_is_uuid( $product_network ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_review_payload', 'Invalid review payload.' );
        $product_id = $this->mappings->local_id( $product_network, 'product' ); if ( ! $product_id ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_review_product', 'Review product mapping is missing.', array( 'status' => 404 ) );
        $comments = get_comments( array( 'post_id' => $product_id, 'meta_key' => '_kitgenix_multistore_sync_for_woocommerce_review_network_id', 'meta_value' => $review_network, 'number' => 1, 'status' => 'all' ) );  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- get_comments() has no other way to look up a review by this plugin's own network-UUID comment meta; scope is already bounded to a single product (post_id) and number=>1.
        $data = array( 'comment_post_ID' => $product_id, 'comment_author' => sanitize_text_field( (string) ( $payload['author'] ?? 'Customer' ) ), 'comment_content' => wp_kses_post( (string) ( $payload['content'] ?? '' ) ), 'comment_type' => 'review', 'comment_approved' => sanitize_text_field( (string) ( $payload['approved'] ?? '1' ) ), 'comment_date_gmt' => sanitize_text_field( (string) ( $payload['date_gmt'] ?? current_time( 'mysql', true ) ) ), 'comment_date' => get_date_from_gmt( sanitize_text_field( (string) ( $payload['date_gmt'] ?? current_time( 'mysql', true ) ) ) ) );
        $cid = Context::remote( function() use ( $comments, $data, $review_network, $payload ) {
            if ( $comments ) { $row = $data; $row['comment_ID'] = $comments[0]->comment_ID; wp_update_comment( wp_slash( $row ) ); $id = (int) $comments[0]->comment_ID; }
            else { $id = (int) wp_insert_comment( wp_slash( $data ) ); if ( $id ) update_comment_meta( $id, '_kitgenix_multistore_sync_for_woocommerce_review_network_id', $review_network ); }
            if ( $id ) { update_comment_meta( $id, 'rating', max( 0, min( 5, (int) ( $payload['rating'] ?? 0 ) ) ) ); update_comment_meta( $id, 'verified', ! empty( $payload['verified'] ) ? 1 : 0 ); }
            return $id;
        } );
        \WC_Comments::clear_transients( $product_id );
        if ( $this->settings->is_control() && $source !== Uuid::site() ) $this->dispatcher->dispatch( 'review_upsert', 'review', $review_network, $payload, $this->dispatcher->targets_for( $source ), $source );
        return array( 'local_id' => $cid, 'object_key' => $review_network );
    }

    public function receive_delete( array $payload, string $source ): array|\WP_Error {
        $review_network = sanitize_text_field( (string) ( $payload['network_id'] ?? '' ) ); $product_network = sanitize_text_field( (string) ( $payload['product_network_id'] ?? '' ) );
        if ( ! wp_is_uuid( $review_network ) ) return new \WP_Error( 'kitgenix_multistore_sync_for_woocommerce_review_payload', 'Invalid review payload.' );
        $product_id = $this->mappings->local_id( $product_network, 'product' );
        if ( $product_id ) {
            $comments = get_comments( array( 'post_id' => $product_id, 'meta_key' => '_kitgenix_multistore_sync_for_woocommerce_review_network_id', 'meta_value' => $review_network, 'number' => 1, 'status' => 'all' ) );  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- get_comments() has no other way to look up a review by this plugin's own network-UUID comment meta; scope is already bounded to a single product (post_id) and number=>1.
            if ( $comments ) Context::remote( fn() => wp_delete_comment( $comments[0]->comment_ID, true ) );
            \WC_Comments::clear_transients( $product_id );
        }
        if ( $this->settings->is_control() && $source !== Uuid::site() ) $this->dispatcher->dispatch( 'review_delete', 'review', $review_network, $payload, $this->dispatcher->targets_for( $source ), $source );
        return array( 'local_id' => 0, 'object_key' => $review_network );
    }
}
