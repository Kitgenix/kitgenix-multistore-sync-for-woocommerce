<?php
namespace Kitgenix\MultiStore\Support;

final class Access {
    public const CAP = 'kitgenix_multistore_sync_for_woocommerce_manage_multistore';

    public function __construct( private Settings $settings ) {}

    public function hooks(): void {
        add_action( 'admin_init', array( $this, 'sync_roles' ), 5 );
    }

    public function sync_roles(): void {
        foreach ( wp_roles()->roles as $role_key => $_data ) {
            $role = get_role( $role_key );
            if ( ! $role ) continue;
            if ( 'administrator' === $role_key || in_array( $role_key, $this->settings->allowed_roles(), true ) ) $role->add_cap( self::CAP );
            else $role->remove_cap( self::CAP );
        }
    }

    public static function can_manage(): bool {
        return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
    }
}
