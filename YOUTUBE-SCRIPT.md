# YouTube Script: Kitgenix MultiStore Sync for WooCommerce

**Suggested video title:** Run a Whole Network of WooCommerce Stores From One Place – Kitgenix MultiStore Sync (Full Walkthrough)

**Alternative titles:**
- Sync Products, Stock & Orders Across Multiple WooCommerce Stores – Kitgenix MultiStore Sync
- WooCommerce Multi-Store Done Properly: One Control Store, One Source of Truth

**Estimated runtime:** about 16–17 minutes (roughly 2,800 spoken words at a typical presenting pace of 165–175 words per minute; allow a little longer if screen demos run with pauses)

**Plugin version covered:** 2.1.0 (requires WordPress 6.8+, PHP 8.1+, WooCommerce 10.0+, installed on every store in the network)

---

## 1. Hook

[ON SCREEN: Fast montage – four browser tabs on four different WooCommerce admin screens. A cursor copies a product title from one tab and pastes it into another. A spreadsheet called "stock_reconcile_FINAL_v3.xlsx" flashes up.]

If you run more than one WooCommerce store – a main brand site plus regional shops, a franchise network, or retail and wholesale side by side – you know the pain.

Every new product gets built three, four, five times. Prices drift out of line. One site says "in stock" when another sold the last one an hour ago. And every week, someone's pulling orders out of every dashboard and stitching them together in a spreadsheet.

[ON SCREEN: Text overlay – "Option A: expensive enterprise software. Option B: copy and paste forever."]

Usually your choice is a big, rigid enterprise platform – or doing it all by hand. Today we'll show you a third option.

## 2. Intro

[ON SCREEN: Kitgenix logo animation, then the plugin name: "Kitgenix MultiStore Sync for WooCommerce".]

This is Kitgenix MultiStore Sync for WooCommerce – the big one in the Kitgenix WooCommerce suite.

Our Stock Sync plugin does one job: keeping stock levels in line. MultiStore Sync goes much further. It coordinates products and variations, inventory, orders and coupons – and, if you switch them on, customers and product reviews – across a whole network of WooCommerce stores.

[ON SCREEN: Diagram – one store icon in the centre labelled "Control", three around it labelled "Connected".]

It's built for brands with regional storefronts, franchises, retail-and-wholesale setups, agencies running groups of client stores, and Multisite networks with several shops.

The promise in one sentence: build your catalogue once, in one place, keep every store in step – and see every store's orders centrally.

## 3. Why We Built This

[ON SCREEN: Two columns. Left: "Stock-only syncers" with a single stock icon. Right: "Enterprise platforms" with a tangled flowchart. A gap in the middle with a question mark.]

When we looked at what existed for WooCommerce store networks, we kept seeing the same gap.

On one side, simple syncers that copy a stock number between sites. On the other, heavyweight platforms that want to run your whole business.

Most networks need something in between: full product, inventory, order and coupon sync, with real control over which store is in charge and what each store gets.

[ON SCREEN: Text overlay – "No Kitgenix cloud relay. Direct, store-to-store."]

And it should run on your own stores. There's no Kitgenix-hosted relay in the middle – your stores talk directly to each other over signed requests, and nothing is sent anywhere until you deliberately connect a store.

## 4. The Control + Connected Model, Explained Simply

[ON SCREEN: Hub-and-spoke diagram, larger. Highlight the centre store: "Control Store – source of truth".]

Before anything else makes sense, you need one mental model.

One store is the **Control Store**. Think of it as headquarters: it holds the master catalogue, your pricing rules and policies, and the central view of everything happening across the network.

Every other store is a **Connected Store**. Connected Stores receive product data from the Control Store, and send their orders back up to it.

[ON SCREEN: Blue arrows labelled "Products, coupons, reviews" flow outward from Control. Green arrows labelled "Orders, customers" flow inward. A purple label in the centre: "Inventory – one authoritative number".]

It's hub-and-spoke, not peer-to-peer. A Connected Store only ever talks to its Control Store, never directly to another Connected Store. So:

- Products, coupons and reviews flow **outward** from the Control Store by default.
- Orders and customer records flow **inward**, up to the Control Store.
- Inventory is one authoritative number that every store converges on.

Hold on to that picture – everything else hangs off it.

## 5. Connecting Stores

[ON SCREEN: Split screen. Left: "Separate installs – Pairing". Right: "WordPress Multisite – Native discovery".]

