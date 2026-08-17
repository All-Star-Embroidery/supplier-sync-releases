#!/usr/bin/env python3
from pathlib import Path
import sys

root = Path(sys.argv[1] if len(sys.argv) > 1 else 'source')
imp = root / 'includes/class-asss-importer.php'
s = imp.read_text(encoding='utf-8')

# New migration hook.
old = "        add_action('admin_init', [$this, 'migrate_standard_categories_and_one_size_v2021'], 38);\n"
new = old + "        add_action('admin_init', [$this, 'migrate_momentec_shipping_weights_v2022'], 39);\n"
assert old in s, 'migration hook anchor missing'
s = s.replace(old, new, 1)

# Managed supplier weight helper. Blank/managed values update; merchant-edited weight wins.
marker = "    private function filter_rows_for_product(int $product_id, array $rows): array {\n"
helper = r'''    /** Apply supplier shipping weight without overwriting a merchant-owned edit. */
    private function apply_supplier_variation_weight(WC_Product_Variation $variation, float $weight_lb, string $supplier): void {
        if ($weight_lb <= 0) return;
        $formatted = $this->weight_for_store($weight_lb);
        $current = (string)$variation->get_weight('edit');
        $managed = (string)$variation->get_meta('_asss_weight_managed') === 'yes';
        $last = (string)$variation->get_meta('_asss_weight_last_value');
        if ($managed && $current !== '' && $last !== '' && (float)$current !== (float)$last) {
            $variation->delete_meta_data('_asss_weight_managed');
            $variation->delete_meta_data('_asss_weight_source');
            $variation->delete_meta_data('_asss_weight_last_value');
            ASSS_Logger::log('Variation shipping-weight ownership changed to merchant', 'info', ['variation_id'=>$variation->get_id(),'supplier'=>$supplier]);
            return;
        }
        if ($current !== '' && !$managed) return;
        $variation->set_weight($formatted);
        $variation->update_meta_data('_asss_supplier_weight_lb', (string)wc_format_decimal($weight_lb, 6));
        $variation->update_meta_data('_asss_weight_managed', 'yes');
        $variation->update_meta_data('_asss_weight_source', sanitize_key($supplier));
        $variation->update_meta_data('_asss_weight_last_value', $formatted);
    }

'''
assert marker in s, 'weight helper anchor missing'
s = s.replace(marker, helper + marker, 1)

# Momentec child variation gets exact feed weight.
old = """        if(isset($row['qty'])&&$row['qty']!==null&&$row['qty']!==''&&is_numeric($row['qty'])){\n            $qty=max(0,(int)$row['qty']);$v->set_manage_stock(true);$v->set_backorders('no');$v->set_stock_quantity($qty);$v->set_stock_status($qty>0?'instock':'outofstock');\n        }\n        [$price,$source]=$this->momentec_variation_price($row);\n"""
new = """        if(isset($row['qty'])&&$row['qty']!==null&&$row['qty']!==''&&is_numeric($row['qty'])){\n            $qty=max(0,(int)$row['qty']);$v->set_manage_stock(true);$v->set_backorders('no');$v->set_stock_quantity($qty);$v->set_stock_status($qty>0?'instock':'outofstock');\n        }\n        $momentec_weight = $row['weight_lb'] ?? null;\n        if ($momentec_weight !== null && is_numeric($momentec_weight) && (float)$momentec_weight > 0) {\n            $this->apply_supplier_variation_weight($v, (float)$momentec_weight, 'momentec');\n            $v->update_meta_data('_asss_momentec_weight_lb', (string)wc_format_decimal((float)$momentec_weight, 6));\n            $v->update_meta_data('_asss_momentec_weight_source', sanitize_text_field((string)($row['weight_source'] ?? 'official-product-feed')));\n            $v->update_meta_data('_asss_momentec_weight_value', sanitize_text_field((string)($row['weight_value'] ?? '')));\n            $v->update_meta_data('_asss_momentec_weight_unit', sanitize_text_field((string)($row['weight_unit'] ?? '')));\n        }\n        [$price,$source]=$this->momentec_variation_price($row);\n"""
assert old in s, 'Momentec variation weight anchor missing'
s = s.replace(old, new, 1)

