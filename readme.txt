=== All Star Supplier Sync ===
Contributors: allstar
Tags: woocommerce, supplier, inventory, sanmar, ssactivewear, github-actions
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.0.3
License: Proprietary

Curated multi-supplier-to-WooCommerce synchronization for All Star. SanMar and S&S Activewear connectors included.

== Description ==

V2 keeps WooCommerce as the customer-facing catalog while supplier catalogs remain lightweight browse/sync sources. Admins select only the products and colors they actually sell; GitHub Actions handles supplier data and inventory, and one WooCommerce product can now carry SanMar, S&S Activewear, or both.

Core V2 behavior:

* Exact sparse supplier variation matrices. Real supplier rows define sellable combinations; Supplier Sync never creates a Cartesian Color x Size expansion.
* Explicit multi-supplier linking prevents duplicate storefront products when the same Brand + Style is available from SanMar and S&S.
* Conservative matching uses existing permanent supplier identity, exact GTIN where available, then exact Color + Size. Size equivalence is never guessed globally; only verified style-specific aliases are allowed.
* Supplier-specific stock, cost, SKU/identity and S&S GTIN remain separate even when both suppliers map to one WooCommerce variation.
* Combined inventory or Preferred Supplier inventory can be selected globally, with product-level overrides.
* Full color-specific variation galleries are retained where supplied, while merchant-owned imagery remains protected.
* Every reliable supplier category is added without erasing merchant categories, improving WooCommerce and ASBO/bulk-order sorting.
* Supplier-managed Main Price is calculated from the preferred available supplier wholesale cost + $20. Manual price edits take ownership and are preserved.
* Supplier-managed ASBO tiers are generated as Embroidery Main / Main-$2 / Main-$4 / Main-$6 at 1/20/50/100 units, with Patch always +$3. Manual ASBO matrix edits take ownership and are preserved.
* Active Products provides Quick Repair and queued Repair Selected.
* Supplier Intelligence shows source coverage, stock, cost ranges and effective sources; Manage Suppliers provides variation-level source detail.
* System Status reports supplier/catalog/bridge health, hourly inventory status, next nominal inventory checks, ASBO readiness and variation-gallery readiness.
* Discontinued source rows disable only that supplier relationship when another active supplier can still fulfill the variation.
* Archived/historical products and variations are never deleted by routine supplier synchronization.

== Installation / upgrade ==

1. Back up the WordPress database before a major production upgrade.
2. Upload and activate/replace the plugin with WooCommerce active.
3. Open Suppliers -> System Status and confirm V2, WooCommerce, bridge and supplier checks are healthy.
4. Existing SanMar/S&S products migrate lazily; no destructive database migration is required.
5. Existing GitHub secrets/workflows remain valid. SanMar inventory runs nominally at :17 and S&S at :37 each hour.
6. Use Suppliers -> Add Products to import new styles or explicitly link a second supplier to an existing Brand + Style product.

== Changelog ==

= 2.0.3 =
* Active Products now lists every enabled supplier source for each WooCommerce product.
* Fixed Bridge Token Show/Hide with a direct no-submit toggle.
* Added Momentec Brands as a third supplier key with credential/settings/capability groundwork; live API calls remain disabled until credentials are verified.
* Preserved Richardson 112 style-specific OSFA ↔ M/L canonical matching from 2.0.2.
* Prepared automated GitHub release workflow assets for future version publishing.

= 2.0.2 =
* Moved the default public update source to `rolejarczyk/ASE.SupplierSync-Releases` and migrates the previous `robrosco` release setting automatically.
* Added a `latest.json` manifest fallback so the public release repository can deliver WordPress updates even when a formal GitHub Release has not been created yet.
* Added a conservative style-specific size-alias framework for multi-supplier linking. Richardson 112 now treats SanMar `OSFA` and S&S `M/L` as the same standard-fit variation when color matches exactly, while retaining both suppliers' raw size labels.
* Global OSFA/M-L guessing remains disabled for every other product unless an explicit verified alias is added.


= 2.0.1 =
* Fixed Add Products thumbnails to prefer a real variation/product photo and hide failed image placeholders.
* Replaced oversized supplier all-colors imagery on review screens with one compact representative product image.
* Supplier disconnect now works for any source, including the final source. Only that supplier relationship/data is removed; shared variations and the WooCommerce parent remain. Supplier-only variations are safely removed from the active storefront rather than hard-deleted.
* Added disconnect controls to Active Products, Manage Suppliers, and supplier Add Products rows.
* Added Supplier Sync-managed pricing: variation Main Price = preferred available wholesale + $20, with manual Woo price ownership protection.
* Added Supplier Sync-managed ASBO pricing: Embroidery discounts of $0/$2/$4/$6 at quantities 1/20/50/100 and Patch +$3 at every tier. Existing manually edited ASBO matrices are preserved.
* Added public GitHub Releases update support, optional unattended plugin updates, and a manual Check GitHub for Updates action.
* GitHub Bridge Token is masked by default with Show/Hide controls.

= 2.0.0 =
* Added true multi-supplier product/variation architecture: one WooCommerce product can carry SanMar, S&S Activewear, or both.
* Added explicit Brand + Style duplicate detection and Link Supplier to Existing Product flows from both supplier review screens.
* Added supplier-specific parent and variation source records while preserving legacy metadata for compatibility.
* Added conservative variation matching by existing permanent supplier identity, exact GTIN, then exact Color + Size; no global fuzzy size mapping is performed; verified style-specific aliases can be defined.
* Added source-specific stale handling so removing a variation from one supplier does not disable the Woo variation when another active source remains.
* Added combined inventory and preferred-supplier inventory strategies, global source priority, stock-buffer-aware source aggregation and per-product strategy/preferred-supplier overrides.
* Updated both hourly inventory bridges so multi-source variations remain valid targets and each supplier updates only its own source inventory.
* SanMar discontinued rows no longer archive/disable a product or variation when another active supplier source remains available.
* Added Suppliers -> Supplier Intelligence and detailed Manage Product Suppliers screens with variation coverage, stock, supplier costs, supplier identities, S&S GTIN and effective inventory source.
* Multi-source products can safely disconnect one supplier source without deleting historical metadata or orphaning the WooCommerce product; remaining-source inventory is recalculated immediately.
* Improved SanMar Add Products and review UI to more closely match the faster S&S catalog flow, including category filtering, compact tables, product/color images and exact per-color size pills.
* Brand tables use faint row/column dividers and left-aligned compact information for both suppliers.
* Supplier categories are unioned across all supplier sources while preserving merchant-added categories.
* System Status / Sync now show separate SanMar and S&S inventory health plus next nominal hourly check times.
* Existing single-supplier products migrate lazily into the V2 source model without a destructive migration job.

= 1.4.0 =
* Added production hourly S&S Activewear inventory synchronization through the lightweight S&S Inventory API.
* GitHub requests only active exact S&S SKU targets, validates full coverage, and refuses partial/missing updates.
* S&S inventory runs nominally at :37, offset from SanMar's :17 workflow.

= 1.3.0 =
* Added controlled S&S -> WooCommerce importing with exact sparse variations, metadata, images/galleries, categories, pricing safety and ASBO integration.

= 1.2.0 =
* Added S&S enabled-brand product catalog caching and WordPress catalog browser/review screens.
* Improved supplier Brand tables and category handling.

= 1.0.0 =
* Production V1 architecture with sparse SanMar variation reconciliation, brand-aware identity, batched per-style cache, product/inventory GitHub bridges, ASBO protection, Active Products and System Status.