There are two ways to build your network, depending on how your stores are hosted.

[ON SCREEN: wp-admin → Kitgenix → MultiStore → Stores on the Control Store. Click "Generate one-time pairing code". An 8-character code appears.]

If your stores are separate WordPress installs – different domains, different hosting – you **pair** them. Install WooCommerce and MultiStore Sync on every store. On your intended Control Store, go to MultiStore, then Stores, and generate a one-time pairing code. It's valid for ten minutes.

[ON SCREEN: Second store's Stores view. Type the Control Store URL, paste the code, click connect. Back on Control, the new store appears as "Connected".]

On the other store, enter the Control Store's URL and that code. Done – the two stores are paired, and the new one takes the Connected role.

[ON SCREEN: MultiStore → Settings on a Multisite network. Switch Network mode to Native WordPress Multisite and choose the Control Site.]

If your shops are subsites of one **WordPress Multisite** network, you don't need codes at all. Turn on Native Multisite mode in Settings, pick your Control Site, and the other subsites are discovered and connected automatically – including new subsites you add later. The sync engine underneath is identical either way.

### Security, in plain terms

[ON SCREEN: Two stores with a padlock between them. Callouts appear as each point is mentioned.]

You're connecting live shops, so let's talk security.

During pairing, a random shared secret is generated. It travels once, in that initial handshake, and is then stored encrypted at rest with AES-256-GCM, using a key derived from your own WordPress security salts.

After that, every request between stores is signed with HMAC-SHA256 – covering the method, route, a timestamp, a single-use nonce and the body. Anything unsigned, more than five minutes old, replayed or signed with the wrong secret is rejected. Stores can also only make the kinds of requests that fit their role.

[ON SCREEN: Callout – "HTTPS required for production pairings."]

Production pairings require HTTPS. Plain HTTP is only accepted between two sites the plugin recognises as local or development – handy for testing, never allowed live-to-live. And pairing attempts are rate-limited.

[ON SCREEN: Stores view – connection cards with badges: Connected, Warning, Offline, Authentication Failed, Version Incompatible. Highlight the "Pause" button.]

Once connected, the Stores screen shows each store's health – Connected, Warning, Offline, Authentication Failed or Version Incompatible – with its versions and when it was last seen. Health checks run every five minutes. Need to take a store out of the loop? Pause it: it keeps its connection and mappings, but stops receiving new deliveries.

## 6. Product & Pricing Sync

[ON SCREEN: Edit a product on the Control Store, change the title, click Update.]

Now the good part. Edit a product on the Control Store, click Update, and the change is captured immediately and queued for every relevant Connected Store. Delivery runs in the background through Action Scheduler – typically within seconds.

[ON SCREEN: Overlay – "Variations, attributes, categories, tags, brands, images & alt text, downloads, upsells & cross-sells, SEO metadata, custom fields…"]

It's the whole product: variations, attributes, categories, tags, brands, images with their alt text, downloads, linked products, SEO metadata, and any custom meta keys or taxonomies you choose to include.

And it's careful. If an update matches what's already there, nothing is written. Every change carries a revision number, so if an older update arrives late, it's skipped rather than overwriting newer data.

[ON SCREEN: Settings → Product & catalog behaviour. Highlight "SKU-assisted linking" and the "Product direction" dropdown.]

Two settings worth knowing. **SKU-assisted linking**, on by default: if a Connected Store already has a product with the same SKU, it's adopted into the network instead of duplicated. And **product direction**: the default, "Control authoritative", sends products outward only – the safer choice. Switch to "Bidirectional" and product edits made on a Connected Store are sent up to the Control Store, which passes them on to everyone else.

### Which stores get which products

[ON SCREEN: Product edit → Product data → MultiStore tab. Sync checkbox, target stores checklist, target groups checklist.]

Not every product belongs everywhere. Every product gets a MultiStore tab in its Product Data panel, where you can switch its sync off or limit it to specific stores.

[ON SCREEN: Stores view → Store groups. Create "EU stores" and tick two Connected Stores.]

Or use **store groups** – named groups like "EU stores" or "Trade" – and target a product at a group instead. Exclusions stick: background checks and bulk tools respect them, so an excluded product is never "repaired" back onto a store you kept it off.

### Per-store pricing rules

[ON SCREEN: Stores view → pricing rules. Configure a store: mode "Exchange", multiplier 1.17, rounding "Charm .99", sale handling "Maintain discount percent".]

Each Connected Store can have its own price rule: a percentage, a fixed amount, or an exchange-rate multiplier. Add optional rounding – nearest whole number, nearest 0.50, or charm pricing ending .95 or .99. Then choose how sale prices behave: same rule, keep the original discount percentage, pass the source sale price through, or send no sale price.

[ON SCREEN: Overlay – "Always calculated from the source price. Never compounded."]

Crucially, prices are always recalculated from the Control Store's source price – sync a product fifty times and the rule applies once, not fifty times over. And for one-off cases, there's a per-product, per-store price override in the MultiStore tab that always wins over the rule.

### Store policies

[ON SCREEN: Stores view → policy matrix. Rows of fields – Product name, Description, Price, Images, SEO metadata… – with "Initial Publish" and "Later Update" columns.]

Finally, per-store field policies. For each Connected Store, a matrix of over thirty product fields, with two columns: Initial Publish and Later Update.

So a regional store can receive the full product when it's first published – but on later updates you stop sending the description, and that store's team can write their own copy without it being overwritten. A Connected Store can also mark specific fields on a specific product as locally owned.

## 7. Inventory – One Source of Truth

[ON SCREEN: Two stores showing "Stock: 10". Each sells one. Both push "9". Red warning: "Real stock: 8".]

Inventory is where many multi-store setups quietly fall apart. The usual approach is "whoever synced last wins." Store A sells one and pushes nine. Store B also sold one and pushes nine. Real stock is eight – your network thinks it's nine, and you oversell.

[ON SCREEN: Store B sends "–1" to the Control Store. Its ledger goes 10 → 9 → 8, then broadcasts "8" to every store.]

MultiStore Sync works differently. There's one authoritative stock value per product, held on the Control Store. When a Connected Store sells something, it doesn't say "stock is now nine" – it says "I sold one." The Control Store applies that change to its ledger inside a database transaction, then sends the result out to every store.

Each change is recorded exactly once – a retried delivery can't be double-counted – and revision numbers mean an older value can never overwrite a newer one.

[ON SCREEN: MultiStore → Inventory view – quantity, status, revision, source, updated.]

You can see it all in the Inventory view. And because real life includes outages, an hourly background reconciliation compares the ledger with each Connected Store's actual stock and repairs any drift.

[ON SCREEN: Connected Store cart – a customer tries to buy 5; an error notice says the network doesn't have enough.]

There's a checkout safety net too. With checkout validation – on by default – a Connected Store checks the Control Store's live stock at cart and checkout, and warns the customer if they're asking for more than the network has. For extra strictness, a fail-closed option blocks checkout if the Control Store can't be reached.

[ON SCREEN: Flow – "Square POS" → "Control Store (WooCommerce Square)" → "Authoritative inventory" → "Connected Stores".]

Restocks from refunds and failed orders flow back into the authoritative number too. And if you sell in person with Square: when WooCommerce Square updates stock on your Control Store, MultiStore Sync picks up that saved quantity and sends it to your Connected Stores – which don't need Square installed. It never calls Square's API or stores Square credentials.

## 8. Orders & Customers Flow Upward

[ON SCREEN: Hub diagram – green arrows carrying order icons flow inward from three Connected Stores.]

Now the other direction. Orders flow one way only: from Connected Stores up to the Control Store.

That's deliberate. Headquarters is where you want one place to see every order, report across the network and coordinate fulfilment – without stores pushing data sideways at each other.

[ON SCREEN: MultiStore → Orders on the Control Store. Search by email, filter by store, bulk-change status, click "Export XLSX".]

By default, orders are indexed centrally as snapshots. The Orders view gives you one searchable list across every store – filter by store, status or date, change statuses in bulk, and export to CSV or XLSX.

[ON SCREEN: One order's note and refund forms.]

You can act from the centre too: update a remote order's status, add a note, or record a refund on the store where the order lives. Pushing that refund through the remote payment gateway is a separate opt-in, off by default.

[ON SCREEN: Settings → Orders. Highlight "Order import mode", "Sequential order numbers", "Network My Account orders".]

Three optional extras, all off by default:

- **Import as real WooCommerce orders** on the Control Store – tagged, with no duplicate customer emails and no double stock movement, plus optional hidden draft placeholders for SKUs it doesn't have yet.
- **Network sequential order numbers** – one running sequence across every store, with your own prefix and starting number.
- **Cross-store My Account history** – customers see a read-only list of their orders from your other stores.

[ON SCREEN: Analytics view – 30-day revenue tiles per currency.]

There's also a thirty-day revenue view by currency.

Customer sync follows the same upward path and is off by default – it's personal data, so switch it on only if you need it. Coupons go the other way: create one on the Control Store and it's distributed outward. And review sync is opt-in, either outward-only or shared across the whole network.

## 9. Bulk Tools & Automations

[ON SCREEN: MultiStore → Sync Centre → Bulk Operations. Start a dry run, then a full product sync. Progress bar fills, stat tiles update, "recently synced" feed scrolls.]

When you first connect a network – or you have thousands of products – you don't sync one at a time. That's what Bulk Operations in the Sync Centre is for.

Run a full product sync or a full inventory reconciliation. Both work in small batches, so they won't time out on large catalogues. Progress is saved as it goes – close the tab and it keeps running. Pause, resume or stop at any time. And always start with a **dry run**: it reports what would happen – drift found, products excluded – without changing or sending anything.

[ON SCREEN: WooCommerce → Products. Show the "MultiStore" column. Bulk Edit ten products, set the MultiStore dropdown to "Enable and sync now".]

For everyday work, MultiStore is built into WooCommerce's product list: a column showing sync status, and a MultiStore option in Quick Edit and Bulk Edit – "Enable", "Disable" or "Enable and sync now". WP All Import imports are queued for sync automatically.

[ON SCREEN: Sync Centre – conflicts table and deliveries table with a "Retry" button.]

The Sync Centre also shows the plumbing: events, deliveries and conflicts. Failed deliveries retry automatically with increasing delays – eight attempts by default – and anything that still fails becomes a conflict for you to review. Nothing is silently dropped.

[ON SCREEN: MultiStore → Automations. "Add inventory automation": name "Pause low-stock products", trigger "Stock below", threshold 3, status "Draft".]

**Automations** are simple and stock-focused: when stock drops below – or rises above – a threshold, set the product to published, draft or private. Pull a product to draft below three units; republish it above ten. They run off the authoritative stock number, so they react to real network stock.

## 10. The Network Doctor

[ON SCREEN: MultiStore → Doctor. Local checks with green ticks and one red cross, per-store checks below, a big percentage score.]

With several stores talking to each other, you want to catch problems before customers do. Meet the Network Doctor.

It checks your own store – WordPress, WooCommerce and PHP versions, HTTPS, REST and permalinks, OpenSSL, Action Scheduler, HPOS, the plugin's database tables, and whether there are genuine unresolved sync failures. Then it asks every connected store to run the same checks and report back, and gives you an overall score.

It ignores noise: a timeout that retried successfully doesn't count against you – only genuine failures do.

[ON SCREEN: MultiStore → Logs. Searchable log, coloured badges, error-category reference table.]

Alongside it, the Logs view has a plain-English reference explaining each error category – and whether it needs you, or will sort itself out. Logs are kept for seven, fourteen or thirty days, with a manual clear button.

Run the Doctor right after connecting your stores, and any time something feels off.

## 11. Migrating From WooMultistore

[ON SCREEN: MultiStore → Migration – "WooMultistore metadata was detected" with an import button.]

Coming from the WooMultistore plugin? The Migration view detects WooMultistore's product data and, in one click, converts those product relationships into MultiStore Sync network mappings – so your first full sync links products instead of duplicating them.

[ON SCREEN: Overlay – "Store connections aren't migrated – re-pair each store."]

It doesn't carry over WooMultistore's store connections, so afterwards you pair each store, then run a full sync from Bulk Operations.

## 12. For Developers & Agencies

[ON SCREEN: Terminal – run `wp kitgenix-multistore-sync-for-woocommerce status`, then `bulk-sync --dry-run` with a live progress bar.]

For agencies and developers, there's a WP-CLI command set under `wp kitgenix-multistore-sync-for-woocommerce`: `status`, `doctor`, `reconcile`, `sync-product`, `index-orders` for backfilling historical orders, plus `bulk-sync` and `bulk-reconcile` with dry-run flags and live progress bars. Network maintenance becomes scriptable.

[ON SCREEN: REST routes under `kitgenix-multistore-sync-for-woocommerce/v1` – /pair, /health, /events, /inventory/…, /orders/…, /diagnostics. Then the Integrations view.]

Stores talk over a documented set of signed REST routes, and there are actions and filters to extend synced meta, modify payloads, or react when inventory changes. The Integrations view shows adapters for extensions like WPML, Polylang, Rank Math, Yoast and ATUM – and you can limit MultiStore management to the user roles you choose.

## 13. Real-World Example

[ON SCREEN: Title card – "A homeware brand: one Control Store, three Connected Stores".]

Let's put it all together. A homeware brand. Their UK site is the Control Store, with three Connected Stores: an Ireland store in euros, a wholesale store for trade, and a franchise partner's store.

[ON SCREEN: Stores view – three stores "Connected". Groups: "Retail" (Ireland, Franchise), "Trade" (Wholesale).]

Setup: three stores paired with one-time codes. Two groups – "Retail" and "Trade". Pricing rules: Ireland uses an exchange-rate multiplier with .99 charm pricing; wholesale gets minus thirty percent; the franchise sells at UK price. And the franchise store receives descriptions on first publish only, so the franchisee can write their own.

[ON SCREEN: UK Control Store – new product "Oak Serving Board", £40, stock 100, images, category "Kitchen". MultiStore tab: target both groups. Publish.]

Monday: the UK team launches an oak serving board – forty pounds, a hundred in stock – targets both groups and clicks Publish.

[ON SCREEN: Cut between three Connected Stores. Ireland: €46.99. Wholesale: £28.00. Franchise: £40.00.]

Moments later, it's live on all three, images and categories included. Ireland shows €46.99, wholesale twenty-eight pounds, the franchise forty pounds – and when the franchisee rewrites the description, future updates leave it alone.

[ON SCREEN: Ireland checkout for two boards. Control Inventory view: 100 → 98. All stores show 98.]

Tuesday: a Dublin customer buys two. Ireland checks live network stock at checkout, the order goes through, and the store reports "two sold". The authoritative stock drops to ninety-eight, and that number goes out to every store.

[ON SCREEN: Control Orders view – the Ireland order, tagged with its store. Add a note.]

On the UK site, the order appears in the central Orders view, tagged with the Ireland store, ready for fulfilment.

[ON SCREEN: Automation fires – product status flips to Draft. Then Doctor at 100%.]

Weeks later, stock falls below the low-stock threshold and an automation moves the board to draft until it's restocked. On Friday, the Network Doctor shows every store healthy. Nobody copy-pasted a single product.

## 14. Tips & Best Practices

[ON SCREEN: Numbered checklist building up item by item.]

A few tips from us:

1. **Choose your Control Store carefully** – it should be where your catalogue is managed day to day.
2. **Start with "Control authoritative"** product direction. Only go Bidirectional if you really need it.
3. **Set up groups, pricing rules and policies before your first full sync** – much easier than unpicking a catalogue later.
4. **Dry-run first**, every time you run a big Bulk Operation.
5. **Keep SKUs unique and consistent** so SKU-assisted linking can match existing products.
6. **Run the Network Doctor** after connecting, and make sure Action Scheduler is healthy on every store.
7. **Only enable the data you need** – customer sync, review sync, order import and gateway refunds are off by default for good reason.
8. **Test on staging** – local and development sites are recognised, so you can try everything before going live.

[ON SCREEN: Settings → Permissions & Safety – "Remove all MultiStore data on uninstall" (off).]

And for peace of mind: deactivating never removes data, deleting only removes MultiStore's own data if you've opted in, and the plugin never deletes your actual WooCommerce products or orders.

## 15. Recap & Call to Action

[ON SCREEN: Hub diagram one last time, each element lighting up as it's named.]

So, to recap: one Control Store as the source of truth, as many Connected Stores as you need – paired securely, or discovered automatically on Multisite. Products flow out, shaped by groups, pricing rules and policies. Inventory is one authoritative number. Orders flow back to one central place. And Bulk Operations, Automations, the Network Doctor and WP-CLI keep it manageable as you grow – all store-to-store, with no relay in the middle.

[ON SCREEN: Kitgenix logo, kitgenix.com, subscribe animation, "links in the description".]

If you run more than one WooCommerce store, give it a try: pair two stores on staging, run the Network Doctor, do a dry-run sync, and see how it fits.

The plugin, documentation and more Kitgenix tools are linked in the description. If this helped, like and subscribe – we've got deeper dives on individual features coming – and tell us about your multi-store setup in the comments.

Thanks for watching – see you in the next one.

[ON SCREEN: End screen with links to related Kitgenix videos.]
