# Kitgenix MultiStore 1.0.0 – Feature Parity

Version 1.0.0 is the feature-parity release built after a direct code comparison with WooMultistore 5.5.0. Kitgenix keeps its own signed event/ledger architecture rather than reproducing WooMultistore's transport implementation.

## Store and network management

- Paired independent WordPress/WooCommerce installations.
- Native WordPress Multisite discovery with a selectable Control Site.
- Stable network UUIDs with optional SKU-assisted adoption of pre-existing products.
- Store groups, pause/resume and health/diagnostic status.
- Configurable administrator roles through the `kitgenix_multistore_sync_for_woocommerce_manage_multistore` capability.
- Separate product policy for initial publish versus later updates.

## Product fidelity

The per-store policy matrix covers name, slug, status, featured state, visibility, long/short description, SKU, GTIN/global ID, price, tax, inventory, backorders, low-stock threshold, sold-individually, shipping, review availability, reviews, purchase note, downloads, categories, tags, brands, custom taxonomies, attributes/terms, images, linked products, variations, SEO metadata, custom metadata, language and integration data.

Category/attribute/custom-taxonomy terms carry description and configured term metadata. Product/category/variation images carry ALT text, title, caption and description. Missing global WooCommerce attribute definitions are created and the delivery retries on the next request once WooCommerce has registered the taxonomy. A configured custom taxonomy that is absent on a destination produces a visible sync conflict instead of being silently ignored.

## Reviews and media

Review synchronisation is opt-in. Control-only mode distributes Control-Store reviews outward. Network mode accepts connected-store reviews on the Control Store and redistributes them using stable review UUIDs. Independent local reviews are not deleted by network cleanup.

Media can use **Local Copy** (recommended) or **Shared Network Media**. Shared mode creates lightweight local attachment records whose URL resolves to the authoritative network image, while preserving product attachment relationships and metadata.

## Orders

The default central-order model remains a non-duplicating snapshot. Optional WooCommerce-order import creates tagged Control-Store orders without reducing/restoring stock or sending transactional emails. Imported line items preserve product totals/taxes, shipping, fees, coupon discounts, order taxes, addresses, currency, payment labels and customer note. Missing SKUs may optionally create hidden draft placeholder products.

The Control Centre provides search/filter/pagination, bulk status changes, notes/refunds and CSV/XLSX export. Network sequential order numbers and read-only cross-store My Account history are optional.

## Integration layer

Dedicated/generic adapters cover WooCommerce Square inventory, Rank Math, Yoast, WPML, Polylang, ATUM Multi-Inventory metadata, WooCommerce Cost of Goods, PDF Invoices & Packing Slips document links, Price by User Role, Per Product Shipping, Custom Product Tabs/WP All Import and Elementor product metadata. The admin screen separately reports whether an extension is detected and whether an adapter is available.

## Safety defaults

The following remain disabled by default: customer snapshot sync, review sync, shared remote media, Control-Store WooCommerce order import, placeholder cloning, network My Account history, network sequential order numbering, remote payment-gateway refunds, non-production outbound writes, and full data removal on uninstall (a dedicated setting or a `KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA` wp-config constant must both be explicitly opted in – actual WooCommerce products/orders are never deleted by this plugin either way).

## Price rules

Per connected store, choose a percentage, fixed amount, or exchange-multiplier adjustment; an optional rounding strategy (nearest whole number, nearest 0.50, or charm pricing at X.95/X.99); and how the sale price should be handled (apply the same rule, preserve the original discount percentage, pass the source sale price through unadjusted, or never send a sale price). Every calculation is always derived fresh from the source product's current price at dispatch time, so re-syncing a product never compounds the adjustment – a manual per-product/per-store price override still always wins over the automatic rule.

## Connection health

The Stores screen distinguishes five connection states rather than a single active/offline flag: Connected, Warning (reachable, but the peer is running an outdated WordPress/WooCommerce version or has no Action Scheduler), Offline (unreachable), Authentication Failed (reachable but the peer rejected the signed request), and Version Incompatible (a Kitgenix MultiStore major-version mismatch between the two stores). Each connected store also shows its Kitgenix plugin version, WordPress version, WooCommerce version, store ID, and last contact time.

## Bulk operations

A resumable Bulk Operations panel (Sync Centre) drives full product syncs and full inventory reconciliation in small batches so neither can time out on a large catalogue or network. Supports an optional dry run (reports what would happen – drift found, items excluded – without changing or sending anything), pause/resume/stop, and a persisted progress report (processed/total, repaired, excluded, drift, and the last errors encountered). The same engine is available from WP-CLI (`wp kitgenix-multistore-sync-for-woocommerce bulk-sync` / `bulk-reconcile`, both with `--dry-run`, and `bulk-sync --products=<ids>` for a specific set of products) with a live progress bar. The existing automatic hourly background reconciliation is unaffected. Per-product/per-store exclusions (sync disabled, or explicit target stores/groups) are respected throughout – reconciliation never re-adds a store a product was deliberately excluded from.
