<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Settings;

final class StorePolicy {
    public function __construct( private Settings $settings, private MappingRepository $mappings ) {}

    public function prepare( array $payload, string $store_uuid, string $network_id, string $entity_type ): array {
        if ( 'product' !== $entity_type ) return $payload;
        $mode = $this->mappings->get( $network_id, 'product', $store_uuid ) ? 'update' : 'publish';
        $policy = $this->settings->policy( $store_uuid );
        $allowed = array();
        foreach ( Settings::policy_fields() as $field => $_label ) {
            if ( 'yes' === (string) ( $policy['fields'][ $field ][ $mode ] ?? 'yes' ) ) $allowed[] = $field;
        }
        $payload['_sync_policy'] = array(
            'store_uuid' => $store_uuid,
            'mode' => $mode,
            'allowed_fields' => $allowed,
        );
        return $payload;
    }

    public static function allows( array $payload, string $field ): bool {
        // Payloads created before per-store policies existed have no policy marker and remain permissive.
        if ( ! isset( $payload['_sync_policy'] ) || ! is_array( $payload['_sync_policy'] ) || ! array_key_exists( 'allowed_fields', $payload['_sync_policy'] ) ) return true;
        if ( ! is_array( $payload['_sync_policy']['allowed_fields'] ) ) return false;
        return in_array( $field, $payload['_sync_policy']['allowed_fields'], true );
    }
}
