# Momentec Brands API groundwork — v2.0.3

Supplier key: `momentec`

v2.0.3 deliberately prepares the WordPress data model without guessing Momentec's account-specific API authentication or endpoint contract before credentials are issued.

## Prepared now

- Third supplier registration in the multi-supplier layer.
- Product and variation source JSON can retain Momentec as an independent source.
- Preferred-supplier and combined-inventory structures recognize Momentec.
- Supplier-specific disconnect cleanup keys are reserved.
- Active Products / Manage Suppliers can display Momentec alongside SanMar and S&S.
- Settings fields exist for staging/production, API base URL, account/customer number, username/client ID, and API key/secret.
- Add Products and Brands expose a Momentec tab that remains read-only until credentials are verified.
- Direct Momentec repair fails safely instead of falling through to another supplier adapter.

## Do not enable live calls yet

Before enabling catalog or inventory synchronization, obtain Momentec API access and verify:

1. staging and production base URLs;
2. authentication type and required headers;
3. product/style/SKU identifiers;
4. color and size representation;
5. inventory/warehouse response structure;
6. wholesale/customer pricing fields;
7. image/media fields;
8. pagination/rate limits;
9. discontinued/closeout semantics.

Once verified, normalize only real supplier SKU rows into the existing exact sparse variation model. Never build a Color × Size Cartesian product.
