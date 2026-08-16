#!/usr/bin/env python3
from pathlib import Path

root = Path('src')

def read(path):
    return (root / path).read_text(encoding='utf-8')

def write(path, text):
    (root / path).write_text(text, encoding='utf-8')

def replace_once(text, old, new, label):
    n = text.count(old)
    if n != 1:
        raise SystemExit(f'{label}: expected one match, found {n}')
    return text.replace(old, new, 1)

imp = read('includes/class-asss-importer.php')

# New migration hook.
old = "        add_action('admin_init', [$this, 'migrate_ss_tn_reference_media_v2019'], 37);\n        add_action('asss_product_synced', [$this, 'normalize_product_storefront_sizes'], 45, 2);"
new = "        add_action('admin_init', [$this, 'migrate_ss_tn_reference_media_v2019'], 37);\n        add_action('admin_init', [$this, 'migrate_standard_categories_and_one_size_v2021'], 38);\n        add_action('asss_product_synced', [$this, 'normalize_product_storefront_sizes'], 45, 2);"
imp = replace_once(imp, old, new, 'migration hook')

# Replace size helpers with a single global customer-facing one-size vocabulary.
old = """    /** Customer-facing size label; raw supplier labels remain in source metadata. */
    private function storefront_size_label(string $brand, string $style, string $size): string {
        $size = trim($size);
        if ($size === '') return '';
        $alias = $this->canonical_supplier_size($brand, $style, $size);
        if (in_array($alias, ['one-size','standard-fit'], true)) return 'OSFA';
        return $size;
    }
"""
new = """    private function is_one_size_value(string $size): bool {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($size)));
        return in_array($key, ['os','osfa','onesize','onesizefitsall'], true);
    }

    /**
     * One canonical WooCommerce term is used sitewide for every ordinary one-size
     * synonym. Prefer the existing OSFA term when present so old variation slugs
     * do not churn; rename only its customer-facing label to One Size Fits All.
     */
    private function canonical_one_size_term_id(): int {
        $this->ensure_attribute_taxonomy('pa_size', 'Size');
        $term = get_term_by('slug', 'osfa', 'pa_size');
        if (!$term || is_wp_error($term)) $term = get_term_by('name', 'OSFA', 'pa_size');
        if (!$term || is_wp_error($term)) $term = get_term_by('name', 'One Size Fits All', 'pa_size');
        if ($term && !is_wp_error($term)) {
            if ((string)$term->name !== 'One Size Fits All') {
                $updated = wp_update_term((int)$term->term_id, 'pa_size', ['name'=>'One Size Fits All']);
                if (!is_wp_error($updated)) $term = get_term((int)$term->term_id, 'pa_size');
            }
            return $term && !is_wp_error($term) ? (int)$term->term_id : 0;
        }
        $created = wp_insert_term('One Size Fits All', 'pa_size', ['slug'=>'osfa']);
        if (is_wp_error($created)) return 0;
        return (int)(is_array($created) ? $created['term_id'] : $created);
    }

    /** Customer-facing size label; raw supplier labels remain in source metadata. */
    private function storefront_size_label(string $brand, string $style, string $size): string {
        $size = trim($size);
        if ($size === '') return '';
        $alias = $this->canonical_supplier_size($brand, $style, $size);
        if (in_array($alias, ['one-size','standard-fit'], true)) return 'One Size Fits All';
        return $size;
    }
"""
imp = replace_once(imp, old, new, 'one-size helpers')

