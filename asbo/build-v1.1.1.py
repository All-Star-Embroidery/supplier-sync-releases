#!/usr/bin/env python3
from pathlib import Path
import hashlib
import json
import shutil
import subprocess
import urllib.request
import zipfile

REPO = Path.cwd()
WORK = REPO / '.asbo-v111-work'
SOURCE_ZIP = WORK / 'asbo-1.1.0.zip'
SOURCE_DIR = WORK / 'source'
PLUGIN_DIR = SOURCE_DIR / 'all-star-bulk-order-block-v1.0.0'
OUT_ZIP = REPO / 'asbo' / 'all-star-bulk-order-block-1.1.1.zip'

if WORK.exists():
    shutil.rmtree(WORK)
WORK.mkdir(parents=True)

urllib.request.urlretrieve(
    'https://raw.githubusercontent.com/rolejarczyk/ASE.SupplierSync-Releases/main/asbo/all-star-bulk-order-block-1.1.0.zip',
    SOURCE_ZIP,
)
with zipfile.ZipFile(SOURCE_ZIP) as zf:
    zf.extractall(SOURCE_DIR)

php = PLUGIN_DIR / 'all-star-bulk-order-block.php'
s = php.read_text(encoding='utf-8')
s = s.replace('Version: 1.1.0', 'Version: 1.1.1', 1)
s = s.replace("private const VERSION = '1.1.0';", "private const VERSION = '1.1.1';", 1)

anchor = "\n\n@media (prefers-reduced-motion: reduce) {\n"
css = r'''

/* v1.1.1 — mobile progression: reduce redundant scrolling and keep pricing glanceable */
@media (max-width: 767px) {
  .asbo__featured-image {
    display: none !important;
  }

  .asbo__product-overview {
    display: block;
  }

  .asbo__product-copy h3 {
    display: none;
  }

  .asbo__product-copy > p {
    font-size: 13px;
    line-height: 1.55;
  }

  .asbo__text-button {
    margin-top: 12px;
    font-size: 13px;
  }

  .asbo__product.is-open .asbo__product-panel {
    padding-top: 14px;
    padding-bottom: 22px;
  }

  .asbo__pricing-section,
  .asbo__decoration-section,
  .asbo__variants-section {
    margin-top: 22px;
    padding-top: 18px;
  }

  .asbo__section-title-row {
    gap: 8px;
    margin-bottom: 10px;
  }

  .asbo__decoration-section h4,
  .asbo__section-title-row h4 {
    font-size: 18px;
  }

  .asbo__section-title-row p {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.45;
  }

  .asbo__active-tier {
    width: auto;
    max-width: 100%;
    justify-self: start;
    padding: 7px 9px;
    font-size: 11px;
    line-height: 1.3;
    text-align: left;
  }

  .asbo__table-scroll {
    width: 100%;
    overflow-x: hidden;
    border-radius: 9px;
  }

  .asbo__pricing-table {
    width: 100%;
    min-width: 0 !important;
    table-layout: fixed;
  }

  .asbo__pricing-table th,
  .asbo__pricing-table td {
    padding: 8px 3px;
    font-size: 11px;
    line-height: 1.2;
    white-space: nowrap;
  }

  .asbo__pricing-table thead th {
    font-size: 10px;
  }

  .asbo__pricing-table th:first-child,
  .asbo__pricing-table td:first-child {
    width: 82px;
    padding-left: 7px;
    padding-right: 5px;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .asbo__pricing-table tbody th {
    font-size: 11px;
    line-height: 1.15;
  }

  .asbo__pricing-table .woocommerce-Price-amount,
  .asbo__pricing-table .woocommerce-Price-currencySymbol {
    font-size: inherit;
  }

  .asbo__decoration-options {
    gap: 7px;
    margin-top: 11px;
  }

  .asbo__decoration-options label {
    flex: 1 1 calc(50% - 4px);
  }

  .asbo__decoration-options span {
    min-width: 0;
    padding: 9px 10px;
    font-size: 12px;
  }
}

@media (max-width: 430px) {
  .asbo__pricing-table th,
  .asbo__pricing-table td {
    padding: 7px 2px;
    font-size: 10px;
  }

  .asbo__pricing-table thead th {
    font-size: 9px;
  }

  .asbo__pricing-table th:first-child,
  .asbo__pricing-table td:first-child {
    width: 72px;
    padding-left: 5px;
    padding-right: 3px;
  }
}
'''
assert anchor in s, 'CSS anchor missing'
s = s.replace(anchor, css + anchor, 1)

