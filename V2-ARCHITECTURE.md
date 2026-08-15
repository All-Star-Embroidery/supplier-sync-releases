# All Star Supplier Sync V2 — Multi-Supplier Architecture Contract

## Store model

WooCommerce remains the catalog customers actually shop. Supplier catalogs are cached sources used for discovery, import, repair, inventory, and supplier intelligence; enabling a supplier brand never creates WooCommerce products by itself.

A WooCommerce product can now have one or more supplier sources:

- SanMar
- S&S Activewear
- both suppliers on the same storefront product

The parent product stores supplier-specific source mappings in `_asss_supplier_sources`. Individual WooCommerce variations store supplier-specific identities, cost references, inventory, and source metadata in their own `_asss_supplier_sources` record.

Legacy single-supplier metadata remains in place for compatibility with ASBO, historical orders, and existing integrations.

## Exact variation rule

Supplier rows remain the definition of real sellable variations. V2 never creates a Color × Size Cartesian expansion.

When another supplier is linked to an existing product, variation matching is deliberately conservative:

1. Existing permanent supplier identity, when already linked.
2. Exact GTIN when a reliable GTIN is available.
3. Exact WooCommerce Color + Size combination.
4. Otherwise create a new supplier-only WooCommerce variation.

There is no global fuzzy size translation. Verified style-specific aliases may be defined when supplier evidence supports equivalence; V2.0.2 includes Richardson 112 `SanMar OSFA` ↔ `S&S M/L`. Supplier-specific raw labels are preserved even when two source rows map to one WooCommerce variation.

## Multi-supplier linking

The Add Products review screens detect an existing WooCommerce product with the same Brand + Style and offer an explicit **Link supplier to existing WooCommerce product** action instead of creating a duplicate storefront product.

Linking a secondary supplier:

- preserves the existing product title, description, merchant-owned retail pricing/ASBO matrix, manual images, and merchant categories;
- unions all reliable supplier categories with existing WooCommerce categories;
- attaches the supplier to matching variations by stable identity/GTIN/exact Color+Size;
- creates only real supplier-only variations that do not already exist;
- removes only the stale source relationship when a supplier no longer offers a selected variation;
- does not delete historical WooCommerce variations.

## Inventory model

SanMar and S&S inventory remain separate per variation. Hourly GitHub workflows update only their own source quantities:

- SanMar: nominal minute `:17`
- S&S Activewear: nominal minute `:37`

The WooCommerce variation stock quantity is recalculated from active supplier sources after each source update.

Two inventory strategies are supported:

### Combined inventory

Default. Available stock from all active, non-discontinued supplier sources is summed after applying the configured stock buffer to each source.

This allows the storefront to remain in stock if either supplier can fulfill the exact WooCommerce variation.

### Preferred supplier inventory

WooCommerce stock follows one preferred source. Global supplier priority is configurable, and an individual product can override both the strategy and preferred supplier.

Supplier Sync does not place vendor purchase orders automatically. Preferred/effective supplier information is decision support only.

## Supplier intelligence

**Suppliers → Supplier Intelligence** provides a unified view of:

- active supplier sources per WooCommerce product;
- total variation coverage;
- variations carried by both suppliers;
- supplier-only variation counts;
- supplier inventory totals;
- supplier cost ranges;
- effective inventory source counts;
- links to detailed per-product supplier management.

**Manage Product Suppliers** shows source data for each variation, including source stock, supplier cost, supplier SKU/identity, S&S GTIN where available, WooCommerce effective stock, and the effective inventory source.

## Content ownership

Supplier Sync uses a strict ownership hierarchy:

1. Merchant-authored/store-owned content.
2. Existing primary supplier-managed content.
3. Secondary supplier data used to backfill missing structural information and source intelligence.

Supplier cost is never silently exposed as a customer-facing retail price. ASBO remains the owner of decorated quantity-tier pricing. Supplier Sync only ensures real variations are structurally purchasable and retains pricing references as supplier metadata.

