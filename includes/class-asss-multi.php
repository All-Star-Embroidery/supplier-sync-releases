<?php
if (!defined('ABSPATH')) exit;

/**
 * Multi-supplier data layer.
 *
 * Keeps supplier-specific identity, cost and inventory attached to one
 * WooCommerce product/variation while leaving storefront pricing and ASBO data
 * merchant-owned. Legacy single-supplier meta remains intact for backwards
 * compatibility with existing integrations and historical orders.
 */
class ASSS_MultiSupplier {
    private ASSS_SanMar $sanmar;

    public function __construct(ASSS_SanMar $sanmar) {
        $this->sanmar = $sanmar;
    }

    public static function suppliers(): array {
        return ['sanmar'=>'SanMar', 'ss'=>'S&S Activewear', 'momentec'=>'Momentec Brands'];
    }

    public function settings(): array {
        $s = $this->sanmar->settings();
        $strategy = sanitize_key((string)($s['multi_inventory_strategy'] ?? 'combined'));
        if (!in_array($strategy, ['combined','preferred'], true)) $strategy = 'combined';
        $priority = array_values(array_filter(array_map('sanitize_key', preg_split('/\s*,\s*/', (string)($s['supplier_priority'] ?? 'ss,sanmar,momentec')) ?: [])));
        $priority = array_values(array_unique(array_filter($priority, static fn($v) => in_array($v, ['ss','sanmar','momentec'], true))));
        foreach (['ss','sanmar','momentec'] as $supplier) if (!in_array($supplier, $priority, true)) $priority[] = $supplier;
        return ['strategy'=>$strategy,'priority'=>$priority];
    }

    public function product_sources(int $product_id): array {
        $raw = get_post_meta($product_id, '_asss_supplier_sources', true);
        $sources = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($sources)) $sources = [];

