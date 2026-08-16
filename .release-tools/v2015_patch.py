#!/usr/bin/env python3
from pathlib import Path
import re
import sys

root = Path(sys.argv[1] if len(sys.argv) > 1 else 'source')

# Version bump.
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = text.replace('Version: 2.0.14', 'Version: 2.0.15', 1)
text = text.replace("define('ASSS_VERSION', '2.0.14');", "define('ASSS_VERSION', '2.0.15');", 1)
main.write_text(text, encoding='utf-8')

# Importer: one robust category pipeline for every supplier.
imp = root / 'includes/class-asss-importer.php'
text = imp.read_text(encoding='utf-8')

old_ctor = "        add_action('asss_momentec_parent_media_job', [$this, 'momentec_parent_media_job'], 10, 1);\n"
if old_ctor not in text:
    raise SystemExit('Importer constructor anchor missing')
text = text.replace(old_ctor, old_ctor + "        add_action('admin_init', [$this, 'migrate_supplier_categories_v2015'], 35);\n", 1)

category_pattern = re.compile(
    r"    /\*\* Collect every distinct category signal present in supplier rows\. \*/\n"
    r"    private function categories_from_rows\(array \$rows\): array \{.*?\n"
    r"    private function sync_tags\(int \$product_id, string \$keywords\): void \{",
    re.S,
)
category_replacement = r'''    /** Collect every distinct category signal present in supplier rows. */
    private function categories_from_rows(array $rows): array {
        $out = [];
        $single_fields = [
            'CATEGORY','CATEGORY_NAME','CATEGORY_PATH','PRODUCT_CATEGORY','PRODUCT_CATEGORY_NAME',
            'BASE_CATEGORY','SUBCATEGORY','SUB_CATEGORY','SUB_CATEGORY_NAME',
        ];
        $multi_fields = ['CATEGORIES','CATEGORY_NAMES','PRODUCT_CATEGORIES'];
        $add = static function(string $value) use (&$out): void {
            $value = trim(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($value === '' || ctype_digit($value)) return;
            $out[mb_strtolower(preg_replace('/\s+/u', ' ', $value))] = $value;
        };

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            foreach ($single_fields as $field) {
                $value = trim((string)$this->sanmar->first($row, [$field]));
                if ($value !== '') $add($value);
            }
            foreach ($multi_fields as $field) {
                $value = trim((string)$this->sanmar->first($row, [$field]));
                if ($value === '') continue;
                foreach (preg_split('/\s*[,;|]\s*/u', $value) ?: [] as $part) $add((string)$part);
            }
        }
        return array_values($out);
    }

    /** Pull every category signal from a normalized S&S/Momentec product payload. */
    private function categories_from_normalized_product(array $data): array {
        $out = [];
        $add = static function($value) use (&$out): void {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    if (is_scalar($nested)) {
                        $v = trim(wp_strip_all_tags((string)$nested));
                        if ($v !== '' && !ctype_digit($v)) $out[mb_strtolower(preg_replace('/\s+/u', ' ', $v))] = $v;
                    }
                }
                return;
            }
            if (!is_scalar($value)) return;
            $v = trim(wp_strip_all_tags((string)$value));
            if ($v !== '' && !ctype_digit($v)) $out[mb_strtolower(preg_replace('/\s+/u', ' ', $v))] = $v;
        };
        foreach (['category','base_category','product_category','subcategory','sub_category','category_path'] as $key) {
            if (array_key_exists($key, $data)) $add($data[$key]);
        }
        if (array_key_exists('categories', $data)) $add($data['categories']);
        return array_values($out);
    }

    /** Normalize one supplier category label into a WooCommerce hierarchy path. */
    private function supplier_category_path(string $value): array {
        $value = trim(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($value === '' || ctype_digit($value)) return [];
        // Only explicit hierarchy delimiters become parent/child relationships.
        // Slash and hyphen stay untouched because apparel category names commonly
        // contain them as literal text (for example Youth/Adult or Performance-Tee).
        $parts = preg_split('/\s*(?:>|»|→|::)\s*/u', $value) ?: [$value];
        $path = [];
        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string)$part)));
            if ($part === '' || ctype_digit($part)) continue;
            $path[] = $part;
        }
        return $path;
    }

    private function normalize_supplier_category_paths(array $categories): array {
        $paths = [];
        foreach ($categories as $category) {
            if (is_array($category)) {
                foreach ($category as $nested) {
                    if (!is_scalar($nested)) continue;
                    $path = $this->supplier_category_path((string)$nested);
                    if ($path) $paths[mb_strtolower(implode(' > ', $path))] = $path;
                }
                continue;
            }
            if (!is_scalar($category)) continue;
            $path = $this->supplier_category_path((string)$category);
            if ($path) $paths[mb_strtolower(implode(' > ', $path))] = $path;
        }
        return array_values($paths);
    }

    /** Create/reuse a real WooCommerce parent/child category path and return every level ID. */
    private function ensure_product_category_path(array $path): array {
        $ids = [];
        $parent = 0;
        foreach ($path as $name) {
            $name = trim(sanitize_text_field((string)$name));
            if ($name === '') continue;
            $term = term_exists($name, 'product_cat', $parent);
            if (!$term) $term = wp_insert_term($name, 'product_cat', ['parent'=>$parent]);
            if (is_wp_error($term)) {
                // Resolve a same-parent slug collision without attaching a term
                // from a different hierarchy branch by mistake.
                $matches = get_terms([
                    'taxonomy'=>'product_cat','hide_empty'=>false,'name'=>$name,'parent'=>$parent,'number'=>1,
                ]);
                if (is_wp_error($matches) || empty($matches[0])) continue;
                $term_id = (int)$matches[0]->term_id;
            } else {
                $term_id = (int)(is_array($term) ? $term['term_id'] : $term);
            }
            if (!$term_id) continue;
            $ids[] = $term_id;
            $parent = $term_id;
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function supplier_category_ids(int $product_id, string $supplier): array {
        $raw = json_decode((string)get_post_meta($product_id, '_asss_supplier_category_ids_' . sanitize_key($supplier), true), true);
        if (!is_array($raw)) $raw = [];
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    /**
     * Apply one supplier's categories while preserving merchant categories and
     * categories owned by every other linked supplier. Stale categories from
     * this supplier are removed only when they are not also owned elsewhere.
     */
    private function sync_supplier_categories(int $product_id, array $categories, string $supplier, bool $is_new = false): void {
        $supplier = sanitize_key($supplier) ?: 'supplier';
        $paths = $this->normalize_supplier_category_paths($categories);
        if (!$paths) return; // Never erase known categories because of an empty/partial supplier payload.

        $new_ids = [];
        $path_labels = [];
        foreach ($paths as $path) {
            $ids = $this->ensure_product_category_path($path);
            foreach ($ids as $id) $new_ids[$id] = true;
            $path_labels[] = implode(' > ', $path);
        }
        $new_ids = array_map('intval', array_keys($new_ids));
        if (!$new_ids) return;

        $suppliers = ['sanmar','ss','momentec'];
        if (!in_array($supplier, $suppliers, true)) $suppliers[] = $supplier;
        $owned_before = [];
        $other_owned = [];
        $previous = $this->supplier_category_ids($product_id, $supplier);

        // Adopt the old pre-2.0.15 global ownership marker for single-supplier
        // products so the first refresh can cleanly migrate without duplicating
        // or accidentally treating old supplier categories as merchant-owned.
        if (!$previous) {
            $legacy = json_decode((string)get_post_meta($product_id, '_asss_supplier_category_ids', true), true);
            $stored_supplier = sanitize_key((string)get_post_meta($product_id, '_asss_supplier', true));
            if (is_array($legacy) && ($stored_supplier === $supplier || $stored_supplier !== 'multi')) {
                $previous = array_values(array_unique(array_filter(array_map('intval', $legacy))));
            }
        }

        foreach ($suppliers as $key) {
            $ids = $key === $supplier ? $previous : $this->supplier_category_ids($product_id, $key);
            foreach ($ids as $id) {
                $owned_before[(int)$id] = true;
                if ($key !== $supplier) $other_owned[(int)$id] = true;
            }
        }

        $current = wp_get_object_terms($product_id, 'product_cat', ['fields'=>'ids']);
        if (is_wp_error($current)) $current = [];
        $current = array_values(array_unique(array_filter(array_map('intval', $current))));
        $manual = $is_new ? [] : array_values(array_filter($current, static fn($id) => !isset($owned_before[(int)$id])));

        $merged_map = [];
        foreach ($manual as $id) $merged_map[(int)$id] = true;
        foreach (array_keys($other_owned) as $id) $merged_map[(int)$id] = true;
        foreach ($new_ids as $id) $merged_map[(int)$id] = true;

        $default_cat = (int)get_option('default_product_cat', 0);
        if ($default_cat && count($merged_map) > 1 && !in_array($default_cat, $manual, true)) unset($merged_map[$default_cat]);
        $merged = array_map('intval', array_keys($merged_map));
        wp_set_object_terms($product_id, $merged, 'product_cat', false);

        update_post_meta($product_id, '_asss_supplier_category_ids_' . $supplier, wp_json_encode(array_values($new_ids)));
        update_post_meta($product_id, '_asss_supplier_categories_' . $supplier, wp_json_encode(array_values($path_labels)));

        // Keep legacy combined metadata accurate for older admin/UI code.
        $combined_ids = [];
        $combined_names = [];
        foreach ($suppliers as $key) {
            $ids = $key === $supplier ? $new_ids : $this->supplier_category_ids($product_id, $key);
            foreach ($ids as $id) {
                $combined_ids[(int)$id] = true;
                $term = get_term((int)$id, 'product_cat');
                if ($term && !is_wp_error($term)) $combined_names[mb_strtolower($term->name)] = $term->name;
            }
        }
        update_post_meta($product_id, '_asss_supplier_category_ids', wp_json_encode(array_map('intval', array_keys($combined_ids))));
        update_post_meta($product_id, '_asss_supplier_categories', wp_json_encode(array_values($combined_names)));
        if ($path_labels) update_post_meta($product_id, '_asss_supplier_category', $path_labels[0]);
    }

    private function sync_taxonomies(int $product_id, string $brand, array $categories, string $keywords, bool $is_new, string $supplier = 'sanmar'): void {
        $this->sync_supplier_categories($product_id, $categories, $supplier, $is_new);
        $this->sync_tags($product_id, $keywords);
        $this->sync_brand($product_id, $brand);
    }

    /**
     * One-time category-only reconciliation for products imported before 2.0.15.
     * Uses already-cached supplier data only; it does not contact supplier APIs.
     */
    public function migrate_supplier_categories_v2015(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2015_supplier_categories_migrated', '') === 'yes') return;
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_key'=>'_asss_sync_enabled','meta_value'=>'yes',
        ]);
        $updated = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            $sources = $this->multi->product_sources($product_id);
            if (!empty($sources['sanmar']['enabled'])) {
                $brand = trim((string)get_post_meta($product_id, '_asss_sanmar_brand', true));
                $style = trim((string)get_post_meta($product_id, '_asss_sanmar_style', true));
                if ($brand !== '' && $style !== '') {
                    $data = $this->sanmar->rows_for_style($brand, $style);
                    if (!is_wp_error($data) && !empty($data['rows'])) {
                        $this->sync_supplier_categories($product_id, $this->categories_from_rows((array)$data['rows']), 'sanmar', false);
                    }
                }
            }
            if (!empty($sources['ss']['enabled'])) {
                $brand_id = absint(get_post_meta($product_id, '_asss_ss_brand_id', true));
                $style_id = absint(get_post_meta($product_id, '_asss_ss_style_id', true));
                if ($brand_id && $style_id) {
                    $data = $this->ss->style_product($brand_id, $style_id);
                    if (!is_wp_error($data)) $this->sync_supplier_categories($product_id, $this->categories_from_normalized_product((array)$data), 'ss', false);
                }
            }
            if (!empty($sources['momentec']['enabled'])) {
                $style = trim((string)get_post_meta($product_id, '_asss_momentec_style', true));
                if ($style !== '') {
                    $data = $this->momentec->style_product($style);
                    if (!is_wp_error($data)) $this->sync_supplier_categories($product_id, $this->categories_from_normalized_product((array)$data), 'momentec', false);
                }
            }
            $updated++;
        }
        update_option('asss_v2015_supplier_categories_migrated', 'yes', false);
        ASSS_Logger::log('v2.0.15 supplier category reconciliation completed', 'info', ['products'=>$updated]);
    }

    private function sync_tags(int $product_id, string $keywords): void {'''
