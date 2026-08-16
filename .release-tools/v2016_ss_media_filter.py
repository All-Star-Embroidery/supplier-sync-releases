#!/usr/bin/env python3
from pathlib import Path
import re
import sys

root = Path(sys.argv[1] if len(sys.argv) > 1 else '.')
main = root / 'all-star-supplier-sync.php'
imp = root / 'includes/class-asss-importer.php'
readme = root / 'readme.txt'


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly 1 match, found {count}')
    return text.replace(old, new, 1)


def regex_once(text, pattern, replacement, label):
    new, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly 1 regex match, found {count}')
    return new

# Version bump.
text = main.read_text(encoding='utf-8')
text = replace_once(text, ' * Version: 2.0.15', ' * Version: 2.0.16', 'plugin header')
text = replace_once(text, "define('ASSS_VERSION', '2.0.15');", "define('ASSS_VERSION', '2.0.16');", 'plugin constant')
main.write_text(text, encoding='utf-8')

text = imp.read_text(encoding='utf-8')

# Audit only customer-facing S&S media, not supplier charts/reference graphics.
old_audit = """            if (!empty($this->sanmar->settings()['sync_images']) && !empty($row['gallery'])) {
                if (!$v->get_image_id()) $missing_image++;
                $expected_gallery = count(array_unique(array_filter((array)$row['gallery'])));
                $saved_gallery = $v->get_meta('_asss_variation_gallery_ids');
                if (is_string($saved_gallery)) {
                    $decoded = json_decode($saved_gallery, true);
                    if (is_array($decoded)) $saved_gallery = $decoded;
                }
                $saved_gallery = array_values(array_unique(array_filter(array_map('intval', (array)$saved_gallery))));
                if ($expected_gallery > 1 && !$saved_gallery) $missing_gallery++;
                elseif ($expected_gallery > 1 && count($saved_gallery) < $expected_gallery) $incomplete_gallery++;
            }
"""
new_audit = """            if (!empty($this->sanmar->settings()['sync_images'])) {
                $classified_media = $this->ss_classify_variant_media($row);
                $expected_gallery = count($classified_media['storefront']);
                if ($expected_gallery > 0 && !$v->get_image_id()) $missing_image++;
                $saved_gallery = $v->get_meta('_asss_variation_gallery_ids');
                if (is_string($saved_gallery)) {
                    $decoded = json_decode($saved_gallery, true);
                    if (is_array($decoded)) $saved_gallery = $decoded;
                }
                $saved_gallery = array_values(array_unique(array_filter(array_map('intval', (array)$saved_gallery))));
                if ($expected_gallery > 1 && !$saved_gallery) $missing_gallery++;
                elseif ($expected_gallery > 1 && count($saved_gallery) < $expected_gallery) $incomplete_gallery++;
            }
"""
text = replace_once(text, old_audit, new_audit, 'S&S audit media block')

