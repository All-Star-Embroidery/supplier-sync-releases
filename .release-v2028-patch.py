#!/usr/bin/env python3
from pathlib import Path

importer = Path('includes/class-asss-importer.php')
s = importer.read_text(encoding='utf-8')

hook = "        add_action('admin_init', [$this, 'migrate_supplier_markup_v2027'], 44);\n"
if hook not in s:
    raise SystemExit('Could not find v2.0.27 migration hook')
s = s.replace(
    hook,
    hook + "        add_action('admin_init', [$this, 'migrate_specific_storefront_titles_v2028'], 45);\n",
    1,
)

start_marker = '''    /**
     * Canonical storefront titles for high-value/core products. Supplier titles
     * remain in supplier data, while Woo gets a stable customer-facing name.
     */
'''
end_marker = '''    /** Flatten only useful supplier discovery fields into searchable text. */
'''
start = s.find(start_marker)
end = s.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('Could not locate existing canonical title block')

title_block = r'''    /**
     * Build a customer-facing supplier title only when the supplier's own title
     * is missing useful identity. Already brand-specific names stay untouched.
     */
    private function compose_supplier_storefront_title(string $brand, string $style, string $supplier_title): string {
        $brand = trim((string)preg_replace('/\s+/u', ' ', $brand));
        $style = trim((string)preg_replace('/\s+/u', ' ', $style));
        $supplier_title = trim((string)preg_replace('/\s+/u', ' ', $supplier_title));
        $brand_key = strtolower($brand);
        $generic_brands = ['s&s activewear','ss activewear','sanmar','momentec brands'];
        $useful_brand = in_array($brand_key, $generic_brands, true) ? '' : $brand;
        if ($supplier_title === '') {
            return sanitize_text_field(trim(implode(' ', array_filter([$useful_brand, $style]))));
        }
        $title_key = strtolower($supplier_title);
        if ($useful_brand !== '' && str_contains($title_key, strtolower($useful_brand))) {
            return sanitize_text_field($supplier_title);
        }
        $identity = [];
        if ($useful_brand !== '') $identity[] = $useful_brand;
        if ($style !== '' && !str_contains($title_key, strtolower($style))) $identity[] = $style;
        $prefix = trim(implode(' ', $identity));
        if ($prefix === '' || strtolower($prefix) === $title_key) return sanitize_text_field($supplier_title);
        return sanitize_text_field($prefix . ' – ' . $supplier_title);
    }

    /**
     * Request-local source title contexts for Supplier Sync-owned S&S/Momentec
     * products. SanMar is intentionally excluded from the generic title rule.
     */
    private function supplier_title_contexts_for(int $product_id): array {
        static $cache = [];
        if (array_key_exists($product_id, $cache)) return $cache[$product_id];
        $contexts = [];

        $ss_brand = trim((string)get_post_meta($product_id, '_asss_ss_brand', true));
        $ss_style = trim((string)get_post_meta($product_id, '_asss_ss_style', true));
        $ss_brand_id = absint(get_post_meta($product_id, '_asss_ss_brand_id', true));
        $ss_style_id = absint(get_post_meta($product_id, '_asss_ss_style_id', true));
        if ($ss_style !== '' && $ss_brand_id && $ss_style_id) {
            $data = $this->ss->style_product($ss_brand_id, $ss_style_id);
            if (!is_wp_error($data) && is_array($data)) {
                $brand = trim((string)($data['brand'] ?? $ss_brand));
                $style = trim((string)($data['style'] ?? $ss_style));
                $raw = trim((string)($data['supplier_title'] ?? $data['title'] ?? ''));
                if ($raw !== '' || $brand !== '' || $style !== '') {
                    $contexts[] = ['supplier'=>'ss','brand'=>$brand,'style'=>$style,'raw'=>$raw];
                }
            }
        }

        $mom_style = trim((string)get_post_meta($product_id, '_asss_momentec_style', true));
        if ($mom_style !== '') {
            $data = $this->momentec->style_product($mom_style);
            if (!is_wp_error($data) && is_array($data)) {
                $brand = trim((string)($data['brand'] ?? get_post_meta($product_id, '_asss_momentec_brand', true)));
                $style = trim((string)($data['style'] ?? $mom_style));
                $raw = trim((string)($data['supplier_title'] ?? $data['title'] ?? ''));
                if ($raw !== '' || $brand !== '' || $style !== '') {
                    $contexts[] = ['supplier'=>'momentec','brand'=>$brand,'style'=>$style,'raw'=>$raw];
                }
            }
        }

        return $cache[$product_id] = $contexts;
    }

    /**
     * Canonical storefront titles for high-value/core products plus the shared
     * generic-title rule for S&S and Momentec. SanMar keeps its existing title
     * unless an explicit canonical rule applies.
     */
    private function canonical_product_title_for(int $product_id): string {
        $sources = [
            [trim((string)get_post_meta($product_id,'_asss_sanmar_brand',true)), trim((string)get_post_meta($product_id,'_asss_sanmar_style',true))],
            [trim((string)get_post_meta($product_id,'_asss_ss_brand',true)), trim((string)get_post_meta($product_id,'_asss_ss_style',true))],
            [trim((string)get_post_meta($product_id,'_asss_momentec_brand',true)), trim((string)get_post_meta($product_id,'_asss_momentec_style',true))],
        ];
        $rules = ['richardson|112' => 'Richardson 112 – Snapback Trucker Cap'];
        $rules = apply_filters('asss_canonical_product_titles', $rules, $product_id);
        if (!is_array($rules)) return '';
        foreach ($sources as [$brand,$style]) {
            if ($brand === '' || $style === '') continue;
            $brand_key = strtolower(preg_replace('/[^a-z0-9]+/i','', $brand));
            $style_key = strtolower(preg_replace('/[^a-z0-9]+/i','', $style));
            if (str_contains($brand_key, 'richardson') && $style_key === '112') {
                return sanitize_text_field((string)($rules['richardson|112'] ?? ''));
            }
            $exact_key = $brand_key . '|' . $style_key;
            if (!empty($rules[$exact_key])) return sanitize_text_field((string)$rules[$exact_key]);
        }
        foreach ($this->supplier_title_contexts_for($product_id) as $context) {
            $wanted = $this->compose_supplier_storefront_title(
                (string)($context['brand'] ?? ''),
                (string)($context['style'] ?? ''),
                (string)($context['raw'] ?? '')
            );
            if ($wanted !== '') return $wanted;
        }
        return '';
    }

    /**
     * Apply Supplier Sync-owned titles without overwriting a merchant edit.
     * Raw supplier names, previously managed names, and the prior Richardson
     * canonical title are safe to migrate; unrelated custom titles are preserved.
     */
    public function normalize_canonical_product_title(int $product_id, string $supplier = ''): void {
        if ($product_id < 1) return;
        $wanted = $this->canonical_product_title_for($product_id);
        if ($wanted === '') return;
        $product = wc_get_product($product_id);
        if (!$product) return;

        $current = trim((string)$product->get_name('edit'));
        if ($current === $wanted) {
            if ((string)get_post_meta($product_id, '_asss_managed_title', true) !== $wanted) update_post_meta($product_id, '_asss_managed_title', $wanted);
            if ((string)get_post_meta($product_id, '_asss_canonical_title', true) !== $wanted) update_post_meta($product_id, '_asss_canonical_title', $wanted);
            return;
        }

        $managed = trim((string)get_post_meta($product_id, '_asss_managed_title', true));
        $legacy = trim((string)get_post_meta($product_id, '_asss_canonical_title', true));
        $owned = $current === ''
            || ($managed !== '' && $current === $managed)
            || ($legacy !== '' && $current === $legacy)
            || $current === 'Snapback Trucker Cap (Richardson 112)';

        if (!$owned) {
            foreach ($this->supplier_title_contexts_for($product_id) as $context) {
                $raw = trim((string)($context['raw'] ?? ''));
                $composed = $this->compose_supplier_storefront_title(
                    (string)($context['brand'] ?? ''),
                    (string)($context['style'] ?? ''),
                    $raw
                );
                if (($raw !== '' && $current === $raw) || ($composed !== '' && $current === $composed)) {
                    $owned = true;
                    break;
                }
            }
        }

        if (!$owned) {
            ASSS_Logger::log('Preserved merchant-owned product title','info',[
                'product_id'=>$product_id,'supplier'=>$supplier,'current'=>$current,'proposed'=>$wanted,
            ]);
            return;
        }

        $before = $current;
        $product->set_name($wanted);
        $product->update_meta_data('_asss_managed_title', $wanted);
        $product->update_meta_data('_asss_canonical_title', $wanted);
        $product->save();
        ASSS_Logger::log('Applied managed storefront product title','info',[
            'product_id'=>$product_id,'supplier'=>$supplier,'before'=>$before,'after'=>$wanted,
        ]);
    }

'''
s = s[:start] + title_block + s[end:]

