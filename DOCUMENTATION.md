# Kitgenix MultiStore Sync for WooCommerce

## Overview

**Plugin Name:** Kitgenix MultiStore Sync for WooCommerce
**Plugin URI / Author URI:** https://kitgenix.com/
**Main file:** `kitgenix-multistore-sync-for-woocommerce.php`
**Version:** 2.1.0 (`KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION`)
**DB schema version:** 1.0.0 (`KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_DB_VERSION`)
**Text Domain:** `kitgenix-multistore-sync-for-woocommerce`
**Domain Path:** `/languages`
**Requires WordPress:** 6.8+ (tested to 7.1)
**Requires PHP:** 8.1
**Requires Plugins:** `woocommerce` (declared via the `Requires Plugins` header)
**WC requires at least / tested up to:** 10.0 / 11.0.0
**Network:** `true` (the plugin header declares multisite-capable; it can be network-activated)
**License:** GPL-2.0-or-later

From the plugin file header's Description: *"Secure, queue-backed multi-store product, inventory, order, customer and coupon synchronisation for WooCommerce."*

The plugin connects two or more independently hosted WooCommerce stores (or subsites of one WordPress Multisite network) into a **Control Store / Connected Store** topology. One store is authoritative ("Control"); every other paired store ("Connected") exchanges signed HTTP events with it to keep products, inventory, orders, coupons, customers and reviews in sync. There is no Kitgenix-hosted relay service — all traffic goes directly, store-to-store, over HTTPS (or HTTP only between recognised local/dev hosts).

The plugin also renders a small shared "Kitgenix Hub" top-level admin menu (`kitgenix_ensure_admin_menu()` in the main file) that is common infrastructure across the whole Kitgenix plugin suite — it is not specific to MultiStore Sync and simply lists other Kitgenix plugins (install/activate/open/review) with WordPress.org metadata cached for 24 hours via transients.

## Architecture

### Bootstrap sequence