# Insert explicit storefront/reference classification after supplier attachment detection.
anchor = """    private function is_supplier_attachment(int $attachment_id): bool {
        if ($attachment_id < 1) return false;
        if ((string)get_post_meta($attachment_id, '_asss_image_url', true) !== '') return true;
        return in_array((string)get_post_meta($attachment_id, '_asss_image_source', true), ['sanmar','ss','momentec','supplier'], true);
    }

"""
helpers = anchor + """    private function ss_media_kind(string $value): string {
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', trim($value)));
    }

    /**
     * Split S&S media into customer-facing product photography and supplier-only
     * reference media. Unknown asset types are deliberately reference-only so a
     * color chart/swatch board can never leak into the WooCommerce gallery.
     */
    private function ss_classify_variant_media(array $row): array {
        $storefront = [];
        $reference = [];
        $allowed = array_fill_keys([
            'front','directside','side','back',
            'onmodelfront','onmodelside','onmodelback',
        ], true);

        // Named S&S product-photo fields are trusted storefront media.
        $images = is_array($row['images'] ?? null) ? $row['images'] : [];
        foreach ([
            'front'=>'front','direct_side'=>'directside','side'=>'side','back'=>'back',
            'on_model_front'=>'onmodelfront','on_model_side'=>'onmodelside','on_model_back'=>'onmodelback',
        ] as $key=>$kind) {
            $url = $this->normalize_ss_media_url((string)($images[$key] ?? ''));
            if ($url !== '' && isset($allowed[$kind])) $storefront[$url] = true;
        }

        $gallery = is_array($row['gallery'] ?? null) ? array_values($row['gallery']) : [];
        $types = is_array($row['gallery_types'] ?? null) ? array_values($row['gallery_types']) : [];
        foreach ($gallery as $i=>$raw) {
            $url = $this->normalize_ss_media_url((string)$raw);
            if ($url === '') continue;
            $kind = $this->ss_media_kind((string)($types[$i] ?? ''));
            if ($kind !== '' && isset($allowed[$kind])) $storefront[$url] = true;
            else $reference[$url] = true;
        }

        // Future GitHub normalizers may provide an explicit reference bucket.
        foreach ((array)($row['reference_media'] ?? []) as $raw) {
            $url = $this->normalize_ss_media_url((string)$raw);
            if ($url !== '') $reference[$url] = true;
        }

        // A primary image is only safe if it was independently identified as a
        // known product photo. Otherwise retain it as supplier reference media.
        $primary = $this->normalize_ss_media_url((string)($row['primary_image'] ?? ''));
        if ($primary !== '' && !isset($storefront[$primary])) $reference[$primary] = true;

        foreach (array_keys($storefront) as $url) unset($reference[$url]);
        return ['storefront'=>array_keys($storefront), 'reference'=>array_keys($reference)];
    }

    private function save_ss_reference_media(int $post_id, array $urls): void {
        $clean = [];
        foreach ($urls as $raw) {
            $url = $this->normalize_ss_media_url((string)$raw);
            if ($url !== '') $clean[$url] = true;
        }
        if ($clean) update_post_meta($post_id, '_asss_ss_reference_media_urls', wp_json_encode(array_keys($clean)));
        else delete_post_meta($post_id, '_asss_ss_reference_media_urls');
    }

"""
text = replace_once(text, anchor, helpers, 'S&S media helper insertion')

# Replace variation media sync so stale supplier charts are actively removed while manual media is preserved.
variation_method = r"    private function sync_ss_variation_media\(int \$variation_id, array \$row\): void \{.*?\n    \}\n\n    private function ss_featured_color_rank"
variation_replacement = """    private function sync_ss_variation_media(int $variation_id, array $row): void {
        $v = wc_get_product($variation_id);
        if (!$v instanceof WC_Product_Variation) return;

        $classified = $this->ss_classify_variant_media($row);
        $urls = array_values((array)$classified['storefront']);
        $this->save_ss_reference_media($variation_id, (array)$classified['reference']);

        $current_primary = (int)$v->get_image_id();
        $manual_primary = $current_primary && !$this->is_supplier_attachment($current_primary);
        $manual_gallery = [];
        if (method_exists($v, 'get_gallery_image_ids')) {
            foreach ((array)$v->get_gallery_image_ids() as $id) {
                $id = (int)$id;
                if ($id && !$this->is_supplier_attachment($id)) $manual_gallery[] = $id;
            }
        }

        // No verified storefront photo: remove Supplier Sync-owned media from the
        // variation instead of leaving a color board/chart visible to customers.
        if (!$urls) {
            if (!$manual_primary) $v->set_image_id(0);
            if (method_exists($v, 'set_gallery_image_ids')) $v->set_gallery_image_ids(array_values(array_unique($manual_gallery)));
            $full = $manual_primary ? array_values(array_unique(array_merge([$current_primary], $manual_gallery))) : array_values(array_unique($manual_gallery));
            $v->update_meta_data('_asss_variation_gallery_ids', $full);
            $v->update_meta_data('_asss_variation_gallery_urls', []);
            $v->update_meta_data('_asss_variation_gallery_supplier_count', 0);
            $v->delete_meta_data('_asss_resolved_variation_image_url');
            $v->save();
            return;
        }

        $supplier_ids = [];
        foreach ($urls as $url) {
            $aid = $this->sideload($url, $variation_id, 'ss');
            if ($aid) $supplier_ids[] = (int)$aid;
        }
        $supplier_ids = array_values(array_unique(array_filter($supplier_ids)));
        if (!$supplier_ids) return;

        if (!$manual_primary) $v->set_image_id($supplier_ids[0]);
        $primary_id = $manual_primary ? $current_primary : $supplier_ids[0];
        $supplier_extra = array_values(array_filter($supplier_ids, static fn($id) => (int)$id !== (int)$primary_id));
        if (method_exists($v, 'set_gallery_image_ids')) {
            $v->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_extra, $manual_gallery))));
        }
        $full = $manual_primary
            ? array_values(array_unique(array_merge([$current_primary], $supplier_ids, $manual_gallery)))
            : array_values(array_unique(array_merge($supplier_ids, $manual_gallery)));
        $v->update_meta_data('_asss_variation_gallery_ids', $full);
        $v->update_meta_data('_asss_variation_gallery_urls', $urls);
        $v->update_meta_data('_asss_variation_gallery_supplier_count', count($supplier_ids));
        $v->update_meta_data('_asss_resolved_variation_image_url', esc_url_raw($urls[0]));
        $v->save();
    }

    private function ss_featured_color_rank"""