pricing_marker = "    private function ss_variation_price(int $product_id, array $row): array {\n"
if pricing_marker not in s:
    raise SystemExit('Could not find pricing method insertion marker')
migration = r'''    /** v2.0.28: repair supplier-generic storefront titles without overwriting merchant edits. */
    public function migrate_specific_storefront_titles_v2028(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2028_specific_titles_migrated','') === 'yes') return;
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_query'=>['relation'=>'OR',
                ['key'=>'_asss_sanmar_style','compare'=>'EXISTS'],
                ['key'=>'_asss_ss_style','compare'=>'EXISTS'],
                ['key'=>'_asss_momentec_style','compare'=>'EXISTS'],
            ],
        ]);
        $changed = 0; $processed = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            $product = wc_get_product($product_id);
            if (!$product) continue;
            $before = (string)$product->get_name('edit');
            $this->normalize_canonical_product_title($product_id, 'v2.0.28-migration');
            $after_product = wc_get_product($product_id);
            $after = $after_product ? (string)$after_product->get_name('edit') : $before;
            if ($after !== $before) $changed++;
            $processed++;
        }
        update_option('asss_v2028_specific_titles_migrated','yes',false);
        ASSS_Logger::log('v2.0.28 specific storefront title migration complete','info',[
            'products'=>$processed,'titles_changed'=>$changed,
        ]);
    }

'''
s = s.replace(pricing_marker, migration + pricing_marker, 1)
importer.write_text(s, encoding='utf-8')

