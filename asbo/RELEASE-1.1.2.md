# All Star Bulk Order Block v1.1.2

Compact ordering, normal-price fallback, product-detail progressive disclosure, live savings, and persistent workflow-bar release.

## Changes

- The sticky bottom summary/action bar now appears as soon as the ASBO block loads, including the Intro step.
- The sticky CTA follows the current workflow action: **Select Products** on Intro, **Next: Artwork Plan** on Items, and **Continue to Checkout** on Artwork.
- Product accordions are now single-open on desktop, tablet, and mobile. Opening a product automatically closes the previously expanded product to prevent long stacked pages.
- Removed the permanently displayed large product hero/details area from expanded products on every viewport.
- Added an on-demand **Product details & size information** modal containing the large product image, product description, garment specifications, and size information.
- Product-detail modal closes with the X button, by clicking outside the modal, or by pressing Escape; focus returns to the opener after closing.
- Reduced product-row, expanded-panel, pricing, decoration, variant-grid, and sticky-bar spacing across desktop, tablet, and mobile for faster progression through an order.
- Pricing now always presents a **1+** level. If `_asbo_pricing_matrix` begins above quantity 1, quantities below the first configured bulk tier use the current WooCommerce product/variation price supplied by the storefront/Supplier Sync.
- Fixed estimated totals and WooCommerce cart pricing for quantities below the first bulk tier, including common 1–5 piece orders when the first decorated tier begins at 6+.
- Supports variation-specific normal prices below the first bulk threshold, so mixed colors/sizes can retain their correct WooCommerce base prices.
- Added **Total Saved** to the sticky summary bar.
- Total Saved includes the live volume-discount difference between normal WooCommerce pricing and ASBO pricing, plus **$15 digitizing savings** once the digitizing threshold is reached, plus the configured standard-shipping savings once the free-shipping threshold is reached.
- Added Gutenberg sidebar controls for the Total Saved label, digitizing savings amount, and shipping savings amount.
- Fixed the product-row **Selected total** label scope issue so the amount is clearly identified instead of appearing as an unexplained `$0.00` before quantities are selected.
- Preserves the existing slide-up/down sticky-bar animation, tier calculations, artwork workflow, checkout validation, and Supplier Sync compatibility.
- Supplier-agnostic compatibility remains intact for SanMar, S&S Activewear, Momentec, and multi-supplier WooCommerce variations.

## UX rationale

- Product rows retain the information customers need to scan and compare quickly: thumbnail, product name, selected quantity, and selected total.
- Secondary product information is available through explicit progressive disclosure instead of occupying permanent ordering space.
- Single-open accordions reduce accumulated page length and make the active task easier to follow.
- Pricing and selection controls stay visually dominant because they are the primary actions required to progress through the order.

## Validation

- PHP syntax validation passed.
- Gutenberg editor JavaScript syntax validation passed.
- Inline storefront JavaScript syntax validation passed.
- `block.json` validation passed.
- ZIP integrity validation passed.
- SHA-256: `27afc6d7c825b0951278f52afd1daa645f0f4807051e9e3ee9831754b6f3cb06`
- ZIP size: `36,356 bytes`
