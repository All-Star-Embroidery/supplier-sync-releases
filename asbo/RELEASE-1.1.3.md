# All Star Bulk Order Block v1.1.3

Responsive readability, persistent Step 1 action bar, guided artwork selection, inactivity cue, and customer order-history reuse release.

## Changes

- Replaced the smallest fixed typography introduced by the compact-layout work with responsive `clamp()` sizing across desktop, tablet, and mobile.
- Kept the compact spacing from v1.1.2 while increasing readability for product names, supporting copy, pricing cells, sticky-bar stats, incentives, form controls, quantity controls, and buttons.
- The per-piece pricing table remains viewport-fitted on mobile without horizontal swiping, but text now scales with available screen width instead of dropping to tiny fixed pixel sizes.
- The sticky bottom summary/action bar is now rendered visible immediately whenever the ASBO block is present, including Step 1 before JavaScript initializes.
- Step 1 sticky CTA correctly begins as **Select Products**, then changes to **Next: Artwork Plan** and **Continue to Checkout** as the customer progresses.
- Added a one-time inactivity cue: after 30 seconds with no pointer, keyboard, form, wheel, touch, or scroll activity, a small gold downward arrow appears above the current sticky CTA.
- Any activity after the cue appears fades it away permanently for that page view; activity before 30 seconds resets the inactivity timer.
- Reworked Step 3 into two clear tasks: **Choose how we should handle your logo** and **Tell us what production should look like**.
- Artwork-path options now have stronger selected-state styling, subtle All Star gold/navy color guidance, compact icons, and clearer plain-language descriptions.
- Renamed the artwork choices to **Upload a new file**, **Reuse approved artwork**, and **I need design or digitizing help** for faster comprehension.
- Logged-in customers selecting **Reuse approved artwork** can choose from up to 25 of their own recent WooCommerce processing, completed, or on-hold orders.
- Previous-order choices display the order number, date, and a short product summary.
- Customer order history is loaded through WooCommerce order APIs for HPOS compatibility and is restricted to the currently logged-in customer.
- Customers can still manually enter an order/reference if the desired order is not listed; guests retain the manual-reference workflow and receive a My Account sign-in link.
- Selected previous-order IDs are validated server-side against the logged-in customer before being stored on the new order.
- The new order stores both a human-readable previous-order reference and the validated previous WooCommerce order ID when available.
- Preserves v1.1.2 compact product-detail modal, single-open product accordions, Total Saved calculation, 1+ normal-price fallback, tier pricing, checkout hardening, and Supplier Sync compatibility.
- Supplier-agnostic compatibility remains intact for SanMar, S&S Activewear, Momentec, and multi-supplier WooCommerce variations.

## Validation

- PHP syntax validation passed.
- Gutenberg editor JavaScript syntax validation passed.
- Inline storefront JavaScript syntax validation passed.
- `block.json` validation passed.
- ZIP integrity validation passed.
- SHA-256: `0d2e74df48eb11124c12d300305ecf9fef9f5aacb4588a00a89b77d6361ce16a`
- ZIP size: `40,675 bytes`
