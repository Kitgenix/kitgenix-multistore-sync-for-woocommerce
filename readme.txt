=== Kitgenix MultiStore for WooCommerce ===
Contributors: kitgenix
Tags: woocommerce, multistore, stock sync, inventory, products
Requires at least: 6.8
Tested up to: 7.0.4
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later
Requires Plugins: woocommerce

Secure, queue-backed multi-store product, inventory, order, customer, coupon and review synchronisation for WooCommerce.

== Description ==

Kitgenix MultiStore connects independent WooCommerce stores or WordPress Multisite stores using an auditable, queue-backed sync engine.

Highlights:

* Paired independent stores and automatic Native WordPress Multisite discovery.
* HMAC signed REST requests, encrypted shared credentials, timestamp and replay protection.
* Persistent events, per-store delivery states, retries, conflicts and reconciliation.
* Product and variation sync with per-store percentage pricing.
* Granular per-store Initial Publish and Later Update field policies.
* Category descriptions/images/meta, attribute-term descriptions and image ALT/title/caption metadata.
* Configurable custom product taxonomies and custom metadata.
* Network product reviews and ratings (opt-in).
* Local-copy or Shared Network Media modes.
* Revisioned inventory ledger with WooCommerce Square bridge and checkout authority validation.
* Searchable central orders, bulk status changes and CSV/XLSX export.
* Optional Control-Store WooCommerce order importing and missing-product placeholder creation.
* Optional network-wide sequential order numbers.
* Optional read-only cross-store My Account order history.
* Coupon and optional customer snapshot sync.
* Store groups, pause/resume, staging protection and Network Doctor.
* Quick Edit/Bulk Edit MultiStore controls.
* Integrated compatibility layer for Rank Math, Yoast, WPML, Polylang, ATUM Multi-Inventory, WooCommerce Cost of Goods, PDF Invoices & Packing Slips, Price by User Role, Per Product Shipping, Custom Product Tabs/WP All Import, Elementor Pro and WooCommerce Square.
* Configurable role-based MultiStore administration.
* WooMultistore migration tools and WP-CLI commands.
* HPOS compatible order handling.

Riskier features such as remote gateway refunds, customer sync, review aggregation, shared remote media, imported duplicate orders and network order numbering are disabled by default.

== Installation ==

1. Install and activate WooCommerce.
2. Install Kitgenix MultiStore on every store.
3. For separate WordPress installations, select one Control Store and pair each Connected Store.
4. For WordPress Multisite, enable Native WordPress Multisite mode and choose the Control Site; network sites are discovered automatically.
5. Configure store pricing and field policies under MultiStore > Stores.
6. Run Network Doctor and staging acceptance tests before production rollout.

== Changelog ==

= 2.0.0 (22 August 2026) =
* Adds granular per-store Initial Publish and Later Update field policies.
* Adds Native WordPress Multisite discovery and automatic network credentials.
* Adds actual product review/rating synchronisation.
* Adds category/attribute term descriptions and metadata, custom taxonomy/meta sync and richer media metadata.
* Adds Shared Network Media mode while retaining local-copy mode as default.
* Adds searchable/paginated network orders, bulk status actions and CSV/XLSX exports.
* Adds optional Control-Store WooCommerce order import and missing-product placeholder cloning.
* Adds optional cross-store My Account history and network-wide sequential order numbers.
* Adds store pause/resume, configurable admin roles, REST-origin product policy and WooCommerce Quick/Bulk Edit controls.
* Adds explicit adapters/status for ATUM, WPML, Polylang, Cost of Goods, PDF Invoices, Price by User Role, Per Product Shipping, Custom Product Tabs/WP All Import and Elementor.
* Aligns the admin UI with the shared Kitgenix design system (topbar, hub page, card layout) used across all Kitgenix plugins.
* Fix: The automation name field on the "Add inventory automation" form now receives the shared input styling.
* Fix: Added spacing between the create-automation form and its submit button.
* New: Added a Support tab (donate options, "what your support funds" summary, and a "get involved" panel for reviews and plugin links) — the plugin previously had no donate/community surface at all.
* Fix: The Log tab is now reachable from the navigation menu (the page existed but had no menu link pointing to it).
* Improved: The Log table is now searchable, paginated, and shows the Level column as a colour-coded badge; several previously untranslated Log tab strings are now translatable.
* New: Added a reference table to the Logs tab explaining, in plain English, what each recorded error category means and whether it needs action — distinguishing routine, self-resolving events (a duplicate delivery retry, clock drift between stores, a temporary network blip) from genuine problems (bad credentials, malformed data, missing store mappings) that need attention.
* Improved: Authentication rejections on the receiving store (replay, expired, or a bad signature) are now recorded to the log instead of only being returned as an HTTP response with no diagnostic trail.
* Improved: The Network Doctor's queue-health check now looks only at genuine delivery failures, so transient retries (a timeout, a 5xx response, a duplicate delivery) no longer flag the sync queue as unhealthy.
* Fix: Two previously silent failures — a Square integration status lookup, and setting an imported order's date — are now recorded to the log instead of failing invisibly.

= 1.0.4 =
* Adds WooCommerce Square inventory fan-out from the Control Store to connected stores.

= 1.0.3 =
* Fixes automatic percentage pricing by finalising destination prices on the Control Store before transport.

= 1.0.2 =
* Adds per-store percentage price adjustments.

= 1.0.1 =
* Adds Local/LocalWP development connection support.

= 1.0.0 =
* Initial architecture.
