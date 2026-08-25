#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: 1.1.8.py <plugin-folder>')

root = Path(sys.argv[1])
main = root / 'all-star-bulk-order-block.php'
block = root / 'block' / 'block.json'
readme = root / 'README.txt'

s = main.read_text()

for old, new in [
    (' * Version: 1.1.7', ' * Version: 1.1.8'),
    ("    private const VERSION = '1.1.7';", "    private const VERSION = '1.1.8';"),
]:
    if s.count(old) != 1:
        raise SystemExit(f'expected exactly one occurrence of {old!r}; found {s.count(old)}')
    s = s.replace(old, new, 1)

responsive_pricing_css = r'''

/* v1.1.8 — keep the pricing matrix readable on tablets and phones.
   Do not squeeze currency values into columns narrower than the values themselves.
   Narrow viewports get a controlled horizontal table scroll instead. */
.asbo__table-scroll {
  max-width: 100%;
}

@media (max-width: 1100px) {
  .asbo__table-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 4px;
    overscroll-behavior-inline: contain;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
  }

  .asbo__pricing-table {
    width: auto;
    min-width: 100%;
    table-layout: auto;
  }

  .asbo__pricing-table th,
  .asbo__pricing-table td {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }

  .asbo__pricing-table th:first-child,
  .asbo__pricing-table td:first-child {
    position: sticky;
    left: 0;
    z-index: 2;
    width: 132px;
    min-width: 132px;
    box-shadow: 1px 0 0 var(--asbo-border);
  }

  .asbo__pricing-table th:not(:first-child),
  .asbo__pricing-table td:not(:first-child) {
    min-width: 70px;
  }

  .asbo__pricing-table thead th:first-child {
    z-index: 3;
    background: var(--asbo-surface);
  }

  .asbo__pricing-table tbody td:first-child {
    background: #fff;
  }
}

@media (max-width: 767px) {
  .asbo__pricing-table th,
  .asbo__pricing-table td {
    padding-right: 7px;
    padding-left: 7px;
  }

  .asbo__pricing-table th:first-child,
  .asbo__pricing-table td:first-child {
    width: 122px;
    min-width: 122px;
  }

  .asbo__pricing-table th:not(:first-child),
  .asbo__pricing-table td:not(:first-child) {
    min-width: 64px;
  }
}
'''

css_marker = '\nCSS;\n    }\n\n    private static function inline_js'
if s.count(css_marker) != 1:
    raise SystemExit(f'inline CSS marker changed unexpectedly; found {s.count(css_marker)}')
s = s.replace(css_marker, responsive_pricing_css + css_marker, 1)

main.write_text(s)

b = block.read_text()
if b.count('"version": "1.1.7"') != 1:
    raise SystemExit('block.json version marker not found exactly once')
block.write_text(b.replace('"version": "1.1.7"', '"version": "1.1.8"', 1))

r = readme.read_text()
if r.count('All Star Bulk Order Block v1.1.7') != 1:
    raise SystemExit('README version marker not found exactly once')
r = r.replace('All Star Bulk Order Block v1.1.7', 'All Star Bulk Order Block v1.1.8', 1)
r += '''\n\nv1.1.8 responsive pricing-matrix fix:\n- Prevents bulk-pricing dollar amounts from overlapping on tablets and phones.\n- Stops the pricing matrix from compressing quantity-tier columns below a readable width.\n- Uses controlled horizontal scrolling on narrow viewports instead of shrinking or stacking currency values.\n- Keeps the Decoration method column visible while customers swipe across quantity tiers.\n- Uses tabular numerals and mobile-specific column widths for cleaner price alignment.\n- Desktop pricing-table behavior remains unchanged above 1100px.\n- No pricing values, quantity tiers, Supplier Sync logic, cart calculations, savings, artwork, or checkout behavior changed.\n'''
readme.write_text(r)

print('ASBO v1.1.8 responsive pricing transform applied successfully')