text, count = category_pattern.subn(lambda m: category_replacement, text, count=1)
if count != 1:
    raise SystemExit('Could not replace supplier category pipeline')

add_pattern = re.compile(
    r"    private function add_supplier_categories\(int \$product_id,array \$categories,string \$supplier\): void \{.*?\n    \}\n\n\n    public function find_momentec_product",
    re.S,
)
add_replacement = r'''    private function add_supplier_categories(int $product_id,array $categories,string $supplier): void {
        $this->sync_supplier_categories($product_id, $categories, $supplier, false);
    }


    public function find_momentec_product'''
text, count = add_pattern.subn(lambda m: add_replacement, text, count=1)
if count != 1:
    raise SystemExit('Could not replace add_supplier_categories')

# S&S and Momentec single-supplier import/refresh calls must record the correct owner.
replacements = {
    "$this->sync_taxonomies($product_id, $brand, $categories, $keywords, $is_new);": "$this->sync_taxonomies($product_id, $brand, $categories, $keywords, $is_new, 'ss');",
    "$this->sync_taxonomies($product_id, $brand, $categories, $keywords, false);": "$this->sync_taxonomies($product_id, $brand, $categories, $keywords, false, 'ss');",
    "$this->sync_taxonomies($product_id,$brand,$categories,'',$is_new);": "$this->sync_taxonomies($product_id,$brand,$categories,'',$is_new,'momentec');",
    "$this->sync_taxonomies($product_id,$brand,$categories,'',false);": "$this->sync_taxonomies($product_id,$brand,$categories,'',false,'momentec');",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'Missing taxonomy call anchor: {old}')
    text = text.replace(old, new)

