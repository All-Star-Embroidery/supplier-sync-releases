#!/usr/bin/env bash
set -euo pipefail

SOURCE_BRANCH='recovery/v2.0.27-exact-source'
VERSION='2.0.27'
ZIP="all-star-supplier-sync-${VERSION}.zip"
TAG="v${VERSION}"

# Work on the exact recoverable plugin source branch.
git fetch origin main "$SOURCE_BRANCH"
git checkout -B supplier-v2027 "origin/$SOURCE_BRANCH"

python3 - <<'PY'
from pathlib import Path

importer = Path('includes/class-asss-importer.php')
s = importer.read_text()
old_count = s.count('+ 20.0') + s.count('+20.0')
if old_count != 4:
    raise SystemExit(f'Expected exactly 4 +20.0 pricing formulas, found {old_count}')
s = s.replace('+ 20.0', '+ 23.0').replace('+20.0', '+23.0')

action_old = "        add_action('admin_init', [$this, 'migrate_standard_supplier_pricing_v2026'], 43);\n"
action_new = action_old + "        add_action('admin_init', [$this, 'migrate_supplier_markup_v2027'], 44);\n"
if action_old not in s:
    raise SystemExit('Could not find v2.0.26 pricing migration hook')
s = s.replace(action_old, action_new, 1)

marker = "    private function ss_variation_price(int $product_id, array $row): array {\n"
if marker not in s:
    raise SystemExit('Could not find ss_variation_price insertion point')
migration = r'''    /** v2.0.27: move Supplier Sync-managed storefront pricing from unit buy + $20 to unit buy + $23. */
    public function migrate_supplier_markup_v2027(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2027_supplier_markup_migrated','') === 'yes') return;
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_key'=>'_asss_sync_enabled','meta_value'=>'yes',
        ]);
        $products = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            if ($product_id < 1) continue;
            $this->sync_managed_pricing_for_product($product_id);
            $products++;
        }
        update_option('asss_v2027_supplier_markup_migrated','yes',false);
        ASSS_Logger::log('v2.0.27 updated Supplier Sync-managed storefront markup to unit buy + $23','info',[
            'products'=>$products,'markup'=>23,
        ]);
    }

'''
s = s.replace(marker, migration + marker, 1)
importer.write_text(s)

main = Path('all-star-supplier-sync.php')
m = main.read_text()
if 'Version: 2.0.26' not in m or "define('ASSS_VERSION', '2.0.26');" not in m:
    raise SystemExit('Expected v2.0.26 plugin version markers not found')
m = m.replace('Version: 2.0.26', 'Version: 2.0.27', 1)
m = m.replace("define('ASSS_VERSION', '2.0.26');", "define('ASSS_VERSION', '2.0.27');", 1)
main.write_text(m)

readme = Path('readme.txt')
r = readme.read_text()
if 'Stable tag: 2.0.26' not in r:
    raise SystemExit('Expected v2.0.26 stable tag not found')
r = r.replace('Stable tag: 2.0.26', 'Stable tag: 2.0.27', 1)
r = r.replace(
    'Supplier-managed Main Price is calculated from the preferred available supplier wholesale cost + $20.',
    'Supplier-managed Main Price is calculated from the preferred available supplier unit buy cost + $23.',
    1,
)
changelog = '''= 2.0.27 =
* Changes Supplier Sync-managed WooCommerce Regular Price from verified unit_buy_price + $20 to verified unit_buy_price + $23.
* Applies the same $23 markup consistently across SanMar, S&S Activewear, Momentec, and multi-supplier pricing selection.
* Adds a one-time migration that reprices existing Supplier Sync-managed products while preserving merchant-owned/manual prices.
* Supplier cost normalization, unit_buy_price, price_breaks, MAP/MSRP/list references, inventory, and the established customer bulk-discount ladder are otherwise unchanged.

'''
anchor = '== Changelog ==\n\n'
if anchor not in r:
    raise SystemExit('Could not find readme changelog anchor')
r = r.replace(anchor, anchor + changelog, 1)
readme.write_text(r)
PY

