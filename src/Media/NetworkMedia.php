<?php
namespace Kitgenix\MultiStore\Media;

use Kitgenix\MultiStore\Support\Environment;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;

final class NetworkMedia {
    public function __construct( private Settings $settings, private Logger $logger ) {}

    public function hooks(): void {
        add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 20, 2 );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'attachment_src' ), 20, 4 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'disable_remote_srcset' ), 20, 5 );
    }

    public function import( array|string $asset, int $post_id ): int {
        $data = is_array( $asset ) ? $asset : array( 'url' => (string) $asset );
        $url = esc_url_raw( (string) ( $data['url'] ?? '' ) );
        if ( ! $url || ! Environment::validate_store_url( $url ) ) return 0;
        $existing = $this->existing( $url );
        if ( $existing ) { $this->apply_metadata( $existing, $data ); return $existing; }
        if ( 'shared_remote' === $this->settings->get( 'media_mode', 'local_copy' ) ) return $this->create_remote_attachment( $url, $post_id, $data );
        return $this->sideload( $url, $post_id, $data );
    }

    public function attachment_url( string|false $url, int|string $attachment_id ): string|false {
        $attachment_id = (int) $attachment_id;
        if ( ! $attachment_id ) return $url;
        $remote = (string) get_post_meta( $attachment_id, '_kitgenix_multistore_sync_for_woocommerce_remote_media_url', true );
        return $remote ?: $url;
    }

    public function attachment_src( array|false $image, int|string $attachment_id, string|array|int $size, bool $icon ): array|false {
        $attachment_id = (int) $attachment_id;
        if ( ! $attachment_id ) return $image;
        $remote = (string) get_post_meta( $attachment_id, '_kitgenix_multistore_sync_for_woocommerce_remote_media_url', true );
        if ( ! $remote ) return $image;
        $width = (int) get_post_meta( $attachment_id, '_kitgenix_multistore_sync_for_woocommerce_remote_width', true ) ?: 1600;
        $height = (int) get_post_meta( $attachment_id, '_kitgenix_multistore_sync_for_woocommerce_remote_height', true ) ?: 1600;
        return array( $remote, $width, $height, false );
    }

    public function disable_remote_srcset( array|false $sources, array $size_array, string $image_src, array $image_meta, int|string $attachment_id ): array|false {
        $attachment_id = (int) $attachment_id;
        if ( ! $attachment_id ) return $sources;
        return get_post_meta( $attachment_id, '_kitgenix_multistore_sync_for_woocommerce_remote_media_url', true ) ? false : $sources;
    }

    private function existing( string $url ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- {$wpdb->postmeta} is WordPress core's own table property, not user input; the value is passed through $wpdb->prepare().
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_kitgenix_multistore_sync_for_woocommerce_source_url','_kitgenix_multistore_sync_for_woocommerce_remote_media_url') AND meta_value=%s LIMIT 1", $url
        ) );
    }

    private function sideload( string $url, int $post_id, array $data ): int {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $id = Environment::with_local_http_access( $url, static fn() => media_sideload_image( $url, $post_id, null, 'id' ) );
        if ( is_wp_error( $id ) ) { $this->logger->warning( 'Image sideload failed.', array( 'url' => $url, 'error' => $id->get_error_message() ) ); return 0; }
        update_post_meta( (int) $id, '_kitgenix_multistore_sync_for_woocommerce_source_url', $url ); $this->apply_metadata( (int) $id, $data ); return (int) $id;
    }

    private function create_remote_attachment( string $url, int $post_id, array $data ): int {
        $mime = sanitize_mime_type( (string) ( $data['mime_type'] ?? '' ) );
        if ( ! $mime ) { $check = wp_check_filetype( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ); $mime = $check['type'] ?: 'image/jpeg'; }
        $id = wp_insert_attachment( array(
            'post_mime_type' => $mime, 'guid' => $url, 'post_parent' => $post_id,
            'post_title' => sanitize_text_field( (string) ( $data['title'] ?? basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'network-image' ) ) ),
            'post_excerpt' => sanitize_textarea_field( (string) ( $data['caption'] ?? '' ) ),
            'post_content' => wp_kses_post( (string) ( $data['description'] ?? '' ) ), 'post_status' => 'inherit',
        ), '', $post_id, true );
        if ( is_wp_error( $id ) ) return 0;
        update_post_meta( (int) $id, '_kitgenix_multistore_sync_for_woocommerce_remote_media_url', $url ); update_post_meta( (int) $id, '_kitgenix_multistore_sync_for_woocommerce_source_url', $url );
        $this->apply_metadata( (int) $id, $data ); return (int) $id;
    }

    private function apply_metadata( int $id, array $data ): void {
        if ( array_key_exists( 'alt', $data ) ) update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $data['alt'] ) );
        $update = array( 'ID' => $id ); $dirty = false;
        foreach ( array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ) as $source => $dest ) {
            if ( array_key_exists( $source, $data ) ) { $update[ $dest ] = 'description' === $source ? wp_kses_post( (string) $data[ $source ] ) : sanitize_text_field( (string) $data[ $source ] ); $dirty = true; }
        }
        if ( $dirty ) wp_update_post( $update );
    }
}
