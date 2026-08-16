# All Star Supplier Sync v2.0.10

Site-wide ASBO quantity pricing policy.

The All Star bulk pricing ladder now applies to **every WooCommerce product enabled for ASBO**, not only Supplier Sync-managed/imported products:

- **6+**: base price
- **9+**: $1.00 off per item
- **12+**: $2.00 off per item
- **24+**: $3.00 off per item
- **48+**: $4.00 off per item
- **96+**: $6.00 off per item
- **144+**: $7.00 off per item
- **288+**: $9.00 off per item

Existing ASBO products preserve each decoration method's current starting price and surcharge; only the quantity tiers are normalized. This includes old products, manually created products, SanMar, S&S, Momentec, and multi-supplier products.

Supplier-managed products remain supplier-managed unless the merchant changes their base price. A merchant base-price edit hands price ownership back to the merchant while the universal quantity ladder remains enforced.