1. `kitgenix-multistore-sync-for-woocommerce.php` (plugin root) is loaded by WordPress.
2. It defines constants (`..._VERSION`, `..._DB_VERSION`, `..._FILE`, `..._DIR`, `..._URL`, `..._BASENAME`), declares the shared Kitgenix Hub helper functions (`kitgenix_get_admin_menu_icon()`, `kitgenix_ensure_admin_menu()`, `kitgenix_render_admin_page()`, three `kitgenix_hub_get_wporg_*()` WP.org metadata fetchers with transient caching), and hooks `admin_menu` (priority 5) to register the shared top-level "Kitgenix" menu page.
3. It registers a **custom PSR-4-like autoloader** via `spl_autoload_register()`: any class under namespace `Kitgenix\MultiStore\` maps to `src/<RelativeNamespace>.php` (backslashes replaced with `/`). This is not a Composer-generated PSR-4 loader — it is a small closure that does the same thing manually.
4. `register_activation_hook()` → `Kitgenix\MultiStore\Database\Schema::activate()`; `register_deactivation_hook()` → `Schema::deactivate()`.
5. On `before_woocommerce_init`, declares HPOS (`custom_order_tables`) feature compatibility via `FeaturesUtil::declare_compatibility()`.
6. On `plugins_loaded` (priority 20): if WooCommerce is not active, shows an admin notice and stops. If PHP < 8.1, silently stops. Otherwise calls `Kitgenix\MultiStore\Plugin::instance()->boot()` inside a `try/catch(\Throwable)` — a boot failure is logged via PHP's `error_log()` (the plugin's own DB logger isn't available yet) and shown as an admin notice, but does not fatal the site.

### `Plugin::boot()` (src/Plugin.php)

A single method performs manual dependency construction (no DI container) for the entire plugin:

- Runs `Schema::install()` if the stored `kitgenix_multistore_sync_for_woocommerce_db_version` option doesn't match the current constant (auto-migration on every relevant request, idempotent via `dbDelta()`).
- Hooks `Privacy::hooks()` and `wp_initialize_site` (new multisite subsite) → `Schema::install()` on that subsite.
- Instantiates every Repository, Support, Http, Sync, Orders, Pricing, Media, Integration, Multisite, Diagnostics, Automation and Admin class, wiring constructor dependencies by hand.
- Stores every service in `$this->services` (an associative array) — `compact()` is used to build it — and exposes them via `Plugin::get(string $id)`.
- Calls `->hooks()` on every service that has one, registers REST routes (`Routes::hooks()`), the Product-data panel, the Admin screen, and (if `WP_CLI` is defined) `Commands::register($this)`.
- Fires `do_action('kitgenix_multistore_loaded', $this)` at the very end — the plugin's own "fully booted" extension point.

### Namespace / autoloading

Custom, not Composer PSR-4: namespace prefix `Kitgenix\MultiStore\` → directory `src/`. E.g. `Kitgenix\MultiStore\Sync\ProductSync` → `src/Sync/ProductSync.php`. No `vendor/autoload.php`, no Composer dependencies at all — the plugin has zero third-party PHP libraries.

### src/ folder layout

| Folder | Responsibility |
|---|---|
| `Database` | Custom table schema (`dbDelta`-based install/upgrade). |
| `Repository` | Raw `$wpdb` data-access classes, one per custom table/entity. |
| `Privacy` | GDPR personal-data exporter/eraser registration. |
| `Integration` | Adapters for third-party plugins (WPML, Polylang, Rank Math, Square, Custom Tabs, etc.) and WP All Import bridge. |
| `Admin` | All wp-admin UI: the MultiStore admin app, product-edit panel, quick/bulk-edit fields. |
| `Cli` | WP-CLI command registration. |
| `Support` | Cross-cutting helpers: capabilities, crypto, environment/SSRF guards, error catalog, logging, revision counter, settings, site UUID, factory reset. |
| `Http` | Outbound HTTP client + HMAC request signer used for store-to-store calls. |
| `Diagnostics` | "Network Doctor" local + remote environment/health checks. |
| `Automation` | Simple stock-threshold → product-status automation engine. |
| `Orders` | Order sync/import/export, sequential order numbers, My Account cross-store history. |
| `Sync` | The core sync engine: dispatcher, queue, event processor, per-entity sync classes, bulk jobs, reconciliation, store policy. |
| `Multisite` | WordPress Multisite "Native Network" auto-discovery/pairing. |
| `Migration` | One-off importer for the third-party "WooMultistore" plugin's legacy meta. |
| `Pricing` | Per-store price-rule calculator (percentage/fixed/exchange, rounding, sale-price modes). |
| `Rest` | REST API route registration + request authentication (`Auth`). |
| `Media` | Image/attachment sync (sideload or "shared remote" lightweight attachment). |

### File-by-file table

| File | Class | Responsibility |
|---|---|---|
| `src/Database/Schema.php` | `Schema` | Creates/upgrades all 12 custom tables via `dbDelta()`; handles activation (incl. network-wide), deactivation (clears cron/Action Scheduler), and a table-rename self-heal for a table name that used to exceed MySQL's 64-char identifier limit. |
| `src/Repository/AutomationRepository.php` | `AutomationRepository` | CRUD for the `automations` table (stock-threshold rules). |
| `src/Repository/ConflictRepository.php` | `ConflictRepository` | Records/queries/resolves sync conflicts; `genuine_open_count()` excludes recoverable/transient failure types. |
| `src/Repository/CustomerRepository.php` | `CustomerRepository` | Upsert/count of synced customer snapshots (control store only). |
| `src/Repository/EventRepository.php` | `EventRepository` | CRUD for `events` and `deliveries` tables — the heart of the event-sourced queue. |
| `src/Repository/InventoryRepository.php` | `InventoryRepository` | Authoritative inventory state + append-only ledger (`inv_ledger`), with transactional `apply_set()`/`apply_delta()` idempotent on `event_uuid`. |
| `src/Repository/MappingRepository.php` | `MappingRepository` | Maps a stable "network ID" (UUID) to a local WordPress object ID per entity type/store; `network_id()` allocates a new UUID on first use. |
| `src/Repository/OrderRepository.php` | `OrderRepository` | CRUD + filtered/paginated `query()` over indexed `remote_orders` snapshots; per-currency 30-day totals. |
| `src/Repository/StoreRepository.php` | `StoreRepository` | CRUD for paired stores; encrypts/decrypts the shared secret via `Crypto`; validates URL/HTTPS via `Environment`; defines the `STATUSES` connection-health enum. |
| `src/Privacy/Privacy.php` | `Privacy` | Registers a `wp_privacy_personal_data_exporters`/`erasers` pair covering `remote_orders` and `customers` tables. |
| `src/Integration/Registry.php` | `Registry` | Adapters for Polylang/WPML language, "Per Product Shipping" 3rd-party table, Kitgenix Custom Tabs + Document Manager sibling-plugin bridges, PDF Invoices document links; `statuses()` powers the Integrations admin tab. |
| `src/Integration/ImportBridge.php` | `ImportBridge` | Hooks WP All Import's `pmxi_saved_post` to enqueue a product sync after an import run. |
| `src/Admin/Admin.php` | `Admin` | The entire MultiStore admin application: menu, asset enqueuing, 16 admin "views" (dashboard/stores/products/inventory/orders/customers/coupons/sync/automations/analytics/integrations/doctor/migration/logs/settings/support), and 27 `admin_post_*` action handlers. |
| `src/Admin/BulkProductTools.php` | `BulkProductTools` | Adds a MultiStore Quick-Edit/Bulk-Edit dropdown and a WooCommerce product-list column showing sync status. |
| `src/Admin/ProductPanel.php` | `ProductPanel` | Adds a "MultiStore" WooCommerce product-data tab/panel for per-product sync toggle, target stores/groups, price overrides, and (on a Connected Store) field-level local overrides. |
| `src/Cli/Commands.php` | `Commands` | Registers `wp kitgenix-multistore-sync-for-woocommerce {status,reconcile,sync-product,doctor,index-orders,bulk-sync,bulk-reconcile}`. |
| `src/Support/Access.php` | `Access` | Defines capability `kitgenix_multistore_sync_for_woocommerce_manage_multistore`; syncs it onto allowed roles on `admin_init`; `can_manage()` gate used everywhere. |
| `src/Support/Context.php` | `Context` | A re-entrant "remote" flag (`enter_remote()`/`leave_remote()`/`is_remote()`/`remote(callable)`) used to suppress outbound-sync hooks while *applying* an incoming change, preventing infinite sync loops. |
| `src/Support/Crypto.php` | `Crypto` | AES-256-GCM encrypt/decrypt of the store secret, keyed from `AUTH_KEY`/`SECURE_AUTH_KEY` + a fixed salt string, via `hash('sha256', …, true)`. |
| `src/Support/Environment.php` | `Environment` | Non-production detection, local/private-IP URL detection, store-URL validation (HTTPS-required + SSRF-aware via `wp_http_validate_url()`), SSL-verify relaxation and a scoped `http_request_host_is_external`/`http_request_args` filter pair for LocalWP-style dev hosts. |
| `src/Support/ErrorCatalog.php` | `ErrorCatalog` | Maps every `WP_Error` code the plugin produces (and generic `http_*`/HTTP-status codes) to a category (`auth-rejected`, `auth-replay-or-clock-skew`, `config-error`, `pairing-rate-limited`, `network-error`, `remote-conflict`, `remote-rejected`, `topology-mismatch`, `malformed-payload`, `mapping-missing`, `validation-error`, `application-error`) and a human-readable note; `is_recoverable()` flags transient categories. |
| `src/Support/Logger.php` | `Logger` | Writes to the custom `logs` table; mirrors `error`/`critical` entries into `wc_get_logger()`; `cleanup(days)`/`clear()`. |
| `src/Support/Reset.php` | `Reset` | `factory_reset()` — the same destructive wipe as the opt-in uninstall path, but callable on demand from Settings, followed by an immediate `Schema::install()` so the site keeps working. |
| `src/Support/Revision.php` | `Revision` | Atomic, cross-process monotonically-increasing counter using MySQL's `LAST_INSERT_ID(expr)` trick against a single `wp_options` row (no native WP API for atomic increment). |
| `src/Support/Settings.php` | `Settings` | Single-option (`kitgenix_multistore_sync_for_woocommerce_settings`) settings store with full defaults, plus dedicated sanitizers for store groups, per-store pricing rules, and per-store field policies. |
| `src/Support/Uuid.php` | `Uuid` | `make()` (wraps `wp_generate_uuid4()`) and `site()` (lazily creates/persists this site's own UUID in `kitgenix_multistore_sync_for_woocommerce_site_uuid`). |
| `src/Http/Client.php` | `Client` | Builds and sends signed outbound REST requests to a paired store (`request()`), plus the initial `pair()` handshake call. |
| `src/Http/Signer.php` | `Signer` | HMAC-SHA256 canonicalisation/signing/verification of `METHOD\nROUTE\nTIMESTAMP\nNONCE\nsha256(body)`. |
| `src/Diagnostics/NetworkDoctor.php` | `NetworkDoctor` | `local_checks()` (WP/WC/PHP versions, HTTPS, REST, OpenSSL, Action Scheduler, HPOS, queue-failure count, environment, DB table existence) and `network_checks()` (same, fetched remotely from every paired store via `/diagnostics`); `score()` computes a percentage. |
| `src/Automation/Engine.php` | `Engine` | Listens to `kitgenix_multistore/inventory_authoritative_changed`; evaluates `stock_below`/`stock_above` rules and sets a mapped product's status (`publish`/`draft`/`private`). |
| `src/Migration/WooMultistoreImporter.php` | `WooMultistoreImporter` | Detects/imports legacy `_woonet_*` postmeta from the third-party "WooMultistore" plugin, converting relationships into stable Kitgenix network-ID mappings. |
| `src/Sync/BulkJob.php` | `BulkJob` | Resumable, chunked, progress-persisted "product_sync" / "reconcile" jobs (option-backed state machine, driven by Action Scheduler/WP-Cron ticks or a tight WP-CLI loop); powers both the admin Bulk Operations panel and `wp … bulk-sync`/`bulk-reconcile`. |
| `src/Sync/CouponSync.php` | `CouponSync` | Control-store coupon create/update/trash → dispatch; receive/apply on a connected store; SKU/category resolution helpers. |
| `src/Sync/CustomerSync.php` | `CustomerSync` | Connected-store customer create/update → dispatch to control; control-side `receive()` upserts into `CustomerRepository`. |
| `src/Sync/Dispatcher.php` | `Dispatcher` | Creates an `events` row and fans it out to target store UUIDs via `Queue::enqueue()`; computes default targets (`targets_for()`) based on network role. |
| `src/Sync/EventProcessor.php` | `EventProcessor` | Receives an inbound `/events` POST, validates payload shape/source/topology, de-duplicates/handles races, dispatches to the correct per-entity handler by `event_type`, records conflicts on failure, and (control store, bidirectional mode) re-fans-out an accepted `product_upsert`. |
| `src/Sync/InventorySync.php` | `InventorySync` | Captures WooCommerce stock hooks (`*_before_set_stock`/`*_set_stock`, `woocommerce_product_object_updated_props`, `woocommerce_restore_order_stock`), computes authoritative sets/deltas, dispatches `inventory_set`/`inventory_delta`, applies inbound inventory events, and validates cart stock at checkout against the control store. |
| `src/Sync/OrderSync.php` | `OrderSync` | Batches (`shutdown`-flushed) order create/update → payload build → dispatch/upsert; remote status/note/refund action helpers with local/remote branching; 90-day backfill scheduler. |
| `src/Sync/ProductApplier.php` | `ProductApplier` | The core "apply an incoming product payload to a local WooCommerce product" engine — diff-aware (never calls a WooCommerce setter unless the value actually differs), revision-guarded against out-of-order delivery, handles type conversion, variations, attributes, images, downloads, linked products, terms/taxonomies, meta, and reviews. |
| `src/Sync/ProductPayload.php` | `ProductPayload` | Builds the full outbound product payload from a `WC_Product` (all fields, terms, attributes, images, downloads, meta, reviews, variations). |
| `src/Sync/ProductSync.php` | `ProductSync` | Product/variation save/trash hooks → queue → `sync_now()` (build payload, dispatch, seed inventory); computes per-product store targeting (explicit stores/groups vs. "all"). |
| `src/Sync/Queue.php` | `Queue` | Delivers one event to one store (`process()`), handles retry/backoff scheduling, records cron/Action Scheduler recurring health-check and cleanup jobs, and computes per-store health status from a `/health` response. |
| `src/Sync/Reconciler.php` | `Reconciler` | Hourly background inventory drift detection/repair by comparing the authoritative ledger against each connected store's live snapshot. |
| `src/Sync/ReviewSync.php` | `ReviewSync` | Comment-hook-driven review create/edit/status/delete → dispatch; inbound apply/delete with product-mapping resolution. |
| `src/Sync/StorePolicy.php` | `StorePolicy` | Resolves, per delivery, which product fields a specific store is allowed to receive (publish vs. update mode) into a `_sync_policy.allowed_fields` payload marker; `allows()` is the read-side check used throughout `ProductApplier`/`Registry`. |
| `src/Orders/MyAccount.php` | `MyAccount` | Renders a read-only "Orders from our other stores" table on WooCommerce My Account, sourced locally (control) or via `/orders/customer-history` (connected). |
| `src/Orders/OrderExporter.php` | `OrderExporter` | Streams a CSV or hand-built XLSX (raw OOXML via `ZipArchive`, no library) export of queried `remote_orders` rows, with formula-injection-safe cell escaping. |
| `src/Orders/OrderImporter.php` | `OrderImporter` | Optional "import as real WooCommerce order" path: creates/updates a tagged, stock/email-suppressed local order from a received order snapshot; can clone missing-SKU line items as hidden draft placeholder products. |
| `src/Orders/OrderNumbers.php` | `OrderNumbers` | Optional network-sequential order numbers: control store allocates from a DB sequence (`sequences` table, `FOR UPDATE`-safe), connected stores request one over REST; retries with backoff via Action Scheduler/cron on failure. |
| `src/Pricing/PriceCalculator.php` | `PriceCalculator` | Pure, stateless price-rule math: percentage/fixed/exchange-multiplier adjustment, 5 rounding strategies, 4 sale-price handling modes; `apply_for_store()` is the single entry point used for both regular product and every variation. |
| `src/Media/NetworkMedia.php` | `NetworkMedia` | `import()` resolves an incoming image/asset reference to a local attachment ID — either by sideloading a real local copy or (in "shared_remote" mode) creating a lightweight attachment whose URL filters (`wp_get_attachment_url`, `wp_get_attachment_image_src`, `wp_calculate_image_srcset`) resolve to the remote source. |
| `src/Rest/Auth.php` | `Auth` | Verifies the 4 `X-Kitgenix-*` signing headers, timestamp window (±300s), nonce replay (10-min transient), and HMAC signature on every incoming request except `/pair`. |
| `src/Rest/Routes.php` | `Routes` | Registers and implements all 11 REST endpoints under `kitgenix-multistore-sync-for-woocommerce/v1`. |
| `kitgenix-multistore-sync-for-woocommerce.php` | (procedural) | Plugin header, constants, shared Kitgenix Hub menu/page, autoloader, activation/deactivation hooks, HPOS compat declaration, safe boot. |
| `uninstall.php` | (procedural) | Opt-in full data removal on plugin deletion (see Uninstall section). |

### Overall sync topology

**Hub-and-spoke (Control Store ↔ Connected Stores), not peer-to-peer.** Exactly one store per network holds `role = 'control'`; every other paired store holds `role = 'connected'`. A Connected Store only ever talks to its Control Store (`StoreRepository::control()`); it never talks directly to another Connected Store. The Control Store fans events out to every active Connected Store (`Dispatcher::targets_for()`).

Two ways a topology is established:
1. **Paired mode** (default, `network_mode = 'paired'`): independent WordPress installs, connected manually via a one-time pairing code (`Http\Client::pair()` / `Rest\Routes::pair()`).
2. **Native WordPress Multisite mode** (`network_mode = 'native_multisite'`): `Multisite\NativeNetwork::discover()` auto-discovers every subsite on `init`, assigns roles based on a configured control `blog_id`, and shares one generated 32-byte hex secret across the whole network (stored as a network/site option, not per-connection).

Order/customer data is **strictly one-directional inward**: Connected → Control only (topology-checked in `EventProcessor::authorize_event()`). Products/coupons/reviews default to Control → Connected, with a `bidirectional` value of the `product_direction` setting and a `network`-mode option for reviews. Inventory is a single **authoritative value** every store converges on (not "whoever changed it last wins" — see Sync Engine section).

## Subsystems

### Database

`src/Database/Schema.php` is the sole schema owner. `Schema::install()` (called on activation, on version mismatch during `Plugin::boot()`, and on every new multisite subsite) builds/upgrades 12 tables via `dbDelta()`, all named `{$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_{name}`. See **Data Storage** for full schemas.

Versioning: a single `kitgenix_multistore_sync_for_woocommerce_db_version` option is compared against the `KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_DB_VERSION` constant (currently `1.0.0`) on every `Plugin::boot()`; a mismatch re-runs `Schema::install()` (which is idempotent via `dbDelta`). There is no per-migration-step versioning system — the whole schema is declared fresh each time and `dbDelta()` diffs it.

Notable self-heal: `self_heal_ledger_table_rename()` renames a pre-1.0.0 `inventory_ledger` table to the shorter `inv_ledger` in place (metadata-only `RENAME TABLE`) because the old full name (prefix + `kitgenix_multistore_sync_for_woocommerce_inventory_ledger`) can exceed MySQL's 64-character identifier limit on sites with a long/hardened `$wpdb->prefix`.

`Schema::deactivate()` clears the 3 WP-Cron fallback hooks and (if Action Scheduler is present) unschedules the 3 recurring Action Scheduler actions. Deactivation never drops data.

### Repository

Eight classes, one per entity, each a thin, hand-written `$wpdb` wrapper (no ORM/query builder). Every class disables the relevant PHPCS direct-DB-query sniffs with a documented justification (fixed, developer-controlled table name; all values go through `$wpdb->prepare()`). Key patterns:

- **`StoreRepository`** — `STATUSES` const enumerates `active`, `warning`, `offline`, `auth_failed`, `version_incompatible`, `disabled`, `unknown`. `save()` validates UUID/URL/HTTPS before persisting and encrypts the secret via `Crypto` only when a new one is supplied (existing secret preserved otherwise).
- **`MappingRepository`** — `network_id()` is the single place a new stable UUID is minted for a local object (product/variation/coupon get it stashed in `_kitgenix_multistore_sync_for_woocommerce_network_id` postmeta too, as a fast local lookup independent of the mapping table).
- **`InventoryRepository`** — the only repository using explicit `START TRANSACTION`/`COMMIT`/`ROLLBACK` and `SELECT … FOR UPDATE`. `apply_set()`/`apply_delta()` are idempotent per `event_uuid` (checked against the ledger before writing) and always append a ledger row alongside updating the authoritative row.
- **`EventRepository`** — owns both `events` and `deliveries` tables; `mark_delivery()` auto-increments `attempts` only when transitioning to `processing`.
- **`OrderRepository::query()`** — the only repository with a general-purpose filtered/paginated query builder (search, status, store, email, date range).

### Privacy

`src/Privacy/Privacy.php` registers exactly one exporter and one eraser, both under key `kitgenix-multistore-sync-for-woocommerce`, hooked via `wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers`.

- **Export** (`export()`): looks up `remote_orders` by `customer_email` and `customers` by `email`, returns each row's fields as a personal-data group (`kitgenix-multistore-sync-for-woocommerce-orders` / `-customers`). Always reports `done => true` (no pagination — table scans are direct email-indexed lookups).
- **Erase** (`erase()`): blanks `customer_email`/`customer_name`/`payload` on matching `remote_orders` rows (kept for aggregate reporting, PII stripped) and hard-`DELETE`s matching `customers` rows.

No exporter/eraser is registered for the `stores`, `mappings`, `events`, `conflicts`, `automations`, or `logs` tables — those are not considered to hold end-customer personal data (store operator/administrative data instead).

### Integration

Two classes:

- **`Registry`** (`src/Integration/Registry.php`) — the main integration hub, hooked on `kitgenix_multistore/product/meta_keys` (filter), `kitgenix_multistore/product/payload` (filter), `kitgenix_multistore/product_applied` (action), `kitgenix_multistore/order/payload` (filter), and WooCommerce PDF Invoices' `wpo_wcpdf_document_link_access_type` (filter). Provides:
  - Extra whitelisted product meta keys for ATUM Multi-Inventory, WooCommerce Cost of Goods, Per Product Shipping, YIKES Custom Product Tabs, Elementor, GTIN plugin, Kitgenix Custom Tabs, and (dynamically, per role) "Price by User Role" per-role meta keys.
  - Polylang/WPML language read on payload build, write on apply.
  - A direct third-party-table (`innozilla_per_product_shipping_rules_woo`) read/write bridge for the "Per Product Shipping" plugin.
  - Kitgenix Custom Tabs sync (through that sibling plugin's own `Settings::sanitize_tabs_rows()`), including discovering and syncing Kitgenix Document Manager documents referenced by `[kitgenix_document_manager_document]`/`[kitgenix_document_manager_link]` shortcodes inside a synced tab, with a dedicated `sideload_document_file()` (SSRF-checked via `Environment::validate_store_url()`, destination's own allowed-mimes list, versioned via Document Manager's own history API when enabled).
  - PDF Invoices & Packing Slips: generates a signed, transient-backed token URL per order/document type so a customer can view a cross-store PDF without authentication; `pdf_access_type()` grants `'full'` access when a valid token is present in `$_GET['kitgenix_multistore']`.
  - `statuses()` — detection/integration status table for 15 named third-party plugins/extensions, consumed by the Integrations admin view.
- **`ImportBridge`** (`src/Integration/ImportBridge.php`) — one hook (`pmxi_saved_post`, WP All Import) that enqueues a product sync job after an import-driven product save (respecting `sync_products`/`product_direction` settings and `Context::is_remote()`).

### Admin

All admin UI lives in `src/Admin/`. See **Admin Interface** below for the full menu/view/field breakdown.

### Cli

`src/Cli/Commands.php` registers 7 WP-CLI commands. See **WP-CLI Commands** below.

### Support

Ten small, focused utility classes — see the file table above. Notable design choices: `Crypto` derives its AES key from WordPress's own security salts (no separate key management/storage); `Context` is a depth-counter (not a boolean) so nested "remote" operations compose safely; `Revision` uses a raw SQL `LAST_INSERT_ID()` trick because WordPress's Options API has no atomic increment primitive; `ErrorCatalog` is a large static lookup table that turns every internal `WP_Error` code into an admin-friendly category + explanation, distinguishing "needs attention" from "self-resolving."

### Http

`src/Http/Client.php` + `src/Http/Signer.php`. See **Authentication & Site Linking** below for full detail — this is the transport layer for every outbound store-to-store call.

### Diagnostics

`src/Diagnostics/NetworkDoctor.php`. `local_checks()` returns an associative array of `{ok: bool, value: string}` per check (WordPress version ≥6.8, WooCommerce ≥10.0 present, PHP ≥8.1, HTTPS home URL, non-empty permalink structure, OpenSSL available, Action Scheduler available, HPOS status/report-only "ok", `genuine_open_count()` conflicts == 0, environment type, `events` table exists). `network_checks()` additionally calls `/diagnostics` on every active paired store. `score()` is `round(ok/total*100)`. Surfaced in the admin "Network Doctor" view and via `wp … doctor` and the `/diagnostics` REST route.

### Automation

`src/Automation/Engine.php` — a minimal rules engine. Listens to `kitgenix_multistore/inventory_authoritative_changed` (fired every time authoritative inventory changes). For each enabled rule of trigger type `stock_below`/`stock_above` whose threshold condition matches the new quantity, it runs the single supported action type `set_product_status` (sets the mapped local product — or its variation's parent — to `publish`/`draft`/`private`). Rules are stored in the `automations` table (`AutomationRepository`) and managed from the "Automations" admin view.

### Orders

Four classes:

- **`OrderSync`** — per-request batched (via a `$pending` array flushed on `shutdown`) product order create/update capture; builds a full order payload (items, shipping/fee/coupon lines, billing/shipping addresses, refunds, totals); on the control store, upserts directly into `OrderRepository`; on a connected store, dispatches an `order_upsert` event. Also exposes `update_remote_status()`/`update_remote_note()`/`update_remote_refund()` which route either to a local `apply_*()` method (if the order lives on this store) or over `Client::request()` to the store that owns it. `schedule_backfill(days)` bulk-queues historical orders for indexing (used by `index-orders` CLI/admin action).
- **`OrderImporter`** — the optional "clone as a real local WooCommerce order" path (`order_import_mode = 'woocommerce'`), gated inside `Context::remote()`. Tags the created order (`_kitgenix_multistore_sync_for_woocommerce_imported_order = 'yes'`) so its own email-enabled and stock-reduce/restore filters (`email_enabled()`, `can_change_stock()`) suppress duplicate customer emails and duplicate stock movement. Can optionally clone missing-SKU line items as hidden draft placeholder products.
- **`OrderNumbers`** — optional network-sequential display order numbers. Control store allocates from a dedicated `sequences` table row under `FOR UPDATE`/`LAST_INSERT_ID()`; a connected store requests the next number over `/orders/next-number`, retrying with exponential backoff (up to 8 attempts) via Action Scheduler/cron on failure. `display()` filters `woocommerce_order_number`.
- **`OrderExporter`** — hand-rolled CSV and XLSX (raw OOXML via `ZipArchive`, no PhpSpreadsheet dependency) streaming export of filtered `remote_orders` rows, with CSV/XLSX formula-injection-safe cell escaping (`safe_cell()` prefixes a leading `=+-@` with a quote).
- **`MyAccount`** — renders a read-only cross-store order history table on the WooCommerce My Account "orders" screen (`woocommerce_after_account_orders`), sourced locally (control store) or via a signed `/orders/customer-history` call (connected store).

### Sync

The core engine — 14 classes, detailed in the **Sync Engine Deep Dive** below.

### Multisite

`src/Multisite/NativeNetwork.php`. Purely an alternative *store-discovery and pairing* mechanism for WordPress Multisite — the actual sync engine (events/queue/processor) is identical regardless of which mode connected a network. On `init` (priority 2), if native mode is enabled (site option `kitgenix_multistore_sync_for_woocommerce_native_enabled` or the `network_mode` setting), it iterates every subsite via `get_sites()`, determines each site's role from a configured control `blog_id`, and calls `StoreRepository::save()` for every *other* subsite using one shared secret (`kitgenix_multistore_sync_for_woocommerce_native_secret`, a 32-byte hex string stored as a **site option**, i.e. shared network-wide rather than per-pair). Re-discovery is debounced via a `native_generation` site option bumped on `wp_initialize_site`/`wp_uninitialize_site`, compared against a per-site `native_seen_generation` option so discovery only re-runs when something actually changed.

### Migration

`src/Migration/WooMultistoreImporter.php` — a one-off bridge for sites migrating off the third-party "WooMultistore" plugin. `detect()` counts distinct posts with any `_woonet_%` postmeta key (a raw `LIKE` scan — there's no WP API for "find distinct post IDs matching a meta-key pattern"). `import()` walks those posts and, for every `product`/`product_variation`, calls `MappingRepository::network_id()` to mint (or re-use) a stable Kitgenix network UUID and marks the product sync-enabled — it does **not** import WooMultistore's actual store connections/credentials, only establishes local network-ID mappings so a subsequent full product sync can proceed without duplicating products. Exposed via the "Migration" admin view.

### Pricing

`src/Pricing/PriceCalculator.php` — pure static math, no side effects, no DB access. `MODES` = `percentage`/`fixed`/`exchange`; `ROUNDINGS` = `none`/`nearest_whole`/`nearest_0_50`/`charm_95`/`charm_99`; `SALE_MODES` = `same_adjustment`/`maintain_discount_percent`/`source`/`clear`. `apply_for_store()` is the single entry point: it always recomputes from the *payload's current source price* (never a previously-adjusted value), so re-syncing a product never compounds a rule. A manual per-product/per-store price override (from `ProductPanel`) always wins over the automatic rule and is applied after it. Result metadata is stamped into `payload['_pricing']` for auditability and to prevent the receiving store re-applying the calculation (`ProductApplier` checks `_pricing.calculated_by` and only falls back to a legacy client-side calculation if that marker is absent).

### Rest

Two classes — see **REST API** below for the full route table, and **Authentication & Site Linking** for `Auth`'s verification logic.

### Media

`src/Media/NetworkMedia.php` — `import(array|string $asset, int $post_id)` is the single entry point used everywhere an image/attachment reference is applied (product featured/gallery/variation images, category thumbnails). Looks up an existing local attachment first by matching either `_kitgenix_multistore_sync_for_woocommerce_source_url` or `_kitgenix_multistore_sync_for_woocommerce_remote_media_url` postmeta against the incoming URL (idempotent — a repeat delivery of the same image never re-downloads). Two modes (`media_mode` setting):
- `local_copy` (default) — `media_sideload_image()` downloads and creates a real local attachment.
- `shared_remote` — creates a lightweight local `attachment` post whose `wp_get_attachment_url`/`wp_get_attachment_image_src`/`wp_calculate_image_srcset` are filtered to resolve to the remote source store's own URL instead of a local file (no local copy stored at all).

## Sync Engine Deep Dive

### What is synced

| Entity | Direction (default) | Trigger | Applier |
|---|---|---|---|
| Products & variations | Control → Connected (bidirectional optional) | WooCommerce product/variation save & trash hooks, WP All Import, REST product writes (optional) | `ProductApplier::apply()` |
| Inventory (stock qty + status) | Authoritative single value, converges everywhere | WooCommerce stock hooks, order-driven restock, Square integration, checkout | `InventorySync::receive_set()` / `receive_delta()` |
| Orders | Connected → Control (indexing only by default; optional full import) | `woocommerce_new_order`/`woocommerce_update_order`/status change, batched on `shutdown` | `OrderSync::receive()` / `OrderImporter::import()` |
| Coupons | Control → Connected | `woocommerce_new_coupon`/`woocommerce_update_coupon`/trash | `CouponSync::receive()` |
| Customers | Connected → Control (off by default) | `woocommerce_created_customer`/`woocommerce_update_customer` | `CustomerSync::receive()` |
| Reviews (product reviews/ratings) | Control → Connected, or full network mesh (off by default) | Comment hooks (`comment_post`, `edit_comment`, `transition_comment_status`, `deleted_comment`) | `ReviewSync::receive_upsert()` / `receive_delete()` |
| Images/media | Follows product sync | Embedded in product payload | `NetworkMedia::import()` |
| Categories, tags, brands, custom taxonomies, attributes, terms | Follows product sync | Embedded in product payload | `ProductApplier::apply_terms()` / `sync_attributes()` |

### Trigger model

Every WooCommerce write hook (product save, stock change, order save, coupon save, comment/review change) synchronously **queues** an outbound change (in-memory, per-request), which is then either flushed on `shutdown` (products/variations/orders) or dispatched immediately (coupons, customers, reviews, inventory). "Dispatch" means:

1. `Dispatcher::dispatch()` creates a row in the `events` table (an idempotent, replayable record of *what* happened), stamped with a revision number from `Revision::next()`.
2. For each target store UUID, `Queue::enqueue()` inserts/updates a `deliveries` row and schedules an async job — `as_schedule_single_action()` (Action Scheduler) if available, else `wp_schedule_single_event()` (WP-Cron fallback), both de-duplicated against an already-pending job for the same event+store.
3. The scheduled job (`kitgenix_multistore_sync_for_woocommerce_process_delivery`) calls `Queue::process()`, which signs and POSTs the event to the target store's `/events` REST route via `Http\Client::request()`.
4. The receiving store's `EventProcessor::receive()` validates, de-duplicates (by `event_uuid`, with race-safe `record_received()` insert), authorizes by topology/role, and dispatches to the matching per-entity `receive*()` method based on `event_type` (`product_upsert`, `product_trash`, `inventory_delta`, `inventory_set`, `order_upsert`, `coupon_upsert`, `coupon_trash`, `customer_upsert`, `review_upsert`, `review_delete`).
5. Applying an incoming change always wraps the actual WooCommerce write in `Context::remote()`, which sets a re-entrant flag checked by every outbound hook — this is what prevents an applied change from re-triggering its own outbound sync (an infinite ping-pong loop).

Nothing is real-time-push in the browser sense; delivery is asynchronous (typically within seconds under Action Scheduler) but not literally synchronous with the triggering page request.

### Conflict resolution / consistency model

- **Products**: last-write-wins by **revision number**, not wall-clock time. `Revision::next()` is a single global monotonic counter (not per-entity), so an out-of-order/retried delivery carrying an older revision than what's already applied (`MappingRepository.last_revision`) is detected and silently skipped (`ProductApplier::apply()`, "Skipped stale out-of-order product delivery"). Per-field, `ProductApplier` only calls a WooCommerce setter when the incoming value actually differs from the current local value — a "no-op" delivery therefore never even calls `save()`.
- **Field-level ownership**: `StorePolicy` computes, at *dispatch* time (in `Queue::process()`), which fields a specific store is allowed to receive for this delivery (publish vs. update mode, per the admin-configured policy matrix) and stamps `payload['_sync_policy']`. `ProductApplier`/`Registry::product_applied()` consult `StorePolicy::allows()` before touching each field group. Separately, a Connected Store can mark specific fields as permanently `_kitgenix_multistore_sync_for_woocommerce_override_fields` (locally owned, never overwritten) via the product-edit MultiStore panel.
- **Inventory**: not last-write-wins at all — it's an **authoritative delta/set ledger**. `InventoryRepository::apply_delta()`/`apply_set()` run inside a DB transaction, are idempotent per `event_uuid` (checked against the ledger first, so a retried delivery never double-applies), and use `revision`-guarded `INSERT … ON DUPLICATE KEY UPDATE` so a lower-revision write can never regress a higher-revision quantity. Every store's local WooCommerce stock number is a *reflection* of the control store's ledger, not an independent source of truth.
- **Genuine conflicts** (i.e. things a human should look at) are recorded via `ConflictRepository::record()` — keyed on `(event_uuid, store_uuid, conflict_type)` so a repeated identical failure updates one row rather than spamming new ones. `ErrorCatalog::is_recoverable()` distinguishes transient/self-resolving failure codes (network errors, replay/clock-skew, remote 409 conflicts, pairing rate limiting) from genuine ones for both the health-check UI and Network Doctor.

### Batching / throttling

- **Per-request batching**: `ProductSync`/`OrderSync` accumulate changed post IDs in an in-memory array and flush once on `shutdown`, so multiple saves to the same product/order within one request only produce one sync event.
- **Bulk jobs** (`BulkJob`): resumable, chunked (50 products or 100 mappings per tick), progress persisted in a single non-autoloaded option (`kitgenix_multistore_sync_for_woocommerce_bulk_job`) rather than a table — deliberately, since only one job is expected to run at a time. Each tick is one Action Scheduler/WP-Cron job (or one WP-CLI loop iteration); `nudge_if_stalled()` self-heals a job whose next tick never fired (backlogged queue, WP-Cron disabled) by re-scheduling whenever an admin views the Sync page; `poke()` is called by the browser's ~1.75s AJAX poll to keep ticks flowing back-to-back without waiting on cron. Supports dry-run, pause, resume, stop.
- **Delivery retry/backoff**: `Queue::process()` — on failure, retries with exponential backoff (`min(3600, 60 * 2^(attempts-1))` seconds) up to `max_retries` (default 8, admin-configurable 1–20), after which the delivery is marked `failed` and a conflict is recorded.
- **Reconciliation throttling**: `Reconciler::run()` (hourly, Action Scheduler recurring or WP-Cron `hourly` fallback) walks every connected store's mappings in chunks of 250 (paging) then compares in batches of 100 network IDs per `/inventory/snapshot` request.

### Error handling / retry logic

- Every outbound `Http\Client::request()` failure returns a `WP_Error`; `Queue::process()` interprets it, decides retry vs. permanent failure, and always records a `Logger` entry plus (on final failure) a `ConflictRepository` row.
- `EventProcessor::receive()` wraps its per-type `match()` dispatch in `try/catch(\Throwable)`, converting any uncaught exception into a `kitgenix_multistore_sync_for_woocommerce_event_exception` error rather than a fatal.
- `ProductApplier::apply()` similarly wraps its entire body in try/catch, logging and returning `kitgenix_multistore_sync_for_woocommerce_product_apply` on any WooCommerce/PHP exception.
- `BulkJob` isolates each item in its own try/catch so one bad product/mapping can never freeze or abort the rest of a batch.
- Sibling-plugin integrations in `Registry::product_applied()` are each in their own try/catch, so a stale/incompatible Custom Tabs/Document Manager install on one destination store can never break the primary product apply.

### Logging

Every conflict, delivery failure, incoming-auth rejection, and notable non-fatal event (e.g. "external inventory source updated authoritative network stock", "custom tabs synced from network product") is written via `Support\Logger` to the `logs` table, with structured JSON `context` (including, where relevant, the `ErrorCatalog` code). `error`/`critical` entries are mirrored to `wc_get_logger()` (WooCommerce's own log viewer) as a secondary channel. Retained for a configurable 7/14/30 days (`log_retention_days`), purged by a daily cron/Action Scheduler `cleanup` job; manually clearable from the admin Logs view.

## Authentication & Site Linking

### Establishing a connection ("pairing")

1. On the intended Control Store, an admin clicks "Generate one-time pairing code" (`Admin::generate_pair()`) → an 8-char uppercase code is generated (`wp_generate_password(8, false, false)`) and stored in transient `kitgenix_multistore_sync_for_woocommerce_pair_code` for 10 minutes.
2. On the store to be connected, the admin enters the Control Store's URL and that code (`Admin::connect_store()` → `Http\Client::pair()`). The connecting store generates a fresh **32-byte random shared secret** (`base64_encode(random_bytes(32))`), validates the target URL (HTTPS required unless both ends are recognised non-production/local), and POSTs `{code, secret, store: {uuid, name, url, role: 'control'}}` to the target's `/pair` REST route (no signature on this one call — it's authenticated purely by the one-time code).
3. The receiving store (`Routes::pair()`) rate-limits by IP (max 15 attempts / 10 minutes via transient), verifies the code with `hash_equals()`, validates the payload shape, saves the caller as a `role = 'control'` store with the supplied secret (`StoreRepository::save()`, which encrypts it via `Crypto::encrypt()`), flips its own `role` setting to `connected`, and responds with its own site identity + capabilities.
4. The initiating store saves the responder as a `role = 'connected'` store using the *same* secret it generated in step 2 — so after pairing, both ends hold an encrypted copy of the one shared secret, but the secret only ever traveled once, over the initial (ideally HTTPS) `/pair` call.

Native Multisite mode skips this entirely — see **Multisite** above; one secret, generated once, is shared network-wide via a site option rather than transmitted per-pair.

### Per-request authentication (all routes except `/pair`)

HMAC-SHA256, not WordPress Application Passwords, not OAuth, not a static API key header. `Http\Signer`:

```
canonical = METHOD "\n" ROUTE "\n" TIMESTAMP "\n" NONCE "\n" sha256(BODY)
signature = hmac_sha256(canonical, shared_secret)
```

Outbound (`Http\Client::request()`) sends 5 custom headers: `X-Kitgenix-Store` (sender's site UUID), `X-Kitgenix-Timestamp`, `X-Kitgenix-Nonce` (32-char random), `X-Kitgenix-Signature`, `X-Kitgenix-Version`.

Inbound verification (`Rest\Auth::verify()`, the `permission_callback` on every authenticated route):
1. Header presence/shape check (valid UUID, numeric timestamp, nonce ≥16 chars, signature exactly 64 hex chars) → else `auth_headers` (401).
2. Timestamp within ±300 seconds of server time → else `auth_expired` (401, "replay-or-clock-skew" category).
3. Store must be a known paired store with a decryptable secret → else `auth_store` (401).
4. Nonce must not already exist as transient `kitgenix_multistore_sync_for_woocommerce_nonce_{md5(store|nonce)}` → else `auth_replay` (409, replay protection).
5. `Signer::verify()` recomputes the HMAC over the actual request method/route/timestamp/nonce/body and compares with `hash_equals()` → else `auth_signature` (401).
6. On success: nonce is stored (10-min TTL) to prevent replay, the store is touched (`status = active`, `last_seen` updated), and the source store UUID is stashed on the request for downstream use.

Every rejection is logged via `Logger` (severity depends on `ErrorCatalog::is_recoverable()`).

### Role-based route authorization (topology)

Beyond signature verification, several routes additionally require the *authenticated peer's stored role* to match an expectation (`Routes::require_peer_role()`) — e.g. `/inventory/snapshot` requires the caller be a `control`-role peer (i.e. *we* are being asked by our control store), while `/inventory/availability`, `/orders/next-number`, `/orders/customer-history` require the caller be `connected` (i.e. we are the control store being asked by a connected store). A mismatch returns `route_topology` (403).

### What credentials are stored, and where

- **Shared secret**: `stores.secret_enc` column, AES-256-GCM ciphertext (`Support\Crypto`), key derived from `AUTH_KEY|SECURE_AUTH_KEY|kitgenix-multistore-sync-for-woocommerce` via SHA-256 — never stored in plaintext, never transmitted after the initial pairing handshake.
- **Native Multisite secret**: site option `kitgenix_multistore_sync_for_woocommerce_native_secret`, stored as **plaintext hex** at the site-options level (not encrypted at rest the way a paired-store secret is) — acceptable because it never crosses a network boundary; it's only ever read locally per-subsite via `switch_to_blog()`.
- **Site UUID**: `kitgenix_multistore_sync_for_woocommerce_site_uuid` option, generated once via `wp_generate_uuid4()`.
- **No WordPress Application Passwords, OAuth, or JWTs are used anywhere in this plugin.**

### SSRF / URL safety

`Support\Environment::validate_store_url()` requires `http`/`https` scheme, a non-empty host, no embedded userinfo/fragment, and (in production, against a non-local URL) passes through WordPress's own `wp_http_validate_url()` (which blocks loopback/private-range targets by default). HTTP is only ever permitted between two ends this plugin's own heuristics recognise as non-production *and* local/private-network (`is_local_url()`, matching `localhost`/`.local`/`.test`/RFC1918 ranges) — a live production pairing always requires HTTPS. `should_verify_ssl()`/`with_local_http_access()` narrowly relax `sslverify` and `http_request_host_is_external` only for that same recognised-local case (e.g. LocalWP self-signed certs), never for any external host.

## Admin Interface

### Menu structure

- **Top-level "Kitgenix" menu** (shared across all Kitgenix plugins, registered by the main plugin file, capability `manage_woocommerce`/`manage_options`, slug `kitgenix`) — a plugin-discovery "hub" grid (install/activate/open/review each Kitgenix plugin), not part of MultiStore's own settings.
- **Submenu "MultiStore"** under that top-level menu (`Admin::menu()`, slug `kitgenix-multistore-sync-for-woocommerce`, capability `Access::CAP`) — this is the actual plugin admin app, a single page whose content is switched by a `?view=` query arg (no real WP submenu pages per section — client-side/server-side tab routing inside one page).

### Views (`?page=kitgenix-multistore-sync-for-woocommerce&view=…`)

| View | Purpose |
|---|---|
| `dashboard` (default) | Connected-store count, network product/inventory/order counts, live event-status breakdown, open-conflict count. |
| `stores` | Network role/mode display, pairing-code generation, store connection form, connected-stores table (status/versions/pause/disconnect), per-store pricing rules table, per-store publish/update policy matrix, store groups management. |
| `products` | Table of network-mapped products (name/SKU/network ID/revision) with a "Sync now" action per row. |
| `inventory` | Authoritative inventory table (network ID/quantity/status/revision/source/updated). |
| `orders` | Searchable/filterable/paginated central order table, bulk status change, CSV/XLSX export, per-order note/refund forms, PDF document links. |
| `customers` | Single stat tile (unique customer record count). |
| `coupons` | Informational card linking to WooCommerce's own coupon screen. |
| `sync` | Bulk Operations panel (start/pause/resume/stop, live progress, recent-items feed), open conflicts table (resolve action), recent events table, deliveries table (retry action for failed). |
| `automations` | Create/list/delete stock-threshold automation rules. |
| `analytics` | 30-day revenue-by-currency stat tiles from `remote_orders`. |
| `integrations` | Per-integration detected/adapter-status cards (WooCommerce, Square, and every `Registry::statuses()` entry). |
| `doctor` | Local + per-store Network Doctor check tables with pass/fail badges and a percentage score. |
| `migration` | WooMultistore legacy-data detection + one-click import. |
| `logs` | Searchable/paginated activity log table with category badges + a reference table explaining every error category. |
| `settings` | The full settings form (see below) + factory-reset danger zone. |
| `support` | Donation/review/docs links (static content, no settings). |

### Settings fields (all stored in one option, see Data Storage)

Grouped by card: Network mode (multisite only) → Network role → Synchronisation toggles (`sync_products`, `sync_inventory`, `sync_orders`, `sync_coupons`, `sync_customers`, `sync_images`, `sync_reviews`, `sync_rest_products`, `sync_custom_tabs`, `review_direction`, `media_mode`) → Product & catalog behaviour (`sku_assisted_linking`, `auto_publish_new_products`, `checkout_inventory_validation`, `inventory_fail_closed`, `product_direction`) → Orders (`order_import_mode`, `clone_products_on_order_import`, `network_my_account_orders`, `sequential_order_numbers`, `order_number_prefix`, `order_number_start`) → Custom fields (`custom_meta_keys`, `custom_taxonomies`, `term_meta_keys`, one-per-line/comma-separated textareas) → Permissions & safety (`allowed_roles` checklist, `allow_non_production_outbound`, `allow_remote_gateway_refunds`, `remove_data_on_uninstall`) → Reliability (`max_retries`, `request_timeout`, `log_retention_days`).

### Product-edit integration

- **`ProductPanel`** adds a "MultiStore" tab to the WooCommerce Product Data metabox: network ID (read-only), sync-enabled checkbox, (control store) target-stores checklist + per-store price override fields + target-groups checklist, or (connected store) a "store overrides" checklist of locally-owned fields.
- **`BulkProductTools`** adds a MultiStore dropdown ("No change" / "Enable" / "Disable" / "Enable and sync now") to both Quick Edit and Bulk Edit, plus a "MultiStore" column on the product list showing sync-on/off + a truncated network ID.

### Notable UX/engineering details

- No AJAX transport for page navigation — every admin-post action does a full redirect with a `kitgenix_multistore_sync_for_woocommerce_notice` query-arg success message. The *one* exception is the Bulk Operations live-progress poll (`wp_ajax_kitgenix_multistore_sync_for_woocommerce_bulk_job_poll`), which patches DOM state via `fetch()` every ~1.75s while a job runs, then does one full reload when the job stops.
- Shared design system (`kitgenix-admin-ui.css`) reused verbatim across every Kitgenix plugin — light/dark theme via `[data-kitgenix-theme]` attribute toggle + `prefers-color-scheme` fallback, persisted per-browser in `localStorage`.

## Hooks Reference

### Actions/filters this plugin hooks INTO

| Hook | Source | Used by |
|---|---|---|
| `admin_menu` | WP core | Main file (shared Kitgenix menu), `Admin::menu()` |
| `admin_enqueue_scripts` | WP core | Main file, `Admin::assets()` |
| `admin_init` | WP core | `Access::sync_roles()` |
| `plugin_action_links_{basename}` | WP core | `Admin::links()` |
| `before_woocommerce_init` | WooCommerce | HPOS compat declaration |
| `plugins_loaded` | WP core | Plugin boot |
| `wp_initialize_site` / `wp_uninitialize_site` | WP core (multisite) | Schema install on new subsite; `NativeNetwork` re-discovery trigger |
| `wp_privacy_personal_data_exporters` / `_erasers` | WP core | `Privacy::hooks()` |
| `rest_api_init` | WP core | `Routes::register()` |
| `init` | WP core | `Queue::ensure_schedules()`, `Reconciler::ensure_schedule()`, `NativeNetwork::discover()` |
| `woocommerce_product_before_set_stock`, `woocommerce_variation_before_set_stock` | WooCommerce | `InventorySync::before()` |
| `woocommerce_product_set_stock`, `woocommerce_variation_set_stock` | WooCommerce | `InventorySync::after()` |
| `woocommerce_product_object_updated_props` | WooCommerce | `InventorySync::after_object_save()`, `ProductSync::capture_updated_props()` |
| `woocommerce_restore_order_stock` | WooCommerce | `InventorySync::after_stock_restored()` |
| `woocommerce_check_cart_items` | WooCommerce | `InventorySync::validate_cart_inventory()` |
| `woocommerce_new_product`, `woocommerce_update_product` | WooCommerce | `ProductSync::queue_product()` |
| `woocommerce_new_product_variation`, `woocommerce_update_product_variation` | WooCommerce | `ProductSync::queue_variation()` |
| `trashed_post` | WP core | `ProductSync::trash()`, `CouponSync::trashed()` |
| `shutdown` | WP core | `ProductSync::flush()`, `OrderSync::flush()` |
| `woocommerce_new_order`, `woocommerce_update_order` | WooCommerce | `OrderSync::queue()` |
| `woocommerce_order_status_changed` | WooCommerce | `OrderSync::status_changed()` |
| `woocommerce_new_coupon`, `woocommerce_update_coupon` | WooCommerce | `CouponSync::changed()` |
| `woocommerce_created_customer`, `woocommerce_update_customer` | WooCommerce | `CustomerSync::changed()` |
| `comment_post`, `edit_comment`, `transition_comment_status`, `deleted_comment` | WP core | `ReviewSync` hooks |
| `woocommerce_after_account_orders` | WooCommerce | `MyAccount::render()` |
| `woocommerce_product_data_tabs`, `woocommerce_product_data_panels`, `woocommerce_process_product_meta` | WooCommerce | `ProductPanel` |
| `woocommerce_product_quick_edit_start/save`, `woocommerce_product_bulk_edit_start/save` | WooCommerce | `BulkProductTools` |
| `manage_edit-product_columns`, `manage_product_posts_custom_column` | WP core | `BulkProductTools::column()`/`column_value()` |
| `pmxi_saved_post` | WP All Import | `ImportBridge::wp_all_import_saved()` |
| `wpo_wcpdf_document_link_access_type` | PDF Invoices & Packing Slips | `Registry::pdf_access_type()` |
| `wp_get_attachment_url`, `wp_get_attachment_image_src`, `wp_calculate_image_srcset` | WP core | `NetworkMedia` (shared-remote media mode) |
| `wc_get_order_statuses`, `wp_roles()` etc. | WooCommerce/WP core | various, read-only |
| `woocommerce_order_number` | WooCommerce | `OrderNumbers::display()` |
| `woocommerce_email_enabled_{status}` (8 statuses), `woocommerce_can_reduce_order_stock`, `woocommerce_can_restore_order_stock` | WooCommerce | `OrderImporter` |
| `cron_schedules` | WP core | `Queue::cron_schedules()` (adds a 5-minute interval) |
| `kitgenix_multistore_sync_for_woocommerce_*` (many, see Cron section) | this plugin | scheduled job callbacks |

### Custom actions/filters this plugin FIRES

| Hook | Type | Fired by | Purpose |
|---|---|---|---|
| `kitgenix_multistore_loaded` | action | `Plugin::boot()` | Plugin fully booted; passes the `Plugin` instance. |
| `kitgenix_multistore/product/meta_keys` | filter | `ProductPayload::meta_keys()` | Extend the whitelisted product-meta-key set to sync. |
| `kitgenix_multistore/product/payload` | filter | `ProductPayload::build()` | Modify the outbound product payload before dispatch. |
| `kitgenix_multistore/product_applied` | action | `ProductApplier::apply()` | Fired after a product/variation is applied locally (product, payload, revision, changed). |
| `kitgenix_multistore/order/payload` | filter | `OrderSync::payload()` | Modify the outbound order payload before dispatch. |
| `kitgenix_multistore/coupon/payload` | filter | `CouponSync::payload()` | Modify the outbound coupon payload. |
| `kitgenix_multistore/customer/payload` | filter | `CustomerSync::payload()` | Modify the outbound customer payload. |
| `kitgenix_multistore/order_received` | action | `OrderSync::receive()` | An order snapshot was received/indexed (payload, source, imported_order_id). |
| `kitgenix_multistore/event_created` | action | `Dispatcher::dispatch()` | A new sync event row was created (event, target_uuids). |
| `kitgenix_multistore/inventory_authoritative_changed` | action | `InventorySync` (multiple sites) | Authoritative inventory changed (network_id, quantity, stock_status) — consumed internally by `Automation\Engine`. |
| `kitgenix_multistore/external_inventory_captured` | action | `InventorySync::after_object_save()` | A non-WooCommerce-native stock write (e.g. WooCommerce Square) was captured as authoritative. |

## WP-CLI Commands

Command namespace: `wp kitgenix-multistore-sync-for-woocommerce <command>` (registered only if `WP_CLI` is defined, via `Commands::register()`).

| Command | Args | Behavior |
|---|---|---|
| `status` | — | Prints version, role, store count, network product count, inventory record count, and delivery-status counts. |
| `reconcile` | — | Runs `Reconciler::run()` synchronously once; reports checked/drift/repaired counts. |
| `sync-product <id>` | `<id>` positional WooCommerce product ID | Calls `ProductSync::sync_now($id)`; errors out on `WP_Error`. |
| `doctor` | — | Prints every `NetworkDoctor::local_checks()` row with a ✓/✕ indicator. |
| `index-orders` | `[--days=<n>]` (default 90, capped 1–3650) | Calls `OrderSync::schedule_backfill($days)`; reports queued count. |
| `bulk-sync` | `[--products=<ids>]` (comma-separated), `[--dry-run]` | Starts/drives a `BulkJob` of type `product_sync` to completion with a live `WP_CLI\Utils\make_progress_bar()`; reports processed/repaired/excluded/drift/errors. |
| `bulk-reconcile` | `[--dry-run]` | Same as `bulk-sync` but type `reconcile`. |

`bulk-sync`/`bulk-reconcile` drive the job in a tight `do...while` loop calling `BulkJob::tick(false)` (no auto-reschedule, since the CLI loop itself drives progress) until status leaves `running`.

## REST API

Namespace: `kitgenix-multistore-sync-for-woocommerce/v1`. Registered in `Rest\Routes::register()` on `rest_api_init`. All routes except `/pair` use `Auth::verify` as `permission_callback` (see **Authentication & Site Linking**); several additionally require a specific peer role via `require_peer_role()`.

| Route | Method(s) | Permission | Peer role required | Purpose / response shape |
|---|---|---|---|---|
| `/pair` | POST | `__return_true` (public — gated by one-time code instead) | — | Completes pairing; body `{code, secret, store:{uuid,name,url,role}}`; response `{success, store, capabilities}`. |
| `/health` | GET, POST | `Auth::verify` | — | Heartbeat; response `{success, store, capabilities, time}`. |
| `/events` | POST | `Auth::verify` | — | Receives one sync event; body is a full event record; delegates to `EventProcessor::receive()`; response `{success, status, ...applier result}`. |
| `/inventory/snapshot` | POST | `Auth::verify` | `control` (caller must be our control store) | Body `{network_ids:[...]}` (max 250); response `{success, inventory:{network_id:{quantity,stock_status,local_id}}}`. |
| `/inventory/availability` | POST | `Auth::verify` | `connected` (caller must be a connected store) | Body `{items:[{network_id,quantity}]}` (max 100); response `{success, availability, unavailable}` — used for checkout-time validation. |
| `/orders/status` | POST | `Auth::verify` | `control` | Body `{network_id, order_id, status}`; response `{success, order_id}`. |
| `/orders/note` | POST | `Auth::verify` | `control` | Body `{network_id, order_id, note, customer_note}`; response `{success, order_id, note_id}`. |
| `/orders/refund` | POST | `Auth::verify` | `control` | Body `{network_id, order_id, amount, reason, refund_payment, restock_items, line_items}`; response `{success, order_id, refund_id, amount}`. |
| `/orders/next-number` | POST | `Auth::verify` | `connected`, and caller-side role must be `control` + `sequential_order_numbers = yes` | Response `{success, number}`. |
| `/orders/customer-history` | POST | `Auth::verify` | `connected`, and this store must be `control` + `network_my_account_orders = yes` | Body `{email}`; response `{success, orders:[...]}` (excludes the requesting store's own rows). |
| `/diagnostics` | POST | `Auth::verify` | — | Response `{success, checks: NetworkDoctor::local_checks()}`. |

Every non-`/pair` route's args arrive as a raw JSON body (`$request->get_json_params()`), not registered WP REST `args` schemas — validation/sanitization happens inline in each callback.

## Data Storage

### Custom database tables

All tables are prefixed `{$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_`.

**`stores`** — paired/discovered remote stores.
```sql
id            bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
uuid          char(36) NOT NULL UNIQUE
name          varchar(191) NOT NULL
url           varchar(255) NOT NULL
role          varchar(32) NOT NULL DEFAULT 'connected'   -- 'control' | 'connected'
secret_enc    longtext NULL                               -- AES-256-GCM ciphertext, base64
status        varchar(32) NOT NULL DEFAULT 'unknown'       -- see StoreRepository::STATUSES
capabilities  longtext NULL                                -- JSON: wp/wc/php versions, hpos, action_scheduler, kitgenix version
last_seen     datetime NULL
created_at    datetime NOT NULL
updated_at    datetime NOT NULL
KEY status (status)
```

**`mappings`** — local object ↔ network UUID.
```sql
id             bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
network_id     char(36) NOT NULL
entity_type    varchar(32) NOT NULL      -- product|variation|coupon|order|customer|order_import|...
store_uuid     char(36) NOT NULL          -- 'this' site's own UUID for a local-object row
object_id      bigint(20) unsigned NOT NULL DEFAULT 0
object_key     varchar(191) NOT NULL DEFAULT ''  -- SKU/code/etc.
last_revision  bigint(20) unsigned NOT NULL DEFAULT 0
updated_at     datetime NOT NULL
UNIQUE KEY entity_store (network_id, entity_type, store_uuid)
UNIQUE KEY local_object (entity_type, store_uuid, object_id)
KEY object_key (entity_type, object_key)
```

**`events`** — the event log (event-sourcing record of every synced change).
```sql
id                 bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
event_uuid         char(36) NOT NULL UNIQUE
parent_event_uuid  char(36) NULL
entity_type        varchar(32) NOT NULL
network_id         char(36) NOT NULL
event_type         varchar(64) NOT NULL      -- product_upsert, inventory_set, order_upsert, etc.
source_store_uuid  char(36) NOT NULL
revision           bigint(20) unsigned NOT NULL DEFAULT 0
payload            longtext NOT NULL          -- JSON
status             varchar(32) NOT NULL DEFAULT 'pending'  -- pending|received|completed|failed
created_at         datetime NOT NULL
completed_at       datetime NULL
KEY status (status), KEY entity (entity_type, network_id), KEY created_at (created_at)
```

**`deliveries`** — per-(event, target store) delivery attempt state.
```sql
id             bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
event_uuid     char(36) NOT NULL
store_uuid     char(36) NOT NULL
status         varchar(32) NOT NULL DEFAULT 'pending'  -- pending|processing|retrying|completed|failed|paused
attempts       smallint(5) unsigned NOT NULL DEFAULT 0
last_error     text NULL
next_attempt   datetime NULL
updated_at     datetime NOT NULL
UNIQUE KEY event_store (event_uuid, store_uuid)
KEY status (status), KEY next_attempt (next_attempt)
```

**`inventory`** — authoritative current stock state, one row per network product/variation.
```sql
id                 bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
network_id         char(36) NOT NULL UNIQUE
quantity           decimal(19,4) NULL
stock_status       varchar(32) NOT NULL DEFAULT 'instock'
revision           bigint(20) unsigned NOT NULL DEFAULT 0
source_store_uuid  char(36) NOT NULL
updated_at         datetime NOT NULL
KEY revision (revision)
```

**`inv_ledger`** (renamed from `inventory_ledger` pre-1.0.0 — see Database subsystem note) — append-only, idempotent inventory change history.
```sql
id                 bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
entry_uuid         char(36) NOT NULL UNIQUE
network_id         char(36) NOT NULL
event_uuid         char(36) NOT NULL UNIQUE   -- idempotency key
source_store_uuid  char(36) NOT NULL
delta              decimal(19,4) NOT NULL DEFAULT 0
quantity_after     decimal(19,4) NULL
reason             varchar(64) NOT NULL DEFAULT 'adjustment'
reference          varchar(191) NOT NULL DEFAULT ''
revision           bigint(20) unsigned NOT NULL DEFAULT 0
created_at         datetime NOT NULL
KEY network_id (network_id)
```

**`remote_orders`** — central order index (network snapshot, not a copy of `wp_posts`).
```sql
id                 bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
network_id         char(36) NOT NULL UNIQUE
store_uuid         char(36) NOT NULL
remote_order_id    bigint(20) unsigned NOT NULL
order_number       varchar(64) NOT NULL DEFAULT ''
status             varchar(64) NOT NULL DEFAULT ''
currency           varchar(12) NOT NULL DEFAULT ''
total              decimal(19,4) NOT NULL DEFAULT 0
customer_email     varchar(191) NOT NULL DEFAULT ''
customer_name      varchar(191) NOT NULL DEFAULT ''
payload            longtext NOT NULL           -- JSON, full order snapshot
order_created_at   datetime NULL
order_updated_at   datetime NULL
synced_at          datetime NOT NULL
KEY store_order (store_uuid, remote_order_id), KEY status (status), KEY synced_at (synced_at)
```

**`customers`** — synced customer snapshots (control store only, opt-in feature).
```sql
id                   bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
network_id           char(36) NOT NULL
store_uuid           char(36) NOT NULL
remote_customer_id   bigint(20) unsigned NOT NULL DEFAULT 0
email                varchar(191) NOT NULL DEFAULT ''
display_name         varchar(191) NOT NULL DEFAULT ''
payload              longtext NOT NULL           -- JSON
synced_at            datetime NOT NULL
UNIQUE KEY network_store (network_id, store_uuid)
KEY email (email)
```

**`conflicts`** — unresolved/resolved sync failures needing (or having had) human review.
```sql
id             bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
conflict_uuid  char(36) NOT NULL UNIQUE
event_uuid     char(36) NOT NULL DEFAULT ''
entity_type    varchar(32) NOT NULL DEFAULT ''
network_id     char(36) NOT NULL DEFAULT ''
store_uuid     char(36) NOT NULL DEFAULT ''
conflict_type  varchar(64) NOT NULL DEFAULT 'sync_error'  -- an ErrorCatalog code
message        text NOT NULL
payload        longtext NULL
status         varchar(20) NOT NULL DEFAULT 'open'  -- open|resolved|ignored
created_at     datetime NOT NULL
resolved_at    datetime NULL
KEY status (status), KEY network_id (network_id), KEY created_at (created_at)
```

**`automations`** — stock-threshold → product-status rules.
```sql
id              bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
name            varchar(191) NOT NULL
enabled         tinyint(1) NOT NULL DEFAULT 1
trigger_type    varchar(64) NOT NULL      -- stock_below | stock_above
trigger_config  longtext NOT NULL          -- JSON {threshold}
action_type     varchar(64) NOT NULL      -- set_product_status
action_config   longtext NOT NULL          -- JSON {status}
last_run        datetime NULL
created_at      datetime NOT NULL
updated_at      datetime NOT NULL
KEY enabled_trigger (enabled, trigger_type)
```

**`sequences`** — atomic counters (currently only network order numbers).
```sql
sequence_key    varchar(64) NOT NULL PRIMARY KEY   -- e.g. 'orders'
current_value   bigint(20) unsigned NOT NULL DEFAULT 0
updated_at      datetime NOT NULL
```

**`logs`** — plugin activity/error log.
```sql
id          bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
level       varchar(20) NOT NULL DEFAULT 'info'
message     text NOT NULL
context     longtext NULL     -- JSON
created_at  datetime NOT NULL
KEY level (level), KEY created_at (created_at)
```

### Options

| Option | Autoload | Purpose |
|---|---|---|
| `kitgenix_multistore_sync_for_woocommerce_settings` | no | All plugin settings (single serialized array, see `Settings::all()` defaults). |
| `kitgenix_multistore_sync_for_woocommerce_site_uuid` | no | This site's own stable network UUID. |
| `kitgenix_multistore_sync_for_woocommerce_db_version` | no | Compared against the plugin constant to trigger `Schema::install()`. |
| `kitgenix_multistore_sync_for_woocommerce_revision` | no | Global monotonic revision counter's stored value. |
| `kitgenix_multistore_sync_for_woocommerce_bulk_job` | no | Persisted `BulkJob` state (type/status/progress/errors/recent items). |
| `kitgenix_multistore_sync_for_woocommerce_woomultistore_migrated_at` | no | Timestamp of the last WooMultistore migration run. |
| `kitgenix_multistore_sync_for_woocommerce_native_seen_generation` | no | Per-site debounce marker for Native Multisite re-discovery. |

### Site options (multisite network-wide, Native mode only)

`kitgenix_multistore_sync_for_woocommerce_native_enabled`, `..._native_control_blog_id`, `..._native_secret`, `..._native_generation`.

### Transients

`kitgenix_multistore_sync_for_woocommerce_pair_code` (10 min), `..._pair_rate_{md5(ip)}` (10 min, pairing rate limit), `..._nonce_{md5(store|nonce)}` (10 min, replay protection), `..._pdf_{hash(token)}` (30 days, PDF document access tokens); plus the unrelated, plugin-hub-wide `kitgenix_hub_wporg_active_installs_v1` / `_ratings_v1` / `_media_v1` (24h, main plugin file, WordPress.org metadata cache).

### Postmeta keys

`_kitgenix_multistore_sync_for_woocommerce_network_id`, `_sync_enabled`, `_target_stores`, `_target_groups`, `_store_overrides`, `_override_fields`, `_source_url` (media), `_remote_media_url` (media, shared-remote mode), `_remote_width`/`_remote_height` (media), `_order_import_placeholder`, `_quick_sync`/`_bulk_sync` (transient request-only fields, not persisted). Order postmeta: `_imported_order`, `_origin_store_uuid`, `_origin_order_id`, `_network_id`, `_origin_order_number`, `_network_order_number`, `_order_number_attempts`. Comment meta: `_kitgenix_multistore_sync_for_woocommerce_review_network_id`.

### Usermeta

None — the plugin does not store any custom usermeta.

## Cron / Scheduled Events

Prefers **Action Scheduler** (`as_schedule_recurring_action`/`as_schedule_single_action`, group `kitgenix-multistore-sync-for-woocommerce`) when available (bundled with WooCommerce); falls back to native **WP-Cron** (`wp_schedule_event`/`wp_schedule_single_event`) under a `_fallback_*`-suffixed hook name when Action Scheduler functions don't exist.

| Hook | Schedule | Registered by | Callback |
|---|---|---|---|
| `kitgenix_multistore_sync_for_woocommerce_health_check` (+ `_fallback_health_check`) | Every 5 minutes | `Queue::ensure_schedules()` (on `init`) | `Queue::health_check()` — pings every active store's `/health`, updates status. |
| `kitgenix_multistore_sync_for_woocommerce_cleanup` (+ `_fallback_cleanup`) | Daily | `Queue::ensure_schedules()` | `Queue::cleanup()` — purges log rows older than `log_retention_days`. |
| `kitgenix_multistore_sync_for_woocommerce_reconcile` (+ `_fallback_reconcile`) | Hourly | `Reconciler::ensure_schedule()` (on `init`) | `Reconciler::run()` — inventory drift detection/repair. |
| `kitgenix_multistore_sync_for_woocommerce_process_delivery` | On-demand, single-fire per (event, store) | `Queue::enqueue()` | `Queue::process()` — delivers one event to one store. |
| `kitgenix_multistore_sync_for_woocommerce_bulk_job_tick` (+ `_fallback`) | On-demand, single-fire, self-rescheduling | `BulkJob::schedule_tick()` | `BulkJob::tick()` — advances a running bulk job one chunk. |
| `kitgenix_multistore_sync_for_woocommerce_sync_product_job` | On-demand, single-fire | `ProductSync::schedule_all()`, `ImportBridge` | `ProductSync::sync_job()` — syncs one product. |
| `kitgenix_multistore_sync_for_woocommerce_index_order_job` | On-demand, single-fire, staggered | `OrderSync::schedule_backfill()` | `OrderSync::sync_now()` — indexes one historical order. |
| `kitgenix_multistore_sync_for_woocommerce_assign_order_number` | On-demand, single-fire, backoff retry | `OrderNumbers::assign()` on failure | `OrderNumbers::assign()` — retries number allocation. |

`cron_schedules` filter adds a custom `kitgenix_multistore_sync_for_woocommerce_five_minutes` interval for the WP-Cron fallback path (Action Scheduler doesn't need a registered interval — it takes a raw second count).

`Schema::deactivate()` clears the 3 WP-Cron fallback hooks and unschedules the 3 recurring Action Scheduler actions on plugin deactivation.

## Privacy / GDPR

See **Subsystems → Privacy** above for full detail. Summary: one exporter + one eraser (`wp_privacy_personal_data_exporters`/`_erasers`), covering `remote_orders` (by `customer_email`) and `customers` (by `email`). Export returns readable field/value pairs; erase blanks PII fields on orders (keeping aggregate/financial data) and hard-deletes matching customer rows.

## Frontend Behavior

This is fundamentally an admin/backend synchronization plugin, but it has three storefront-visible effects:

1. **My Account cross-store order history** (`MyAccount::render()`, opt-in via `network_my_account_orders`): a read-only "Orders from our other stores" table appended after WooCommerce's own order-history table.
2. **Checkout inventory validation** (`InventorySync::validate_cart_inventory()`, opt-in via `checkout_inventory_validation` on a Connected Store): on `woocommerce_check_cart_items`, queries the control store's live authoritative stock for every stock-managed cart item and adds a `wc_add_notice('error', …)` if requested quantity exceeds what the network actually has; `inventory_fail_closed` additionally blocks checkout entirely if the control store can't be reached to validate.
3. **Media/image URLs** (`NetworkMedia`, only in `shared_remote` media mode): frontend `<img>` `src`/`srcset` for a shared-remote attachment resolve to the source store's own image URL via filtered `wp_get_attachment_url()`/`wp_get_attachment_image_src()`, and `wp_calculate_image_srcset()` is disabled for such attachments (no local responsive-image copies exist to reference).

No shortcodes, widgets, blocks, or public-facing settings pages are registered by this plugin.

## Assets

### JavaScript (`assets/js/`)

| File | Purpose | Enqueued on |
|---|---|---|
| `kitgenix-admin-tabs.js` | Shared topbar/tab/search controller — hash/query-param tab routing, mobile hamburger menu, dropdown menus, topbar search expand/collapse + `/`/Cmd+K shortcut + live item filtering, light/dark theme toggle (persisted to `localStorage`), notice-repositioning `MutationObserver`, sticky-topbar-height CSS custom property sync. Deliberately kept byte-identical across every Kitgenix plugin. | Every MultiStore admin page + any WooCommerce product-edit screen (registered, but only actually enqueued on MultiStore pages themselves). |
| `kitgenix-admin-components.js` | Shared component controller — toast notifications, accessible modal open/close (focus trap, `Escape` to close, stacked-modal support), collapsible/accordion cards, copy-to-clipboard buttons, live client-side table search filtering, lightweight client-side table pagination. Also kept identical across plugins. | Same pages as above. |
| `admin.js` | Plugin-specific: (1) generic `data-*confirm` click-confirmation + order-table "check all" checkbox handler; (2) Bulk Operations live-progress poller — fetches `wp_ajax_kitgenix_multistore_sync_for_woocommerce_bulk_job_poll` every 1.75s while a job is running and patches the progress bar, stat tiles, error list, and "recently synced products" feed in place, reloading the page once only when the job actually stops. | MultiStore admin pages only, depends on the two shared scripts above. |

### CSS (`assets/css/`)

| File | Purpose | Enqueued on |
|---|---|---|
| `kitgenix-admin-ui.css` (2,673 lines) | The full shared Kitgenix design system: CSS custom-property token set (brand colors, radius scale, spacing scale, Manrope/Inter Google Fonts import), light theme on `:root` plus a `[data-kitgenix-theme="dark"]` override block, topbar/nav/dropdown/mobile-menu styling, cards, stat tiles, tables, toggles, badges, notices, buttons, modals, forms, pagination — the base every other stylesheet builds on. Kept byte-identical across every Kitgenix plugin. | Every wp-admin page where any Kitgenix admin screen is rendered (registered globally via `admin_enqueue_scripts` priority 5 in the main plugin file, enqueued conditionally per-page by `Admin::assets()`). |
| `kitgenix-hub.css` | Styles specifically for the shared "Kitgenix" plugin-discovery hub grid/cards (`admin.php?page=kitgenix`) — layered on the same design tokens. | The Kitgenix Hub top-level page only. |
| `admin.css` | MultiStore-specific overrides only — the WooCommerce product-data-tab icon mask, a couple of MultiStore-namespaced layout tweaks. Everything generic (cards/tables/toggles/badges) intentionally lives in `kitgenix-admin-ui.css` instead of being duplicated here. | MultiStore admin pages + WooCommerce product edit screens. |

### Images (`assets/images/`)

Two subfolders — `logos/` (Kitgenix brand SVGs, including the WordPress admin-menu favicon mask source) and `social-media/` (per-network SVG icons used in the hub header and settings sidebar). No product/marketing screenshots are bundled in the codebase itself (readme.txt references screenshots hosted separately on WordPress.org).

## Internationalization

- **Text domain:** `kitgenix-multistore-sync-for-woocommerce`, **Domain Path:** `/languages` (both declared in the plugin header).
- **No explicit `load_plugin_textdomain()` call** was found anywhere in the codebase — as of WordPress 4.6+, a plugin hosted on WordPress.org with these headers has its translations loaded automatically by WordPress core (via `_load_textdomain_just_in_time()`/GlotPress), so an explicit load call is unnecessary and correctly omitted.
- Every user-facing string in the codebase is wrapped in `__()`/`_e()`/`esc_html__()`/`esc_attr__()` (or the admin file's local shortcuts), with proper translator comments (`/* translators: %s … */`) wherever a placeholder appears.
- `languages/kitgenix-multistore-sync-for-woocommerce.pot` is a generated (WP-CLI `wp i18n make-pot`) template, `POT-Creation-Date: 2026-08-30`, `X-Generator: WP-CLI 2.12.0` — no compiled `.mo`/`.po` translation files are bundled; translations are expected to come via WordPress.org's translation platform.

## Security

- **Capability gating**: custom capability `kitgenix_multistore_sync_for_woocommerce_manage_multistore` (`Access::CAP`), synced onto `administrator` (always) plus any admin-selected roles (default `administrator`, `shop_manager`) on every `admin_init`. `Access::can_manage()` (`current_user_can(CAP) || current_user_can('manage_options')`) gates every admin view and every `admin_post_*`/AJAX handler.
- **Nonces**: every state-changing admin form uses `wp_nonce_field()` + `check_admin_referer()` (via the shared `Admin::guard()` helper) or an inline `wp_verify_nonce()` check for GET-based actions (delete/toggle/retry links use `wp_nonce_url()`). The AJAX bulk-job poll uses `check_ajax_referer()`. The WooCommerce Quick/Bulk-Edit save handlers rely on WooCommerce's own already-verified AJAX nonce (documented inline) plus an explicit `current_user_can('edit_post', …)` capability check.
- **Sanitization**: consistent use of `sanitize_text_field()`, `sanitize_key()`, `sanitize_email()`, `esc_url_raw()`, `wc_format_decimal()`, `wc_clean()`, `wp_kses_post()`/`wp_strip_all_tags()` per field type throughout every handler and repository.
- **Escaping**: `esc_html()`/`esc_attr()`/`esc_url()`/`esc_textarea()` throughout `Admin.php`'s hand-built HTML output; PHPCS `WordPress.Security.EscapeOutput.OutputNotEscaped` is disabled file-wide with a documented rationale (every dynamic value is escaped at one of a small number of audited points, including a self-escaping `icon()`/`metric()`/`checks_table()`/`toggle()` helper pattern).
- **REST authentication**: see **Authentication & Site Linking** — HMAC-SHA256 request signing, timestamp window, nonce replay protection, encrypted-at-rest shared secrets, role-based topology checks, IP-based pairing rate limiting (15 attempts / 10 minutes).
- **SSRF hardening**: `Environment::validate_store_url()`/`is_local_url()`/`should_verify_ssl()` — production store connections must be valid, non-credentialed HTTPS origins passing `wp_http_validate_url()`; HTTP/relaxed-SSL is permitted only between two ends both independently recognised as local/non-production, never for any external or production host.
- **CSV/XLSX export injection protection**: `OrderExporter::safe_cell()` prefixes any cell value starting with `=`, `+`, `-`, or `@` with a leading `'` to defeat spreadsheet-formula injection when opened in Excel/Sheets.
- **Encryption at rest**: shared connection secrets via AES-256-GCM (`Crypto`), keyed from WordPress's own `AUTH_KEY`/`SECURE_AUTH_KEY` salts — no separate secret-management system, no plaintext secret column.
- **No SQL injection surface**: every repository disables the relevant PHPCS direct-query sniffs with a documented justification and consistently uses `$wpdb->prepare()` for every interpolated value; table names are always built from `$wpdb->prefix` plus a fixed literal, never from request input.

## Uninstall / Cleanup

- **Deactivation** (`Schema::deactivate()`): clears the 3 WP-Cron fallback hooks and unschedules the 3 recurring Action Scheduler actions. **No data is removed** — deactivating and reactivating the plugin resumes exactly where it left off.
- **Uninstall** (`uninstall.php`, only runs on actual plugin *deletion* through wp-admin/WP-CLI): data removal is **opt-in only**, gated by either of two independent switches (either is sufficient, default is neither):
  1. The admin setting **"Remove all MultiStore data on uninstall"** (`remove_data_on_uninstall = 'yes'`, Settings → Permissions & Safety).
  2. A `wp-config.php` constant `KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_REMOVE_DATA = true` (useful for CI/automated teardown).

  When either is true, for every site (looping `get_sites()` under multisite): drops all 12 custom tables, deletes all `_kitgenix_multistore_sync_for_woocommerce_%` postmeta, deletes all matching transients, deletes the plugin's own 6 options, and removes the custom capability from every role. Under multisite, also deletes the 4 Native-Multisite site options network-wide.
  3. **Actual WooCommerce products and orders are never deleted by either path** — only this plugin's own tables/postmeta/options/transients/capability.
- **`Support\Reset::factory_reset()`**: an on-demand equivalent of the opt-in uninstall wipe, triggered from Settings → Danger Zone (requires typing "RESET" to confirm) — performs the same table/postmeta/option/capability cleanup for the *current site only*, disables Native Multisite mode, then immediately re-runs `Schema::install()` so the site is left in a fresh, working (just-activated-equivalent) state rather than a broken one. Lets an admin clear a stale connection without deleting/reinstalling the whole plugin.

## Known Limitations / TODOs

No `TODO`/`FIXME`/`XXX` comments were found anywhere in the codebase (confirmed by search) — this is a mature, hardened 1.0.x codebase with extensive inline rationale comments rather than open work markers. Limitations observed while reading the code (not flagged as such by the developers, but worth noting for maintainers):

- **No compiled translations bundled** — only the `.pot` template ships; actual language files depend on WordPress.org's translation platform once the plugin is listed there.
- **Native Multisite shared secret is stored in plaintext** at the site-options level (`kitgenix_multistore_sync_for_woocommerce_native_secret`), unlike the per-pair `secret_enc` column which is AES-256-GCM encrypted — acceptable given it never leaves the local network boundary, but an asymmetry worth knowing about.
- **No hard cap on connected-store count** anywhere in the code (confirmed in `docs/FEATURE-PARITY.md`) — practical scale is bounded only by catalogue size, event volume, hosting resources and Action Scheduler/cron throughput, i.e. this could degrade on very large networks without an explicit warning mechanism.
- **`OrderExporter`'s XLSX writer is hand-built OOXML** (raw `ZipArchive` + manually authored XML parts), not a full spreadsheet library — it supports a single unstyled sheet of string cells only; sufficient for the order-export use case but not general-purpose.
- **Bulk job state is a single global option**, not per-user/per-job — the code comments explicitly acknowledge this assumes "a single admin-triggered job at a time," so two admins cannot run independent bulk jobs concurrently on the same site.
- **The `Migration\WooMultistoreImporter` only migrates local network-ID mappings**, not WooMultistore's actual store connections/credentials — a site migrating off WooMultistore must still re-pair every store manually after running the import.
- **Global WooCommerce attribute creation during product apply is retry-driven**: `ProductApplier::sync_attributes()` deliberately throws a `RuntimeException` after creating a missing global attribute taxonomy, forcing the delivery to fail-and-retry once WooCommerce has registered it on the next request — an intentional but slightly unusual control-flow pattern (documented inline) rather than a bug.
- **`docs/STAGING-ACCEPTANCE.md`** is explicitly a pre-launch manual test checklist (not automated tests) — there is no visible automated test suite (unit/integration tests) anywhere in the repository; QA is procedural/manual per this document.
- **`docs/SQUARE-INVENTORY.md`** is a supplementary internal doc covering the Square-integration inventory flow (`kitgenix_multistore/external_inventory_captured`, "WooCommerce Square" in `Registry::statuses()`) — not referenced elsewhere in this document but worth knowing about alongside `FEATURE-PARITY.md`/`STAGING-ACCEPTANCE.md`.
