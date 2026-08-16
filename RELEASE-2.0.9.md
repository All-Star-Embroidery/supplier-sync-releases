# All Star Supplier Sync v2.0.9

New All Star bulk hat pricing ladder.

Supplier Sync-managed ASBO pricing matrices now use fixed per-item discounts based on total quantity:

- **6+**: base price
- **9+**: $1.00 off per item
- **12+**: $2.00 off per item
- **24+**: $3.00 off per item
- **48+**: $4.00 off per item
- **96+**: $6.00 off per item
- **144+**: $7.00 off per item
- **288+**: $9.00 off per item

Patch pricing keeps its existing $3.00 per-item surcharge above Embroidery while receiving the same quantity discounts.

Existing Supplier Sync-managed matrices are migrated automatically using their saved base selling price. Matrices that were manually edited by the merchant remain untouched and are permanently handed back to merchant ownership.