# Replace normalization pass so parent options are rebuilt even when the child
# already happens to use the canonical slug. This fixes Any Size... rows.
start = imp.index("    public function normalize_product_storefront_sizes(int $product_id, string $supplier = ''): void {")
end = imp.index("\n    /** Local, one-time cleanup for products imported before 2.0.18. */", start)
new_func = r'''    public function normalize_product_storefront_sizes(int $product_id, string $supplier = ''): void {
        $changed = 0;
        $needs_parent_rebuild = false;
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
            $want_one_size = false;
            foreach ($raw_sizes as $key=>$raw_size) {
                if ($raw_size === '') continue;
                [$brand,$style] = $parents[$key];
                if ($this->storefront_size_label($brand,$style,$raw_size) === 'One Size Fits All') { $want_one_size = true; break; }
            }
            if (!$want_one_size) continue;
            $needs_parent_rebuild = true;
            $attrs = $v->get_attributes();
            $want = $this->term_slug('pa_size','One Size Fits All');
            if (($attrs['pa_size'] ?? '') !== $want) {
                $attrs['pa_size'] = $want;
                $v->set_attributes($attrs);
                $changed++;
            }
            $v->update_meta_data('_asss_storefront_size_label','One Size Fits All');
            $v->save();
        }
        if ($changed || $needs_parent_rebuild) {
            $this->rebuild_attributes_from_variations($product_id);
            WC_Product_Variable::sync($product_id);
            wc_delete_product_transients($product_id);
            ASSS_Logger::log('Normalized equivalent one-size labels to One Size Fits All', 'info', ['product_id'=>$product_id,'variations_changed'=>$changed,'parent_rebuilt'=>$needs_parent_rebuild]);
        }
    }
'''
imp = imp[:start] + new_func + imp[end:]

# Parent size options must be canonical before children are reconciled.
old = """            $z = trim((string)$this->sanmar->first($row, ['SIZE']));
            if ($c !== '') $colors[$c] = true;
            if ($z !== '') $sizes[$z] = true;"""
new = """            $z = trim((string)$this->sanmar->first($row, ['SIZE']));
            if ($z !== '') $z = $this->storefront_size_label('', '', $z);
            if ($c !== '') $colors[$c] = true;
            if ($z !== '') $sizes[$z] = true;"""
imp = replace_once(imp, old, new, 'canonical parent size values')

# Parent taxonomy attributes use the same canonical one-size term as variations.
old = """        foreach ($values as $value) {
            $term_id = $this->ensure_term($taxonomy, (string)$value);
            if ($term_id) $term_ids[] = $term_id;
        }"""
new = """        foreach ($values as $value) {
            $value = (string)$value;
            $term_id = ($taxonomy === 'pa_size' && $this->is_one_size_value($value))
                ? $this->canonical_one_size_term_id()
                : $this->ensure_term($taxonomy, $value);
            if ($term_id) $term_ids[] = $term_id;
        }"""
imp = replace_once(imp, old, new, 'canonical parent term id')

# Child term slug uses the canonical sitewide term.
old = """    private function term_slug(string $taxonomy, string $value): string {
        if ($taxonomy === 'pa_size') {
            $size_key = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)));
            if (in_array($size_key, ['os','osfa','onesize','onesizefitsall'], true)) $value = 'OSFA';
        }
        $term_id = $this->ensure_term($taxonomy, $value);"""
new = """    private function term_slug(string $taxonomy, string $value): string {
        if ($taxonomy === 'pa_size' && $this->is_one_size_value($value)) {
            $term_id = $this->canonical_one_size_term_id();
        } else {
            $term_id = $this->ensure_term($taxonomy, $value);
        }"""
imp = replace_once(imp, old, new, 'canonical child term slug')

