# All Star Supplier Sync V1 — Architecture Contract

## Source of truth

- WooCommerce contains only the products All Star has deliberately imported.
- The supplier cache is a browse/sync source, not a second storefront catalog.
- Supplier SKU rows define exact sellable Color + Size combinations for both SanMar and S&S.
- Never generate the Cartesian product of parent Color and Size attributes.

## Product identity

Parent identity remains supplier-scoped until multi-supplier linking is enabled:

- `sanmar | brand | style`
- `ss | brandID | styleID`

Variation identity priority:

1. SanMar `UNIQUE_KEY` or S&S permanent `skuID_Master`.
2. Exact Color + Size combination when a supplier identifier is unavailable.

A WooCommerce product variation cannot represent two different supplier rows with the same exact Color + Size attributes. If a supplier feed duplicates that combination, V1 keeps the richer source row and disables legacy duplicates rather than deleting them.

## Supplier-owned fields

These may be refreshed from SanMar when available:

- supplier/brand/style mapping
- supplier unique/inventory keys
- available exact Color + Size variations
- weight
- supplier status/discontinued state
- stock quantity (inventory workflow)
- supplier cost/MAP metadata
- supplier/spec-sheet references
- supplier-managed product and variation images/galleries
- global Color/Size attribute terms needed by real variations

## Merchant/store-owned fields

These are protected from routine supplier replacement:

- ASBO tiered pricing matrix
- manually-entered variation regular/sale prices (Supplier Sync tracks the last managed base value and relinquishes ownership when the merchant edits it)
- manually-selected primary/gallery images
- manually-edited product description after Supplier Sync detects an edit
- merchant tags beyond the supplier-managed tag set
- manually entered shipping dimensions/classes when supplier data does not provide them
- publication/visibility decision after a previously archived product appears active again
- non-Color/Size product attributes added manually or by another plugin (preserved during supplier repair)

## Variation reconciliation

For every imported/quick-repaired style:

1. Filter to the admin's saved color selection.
2. Hydrate complete image sets across only the real size rows of each color.
3. De-duplicate supplier identities and exact Color + Size combinations.
4. Set parent Color and Size attributes to the union used by the selected real rows while preserving all non-Color/Size product attributes.
5. Create/update every expected real variation.
6. Reassert attributes, SKU, supplier IDs, weight, price ownership and images/gallery.
7. Disable stale, duplicate, and phantom Cartesian children as Private + Out of stock.
8. Repair blank WooCommerce base prices only after invalid children are disabled.
9. Run a variation audit and clear WooCommerce variable-product transients.

## ASBO contract

Supplier Sync integrates using:

- `_asbo_enabled`
- `_asbo_display_name`
- `_asbo_short_description`
- `_asbo_size_chart`
- `_asbo_pricing_matrix`

Supplier Sync never replaces `_asbo_pricing_matrix`. ASBO remains responsible for decorated/tiered cart pricing. Supplier Sync ensures each real WooCommerce variation has a valid base price so WooCommerce considers it purchasable before ASBO applies its selected tier.

## Product data bridge

GitHub:

1. Discovers canonical SanMar brand feeds.
2. Syncs only enabled and changed brands.
3. Normalizes exact supplier variation rows.
4. Uploads in requests capped at 25 styles and approximately 4 MiB of product JSON.

WordPress:

1. Stages all chunks for a generation.
2. Writes full detail into per-style files.
3. Publishes only a lightweight brand index after the entire batch is present.
4. Keeps the previous live generation available until the new one is complete.
5. Queues updates only for linked WooCommerce products.

## Inventory bridge

GitHub requests only active linked variations. WordPress excludes archived parents and stale/discontinued/private supplier children from targets.

Before writing stock, GitHub requires complete target coverage. A zero-target store is a successful no-op and does not download the large SanMar inventory file.

## Discontinued / reactivated behavior

- Discontinued variation: Private + Out of stock, retained for historical orders.
- Entire discontinued product: Draft + hidden + Out of stock, retained in WooCommerce.
- Admin notification occurs when a product is newly archived.
- If a later product feed appears active again, the product remains archived and the admin is notified once for review.
- Deliberately publishing the product in WooCommerce clears Supplier Sync archive markers.

## Extension hooks

- `asss_product_synced`
- `asss_variation_synced`

These are the V1 integration points for other All Star plugins and future supplier adapters.

## WordPress fallback scheduling

GitHub Actions is the V1 production scheduler. Legacy WordPress direct-transfer cron hooks are unscheduled when their fallback settings are off and are only created when an administrator explicitly enables those fallback modes.

## Repair queue

A single Quick Repair can run immediately. Multi-product Repair Selected and automatic linked-product refreshes are queued through WooCommerce's Action Scheduler when available, with WP-Cron as a fallback. This prevents large catalogs from being repaired in one long admin/REST request while preserving each product's saved color policy.


## S&S Step 6 import contract

- S&S catalog browsing is cache-backed; enabling a brand never creates WooCommerce products.
- An admin must choose colors and explicitly create/update a WooCommerce Draft.
- Exact S&S SKU rows are the only allowed variation combinations.
- S&S SKU, permanent SKU ID, GTIN, weight, country of origin, supplier cost tiers, MAP/suggested retail, case metadata, warehouse metadata, inventory snapshot, and ordered image gallery are preserved when available.
- Supplier cost is never the storefront regular price. ASBO base pricing wins when configured; otherwise MAP/suggested retail is used as a draft review price.
- Existing merchant regular/sale prices, descriptions, imagery, categories, tags, and non-Color/Size attributes remain protected.
- All supplier categories are applied, while manually-added categories remain in place.
- S&S case dimensions are not treated as per-piece WooCommerce dimensions.
- If a matching SanMar Brand + Style product already exists, Step 6 blocks creation of a duplicate and waits for the multi-supplier linking layer.


## S&S hourly inventory (v1.4)

GitHub queries WordPress for exact active S&S variation targets, requests only those supplier SKUs from the S&S Inventory API, validates complete coverage, then pushes a small normalized snapshot back to WordPress. Missing supplier responses never mean zero stock; incomplete runs fail without applying stock. The S&S workflow runs nominally at :37 each hour, offset from SanMar at :17.
