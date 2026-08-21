<?php
namespace Kitgenix\MultiStore\Orders;

use Kitgenix\MultiStore\Repository\OrderRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;

final class OrderExporter {
    public function __construct( private OrderRepository $orders, private StoreRepository $stores ) {}

    public function download( array $args, string $format ): never {
        $raw = array(); $page = 1; $max_rows = 100000;
        do {
            $result = $this->orders->query( array_merge( $args, array( 'limit' => 1000, 'page' => $page ) ) );
            foreach ( $result['rows'] as $row ) { $raw[] = $row; if ( count( $raw ) >= $max_rows ) break 2; }
            ++$page;
        } while ( $page <= max( 1, (int) ( $result['pages'] ?? 1 ) ) );
        $rows = $this->flatten( $raw );
        $format = 'xlsx' === $format ? 'xlsx' : 'csv'; $filename = 'kitgenix-network-orders-' . gmdate( 'Y-m-d-His' ) . '.' . $format;
        nocache_headers(); header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        if ( 'csv' === $format ) { header( 'Content-Type: text/csv; charset=utf-8' ); $out = fopen( 'php://output', 'wb' ); fwrite( $out, "\xEF\xBB\xBF" ); if ( $rows ) fputcsv( $out, array_keys( $rows[0] ) ); foreach ( $rows as $row ) fputcsv( $out, $row ); fclose( $out ); exit; }
        if ( ! class_exists( '\\ZipArchive' ) ) wp_die( 'PHP ZipArchive is required for XLSX exports.' );
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ); echo $this->xlsx( $rows ); exit;
    }

    private function flatten( array $rows ): array {
        $out = array(); foreach ( $rows as $r ) { $store = $this->stores->get( (string) $r['store_uuid'] ); $p = $r['payload_array'] ?? json_decode( (string) $r['payload'], true ) ?: array(); $items = array(); foreach ( (array) ( $p['items'] ?? array() ) as $i ) $items[] = (string) ( $i['name'] ?? '' ) . ' × ' . (string) ( $i['quantity'] ?? 0 ); $row = array( 'Network ID' => $r['network_id'], 'Order' => $r['order_number'], 'Store' => $store['name'] ?? $r['store_uuid'], 'Status' => $r['status'], 'Currency' => $r['currency'], 'Total' => $r['total'], 'Customer' => $r['customer_name'], 'Email' => $r['customer_email'], 'Date' => $r['order_created_at'], 'Items' => implode( '; ', $items ) ); $out[] = array_map( array( $this, 'safe_cell' ), $row ); } return $out;
    }

    private function xlsx( array $rows ): string {
        $tmp = wp_tempnam( 'kitgenix-multistore-sync-for-woocommerce_orders.xlsx' ); if ( ! $tmp ) wp_die( 'Could not create a temporary XLSX export file.' ); $zip = new \ZipArchive(); if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) wp_die( 'Could not open the temporary XLSX export file.' );
        $headers = $rows ? array_keys( $rows[0] ) : array( 'Order' ); $data = array_merge( array( $headers ), array_map( 'array_values', $rows ) ); $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ( $data as $ri => $row ) { $sheet .= '<row r="' . ( $ri + 1 ) . '">'; foreach ( $row as $ci => $value ) { $ref = $this->col( $ci + 1 ) . ( $ri + 1 ); $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>'; } $sheet .= '</row>'; } $sheet .= '</sheetData></worksheet>';
        $zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' );
        $zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
        $zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Orders" sheetId="1" r:id="rId1"/></sheets></workbook>' );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
        $zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet ); $zip->close(); $bytes = file_get_contents( $tmp ); @unlink( $tmp ); return (string) $bytes;
    }

    private function safe_cell( mixed $value ): string {
        $value = (string) $value;
        // Prevent CSV/XLSX formula injection when exports are opened in desktop spreadsheet software.
        return preg_match( '/^[=+\-@]/u', ltrim( $value ) ) ? "'" . $value : $value;
    }

    private function col( int $n ): string { $s = ''; while ( $n > 0 ) { $n--; $s = chr( 65 + ( $n % 26 ) ) . $s; $n = intdiv( $n, 26 ); } return $s; }
}
