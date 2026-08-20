#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('source')


def replace_once(path, old, new, label):
    p = ROOT / path
    s = p.read_text()
    if old not in s:
        raise RuntimeError(f'{path}: missing {label}')
    p.write_text(s.replace(old, new, 1))


def replace_all(path, old, new, label, minimum=1):
    p = ROOT / path
    s = p.read_text()
    count = s.count(old)
    if count < minimum:
        raise RuntimeError(f'{path}: expected >= {minimum} occurrences for {label}, found {count}')
    p.write_text(s.replace(old, new))

# Version metadata.
replace_once('all-star-supplier-sync.php', 'Version: 2.0.25', 'Version: 2.0.26', 'plugin header version')
replace_once('all-star-supplier-sync.php', "define('ASSS_VERSION', '2.0.25');", "define('ASSS_VERSION', '2.0.26');", 'version constant')
replace_once('readme.txt', 'Stable tag: 2.0.25', 'Stable tag: 2.0.26', 'stable tag')
replace_once('readme.txt', '== Changelog ==\n', '''== Changelog ==\n\n= 2.0.26 =\n* Standardizes supplier-managed pricing around a verified 1-unit/account buy cost for SanMar, S&S Activewear and Momentec.\n* Woo regular price is buy cost + $20; MAP/MSRP/suggested retail are reference-only and are no longer used as retail-price fallbacks.\n* Preserves supplier quantity cost breaks (piece/dozen/case where available) in the multi-supplier source record for margin analysis and future ASBO use.\n* Repairs existing Supplier Sync-managed Woo prices and ASBO matrices from the standardized cost basis without overriding merchant-owned prices or matrices.\n''', 'changelog')

# SanMar normalized bridge fields.
replace_once('includes/class-asss-sanmar.php', '''                'PIECE_PRICE'=>(string)($v['piece_price'] ?? ''),
                'CASE_PRICE'=>(string)($v['case_price'] ?? ''),
''', '''                'UNIT_BUY_PRICE'=>(string)($v['unit_buy_price'] ?? $v['piece_price'] ?? ''),
                'UNIT_BUY_QTY'=>(string)($v['unit_buy_qty'] ?? 1),
                'PIECE_PRICE'=>(string)($v['piece_price'] ?? ''),
                'DOZEN_PRICE'=>(string)($v['dozen_price'] ?? ''),
                'CASE_PRICE'=>(string)($v['case_price'] ?? ''),
                'PRICE_BREAKS'=>wp_json_encode(array_values((array)($v['price_breaks'] ?? []))),
                'SUGGESTED_PRICE'=>(string)($v['suggested_price'] ?? ''),
''', 'SanMar bridge pricing fields')

