#!/usr/bin/env python3
from pathlib import Path
import re

root = Path('src')
imp = root / 'includes/class-asss-importer.php'
s = imp.read_text(encoding='utf-8')

def replace_once(old, new, label):
    global s
    n = s.count(old)
    if n != 1:
        raise SystemExit(f'{label}: expected exactly 1 match, found {n}')
    s = s.replace(old, new, 1)

# Run a new one-time cleanup because the v2.0.18 migration has already been marked complete on production.
replace_once(
"        add_action('admin_init', [$this, 'migrate_storefront_media_and_sizes_v2018'], 36);",
"        add_action('admin_init', [$this, 'migrate_storefront_media_and_sizes_v2018'], 36);\n        add_action('admin_init', [$this, 'migrate_ss_tn_reference_media_v2019'], 37);",
'v2019 migration hook')

old = '''    private function invalid_supplier_attachment(int $attachment_id): bool {
        if ($attachment_id < 1 || !$this->is_supplier_attachment($attachment_id)) return false;
        $url = trim((string)get_post_meta($attachment_id, '_asss_image_url', true));
        if ($url === '') $url = (string)wp_get_attachment_url($attachment_id);
        return $url !== '' && $this->is_invalid_storefront_media_url($url);
    }
'''
new = '''    private function invalid_supplier_attachment(int $attachment_id): bool {
        if ($attachment_id < 1 || !$this->is_supplier_attachment($attachment_id)) return false;
        $url = trim((string)get_post_meta($attachment_id, '_asss_image_url', true));
        if ($url === '') $url = (string)wp_get_attachment_url($attachment_id);
        return $url !== '' && $this->is_invalid_storefront_media_url($url);
    }

    /**
     * S&S sometimes supplies a style-level TN asset while the exact color rows
     * have proper front/back/direct-side product photography. On the affected
     * styles the TN asset is the blue "image not yet available" graphic. Image
     * optimization plugins can create a WebP attachment that no longer carries
     * Supplier Sync's _asss_image_source metadata, so cleanup must also inspect
     * the local attachment basename. Scope this rule narrowly to S&S-linked
     * products and require the stored S&S style token immediately before TN.
     */
    private function is_ss_tn_reference_attachment(int $attachment_id, int $product_id): bool {
        if ($attachment_id < 1 || $product_id < 1) return false;
        $style = trim((string)get_post_meta($product_id, '_asss_ss_style', true));
        if ($style === '') return false;
        $style_key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $style));
        if ($style_key === '') return false;

        $candidates = [];
        $source_url = trim((string)get_post_meta($attachment_id, '_asss_image_url', true));
        if ($source_url !== '') $candidates[] = basename((string)parse_url($source_url, PHP_URL_PATH));
        $local_url = (string)wp_get_attachment_url($attachment_id);
        if ($local_url !== '') $candidates[] = basename((string)parse_url($local_url, PHP_URL_PATH));
        $file = get_attached_file($attachment_id);
        if (is_string($file) && $file !== '') $candidates[] = basename($file);
        $post = get_post($attachment_id);
        if ($post) {
            $candidates[] = (string)$post->post_title;
            $candidates[] = (string)$post->post_name;
        }

        foreach ($candidates as $candidate) {
            $stem = preg_replace('/\\.(?:webp|avif|jpe?g|png|gif)$/i', '', trim((string)$candidate));
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$stem));
            if ($key === '') continue;
            // Optimizers may append a numeric duplicate suffix after conversion.
            $key = preg_replace('/\\d+$/', '', $key);
            if (str_ends_with($key, $style_key . 'tn')) return true;
        }
        return false;
    }

    private function invalid_storefront_attachment_for_product(int $attachment_id, int $product_id): bool {
        if ($this->invalid_supplier_attachment($attachment_id)) return true;
        return $this->is_ss_tn_reference_attachment($attachment_id, $product_id);
    }
'''
replace_once(old, new, 'S&S TN attachment helper')

