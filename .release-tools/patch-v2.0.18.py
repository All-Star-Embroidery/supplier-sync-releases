#!/usr/bin/env python3
from pathlib import Path
import re

root = Path('src')
path = root / 'includes/class-asss-importer.php'
s = path.read_text(encoding='utf-8')

def replace_once(old, new, label):
    global s
    n = s.count(old)
    if n != 1:
        raise SystemExit(f'{label}: expected exactly 1 literal match, found {n}')
    s = s.replace(old, new, 1)

def sub_once(pattern, repl, label, flags=re.S):
    global s
    s2, n = re.subn(pattern, repl, s, count=1, flags=flags)
    if n != 1:
        raise SystemExit(f'{label}: expected exactly 1 regex match, found {n}')
    s = s2

# Hook post-sync cleanup/size normalization plus one-time local migration.
replace_once(
"        add_action('admin_init', [$this, 'migrate_supplier_categories_v2015'], 35);",
"        add_action('admin_init', [$this, 'migrate_supplier_categories_v2015'], 35);\n        add_action('admin_init', [$this, 'migrate_storefront_media_and_sizes_v2018'], 36);\n        add_action('asss_product_synced', [$this, 'normalize_product_storefront_sizes'], 45, 2);\n        add_action('asss_product_synced', [$this, 'cleanup_invalid_supplier_storefront_media'], 50, 2);",
'constructor hooks')

