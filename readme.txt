=== All Star Supplier Sync ===
Contributors: allstar
Tags: woocommerce, supplier, inventory, sanmar, ssactivewear, github-actions
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.0.23
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
* Supplier categories are mapped into a controlled storefront taxonomy without erasing merchant categories; raw supplier department/channel labels are retained only as internal metadata.
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

= 2.0.23 =
* Richardson style 112 now has one canonical storefront title across all supplier links: Snapback Trucker Cap (Richardson 112).
* Adds a shared discovery-taxonomy layer across SanMar, S&S, and Momentec for consistent customer-facing categories and product tags.
* Product tags are now controlled filter facets such as construction, fit/profile, materials, performance features, audience, and product type instead of raw supplier prose/codes.
* Supplier tags are owned per supplier and merged safely, preserving tags added manually and useful tags from other linked suppliers.
* Category fallback uses the product title to repair thin supplier categorization into the existing controlled storefront taxonomy without reintroducing raw supplier category noise.
* Existing supplier-linked products are reconciled once after upgrade; future imports, links, refreshes, and Quick Repair keep discovery taxonomy standardized.

= 2.0.22 =
* Momentec shipping weight now comes from the official full product-data feed by exact Item_SKU; /v2/Style remains the source for customer-specific style data.
* Weight_Unit is normalized to pounds in GitHub (lb/oz/kg/g supported), with the heaviest known style weight used only as a conservative fallback when a specific feed row lacks weight.
* WooCommerce Momentec variations now receive supplier-managed shipping weight, while merchant-edited weights remain protected. Parent products retain the maximum selected-variation weight as a safe fallback.
* Momentec variation audits now include missing shipping weight as a critical coverage field. GitHub publishing refuses hydration when no usable weight can be established.
* Existing Momentec-linked products automatically queue fresh GitHub hydration when their cached style predates weight support; already-weighted caches repair immediately.

= 2.0.21 =
* Standardized supplier categories across SanMar, S&S Activewear, and Momentec using one controlled WooCommerce storefront vocabulary instead of copying raw supplier department/channel strings into Product Categories.
* Headwear products now consistently receive Headwear, Hats, and Caps & Hats plus a useful subtype such as Caps, Bucket Hats, Beanies, or Visors when detected. Momentec labels such as Adult | HEADWEAR | HEADWEAR ASB / BUCKET HAT no longer become literal Woo categories.
* Raw supplier category values are retained in supplier-specific internal metadata for troubleshooting while stale unassigned supplier-created category terms are conservatively pruned.
* Standardized OS, OSFA, One Size, and One Size Fits All to the customer-facing label One Size Fits All across the whole WooCommerce variable-product catalog. Verified style-specific one-size aliases such as Richardson 112 M/L also display as One Size Fits All on supplier-linked products.
* Fixed the parent/child size mismatch that could turn Momentec OS variations into WooCommerce Any Size rows. Parent Size options and variation attributes now use the same canonical term from initial import onward.
* Added a one-time migration that repairs existing variable products, parent Size options, variation Size attributes, defaults, supplier categories, and supplier-linked one-size aliases without changing raw supplier size metadata.

= 2.0.20 =
* Moved bridge-token regeneration to a bottom-of-page Danger Zone and added acknowledgement, typed REGENERATE confirmation, browser confirmation, and server-side enforcement before a token can change.
* Newly created SanMar, S&S, and Momentec variable products now default to the first alphabetical real variation combination (Color, then Size), guaranteeing WooCommerce defaults point to a sellable child. Existing products/defaults are not changed.
* Added a Momentec Ready to Import view on Add Products for styles whose secure GitHub customer-detail hydration has completed.
* Momentec queue actions now preserve the current search, brand/category filters, catalog page, and All/Ready view so long catalog workflows do not reset unexpectedly.
* Updated plugin readme Stable Tag to match the current release.

= 2.0.19 =
* Corrected S&S placeholder cleanup for style-level TN assets such as CCRC0TN and FF180APTN; these are treated as supplier reference media rather than storefront photography.
* Cleanup now checks the original supplier URL plus the local attachment filename/title so WebP/optimized derivatives that lost Supplier Sync attachment metadata are also removed from affected S&S product/variation galleries.
* Added a new one-time S&S cleanup migration because sites that already ran the v2.0.18 migration need the refined TN rule to execute automatically.
* No supplier media files are deleted from the WordPress Media Library; the fix removes them from customer-facing featured/gallery slots while preserving ordinary merchant media.