# Momentec source record retains weight for multi-supplier intelligence/repair.
old = """            'sku'=>$sku,'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '',\n            'retail_price'=>$row['retail_price'] ?? '','inventory_qty'=>isset($row['qty'])&&is_numeric($row['qty'])?(int)$row['qty']:null,\n"""
new = """            'sku'=>$sku,'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '',\n            'weight_lb'=>$row['weight_lb'] ?? null,'weight_source'=>(string)($row['weight_source'] ?? ''),\n            'retail_price'=>$row['retail_price'] ?? '','inventory_qty'=>isset($row['qty'])&&is_numeric($row['qty'])?(int)$row['qty']:null,\n"""
assert old in s, 'Momentec source record anchor missing'
s = s.replace(old, new, 1)

# Existing variation path while linking Momentec also receives weight if blank/managed.
needle = "$existing=wc_get_product($vid);if(!empty($this->sanmar->settings()['sync_images'])&&$existing instanceof WC_Product_Variation&&(!empty($row['gallery'])||!empty($row['primary_image'])))update_post_meta($vid,'_asss_momentec_media_pending','yes');}"
repl = "$existing=wc_get_product($vid);if($existing instanceof WC_Product_Variation && isset($row['weight_lb']) && is_numeric($row['weight_lb']) && (float)$row['weight_lb']>0){$this->apply_supplier_variation_weight($existing,(float)$row['weight_lb'],'momentec');$existing->update_meta_data('_asss_momentec_weight_lb',(string)wc_format_decimal((float)$row['weight_lb'],6));$existing->update_meta_data('_asss_momentec_weight_source',sanitize_text_field((string)($row['weight_source'] ?? 'official-product-feed')));$existing->save();}if(!empty($this->sanmar->settings()['sync_images'])&&$existing instanceof WC_Product_Variation&&(!empty($row['gallery'])||!empty($row['primary_image'])))update_post_meta($vid,'_asss_momentec_media_pending','yes');}"
assert needle in s, 'Momentec multi-link existing variation anchor missing'
s = s.replace(needle, repl, 1)

# Add weight into the compact multi-link source payload.
compact = "'sku'=>(string)($row['sku'] ?? ''),'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '','retail_price'"
compact_new = "'sku'=>(string)($row['sku'] ?? ''),'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '','weight_lb'=>$row['weight_lb'] ?? null,'weight_source'=>(string)($row['weight_source'] ?? ''),'retail_price'"
assert compact in s, 'Momentec compact source record anchor missing'
s = s.replace(compact, compact_new, 1)

# Momentec audit treats expected shipping weight as critical coverage.
old = "$missing_price=0;$missing_image=0;$missing_gallery=0;$missing_sku=0;"
assert old in s, 'Momentec audit counters anchor missing'
s = s.replace(old, "$missing_price=0;$missing_image=0;$missing_gallery=0;$missing_sku=0;$missing_weight=0;", 1)
audit_needle = "if($v->get_sku('edit')==='')$missing_sku++;if(!empty($this->sanmar->settings()['sync_images'])"
audit_repl = "if($v->get_sku('edit')==='')$missing_sku++;$expected_weight=$row['weight_lb'] ?? null;if($expected_weight!==null&&is_numeric($expected_weight)&&(float)$expected_weight>0&&$v->get_weight('edit')==='')$missing_weight++;if(!empty($this->sanmar->settings()['sync_images'])"
assert audit_needle in s, 'Momentec audit body anchor missing'
s = s.replace(audit_needle, audit_repl, 1)
old = "'missing_sku'=>$missing_sku,'created'=>$created"
assert old in s, 'Momentec audit output anchor missing'
s = s.replace(old, "'missing_sku'=>$missing_sku,'missing_weight'=>$missing_weight,'created'=>$created", 1)
s = s.replace("array_sum([$audit['missing_expected'],$missing_price,$missing_image,$missing_gallery,$missing_sku])", "array_sum([$audit['missing_expected'],$missing_price,$missing_image,$missing_gallery,$missing_sku,$missing_weight])")

