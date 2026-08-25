# All Star Bulk Order Block v1.1.7

Product-details discoverability and accessibility polish for the All Star Embroidery bulk-order experience.

## Details & sizing chip

- Adds a compact **Details & sizing** chip directly with each collapsed product row so shoppers can inspect a product without first expanding its pricing/options accordion.
- On desktop/tablet, the chip sits beside the product title when space allows.
- On mobile, the chip moves beneath the title to preserve readability and tap spacing.
- The chip opens the existing product-details modal containing the larger image, description, garment specifications, and size information.

## Visual treatment

- Uses a low-contrast warm-neutral background (`#f7f8fa`), dark navy text, and a soft gray border.
- Hover treatment is intentionally restrained: slightly darker neutral background/border instead of a bright color change.
- Keyboard focus uses a subtle All Star gold ring so the control remains accessible without competing visually with primary yellow workflow buttons.

## Interaction/accessibility architecture

- The full collapsed product row remains its own large native button for opening/closing the pricing accordion.
- The new details chip is a **separate native button**, not a button nested inside another button.
- This prevents invalid interactive markup, accidental accordion expansion when viewing details, and keyboard/focus conflicts.
- Focus returns to the chip that opened the modal when the modal closes.

## Cleanup

- Removes the redundant **Product details & size information** action from inside the expanded pricing panel. Product details are now consistently available from the collapsed summary row.
- Existing one-product-at-a-time accordion behavior remains unchanged.

## Preserved behavior

No customer pricing, bulk-discount tiers, WooCommerce Regular Price logic, Supplier Sync architecture, supplier costs, 10K stitch policy, cart calculations, savings calculations, artwork flow, or checkout behavior changed in this release.