# Multi-supplier source schema owns the standardized cost semantics.
replace_once('includes/class-asss-multi.php', '''    public function save_variation_sources(int $variation_id, array $sources): void {
''', '''    private function positive_money($value): ?float {
        return ($value !== null && $value !== '' && is_numeric($value) && (float)$value > 0) ? (float)$value : null;
    }

    /**
     * Normalize every supplier source around the same pricing vocabulary.
     * unit_buy_price always means the cost to All Star for buying one unit at the
     * account's normal piece level. Supplier quantity breaks stay separate and
     * never replace the base WooCommerce pricing cost.
     */
    private function normalize_pricing_source(array $data): array {
        $unit = null;
        foreach (['unit_buy_price','unit_buy_cost','cost','customer_price','piece_price'] as $key) {
            $unit = $this->positive_money($data[$key] ?? null);
            if ($unit !== null) break;
        }
        if ($unit !== null) {
            $data['unit_buy_price'] = $unit;
            $data['unit_buy_qty'] = 1;
            if ($this->positive_money($data['cost'] ?? null) === null) $data['cost'] = $unit;
        }

        $breaks = $data['price_breaks'] ?? [];
        if (is_string($breaks)) {
            $decoded = json_decode($breaks, true);
            $breaks = is_array($decoded) ? $decoded : [];
        }
        $by_qty = [];
        foreach ((array)$breaks as $row) {
            if (!is_array($row)) continue;
            $qty = absint($row['min_qty'] ?? $row['qty'] ?? 0);
            $cost = $this->positive_money($row['unit_cost'] ?? $row['cost'] ?? null);
            if (!$qty || $cost === null) continue;
            $by_qty[$qty] = ['min_qty'=>$qty,'unit_cost'=>$cost,'source'=>sanitize_key((string)($row['source'] ?? 'supplier'))];
        }
        if ($unit !== null && !isset($by_qty[1])) $by_qty[1] = ['min_qty'=>1,'unit_cost'=>$unit,'source'=>'account_piece'];
        $dozen = $this->positive_money($data['dozen_price'] ?? null);
        if ($dozen !== null && !isset($by_qty[12])) $by_qty[12] = ['min_qty'=>12,'unit_cost'=>$dozen,'source'=>'dozen'];
        $case = $this->positive_money($data['case_price'] ?? null);
        $case_qty = absint($data['case_qty'] ?? $data['case_size'] ?? 0);
        if ($case !== null && $case_qty > 0) $by_qty[$case_qty] = ['min_qty'=>$case_qty,'unit_cost'=>$case,'source'=>'case'];
        ksort($by_qty, SORT_NUMERIC);
        $data['price_breaks'] = array_values($by_qty);
        return $data;
    }

    public function save_variation_sources(int $variation_id, array $sources): void {
''', 'pricing source helper')
replace_once('includes/class-asss-multi.php', '''            if (!in_array($supplier, ['sanmar','ss','momentec'], true) || !is_array($data)) continue;
            $data['supplier'] = $supplier;
            $data['enabled'] = array_key_exists('enabled',$data) ? !empty($data['enabled']) : true;
            $clean[$supplier] = $data;
''', '''            if (!in_array($supplier, ['sanmar','ss','momentec'], true) || !is_array($data)) continue;
            $data = $this->normalize_pricing_source($data);
            $data['supplier'] = $supplier;
            $data['enabled'] = array_key_exists('enabled',$data) ? !empty($data['enabled']) : true;
            $clean[$supplier] = $data;
''', 'save variation source normalization')
replace_once('includes/class-asss-multi.php', '''        $sources = $this->variation_sources($variation_id);
        $old = is_array($sources[$supplier] ?? null) ? $sources[$supplier] : [];
        $sources[$supplier] = array_merge($old, $data, [
''', '''        $sources = $this->variation_sources($variation_id);
        $old = is_array($sources[$supplier] ?? null) ? $sources[$supplier] : [];
        $data = $this->normalize_pricing_source($data);
        $sources[$supplier] = array_merge($old, $data, [
''', 'register variation source normalization')

# Pricing migration hook.
replace_once('includes/class-asss-importer.php', '''        add_action('admin_init', [$this, 'migrate_sanmar_gallery_images_v2025'], 42);
''', '''        add_action('admin_init', [$this, 'migrate_sanmar_gallery_images_v2025'], 42);
        add_action('admin_init', [$this, 'migrate_standard_supplier_pricing_v2026'], 43);
''', 'v2026 migration hook')

# SanMar regular price uses verified one-unit cost only; no retail/MAP fallback.
replace_once('includes/class-asss-importer.php', '''        $wholesale = (string)$this->sanmar->first($row, ['PIECE_PRICE','PRICE']);
        $price = null;
        $source = '';
        if ($wholesale !== '' && is_numeric($wholesale) && (float)$wholesale > 0) {
            $price = (float)$wholesale + 20.0;
            $source = 'supplier_markup:sanmar';
        } else {
            // Rare safety fallback for a supplier row that has no usable cost.
            $map = (string)$this->sanmar->first($row, ['MAP_PRICE']);
            if ($map !== '' && is_numeric($map) && (float)$map > 0) {
                $price = (float)$map;
                $source = 'map_fallback:sanmar';
            }
        }
        if ($price === null || $price <= 0) return;
''', '''        $wholesale = (string)$this->sanmar->first($row, ['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']);
        if ($wholesale === '' || !is_numeric($wholesale) || (float)$wholesale <= 0) return;
        $price = (float)$wholesale + 20.0;
        $source = 'supplier_markup:sanmar:unit-buy';
''', 'SanMar base price rule')

