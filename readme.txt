=== Kitgenix MultiStore Sync for WooCommerce ===
Contributors: kitgenix
Donate link: https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2
Tags: woocommerce, multistore sync, inventory sync, product sync, order sync
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
PluginScore Verification: 

Synchronize WooCommerce products, inventory, orders, coupons and optional customer/review data across connected stores.

== Description ==

**Kitgenix MultiStore Sync for WooCommerce** connects multiple WooCommerce stores so product, inventory and selected commerce data can be coordinated from a Control Store without sending the sync workload through a hosted Kitgenix service.

It supports independently hosted WooCommerce installations as well as WordPress Multisite. Stores communicate directly with one another over authenticated REST requests, while Action Scheduler is used for asynchronous delivery where available and WP-Cron provides fallback scheduling.

The plugin is aimed at merchants operating regional storefronts, wholesale/retail sites, brand-specific stores, several domains sharing one catalogue, or a WordPress Multisite network that needs stronger cross-store coordination than manually exporting products and stock.

Learn more about Kitgenix at [Kitgenix](https://kitgenix.com/).

= Multi-Store WooCommerce Features =

* Control Store and Connected Store architecture for independent WordPress installations.
* Native WordPress Multisite discovery mode.
* Product and variation synchronization.
* Authoritative network inventory synchronization.
* Central order indexing on the Control Store.
* Optional import of remote order snapshots as WooCommerce orders.
* Remote order status, note and refund actions supported by authenticated routes/workflows.
* Coupon synchronization.
* Optional customer synchronization.
* Optional product-review synchronization.
* Per-store price rules.
* Per-store field policies for initial publish versus later updates.
* Store groups and per-product target/exclusion controls.
* Resumable bulk product sync and inventory reconciliation.
* Dry-run, pause, resume and stop controls for supported bulk jobs.
* Automatic inventory actions based on stock thresholds.
* Delivery retry/backoff and conflict recording.
* Network Doctor and health checks.
* Searchable logs and delivery diagnostics.
* CSV/XLSX order export.
* WP-CLI commands for status, reconciliation, product sync, doctor, order indexing and bulk jobs.
* Migration bridge for supported WooMultistore data/configuration paths present in the codebase.
* Integration hooks for product meta/payload customization and selected Kitgenix cross-plugin data.
* WooCommerce HPOS compatibility handling.

= Control Store and Connected Stores =

One site acts as the Control Store. Other WooCommerce sites are paired as Connected Stores. The Control Store keeps the network registry, policies and central operational views, while connected stores exchange signed events directly with it.

For separate WordPress installations, a pairing workflow creates the trusted relationship. For WordPress Multisite, Native Multisite mode can discover network sites and determine the control site without requiring the same manual pairing flow for every subsite.

The plugin does not impose a small fixed store-count constant in the application logic; practical scale is instead determined by catalogue size, event volume, hosting resources, network latency and Action Scheduler/cron throughput.

= Product and Variation Synchronization =

Products and variations can be created/updated on target stores according to the configured policies. The product payload layer handles core product information and has specific code paths for attributes, taxonomy terms, images, downloadable files, linked products, variations and whitelisted/custom metadata.

Per-store policies allow the fields synchronized on the first publish to differ from the fields synchronized on later updates. That makes it possible, for example, to centralize product creation while allowing a connected store to retain selected local merchandising fields after the initial copy.

Products can be excluded entirely or limited to selected stores/store groups. Both normal delivery and reconciliation respect targeting so an intentionally excluded product is not repeatedly recreated as "drift."

= Authoritative Inventory Synchronization =

Inventory changes are captured from WooCommerce stock hooks and represented as network events. The inventory layer is designed around an authoritative value so stores converge on one stock state rather than continually overwriting each other with unrelated local counters.

The plugin watches stock quantity/status changes, order-driven stock changes and restoration flows. Periodic reconciliation runs in the background to identify/correct drift that may have resulted from a missed event, temporary outage or other operational interruption.

Cart/checkout validation logic can also compare requested quantities with the authoritative inventory reference where enabled, reducing the chance of a connected storefront accepting stock that the network no longer has available.

= Orders Across Stores =

Orders from connected stores can be indexed centrally on the Control Store. The remote-order repository stores network order snapshots so administrators can search and report across the network without pretending that every remote order was originally created in the Control Store database.

The code also includes optional order import and authenticated actions for remote status changes, order notes and refunds. Network order numbers can be coordinated where that feature is enabled.

Central order views support export workflows, including CSV and XLSX generation implemented by the plugin.

= Coupons, Customers and Reviews =

Coupon synchronization is available for distributing coupon definitions according to the configured direction/policy.

Customer synchronization and product-review synchronization are separate features and are not forced on every installation. They can be enabled deliberately when a merchant wants those data sets shared between connected stores.

Because customer/order records can contain personal information, sites should review the plugin's sync choices as part of their own privacy/data-governance process rather than enabling every data type automatically.

= Per-Store Pricing =

Each connected store can have a pricing rule that adjusts source prices using a percentage, fixed amount or exchange-rate style multiplier. Optional rounding/charm-pricing behaviour and sale-price handling can be configured.

The adjustment is calculated from the source value for each sync rather than continually stacking a new adjustment on top of the previously adjusted remote price.

Manual per-product/per-store behaviour and field policies allow a network to keep central catalogue ownership while still supporting regional or channel-specific prices.

= Bulk Operations =

Large full-catalogue work is handled as persisted bulk jobs rather than a single long browser request. Product sync and inventory reconciliation jobs can be started in batches, monitored and, where supported by the operation, paused, resumed or stopped.

Dry-run mode lets an administrator inspect what a bulk process would do before applying the changes. Persisted progress makes the operation resilient to the administrator closing the browser tab.

= Queueing, Retries and Conflict Handling =

The dispatcher records delivery state and processes asynchronous work through Action Scheduler when it is available. WP-Cron fallbacks exist for key background operations.

Failed deliveries can be retried with backoff. When an issue cannot be resolved automatically, the plugin can keep a conflict record/log entry that identifies the store, event and diagnostic category instead of silently discarding the failure.

The admin log and Network Doctor provide visibility into connectivity, authentication and queue health.

= Store Health and Network Doctor =

Connected-store health checks run on a schedule. The Control Store can display whether a peer is connected, warning, offline, authentication-failed or incompatible according to the health data available.

Network Doctor performs local and remote checks such as HTTPS/REST reachability, plugin versions, WooCommerce environment, queue/scheduler health and other prerequisites relevant to reliable synchronization.

= Security Between Stores =

Requests between paired stores are signed with an HMAC-SHA256 scheme covering request components such as method, route, timestamp, nonce and body hash. The receiving site rejects requests that fail authentication, fall outside the permitted time window or replay a nonce.

Production pairings require HTTPS. The code only permits unencrypted HTTP for environments it recognizes as local/development, not for a live-to-live production connection.

Shared secrets are encrypted at rest with AES-256-GCM using key material derived from the WordPress installation's security salts. Secrets are therefore not stored as ordinary plaintext options.

= Native WordPress Multisite =

On Multisite, the plugin can discover network sites and operate without treating every subsite as an unrelated remote SaaS tenant. Network initialization/removal hooks keep the local site registry aligned when sites are created or removed.

This mode is useful where several WooCommerce stores share one WordPress network but still require separate products/orders/storefront behaviour.

= WP-CLI =

The command base is:

`wp kitgenix-multistore-sync-for-woocommerce`

Implemented commands include `status`, `reconcile`, `sync-product`, `doctor`, `index-orders`, `bulk-sync` and `bulk-reconcile`.

These commands provide operational visibility and make catalogue/network maintenance scriptable in managed hosting and deployment workflows.

= Direct Store-to-Store Data Flow =

The sync feature does not use a Kitgenix cloud relay. Network data is exchanged only with stores the administrator explicitly pairs/connects (or sites discovered inside the same configured Multisite network). Media or selected synced document assets can be downloaded directly from the sending store when a target store needs a local copy.

The separate shared Kitgenix Hub may request public plugin metadata from WordPress.org, and the admin UI imports Google Fonts as documented under **External Services**. Those requests are unrelated to commerce synchronization.

= Common Multi-Store Scenarios =

* UK/Ireland or other regional WooCommerce storefronts sharing one catalogue.
* Retail and wholesale stores sharing inventory but using different prices.
* Multiple brand/domain stores supplied from the same physical stock pool.
* WordPress Multisite networks with several WooCommerce shops.
* Central order visibility across separately hosted stores.
* Central product creation with per-store update policies.
* Store-specific pricing while preserving a common source product.

== Installation ==

1. Install and activate WooCommerce, then install and activate Kitgenix MultiStore Sync for WooCommerce on every store you want to connect.
2. **Separate WordPress installations:** on the store that should hold the master catalogue, generate a one-time pairing code (MultiStore → Stores). On each other store, enter that store's URL and the pairing code to connect it as a Connected Store. HTTPS is required for production pairings.
3. **WordPress Multisite:** enable Native WordPress Multisite mode under MultiStore → Settings and choose the Control Site; the other sites on the network are discovered and paired automatically.
4. Review synchronisation settings under MultiStore → Settings – what syncs, in which direction, and any per-store price rules or field policies.
5. Run Network Doctor (MultiStore → Doctor) to confirm connectivity, HTTPS, Action Scheduler and HPOS status before relying on the connection in production.
6. Use Bulk Operations (or the WP-CLI `bulk-sync`/`bulk-reconcile` commands) to run an initial full sync once your stores are paired.

== External Services ==

This plugin's core purpose is to keep two or more of **your own** WooCommerce stores in sync. To do that, once you deliberately pair or connect a store, it communicates directly – and only – with the WooCommerce store(s) you specify. There is no Kitgenix-hosted or other third-party sync service in between, and nothing is sent anywhere until at least one store is paired.

= Requests made between your paired stores =

* **Pairing** (one-time, admin-initiated): when you enter a store's URL and pairing code, this site sends an HTTPS POST containing a randomly generated 32-byte shared secret and this site's identity (site UUID, name, URL) to `https://<other-store>/wp-json/kitgenix-multistore-sync-for-woocommerce/v1/pair`. The receiving store only accepts this if it currently has a matching, unexpired, one-time pairing code.
* **Event delivery** (automatic, whenever synced data changes): a signed POST is sent to `https://<paired-store>/wp-json/kitgenix-multistore-sync-for-woocommerce/v1/events`, carrying the product, inventory, order, coupon, customer or review payload for that change – only for the data types you have enabled in Settings.
* **Health checks** (automatic, every 5 minutes): a signed POST to `/v1/health` confirms a paired store is reachable and compares WordPress/WooCommerce/PHP versions and Action Scheduler availability.
* **Inventory reconciliation** (automatic, hourly): a signed POST to `/v1/inventory/snapshot` retrieves a connected store's current stock so it can be compared against the authoritative ledger; any drift found is corrected with a further signed delivery.
* **Checkout-time stock validation** (on a Connected Store, only if enabled): a signed POST to `/v1/inventory/availability` on the Control Store confirms live stock before checkout completes.
* **Remote order actions** (admin-initiated, from wherever the order is being managed): signed POSTs to `/v1/orders/status`, `/v1/orders/note` and `/v1/orders/refund` update a remote order's status, add a note, or – only if "Allow remote gateway refunds" is explicitly enabled – issue a refund on the store where the order actually lives.
* **Network order numbering / My Account history** (only if those optional features are enabled): signed POSTs to `/v1/orders/next-number` and `/v1/orders/customer-history`.
* **Network Doctor** (admin-initiated, read-only): a signed POST to `/v1/diagnostics` reads the same local environment checks from each paired store, without changing any commerce data.
* **Media and document files**: when image sync (or, if enabled, Kitgenix Custom Tabs/Document Manager sync) brings in a file, the receiving store downloads it directly from the sending store's own media/attachment URL.

= What data can be exchanged =

Depending on which sync options you enable in Settings, the data exchanged between your own paired stores can include: product/variation content (names, descriptions, SKUs, prices, stock, images, taxonomy terms, and any custom meta keys you explicitly list); order snapshots (line items, billing/shipping address fields, totals, coupon codes, refunds); coupon definitions; customer records (off by default); and product reviews (off by default). No data is exchanged with any server other than the WooCommerce store(s) you have explicitly paired or connected yourself.

= Authentication and data protection =

Every request between paired stores carries an HMAC-SHA256 signature (covering the HTTP method, route, a timestamp, a single-use nonce, and a hash of the request body); the receiving store rejects anything unsigned, expired (more than 5 minutes old), replayed, or signed with the wrong secret. HTTPS is required for any production-to-production pairing – unencrypted HTTP is only ever accepted between two sites this plugin recognises as local/development environments, never for a connection involving a live production site. The shared connection secret itself is never stored in plaintext: it is encrypted at rest with AES-256-GCM, keyed from your own WordPress installation's `AUTH_KEY`/`SECURE_AUTH_KEY` security salts.

= WordPress.org plugin directory (unrelated to store sync) =

The "Kitgenix" hub screen bundled with this and other Kitgenix plugins calls the public WordPress.org Plugins API (`api.wordpress.org`) to display current active-install counts, star ratings, and banner/icon artwork for Kitgenix's own plugin listings. This request sends only the relevant plugin slugs, is cached for 24 hours, and has no connection to the multi-store synchronisation feature or to any of your store or commerce data. See the [WordPress.org API Manual](https://developer.wordpress.org/reference/functions/plugins_api/) and [WordPress.org Privacy Policy](https://wordpress.org/about/privacy/) for details of that endpoint.

== Frequently Asked Questions ==

= Does this plugin connect to any external/third-party service? =

No third-party or Kitgenix-operated sync service is ever contacted. The only outbound connections used for synchronisation are to the WooCommerce store(s) *you* explicitly pair or connect – sites you own or administer, identified by the URL you enter yourself. Nothing is sent anywhere until you pair at least one store, and HTTPS is required for any production (non-staging/local) connection. See "External Services" above for the full breakdown of every request the plugin makes.

= Can I sync stock between two WooCommerce stores? =

Yes – that's the core feature. Once two stores are paired, stock is kept as a single authoritative quantity/status that every store converges on, captured immediately from WooCommerce's own stock-change hooks and reconciled automatically once an hour in the background to correct any drift.

= Is synchronisation one-way or two-way? =

It depends on the data type. Products default to one-way, Control Store → Connected Stores (a bidirectional option is available). Stock is always kept as a single authoritative network value that every store converges on. Orders are indexed centrally on the Control Store from every connected store, with optional importing back as full WooCommerce orders. Coupons flow Control Store → Connected Stores. Customer sync (off by default) flows Connected Store → Control Store. Reviews (off by default) are shared network-wide.

= Does this sync in real time? =

Changes are captured immediately from the relevant WordPress/WooCommerce action (saving a product, a stock change, an order status update, and so on) and queued straight away. Delivery to each connected store then happens asynchronously through Action Scheduler (or WP-Cron as a fallback), typically within seconds, rather than as a synchronous call that blocks the page you're on.

= How do I connect two WooCommerce sites? =

Generate a one-time pairing code on the store that should be the Control Store (MultiStore → Stores), then enter that store's URL and the pairing code on the other store to connect it as a Connected Store. On a WordPress Multisite network, enable Native Multisite mode instead and every site is discovered and paired automatically – no manual pairing code needed.

= Is this secure? =

Every request between paired stores is signed with an HMAC-SHA256 signature and a short-lived timestamp/nonce (replay protection), HTTPS is required in production, and the shared connection secret is stored encrypted at rest (AES-256-GCM) using your own WordPress site's security keys – never in plaintext.

= Can I exclude specific products from syncing to specific stores? =

Yes. Each product can be limited to explicit target stores and/or store groups, and a product can be excluded from sync entirely. Both automatic hourly reconciliation and Bulk Operations respect these exclusions, so an excluded product is never "repaired" back onto a store it was deliberately kept off.

= What happens when a sync fails? =

A failed delivery is retried automatically with exponential backoff, up to a configurable number of attempts (default 8). If every attempt fails, the event is logged and recorded as an open conflict on the Conflicts screen, and the failure reason (invalid credentials, mapping missing, timeout, and so on) is shown in the Log with a plain-English explanation of whether it needs your attention.

= Are logs available? =

Yes. Every sync event, delivery attempt, retry, and authentication rejection is recorded in a searchable, paginated Log, and Network Doctor gives a one-screen view of local and remote connectivity health.

= How many stores can be connected? =

There's no fixed limit in the plugin itself – one Control Store can have any number of Connected Stores, whether paired individually or discovered automatically across a WordPress Multisite network.

= Is WooCommerce required on both stores? =

Yes. WooCommerce must be installed and active on every store you pair, including the Control Store and every Connected Store.

= Does this plugin talk to WooCommerce Square or Square's own servers? =

No. It reads the stock quantity that WooCommerce Square has already written into your WooCommerce product (via WordPress's normal product-save hooks) and distributes that number to your other connected stores. It never calls Square's API directly and never stores Square API credentials.

= Is this plugin compatible with WooCommerce HPOS (High-Performance Order Storage)? =

Yes, Kitgenix MultiStore Sync for WooCommerce declares HPOS compatibility and includes dedicated handling for order-driven stock restoration under HPOS.

= Does this plugin work with WordPress Multisite? =

Yes. Native WordPress Multisite mode discovers network sites and their Control Site automatically. Outside Multisite, it pairs independent, separately hosted WooCommerce installations as a Control Store and one or more Connected Stores instead.

= Can I set different prices for each connected store? =

Yes. Each connected store can have its own price rule – a percentage adjustment, a fixed amount, or an exchange-rate multiplier – with optional rounding or charm pricing, and configurable sale-price handling. Prices are always recalculated fresh from the source product on every sync, so a rule is never applied on top of a previous adjustment.

= What happens to my data when I uninstall or delete this plugin? =

By default, nothing is removed: the plugin's settings and roles are left in place, and all synced data (stores, mappings, events, inventory ledger, remote order snapshots, customers, conflicts, logs) is kept, so reinstalling picks back up where you left off. Your actual WooCommerce products and orders are never touched by this plugin, on uninstall or otherwise. To remove everything on uninstall, enable "Remove all MultiStore data on uninstall" under Settings → Permissions & Safety before deleting the plugin.

== Screenshots ==

1. The Stores screen, showing each connected store's live status alongside its Kitgenix MultiStore, WordPress and WooCommerce versions and last contact time.
2. Pairing a new store, generating a pairing code and encrypted shared secret to connect a Control Store to a Connected Store.
3. The Sync Centre's Bulk Operations overview.
4. Per-store pricing rules, configuring a percentage, fixed-amount or exchange-rate price adjustment with rounding and sale-price handling for a connected store.
5. The Settings screen's synchronisation options, toggling which data types sync (products, stock, orders, coupons, customers, reviews) and in which direction.
6. Network Doctor, reporting local and remote environment checks such as HTTPS, REST availability, Action Scheduler, HPOS status and queue health.

== Credits ==
Built with ❤︎ by @kitgenix - https://kitgenix.com

== Upgrade Notice ==

= 2.1.0 =
Version 2.1.0 is recommended for all sites.

== Changelog ==

= 2.1.0 (13 October 2026) =

* New: Added a manual "Clear log" button to the Log page for immediately wiping technical logs.
* New: Simplified log retention to a 7/14/30-day preset.
* New: Added a "Request a Feature" button to the Need Help? sidebar, linking directly to this plugin's page on the Kitgenix roadmap (https://roadmap.kitgenix.com).
* New: Added a "Request a Feature" banner below the plugin grid on the Kitgenix Hub page, linking to the shared Kitgenix roadmap.

= 1.0.0 (31 August 2026) =

* New: Optional synchronisation of Kitgenix Custom Tabs product tabs, and of any Kitgenix Document Manager document referenced by a [kitgenix_document_manager_document] shortcode inside a synced tab's content. Off by default; enable "Custom tabs & document synchronisation" in Settings, then allow the "Custom tabs" and "Custom tab documents" fields per store under Store Policies.
* Hardening: Product sync now compares incoming data against the live product before writing anything, so a delivery that matches the current state performs no WooCommerce writes at all - no save(), no attribute update, no post_modified change, and no WooCommerce attribute-lookup or variation-summary regeneration.
* Hardening: Product apply now performs at most one primary product save() per delivery instead of up to six (basic fields, attributes, images, downloads, linked products), each only when that group actually changed.
* Hardening: Variation sync now compares each variation individually and only calls save() on variations that changed; the parent variable product is only re-synced (WC_Product_Variable::sync()) when a variation was actually created, updated, or removed.
* Hardening: A pure stock quantity/status change no longer also queues a full product payload sync in addition to the dedicated inventory pathway; product configuration changes (manage_stock, backorders) still trigger a full sync as before.
* Hardening: Category, tag, brand and custom taxonomy assignments, and custom meta, are now only written when the resulting value actually differs from what is already stored.
* Hardening: An out-of-order product delivery (older revision arriving after a newer one was already applied) is now detected and skipped instead of overwriting newer data.
* Hardening: Outbound delivery scheduling now de-duplicates against an already-pending Action Scheduler action for the same event/store instead of always queuing a new one.
* New: Added granular per-store Initial Publish and Later Update field policies, allowing different synchronisation rules for the first product publish and subsequent updates.
* New: Added native WordPress Multisite discovery with automatic network credential handling.
* New: Added full product review and rating synchronisation between connected stores.
* New: Added synchronisation of category and attribute term descriptions and metadata, custom taxonomies and taxonomy metadata, and richer media metadata.
* New: Added Shared Network Media mode for WordPress Multisite environments while retaining local media copying as the default behaviour.
* New: Added searchable and paginated network-wide order management with bulk status actions and CSV/XLSX exports.
* New: Added optional Control Store WooCommerce order importing, including missing-product placeholder cloning when an ordered product does not yet exist locally.
* New: Added optional cross-store WooCommerce My Account order history so customers can view eligible purchases from across the connected store network.
* New: Added optional network-wide sequential WooCommerce order numbers.
* New: Added per-store pause and resume controls without requiring a store to be disconnected or removed.
* New: Added configurable administrative roles and capabilities for controlling access to MultiStore management features.
* New: Added REST-origin product synchronisation policies for controlling how product changes originating from the WordPress REST API are handled.
* New: Added WooCommerce Quick Edit and Bulk Edit controls for MultiStore product synchronisation settings.
* New: Added explicit integration adapters and compatibility status reporting for ATUM Inventory Management, WPML, Polylang, WooCommerce Cost of Goods, PDF invoice plugins, Price by User Role, Per Product Shipping, Custom Product Tabs, WP All Import, and Elementor.
* New: Added a Support tab with donation options, a "what your support funds" summary, and a "get involved" panel for reviews and plugin links. The plugin previously had no dedicated support or community area.
* New: Added a plain-English diagnostic reference table to the Logs tab explaining what each recorded error category means and whether administrator action is required, distinguishing routine self-resolving events such as duplicate delivery retries, clock drift, and temporary network failures from genuine issues such as invalid credentials, malformed data, and missing store mappings.
* New: Expanded per-store price rules with percentage adjustments, fixed-amount adjustments, and exchange-rate multipliers.
* New: Added optional price-rounding strategies for adjusted prices, including nearest whole number, nearest 0.50, and charm pricing ending in .95 or .99.
* New: Added configurable sale-price handling with options to apply the same price rule, preserve the original discount percentage, pass the source sale price through unchanged, or omit sale prices entirely.
* New: Added manual per-product/per-store price overrides that take precedence over the configured store-level pricing rule.
* New: Price adjustments are always recalculated from the original source price, preventing repeated synchronisation from compounding previous adjustments.
* New: The Stores screen now distinguishes five connection states – Connected, Warning, Offline, Authentication Failed, and Version Incompatible – instead of relying on a single active/offline state.
* New: Store connection cards now display each connected store's Kitgenix MultiStore version, WordPress version, WooCommerce version, store ID, and last contact time.
* New: Added a resumable Bulk Operations panel in the Sync Centre for full product synchronisation and full inventory reconciliation.
* New: Bulk Operations are processed in small batches to avoid PHP execution timeouts on large catalogues or store networks.
* New: Bulk Operations support dry runs, pause, resume, stop, and persisted progress reporting.
* New: Added WP-CLI bulk synchronisation with `wp kitgenix-multistore-sync-for-woocommerce bulk-sync`, including `--dry-run` and `--products=<ids>` support.
* New: Added WP-CLI bulk inventory reconciliation with `wp kitgenix-multistore-sync-for-woocommerce bulk-reconcile`, including `--dry-run` support and live progress reporting.
* New: The resumable Bulk Operations workflow replaces the previous single-request "Sync all products" and "Run reconciliation" actions, which could time out on large catalogues and provided no progress reporting or dry-run support.
* New: Added a dedicated "Remove all MultiStore data on uninstall" setting, disabled by default, providing a discoverable way to remove the plugin's own database tables and options when uninstalling.
* New: The existing `KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA` wp-config constant remains available for automated deployments and CI environments. Data removal remains opt-in, and WooCommerce products and orders are never deleted by the plugin.
* Improved: Aligned the entire admin interface with the shared Kitgenix design system, including the sticky topbar, Kitgenix Hub integration, card-based layouts, shared navigation, and consistent styling used across the Kitgenix plugin suite.
* Improved: The Log table is now searchable and paginated, with the Level column displayed using colour-coded status badges for faster diagnosis.
* Improved: Several previously untranslated strings on the Log tab are now fully translatable.
* Improved: Authentication rejections on receiving stores – including replayed requests, expired requests, and invalid signatures – are now recorded in the Log instead of only being returned as an HTTP response with no diagnostic trail.
* Improved: The Network Doctor queue-health check now considers only genuine delivery failures, preventing transient retries caused by timeouts, HTTP 5xx responses, or duplicate deliveries from incorrectly marking the synchronisation queue as unhealthy.
* Improved: Automatic hourly inventory reconciliation continues to run independently of the new resumable Bulk Operations system.
* Fix: The automation name field on the "Add inventory automation" form now receives the shared Kitgenix input styling.
* Fix: Added appropriate spacing between the inventory automation creation form and its submit button.
* Fix: The Log tab is now accessible directly from the plugin navigation. The page previously existed but had no navigation item linking to it.
* Fix: Two previously silent failures – Square integration status lookups and setting imported WooCommerce order dates – are now recorded in the Log instead of failing without diagnostic information.
* Fix: A refund processed with "Restock refunded items" enabled could restore WooCommerce stock locally without propagating that restoration to the authoritative network inventory ledger or connected stores.
* Fix: WooCommerce 11.0's automatic stock restoration when an order transitions to Failed status is now captured and propagated as an authoritative inventory change across the MultiStore network.
* Fix: The Control Store now always captures order-driven stock restorations as authoritative inventory changes regardless of the internal synchronisation context that triggered them, with duplicate-dispatch protection shared with the existing stock-change pathways.
* Fix: Inventory reconciliation could previously repair stock onto a store where the product had deliberately been excluded through disabled synchronisation or explicit store/group targeting rules. Both background reconciliation and Bulk Operations reconciliation now respect all product exclusions before applying repairs.
* Fix: Corrected a version-string mismatch where the plugin header and readme reported version 2.0.0 while the changelog and internal release gates still referenced 1.0.0.
* Fix: Removed a stale test-suite file reference left over from an earlier filename.
* Dev: Confirmed compatibility with WordPress 7.1 and WooCommerce 11.0.0, including review against WooCommerce 11.0 developer advisories.
* Dev: Confirmed the retired WooCommerce Product Editor beta APIs are not used by the plugin.
* Dev: Confirmed WooCommerce 11.0 failed-order stock restoration is handled correctly through the plugin's authoritative stock-change capture and network inventory synchronisation system.