# Use the common normalized category collector for S&S/Momentec payloads so new fields are automatically included.
ss_import_old = "$categories = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : [];\n        $base_category = trim((string)($data['category'] ?? $data['base_category'] ?? ''));\n        if ($base_category !== '') array_unshift($categories, $base_category);\n        $categories = array_values(array_unique(array_filter(array_map('sanitize_text_field', $categories))));"
if ss_import_old in text:
    text = text.replace(ss_import_old, "$categories = $this->categories_from_normalized_product($data);", 1)

ss_update_old = "$categories = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : [];\n        $base = trim((string)($data['category'] ?? $data['base_category'] ?? ''));\n        if ($base !== '') array_unshift($categories, $base);\n        $categories = array_values(array_unique(array_filter(array_map('sanitize_text_field', $categories))));"
if ss_update_old in text:
    text = text.replace(ss_update_old, "$categories = $this->categories_from_normalized_product($data);", 1)

# Compact Momentec import/update/link category extraction.
text = text.replace("$categories=is_array($data['categories'] ?? null)?$data['categories']:[];$base=trim((string)($data['category'] ?? ''));if($base!=='')array_unshift($categories,$base);$categories=array_values(array_unique(array_filter(array_map('sanitize_text_field',$categories))));", "$categories=$this->categories_from_normalized_product($data);", 1)
text = text.replace("$categories=is_array($data['categories'] ?? null)?$data['categories']:[];$base=trim((string)($data['category'] ?? ''));if($base!=='')array_unshift($categories,$base);$categories=array_values(array_unique(array_filter(array_map('sanitize_text_field',$categories))));$this->sync_taxonomies", "$categories=$this->categories_from_normalized_product($data);$this->sync_taxonomies", 1)
text = text.replace("$categories=is_array($data['categories'] ?? null)?$data['categories']:[];$base=trim((string)($data['category'] ?? ''));if($base!=='')array_unshift($categories,$base);$this->add_supplier_categories", "$categories=$this->categories_from_normalized_product($data);$this->add_supplier_categories", 1)

