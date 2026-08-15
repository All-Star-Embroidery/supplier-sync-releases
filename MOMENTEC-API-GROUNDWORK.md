# Momentec Brands API groundwork — v2.0.4

Supplier key: `momentec`

Momentec publicly describes its integration as a RESTful Web API and says approved customers receive **staging account credentials**. Its public Swagger page currently references an OpenAPI definition that is not loading reliably in-browser, so Supplier Sync still deliberately avoids guessing the exact login request, auth header, or token flow until All Star's staging account is available.

## What we know now

- Momentec supports API integrations for catalog retrieval, inventory checks, order placement, and order status.
- Approved API customers receive staging account credentials.
- The normal Momentec website itself uses user ID/email + password, which is consistent with the account-credential model All Star expected.
- We have not yet verified whether API calls use HTTP Basic auth directly, exchange username/password for a session/token, or use another account-scoped mechanism. That will be confirmed against the staging account before live calls are enabled.

## Credential security in v2.0.4

Sensitive Momentec credentials are **not stored in the WordPress database**.

Use either `wp-config.php` constants or server environment variables:

```php
define('ASSS_MOMENTEC_USERNAME', 'YOUR_MOMENTEC_API_USERNAME');
define('ASSS_MOMENTEC_PASSWORD', 'YOUR_MOMENTEC_API_PASSWORD');
```

- Constants take precedence over environment variables.
- The WordPress settings screen only reports whether each credential is configured; it never renders the secret values.
- Legacy `momentec_username`, `momentec_password`, `momentec_api_key`, and `momentec_secret` values are removed from the `asss_settings` option on an administrator load.
- API base URL, environment, and account/customer number remain non-secret WordPress settings.

## Prepared now

- Third supplier registration in the multi-supplier layer.
- Product and variation source JSON can retain Momentec as an independent source.
- Preferred-supplier and combined-inventory structures recognize Momentec.
- Supplier-specific disconnect cleanup keys are reserved.
- Active Products / Manage Suppliers can display Momentec alongside SanMar and S&S.
- Add Products and Brands expose a Momentec tab that remains read-only until staging authentication is verified.
- Direct Momentec repair fails safely instead of falling through to another supplier adapter.

## Before enabling live calls

Verify against Momentec staging:

1. staging and production base URLs;
2. exact username/password authentication flow and required headers/tokens;
3. product/style/SKU identifiers;
4. color and size representation;
5. inventory/warehouse response structure;
6. wholesale/customer pricing fields;
7. image/media fields;
8. pagination/rate limits;
9. discontinued/closeout semantics.

Once verified, normalize only real supplier SKU rows into the existing exact sparse variation model. Never build a Color × Size Cartesian product.