main = Path('all-star-supplier-sync.php')
m = main.read_text(encoding='utf-8')
if 'Version: 2.0.27' not in m or "define('ASSS_VERSION', '2.0.27');" not in m:
    raise SystemExit('Expected v2.0.27 plugin markers not found')
m = m.replace('Version: 2.0.27', 'Version: 2.0.28', 1)
m = m.replace("define('ASSS_VERSION', '2.0.27');", "define('ASSS_VERSION', '2.0.28');", 1)
main.write_text(m, encoding='utf-8')

readme = Path('readme.txt')
r = readme.read_text(encoding='utf-8')
if 'Stable tag: 2.0.27' not in r:
    raise SystemExit('Expected v2.0.27 stable tag not found')
r = r.replace('Stable tag: 2.0.27', 'Stable tag: 2.0.28', 1)
changelog = '''= 2.0.28 =
* Makes generic S&S Activewear and Momentec storefront titles specific by adding missing brand/style identity.
* Preserves supplier titles that already contain a useful brand name, such as Delta or Comfort Colors product names.
* Changes the Richardson 112 canonical title to `Richardson 112 – Snapback Trucker Cap` so the exact model is immediately visible.
* Adds a one-time title-only migration for existing Supplier Sync products while preserving merchant-edited/custom titles.
* Raw supplier title wording is retained by the GitHub normalizers; pricing, inventory, variations, media, taxonomy, and ASBO behavior are unchanged.

'''
anchor = '== Changelog ==\n\n'
if anchor not in r:
    raise SystemExit('Could not find readme changelog anchor')
r = r.replace(anchor, anchor + changelog, 1)
readme.write_text(r, encoding='utf-8')