imp.write_text(text, encoding='utf-8')

# Momentec: merge official full-catalog categories into hydrated v2 detail, including existing cached styles.
mom = root / 'includes/class-asss-momentec.php'
text = mom.read_text(encoding='utf-8')
anchor = "    public function catalog_count(): int {\n        $data = $this->catalog_data();\n        return is_array($data['products'] ?? null) ? count($data['products']) : 0;\n    }\n"
if anchor not in text:
    raise SystemExit('Momentec catalog_count anchor missing')
insert = anchor + r'''

    /** Find the official catalog-feed summary for one style. */
    public function catalog_product(string $style): array {
        $key = $this->style_key($style);
        foreach ((array)($this->catalog_data()['products'] ?? []) as $row) {
            if (!is_array($row)) continue;
            if ($this->style_key((string)($row['style'] ?? '')) === $key) return $row;
        }
        return [];
    }

    /**
     * Hydrated v2 data owns customer pricing/SKUs/media; the official product
     * feed owns browse metadata such as Category. Merge them so Woo imports get
     * the richest trustworthy category set without sending credentials to WP.
     */
    private function enrich_style_with_catalog(array $product, string $style): array {
        $catalog = $this->catalog_product($style);
        if (!$catalog) return $product;

        $categories = [];
        foreach ([(string)($product['category'] ?? ''), (string)($product['base_category'] ?? ''), (string)($catalog['category'] ?? '')] as $cat) {
            $cat = trim($cat); if ($cat !== '') $categories[] = $cat;
        }
        foreach ([(array)($product['categories'] ?? []), (array)($catalog['categories'] ?? [])] as $set) {
            foreach ($set as $cat) { $cat = trim((string)$cat); if ($cat !== '') $categories[] = $cat; }
        }
        $categories = $this->clean_text_list($categories);
        if ($categories) $product['categories'] = $categories;
        if (trim((string)($product['category'] ?? '')) === '') {
            $product['category'] = sanitize_text_field((string)($catalog['category'] ?? ($categories[0] ?? '')));
        }
        if (trim((string)($product['brand'] ?? '')) === '') $product['brand'] = sanitize_text_field((string)($catalog['brand'] ?? ''));
        if (trim((string)($product['title'] ?? '')) === '') $product['title'] = sanitize_text_field((string)($catalog['title'] ?? $style));
        if (trim((string)($product['description'] ?? '')) === '') $product['description'] = sanitize_textarea_field((string)($catalog['description'] ?? ''));
        if (trim((string)($product['division'] ?? '')) === '') $product['division'] = sanitize_text_field((string)($catalog['division'] ?? ''));
        if (!is_array($product['images'] ?? null)) $product['images'] = [];
        $catalog_image = esc_url_raw((string)($catalog['image'] ?? ''));
        if ($catalog_image !== '' && empty($product['images']['thumbnail'])) $product['images']['thumbnail'] = $catalog_image;
        if ($catalog_image !== '' && empty($product['images']['product'])) $product['images']['product'] = $catalog_image;
        return $product;
    }
'''
text = text.replace(anchor, insert, 1)