        // Hydrate legacy products lazily so installing V2 never requires a
        // destructive migration job.
        $legacy = (string)get_post_meta($product_id, '_asss_supplier', true);
        if (($legacy === 'sanmar' || $legacy === 'multi') && !isset($sources['sanmar'])) {
            $brand = (string)get_post_meta($product_id, '_asss_sanmar_brand', true);
            $style = (string)get_post_meta($product_id, '_asss_sanmar_style', true);
            if ($brand !== '' || $style !== '') {
                $sources['sanmar'] = [
                    'supplier'=>'sanmar','brand'=>$brand,'style'=>$style,'enabled'=>true,
                    'selection_mode'=>(string)get_post_meta($product_id, '_asss_sanmar_color_selection_mode', true) ?: ((string)get_post_meta($product_id, '_asss_color_selection_mode', true) ?: 'all'),
                    'selected_colors'=>$this->decode_list((string)get_post_meta($product_id, '_asss_sanmar_selected_colors', true) ?: (string)get_post_meta($product_id, '_asss_selected_colors', true)),
                ];
            }
        }
        if (($legacy === 'ss' || $legacy === 'multi') && !isset($sources['ss'])) {
            $brand_id = absint(get_post_meta($product_id, '_asss_ss_brand_id', true));
            $style_id = absint(get_post_meta($product_id, '_asss_ss_style_id', true));
            if ($brand_id || $style_id) {
                $sources['ss'] = [
                    'supplier'=>'ss','brand_id'=>$brand_id,'style_id'=>$style_id,
                    'brand'=>(string)get_post_meta($product_id, '_asss_ss_brand', true),
                    'style'=>(string)get_post_meta($product_id, '_asss_ss_style', true),'enabled'=>true,
                    'selection_mode'=>(string)get_post_meta($product_id, '_asss_ss_color_selection_mode', true) ?: ((string)get_post_meta($product_id, '_asss_color_selection_mode', true) ?: 'all'),
                    'selected_colors'=>$this->decode_list((string)get_post_meta($product_id, '_asss_ss_selected_colors', true) ?: (string)get_post_meta($product_id, '_asss_selected_colors', true)),
                ];
            }
        }
        return $sources;
    }

    public function save_product_sources(int $product_id, array $sources): void {
        $clean = [];
        foreach ($sources as $supplier=>$data) {
            $supplier = sanitize_key((string)$supplier);
            if (!in_array($supplier, ['sanmar','ss','momentec'], true) || !is_array($data)) continue;
            $data['supplier'] = $supplier;
            $data['enabled'] = array_key_exists('enabled',$data) ? !empty($data['enabled']) : true;
            if (isset($data['selected_colors']) && is_array($data['selected_colors'])) {
                $data['selected_colors'] = array_values(array_unique(array_filter(array_map('sanitize_text_field', $data['selected_colors']))));
            }
            $clean[$supplier] = $data;
        }
        update_post_meta($product_id, '_asss_supplier_sources', wp_json_encode($clean));
        $active = array_keys(array_filter($clean, static fn($v)=>!empty($v['enabled'])));
        if (count($active) > 1) update_post_meta($product_id, '_asss_supplier', 'multi');
        elseif (count($active) === 1) update_post_meta($product_id, '_asss_supplier', $active[0]);
        else delete_post_meta($product_id, '_asss_supplier');
        update_post_meta($product_id, '_asss_source_count', count($active));
    }

    public function register_product_source(int $product_id, string $supplier, array $data): void {
        $supplier = sanitize_key($supplier);
        if (!in_array($supplier, ['sanmar','ss','momentec'], true)) return;
        $sources = $this->product_sources($product_id);
        $old = is_array($sources[$supplier] ?? null) ? $sources[$supplier] : [];
        $sources[$supplier] = array_merge($old, $data, [
            'supplier'=>$supplier,'enabled'=>true,
            'linked_at'=>(string)($old['linked_at'] ?? current_time('mysql')),
            'updated_at'=>current_time('mysql'),
        ]);
        $this->save_product_sources($product_id, $sources);
    }

    public function remove_product_source(int $product_id, string $supplier, string $reason = ''): bool {
        $supplier = sanitize_key($supplier);
        if (!in_array($supplier, ['sanmar','ss','momentec'], true)) return false;
        $sources = $this->product_sources($product_id);
        if (empty($sources[$supplier]['enabled'])) return false;

        $snapshot = $sources[$supplier];
        $this->append_disconnect_history($product_id, $supplier, $snapshot, $reason);
        unset($sources[$supplier]);
        $this->save_product_sources($product_id, $sources);
        $this->purge_product_supplier_meta($product_id, $supplier);
        if ((string)get_post_meta($product_id, '_asss_preferred_supplier', true) === $supplier) {
            delete_post_meta($product_id, '_asss_preferred_supplier');
        }

        foreach ($this->variation_ids($product_id) as $variation_id) {
            $v_sources = $this->variation_sources($variation_id);
            if (!empty($v_sources[$supplier]['enabled'])) {
                $this->remove_variation_source($variation_id, $supplier, $reason ?: 'product-source-disconnected');
            }
        }

        $remaining = array_filter($this->product_sources($product_id), static fn($v)=>is_array($v)&&!empty($v['enabled']));
        if ($remaining) {
            update_post_meta($product_id, '_asss_sync_enabled', 'yes');
            $this->refresh_legacy_product_meta($product_id, $remaining);
        } else {
            update_post_meta($product_id, '_asss_sync_enabled', 'no');
            foreach (['_asss_supplier_product_key','_asss_color_selection_mode','_asss_selected_colors','_asss_inventory_strategy_override','_asss_preferred_supplier'] as $key) delete_post_meta($product_id, $key);
        }

        $this->recalculate_product_inventory($product_id);
        update_post_meta($product_id, '_asss_last_product_sync', current_time('mysql'));
        ASSS_Logger::log('Supplier source disconnected from WooCommerce product', 'info', [
            'product_id'=>$product_id,'supplier'=>$supplier,'remaining_sources'=>array_keys($remaining),
        ]);
        return true;
    }

    public function variation_sources(int $variation_id): array {
        $raw = get_post_meta($variation_id, '_asss_supplier_sources', true);
        $sources = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($sources)) $sources = [];
        $legacy = (string)get_post_meta($variation_id, '_asss_supplier', true);
        if (($legacy === 'sanmar' || $legacy === 'multi') && !isset($sources['sanmar'])) {
            $identity = (string)get_post_meta($variation_id, '_asss_sanmar_unique_key', true);
            if ($identity !== '' || (string)get_post_meta($variation_id, '_asss_sanmar_inventory_key', true) !== '') {
                $sources['sanmar'] = [
                    'supplier'=>'sanmar','unique_key'=>$identity,
                    'inventory_key'=>(string)get_post_meta($variation_id, '_asss_sanmar_inventory_key', true),
                    'size_index'=>(string)get_post_meta($variation_id, '_asss_sanmar_size_index', true),
                    'brand'=>(string)get_post_meta($variation_id, '_asss_sanmar_brand', true),
                    'style'=>(string)get_post_meta($variation_id, '_asss_sanmar_style', true),
                    'color'=>(string)get_post_meta($variation_id, '_asss_sanmar_color', true),
                    'size'=>(string)get_post_meta($variation_id, '_asss_sanmar_size', true),
                    'cost'=>(string)get_post_meta($variation_id, '_asss_supplier_cost', true),
                    'map_price'=>(string)get_post_meta($variation_id, '_asss_map_price', true),
                    'inventory_qty'=>$this->nullable_int(get_post_meta($variation_id, '_asss_inventory_sanmar_qty', true), get_post_meta($variation_id, '_asss_supplier_inventory_qty', true)),
                ];
            }
        }
        if (($legacy === 'ss' || $legacy === 'multi') && !isset($sources['ss'])) {
            $sku = (string)get_post_meta($variation_id, '_asss_ss_sku', true);
            $sku_id = (string)get_post_meta($variation_id, '_asss_ss_sku_id', true);
            if ($sku !== '' || $sku_id !== '') {
                $sources['ss'] = [
                    'supplier'=>'ss','sku'=>$sku,'sku_id'=>$sku_id,
                    'gtin'=>(string)get_post_meta($variation_id, '_asss_ss_gtin', true),
                    'color'=>(string)get_post_meta($variation_id, '_asss_ss_color', true),
                    'size'=>(string)get_post_meta($variation_id, '_asss_ss_size', true),
                    'cost'=>(string)get_post_meta($variation_id, '_asss_ss_customer_price', true),
                    'map_price'=>(string)get_post_meta($variation_id, '_asss_ss_map_price', true),
                    'retail_price'=>(string)get_post_meta($variation_id, '_asss_ss_retail_price', true),
                    'inventory_qty'=>$this->nullable_int(get_post_meta($variation_id, '_asss_inventory_ss_qty', true), get_post_meta($variation_id, '_asss_supplier_inventory_qty', true)),
                ];
            }
        }
        return $sources;
    }

    public function save_variation_sources(int $variation_id, array $sources): void {
        $clean = [];
        foreach ($sources as $supplier=>$data) {
            $supplier = sanitize_key((string)$supplier);
            if (!in_array($supplier, ['sanmar','ss','momentec'], true) || !is_array($data)) continue;
            $data['supplier'] = $supplier;
            $data['enabled'] = array_key_exists('enabled',$data) ? !empty($data['enabled']) : true;
            $clean[$supplier] = $data;
        }
        update_post_meta($variation_id, '_asss_supplier_sources', wp_json_encode($clean));
        $active = array_keys(array_filter($clean, static fn($v)=>!empty($v['enabled'])));
        if (count($active) > 1) update_post_meta($variation_id, '_asss_supplier', 'multi');
        elseif (count($active) === 1) update_post_meta($variation_id, '_asss_supplier', $active[0]);
        else delete_post_meta($variation_id, '_asss_supplier');
        update_post_meta($variation_id, '_asss_source_count', count($active));
        if ($active) {
            $v = wc_get_product($variation_id);
            if ($v instanceof WC_Product_Variation) {
                $supplier_disabled = (string)$v->get_meta('_asss_stale_variation') === 'yes' || (string)$v->get_meta('_asss_discontinued_variation') === 'yes';
                if ($supplier_disabled) {
                    $v->delete_meta_data('_asss_stale_variation');
                    $v->delete_meta_data('_asss_stale_variation_reason');
                    $v->delete_meta_data('_asss_discontinued_variation');
                    $v->set_status('publish');
                    $v->save();
                }
            }
        }
    }

    public function register_variation_source(int $variation_id, string $supplier, array $data): void {
        $supplier = sanitize_key($supplier);
        if (!in_array($supplier, ['sanmar','ss','momentec'], true)) return;
        $sources = $this->variation_sources($variation_id);
        $old = is_array($sources[$supplier] ?? null) ? $sources[$supplier] : [];
        $sources[$supplier] = array_merge($old, $data, [
            'supplier'=>$supplier,'enabled'=>true,
            'linked_at'=>(string)($old['linked_at'] ?? current_time('mysql')),
            'updated_at'=>current_time('mysql'),
        ]);
        $this->save_variation_sources($variation_id, $sources);
    }

    public function remove_variation_source(int $variation_id, string $supplier, string $reason = ''): void {
        $supplier = sanitize_key($supplier);
        $sources = $this->variation_sources($variation_id);
        if (!isset($sources[$supplier])) return;
        $snapshot = $sources[$supplier];
        $this->append_disconnect_history($variation_id, $supplier, $snapshot, $reason);
        unset($sources[$supplier]);
        $this->save_variation_sources($variation_id, $sources);
        $this->purge_variation_supplier_meta($variation_id, $supplier);

        $active = array_filter($sources, static fn($v)=>is_array($v)&&!empty($v['enabled']));
        if (!$active) {
            $v = wc_get_product($variation_id);
            if ($v instanceof WC_Product_Variation) {
                $v->set_status('private');
                $v->set_stock_quantity(0);
                $v->set_stock_status('outofstock');
                $v->update_meta_data('_asss_stale_variation','yes');
                $v->update_meta_data('_asss_stale_variation_reason','no-active-supplier-source');
                $v->delete_meta_data('_asss_effective_supplier');
                $v->delete_meta_data('_asss_effective_inventory_qty');
                $v->save();
            }
        } else {
            $this->refresh_legacy_variation_meta($variation_id, $active);
            $this->recalculate_variation_inventory($variation_id);
        }
    }

    public function set_source_inventory(int $variation_id, string $supplier, int $supplier_qty, array $warehouses = [], array $extra = []): array {
        $supplier = sanitize_key($supplier);
        $sources = $this->variation_sources($variation_id);
        $source = is_array($sources[$supplier] ?? null) ? $sources[$supplier] : ['supplier'=>$supplier,'enabled'=>true];
        $source['inventory_qty'] = max(0, $supplier_qty);
        $source['warehouses'] = $warehouses;
        $source['last_inventory_sync'] = current_time('mysql');
        foreach ($extra as $key=>$value) $source[sanitize_key((string)$key)] = $value;
        $sources[$supplier] = $source;
        $this->save_variation_sources($variation_id, $sources);
        update_post_meta($variation_id, '_asss_inventory_' . $supplier . '_qty', max(0, $supplier_qty));
        return $this->recalculate_variation_inventory($variation_id);
    }

    public function recalculate_variation_inventory(int $variation_id): array {
        $v = wc_get_product($variation_id);
        if (!$v instanceof WC_Product_Variation) return ['quantity'=>0,'supplier'=>''];
        $sources = array_filter($this->variation_sources($variation_id), static fn($s)=>is_array($s) && !empty($s['enabled']));
        $settings = $this->settings();
        $parent_id=(int)$v->get_parent_id();
        if($parent_id>0){
            $override=sanitize_key((string)get_post_meta($parent_id,'_asss_inventory_strategy_override',true));
            if(in_array($override,['combined','preferred'],true))$settings['strategy']=$override;
            $preferred=sanitize_key((string)get_post_meta($parent_id,'_asss_preferred_supplier',true));
            if(in_array($preferred,['ss','sanmar','momentec'],true)){
                $settings['priority']=array_values(array_unique(array_merge([$preferred],$settings['priority'])));
            }
        }
        $buffer = max(0, (int)($this->sanmar->settings()['stock_buffer'] ?? 0));
        $available = [];
        foreach ($sources as $supplier=>$source) {
            $disc=$source['discontinued'] ?? false;
            if($disc===true || $disc===1 || $disc==='1' || $disc==='yes') continue;
            $raw = $source['inventory_qty'] ?? null;
            if ($raw === null || $raw === '' || !is_numeric($raw)) continue;
            $available[$supplier] = max(0, (int)$raw - $buffer);
        }

        $effective_supplier = '';
        foreach ($settings['priority'] as $supplier) {
            if (($available[$supplier] ?? 0) > 0) { $effective_supplier = $supplier; break; }
        }
        if ($effective_supplier === '' && $available) $effective_supplier = array_key_first($available);

        if ($settings['strategy'] === 'preferred') {
            $qty = $effective_supplier !== '' ? (int)($available[$effective_supplier] ?? 0) : 0;
        } else {
            $qty = array_sum($available);
        }

        $v->set_manage_stock(true);
        $v->set_backorders('no');
        $v->set_stock_quantity(max(0, $qty));
        $v->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        $v->update_meta_data('_asss_effective_inventory_qty', max(0, $qty));
        $v->update_meta_data('_asss_effective_supplier', $effective_supplier);
        $v->update_meta_data('_asss_effective_inventory_strategy', $settings['strategy']);
        $v->update_meta_data('_asss_last_inventory_sync', current_time('mysql'));
        $v->save();
        return ['quantity'=>max(0,$qty),'supplier'=>$effective_supplier,'available'=>$available,'strategy'=>$settings['strategy']];
    }

    public function recalculate_product_inventory(int $product_id): void {
        foreach ($this->variation_ids($product_id) as $variation_id) $this->recalculate_variation_inventory($variation_id);
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
    }

    public function product_coverage(int $product_id): array {
        $coverage = ['sanmar'=>0,'ss'=>0,'momentec'=>0,'both'=>0,'total'=>0];
        foreach ($this->variation_ids($product_id) as $variation_id) {
            $active = array_keys(array_filter($this->variation_sources($variation_id), static fn($v)=>is_array($v)&&!empty($v['enabled'])));
            if (!$active) continue;
            $coverage['total']++;
            if (in_array('sanmar',$active,true)) $coverage['sanmar']++;
            if (in_array('ss',$active,true)) $coverage['ss']++;
            if (in_array('momentec',$active,true)) $coverage['momentec']++;
            if (count($active) > 1) $coverage['both']++;
        }
        $coverage['sanmar_only'] = max(0, $coverage['sanmar'] - $coverage['both']);
        $coverage['ss_only'] = max(0, $coverage['ss'] - $coverage['both']);
        return $coverage;
    }

    public function variation_intelligence(int $variation_id): array {
        $sources = array_filter($this->variation_sources($variation_id), static fn($v)=>is_array($v)&&!empty($v['enabled']));
        $out = [];
        foreach ($sources as $supplier=>$source) {
            $cost = $source['cost'] ?? $source['customer_price'] ?? '';
            $qty = $source['inventory_qty'] ?? '';
            $out[$supplier] = [
                'label'=>self::suppliers()[$supplier] ?? $supplier,
                'quantity'=>is_numeric($qty)?(int)$qty:null,
                'cost'=>is_numeric($cost)?(float)$cost:null,
                'sku'=>(string)($source['sku'] ?? $source['unique_key'] ?? $source['inventory_key'] ?? ''),
                'gtin'=>(string)($source['gtin'] ?? ''),
            ];
        }
        return $out;
    }

    public function source_counts(): array {
        $counts = ['sanmar_products'=>0,'ss_products'=>0,'momentec_products'=>0,'multi_products'=>0,'total_products'=>0];
        $q = new WP_Query(['post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'meta_query'=>[['key'=>'_asss_sync_enabled','value'=>'yes']]]);
        foreach ($q->posts as $id) {
            $sources = array_keys(array_filter($this->product_sources((int)$id), static fn($v)=>is_array($v)&&!empty($v['enabled'])));
            if (!$sources) continue;
            $counts['total_products']++;
            if (in_array('sanmar',$sources,true)) $counts['sanmar_products']++;
            if (in_array('ss',$sources,true)) $counts['ss_products']++;
            if (in_array('momentec',$sources,true)) $counts['momentec_products']++;
            if (count($sources)>1) $counts['multi_products']++;
        }
        return $counts;
    }

    private function append_disconnect_history(int $post_id, string $supplier, array $snapshot, string $reason): void {
        $raw = get_post_meta($post_id, '_asss_disconnected_source_history', true);
        $history = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($history)) $history = [];
        $history[] = [
            'supplier'=>$supplier,
            'disconnected_at'=>current_time('mysql'),
            'reason'=>sanitize_text_field($reason),
            'identity'=>array_filter([
                'brand'=>$snapshot['brand'] ?? '', 'style'=>$snapshot['style'] ?? '',
                'brand_id'=>$snapshot['brand_id'] ?? '', 'style_id'=>$snapshot['style_id'] ?? '',
                'sku'=>$snapshot['sku'] ?? '', 'sku_id'=>$snapshot['sku_id'] ?? '',
                'unique_key'=>$snapshot['unique_key'] ?? '',
            ], static fn($v)=>$v!=='' && $v!==null),
        ];
        if (count($history) > 20) $history = array_slice($history, -20);
        update_post_meta($post_id, '_asss_disconnected_source_history', wp_json_encode($history));
    }

    private function purge_product_supplier_meta(int $product_id, string $supplier): void {
        if ($supplier === 'ss') {
            $keys = [
                '_asss_ss_brand','_asss_ss_brand_id','_asss_ss_style','_asss_ss_style_id','_asss_ss_specs','_asss_ss_part_number',
                '_asss_ss_color_selection_mode','_asss_ss_selected_colors','_asss_ss_new_style','_asss_ss_sustainable_style','_asss_e_retailing_restricted',
                '_asss_supplier_category_ids_ss','_asss_supplier_categories_ss',
            ];
        } elseif ($supplier === 'momentec') {
            $keys = ['_asss_momentec_brand','_asss_momentec_brand_id','_asss_momentec_style','_asss_momentec_style_id','_asss_momentec_color_selection_mode','_asss_momentec_selected_colors','_asss_supplier_category_ids_momentec','_asss_supplier_categories_momentec'];
        } else {
            $keys = [
                '_asss_sanmar_brand','_asss_sanmar_style','_asss_sanmar_color_selection_mode','_asss_sanmar_selected_colors',
                '_asss_supplier_category_ids_sanmar','_asss_supplier_categories_sanmar','_asss_last_product_file','_asss_spec_sheet',
            ];
        }
        foreach ($keys as $key) delete_post_meta($product_id, $key);
    }

    private function purge_variation_supplier_meta(int $variation_id, string $supplier): void {
        if ($supplier === 'ss') {
            $keys = [
                '_asss_ss_sku','_asss_ss_sku_id','_asss_ss_gtin','_asss_ss_color','_asss_ss_size','_asss_ss_customer_price','_asss_ss_piece_price',
                '_asss_ss_dozen_price','_asss_ss_case_price','_asss_ss_sale_price','_asss_ss_map_price','_asss_ss_retail_price','_asss_ss_country_of_origin',
                '_asss_ss_case_qty','_asss_ss_case_weight_lb','_asss_ss_case_dimensions','_asss_ss_warehouses','_asss_ss_inventory_warehouses','_asss_inventory_ss_qty',
            ];
        } elseif ($supplier === 'momentec') {
            $keys = ['_asss_momentec_sku','_asss_momentec_sku_id','_asss_momentec_gtin','_asss_momentec_color','_asss_momentec_size','_asss_momentec_cost','_asss_momentec_map_price','_asss_momentec_retail_price','_asss_momentec_warehouses','_asss_inventory_momentec_qty'];
        } else {
            $keys = [
                '_asss_sanmar_style','_asss_sanmar_brand','_asss_sanmar_unique_key','_asss_sanmar_inventory_key','_asss_sanmar_size_index','_asss_sanmar_color',
                '_asss_sanmar_size','_asss_sanmar_cost','_asss_sanmar_case_price','_asss_sanmar_sale_price','_asss_sanmar_map_price','_asss_sanmar_discontinued_code',
                '_asss_inventory_sanmar_qty',
            ];
        }
        foreach ($keys as $key) delete_post_meta($variation_id, $key);
    }

    private function refresh_legacy_product_meta(int $product_id, array $active): void {
        $priority = $this->settings()['priority'];
        $chosen = '';
        foreach ($priority as $supplier) if (!empty($active[$supplier])) { $chosen = $supplier; break; }
        if ($chosen === '' && $active) $chosen = (string)array_key_first($active);
        if ($chosen === '') return;
        $source = $active[$chosen];
        update_post_meta($product_id, '_asss_color_selection_mode', (string)($source['selection_mode'] ?? 'all'));
        update_post_meta($product_id, '_asss_selected_colors', wp_json_encode((array)($source['selected_colors'] ?? [])));
        if ($chosen === 'ss') update_post_meta($product_id, '_asss_supplier_product_key', 'ss|' . absint($source['brand_id'] ?? 0) . '|' . absint($source['style_id'] ?? 0));
        elseif ($chosen === 'momentec') update_post_meta($product_id, '_asss_supplier_product_key', 'momentec|' . strtolower(trim((string)($source['brand'] ?? ''))) . '|' . strtolower(trim((string)($source['style'] ?? ''))));
        else update_post_meta($product_id, '_asss_supplier_product_key', 'sanmar|' . strtolower(trim((string)($source['brand'] ?? ''))) . '|' . strtolower(trim((string)($source['style'] ?? ''))));
    }

    private function refresh_legacy_variation_meta(int $variation_id, array $active): void {
        $priority = $this->settings()['priority'];
        $chosen = '';
        foreach ($priority as $supplier) if (!empty($active[$supplier])) { $chosen = $supplier; break; }
        if ($chosen === '' && $active) $chosen = (string)array_key_first($active);
        if ($chosen === '') return;
        $source = $active[$chosen];
        $map = $source['map_price'] ?? '';
        $cost = $source['cost'] ?? $source['customer_price'] ?? '';
        if ($cost !== '') update_post_meta($variation_id, '_asss_supplier_cost', (string)$cost); else delete_post_meta($variation_id, '_asss_supplier_cost');
        if ($map !== '') update_post_meta($variation_id, '_asss_map_price', (string)$map); else delete_post_meta($variation_id, '_asss_map_price');
        if ($chosen === 'ss') {
            $retail = $source['retail_price'] ?? '';
            if ($retail !== '') update_post_meta($variation_id, '_asss_suggested_retail_price', (string)$retail);
        }
    }

    private function variation_ids(int $product_id): array {
        return array_map('intval', get_posts([
            'post_type'=>'product_variation','post_status'=>['publish','private','draft','pending'],
            'post_parent'=>$product_id,'fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
        ]));
    }

    private function decode_list(string $raw): array {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval',$decoded))) : [];
    }

    private function nullable_int($primary, $fallback = null): ?int {
        foreach ([$primary,$fallback] as $value) if ($value !== '' && $value !== null && is_numeric($value)) return max(0,(int)$value);
        return null;
    }
}