# S&S and Momentec use the same one-unit rule and never substitute retail/MAP.
replace_once('includes/class-asss-importer.php', '''    private function ss_variation_price(int $product_id, array $row): array {
        $wholesale = $row['customer_price'] ?? null;
        if ($wholesale !== null && $wholesale !== '' && is_numeric($wholesale) && (float)$wholesale > 0) {
            return [(float)$wholesale + 20.0, 'supplier_markup:ss'];
        }
        // Safety fallback only when the account-specific wholesale value is
        // unexpectedly unavailable. Normal S&S pricing always uses +$20 cost.
        $candidates = [];
        foreach (['map_price','retail_price'] as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value) && (float)$value > 0) $candidates[] = (float)$value;
        }
        return $candidates ? [max($candidates), 'ss_retail_fallback'] : [null, ''];
    }
''', '''    private function ss_variation_price(int $product_id, array $row): array {
        foreach (['unit_buy_price','customer_price','piece_price'] as $key) {
            $wholesale = $row[$key] ?? null;
            if ($wholesale !== null && $wholesale !== '' && is_numeric($wholesale) && (float)$wholesale > 0) {
                return [(float)$wholesale + 20.0, 'supplier_markup:ss:unit-buy'];
            }
        }
        return [null, ''];
    }
''', 'S&S base price rule')
replace_once('includes/class-asss-importer.php', '''    private function momentec_variation_price(array $row): array {
        $cost=$row['customer_price'] ?? $row['piece_price'] ?? null;
        if($cost!==null && $cost!=='' && is_numeric($cost) && (float)$cost>0) return [(float)$cost+20.0,'supplier_markup:momentec'];
        $retail=$row['retail_price'] ?? null;
        return ($retail!==null && $retail!=='' && is_numeric($retail) && (float)$retail>0) ? [(float)$retail,'momentec_retail_fallback'] : [null,''];
    }
''', '''    private function momentec_variation_price(array $row): array {
        foreach(['unit_buy_price','customer_price','piece_price'] as $key){
            $cost=$row[$key] ?? null;
            if($cost!==null && $cost!=='' && is_numeric($cost) && (float)$cost>0)return[(float)$cost+20.0,'supplier_markup:momentec:unit-buy'];
        }
        return [null,''];
    }
''', 'Momentec base price rule')

# Multi-source pricing engine prefers the explicit standardized unit cost.
replace_once('includes/class-asss-importer.php', '''            foreach (['cost','customer_price','piece_price'] as $key) {
''', '''            foreach (['unit_buy_price','unit_buy_cost','cost','customer_price','piece_price'] as $key) {
''', 'pricing basis priority')

# Effective pricing metadata exposes exactly what Woo + ASBO were based on.
replace_once('includes/class-asss-importer.php', '''                    $variation->update_meta_data('_asss_pricing_wholesale_basis', (string)wc_format_decimal((float)$basis['cost'], wc_get_price_decimals()));
                    $variation->update_meta_data('_asss_pricing_basis_supplier', $basis['supplier']);
''', '''                    $variation->update_meta_data('_asss_pricing_wholesale_basis', (string)wc_format_decimal((float)$basis['cost'], wc_get_price_decimals()));
                    $variation->update_meta_data('_asss_pricing_basis_supplier', $basis['supplier']);
                    $variation->update_meta_data('_asss_unit_buy_price', (string)wc_format_decimal((float)$basis['cost'], wc_get_price_decimals()));
                    $variation->update_meta_data('_asss_unit_buy_qty', '1');
''', 'effective unit price meta')

# SanMar variation metadata and source records use explicit unit cost + preserve price tiers.
replace_all('includes/class-asss-importer.php', "['PIECE_PRICE','PRICE']", "['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']", 'SanMar wholesale key priority', minimum=5)
replace_once('includes/class-asss-importer.php', '''        $v->update_meta_data('_asss_sanmar_case_price', (string)$this->sanmar->first($row, ['CASE_PRICE']));
        $v->update_meta_data('_asss_supplier_sale_price', (string)$this->sanmar->first($row, ['PIECE_SALE_PRICE']));
''', '''        $v->update_meta_data('_asss_sanmar_case_price', (string)$this->sanmar->first($row, ['CASE_PRICE']));
        $v->update_meta_data('_asss_sanmar_case_size', (string)$this->sanmar->first($row, ['CASE_SIZE']));
        $v->update_meta_data('_asss_sanmar_dozen_price', (string)$this->sanmar->first($row, ['DOZEN_PRICE']));
        $v->update_meta_data('_asss_sanmar_suggested_price', (string)$this->sanmar->first($row, ['SUGGESTED_PRICE']));
        $v->update_meta_data('_asss_supplier_sale_price', (string)$this->sanmar->first($row, ['PIECE_SALE_PRICE']));
''', 'SanMar reference price meta')
replace_once('includes/class-asss-importer.php', '''            'cost'=>(string)$this->sanmar->first($row, ['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']),
            'map_price'=>(string)$this->sanmar->first($row, ['MAP_PRICE']),
''', '''            'cost'=>(string)$this->sanmar->first($row, ['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']),
            'unit_buy_price'=>(string)$this->sanmar->first($row, ['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']),
            'unit_buy_qty'=>1,
            'dozen_price'=>(string)$this->sanmar->first($row, ['DOZEN_PRICE']),
            'case_price'=>(string)$this->sanmar->first($row, ['CASE_PRICE']),
            'case_size'=>(string)$this->sanmar->first($row, ['CASE_SIZE']),
            'suggested_price'=>(string)$this->sanmar->first($row, ['SUGGESTED_PRICE']),
            'map_price'=>(string)$this->sanmar->first($row, ['MAP_PRICE']),
''', 'primary SanMar source pricing fields')
# Compact multi-link SanMar source record.
replace_once('includes/class-asss-importer.php', '''                'cost'=>(string)$this->sanmar->first($row,['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']),
                'case_price'=>(string)$this->sanmar->first($row,['CASE_PRICE']),
''', '''                'cost'=>(string)$this->sanmar->first($row,['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']),
                'unit_buy_price'=>(string)$this->sanmar->first($row,['UNIT_BUY_PRICE','PIECE_PRICE','PRICE']),'unit_buy_qty'=>1,
                'dozen_price'=>(string)$this->sanmar->first($row,['DOZEN_PRICE']),
                'case_price'=>(string)$this->sanmar->first($row,['CASE_PRICE']),'case_size'=>(string)$this->sanmar->first($row,['CASE_SIZE']),
                'suggested_price'=>(string)$this->sanmar->first($row,['SUGGESTED_PRICE']),
''', 'multi-link SanMar pricing fields')