= 2.0.18 =
* Added a global storefront-media denylist across SanMar, S&S Activewear, and Momentec for image-not-available placeholders, unavailable-color graphics, swatch sheets, color boards/squares, size charts, and similar supplier reference graphics.
* Invalid supplier graphics are blocked before sideload and removed from existing Supplier Sync-owned featured/product/variation galleries; merchant-uploaded media remains protected.
* Added a one-time local cleanup for existing supplier-linked products plus ongoing cleanup after every import, link, refresh, and Quick Repair.
* Canonicalized equivalent one-size supplier labels to customer-facing OSFA while preserving raw supplier size values for inventory/matching. OS, OSFA, One Size, and One Size Fits All are equivalent; Richardson 112's verified M/L alias also displays as OSFA.
* Extended cross-supplier one-size matching so OS and OSFA can share one WooCommerce variation without weakening unrelated M/L matching.


= 2.0.17 =
* Unified storefront-vs-reference media policy across SanMar, S&S Activewear, and Momentec.
* Supplier swatch sheets, color boards/chips, charts, unknown asset types, and Momentec alternate media are retained as supplier reference metadata but excluded from WooCommerce featured/product/variation galleries.
* SanMar COLOR_SWATCH_IMAGE is no longer imported into the customer-facing product gallery.
* SanMar featured media now prefers real product photography before generic product imagery.
* Momentec galleries now trust only known photographic view types; alternate/unknown media stays reference-only.
* Quick Repair/refresh removes previously imported Supplier Sync-owned reference graphics while preserving merchant-added featured/gallery images.

= 2.0.16 =
* S&S full-color boards, swatch sheets, generic style images, and unknown media asset types are excluded from customer-facing product and variation galleries.
* Only verified product photography (front, side, back, direct-side, and on-model angles) can enter S&S storefront galleries.
* Supplier reference graphics are retained separately in Supplier Sync metadata instead of being discarded or displayed to customers.
* S&S featured images no longer fall back to generic style-level imagery that may contain a full color panel.
* Quick Repair now removes stale Supplier Sync-owned S&S reference graphics from existing product/variation galleries while preserving merchant-added images.
* S&S variation-gallery audits now evaluate only verified storefront media, avoiding false missing-gallery warnings from reference graphics.

= 2.0.15 =
* Unified WooCommerce product-category import logic across SanMar, S&S Activewear, and Momentec.
* Categories are assigned during import, link, refresh, and Quick Repair while merchant-owned categories are preserved.
* Supplier category ownership is tracked separately so one supplier refresh cannot erase categories provided by another supplier.
* Explicit supplier hierarchy labels create real WooCommerce parent/child category terms instead of flat path-name duplicates.
* Momentec hydrated v2 styles now inherit the official full-catalog CSV category set, including already-cached styles.
* Added a one-time category-only reconciliation for existing supplier-linked products using local cache data only.


= 2.0.6 =
* Momentec production v2 is now a live Supplier Sync connector using WordPress <> GitHub Actions <> Momentec.
* Added secure normalized Momentec style cache; supplier credentials remain only in GitHub Actions Secrets.
* Added intentional style publishing because Momentec v2 does not expose a full catalog-list endpoint.
* Added Momentec Add Products browser, review screen, exact-color selection, new-product import, and existing-product linking.
* Added exact sparse Momentec Color+Size variation creation with no Cartesian combinations.
* Added Momentec customer-cost pricing integration using the existing preferred-supplier cost + $20 pricing rules while preserving manual overrides.
* Added color-specific variation galleries sourced from full-SKU Momentec v2 Style lookups.
* Added strict targeted Momentec inventory bridge endpoints and multi-supplier inventory support.
* Added Momentec Quick Repair and multi-supplier repair support from the normalized cache.


= 2.0.5 =
* Momentec now follows the project architecture: WordPress <> GitHub Actions <> supplier.
* Momentec username/password belong only in GitHub Actions Secrets as MOMENTEC_USERNAME and MOMENTEC_PASSWORD.
* Removed Momentec credential, API base URL, account number, and environment storage from WordPress.
* Automatically purges legacy Momentec connection values left by 2.0.3/2.0.4.
* WordPress Momentec settings now explain the GitHub bridge and credential preflight instead of accepting supplier connection details.


= 2.0.4 =
* Security hardening: Momentec API username and password are no longer accepted or stored in the WordPress database.
* Momentec credentials now come from ASSS_MOMENTEC_USERNAME and ASSS_MOMENTEC_PASSWORD in wp-config.php or server environment variables.
* Automatically removes legacy Momentec credential-shaped values saved by the 2.0.3 groundwork screen.
* Replaces credential entry fields with secure configuration status and setup instructions.


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