# Shared storefront category classifier. Supplier-specific raw labels are signals,
# not Woo category names.
marker = "    /** Normalize one supplier category label into a WooCommerce hierarchy path. */\n"
idx = imp.index(marker)
category_helpers = r'''    private function category_signal_text(int $product_id, array $raw_categories): string {
        $parts = [get_the_title($product_id)];
        $walk = static function($value) use (&$parts, &$walk): void {
            if (is_array($value)) { foreach ($value as $nested) $walk($nested); return; }
            if (!is_scalar($value)) return;
            $value = trim(wp_strip_all_tags(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($value !== '') $parts[] = $value;
        };
        $walk($raw_categories);
        $text = mb_strtolower(implode(' ', $parts));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function category_signal_has(string $text, array $phrases): bool {
        $padded = ' ' . $text . ' ';
        foreach ($phrases as $phrase) {
            $phrase = trim(preg_replace('/\s+/u', ' ', strtolower((string)$phrase)));
            if ($phrase !== '' && strpos($padded, ' ' . $phrase . ' ') !== false) return true;
        }
        return false;
    }

    /**
     * Map noisy supplier taxonomies into a small customer-facing Woo vocabulary.
     * Audience/channel/internal supplier labels (Adult, ASB, divisions, etc.) are
     * intentionally ignored. Manual Woo categories remain separately preserved.
     */
    private function canonical_storefront_categories(int $product_id, array $raw_categories): array {
        $text = $this->category_signal_text($product_id, $raw_categories);
        $out = [];
        $add = static function(string $name) use (&$out): void { $out[mb_strtolower($name)] = $name; };

        $headwear = $this->category_signal_has($text, ['headwear','hat','hats','cap','caps','trucker','snapback','beanie','visor','bucket hat','rope cap','flexfit']);
        if ($headwear) {
            $add('Headwear');
            $add('Hats');
            $add('Caps & Hats');
            if ($this->category_signal_has($text, ['bucket hat','bucket hats','bucket'])) $add('Bucket Hats');
            elseif ($this->category_signal_has($text, ['beanie','beanies','knit cap','knit hat','watch cap','toque'])) $add('Beanies');
            elseif ($this->category_signal_has($text, ['visor','visors'])) $add('Visors');
            elseif ($this->category_signal_has($text, ['cap','caps','trucker','snapback','baseball cap','fitted','flexfit','rope cap','5 panel','6 panel'])) $add('Caps');
            return array_values($out);
        }

        if ($this->category_signal_has($text, ['bag','bags','backpack','backpacks','duffel','duffels','tote','totes'])) {
            $add('Bags');
            if ($this->category_signal_has($text, ['backpack','backpacks'])) $add('Backpacks');
            elseif ($this->category_signal_has($text, ['duffel','duffels'])) $add('Duffel Bags');
            elseif ($this->category_signal_has($text, ['tote','totes'])) $add('Tote Bags');
            return array_values($out);
        }

        $apparel = $this->category_signal_has($text, ['apparel','shirt','shirts','tee','tees','t shirt','t shirts','polo','polos','sweatshirt','sweatshirts','hoodie','hoodies','jacket','jackets','vest','vests','outerwear','pants','shorts','jogger','joggers','jersey','jerseys','uniform','uniforms','workwear']);
        if ($apparel) {
            $add('Apparel');
            if ($this->category_signal_has($text, ['polo','polos'])) $add('Polos');
            elseif ($this->category_signal_has($text, ['sweatshirt','sweatshirts','hoodie','hoodies'])) $add('Sweatshirts & Hoodies');
            elseif ($this->category_signal_has($text, ['jacket','jackets','vest','vests','outerwear'])) $add('Outerwear');
            elseif ($this->category_signal_has($text, ['pants','shorts','jogger','joggers'])) $add('Bottoms');
            elseif ($this->category_signal_has($text, ['jersey','jerseys','uniform','uniforms'])) $add('Uniforms');
            elseif ($this->category_signal_has($text, ['shirt','shirts','tee','tees','t shirt','t shirts'])) $add('Shirts & Tees');
            if ($this->category_signal_has($text, ['workwear'])) $add('Workwear');
            return array_values($out);
        }

        if ($this->category_signal_has($text, ['blanket','blankets'])) return ['Accessories','Blankets'];
        if ($this->category_signal_has($text, ['towel','towels'])) return ['Accessories','Towels'];
        if ($this->category_signal_has($text, ['apron','aprons'])) return ['Accessories','Aprons'];
        if ($this->category_signal_has($text, ['accessory','accessories','scarf','scarves','glove','gloves','sock','socks','belt','belts'])) return ['Accessories'];

        // Unknown supplier departments do not become dozens of raw storefront
        // categories. A single controlled fallback is preferable to channel codes.
        return ['Other Products'];
    }

'''
imp = imp[:idx] + category_helpers + imp[idx:]