# S&S source records carry all supplier cost levels while cost remains account/unit buy cost.
replace_once('includes/class-asss-importer.php', '''            'cost'=>$row['customer_price'] ?? '', 'map_price'=>$row['map_price'] ?? '', 'retail_price'=>$row['retail_price'] ?? '',
            'inventory_qty'=>isset($row['qty']) && is_numeric($row['qty']) ? (int)$row['qty'] : null,
''', '''            'cost'=>$row['unit_buy_price'] ?? $row['customer_price'] ?? $row['piece_price'] ?? '',
            'unit_buy_price'=>$row['unit_buy_price'] ?? $row['customer_price'] ?? $row['piece_price'] ?? '', 'unit_buy_qty'=>1,
            'piece_price'=>$row['piece_price'] ?? '', 'dozen_price'=>$row['dozen_price'] ?? '', 'case_price'=>$row['case_price'] ?? '', 'case_qty'=>$row['case_qty'] ?? '',
            'price_breaks'=>(array)($row['price_breaks'] ?? []), 'map_price'=>$row['map_price'] ?? '', 'retail_price'=>$row['retail_price'] ?? '',
            'inventory_qty'=>isset($row['qty']) && is_numeric($row['qty']) ? (int)$row['qty'] : null,
''', 'S&S main source pricing fields')
replace_once('includes/class-asss-importer.php', '''                'cost'=>$row['customer_price'] ?? '', 'piece_price'=>$row['piece_price'] ?? '', 'dozen_price'=>$row['dozen_price'] ?? '',
                'case_price'=>$row['case_price'] ?? '', 'sale_price'=>$row['sale_price'] ?? '', 'map_price'=>$row['map_price'] ?? '', 'retail_price'=>$row['retail_price'] ?? '',
''', '''                'cost'=>$row['unit_buy_price'] ?? $row['customer_price'] ?? $row['piece_price'] ?? '',
                'unit_buy_price'=>$row['unit_buy_price'] ?? $row['customer_price'] ?? $row['piece_price'] ?? '', 'unit_buy_qty'=>1,
                'piece_price'=>$row['piece_price'] ?? '', 'dozen_price'=>$row['dozen_price'] ?? '',
                'case_price'=>$row['case_price'] ?? '', 'price_breaks'=>(array)($row['price_breaks'] ?? []), 'sale_price'=>$row['sale_price'] ?? '', 'map_price'=>$row['map_price'] ?? '', 'retail_price'=>$row['retail_price'] ?? '',
''', 'S&S link source pricing fields')

# Momentec source record uses the same standardized vocabulary.
replace_once('includes/class-asss-importer.php', '''            'sku'=>$sku,'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '',
            'weight_lb'=>$row['weight_lb'] ?? null,'weight_source'=>(string)($row['weight_source'] ?? ''),
''', '''            'sku'=>$sku,'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,
            'cost'=>$row['unit_buy_price'] ?? $row['customer_price'] ?? $row['piece_price'] ?? '',
            'unit_buy_price'=>$row['unit_buy_price'] ?? $row['customer_price'] ?? $row['piece_price'] ?? '', 'unit_buy_qty'=>1,
            'price_breaks'=>(array)($row['price_breaks'] ?? []),
            'weight_lb'=>$row['weight_lb'] ?? null,'weight_source'=>(string)($row['weight_source'] ?? ''),
''', 'Momentec source pricing fields')