# Make the product cleanup product-aware so optimizer-generated derivatives can be removed too.
pattern = re.compile(r"    /\*\* Remove only Supplier Sync-owned invalid graphics; merchant media is sacred\. \*/\n    public function cleanup_invalid_supplier_storefront_media\(int \$product_id, string \$supplier = ''\): void \{.*?\n    \}\n\n    /\*\*\n     \* Canonicalize only equivalent one-size labels", re.S)
m = pattern.search(s)
if not m:
    raise SystemExit('cleanup function block not found')
block = m.group(0)
block = block.replace('$this->invalid_supplier_attachment($featured)', '$this->invalid_storefront_attachment_for_product($featured, $product_id)')
block = block.replace('$this->invalid_supplier_attachment($id)', '$this->invalid_storefront_attachment_for_product($id, $product_id)')
block = block.replace('$this->invalid_supplier_attachment($primary)', '$this->invalid_storefront_attachment_for_product($primary, $product_id)')
block = block.replace("fn($id) => $id > 0 && !$this->invalid_supplier_attachment($id)", "fn($id) => $id > 0 && !$this->invalid_storefront_attachment_for_product($id, $product_id)")
# Update comment because narrowly-recognized optimizer derivatives may lack supplier metadata.
block = block.replace('Remove only Supplier Sync-owned invalid graphics; merchant media is sacred.', 'Remove invalid supplier/reference graphics, including recognized optimizer derivatives; ordinary merchant media is preserved.')
s = s[:m.start()] + block + s[m.end():]

# Add a new migration that reruns cleanup on S&S-linked products after v2.0.18.
anchor = '''    /**
     * Supplier descriptions are managed until the merchant edits them. Once a
'''
insert = '''    /** One-time v2.0.19 cleanup for S&S TN/reference thumbnails and optimizer derivatives. */
    public function migrate_ss_tn_reference_media_v2019(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2019_ss_tn_media_migrated','') === 'yes') return;
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_key'=>'_asss_ss_style','meta_compare'=>'EXISTS',
        ]);
        foreach ((array)$ids as $product_id) $this->cleanup_invalid_supplier_storefront_media((int)$product_id, 'ss-v2019');
        update_option('asss_v2019_ss_tn_media_migrated','yes',false);
        ASSS_Logger::log('v2.0.19 S&S TN/reference media cleanup completed', 'info', ['products'=>count((array)$ids)]);
    }

'''
if anchor not in s:
    raise SystemExit('description anchor not found')
s = s.replace(anchor, insert + anchor, 1)

imp.write_text(s, encoding='utf-8')

# Version bump.
main = root / 'all-star-supplier-sync.php'
t = main.read_text(encoding='utf-8')
t = t.replace(' * Version: 2.0.18', ' * Version: 2.0.19', 1)
t = t.replace("define('ASSS_VERSION', '2.0.18');", "define('ASSS_VERSION', '2.0.19');", 1)
main.write_text(t, encoding='utf-8')

# Readme/changelog.
readme = root / 'readme.txt'
r = readme.read_text(encoding='utf-8')
r = re.sub(r'^Stable tag: .*$', 'Stable tag: 2.0.19', r, count=1, flags=re.M)
needle = '== Changelog ==\n\n'
entry = '''= 2.0.19 =
* Corrected S&S placeholder cleanup for style-level TN assets such as CCRC0TN and FF180APTN; these are treated as supplier reference media rather than storefront photography.
* Cleanup now checks the original supplier URL plus the local attachment filename/title so WebP/optimized derivatives that lost Supplier Sync attachment metadata are also removed from affected S&S product/variation galleries.
* Added a new one-time S&S cleanup migration because sites that already ran the v2.0.18 migration need the refined TN rule to execute automatically.
* No supplier media files are deleted from the WordPress Media Library; the fix removes them from customer-facing featured/gallery slots while preserving ordinary merchant media.

'''
if needle not in r:
    raise SystemExit('readme changelog anchor missing')
r = r.replace(needle, needle + entry, 1)
readme.write_text(r, encoding='utf-8')

print('v2.0.19 patch applied')
