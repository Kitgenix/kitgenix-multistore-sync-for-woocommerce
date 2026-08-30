# Kitgenix MultiStore 1.0.0 – Staging Acceptance Matrix

Run this matrix on at least two clean HTTPS WooCommerce staging stores before a live rollout. A three-store test is strongly recommended for fan-out, store policies and reconciliation. Test on the same hosting/CDN/security stack intended for production.

## Environment

- WordPress 6.8+ (tested up to 7.1)
- WooCommerce 10.0+ (tested up to 11.0.0)
- PHP 8.1–8.4
- HTTPS on production-equivalent sites
- WP-Cron working or Action Scheduler available
- HPOS tested in the mode used by the target stores
- If using Native Multisite, network-activate the plugin and test at least three subsites
- If WooCommerce Square is installed, confirm the exact plugin version and re-check `docs/SQUARE-INVENTORY.md` – the stock-quantity write path it depends on can change between Square plugin releases

## Pairing, topology and security

1. Pair one Control Store and at least one Connected Store.
2. Confirm a one-time pairing code cannot be reused.
3. Replay an otherwise valid signed request and confirm nonce replay rejection.
4. Confirm a Connected Store cannot invoke Control-only order endpoints.
5. In Control-authoritative product mode, edit/trash a Connected product and confirm the Control Store is not overwritten.
6. Clone a store to staging/local and confirm outbound writes are blocked until explicitly enabled.
7. Pause a store with a pending delivery; confirm it becomes paused rather than failed. Resume the store and confirm the delivery requeues.

## Native WordPress Multisite

1. Enable Native Multisite mode and choose the Control Site.
2. Confirm all WooCommerce network sites are discovered with the correct roles.
3. Add a new site and confirm discovery refreshes.
4. Remove a site and confirm discovery refreshes without changing another site's UUID.
5. Confirm product/inventory/order flows work using the same event pipeline as paired stores.

## Product synchronisation and policy matrix

1. Create simple, variable, grouped and external products.
2. Verify core product fields, categories, tags, brands, attributes, variations, downloads, links and media.
3. Configure one field as **Initial Publish: On / Later Update: Off**. Publish, then edit that field and prove the destination retains its original value.
4. Configure one field as **Initial Publish: Off / Later Update: On** and confirm behaviour.
5. Disable every field for a test store and confirm no managed product field is overwritten.
6. Test per-product local overrides on the Connected Store.
7. Test store targeting and Store Groups.
8. Test SKU-assisted linking against an existing destination product and variation.
9. Test simple → variable and variable → simple conversion.
10. Remove a managed variation and confirm stale network-managed variations are cleaned up.
11. Test product trash propagation in Control mode and bidirectional mode.
12. Create a global attribute on Control that does not exist on the destination; confirm the first delivery creates the attribute definition, retries, and then applies terms/attributes without creating a duplicate product.
13. Configure a custom taxonomy that is not registered on the destination and confirm Sync Centre shows a visible conflict rather than silently skipping it.

## Pricing

1. Set a destination percentage rule such as +25%.
2. Verify simple regular/sale prices and every variation price.
3. Verify a manual destination price override wins over the percentage rule.
4. Change the percentage and queue all products; confirm recalculation occurs without editing each product.
5. Switch the rule to a fixed amount and confirm the destination price reflects the flat adjustment, floored at zero.
6. Switch the rule to an exchange multiplier and confirm the destination price is the source price multiplied by the configured factor.
7. Enable each rounding strategy in turn (nearest whole number, nearest 0.50, charm X.95, charm X.99) and confirm the destination price lands exactly where expected.
8. Test each sale-price handling mode: same rule applied to sale price; original discount percentage preserved against the new regular price; sale price passed through unadjusted; sale price always cleared.
9. Re-sync the same product several times in a row with a rule enabled and confirm the destination price never compounds – it always matches a single fresh calculation from the current source price.

## Taxonomy, metadata and media fidelity

1. Verify category name/slug/hierarchy/description/image and configured term metadata.
2. Verify global attribute-term descriptions and configured term metadata.
3. Configure a custom product meta key and verify create/update/removal.
4. Configure a registered custom taxonomy on both stores and verify terms/hierarchy.
5. Verify image ALT text, title, caption and description for featured/gallery/variation/category media.
6. Test Local Copy mode.
7. Test Shared Network Media mode and confirm frontend attachment URLs resolve to the authoritative image.

## Reviews

1. Enable review sync in Control-only mode; create/edit/moderate/delete a Control review and verify the destination.
2. Enable Network mode; create a review on a Connected Store and confirm it reaches Control and the other connected stores.
3. Confirm ratings/review counts recalculate after changes.
4. Confirm independent local reviews without a Kitgenix review UUID are not deleted by network reconciliation.

