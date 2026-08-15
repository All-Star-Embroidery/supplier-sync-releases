#!/usr/bin/env python3
from pathlib import Path
import re
import sys

if len(sys.argv)!=2: raise SystemExit('usage: v206_patch_misc.py <source-dir>')
root=Path(sys.argv[1])

multi=root/'includes/class-asss-multi.php';text=multi.read_text(encoding='utf-8')
text=text.replace("'_asss_momentec_style_id','_asss_momentec_color_selection_mode'", "'_asss_momentec_style_id','_asss_momentec_specs','_asss_momentec_color_selection_mode'")
text=text.replace("'_asss_momentec_retail_price','_asss_momentec_warehouses','_asss_inventory_momentec_qty'", "'_asss_momentec_retail_price','_asss_momentec_warehouses','_asss_momentec_availability','_asss_momentec_availability_date','_asss_inventory_momentec_qty'")
text=text.replace("        if ($chosen === 'ss') {\n            $retail = $source['retail_price'] ?? '';", "        if (in_array($chosen, ['ss','momentec'], true)) {\n            $retail = $source['retail_price'] ?? '';")
multi.write_text(text,encoding='utf-8')

readme=root/'readme.txt';text=readme.read_text(encoding='utf-8')
text=re.sub(r'^Stable tag:\s*2\.0\.5\s*$', 'Stable tag: 2.0.6', text, count=1, flags=re.M)
if '= 2.0.6 =' not in text:
    text=text.replace('== Changelog ==', '''== Changelog ==

= 2.0.6 =
* Momentec production v2 is now a live Supplier Sync connector using WordPress <> GitHub Actions <> Momentec.
* Added secure normalized Momentec style cache; supplier credentials remain only in GitHub Actions Secrets.
* Added intentional style publishing because Momentec v2 does not expose a full catalog-list endpoint.
* Added Momentec Add Products browser, review screen, exact-color selection, new-product import, and existing-product linking.
* Added exact sparse Momentec Color+Size variation creation with no Cartesian combinations.
* Added Momentec customer-cost pricing integration using the existing preferred-supplier cost + $20 pricing rules while preserving manual overrides.
* Added color-specific variation galleries sourced from full-SKU Momentec v2 Style lookups.
* Added strict targeted Momentec inventory bridge endpoints and multi-supplier inventory support.
* Added Momentec Quick Repair and multi-supplier repair support from the normalized cache.
''',1)
readme.write_text(text,encoding='utf-8')

print('Applied v2.0.6 multi-supplier cleanup and readme changelog patches.')
