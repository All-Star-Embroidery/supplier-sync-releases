# All Star Bulk Order Block v1.1.4

Pricing-integrity release aligned with ASE Supplier Sync v2.0.26+.

## Pricing source of truth

- WooCommerce **Regular Price** is now the authoritative customer-facing **1+ price** everywhere in ASBO.
- ASBO no longer uses WooCommerce's current/sale price as the 1+ base.
- Legacy `1:` entries in `_asbo_pricing_matrix` are ignored by both storefront JavaScript and server-side cart pricing.
- All established ASBO customer bulk tiers above 1 remain unchanged.
- The pricing table always renders the WooCommerce Regular Price in the `1+` column, even if legacy product metadata still contains a matrix `1:` entry.

## Supplier Sync separation

ASBO does **not** derive customer pricing from supplier data. It does not read or expose:

- `unit_buy_price`
- supplier `price_breaks`
- MAP
- MSRP
- suggested retail
- list/reference prices

Supplier Sync remains responsible for calculating and writing WooCommerce Regular Price. ASBO consumes that storefront price and the customer-facing `_asbo_pricing_matrix` only.

## Savings and checkout behavior

- `Total Saved` now uses WooCommerce Regular Price as the normal-price baseline before adding configured digitizing and shipping savings.
- Missing WooCommerce Regular Price now blocks the affected selection with a clear Supplier Sync → Quick Repair message.
- Removed the old ASBO purchasability override that allowed supplier variations through WooCommerce while their base price was blank.
- Stock, variation ownership, exact attributes, atomic rollback, and Supplier Sync compatibility remain intact.

## Customer discount ladder

No customer bulk thresholds or bulk prices were changed in this release. Any future margin/discount-ladder redesign should be handled separately after agreeing on desired margin behavior.