old = '''      if (trigger) {\n        const panel = trigger.nextElementSibling;\n        const expanded = trigger.getAttribute('aria-expanded') === 'true';\n        trigger.setAttribute('aria-expanded', String(!expanded));\n        trigger.querySelector('.asbo__product-icon').textContent = expanded ? '+' : '−';\n        trigger.closest('[data-product]')?.classList.toggle('is-open', !expanded);\n        animatePanel(panel, !expanded);\n        return;\n      }\n'''
new = '''      if (trigger) {\n        const panel = trigger.nextElementSibling;\n        const expanded = trigger.getAttribute('aria-expanded') === 'true';\n        const opening = !expanded;\n\n        // Mobile keeps a single product open to reduce long-page drift.\n        if (opening && window.matchMedia('(max-width: 767px)').matches) {\n          root.querySelectorAll('.asbo__product-trigger[aria-expanded="true"]').forEach((otherTrigger) => {\n            if (otherTrigger === trigger) return;\n            const otherPanel = otherTrigger.nextElementSibling;\n            otherTrigger.setAttribute('aria-expanded', 'false');\n            const otherIcon = otherTrigger.querySelector('.asbo__product-icon');\n            if (otherIcon) otherIcon.textContent = '+';\n            otherTrigger.closest('[data-product]')?.classList.remove('is-open');\n            animatePanel(otherPanel, false);\n          });\n        }\n\n        trigger.setAttribute('aria-expanded', String(opening));\n        trigger.querySelector('.asbo__product-icon').textContent = opening ? '−' : '+';\n        trigger.closest('[data-product]')?.classList.toggle('is-open', opening);\n        animatePanel(panel, opening);\n        return;\n      }\n'''
assert old in s, 'accordion JS anchor missing'
s = s.replace(old, new, 1)
php.write_text(s, encoding='utf-8')

block = PLUGIN_DIR / 'block' / 'block.json'
b = block.read_text(encoding='utf-8').replace('"version": "1.1.0"', '"version": "1.1.1"', 1)
block.write_text(b, encoding='utf-8')

readme = PLUGIN_DIR / 'README.txt'
r = readme.read_text(encoding='utf-8').replace('All Star Bulk Order Block v1.1.0', 'All Star Bulk Order Block v1.1.1', 1)
r += '''\n\nv1.1.1 mobile UX improvements:\n- Hides the redundant large expanded-product hero image on screens 767px and below.\n- Keeps one product accordion open at a time on mobile to reduce long-page drift.\n- Fits the full per-piece pricing matrix inside the mobile viewport without horizontal swiping.\n- Tightens mobile spacing, typography, tier messaging, and decoration controls while preserving desktop styling.\n'''
readme.write_text(r, encoding='utf-8')

subprocess.run(['php', '-l', str(php)], check=True)
a = s.index("return <<<'JS'") + len("return <<<'JS'")
b = s.index('\nJS;', a)
js = WORK / 'asbo-inline.js'
js.write_text(s[a:b], encoding='utf-8')
subprocess.run(['node', '--check', str(js)], check=True)

if OUT_ZIP.exists():
    OUT_ZIP.unlink()
with zipfile.ZipFile(OUT_ZIP, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
    for path in sorted(PLUGIN_DIR.rglob('*')):
        arc = Path(PLUGIN_DIR.name) / path.relative_to(PLUGIN_DIR)
        if path.is_dir():
            continue
        zf.write(path, arc.as_posix())

sha = hashlib.sha256(OUT_ZIP.read_bytes()).hexdigest()
manifest = {
    'name': 'All Star Bulk Order Block',
    'slug': 'all-star-bulk-order-block',
    'version': '1.1.1',
    'download_url': 'https://raw.githubusercontent.com/rolejarczyk/ASE.SupplierSync-Releases/main/asbo/all-star-bulk-order-block-1.1.1.zip',
    'homepage': 'https://github.com/rolejarczyk/ASE.SupplierSync-Releases/tree/main/asbo',
    'requires': '6.5',
    'tested': '6.8',
    'requires_php': '7.4',
    'last_updated': '2026-08-20 05:17:00 -0400',
    'description': 'All Star Embroidery Gutenberg bulk-order workflow with WooCommerce and ASE Supplier Sync compatibility.',
    'changelog': '<h4>1.1.1</h4><ul><li>Mobile expanded products no longer repeat the large hero image.</li><li>Mobile keeps one product accordion open at a time to reduce excessive scrolling.</li><li>The per-piece pricing matrix now fits within the mobile viewport without horizontal swiping.</li><li>Mobile spacing, tier messaging, typography, and decoration controls were tightened for faster progression.</li><li>Desktop behavior and Supplier Sync compatibility are unchanged.</li></ul>',
}
(REPO / 'asbo' / 'latest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
(REPO / 'asbo' / 'RELEASE-1.1.1.md').write_text(f'''# All Star Bulk Order Block v1.1.1\n\nMobile progression and pricing-table UX release.\n\n- Hides the redundant large featured image inside expanded products on screens 767px and below; the accordion row already provides product identity and thumbnail context.\n- Keeps only one product accordion expanded at a time on mobile, reducing long-page drift while preserving multi-open accordion behavior on desktop/tablet.\n- Reflows the full per-piece pricing matrix to fit the phone viewport, removing the need to swipe horizontally to compare quantity tiers.\n- Tightens mobile section spacing, description typography, active-tier messaging, and decoration controls.\n- Preserves the sticky bottom summary/action bar and all desktop styling.\n- Preserves v1.1.0 Supplier Sync compatibility for SanMar, S&S Activewear, Momentec, and multi-supplier Woo variations.\n- PHP and inline JavaScript syntax validated during build.\n\nSHA-256: {sha}\n''', encoding='utf-8')

shutil.rmtree(WORK)
print('Built', OUT_ZIP)
print('SHA256', sha)
