<?php
/** Static regression tests for the Square/direct-save inventory bridge. */
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/src/Sync/InventorySync.php');
$tests=array(
    'Hooks WooCommerce standard product stock changes' => str_contains($source,"add_action('woocommerce_product_set_stock'")&&str_contains($source,"add_action('woocommerce_variation_set_stock'"),
    'Hooks updated-props fallback' => str_contains($source,"add_action('woocommerce_product_object_updated_props'"),
    'Only Control Store accepts fallback direct-save authority' => str_contains($source,'!$this->settings->is_control()'),
    'Square source is identified when Square sync is active' => str_contains($source,"defined('DOING_SQUARE_SYNC')")&&str_contains($source,"return'square'"),
    'Square uses an absolute inventory ledger set' => str_contains($source,"'square'===\$base['reference']")&&str_contains($source,'apply_set(')&&str_contains($source,'square_inventory_set'),
    'Final fallback quantity is dispatched as inventory_set' => str_contains($source,"dispatch('inventory_set'")&&str_contains($source,"'quantity'=>\$quantity"),
    'Standard stock path is marked handled before updated-props fallback' => str_contains($source,'native_stock_handled[$id]=true'),
    'Updated-props fallback consumes standard-hook marker' => str_contains($source,'if(!empty($this->native_stock_handled[$id])){unset($this->native_stock_handled[$id]);return;}'),
    'Remote Kitgenix writes are ignored' => str_contains($source,'Context::is_remote()'),
    'External inventory capture is logged' => str_contains($source,'External inventory source updated authoritative network stock.'),
);
$failed=0;foreach($tests as$label=>$ok){echo($ok?'PASS':'FAIL').": $label\n";if(!$ok)$failed++;}
echo "\n".count($tests)." Square bridge tests, $failed failures.\n";
exit($failed?1:0);
