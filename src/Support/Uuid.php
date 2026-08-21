<?php
namespace Kitgenix\MultiStore\Support;

final class Uuid {
    public static function make(): string {
        return wp_generate_uuid4();
    }

    public static function site(): string {
        $uuid = (string) get_option( 'kitgenix_multistore_sync_for_woocommerce_site_uuid', '' );
        if ( ! $uuid ) {
            $uuid = self::make();
            update_option( 'kitgenix_multistore_sync_for_woocommerce_site_uuid', $uuid, false );
        }
        return $uuid;
    }
}