# One-time migration: refresh old Momentec caches through GitHub, or repair immediately when weight is cached.
migration_marker = "    /** Local, one-time cleanup for products imported before 2.0.18. */\n"
migration = r'''    /**
     * v2.0.22: shipping is weight-based, so every linked Momentec style must be
     * rehydrated from GitHub if its older cache predates official feed weights.
     */
    public function migrate_momentec_shipping_weights_v2022(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2022_momentec_weights_migrated','') === 'yes') return;
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_key'=>'_asss_momentec_style','meta_compare'=>'EXISTS',
        ]);
        $queue = []; $repaired = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            $style = trim((string)get_post_meta($product_id,'_asss_momentec_style',true));
            if ($style === '') continue;
            $data = $this->momentec->style_product($style);
            $variants = !is_wp_error($data) && is_array($data['variants'] ?? null) ? $data['variants'] : [];
            $complete = !empty($variants);
            foreach ($variants as $row) {
                $w = is_array($row) ? ($row['weight_lb'] ?? null) : null;
                if ($w === null || !is_numeric($w) || (float)$w <= 0) { $complete = false; break; }
            }
            if ($complete) {
                $result = $this->update_momentec_style($product_id);
                if (!is_wp_error($result)) $repaired++;
            } else {
                $queue[strtolower($style)] = $style;
            }
        }
        $queued = 0;
        if ($queue) {
            $result = $this->momentec->queue_style_requests(array_values($queue), get_current_user_id(), true);
            if (is_array($result)) $queued = (int)($result['queued'] ?? 0);
        }
        update_option('asss_v2022_momentec_weights_migrated','yes',false);
        ASSS_Logger::log('v2.0.22 Momentec shipping-weight migration initialized','info',[
            'products'=>count((array)$ids),'repaired_from_weighted_cache'=>$repaired,'styles_queued_for_github'=>$queued,
        ]);
    }

'''
assert migration_marker in s, 'migration insertion anchor missing'
s = s.replace(migration_marker, migration + migration_marker, 1)
imp.write_text(s, encoding='utf-8')

# Version metadata.
main = root / 'all-star-supplier-sync.php'
m = main.read_text(encoding='utf-8')
assert 'Version: 2.0.21' in m and "define('ASSS_VERSION', '2.0.21');" in m
m = m.replace('Version: 2.0.21', 'Version: 2.0.22', 1).replace("define('ASSS_VERSION', '2.0.21');", "define('ASSS_VERSION', '2.0.22');", 1)
main.write_text(m, encoding='utf-8')

readme = root / 'readme.txt'
r = readme.read_text(encoding='utf-8')
assert 'Stable tag: 2.0.21' in r
r = r.replace('Stable tag: 2.0.21', 'Stable tag: 2.0.22', 1)
changelog = '''= 2.0.22 =\n* Momentec shipping weight now comes from the official full product-data feed by exact Item_SKU; /v2/Style remains the source for customer-specific style data.\n* Weight_Unit is normalized to pounds in GitHub (lb/oz/kg/g supported), with the heaviest known style weight used only as a conservative fallback when a specific feed row lacks weight.\n* WooCommerce Momentec variations now receive supplier-managed shipping weight, while merchant-edited weights remain protected. Parent products retain the maximum selected-variation weight as a safe fallback.\n* Momentec variation audits now include missing shipping weight as a critical coverage field. GitHub publishing refuses hydration when no usable weight can be established.\n* Existing Momentec-linked products automatically queue fresh GitHub hydration when their cached style predates weight support; already-weighted caches repair immediately.\n\n'''
anchor = '== Changelog ==\n\n'
assert anchor in r
r = r.replace(anchor, anchor + changelog, 1)
readme.write_text(r, encoding='utf-8')

print('Applied v2.0.22 Momentec shipping-weight patch')