# Canonicalize every supplier before Woo category creation and retain raw labels
# only as internal troubleshooting metadata.
old = """    private function sync_supplier_categories(int $product_id, array $categories, string $supplier, bool $is_new = false): void {
        $supplier = sanitize_key($supplier) ?: 'supplier';
        $paths = $this->normalize_supplier_category_paths($categories);
        if (!$paths) return; // Never erase known categories because of an empty/partial supplier payload.
"""
new = """    private function sync_supplier_categories(int $product_id, array $categories, string $supplier, bool $is_new = false): void {
        $supplier = sanitize_key($supplier) ?: 'supplier';
        update_post_meta($product_id, '_asss_supplier_raw_categories_' . $supplier, wp_json_encode(array_values($categories)));
        $categories = $this->canonical_storefront_categories($product_id, $categories);
        $paths = $this->normalize_supplier_category_paths($categories);
        if (!$paths) return;
"""
imp = replace_once(imp, old, new, 'shared category canonicalization')

# Conservative cleanup for stale supplier-created taxonomy terms after they have
# been removed from products. Only unassigned leaf terms are deleted.
old = """        update_post_meta($product_id, '_asss_supplier_category_ids_' . $supplier, wp_json_encode(array_values($new_ids)));
        update_post_meta($product_id, '_asss_supplier_categories_' . $supplier, wp_json_encode(array_values($path_labels)));
"""
new = """        update_post_meta($product_id, '_asss_supplier_category_ids_' . $supplier, wp_json_encode(array_values($new_ids)));
        update_post_meta($product_id, '_asss_supplier_categories_' . $supplier, wp_json_encode(array_values($path_labels)));
        $this->prune_orphaned_supplier_categories(array_values(array_diff($previous, $new_ids)));
"""
imp = replace_once(imp, old, new, 'category prune call')

marker = "    private function sync_taxonomies(int $product_id, string $brand, array $categories, string $keywords, bool $is_new, string $supplier = 'sanmar'): void {\n"
idx = imp.index(marker)
pruner = r'''    private function prune_orphaned_supplier_categories(array $term_ids): void {
        $pending = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
        $default = (int)get_option('default_product_cat', 0);
        for ($pass=0; $pass<4 && $pending; $pass++) {
            $next = [];
            foreach ($pending as $term_id) {
                if (!$term_id || $term_id === $default) continue;
                clean_term_cache($term_id, 'product_cat');
                $term = get_term($term_id, 'product_cat');
                if (!$term || is_wp_error($term) || (int)$term->count > 0) continue;
                $children = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$term_id,'fields'=>'ids','number'=>1]);
                if (is_wp_error($children) || !empty($children)) { $next[] = $term_id; continue; }
                wp_delete_term($term_id, 'product_cat');
            }
            if (count($next) === count($pending)) break;
            $pending = $next;
        }
    }

'''
imp = imp[:idx] + pruner + imp[idx:]