## Inventory and WooCommerce Square

1. Set stock on Control and verify all stores converge.
2. Place near-simultaneous orders on two Connected Stores and verify both deltas appear in the authoritative ledger.
3. Take a store offline, change stock, restore it and verify retry/reconciliation convergence.
4. Force stock drift and run Reconciliation.
5. Test checkout inventory validation and both fail-open/fail-closed modes.
6. If WooCommerce Square is used, change Square stock, let WooCommerce Square update Control, and verify the absolute final quantity fans out to all stores.
7. Place a Connected-Store order and verify the authoritative quantity flows back through Control without double decrement.
8. On the Control Store, refund an order with "restock items" enabled and confirm the restored stock reaches the authoritative ledger and every connected store (not just the local WooCommerce quantity).
9. On the Control Store, transition an order to Failed status (WooCommerce 11.0+ restores its reduced stock automatically) and confirm the restored stock likewise reaches the authoritative ledger and every connected store.
10. Confirm neither of the two scenarios above double-counts the same stock restoration (check the inventory ledger for exactly one entry per restoration, and the Sync Centre for exactly one dispatched event).

## Connection health

1. Pair two stores and confirm the Stores screen shows "Connected" with matching WordPress/WooCommerce/plugin versions and a recent "last contact" time.
2. Temporarily rotate the pairing secret on one side only and confirm the next health check marks the store "Authentication Failed", distinct from a generic "Offline".
3. Take a store fully offline (stop the site) and confirm the next health check marks it "Offline".
4. Confirm a store running WordPress/WooCommerce below this plugin's stated minimum, or without Action Scheduler, is marked "Warning" rather than "Connected".

## Bulk operations

1. Start a product-sync bulk job as a dry run; confirm it reports counts without changing or sending anything.
2. Start a real product-sync bulk job on a catalogue large enough to require multiple batches; confirm progress advances, the request never times out, and the final report matches reality.
3. Pause a running bulk job, confirm it stops advancing, then resume it and confirm it continues from where it left off rather than restarting.
4. Start a reconcile bulk job as a dry run and confirm no repair events are dispatched.
5. Confirm a product/store deliberately excluded from sync is never "repaired" back onto that store by a reconcile bulk job.
6. Run `wp kitgenix-multistore-sync-for-woocommerce bulk-sync --products=<a few IDs>` and confirm only those products are processed with a live progress bar.
7. Confirm the existing automatic hourly background reconciliation still runs unaffected by any of the above.

## Central orders

1. Backfill/index Control and Connected orders.
2. Search/filter/paginate central orders.
3. Bulk-change statuses and confirm only originating orders change.
4. Add private/customer notes.
5. Test non-gateway refunds.
6. Test remote gateway refunds only in a gateway sandbox and only after deliberately enabling them.
7. Export CSV and XLSX; open both and verify all filtered rows, Unicode and formula-safe data.
8. Enable network sequential order numbers; create concurrent orders on multiple stores and confirm unique monotonic display numbers.
9. Temporarily make Control unreachable while creating a Connected order; restore Control and confirm number allocation retries.

## Optional Control-Store WooCommerce order imports

1. Enable WooCommerce import mode on a staging Control Store.
2. Create a Connected order containing products, shipping, fees, coupon and taxes.
3. Confirm the imported Control order preserves totals and is tagged as imported.
4. Confirm creating/updating the imported duplicate does not reduce/restore stock, send WooCommerce order emails or loop back as a new network order.
5. Test missing-SKU placeholder creation with the option both off and on.

## My Account network history

1. Enable network My Account orders.
2. Place orders for the same customer email on two stores.
3. Log into each store and confirm the other-store summaries appear read-only.
4. Confirm a paired store cannot query arbitrary history without a valid signed peer request.

## Integration adapters

Where installed, test the exact extension versions used in production:

- WPML and Polylang product language assignment.
- ATUM Multi-Inventory product metadata.
- WooCommerce Cost of Goods metadata.
- PDF Invoices & Packing Slips central document links and link expiry/access.
- Price by User Role product metadata.
- Per Product Shipping rule rows.
- Custom Product Tabs and WP All Import-triggered product sync.
- Elementor product metadata.
- Rank Math / Yoast product SEO metadata.
- WooCommerce Square inventory bridge.

## Launch gate

Do not launch until the inventory concurrency, failed-network retry, policy-matrix, price-rule, order refund/import, sequential-number, My Account privacy, reconciliation, order-driven stock restoration, connection-health, and bulk-operations scenarios above pass on the actual production-equivalent stack.
