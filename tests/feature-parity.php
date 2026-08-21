<?php
/**
 * Feature-parity release gates for Kitgenix MultiStore 1.0.0.
 * These are static architecture gates and do not replace staging integration tests.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($root . '/' . $path) ?: '';
$all = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) if ($f->isFile() && 'php' === strtolower($f->getExtension())) $all .= "\n" . file_get_contents($f->getPathname());
$main = $read('kitgenix-multistore.php');
$settings = $read('src/Support/Settings.php');
$admin = $read('src/Admin/Admin.php');
$payload = $read('src/Sync/ProductPayload.php');
$applier = $read('src/Sync/ProductApplier.php');
$registry = $read('src/Integration/Registry.php');
$orderImporter = $read('src/Orders/OrderImporter.php');
$routes = $read('src/Rest/Routes.php');

$tests = [
    'Release version is 1.0.0' => str_contains($main, "Version: 1.0.0") && str_contains($main, "KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION', '1.0.0"),
    'Native Multisite network mode exists' => str_contains($all, 'class NativeNetwork') && str_contains($settings, "'network_mode' => 'paired'") && str_contains($all, 'kitgenix_multistore_sync_for_woocommerce_native_secret'),
    'Multisite discovery invalidates on site changes' => str_contains($all, 'kitgenix_multistore_sync_for_woocommerce_native_generation') && str_contains($all, 'wp_initialize_site') && str_contains($all, 'wp_uninitialize_site'),
    'Per-store publish/update policy matrix exists' => str_contains($settings, "'publish' => 'yes'") && str_contains($settings, "'update' => 'yes'") && str_contains($admin, 'save_store_policies'),
    'Destination payload carries an enforced sync policy' => str_contains($all, "'_sync_policy'") && str_contains($all, 'StorePolicy::allows'),
    'Product review sync supports create/update/delete' => str_contains($all, 'class ReviewSync') && str_contains($all, 'comment_post') && str_contains($all, 'delete_comment'),
    'Category and term descriptions/meta are carried' => str_contains($payload, "'description' => \$term->description") && str_contains($payload, 'term_meta_keys()') && str_contains($applier, 'update_term_meta'),
    'Product image metadata includes ALT/title/caption/description' => str_contains($payload, "'alt'") && str_contains($payload, "'caption'") && str_contains($payload, "'description'") && str_contains($all, '_wp_attachment_image_alt'),
    'Custom product metadata is allowlisted' => str_contains($settings, 'custom_meta_keys') && str_contains($admin, 'Custom product metadata') && str_contains($all, 'managed_meta_keys'),
    'Custom taxonomies are configurable and fail visibly when absent' => str_contains($settings, 'custom_taxonomies') && str_contains($applier, 'is not registered on the destination store'),
    'Global Woo attributes can be created then retried' => str_contains($applier, 'wc_create_attribute') && str_contains($applier, 'will retry after the taxonomy is registered'),
    'Shared remote network media mode exists' => str_contains($all, 'shared_remote') && str_contains($all, 'wp_get_attachment_url'),
    'Network customer My Account history route is signed/authenticated' => str_contains($routes, '/orders/customer-history') && str_contains($routes, "require_peer_role(\$request,'connected')") && str_contains($all, 'network_my_account_orders'),
    'Network-wide order sequence table and endpoint exist' => str_contains($all, 'kitgenix_multistore_sync_for_woocommerce_sequences') && str_contains($routes, '/orders/next-number') && str_contains($all, '_kitgenix_multistore_sync_for_woocommerce_network_order_number'),
    'Optional true WooCommerce order import exists' => str_contains($orderImporter, "'woocommerce' !== \$this->settings->get( 'order_import_mode'") && str_contains($orderImporter, 'wc_create_order'),
    'Imported order duplication suppresses emails and stock side effects' => str_contains($orderImporter, 'woocommerce_email_enabled_') && str_contains($orderImporter, 'woocommerce_can_reduce_order_stock') && str_contains($orderImporter, 'woocommerce_can_restore_order_stock'),
    'Imported orders preserve shipping/fees/coupon/tax details' => str_contains($orderImporter, 'WC_Order_Item_Shipping') && str_contains($orderImporter, 'WC_Order_Item_Fee') && str_contains($orderImporter, 'set_shipping_tax') && str_contains($orderImporter, 'set_discount_tax'),
    'Missing imported products can be cloned as hidden placeholders' => str_contains($orderImporter, '_kitgenix_multistore_sync_for_woocommerce_order_import_placeholder') && str_contains($orderImporter, "set_catalog_visibility( 'hidden' )"),
    'Network order CSV and XLSX export exist' => str_contains($all, 'class OrderExporter') && str_contains($all, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') && str_contains($all, 'ZipArchive'),
    'Central orders support bulk status actions' => str_contains($admin, 'bulk_order_status') && str_contains($admin, 'network_ids'),
    'WooCommerce Quick Edit/Bulk Edit MultiStore controls exist' => str_contains($all, 'woocommerce_product_quick_edit_save') && str_contains($all, 'woocommerce_product_bulk_edit_save'),
    'SKU-assisted network linking is configurable' => str_contains($settings, 'sku_assisted_linking') && str_contains($applier, "get( 'sku_assisted_linking'"),
    'Stores can be paused/resumed without disconnecting' => str_contains($all, 'set_status') && str_contains($admin, 'toggle_store'),
    'Dedicated MultiStore capability/role controls exist' => str_contains($all, 'kitgenix_multistore_sync_for_woocommerce_manage_multistore') && str_contains($settings, 'allowed_roles'),
    'REST-origin product sync can be toggled' => str_contains($settings, 'sync_rest_products') && str_contains($all, 'REST_REQUEST'),
    'WP All Import bridge exists' => str_contains($all, 'pmxi_saved_post') && str_contains($all, 'class ImportBridge'),
    'ATUM metadata adapter exists' => str_contains($registry, '_multi_inventory') && str_contains($registry, 'ATUM Multi-Inventory'),
    'Cost of Goods adapter exists' => str_contains($registry, '_wc_cog_cost') && str_contains($registry, 'WooCommerce Cost of Goods'),
    'WPML and Polylang language adapters exist' => str_contains($registry, 'wpml_set_element_language_details') && str_contains($registry, 'pll_set_post_language'),
    'PDF invoices adapter produces controlled document links' => str_contains($registry, 'wcpdf_get_document') && str_contains($registry, 'kitgenix_multistore_sync_for_woocommerce_pdf_') && str_contains($registry, 'pdf_access_type'),
    'Price-by-role adapter exists' => str_contains($registry, '_alg_wc_price_by_user_role_') && str_contains($registry, 'alg_get_user_roles'),
    'Per-product shipping adapter exists' => str_contains($registry, 'innozilla_per_product_shipping_rules_woo') && str_contains($registry, 'per_product_shipping_rules'),
    'Custom product tabs/Elementor product meta compatibility exists' => str_contains($registry, 'yikes_woo_products_tabs') && str_contains($registry, '_elementor_data'),
    'Integration UI distinguishes detected and integrated states' => str_contains($registry, "'detected'") && str_contains($registry, "'integrated'") && str_contains($admin, 'Integrated'),
];

$failed=0;
foreach($tests as $label=>$ok){ echo ($ok?'PASS':'FAIL') . ": $label\n"; if(!$ok) $failed++; }
echo "\n".count($tests)." feature-parity gates, $failed failures.\n";
exit($failed?1:0);
