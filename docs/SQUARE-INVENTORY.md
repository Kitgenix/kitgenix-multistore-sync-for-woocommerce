# WooCommerce Square inventory bridge

Kitgenix MultiStore 1.0.4+ captures stock quantities pulled into the Control Store by WooCommerce Square and fans the final WooCommerce quantity out to connected stores.

## Recommended topology

Square POS / Square inventory -> UK WooCommerce Control Store -> Kitgenix authoritative inventory -> Ireland and other connected WooCommerce stores.

WooCommerce Square should be installed only where required. Connected stores do not need Square for Kitgenix to receive the stock quantity.

## How it works

WooCommerce Square 5.4.3 updates imported/polled inventory with `set_stock_quantity()` followed by `save()`. Current WooCommerce product data stores emit the standard `woocommerce_product_set_stock` / `woocommerce_variation_set_stock` hooks during that save, so Kitgenix's normal inventory engine captures the change. Kitgenix also listens to `woocommerce_product_object_updated_props` on the Control Store as a fallback for stock-related saves from integrations or custom data stores that do not follow the standard quantity hook path.

Direct product saves that include `stock_quantity`, `stock_status`, or `manage_stock` are inspected. When a numeric managed-stock quantity is present, Kitgenix:

1. records the UK WooCommerce quantity as an absolute authoritative network stock value (not a second delta);
2. creates a new inventory revision;
3. dispatches an `inventory_set` event to every active connected store;
4. records the source (`square` when `DOING_SQUARE_SYNC` is active);
5. prevents the same WooCommerce native stock operation being dispatched twice.

Remote Kitgenix writes are excluded through the normal remote-sync context guard, preventing loops.

## Important

If Square is configured as the system of record in WooCommerce Square, a Square inventory change must first reach the UK WooCommerce product. Once WooCommerce saves the new quantity, Kitgenix can distribute it. Kitgenix does not call Square's API directly and does not store Square API credentials.

## Order-driven stock restoration (refunds, and WooCommerce 11.0 failed orders)

WooCommerce restores previously-reduced stock in two situations, both firing the core `woocommerce_restore_order_stock` hook: a refund with "restock items" checked, and – new in WooCommerce 11.0 – an order transitioning to **Failed** status via `wc_maybe_increase_stock_levels()`.

Kitgenix listens to this hook directly on the Control Store (`InventorySync::after_stock_restored()`), and deliberately does **not** gate on the usual remote-sync context guard. That guard exists so `OrderSync::apply_status()`/`apply_refund()` don't re-queue the same order twice for outbound sync – but it previously had the side effect of also silently suppressing the *inventory* consequences of a restoration triggered through those code paths, meaning restored stock could be applied locally in WooCommerce but never reach the authoritative network ledger or connected stores. This handler closes that gap: it always captures the resulting quantity as an authoritative set, de-duplicated against the standard `*_set_stock` hook path so a restoration is never double-counted.