# Expand verified size equivalence, then add storefront size + media safety helpers.
pattern = r"    private function canonical_supplier_size\(string \$brand, string \$style, string \$size\): string \{.*?\n    \}\n\n    /\*\*\n     \* Supplier descriptions"
replacement = r'''    private function canonical_supplier_size(string $brand, string $style, string $size): string {
        $product_key = strtolower(preg_replace('/\s+/u', '', trim($brand))) . '|' . strtolower(preg_replace('/\s+/u', '', trim($style)));
        $size_key = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($size)));
        $maps = [
            // Richardson 112 standard fit: SanMar publishes OSFA while S&S
            // publishes M/L for the equivalent standard-size SKU family.
            'richardson|112' => [
                'osfa' => 'standard-fit',
                'ml' => 'standard-fit',
                'mdlg' => 'standard-fit',
            ],
        ];
        $maps = apply_filters('asss_supplier_size_aliases', $maps, $brand, $style);
        if (is_array($maps) && !empty($maps[$product_key]) && is_array($maps[$product_key])) {
            $mapped = (string)($maps[$product_key][$size_key] ?? '');
            if ($mapped !== '') return $mapped;
        }
        // Supplier-wide one-size vocabulary. Style-specific maps above win so
        // Richardson 112 OSFA and M/L still collapse onto the same verified fit.
        if (in_array($size_key, ['os','osfa','onesize','onesizefitsall'], true)) return 'one-size';
        return '';
    }

    /** Customer-facing size label; raw supplier labels remain in source metadata. */
    private function storefront_size_label(string $brand, string $style, string $size): string {
        $size = trim($size);
        if ($size === '') return '';
        $alias = $this->canonical_supplier_size($brand, $style, $size);
        if (in_array($alias, ['one-size','standard-fit'], true)) return 'OSFA';
        return $size;
    }

    /**
     * Global supplier-media denylist for non-product graphics. This sits below
     * supplier-specific media classifiers as a final safety net so a supplier
     * cannot accidentally promote a swatch, chart, placeholder, or unavailable
     * image into WooCommerce simply because it labels the asset as a photo.
     */
    private function is_invalid_storefront_media_url(string $url): bool {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') return true;
        $decoded = strtolower(rawurldecode($url));
        $compact = preg_replace('/[^a-z0-9]+/i', '', $decoded);
        $markers = [
            'imagenotyetavailableforselectedcolor','imagenotavailable','imageunavailable','noimageavailable',
            'imagenotfound','noimage','nophoto','placeholder','comingsoon','selectedcolorunavailable',
            'swatchsheet','colorswatch','colorchart','colorboard','colorsquare','sizechart','referencegraphic',
        ];
        $invalid = false;
        foreach ($markers as $marker) {
            if ($marker !== '' && strpos($compact, $marker) !== false) { $invalid = true; break; }
        }
        return (bool)apply_filters('asss_invalid_storefront_media_url', $invalid, $url);
    }

    private function invalid_supplier_attachment(int $attachment_id): bool {
        if ($attachment_id < 1 || !$this->is_supplier_attachment($attachment_id)) return false;
        $url = trim((string)get_post_meta($attachment_id, '_asss_image_url', true));
        if ($url === '') $url = (string)wp_get_attachment_url($attachment_id);
        return $url !== '' && $this->is_invalid_storefront_media_url($url);
    }

    /** Remove only Supplier Sync-owned invalid graphics; merchant media is sacred. */
    public function cleanup_invalid_supplier_storefront_media(int $product_id, string $supplier = ''): void {
        $product = wc_get_product($product_id);
        if (!$product) return;
        $removed = 0;
        $featured = (int)$product->get_image_id();
        if ($featured && $this->invalid_supplier_attachment($featured)) {
            $product->set_image_id(0); $removed++;
        }
        $gallery = [];
        foreach ((array)$product->get_gallery_image_ids() as $id) {
            $id = (int)$id;
            if ($id && $this->invalid_supplier_attachment($id)) { $removed++; continue; }
            if ($id) $gallery[] = $id;
        }
        $product->set_gallery_image_ids(array_values(array_unique($gallery)));
        $product->save();

        foreach ($this->variation_ids_direct($product_id) as $variation_id) {
            $v = wc_get_product($variation_id);
            if (!$v instanceof WC_Product_Variation) continue;
            $changed = false;
            $primary = (int)$v->get_image_id();
            if ($primary && $this->invalid_supplier_attachment($primary)) {
                $v->set_image_id(0); $removed++; $changed = true;
                $resolved = (string)$v->get_meta('_asss_resolved_variation_image_url');
                if ($resolved !== '' && $this->is_invalid_storefront_media_url($resolved)) $v->delete_meta_data('_asss_resolved_variation_image_url');
            }
            if (method_exists($v, 'get_gallery_image_ids') && method_exists($v, 'set_gallery_image_ids')) {
                $keep = [];
                foreach ((array)$v->get_gallery_image_ids() as $id) {
                    $id = (int)$id;
                    if ($id && $this->invalid_supplier_attachment($id)) { $removed++; $changed = true; continue; }
                    if ($id) $keep[] = $id;
                }
                if ($changed) $v->set_gallery_image_ids(array_values(array_unique($keep)));
            }
            $stored = $v->get_meta('_asss_variation_gallery_ids');
            if (is_string($stored)) { $decoded = json_decode($stored, true); if (is_array($decoded)) $stored = $decoded; }
            if (is_array($stored)) {
                $clean = array_values(array_filter(array_map('intval', $stored), fn($id) => $id > 0 && !$this->invalid_supplier_attachment($id)));
                if ($clean !== array_values(array_filter(array_map('intval', $stored)))) {
                    $v->update_meta_data('_asss_variation_gallery_ids', $clean); $changed = true;
                }
            }
            if ($changed) $v->save();
        }
        if ($removed) ASSS_Logger::log('Removed invalid supplier storefront media', 'info', ['product_id'=>$product_id,'supplier'=>$supplier,'attachments_removed'=>$removed]);
    }

    /**
     * Canonicalize only equivalent one-size labels in WooCommerce. Supplier raw
     * labels (OS, OSFA, M/L, etc.) remain untouched in supplier-specific meta.
     */
    public function normalize_product_storefront_sizes(int $product_id, string $supplier = ''): void {
        $changed = 0;
        $parents = [
            'sanmar' => [trim((string)get_post_meta($product_id,'_asss_sanmar_brand',true)), trim((string)get_post_meta($product_id,'_asss_sanmar_style',true))],
            'ss' => [trim((string)get_post_meta($product_id,'_asss_ss_brand',true)), trim((string)get_post_meta($product_id,'_asss_ss_style',true))],
            'momentec' => [trim((string)get_post_meta($product_id,'_asss_momentec_brand',true)), trim((string)get_post_meta($product_id,'_asss_momentec_style',true))],
        ];
        foreach ($this->variation_ids_direct($product_id) as $variation_id) {
            $v = wc_get_product($variation_id);
            if (!$v instanceof WC_Product_Variation) continue;
            $raw_sizes = [
                'sanmar' => trim((string)$v->get_meta('_asss_sanmar_size')),
                'ss' => trim((string)$v->get_meta('_asss_ss_size')),
                'momentec' => trim((string)$v->get_meta('_asss_momentec_size')),
            ];
            $want_osfa = false;
            foreach ($raw_sizes as $key=>$raw_size) {
                if ($raw_size === '') continue;
                [$brand,$style] = $parents[$key];
                if ($this->storefront_size_label($brand,$style,$raw_size) === 'OSFA') { $want_osfa = true; break; }
            }
            if (!$want_osfa) continue;
            $attrs = $v->get_attributes();
            $want = $this->term_slug('pa_size','OSFA');
            if (($attrs['pa_size'] ?? '') === $want) continue;
            $attrs['pa_size'] = $want;
            $v->set_attributes($attrs);
            $v->update_meta_data('_asss_storefront_size_label','OSFA');
            $v->save();
            $changed++;
        }
        if ($changed) {
            $this->rebuild_attributes_from_variations($product_id);
            WC_Product_Variable::sync($product_id);
            wc_delete_product_transients($product_id);
            ASSS_Logger::log('Normalized equivalent one-size labels to OSFA', 'info', ['product_id'=>$product_id,'variations'=>$changed]);
        }
    }

    /** Local, one-time cleanup for products imported before 2.0.18. */
    public function migrate_storefront_media_and_sizes_v2018(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2018_storefront_media_sizes_migrated','') === 'yes') return;
        $ids = get_posts(['post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,'meta_key'=>'_asss_sync_enabled','meta_value'=>'yes']);
        foreach ((array)$ids as $product_id) {
            $this->cleanup_invalid_supplier_storefront_media((int)$product_id, 'migration');
            $this->normalize_product_storefront_sizes((int)$product_id, 'migration');
        }
        update_option('asss_v2018_storefront_media_sizes_migrated','yes',false);
        ASSS_Logger::log('v2.0.18 storefront media/size migration completed', 'info', ['products'=>count((array)$ids)]);
    }

    /**
     * Supplier descriptions'''
