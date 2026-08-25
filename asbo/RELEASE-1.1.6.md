# All Star Bulk Order Block v1.1.6

Launch-oriented ordering UX polish for the All Star Embroidery bulk-order experience.

## Collapsed product pricing

- Every collapsed product row now exposes a **Starting at** unit price before the customer opens the product.
- The value is the lowest customer-facing price available from WooCommerce Regular Price (1+) and the approved ASBO pricing matrix.
- Supplier cost fields, `unit_buy_price`, supplier `price_breaks`, MAP, MSRP, list, and suggested retail are never used to calculate or display this value.

## Decoration method clarity

- The selected decoration method keeps the existing high-contrast navy background.
- The active choice now also receives a gold border and visible checkmark, improving recognition on mobile screens and in glare.
- The underlying radio-control behavior and pricing calculations are unchanged.

## Scoped subcategory filtering

- When the ASBO block is configured with a WooCommerce parent category such as Hats or Headwear, Step 2 now shows a compact **Filter styles** control.
- Only represented direct child categories of that configured parent are shown.
- A Hats/Headwear ASBO block therefore does not surface Shirts, Apparel, Jackets, or unrelated catalog categories.
- The **All [parent category]** option restores the complete product list for that ASBO page.
- Filtering is instant and client-side with no reload.
- If an open product becomes hidden by the selected filter, its accordion closes automatically so the customer does not retain hidden expanded state.

## Preserved behavior

- Existing ASBO customer pricing tiers and calculations are unchanged.
- WooCommerce Regular Price remains authoritative for 1+ pricing.
- Supplier Sync pricing architecture is unchanged.
- The 10K stitch allowance policy remains unchanged.
- Cart, artwork, checkout, savings, and Supplier Sync compatibility remain unchanged.