save_anchor = "$product['supplier']='momentec';$product['supplier_name']=self::LABEL;$product['style']=$style;$product['supplier_style_id']=$style;$product['received_at']=current_time('mysql');"
if save_anchor not in text:
    raise SystemExit('Momentec save_style anchor missing')
text = text.replace(save_anchor, "$product=$this->enrich_style_with_catalog($product,$style);" + save_anchor, 1)

style_pattern = re.compile(r"    public function style_product\(string \$style\) \{.*?\n    \}\n\n    public function purge_legacy_wordpress_connection_values", re.S)
style_replacement = r'''    public function style_product(string $style) {
        $key=$this->style_key($style);$meta=$this->style_manifest()[$key] ?? [];
        if(!is_array($meta)||empty($meta['file']))return new WP_Error('momentec_style_missing','Momentec customer-specific details are not cached yet. Queue this style from the Momentec catalog and let GitHub hydrate it.');
        $path=$this->styles_dir().'/'.basename((string)$meta['file']);if(!is_file($path))return new WP_Error('momentec_style_file','Momentec cached style file is missing. Queue the style for refresh.');
        $decoded=json_decode((string)@file_get_contents($path),true);if(!is_array($decoded))return new WP_Error('momentec_style_json','Momentec cached style file is invalid.');
        // Runtime enrichment also upgrades already-cached pre-2.0.15 styles; no
        // new Momentec API hydration is required just to restore categories.
        return $this->enrich_style_with_catalog($decoded,$style);
    }

    public function purge_legacy_wordpress_connection_values'''
text, count = style_pattern.subn(lambda m: style_replacement, text, count=1)
if count != 1:
    raise SystemExit('Could not patch Momentec style_product enrichment')

mom.write_text(text, encoding='utf-8')

# Readme housekeeping.
readme = root / 'readme.txt'
if readme.exists():
    text = readme.read_text(encoding='utf-8')
    text = text.replace('Stable tag: 2.0.6', 'Stable tag: 2.0.15', 1)
    marker = '== Changelog ==\n'
    entry = '''== Changelog ==\n\n= 2.0.15 =\n* Unified WooCommerce product-category import logic across SanMar, S&S Activewear, and Momentec.\n* Categories are assigned during import, link, refresh, and Quick Repair while merchant-owned categories are preserved.\n* Supplier category ownership is tracked separately so one supplier refresh cannot erase categories provided by another supplier.\n* Explicit supplier hierarchy labels create real WooCommerce parent/child category terms instead of flat path-name duplicates.\n* Momentec hydrated v2 styles now inherit the official full-catalog CSV category set, including already-cached styles.\n* Added a one-time category-only reconciliation for existing supplier-linked products using local cache data only.\n\n'''
    if marker in text:
        text = text.replace(marker, entry, 1)
    readme.write_text(text, encoding='utf-8')