sub_once(pattern, replacement, 'canonical size + global media helpers')

# Global OS/One Size term canonicalization (contextual M/L remains style-specific and post-sync).
replace_once(
"    private function term_slug(string $taxonomy, string $value): string {\n        $term_id = $this->ensure_term($taxonomy, $value);",
"    private function term_slug(string $taxonomy, string $value): string {\n        if ($taxonomy === 'pa_size') {\n            $size_key = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)));\n            if (in_array($size_key, ['os','osfa','onesize','onesizefitsall'], true)) $value = 'OSFA';\n        }\n        $term_id = $this->ensure_term($taxonomy, $value);",
'term_slug one-size canonicalization')

# SanMar parent media must skip placeholder/unavailable assets before choosing fallbacks.
replace_once(
"            if ($candidate && !isset($reference[$candidate]) && filter_var($candidate, FILTER_VALIDATE_URL)) {",
"            if ($candidate && !isset($reference[$candidate]) && filter_var($candidate, FILTER_VALIDATE_URL) && !$this->is_invalid_storefront_media_url($candidate)) {",
'SanMar featured placeholder filter')
replace_once(
"            if ($url && $url !== $featured_url && !isset($reference[$url]) && filter_var($url, FILTER_VALIDATE_URL)) $gallery_urls[$url] = true;",
"            if ($url && $url !== $featured_url && !isset($reference[$url]) && filter_var($url, FILTER_VALIDATE_URL) && !$this->is_invalid_storefront_media_url($url)) $gallery_urls[$url] = true;",
'SanMar gallery placeholder filter')

