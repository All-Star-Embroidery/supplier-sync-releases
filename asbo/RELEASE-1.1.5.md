# All Star Bulk Order Block v1.1.5

Customer-facing stitch-count policy clarification for All Star Embroidery.

## 10K stitch allowance

- Standard embroidery pricing now clearly states that it includes designs up to **10,000 stitches per design**.
- Larger, more detailed, or more complex artwork may require an additional embroidery charge after artwork review.
- Any additional embroidery charge is communicated to the customer **before production begins**.
- Customer-facing wording is based on artwork size, complexity, and stitch count; it does not refer to owner discretion.

## Placement

- Adds a compact note beside per-piece embroidery pricing when the product pricing matrix includes an embroidery decoration method.
- Adds a more detailed **10K Stitch Allowance** explanation in Step 3 / Artwork.
- Updates the production-order review fine print with the same explicit stitch-count policy.
- Adds matching Gutenberg editor previews so site managers can see where the policy appears.

## Design

- Uses the existing All Star navy, gold, muted text, border radius, and responsive typography variables.
- Pricing-area copy is secondary text rather than a warning box.
- Artwork-step treatment uses a restrained gold accent consistent with the existing workflow.
- Mobile layout stacks the allowance label and copy cleanly without crowding the pricing table.

## Functional scope

No pricing calculations were changed in this release. There is no stitch-count calculator, no automatic stitch surcharge, and checkout is not blocked when stitch count is unknown. Supplier Sync pricing, WooCommerce Regular Price behavior, the ASBO customer bulk-discount matrix, cart logic, artwork upload behavior, and checkout behavior remain unchanged.
