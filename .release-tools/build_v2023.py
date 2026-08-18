#!/usr/bin/env python3
from pathlib import Path

root = Path('source')
imp = root / 'includes/class-asss-importer.php'
s = imp.read_text()

# Hooks: one-time repair + future sync normalization.
old = "        add_action('admin_init', [$this, 'migrate_momentec_shipping_weights_v2022'], 39);\n"
new = old + "        add_action('admin_init', [$this, 'migrate_discovery_taxonomy_and_titles_v2023'], 40);\n"
assert old in s
s = s.replace(old, new, 1)

old = "        add_action('asss_product_synced', [$this, 'normalize_product_storefront_sizes'], 45, 2);\n"
new = (
    "        add_action('asss_product_synced', [$this, 'sync_product_discovery_taxonomy'], 40, 2);\n"
    "        add_action('asss_product_synced', [$this, 'normalize_canonical_product_title'], 42, 2);\n"
    + old
)
assert old in s
s = s.replace(old, new, 1)

marker = "    /**\n     * Canonicalize only equivalent one-size labels in WooCommerce. Supplier raw\n"
block = r'''    /**
     * Canonical storefront titles for high-value/core products. Supplier titles
     * remain in supplier data, while Woo gets a stable customer-facing name.
     */
    private function canonical_product_title_for(int $product_id): string {
        $sources = [
            [trim((string)get_post_meta($product_id,'_asss_sanmar_brand',true)), trim((string)get_post_meta($product_id,'_asss_sanmar_style',true))],
            [trim((string)get_post_meta($product_id,'_asss_ss_brand',true)), trim((string)get_post_meta($product_id,'_asss_ss_style',true))],
            [trim((string)get_post_meta($product_id,'_asss_momentec_brand',true)), trim((string)get_post_meta($product_id,'_asss_momentec_style',true))],
        ];
        $rules = ['richardson|112' => 'Snapback Trucker Cap (Richardson 112)'];
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
        return '';
    }

    public function normalize_canonical_product_title(int $product_id, string $supplier = ''): void {
        if ($product_id < 1) return;
        $wanted = $this->canonical_product_title_for($product_id);
        if ($wanted === '') return;
        $product = wc_get_product($product_id);
        if (!$product || (string)$product->get_name('edit') === $wanted) return;
        $before = (string)$product->get_name('edit');
        $product->set_name($wanted);
        $product->update_meta_data('_asss_canonical_title', $wanted);
        $product->save();
        ASSS_Logger::log('Applied canonical storefront product title','info',[
            'product_id'=>$product_id,'supplier'=>$supplier,'before'=>$before,'after'=>$wanted,
        ]);
    }

    /** Flatten only useful supplier discovery fields into searchable text. */
    private function discovery_scalar_text($value, int $depth = 0): string {
        if ($depth > 4 || $value === null || is_bool($value)) return '';
        if (is_scalar($value)) return trim(wp_strip_all_tags((string)$value));
        if (!is_array($value)) return '';
        $parts = [];
        foreach ($value as $k=>$v) {
            if (is_string($k)) $parts[] = sanitize_text_field($k);
            $part = $this->discovery_scalar_text($v, $depth + 1);
            if ($part !== '') $parts[] = $part;
        }
        return implode(' ', $parts);
    }

    /** Controlled customer-facing tags. Do not emit supplier codes, colors, sizes, or arbitrary prose. */
    private function discovery_tags_from_text(string $text, array $categories = []): array {
        $text = strtolower(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text .= ' ' . strtolower(implode(' ', array_map('strval', $categories)));
        $tags = [];
        $add = static function(string $tag) use (&$tags): void { if ($tag !== '') $tags[$tag] = true; };
        $has = static fn(string $pattern): bool => (bool)preg_match($pattern, $text);

        // Headwear style / construction.
        if ($has('/\btrucker\b/u')) $add('Trucker Hat');
        if ($has('/\bsnap[ -]?back\b/u')) $add('Snapback');
        if ($has('/\bdad (?:cap|hat)\b/u')) $add('Dad Cap');
        if ($has('/\bbucket (?:cap|hat)|\bbucket hats?\b/u')) $add('Bucket Hat');
        if ($has('/\bbeanie\b|\bknit (?:cap|hat)\b/u')) $add('Beanie');
        if ($has('/\bvisor\b/u')) $add('Visor');
        if ($has('/\brope (?:cap|hat)|\brope detail\b/u')) $add('Rope Cap');
        if ($has('/\b5[ -]?panel\b|\bfive[ -]?panel\b/u')) $add('5-Panel');
        if ($has('/\b6[ -]?panel\b|\bsix[ -]?panel\b/u')) $add('6-Panel');
        if ($has('/\bunstructured\b/u')) $add('Unstructured');
        elseif ($has('/\bstructured\b/u')) $add('Structured');
        if ($has('/\blow[ -]?profile\b/u')) $add('Low Profile');
        if ($has('/\bmid[ -]?profile\b|\bmid pro\b/u')) $add('Mid Profile');
        if ($has('/\bhigh[ -]?profile\b/u')) $add('High Profile');
        if ($has('/\bmesh back\b|\bmeshback\b|\btrucker\b/u')) $add('Mesh Back');
        if ($has('/\bflat (?:bill|visor)\b/u')) $add('Flat Bill');
        if ($has('/\bpre[ -]?curved\b|\bcurved (?:bill|visor)\b/u')) $add('Curved Bill');
        if ($has('/\badjustable\b|\bsnapback\b|\bhook[ -]?(?:and|&) ?loop\b|\bstrapback\b|\bbuckle closure\b/u')) $add('Adjustable');
        if ($has('/\bstretch fit\b|\bstretchfit\b|\bflexfit\b/u')) $add('Stretch Fit');
        elseif ($has('/\bfitted\b/u')) $add('Fitted');

        // Materials.
        $materials = [
            'Cotton'=>'/\bcotton\b/u','Polyester'=>'/\bpolyester\b/u','Nylon'=>'/\bnylon\b/u',
            'Acrylic'=>'/\bacrylic\b/u','Wool'=>'/\bwool\b/u','Spandex'=>'/\bspandex\b|\belastane\b/u',
            'Twill'=>'/\btwill\b/u','Canvas'=>'/\bcanvas\b/u','Fleece'=>'/\bfleece\b/u',
        ];
        foreach ($materials as $tag=>$pattern) if ($has($pattern)) $add($tag);

        // Performance / features.
        if ($has('/\bperformance\b/u')) $add('Performance');
        if ($has('/\bmoisture[ -]?(?:wicking|management)\b|\bwicks? moisture\b/u')) $add('Moisture Wicking');
        if ($has('/\bbreathable\b|\bventilation\b/u')) $add('Breathable');
        if ($has('/\bquick[ -]?dry\b|\bfast[ -]?dry\b/u')) $add('Quick Dry');
        if ($has('/\bwater[ -]?(?:resistant|repellent)\b|\bdwr\b/u')) $add('Water Resistant');
        if ($has('/\bupf\s*\d+\b|\buv protection\b|\bsun protection\b/u')) $add('UV Protection');
        if ($has('/\breflective\b/u')) $add('Reflective');
        if ($has('/\brecycled\b|\bsustainable\b|\beco[ -]?friendly\b/u')) $add('Sustainable');
        if ($has('/\borganic\b/u')) $add('Organic');

        // Audience.
        if ($has('/\byouth\b|\bjunior\b/u')) $add('Youth');
        if ($has('/\btoddler\b/u')) $add('Toddler');
        if ($has('/\binfant\b|\bbaby\b/u')) $add('Infant');
        if ($has('/\bwomen(?:s|\x27s)?\b|\bladies\b/u')) $add("Women's");
        if ($has('/\bmen(?:s|\x27s)?\b/u')) $add("Men's");
        if ($has('/\bunisex\b/u')) $add('Unisex');

        // Apparel / bags / use cases.
        $types = [
            'T-Shirt'=>'/\bt[ -]?shirt\b|\btee\b/u','Polo'=>'/\bpolo\b/u','Hoodie'=>'/\bhoodie\b|\bhooded sweatshirt\b/u',
            'Sweatshirt'=>'/\bsweatshirt\b|\bcrewneck fleece\b/u','Jacket'=>'/\bjacket\b/u','Vest'=>'/\bvest\b/u',
            'Quarter-Zip'=>'/\bquarter[ -]?zip\b|\b1\/4[ -]?zip\b/u','Jersey'=>'/\bjersey\b/u','Uniform'=>'/\buniform\b/u',
            'Shorts'=>'/\bshorts\b/u','Pants'=>'/\bpants\b|\btrouser/u','Backpack'=>'/\bbackpack\b/u',
            'Duffel Bag'=>'/\bduffel\b/u','Tote Bag'=>'/\btote\b/u','Workwear'=>'/\bworkwear\b|\bindustrial\b/u',
            'Teamwear'=>'/\bteamwear\b|\bteam uniform\b|\bathletic uniform\b/u',
        ];
        foreach ($types as $tag=>$pattern) if ($has($pattern)) $add($tag);

        $out = array_keys($tags);
        natcasesort($out);
        return array_values($out);
    }

    /** Add controlled fallback categories when supplier categorization is thin or oddly named. */
    private function discovery_categories_from_title(string $title): array {
        $t = strtolower(wp_strip_all_tags($title));
        $out = [];
        $add = static function(string $name) use (&$out): void { $out[$name] = true; };
        if (preg_match('/\b(cap|hat|headwear|trucker|snapback|beanie|visor)\b/u', $t)) {
            foreach (['Headwear','Hats','Caps & Hats'] as $name) $add($name);
            if (str_contains($t,'bucket')) $add('Bucket Hats');
            elseif (preg_match('/\bbeanie\b|\bknit\b/u',$t)) $add('Beanies');
            elseif (str_contains($t,'visor')) $add('Visors');
            else $add('Caps');
        }
        if (preg_match('/\b(t[ -]?shirt|tee|shirt|polo|hoodie|sweatshirt|jacket|vest|jersey|shorts|pants|uniform|workwear)\b/u',$t)) {
            $add('Apparel');
            if (preg_match('/\bt[ -]?shirt\b|\btee\b|\bshirt\b/u',$t)) $add('Shirts & Tees');
            if (str_contains($t,'polo')) $add('Polos');
            if (preg_match('/\bhoodie\b|\bsweatshirt\b/u',$t)) $add('Sweatshirts & Hoodies');
            if (preg_match('/\bjacket\b|\bvest\b/u',$t)) $add('Outerwear');
            if (preg_match('/\bshorts\b|\bpants\b/u',$t)) $add('Bottoms');
            if (preg_match('/\bjersey\b|\buniform\b/u',$t)) $add('Uniforms');
            if (str_contains($t,'workwear')) $add('Workwear');
        }
        if (preg_match('/\b(bag|backpack|duffel|tote)\b/u',$t)) {
            $add('Bags');
            if (str_contains($t,'backpack')) $add('Backpacks');
            if (str_contains($t,'duffel')) $add('Duffel Bags');
            if (str_contains($t,'tote')) $add('Tote Bags');
        }
        return array_keys($out);
    }

    private function discovery_tag_meta_key(string $supplier): string {
        return '_asss_' . sanitize_key($supplier) . '_discovery_tags';
    }

    /** Per-supplier tag ownership preserves manual tags and tags from other suppliers. */
    private function sync_supplier_discovery_tags(int $product_id, array $new_tags, string $supplier): void {
        $supplier = sanitize_key($supplier);
        if (!in_array($supplier,['sanmar','ss','momentec'],true)) return;
        $new_tags = array_values(array_unique(array_filter(array_map(static fn($v)=>trim(sanitize_text_field((string)$v)), $new_tags))));
        natcasesort($new_tags); $new_tags = array_values($new_tags);

        $current = wp_get_object_terms($product_id,'product_tag',['fields'=>'names']);
        if (is_wp_error($current)) $current=[];
        $current = array_map('strval',$current);

        $owned_before = [];
        $legacy = json_decode((string)get_post_meta($product_id,'_asss_supplier_tags',true),true);
        if (is_array($legacy)) $owned_before = array_merge($owned_before,array_map('strval',$legacy));
        foreach (['sanmar','ss','momentec'] as $src) {
            $stored=json_decode((string)get_post_meta($product_id,$this->discovery_tag_meta_key($src),true),true);
            if (is_array($stored)) $owned_before=array_merge($owned_before,array_map('strval',$stored));
        }
        $manual = array_values(array_diff($current,array_values(array_unique($owned_before))));
        update_post_meta($product_id,$this->discovery_tag_meta_key($supplier),wp_json_encode($new_tags));

        $supplier_tags=[];
        foreach (['sanmar','ss','momentec'] as $src) {
            $stored=json_decode((string)get_post_meta($product_id,$this->discovery_tag_meta_key($src),true),true);
            if (is_array($stored)) $supplier_tags=array_merge($supplier_tags,array_map('strval',$stored));
        }
        $supplier_tags=array_values(array_unique(array_filter($supplier_tags)));
        $merged=array_values(array_unique(array_merge($manual,$supplier_tags)));
        natcasesort($merged);
        wp_set_object_terms($product_id,array_values($merged),'product_tag',false);
        update_post_meta($product_id,'_asss_supplier_tags',wp_json_encode($supplier_tags));
    }

    /** Rebuild controlled categories + filter tags from every currently linked supplier. */
    public function sync_product_discovery_taxonomy(int $product_id, string $trigger_supplier = ''): void {
        if ($product_id < 1) return;

        $san_brand=trim((string)get_post_meta($product_id,'_asss_sanmar_brand',true));
        $san_style=trim((string)get_post_meta($product_id,'_asss_sanmar_style',true));
        if ($san_brand!=='' && $san_style!=='') {
            $rows=$this->sanmar->rows_for_style($san_brand,$san_style);
            if (!is_wp_error($rows) && is_array($rows) && $rows) {
                $first=(array)reset($rows);
                $categories=$this->categories_from_rows($rows);
                $title=(string)$this->sanmar->first($first,['PRODUCT_TITLE','PRODUCT_NAME','TITLE'],$san_style);
                $categories=array_values(array_unique(array_merge($categories,$this->discovery_categories_from_title($title))));
                $parts=[$title,(string)$this->sanmar->first($first,['PRODUCT_DESCRIPTION','DESCRIPTION']),(string)$this->sanmar->first($first,['KEYWORDS']),implode(' ',$categories)];
                foreach ($first as $key=>$value) if (preg_match('/fabric|material|feature|fit|profile|closure|gender|age|sport|performance|technology/i',(string)$key)) $parts[]=(string)$value;
                $this->sync_supplier_categories($product_id,$categories,'sanmar',false);
                $this->sync_supplier_discovery_tags($product_id,$this->discovery_tags_from_text(implode(' ',$parts),$categories),'sanmar');
            }
        }

        $ss_brand=trim((string)get_post_meta($product_id,'_asss_ss_brand',true));
        $ss_bid=absint(get_post_meta($product_id,'_asss_ss_brand_id',true));
        $ss_sid=absint(get_post_meta($product_id,'_asss_ss_style_id',true));
        if ($ss_bid && $ss_sid) {
            $data=$this->ss->style_product($ss_bid,$ss_sid);
            if (!is_wp_error($data) && is_array($data)) {
                $title=trim((string)($data['title'] ?? $data['style'] ?? ''));
                $categories=$this->categories_from_normalized_product($data);
                $categories=array_values(array_unique(array_merge($categories,$this->discovery_categories_from_title($title))));
                $parts=[$title,(string)($data['description'] ?? ''),implode(' ',$categories),$this->discovery_scalar_text($data['keywords'] ?? ''),$this->discovery_scalar_text($data['specs'] ?? [])];
                $this->sync_supplier_categories($product_id,$categories,'ss',false);
                $this->sync_supplier_discovery_tags($product_id,$this->discovery_tags_from_text(implode(' ',$parts),$categories),'ss');
            }
        }

        $mom_style=trim((string)get_post_meta($product_id,'_asss_momentec_style',true));
        if ($mom_style!=='') {
            $data=$this->momentec->style_product($mom_style);
            if (!is_wp_error($data) && is_array($data)) {
                $title=trim((string)($data['title'] ?? $mom_style));
                $categories=$this->categories_from_normalized_product($data);
                $categories=array_values(array_unique(array_merge($categories,$this->discovery_categories_from_title($title))));
                $parts=[$title,(string)($data['description'] ?? ''),implode(' ',$categories),$this->discovery_scalar_text($data['keywords'] ?? ''),$this->discovery_scalar_text($data['specs'] ?? []),$this->discovery_scalar_text($data['division'] ?? ''),$this->discovery_scalar_text($data['variation_theme'] ?? ''),$this->discovery_scalar_text($data['ribbon'] ?? '')];
                $this->sync_supplier_categories($product_id,$categories,'momentec',false);
                $this->sync_supplier_discovery_tags($product_id,$this->discovery_tags_from_text(implode(' ',$parts),$categories),'momentec');
            }
        }
    }

    /** v2.0.23 repairs existing supplier-linked products and Richardson 112 title. */
    public function migrate_discovery_taxonomy_and_titles_v2023(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2023_discovery_taxonomy_migrated','') === 'yes') return;
        $ids=get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_query'=>['relation'=>'OR',
                ['key'=>'_asss_sanmar_style','compare'=>'EXISTS'],
                ['key'=>'_asss_ss_style','compare'=>'EXISTS'],
                ['key'=>'_asss_momentec_style','compare'=>'EXISTS'],
            ],
        ]);
        $processed=0;
        foreach ((array)$ids as $product_id) {
            $product_id=(int)$product_id;
            $this->sync_product_discovery_taxonomy($product_id,'migration');
            $this->normalize_canonical_product_title($product_id,'migration');
            $processed++;
        }
        update_option('asss_v2023_discovery_taxonomy_migrated','yes',false);
        ASSS_Logger::log('v2.0.23 discovery taxonomy migration complete','info',['products'=>$processed]);
    }

'''
assert marker in s
s = s.replace(marker, block + marker, 1)
imp.write_text(s)