text = regex_once(text, variation_method, variation_replacement, 'variation media method')

# A generic S&S "style" asset may be a full color board, so it is not a trusted product angle.
text = replace_once(text, "$allowed=['front','style','directside','side','back'];", "$allowed=['front','directside','side','back'];", 'featured safe media allowlist')

# Parent featured image: product photos only. Never fall back to the style/color-board image.
featured_method = r"    private function ss_parent_featured_url\(array \$data,array \$variants\): string \{.*?\n    \}\n\n    private function sync_ss_parent_media"
featured_replacement = """    private function ss_parent_featured_url(array $data,array $variants): string {
        $ranked=[];
        foreach(array_values($variants) as $index=>$row){
            if(!is_array($row))continue;
            $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            $ranked[]=['rank'=>$this->ss_featured_color_rank($color,(int)$index),'index'=>(int)$index,'row'=>$row];
        }
        usort($ranked,static function($a,$b){
            $cmp=((int)$a['rank'])<=>((int)$b['rank']);
            return $cmp!==0?$cmp:(((int)$a['index'])<=>((int)$b['index']));
        });

        // Prefer a clean FRONT shot in a familiar neutral storefront color.
        foreach($ranked as $entry){
            if((int)$entry['rank']>=10000)continue;
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],true);
            if($url!=='')return $url;
        }
        // Then any clean FRONT shot from the selected supplier colors.
        foreach($ranked as $entry){
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],true);
            if($url!=='')return $url;
        }
        // Then a clean non-model product angle. Style-level generic images are
        // intentionally excluded because S&S sometimes uses a full color board.
        foreach($ranked as $entry){
            if((int)$entry['rank']>=10000)continue;
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],false);
            if($url!=='')return $url;
        }
        foreach($ranked as $entry){
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],false);
            if($url!=='')return $url;
        }
        return '';
    }

    private function sync_ss_parent_media"""
text = regex_once(text, featured_method, featured_replacement, 'parent featured method')

