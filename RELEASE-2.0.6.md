# All Star Supplier Sync v2.0.6

Momentec production v2 integration using the project security architecture: **WordPress <> GitHub Actions <> Supplier**.

- Momentec username/password stay only in GitHub Actions Secrets.
- WordPress receives normalized supplier data through the existing authenticated bridge; it never receives Momentec credentials.
- Added intentional production style publishing and normalized Momentec cache.
- Added Momentec Add Products browser and review/import flow.
- Added new-product import and existing-product/multi-supplier linking.
- Preserves exact sparse Color+Size SKU combinations; no Cartesian variations are generated.
- Customer-specific Momentec `cart_price` is treated as supplier cost and feeds the existing preferred-supplier cost + $20 pricing rules.
- Manual WooCommerce/ASBO pricing protection remains in force.
- Added color-specific multi-image variation galleries using full-SKU v2 Style lookups.
- Added strict targeted Momentec inventory targets/apply bridge with all-or-nothing coverage validation.
- Added Quick Repair / multi-supplier repair support for cached Momentec products.

Production API verification was completed before enabling this release: v2 username/password authentication succeeded, exact sparse row integrity passed, and the test style achieved 142/142 inventory, cost, list-price, and gallery coverage with 0 invented and 0 lost variations.
