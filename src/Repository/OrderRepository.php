<?php
namespace Kitgenix\MultiStore\Repository;

final class OrderRepository {
    private string $table;
    public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'kitgenix_multistore_sync_for_woocommerce_remote_orders'; }

    public function upsert( array $p, string $store_uuid ): void {
        global $wpdb;
        $sql = "INSERT INTO {$this->table} (network_id,store_uuid,remote_order_id,order_number,status,currency,total,customer_email,customer_name,payload,order_created_at,order_updated_at,synced_at)
                VALUES (%s,%s,%d,%s,%s,%s,%f,%s,%s,%s,%s,%s,%s)
                ON DUPLICATE KEY UPDATE store_uuid=VALUES(store_uuid),remote_order_id=VALUES(remote_order_id),order_number=VALUES(order_number),status=VALUES(status),currency=VALUES(currency),total=VALUES(total),customer_email=VALUES(customer_email),customer_name=VALUES(customer_name),payload=VALUES(payload),order_updated_at=VALUES(order_updated_at),synced_at=VALUES(synced_at)";
        $wpdb->query( $wpdb->prepare( $sql, $p['network_id'], $store_uuid, (int) $p['order_id'], (string) $p['number'], (string) $p['status'], (string) $p['currency'], (float) $p['total'], (string) ( $p['billing']['email'] ?? '' ), trim( (string) ( $p['billing']['first_name'] ?? '' ) . ' ' . (string) ( $p['billing']['last_name'] ?? '' ) ), wp_json_encode( $p, JSON_UNESCAPED_SLASHES ), (string) ( $p['date_created'] ?? '' ), (string) ( $p['date_modified'] ?? '' ), current_time( 'mysql', true ) ) );
    }

    public function query( array $args = array() ): array {
        global $wpdb;
        $args = wp_parse_args( $args, array( 'limit' => 100, 'page' => 1, 'search' => '', 'status' => '', 'store_uuid' => '', 'email' => '', 'date_from' => '', 'date_to' => '' ) );
        $where = array( '1=1' ); $params = array();
        if ( $args['status'] ) { $where[] = 'status=%s'; $params[] = sanitize_key( $args['status'] ); }
        if ( $args['store_uuid'] ) { $where[] = 'store_uuid=%s'; $params[] = sanitize_text_field( $args['store_uuid'] ); }
        if ( $args['email'] ) { $where[] = 'customer_email=%s'; $params[] = sanitize_email( $args['email'] ); }
        if ( $args['search'] ) { $like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%'; $where[] = '(order_number LIKE %s OR customer_email LIKE %s OR customer_name LIKE %s OR network_id LIKE %s)'; array_push( $params, $like, $like, $like, $like ); }
        if ( $args['date_from'] ) { $where[] = 'COALESCE(order_created_at,synced_at)>=%s'; $params[] = sanitize_text_field( $args['date_from'] ); }
        if ( $args['date_to'] ) { $where[] = 'COALESCE(order_created_at,synced_at)<=%s'; $params[] = sanitize_text_field( $args['date_to'] ); }
        $where_sql = implode( ' AND ', $where ); $limit = max( 1, min( 1000, (int) $args['limit'] ) ); $offset = ( max( 1, (int) $args['page'] ) - 1 ) * $limit;
        $count_sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}"; $select_sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY COALESCE(order_updated_at,synced_at) DESC,id DESC LIMIT %d OFFSET %d";
        $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql );
        $qparams = array_merge( $params, array( $limit, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $select_sql, ...$qparams ), ARRAY_A ) ?: array();
        foreach ( $rows as &$row ) $row['payload_array'] = json_decode( (string) $row['payload'], true ) ?: array();
        return array( 'rows' => $rows, 'total' => $total, 'pages' => (int) ceil( $total / $limit ), 'page' => max( 1, (int) $args['page'] ) );
    }

    public function recent( int $limit = 100 ): array { return $this->query( array( 'limit' => $limit ) )['rows']; }
    public function get( string $network_id ): ?array { global $wpdb; $r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE network_id=%s LIMIT 1", $network_id ), ARRAY_A ); if ( $r ) $r['payload_array'] = json_decode( (string) $r['payload'], true ) ?: array(); return $r ?: null; }
    public function by_email( string $email, int $limit = 100 ): array { return $this->query( array( 'email' => $email, 'limit' => $limit ) )['rows']; }
    public function count(): int { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); }
    public function totals_by_currency( int $days = 30 ): array { global $wpdb; $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days ); return $wpdb->get_results( $wpdb->prepare( "SELECT currency,COUNT(*) orders,SUM(total) revenue FROM {$this->table} WHERE COALESCE(order_created_at,synced_at) >= %s GROUP BY currency ORDER BY revenue DESC", $since ), ARRAY_A ) ?: array(); }
}