# Parent gallery: only classified storefront media. Supplier charts are retained as reference metadata.
parent_method = r"    private function sync_ss_parent_media\(int \$product_id, array \$data, array \$variants\): void \{.*?\n    \}\n\n    private function sync_ss_bulk_order_fields"
parent_replacement = """    private function sync_ss_parent_media(int $product_id, array $data, array $variants): void {
        $product = wc_get_product($product_id);
        if (!$product) return;

        $storefront = [];
        $reference = [];
        foreach ([(string)($data['images']['thumbnail'] ?? ''),(string)($data['images']['product'] ?? '')] as $raw) {
            $url = $this->normalize_ss_media_url($raw);
            if ($url !== '') $reference[$url] = true;
        }
        foreach ((array)($data['reference_media'] ?? []) as $raw) {
            $url = $this->normalize_ss_media_url((string)$raw);
            if ($url !== '') $reference[$url] = true;
        }
        foreach ($variants as $row) {
            if (!is_array($row)) continue;
            $classified = $this->ss_classify_variant_media($row);
            foreach ((array)$classified['storefront'] as $url) $storefront[$url] = true;
            foreach ((array)$classified['reference'] as $url) $reference[$url] = true;
        }
        foreach (array_keys($storefront) as $url) unset($reference[$url]);
        $this->save_ss_reference_media($product_id, array_keys($reference));

        $current = (int)$product->get_image_id();
        $manual_featured = $current && !$this->is_supplier_attachment($current);
        $featured_id = $current;
        $featured_url = '';
        if (!$manual_featured) {
            $featured_url = $this->ss_parent_featured_url($data, $variants);
            if ($featured_url !== '') {
                $candidate = $this->sideload($featured_url, $product_id, 'ss');
                if ($candidate) {
                    $featured_id = (int)$candidate;
                    $product->set_image_id($featured_id);
                }
            } else {
                // Existing Supplier Sync-owned color board/reference image must
                // disappear even when S&S has no clean product photo to replace it.
                $product->set_image_id(0);
                $featured_id = 0;
            }
        }

        $ids = [];
        foreach (array_slice(array_keys($storefront), 0, 12) as $url) {
            if ($featured_url !== '' && hash_equals($featured_url, $url) && $featured_id) {
                $ids[] = $featured_id;
                continue;
            }
            $id = $this->sideload($url, $product_id, 'ss');
            if ($id) $ids[] = (int)$id;
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $manual_gallery = [];
        foreach ($product->get_gallery_image_ids() as $id) {
            $id = (int)$id;
            if ($id && !$this->is_supplier_attachment($id)) $manual_gallery[] = $id;
        }
        $primary = (int)$product->get_image_id();
        $supplier_gallery = array_values(array_filter($ids, static fn($id) => (int)$id !== $primary));
        $product->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_gallery, $manual_gallery))));
        $product->save();

        if (!$manual_featured && $featured_url !== '') update_post_meta($product_id, '_asss_ss_featured_image_url', esc_url_raw($featured_url));
        elseif (!$manual_featured) delete_post_meta($product_id, '_asss_ss_featured_image_url');
    }

    private function sync_ss_bulk_order_fields"""
text = regex_once(text, parent_method, parent_replacement, 'parent media method')

# Multi-supplier repairs may safely call the media reconciler every time because it protects merchant images.
old_multi = "if(!empty($this->sanmar->settings()['sync_images']) && $existing_v instanceof WC_Product_Variation && !$existing_v->get_image_id()) $this->sync_ss_variation_media($vid,$row);"
new_multi = "if(!empty($this->sanmar->settings()['sync_images']) && $existing_v instanceof WC_Product_Variation) $this->sync_ss_variation_media($vid,$row);"
if old_multi in text:
    text = text.replace(old_multi, new_multi)

imp.write_text(text, encoding='utf-8')

# Readme/changelog.
text = readme.read_text(encoding='utf-8')
text = replace_once(text, 'Stable tag: 2.0.15', 'Stable tag: 2.0.16', 'readme stable tag')
marker = '== Changelog ==\n\n'
entry = """== Changelog ==

= 2.0.16 =
* S&S full-color boards, swatch sheets, generic style images, and unknown media asset types are excluded from customer-facing product and variation galleries.
* Only verified product photography (front, side, back, direct-side, and on-model angles) can enter S&S storefront galleries.
* Supplier reference graphics are retained separately in Supplier Sync metadata instead of being discarded or displayed to customers.
* S&S featured images no longer fall back to generic style-level imagery that may contain a full color panel.
* Quick Repair now removes stale Supplier Sync-owned S&S reference graphics from existing product/variation galleries while preserving merchant-added images.
* S&S variation-gallery audits now evaluate only verified storefront media, avoiding false missing-gallery warnings from reference graphics.

"""
text = replace_once(text, marker, entry, 'readme changelog insertion')
readme.write_text(text, encoding='utf-8')

print('Patched All Star Supplier Sync to v2.0.16 S&S media filtering.')