# SanMar variation gallery metadata itself should not retain invalid URLs.
pattern = r"(    private function variation_gallery_urls\(array \$row\): array \{.*?)(        return array_keys\(\$urls\);\n    \})"
def filter_variation_gallery(m):
    return m.group(1) + "        return array_values(array_filter(array_keys($urls), fn($url) => !$this->is_invalid_storefront_media_url((string)$url)));\n    }"
sub_once(pattern, filter_variation_gallery, 'SanMar variation gallery filter')

# Final download safety net: never sideload a known invalid storefront graphic.
replace_once(
"    private function sideload(string $url, int $parent, string $source = 'sanmar'): int {\n        $url = trim($url);\n        if (!filter_var($url, FILTER_VALIDATE_URL)) return 0;",
"    private function sideload(string $url, int $parent, string $source = 'sanmar'): int {\n        $url = trim($url);\n        if ($this->is_invalid_storefront_media_url($url)) return 0;\n        if (!filter_var($url, FILTER_VALIDATE_URL)) return 0;",
'sideload safety net')

# S&S and Momentec URL normalizers enforce the same global denylist.
pattern = r"    private function normalize_ss_media_url\(string \$url\): string \{.*?\n    \}"
replacement = r'''    private function normalize_ss_media_url(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        $normalized = preg_match('#^https?://#i', $url) ? esc_url_raw($url) : esc_url_raw('https://www.ssactivewear.com/' . ltrim($url, '/'));
        return ($normalized !== '' && !$this->is_invalid_storefront_media_url($normalized)) ? $normalized : '';
    }'''
sub_once(pattern, replacement, 'S&S URL placeholder filter')

pattern = r"    private function momentec_media_url\(string \$url\): string \{.*?\n    \}"
replacement = r'''    private function momentec_media_url(string $url): string {
        $url = trim($url);
        $normalized = preg_match('#^https?://#i',$url) ? esc_url_raw($url) : '';
        return ($normalized !== '' && !$this->is_invalid_storefront_media_url($normalized)) ? $normalized : '';
    }'''
sub_once(pattern, replacement, 'Momentec URL placeholder filter')

path.write_text(s, encoding='utf-8')

# Version bump.
main = root / 'all-star-supplier-sync.php'
t = main.read_text(encoding='utf-8')
t = t.replace('Version: 2.0.17', 'Version: 2.0.18', 1).replace("define('ASSS_VERSION', '2.0.17');", "define('ASSS_VERSION', '2.0.18');", 1)
if '2.0.18' not in t: raise SystemExit('version bump failed')
main.write_text(t, encoding='utf-8')

readme = root / 'readme.txt'
r = readme.read_text(encoding='utf-8')
r = re.sub(r'^Stable tag: 2\.0\.17$', 'Stable tag: 2.0.18', r, count=1, flags=re.M)
marker = '== Changelog ==\n'
entry = '''== Changelog ==\n\n= 2.0.18 =\n* Added a global storefront-media denylist across SanMar, S&S Activewear, and Momentec for image-not-available placeholders, unavailable-color graphics, swatch sheets, color boards/squares, size charts, and similar supplier reference graphics.\n* Invalid supplier graphics are blocked before sideload and removed from existing Supplier Sync-owned featured/product/variation galleries; merchant-uploaded media remains protected.\n* Added a one-time local cleanup for existing supplier-linked products plus ongoing cleanup after every import, link, refresh, and Quick Repair.\n* Canonicalized equivalent one-size supplier labels to customer-facing OSFA while preserving raw supplier size values for inventory/matching. OS, OSFA, One Size, and One Size Fits All are equivalent; Richardson 112's verified M/L alias also displays as OSFA.\n* Extended cross-supplier one-size matching so OS and OSFA can share one WooCommerce variation without weakening unrelated M/L matching.\n\n'''
if marker not in r: raise SystemExit('readme changelog marker missing')
r = r.replace(marker, entry, 1)
readme.write_text(r, encoding='utf-8')

print('v2.0.18 patch applied')
