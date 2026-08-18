# All Star Bulk Order Block v1.1.0

GitHub-managed updater and ASE Supplier Sync compatibility release.

- Supports Supplier Sync-managed WooCommerce products from SanMar, S&S Activewear, Momentec, and multi-supplier products.
- ASBO remains supplier-agnostic: it reads only WooCommerce products/variations plus the `_asbo_*` integration fields.
- Server reloads real Woo variation attributes immediately before cart insertion.
- Stock/status/parent validation happens before the cart is mutated.
- Cart submission is atomic: newly added ASBO lines are rolled back if any selected item fails.
- Customer receives the exact product/variation failure instead of a generic 500 message.
- Adds a GitHub manifest updater and enables WordPress automatic updates for this plugin.

## Release files

- `latest.json` — updater manifest consumed by WordPress.
- `all-star-bulk-order-block-1.1.0.zip` — install/update package.

The ZIP intentionally preserves the existing installed plugin directory `all-star-bulk-order-block-v1.0.0/` so this first managed update replaces the current installation cleanly. Future package versions should preserve that directory name unless an explicit migration is added.
