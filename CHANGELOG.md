# Changelog

## 2.1.0 – 13 October 2026

- **New:** Added a manual "Clear log" button to the Log page for immediately wiping technical logs.
- **New:** Simplified log retention to a 7/14/30-day preset.
- **New:** Added a "Request a Feature" button to the Need Help? sidebar, linking directly to this plugin's page on the Kitgenix roadmap (https://roadmap.kitgenix.com).
- **New:** Added a "Request a Feature" banner below the plugin grid on the Kitgenix Hub page, linking to the shared Kitgenix roadmap.

## 1.0.0 – 22 August 2026

- **New:** Optional synchronisation of Kitgenix Custom Tabs product tabs, and of any Kitgenix Document Manager document referenced by a `[kitgenix_document_manager_document]` shortcode inside a synced tab's content. Off by default; enable "Custom tabs & document synchronisation" in Settings, then allow the "Custom tabs" and "Custom tab documents" fields per store under Store Policies.
- **Hardening:** Product sync now compares incoming data against the live product before writing anything, so a delivery that matches the current state performs no WooCommerce writes at all - no `save()`, no attribute update, no `post_modified` change, and no WooCommerce attribute-lookup table rebuild or `wc_regenerate_attribute_variation_summaries` regeneration.
- **Hardening:** Product apply now performs at most one primary product `save()` per delivery instead of up to six (basic fields, attributes, images, downloads, linked products), each only when that group actually changed.
- **Hardening:** Variation sync now compares each variation individually and only calls `save()` on variations that changed; the parent variable product is only re-synced (`WC_Product_Variable::sync()`) when a variation was actually created, updated, or removed.
- **Hardening:** A pure stock quantity/status change no longer also queues a full product payload sync in addition to the dedicated inventory pathway; product configuration changes (`manage_stock`, backorders) still trigger a full sync as before.
- **Hardening:** Category, tag, brand and custom taxonomy assignments, and custom meta, are now only written when the resulting value actually differs from what is already stored.
- **Hardening:** An out-of-order product delivery (older revision arriving after a newer one was already applied) is now detected and skipped instead of overwriting newer data.
- **Hardening:** Outbound delivery scheduling now de-duplicates against an already-pending Action Scheduler action for the same event/store instead of always queuing a new one.
- **New:** Added granular per-store Initial Publish and Later Update field policies, allowing different synchronisation rules for the first product publish and subsequent updates.
- **New:** Added native WordPress Multisite discovery with automatic network credential handling.
- **New:** Added full product review and rating synchronisation between connected stores.
- **New:** Added synchronisation of category and attribute term descriptions and metadata, custom taxonomies and taxonomy metadata, and richer media metadata.
- **New:** Added Shared Network Media mode for WordPress Multisite environments while retaining local media copying as the default behaviour.
- **New:** Added searchable and paginated network-wide order management with bulk status actions and CSV/XLSX exports.
- **New:** Added optional Control Store WooCommerce order importing, including missing-product placeholder cloning when an ordered product does not yet exist locally.
- **New:** Added optional cross-store WooCommerce My Account order history so customers can view eligible purchases from across the connected store network.
- **New:** Added optional network-wide sequential WooCommerce order numbers.
- **New:** Added per-store pause and resume controls without requiring a store to be disconnected or removed.
- **New:** Added configurable administrative roles and capabilities for controlling access to MultiStore management features.
- **New:** Added REST-origin product synchronisation policies for controlling how product changes originating from the WordPress REST API are handled.
- **New:** Added WooCommerce Quick Edit and Bulk Edit controls for MultiStore product synchronisation settings.
- **New:** Added explicit integration adapters and compatibility status reporting for ATUM Inventory Management, WPML, Polylang, WooCommerce Cost of Goods, PDF invoice plugins, Price by User Role, Per Product Shipping, Custom Product Tabs, WP All Import, and Elementor.
- **New:** Added a Support tab with donation options, a "what your support funds" summary, and a "get involved" panel for reviews and plugin links. The plugin previously had no dedicated support or community area.
- **New:** Added a plain-English diagnostic reference table to the Logs tab explaining what each recorded error category means and whether administrator action is required, distinguishing routine self-resolving events such as duplicate delivery retries, clock drift, and temporary network failures from genuine issues such as invalid credentials, malformed data, and missing store mappings.
- **New:** Expanded per-store price rules with percentage adjustments, fixed-amount adjustments, and exchange-rate multipliers.
- **New:** Added optional price-rounding strategies for adjusted prices, including nearest whole number, nearest 0.50, and charm pricing ending in .95 or .99.
- **New:** Added configurable sale-price handling with options to apply the same price rule, preserve the original discount percentage, pass the source sale price through unchanged, or omit sale prices entirely.
- **New:** Added manual per-product/per-store price overrides that take precedence over the configured store-level pricing rule.
- **New:** Price adjustments are always recalculated from the original source price, preventing repeated synchronisation from compounding previous adjustments.
- **New:** The Stores screen now distinguishes five connection states – Connected, Warning, Offline, Authentication Failed, and Version Incompatible – instead of relying on a single active/offline state.
- **New:** Store connection cards now display each connected store's Kitgenix MultiStore version, WordPress version, WooCommerce version, store ID, and last contact time.
- **New:** Added a resumable Bulk Operations panel in the Sync Centre for full product synchronisation and full inventory reconciliation.
- **New:** Bulk Operations are processed in small batches to avoid PHP execution timeouts on large catalogues or store networks.
- **New:** Bulk Operations support dry runs, pause, resume, stop, and persisted progress reporting.
- **New:** Added WP-CLI bulk synchronisation with `wp kitgenix-multistore-sync-for-woocommerce bulk-sync`, including `--dry-run` and `--products=<ids>` support.
- **New:** Added WP-CLI bulk inventory reconciliation with `wp kitgenix-multistore-sync-for-woocommerce bulk-reconcile`, including `--dry-run` support and live progress reporting.
- **New:** The resumable Bulk Operations workflow replaces the previous single-request "Sync all products" and "Run reconciliation" actions, which could time out on large catalogues and provided no progress reporting or dry-run support.
- **New:** Added a dedicated "Remove all MultiStore data on uninstall" setting, disabled by default, providing a discoverable way to remove the plugin's own database tables and options when uninstalling.
- **New:** The existing `KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA` wp-config constant remains available for automated deployments and CI environments. Data removal remains opt-in, and WooCommerce products and orders are never deleted by the plugin.
- **Improved:** Aligned the entire admin interface with the shared Kitgenix design system, including the sticky topbar, Kitgenix Hub integration, card-based layouts, shared navigation, and consistent styling used across the Kitgenix plugin suite.
- **Improved:** The Log table is now searchable and paginated, with the Level column displayed using colour-coded status badges for faster diagnosis.
- **Improved:** Several previously untranslated strings on the Log tab are now fully translatable.
- **Improved:** Authentication rejections on receiving stores – including replayed requests, expired requests, and invalid signatures – are now recorded in the Log instead of only being returned as an HTTP response with no diagnostic trail.
- **Improved:** The Network Doctor queue-health check now considers only genuine delivery failures, preventing transient retries caused by timeouts, HTTP 5xx responses, or duplicate deliveries from incorrectly marking the synchronisation queue as unhealthy.
- **Improved:** Automatic hourly inventory reconciliation continues to run independently of the new resumable Bulk Operations system.
- **Fix:** The automation name field on the "Add inventory automation" form now receives the shared Kitgenix input styling.
- **Fix:** Added appropriate spacing between the inventory automation creation form and its submit button.
- **Fix:** The Log tab is now accessible directly from the plugin navigation. The page previously existed but had no navigation item linking to it.
- **Fix:** Two previously silent failures – Square integration status lookups and setting imported WooCommerce order dates – are now recorded in the Log instead of failing without diagnostic information.
- **Fix:** A refund processed with "Restock refunded items" enabled could restore WooCommerce stock locally without propagating that restoration to the authoritative network inventory ledger or connected stores.
- **Fix:** WooCommerce 11.0's automatic stock restoration when an order transitions to Failed status is now captured and propagated as an authoritative inventory change across the MultiStore network.
- **Fix:** The Control Store now always captures order-driven stock restorations as authoritative inventory changes regardless of the internal synchronisation context that triggered them, with duplicate-dispatch protection shared with the existing stock-change pathways.
- **Fix:** Inventory reconciliation could previously repair stock onto a store where the product had deliberately been excluded through disabled synchronisation or explicit store/group targeting rules. Both background reconciliation and Bulk Operations reconciliation now respect all product exclusions before applying repairs.
- **Fix:** Corrected a version-string mismatch where the plugin header and readme reported version 2.0.0 while the changelog and internal release gates still referenced 1.0.0.
- **Fix:** Removed a stale test-suite file reference left over from an earlier filename.
- **Dev:** Confirmed compatibility with WordPress 7.1 and WooCommerce 11.0.0, including review against WooCommerce 11.0 developer advisories.
- **Dev:** Confirmed the retired WooCommerce Product Editor beta APIs are not used by the plugin.
- **Dev:** Confirmed WooCommerce 11.0 failed-order stock restoration is handled correctly through the plugin's authoritative stock-change capture and network inventory synchronisation system.