# Remove SanMar MAP as a deep-repair retail substitute. Existing ASBO/managed sibling
# prices remain safe fallbacks because those derive from verified supplier cost.
replace_once('includes/class-asss-importer.php', '''        $global_maps = [];
        foreach ($expected as $row) {
            $map = (string)$this->sanmar->first($row, ['MAP_PRICE']);
            if ($map !== '' && is_numeric($map) && (float)$map > 0) $global_maps[] = (float)$map;
        }
        $global_map = $global_maps ? max($global_maps) : null;

''', '', 'remove SanMar global MAP fallback')
replace_once('includes/class-asss-importer.php', '''            // Price guarantee. ASBO is product-level and should make EVERY child
            // purchasable. Managed prices follow ASBO changes; manual prices are
            // never overwritten. If ASBO is absent, use row MAP, a same-color
            // sibling, global MAP, then an existing managed sibling. Wholesale
            // PIECE_PRICE is intentionally never exposed as retail price.
''', '''            // Price guarantee. ASBO is product-level and should make EVERY child
            // purchasable. Managed prices follow ASBO changes; manual prices are
            // never overwritten. Suggested retail/MAP are reference-only; when a
            // row lacks cost, only another verified managed cost-derived price may
            // repair it.
''', 'deep repair comment')
replace_once('includes/class-asss-importer.php', '''            } elseif ($current === '' || $managed) {
                $row_map = (string)$this->sanmar->first($row, ['MAP_PRICE']);
                if ($row_map !== '' && is_numeric($row_map) && (float)$row_map > 0) {
                    $candidate = (float)$row_map;
                    $source = 'map-row-deep-repair';
                } else {
                    $ckey = strtolower($color);
                    if ($ckey !== '' && !empty($price_by_color[$ckey])) {
                        $candidate = max($price_by_color[$ckey]);
                        $source = 'same-color-sibling-deep-repair';
                    } elseif ($global_map !== null) {
                        $candidate = $global_map;
                        $source = 'map-product-deep-repair';
                    } elseif ($any_managed_price !== null) {
                        $candidate = $any_managed_price;
                        $source = 'managed-sibling-deep-repair';
                    }
                }
            }
''', '''            } elseif ($current === '' || $managed) {
                $ckey = strtolower($color);
                if ($ckey !== '' && !empty($price_by_color[$ckey])) {
                    $candidate = max($price_by_color[$ckey]);
                    $source = 'same-color-cost-derived-repair';
                } elseif ($any_managed_price !== null) {
                    $candidate = $any_managed_price;
                    $source = 'managed-cost-derived-repair';
                }
            }
''', 'remove SanMar row MAP fallback')

# One-time repair: normalize every stored source schema, then recalculate only
# Supplier Sync-managed Woo prices/matrices. Merchant-owned values remain protected.
insert_before = '''    /** v2.0.25: queue the current Supplier Sync-owned SanMar parent gallery for GitHub normalization. */
'''
migration = '''    /** v2.0.26: standardize existing supplier cost records and rebuild managed pricing. */
    public function migrate_standard_supplier_pricing_v2026(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2026_supplier_pricing_migrated','') === 'yes') return;
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_key'=>'_asss_sync_enabled','meta_value'=>'yes',
        ]);
        $products = 0; $variations = 0; $missing_unit_cost = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            foreach ($this->variation_ids_direct($product_id) as $variation_id) {
                $sources = $this->multi->variation_sources((int)$variation_id);
                if (!$sources) continue;
                $this->multi->save_variation_sources((int)$variation_id, $sources);
                $variations++;
                $has_unit = false;
                foreach ($this->multi->variation_sources((int)$variation_id) as $source) {
                    if (!is_array($source) || empty($source['enabled'])) continue;
                    $value = $source['unit_buy_price'] ?? null;
                    if ($value !== null && $value !== '' && is_numeric($value) && (float)$value > 0) { $has_unit = true; break; }
                }
                if (!$has_unit) $missing_unit_cost++;
            }
            $this->sync_managed_pricing_for_product($product_id);
            $products++;
        }
        update_option('asss_v2026_supplier_pricing_migrated','yes',false);
        ASSS_Logger::log('v2.0.26 standardized supplier pricing basis and rebuilt managed pricing','info',[
            'products'=>$products,'variations'=>$variations,'variations_missing_unit_cost'=>$missing_unit_cost,
        ]);
    }

'''
p = ROOT / 'includes/class-asss-importer.php'
s = p.read_text()
if insert_before not in s:
    raise RuntimeError('importer: missing v2025 insertion point')
p.write_text(s.replace(insert_before, migration + insert_before, 1))

print('v2.0.26 patch applied')
