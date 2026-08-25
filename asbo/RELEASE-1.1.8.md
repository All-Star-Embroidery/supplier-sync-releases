# All Star Bulk Order Block v1.1.8

Responsive pricing-matrix readability fix for tablet and mobile ordering.

## Pricing matrix

- Prevents bulk-order prices such as `$30.22`, `$29.22`, etc. from visually overlapping when the ordering block is viewed on a tablet or phone.
- Stops narrow screens from compressing all quantity tiers into columns that are physically smaller than the price text.
- On narrower viewports, the matrix keeps readable minimum column widths and uses a controlled horizontal swipe/scroll instead.
- Keeps the **Decoration method** column visible while the customer scrolls across the quantity tiers, so they always know whether they are looking at Embroidery or Patch pricing.
- Uses tabular numerals for cleaner price alignment.
- Mobile gets slightly tighter—but still readable—column widths than tablet.

## Desktop behavior

Desktop layouts above 1100px retain the existing full-width pricing table behavior. The fix only takes over where the current layout begins to squeeze the prices.

## Preserved behavior

No customer prices, quantity thresholds, bulk-discount ladder, WooCommerce Regular Price behavior, Supplier Sync cost architecture, 10K stitch policy, cart calculations, savings calculations, artwork flow, or checkout behavior changed in this release.