php -l all-star-supplier-sync.php
php -l includes/class-asss-importer.php
find includes -maxdepth 1 -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
grep -q 'Version: 2.0.27' all-star-supplier-sync.php
grep -q "define('ASSS_VERSION', '2.0.27')" all-star-supplier-sync.php
grep -q 'Stable tag: 2.0.27' readme.txt
grep -q 'asss_v2027_supplier_markup_migrated' includes/class-asss-importer.php
if grep -Eq '\+ ?20\.0' includes/class-asss-importer.php; then
  echo 'Found old +20.0 pricing formula after patch' >&2
  exit 1
fi
test "$(grep -Eo '\+ ?23\.0' includes/class-asss-importer.php | wc -l)" = '4'

git config user.name 'github-actions[bot]'
git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
git add all-star-supplier-sync.php includes/class-asss-importer.php readme.txt
git commit -m 'release: Supplier Sync v2.0.27 uses unit buy + $23'
if test -f .github/workflows/markup-v2027.yml; then
  git rm .github/workflows/markup-v2027.yml
  git commit -m 'maintenance: remove unused source-branch v2.0.27 workflow'
fi
git push origin HEAD:"$SOURCE_BRANCH"

BUILD=/tmp/asss-build
rm -rf "$BUILD"
mkdir -p "$BUILD/all-star-supplier-sync/includes"
cp all-star-supplier-sync.php readme.txt "$BUILD/all-star-supplier-sync/"
cp MOMENTEC-API-GROUNDWORK.md V1-ARCHITECTURE.md V2-ARCHITECTURE.md "$BUILD/all-star-supplier-sync/" 2>/dev/null || true
cp includes/*.php "$BUILD/all-star-supplier-sync/includes/"
(cd "$BUILD" && zip -qr "$GITHUB_WORKSPACE/$ZIP" all-star-supplier-sync)

unzip -t "$ZIP" >/dev/null
unzip -l "$ZIP" | grep -q 'all-star-supplier-sync/all-star-supplier-sync.php'
unzip -p "$ZIP" all-star-supplier-sync/all-star-supplier-sync.php | grep -q 'Version: 2.0.27'
unzip -p "$ZIP" all-star-supplier-sync/includes/class-asss-importer.php | grep -q 'asss_v2027_supplier_markup_migrated'
if unzip -p "$ZIP" all-star-supplier-sync/includes/class-asss-importer.php | grep -Eq '\+ ?20\.0'; then
  echo 'Published ZIP still contains old +20 pricing formula' >&2
  exit 1
fi

TARGET="$(git rev-parse HEAD)"
NOTES=$'Supplier Sync v2.0.27\n\n- WooCommerce Regular Price for Supplier Sync-managed variations is now verified unit_buy_price + $23 (was +$20).\n- Applies consistently to SanMar, S&S Activewear, Momentec, and multi-supplier pricing.\n- Existing Supplier Sync-managed products are migrated automatically; merchant-owned/manual prices remain protected.\n- Supplier price_breaks and reference MAP/MSRP/list fields are unchanged.'
if gh release view "$TAG" >/dev/null 2>&1; then
  gh release upload "$TAG" "$ZIP" --clobber
else
  gh release create "$TAG" "$ZIP" --target "$TARGET" --title 'All Star Supplier Sync v2.0.27' --notes "$NOTES"
fi

# Update the live WordPress manifest on main, then clean temporary publishing helpers.
git fetch origin main
rm -rf /tmp/asss-main
git worktree add --detach /tmp/asss-main origin/main
cd /tmp/asss-main
python3 - <<'PY'
import json
from pathlib import Path
p=Path('latest.json')
data=json.loads(p.read_text())
data['version']='2.0.27'
data['package']='https://github.com/rolejarczyk/ASE.SupplierSync-Releases/releases/download/v2.0.27/all-star-supplier-sync-2.0.27.zip'
data['url']='https://github.com/rolejarczyk/ASE.SupplierSync-Releases/releases/tag/v2.0.27'
data['name']='All Star Supplier Sync v2.0.27'
p.write_text(json.dumps(data,indent=2)+'\n')
PY
rm -f .github/workflows/publish-supplier-markup-v2027.yml .github/workflows/publish-supplier-v2027-pr.yml .release-staging/publish-supplier-v2027.sh .supplier-sync-publish-trigger
git add -A
git config user.name 'github-actions[bot]'
git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
git commit -m 'release: publish Supplier Sync v2.0.27 (+$23 markup)'
git push origin HEAD:main