main = root / 'all-star-supplier-sync.php'
m = main.read_text()
m = m.replace('Version: 2.0.22', 'Version: 2.0.23', 1)
m = m.replace("define('ASSS_VERSION', '2.0.22');", "define('ASSS_VERSION', '2.0.23');", 1)
main.write_text(m)

readme = root / 'readme.txt'
r = readme.read_text().replace('Stable tag: 2.0.22', 'Stable tag: 2.0.23', 1)
anchor = '== Changelog ==\n\n'
entry = '''= 2.0.23 =\n* Richardson style 112 now has one canonical storefront title across all supplier links: Snapback Trucker Cap (Richardson 112).\n* Adds a shared discovery-taxonomy layer across SanMar, S&S, and Momentec for consistent customer-facing categories and product tags.\n* Product tags are now controlled filter facets such as construction, fit/profile, materials, performance features, audience, and product type instead of raw supplier prose/codes.\n* Supplier tags are owned per supplier and merged safely, preserving tags added manually and useful tags from other linked suppliers.\n* Category fallback uses the product title to repair thin supplier categorization into the existing controlled storefront taxonomy without reintroducing raw supplier category noise.\n* Existing supplier-linked products are reconciled once after upgrade; future imports, links, refreshes, and Quick Repair keep discovery taxonomy standardized.\n\n'''
assert anchor in r
r = r.replace(anchor, anchor + entry, 1)
readme.write_text(r)
