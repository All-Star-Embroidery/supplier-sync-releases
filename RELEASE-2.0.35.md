# All Star Supplier Sync v2.0.35

This release tightens Supplier Sync category normalization for SanMar, S&S Activewear, and Momentec.

## Changed

- Collapses supplier/app category drift into a smaller customer-facing hierarchy.
- Maps hats and headwear to `Headwear > Caps & Hats`, `Headwear > Beanies`, `Headwear > Bucket Hats`, or `Headwear > Visors`.
- Maps apparel to `Apparel > T-Shirts`, `Apparel > Polos`, `Apparel > Sweatshirts & Hoodies`, `Apparel > Outerwear`, `Apparel > Bottoms`, `Apparel > Uniforms`, or `Apparel > Workwear`.
- Maps bags and accessories into grouped storefront terms instead of supplier-specific fragments.
- Adds an incremental WooCommerce admin migration that processes existing Supplier Sync-linked products 25 at a time.
- Removes known legacy supplier category noise only from products linked to Supplier Sync after canonical categories are assigned.

## Protected

- Manual and unrelated WooCommerce products, including varsity jackets, are not targeted by the migration.
- The migration does not delete unrelated/manual categories globally.
- Pricing, inventory, ASBO behavior, supplier variation matching, image logic, and GitHub worker behavior are unchanged.