# Global v2.0.21 migration: canonicalize one-size across ALL variable products,
# then re-run supplier category ownership using cached data for linked products.
marker = "    /** Local, one-time cleanup for products imported before 2.0.18. */\n"
idx = imp.index(marker)
migration = r'''    /**
     * v2.0.21: standardize one-size naming across the whole Woo catalog and
     * replace noisy supplier categories with the shared storefront vocabulary.
     */
    public function migrate_standard_categories_and_one_size_v2021(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2021_taxonomy_size_migrated','') === 'yes') return;

        $canonical_id = $this->canonical_one_size_term_id();
        $canonical = $canonical_id ? get_term($canonical_id, 'pa_size') : null;
        $canonical_slug = $canonical && !is_wp_error($canonical) ? (string)$canonical->slug : 'osfa';
        $legacy_ids = [];
        $legacy_slugs = [];
        $terms = get_terms(['taxonomy'=>'pa_size','hide_empty'=>false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$term->name));
                $slug_key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$term->slug));
                if (in_array($key,['os','osfa','onesize','onesizefitsall'],true) || in_array($slug_key,['os','osfa','onesize','onesizefitsall'],true)) {
                    $legacy_ids[(int)$term->term_id] = true;
                    $legacy_slugs[(string)$term->slug] = true;
                }
            }
        }
        $legacy_slugs[$canonical_slug] = true;

        $variable_ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'tax_query'=>[['taxonomy'=>'product_type','field'=>'slug','terms'=>['variable']]],
        ]);
        $variation_updates = 0;
        foreach ((array)$variable_ids as $product_id) {
            $product_id = (int)$product_id;
            foreach ($this->variation_ids_direct($product_id) as $variation_id) {
                $v = wc_get_product($variation_id);
                if (!$v instanceof WC_Product_Variation) continue;
                $attrs = $v->get_attributes();
                $current = (string)($attrs['pa_size'] ?? '');
                if ($current !== '' && isset($legacy_slugs[$current]) && $current !== $canonical_slug) {
                    $attrs['pa_size'] = $canonical_slug;
                    $v->set_attributes($attrs);
                    $v->update_meta_data('_asss_storefront_size_label','One Size Fits All');
                    $v->save();
                    $variation_updates++;
                }
            }

            $product = wc_get_product($product_id);
            if ($product instanceof WC_Product_Variable) {
                $attrs = $product->get_attributes('edit');
                $parent_changed = false;
                foreach ($attrs as $attribute) {
                    if (!$attribute instanceof WC_Product_Attribute || (string)$attribute->get_name() !== 'pa_size') continue;
                    $options = array_map('intval', (array)$attribute->get_options());
                    $new_options = [];
                    $had_one_size = false;
                    foreach ($options as $term_id) {
                        if (isset($legacy_ids[$term_id])) { $had_one_size = true; continue; }
                        if ($term_id) $new_options[] = $term_id;
                    }
                    if ($had_one_size && $canonical_id) $new_options[] = $canonical_id;
                    $new_options = array_values(array_unique(array_filter(array_map('intval',$new_options))));
                    if ($new_options !== $options) { $attribute->set_options($new_options); $parent_changed = true; }
                }
                $defaults = $product->get_default_attributes('edit');
                if (!empty($defaults['pa_size']) && isset($legacy_slugs[(string)$defaults['pa_size']]) && (string)$defaults['pa_size'] !== $canonical_slug) {
                    $defaults['pa_size'] = $canonical_slug;
                    $product->set_default_attributes($defaults);
                    $parent_changed = true;
                }
                if ($parent_changed) { $product->set_attributes(array_values($attrs)); $product->save(); }
            }
        }

        $supplier_ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_key'=>'_asss_sync_enabled','meta_value'=>'yes',
        ]);
        foreach ((array)$supplier_ids as $product_id) {
            $product_id = (int)$product_id;
            $this->normalize_product_storefront_sizes($product_id, 'v2021-migration');
            $sources = $this->multi->product_sources($product_id);
            if (!empty($sources['sanmar']['enabled'])) {
                $brand = trim((string)get_post_meta($product_id,'_asss_sanmar_brand',true));
                $style = trim((string)get_post_meta($product_id,'_asss_sanmar_style',true));
                if ($brand !== '' && $style !== '') {
                    $data = $this->sanmar->rows_for_style($brand,$style);
                    if (!is_wp_error($data) && !empty($data['rows'])) $this->sync_supplier_categories($product_id,$this->categories_from_rows((array)$data['rows']),'sanmar',false);
                }
            }
            if (!empty($sources['ss']['enabled'])) {
                $brand_id = absint(get_post_meta($product_id,'_asss_ss_brand_id',true));
                $style_id = absint(get_post_meta($product_id,'_asss_ss_style_id',true));
                if ($brand_id && $style_id) {
                    $data = $this->ss->style_product($brand_id,$style_id);
                    if (!is_wp_error($data)) $this->sync_supplier_categories($product_id,$this->categories_from_normalized_product((array)$data),'ss',false);
                }
            }
            if (!empty($sources['momentec']['enabled'])) {
                $style = trim((string)get_post_meta($product_id,'_asss_momentec_style',true));
                if ($style !== '') {
                    $data = $this->momentec->style_product($style);
                    if (!is_wp_error($data)) $this->sync_supplier_categories($product_id,$this->categories_from_normalized_product((array)$data),'momentec',false);
                }
            }
        }

        update_option('asss_v2021_taxonomy_size_migrated','yes',false);
        ASSS_Logger::log('v2.0.21 category and One Size Fits All migration completed','info',[
            'variable_products'=>count((array)$variable_ids),
            'supplier_products'=>count((array)$supplier_ids),
            'variation_size_updates'=>$variation_updates,
        ]);
    }

'''
imp = imp[:idx] + migration + imp[idx:]