## Images and galleries

A supplier may provide multiple images for one color/variation. Supplier Sync preserves the available ordered image set and uses WooCommerce native variation galleries when supported.

Secondary-supplier linking never replaces a merchant-owned existing primary image solely because the secondary supplier has another image. Supplier image attachments are reused rather than downloaded repeatedly when multiple sizes share the same color imagery.

## Categories and bulk-order sorting

Supplier Sync assigns every reliable supplier category supplied by the active catalog adapter while preserving merchant-added categories. This makes WooCommerce categories available to ASBO/bulk-order filtering without reducing products to one broad supplier category.

The ASBO meta contract remains:

- `_asbo_enabled`
- `_asbo_display_name`
- `_asbo_short_description`
- `_asbo_size_chart`
- `_asbo_pricing_matrix`

Supplier Sync generates `_asbo_pricing_matrix` only while that matrix remains Supplier Sync-managed. A merchant edit immediately transfers ownership and future supplier syncs preserve the custom matrix.

## Discontinued handling

Discontinued supplier source rows are removed from that source's available inventory. A variation is disabled globally only when it has no remaining active, non-discontinued supplier source.

A parent product is not automatically archived because SanMar is discontinued when an active S&S source remains linked. Historical variations are disabled/private rather than deleted.

## Admin workflow

Normal daily use is:

1. Enable desired brands under **Suppliers → Brands**.
2. Let GitHub keep supplier catalogs current.
3. Browse **Suppliers → Add Products**.
4. Import a new product or explicitly link another supplier to an existing Brand + Style product.
5. Use **Suppliers → Active Products** for Quick Repair / Repair Selected.
6. Use **Suppliers → Supplier Intelligence** when comparing source stock/cost or managing a multi-source product.
7. Use **Suppliers → System Status** to verify product bridge, inventory bridge, next nominal inventory checks, catalog health, ASBO readiness, and variation-gallery readiness.

## Safety principles

- No supplier is automatically merged based on fuzzy text. Size equivalence is permitted only through an explicit verified Brand + Style alias such as Richardson 112 OSFA ↔ M/L.
- No partial inventory payload is applied.
- Missing supplier inventory is not silently interpreted as zero.
- Secondary supplier linking does not overwrite merchant-owned prices or a merchant-edited ASBO matrix; Supplier Sync-managed pricing may recalculate from the configured preferred source.
- Product/variation records are not deleted merely because a supplier stops carrying them.
- GitHub and WordPress both validate exact inventory identities before stock is changed.

## V2 pricing ownership

Supplier Sync may manage pricing when a product/variation has not been manually overridden. For each active variation, the preferred available supplier wholesale cost is selected using the product-level preferred supplier first and then global supplier priority. The managed WooCommerce Main Price is `wholesale + $20`.

Because ASBO stores one pricing matrix at the parent product level, the managed ASBO basis uses the **highest** managed Main Price among the product's active variations. This prevents a higher-cost size/color from being underpriced by a cheaper sibling variation. Managed tiers are:

- Embroidery: qty 1 = Main, 20 = Main - $2, 50 = Main - $4, 100 = Main - $6.
- Patch: the corresponding Embroidery tier + $3.

Supplier Sync records the last Woo variation price and ASBO matrix it wrote. If the merchant changes either value, ownership transfers to the merchant and routine supplier syncs stop overwriting that customized pricing.

## Source disconnection

A supplier can be disconnected even when it is the final source on a WooCommerce product. Disconnect removes that source from the parent and each variation. Shared variations remain active through their remaining source; supplier-only variations are made private/out-of-stock rather than deleted so historical orders remain intact. The parent WooCommerce product itself is preserved.

## Plugin updates

V2.0.2 uses the public release-only repository `rolejarczyk/ASE.SupplierSync-Releases`. WordPress never needs credentials for the private development repository. The updater prefers a published GitHub Release and falls back to the public `latest.json` manifest/package, so future builds can appear in the normal WordPress updater without a private GitHub token.