# Momentec ASBO helper displays the canonical size wording too.
old = """        foreach($variants as $row){$size=trim((string)($row['size'] ?? ''));if($size!=='')$sizes[$size]=true;}"""
new = """        foreach($variants as $row){$size=trim((string)($row['size'] ?? ''));if($size!==''){$size=$this->storefront_size_label($brand,$style,$size);$sizes[$size]=true;}}"""
imp = replace_once(imp, old, new, 'momentec size chart label')

write('includes/class-asss-importer.php', imp)

# Bump plugin version.
main = read('all-star-supplier-sync.php')
main = replace_once(main, ' * Version: 2.0.20', ' * Version: 2.0.21', 'plugin header')
main = replace_once(main, "define('ASSS_VERSION', '2.0.20');", "define('ASSS_VERSION', '2.0.21');", 'version constant')
write('all-star-supplier-sync.php', main)

# Readme/changelog.
readme = read('readme.txt')
readme = replace_once(readme, 'Stable tag: 2.0.20', 'Stable tag: 2.0.21', 'stable tag')
readme = replace_once(readme,
    '* Every reliable supplier category is added without erasing merchant categories, improving WooCommerce and ASBO/bulk-order sorting.',
    '* Supplier categories are mapped into a controlled storefront taxonomy without erasing merchant categories; raw supplier department/channel labels are retained only as internal metadata.',
    'category description')
changelog = """= 2.0.21 =
* Standardized supplier categories across SanMar, S&S Activewear, and Momentec using one controlled WooCommerce storefront vocabulary instead of copying raw supplier department/channel strings into Product Categories.
* Headwear products now consistently receive Headwear, Hats, and Caps & Hats plus a useful subtype such as Caps, Bucket Hats, Beanies, or Visors when detected. Momentec labels such as Adult | HEADWEAR | HEADWEAR ASB / BUCKET HAT no longer become literal Woo categories.
* Raw supplier category values are retained in supplier-specific internal metadata for troubleshooting while stale unassigned supplier-created category terms are conservatively pruned.
* Standardized OS, OSFA, One Size, and One Size Fits All to the customer-facing label One Size Fits All across the whole WooCommerce variable-product catalog. Verified style-specific one-size aliases such as Richardson 112 M/L also display as One Size Fits All on supplier-linked products.
* Fixed the parent/child size mismatch that could turn Momentec OS variations into WooCommerce Any Size rows. Parent Size options and variation attributes now use the same canonical term from initial import onward.
* Added a one-time migration that repairs existing variable products, parent Size options, variation Size attributes, defaults, supplier categories, and supplier-linked one-size aliases without changing raw supplier size metadata.

"""
readme = replace_once(readme, '== Changelog ==\n\n', '== Changelog ==\n\n' + changelog, 'changelog insertion')
write('readme.txt', readme)

print('PATCH_V2021=SUCCESS')
