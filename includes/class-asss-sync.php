<?php
if (!defined('ABSPATH')) exit;

class ASSS_Sync {
    private ASSS_SanMar $sanmar;
    private ASSS_Importer $importer;
    private ASSS_MultiSupplier $multi;

    public function __construct(ASSS_SanMar $sanmar, ASSS_Importer $importer, ASSS_MultiSupplier $multi) {
        $this->sanmar = $sanmar;
        $this->importer = $importer;
        $this->multi = $multi;
        add_action('asss_hourly_inventory', [$this, 'inventory_sync']);
        add_action('asss_daily_products', [$this, 'daily_product_sync']);
        add_action('asss_delayed_product_ingest', [$this, 'product_sync']);
        add_action('asss_repair_product', [$this, 'repair_product_job'], 10, 1);
    }

    public function repair_product_job(int $product_id): void {
        $product_id=absint($product_id);
        if(!$product_id || !in_array((string)get_post_meta($product_id,'_asss_supplier',true), ['sanmar','ss','multi'], true) || (string)get_post_meta($product_id,'_asss_sync_enabled',true)!=='yes') return;
        $r=$this->importer->update_style($product_id);
        if(is_wp_error($r)) {
            ASSS_Logger::log('Queued supplier product repair failed','error',['product_id'=>$product_id,'error'=>$r->get_error_message()]);
        } else {
            ASSS_Logger::log('Queued supplier product repair completed','info',['product_id'=>$product_id]);
        }
    }

    public function queue_product_repairs(array $product_ids): array {
        $queued=0; $skipped=0;
        $ids=array_values(array_unique(array_filter(array_map('absint',$product_ids))));
        foreach($ids as $index=>$product_id) {
            if(!in_array((string)get_post_meta($product_id,'_asss_supplier',true), ['sanmar','ss','multi'], true) || (string)get_post_meta($product_id,'_asss_sync_enabled',true)!=='yes') { $skipped++; continue; }
            $already=false;
            if(function_exists('as_next_scheduled_action')) {
                $already=(bool)as_next_scheduled_action('asss_repair_product',[$product_id],'all-star-supplier-sync');
            } else {
                $already=(bool)wp_next_scheduled('asss_repair_product',[$product_id]);
            }
            if($already) { $skipped++; continue; }

            if(function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('asss_repair_product',[$product_id],'all-star-supplier-sync',true);
            } else {
                wp_schedule_single_event(time()+10+($index*15),'asss_repair_product',[$product_id]);
            }
            $queued++;
        }
        if($queued && function_exists('spawn_cron')) spawn_cron();
        return compact('queued','skipped');
    }

    public function queue_brand_product_sync(string $brand): array {
        $ids=[];
        foreach($this->linked_product_ids(false, 'sanmar') as $id) {
            $linked_brand=(string)get_post_meta($id,'_asss_sanmar_brand',true);
            if(strcasecmp($linked_brand,$brand)===0) $ids[]=(int)$id;
        }
        $result=$this->queue_product_repairs($ids);
        ASSS_Logger::log('Bridge brand product repairs queued','info',['brand'=>$brand,'queued'=>$result['queued'],'skipped'=>$result['skipped']]);
        return $result;
    }

    public function linked_product_ids(bool $include_archived = true, string $supplier = ''): array {
        $q = new WP_Query([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,
            'meta_query'=>[['key'=>'_asss_sync_enabled','value'=>'yes']],
        ]);
        $ids=[];
        foreach ($q->posts as $id) {
            $id=(int)$id;
            $linked=(string)get_post_meta($id,'_asss_supplier',true);
            if (!in_array($linked, ['sanmar','ss','multi'], true)) continue;
            if ($supplier !== '') {
                $sources=$this->multi->product_sources($id);
                if (empty($sources[$supplier]['enabled'])) continue;
            }
            if (!$include_archived && (string)get_post_meta($id,'_asss_supplier_archived',true)==='yes') continue;
            $ids[]=$id;
        }
        return $ids;
    }

    public function queue_ss_brand_product_sync(int $brand_id): array {
        $ids=[];
        foreach($this->linked_product_ids(false, 'ss') as $id) {
            if(absint(get_post_meta($id,'_asss_ss_brand_id',true))===$brand_id) $ids[]=(int)$id;
        }
        $result=$this->queue_product_repairs($ids);
        ASSS_Logger::log('S&S brand product repairs queued','info',['brand_id'=>$brand_id,'queued'=>$result['queued'],'skipped'=>$result['skipped']]);
        return $result;
    }

    public function brands_in_use(): array {
        $out=[];
        foreach ($this->linked_product_ids(true, 'sanmar') as $id) {
            $b = get_post_meta($id, '_asss_sanmar_brand', true);
            if ($b) $out[$b]=1;
        }
        return array_keys($out);
    }

    public function daily_product_sync(): void {
        $s=$this->sanmar->settings();
        if(empty($s['daily_product_sync'])) return;
        ASSS_Logger::log('Daily product sync started');
        if(!empty($s['request_brand_files'])) {
            foreach($this->brands_in_use() as $brand) {
                $r=$this->sanmar->request_brand_file($brand);
                if(is_wp_error($r)) ASSS_Logger::log('Brand refresh request failed','error',['brand'=>$brand,'error'=>$r->get_error_message()]);
                else ASSS_Logger::log('Requested fresh SanMar brand file','info',['brand'=>$brand]);
            }
            wp_schedule_single_event(time()+30*MINUTE_IN_SECONDS,'asss_delayed_product_ingest');
        } else {
            $this->product_sync();
        }
    }

    public function product_sync_brand(string $brand): array {
        $ok=0; $fail=0;
        foreach($this->linked_product_ids(true, 'sanmar') as $id) {
            $linked_brand=(string)get_post_meta($id,'_asss_sanmar_brand',true);
            if(strcasecmp($linked_brand,$brand)!==0) continue;
            $r=$this->importer->update_style($id);
            if(is_wp_error($r)) {
                ASSS_Logger::log('Bridge product sync failed','error',['brand'=>$brand,'product_id'=>$id,'error'=>$r->get_error_message()]);
                $fail++;
            } else $ok++;
        }
        ASSS_Logger::log('Bridge brand product sync complete','info',['brand'=>$brand,'updated'=>$ok,'failed'=>$fail]);
        return compact('ok','fail');
    }

    public function product_sync(): array {
        $ok=0; $fail=0;
        foreach($this->linked_product_ids() as $id) {
            $r=$this->importer->update_style($id);
            if(is_wp_error($r)) {
                ASSS_Logger::log('Product sync failed','error',['product_id'=>$id,'error'=>$r->get_error_message()]);
                $fail++;
            } else $ok++;
        }
        ASSS_Logger::log('Product sync complete','info',['updated'=>$ok,'failed'=>$fail]);
        return compact('ok','fail');
    }

    /**
     * Return only variations belonging to linked supplier products. GitHub uses
     * this compact list to filter SanMar's full inventory feed before POSTing
     * inventory back to WordPress.
     */
    public function inventory_targets(): array {
        $targets=[];
        foreach ($this->linked_product_ids(false, 'sanmar') as $parent_id) {
            $parent=wc_get_product($parent_id);
            if (!$parent instanceof WC_Product_Variable) continue;
            $ids=get_posts([
                'post_type'=>'product_variation','post_status'=>['publish','private','draft','pending'],
                'post_parent'=>$parent_id,'fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            ]);
            foreach ($ids as $variation_id) {
                $v=wc_get_product((int)$variation_id);
                if (!$v instanceof WC_Product_Variation) continue;
                if (empty($this->multi->variation_sources((int)$variation_id)['sanmar']['enabled'])) continue;
                if ((string)$v->get_meta('_asss_stale_variation')==='yes') continue;
                if ((string)$v->get_meta('_asss_discontinued_variation')==='yes') continue;
                if ($v->get_status('edit')!=='publish') continue;
                $src=$this->multi->variation_sources((int)$variation_id)['sanmar'] ?? [];
                $target=[
                    'variation_id'=>(int)$variation_id,'product_id'=>(int)$parent_id,
                    'unique_key'=>(string)($src['unique_key'] ?? $v->get_meta('_asss_sanmar_unique_key')),
                    'inventory_key'=>(string)($src['inventory_key'] ?? $v->get_meta('_asss_sanmar_inventory_key')),
                    'size_index'=>(string)($src['size_index'] ?? $v->get_meta('_asss_sanmar_size_index')),
                    'style'=>(string)($src['style'] ?? $v->get_meta('_asss_sanmar_style')),
                    'color'=>(string)($src['color'] ?? $v->get_meta('_asss_sanmar_color')),
                    'size'=>(string)($src['size'] ?? $v->get_meta('_asss_sanmar_size')),
                ];
                if ($target['unique_key']==='' && $target['inventory_key']==='' && $target['style']==='') continue;
                $targets[]=$target;
            }
        }
        return $targets;
    }

    /**
     * Return only active exact S&S SKU variations currently sellable on this
     * store. The hourly GitHub workflow uses these supplier SKUs to make small,
     * targeted Inventory API requests instead of downloading the S&S catalog.
     */
    public function inventory_targets_ss(): array {
        $targets = [];
        foreach ($this->linked_product_ids(false, 'ss') as $parent_id) {
            $parent = wc_get_product($parent_id);
            if (!$parent instanceof WC_Product_Variable) continue;
            $ids = get_posts([
                'post_type'=>'product_variation','post_status'=>['publish','private','draft','pending'],
                'post_parent'=>$parent_id,'fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            ]);
            foreach ($ids as $variation_id) {
                $v = wc_get_product((int)$variation_id);
                if (!$v instanceof WC_Product_Variation) continue;
                if (empty($this->multi->variation_sources((int)$variation_id)['ss']['enabled'])) continue;
                if ((string)$v->get_meta('_asss_stale_variation') === 'yes') continue;
                if ((string)$v->get_meta('_asss_discontinued_variation') === 'yes') continue;
                if ($v->get_status('edit') !== 'publish') continue;

                $src=$this->multi->variation_sources((int)$variation_id)['ss'] ?? [];
                $target = [
                    'variation_id'=>(int)$variation_id,
                    'product_id'=>(int)$parent_id,
                    'sku_id'=>(string)($src['sku_id'] ?? $v->get_meta('_asss_ss_sku_id')),
                    'sku'=>(string)($src['sku'] ?? $v->get_meta('_asss_ss_sku')),
                    'gtin'=>(string)($src['gtin'] ?? $v->get_meta('_asss_ss_gtin')),
                    'brand_id'=>absint(get_post_meta($parent_id, '_asss_ss_brand_id', true)),
                    'style_id'=>absint(get_post_meta($parent_id, '_asss_ss_style_id', true)),
                    'style'=>(string)get_post_meta($parent_id, '_asss_ss_style', true),
                    'color'=>(string)($src['color'] ?? $v->get_meta('_asss_ss_color')),
                    'size'=>(string)($src['size'] ?? $v->get_meta('_asss_ss_size')),
                ];
                if ($target['sku'] === '' && $target['sku_id'] === '' && $target['gtin'] === '') continue;
                $targets[] = $target;
            }
        }
        return $targets;
    }

    /**
     * Apply one complete, pre-normalized S&S inventory snapshot. The payload is
     * required to cover the current active target set exactly before any stock is
     * changed. Missing API rows are never interpreted as zero inventory.
     */
    public function apply_ss_inventory_payload(array $rows, array $meta=[]): array {
        $targets = $this->inventory_targets_ss();
        if (!$targets) return new WP_Error('no_inventory_targets', 'No linked active S&S variations are available for inventory sync.');

        $by_id = [];
        foreach ($targets as $target) $by_id[(int)$target['variation_id']] = $target;
        $expected_ids = array_keys($by_id);
        sort($expected_ids, SORT_NUMERIC);

        $prepared = [];
        foreach ($rows as $row) {
            if (!is_array($row)) return new WP_Error('invalid_inventory_row', 'S&S inventory payload contains a non-object row.', ['status'=>400]);
            $vid = absint($row['variation_id'] ?? 0);
            if (!$vid || !isset($by_id[$vid])) {
                return new WP_Error('unexpected_inventory_target', 'S&S inventory payload contains a variation that is not an active S&S target: ' . $vid, ['status'=>409]);
            }
            if (isset($prepared[$vid])) {
                return new WP_Error('duplicate_inventory_target', 'S&S inventory payload contains duplicate variation #' . $vid . '.', ['status'=>409]);
            }
            $target = $by_id[$vid];
            $row_sku = sanitize_text_field((string)($row['sku'] ?? ''));
            $row_sku_id = sanitize_text_field((string)($row['sku_id'] ?? ''));
            $row_gtin = sanitize_text_field((string)($row['gtin'] ?? ''));
            if ((string)$target['sku'] !== '' && $row_sku !== '' && strcasecmp((string)$target['sku'], $row_sku) !== 0) {
                return new WP_Error('inventory_identity_mismatch', 'S&S SKU mismatch for Woo variation #' . $vid . '.', ['status'=>409]);
            }
            if ((string)$target['sku_id'] !== '' && $row_sku_id !== '' && (string)$target['sku_id'] !== $row_sku_id) {
                return new WP_Error('inventory_identity_mismatch', 'S&S permanent SKU ID mismatch for Woo variation #' . $vid . '.', ['status'=>409]);
            }
            if ((string)$target['gtin'] !== '' && $row_gtin !== '' && (string)$target['gtin'] !== $row_gtin) {
                return new WP_Error('inventory_identity_mismatch', 'S&S GTIN mismatch for Woo variation #' . $vid . '.', ['status'=>409]);
            }
            $raw_qty = $row['quantity'] ?? null;
            if ($raw_qty === null || $raw_qty === '' || !is_numeric($raw_qty)) {
                return new WP_Error('invalid_inventory_quantity', 'S&S inventory quantity is missing or non-numeric for Woo variation #' . $vid . '.', ['status'=>400]);
            }
            $warehouses = [];
            foreach ((array)($row['warehouses'] ?? []) as $wh) {
                if (!is_array($wh)) continue;
                $wh_qty = $wh['quantity'] ?? null;
                if ($wh_qty === null || $wh_qty === '' || !is_numeric($wh_qty)) {
                    return new WP_Error('invalid_warehouse_quantity', 'S&S warehouse quantity is non-numeric for Woo variation #' . $vid . '.', ['status'=>400]);
                }
                $warehouses[] = [
                    'warehouse'=>sanitize_text_field((string)($wh['warehouse'] ?? '')),
                    'sku_id'=>absint($wh['sku_id'] ?? 0),
                    'quantity'=>max(0, (int)$wh_qty),
                ];
            }
            $prepared[$vid] = [
                'quantity'=>max(0, (int)$raw_qty),
                'warehouses'=>$warehouses,
            ];
        }

        $received_ids = array_keys($prepared);
        sort($received_ids, SORT_NUMERIC);
        if ($expected_ids !== $received_ids) {
            $missing = array_values(array_diff($expected_ids, $received_ids));
            $unexpected = array_values(array_diff($received_ids, $expected_ids));
            return new WP_Error(
                'incomplete_inventory_coverage',
                'S&S inventory coverage changed before apply. Missing: ' . count($missing) . '; unexpected: ' . count($unexpected) . '. No stock was changed.',
                ['status'=>409]
            );
        }
        $declared_target_count = absint($meta['target_count'] ?? 0);
        if ($declared_target_count && $declared_target_count !== count($expected_ids)) {
            return new WP_Error('inventory_target_count_changed', 'S&S target count changed before apply. No stock was changed.', ['status'=>409]);
        }

        // Preload every variation before writing anything. This gives us a second
        // all-or-nothing guard if an admin or repair job changed the product while
        // the GitHub request was in flight.
        $variation_objects = [];
        foreach ($prepared as $vid=>$incoming) {
            $v = wc_get_product((int)$vid);
            if (!$v instanceof WC_Product_Variation || empty($this->multi->variation_sources((int)$vid)['ss']['enabled'])) {
                return new WP_Error('inventory_target_changed', 'S&S variation #' . (int)$vid . ' changed before inventory apply. No stock was changed.', ['status'=>409]);
            }
            $variation_objects[(int)$vid] = $v;
        }

        $settings = $this->sanmar->settings();
        $buffer = max(0, (int)($settings['stock_buffer'] ?? 0));
        $matched = 0; $changed = 0; $unchanged = 0; $unmatched = 0;
        $parents = [];
        foreach ($prepared as $vid=>$incoming) {
            $v = $variation_objects[(int)$vid];
            $supplier_qty = (int)$incoming['quantity'];
            $qty = max(0, $supplier_qty - $buffer);
            $before = (int)$v->get_stock_quantity();
            $before_status = (string)$v->get_stock_status();
            $after_status = $qty > 0 ? 'instock' : 'outofstock';

            $result=$this->multi->set_source_inventory((int)$vid,'ss',$supplier_qty,$incoming['warehouses'],['source'=>sanitize_text_field((string)($meta['source'] ?? 'ss-inventory-api'))]);
            $v=wc_get_product((int)$vid);
            if($v instanceof WC_Product_Variation){
                $v->update_meta_data('_asss_supplier_inventory_qty', $supplier_qty);
                $v->update_meta_data('_asss_inventory_source', sanitize_text_field((string)($meta['source'] ?? 'ss-inventory-api')));
                $v->update_meta_data('_asss_ss_inventory_warehouses', wp_json_encode($incoming['warehouses']));
                $v->save();
            }
            $qty=(int)($result['quantity'] ?? $qty);
            $after_status=$qty>0?'instock':'outofstock';

            $matched++;
            if ($before !== $qty || $before_status !== $after_status) $changed++; else $unchanged++;
            $parents[$v->get_parent_id()] = 1;
        }

        // If a product was repaired/changed between validation and saving, fail
        // loudly in the log. The workflow will also reject any unmatched count.
        foreach (array_keys($parents) as $parent_id) {
            if ($parent_id > 0) {
                WC_Product_Variable::sync((int)$parent_id);
                wc_delete_product_transients((int)$parent_id);
            }
        }

        $status = [
            'received_at'=>current_time('mysql'),
            'source'=>sanitize_text_field((string)($meta['source'] ?? 'ss-inventory-api')),
            'source_timestamp'=>sanitize_text_field((string)($meta['source_timestamp'] ?? '')),
            'target_count'=>absint($meta['target_count'] ?? count($targets)),
            'api_requests'=>absint($meta['api_requests'] ?? 0),
            'rate_limit_remaining'=>isset($meta['rate_limit_remaining']) && is_numeric($meta['rate_limit_remaining']) ? (int)$meta['rate_limit_remaining'] : null,
            'rows_received'=>count($rows),
            'matched'=>$matched,
            'changed'=>$changed,
            'unchanged'=>$unchanged,
            'unmatched'=>$unmatched,
            'stock_buffer'=>$buffer,
        ];
        update_option('asss_ss_inventory_bridge_status', $status, false);
        ASSS_Logger::log('S&S GitHub inventory bridge sync complete', $unmatched ? 'warning' : 'info', $status);

        return [
            'rows_received'=>count($rows),
            'matched'=>$matched,
            'changed'=>$changed,
            'unchanged'=>$unchanged,
            'unmatched'=>$unmatched,
            'stock_buffer'=>$buffer,
            'message'=>'S&S inventory updates applied to exact linked WooCommerce variations.',
        ];
    }

    private function variation_lookup(): array {
        $out=[]; $inventory_candidates=[];
        foreach ($this->inventory_targets() as $r) {
            $vid=(int)$r['variation_id'];
            if ($r['unique_key'] !== '') $out['u:'.$r['unique_key']]=$vid;
            if ($r['inventory_key'] !== '' && $r['size_index'] !== '') {
                $out['is:'.$r['inventory_key'].'|'.$r['size_index']]=$vid;
            }
            if ($r['inventory_key'] !== '') $inventory_candidates[$r['inventory_key']][]=$vid;
            if ($r['style'] !== '' || $r['color'] !== '' || $r['size'] !== '') {
                $out['s:'.strtolower($r['style'].'|'.$r['color'].'|'.$r['size'])]=$vid;
            }
        }
        // INVENTORY_KEY can represent more than one size. Only permit a bare
        // inventory-key match when it maps unambiguously to one variation.
        foreach ($inventory_candidates as $key=>$ids) {
            $ids=array_values(array_unique(array_map('intval',$ids)));
            if(count($ids)===1) $out['i:'.$key]=$ids[0];
        }
        return $out;
    }

    private function match_inventory_row(array $row, array $managed): int {
        $unique = sanitize_text_field((string)($row['unique_key'] ?? $row['UNIQUE_KEY'] ?? ''));
        $inv = sanitize_text_field((string)($row['inventory_key'] ?? $row['INVENTORY_KEY'] ?? ''));
        $size_index = sanitize_text_field((string)($row['size_index'] ?? $row['SIZE_INDEX'] ?? ''));
        $style = sanitize_text_field((string)($row['style'] ?? $row['STYLE'] ?? $row['STYLE#'] ?? ''));
        $color = sanitize_text_field((string)($row['color'] ?? $row['COLOR_NAME'] ?? $row['COLOR'] ?? $row['CATALOG_COLOR'] ?? ''));
        $size = sanitize_text_field((string)($row['size'] ?? $row['SIZE'] ?? ''));
        $keys=array_filter([
            $unique !== '' ? 'u:'.$unique : '',
            ($inv !== '' && $size_index !== '') ? 'is:'.$inv.'|'.$size_index : '',
            $inv !== '' ? 'i:'.$inv : '',
            ($style !== '' || $color !== '' || $size !== '') ? 's:'.strtolower($style.'|'.$color.'|'.$size) : '',
        ]);
        foreach($keys as $key) if(isset($managed[$key])) return (int)$managed[$key];
        return 0;
    }

    /**
     * Apply normalized inventory from GitHub. Missing rows are never interpreted
     * as zero stock; only explicit supplier quantities are written. This avoids
     * emptying the store if SanMar publishes a partial or malformed feed.
     */
    public function apply_inventory_payload(array $rows, array $meta=[]): array {
        $managed=$this->variation_lookup();
        if(!$managed) return new WP_Error('no_inventory_targets','No linked SanMar variations are available for inventory sync.');

        $settings=$this->sanmar->settings();
        $buffer=max(0,(int)($settings['stock_buffer'] ?? 0));
        $matched=0; $changed=0; $unchanged=0; $unmatched=0; $discontinued=0;
        $parents=[]; $seen_variations=[]; $parent_disc=[]; $parent_seen=[];

        foreach($rows as $row) {
            if(!is_array($row)) continue;
            $vid=$this->match_inventory_row($row,$managed);
            if(!$vid) { $unmatched++; continue; }

            // A variation should appear only once in the normalized payload. If a
            // malformed payload repeats it, use the first row rather than double-
            // applying or producing confusing log counts.
            if(isset($seen_variations[$vid])) continue;
            $seen_variations[$vid]=1;

            $raw_qty=$row['quantity'] ?? $row['QUANTITY'] ?? null;
            if($raw_qty === null || $raw_qty === '' || !is_numeric($raw_qty)) { $unmatched++; continue; }
            $supplier_qty=max(0,(int)$raw_qty);
            $disc_code=strtoupper(sanitize_text_field((string)($row['discontinued_code'] ?? $row['DISCONTINUED_CODE'] ?? '')));
            $is_discontinued=!empty($row['discontinued']) || ($disc_code==='S' && $supplier_qty===0);
            if($is_discontinued) $discontinued++;
            $parent_id_for_disc=(int)get_post_field('post_parent',$vid);
            if($parent_id_for_disc>0){ $parent_seen[$parent_id_for_disc]=($parent_seen[$parent_id_for_disc]??0)+1; if($is_discontinued) $parent_disc[$parent_id_for_disc]=($parent_disc[$parent_id_for_disc]??0)+1; }
            $qty=max(0,$supplier_qty-$buffer);

            $v=wc_get_product($vid);
            if(!$v instanceof WC_Product_Variation) { $unmatched++; continue; }
            $matched++;
            $before=(int)$v->get_stock_quantity();
            $before_status=(string)$v->get_stock_status();
            $after_status=$qty>0?'instock':'outofstock';

            if($is_discontinued) {
                $sources=$this->multi->variation_sources($vid);
                if(isset($sources['sanmar'])){
                    $sources['sanmar']['discontinued']=true;
                    $sources['sanmar']['discontinued_code']=$disc_code;
                    $sources['sanmar']['inventory_qty']=$supplier_qty;
                    $this->multi->save_variation_sources($vid,$sources);
                }
            }
            $result=$this->multi->set_source_inventory($vid,'sanmar',$supplier_qty,[],[
                'source'=>sanitize_text_field((string)($meta['source'] ?? 'github-actions')),
                'discontinued'=>$is_discontinued,
                'discontinued_code'=>$disc_code,
            ]);
            $v=wc_get_product($vid);
            $qty=(int)($result['quantity'] ?? $qty);
            $after_status=$qty>0?'instock':'outofstock';
            if($v instanceof WC_Product_Variation){
                $active_sources=array_filter($this->multi->variation_sources($vid),static function($src){if(!is_array($src)||empty($src['enabled']))return false;$d=$src['discontinued']??false;return !($d===true||$d===1||$d==='1'||$d==='yes');});
                if($is_discontinued && !$active_sources){
                    $v->set_status('private');
                    $v->update_meta_data('_asss_discontinued_variation','yes');
                    $v->update_meta_data('_asss_discontinued_variation_at',current_time('mysql'));
                } elseif(!$is_discontinued && (string)$v->get_meta('_asss_stale_variation')!=='yes') {
                    $v->set_status('publish');
                    $v->delete_meta_data('_asss_discontinued_variation');
                }
                $v->update_meta_data('_asss_supplier_inventory_qty',$supplier_qty);
                $v->update_meta_data('_asss_last_inventory_sync',current_time('mysql'));
                $v->update_meta_data('_asss_inventory_source',sanitize_text_field((string)($meta['source'] ?? 'github-actions')));
                $v->update_meta_data('_asss_sanmar_discontinued_code',$disc_code);
                $v->save();
            }

            if($before!==$qty || $before_status!==$after_status) $changed++; else $unchanged++;
            $parents[$v->get_parent_id()]=1;
        }

        foreach(array_keys($parents) as $parent_id) {
            if($parent_id>0) {
                WC_Product_Variable::sync((int)$parent_id);
                wc_delete_product_transients((int)$parent_id);
                if (($parent_seen[$parent_id] ?? 0) > 0 && ($parent_disc[$parent_id] ?? 0) === ($parent_seen[$parent_id] ?? 0)) {
                    $parent=wc_get_product((int)$parent_id);
                    $product_sources=$this->multi->product_sources((int)$parent_id);
                    $has_other=!empty($product_sources['ss']['enabled']);
                    if($parent && !$has_other) {
                        $already_archived=(string)$parent->get_meta('_asss_supplier_archived')==='yes';
                        $parent->set_status('draft');
                        $parent->set_catalog_visibility('hidden');
                        $parent->set_stock_status('outofstock');
                        $parent->update_meta_data('_asss_discontinued','yes');
                        $parent->update_meta_data('_asss_supplier_archived','yes');
                        if(!$already_archived) $parent->update_meta_data('_asss_supplier_archived_at',current_time('mysql'));
                        $parent->delete_meta_data('_asss_supplier_reactivated');
                        $parent->delete_meta_data('_asss_supplier_reactivated_at');
                        $parent->save();
                        if(!$already_archived) {
                            $to=$this->sanmar->settings()['admin_notify'] ?: get_option('admin_email');
                            wp_mail(
                                $to,
                                'Supplier product archived: '.$parent->get_name(),
                                "Every linked SanMar variation is discontinued and has zero supplier inventory. The product was moved to Draft, hidden from the catalog, and marked Out of stock.\n\nProduct: ".$parent->get_name()."\nEdit: ".admin_url('post.php?post='.$parent_id.'&action=edit')
                            );
                        }
                        ASSS_Logger::log('Product archived because all supplier variations are discontinued and out of stock','warning',['product_id'=>$parent_id,'new_archive'=>!$already_archived]);
                    }
                }
            }
        }

        $status=[
            'received_at'=>current_time('mysql'),
            'source'=>sanitize_text_field((string)($meta['source'] ?? 'github-actions')),
            'source_file'=>sanitize_text_field((string)($meta['source_file'] ?? '')),
            'source_timestamp'=>sanitize_text_field((string)($meta['source_timestamp'] ?? '')),
            'headers'=>array_values((array)($meta['headers'] ?? [])),
            'raw_rows_scanned'=>absint($meta['raw_rows_scanned'] ?? 0),
            'rows_received'=>count($rows),
            'matched'=>$matched,
            'changed'=>$changed,
            'unchanged'=>$unchanged,
            'unmatched'=>$unmatched,
            'discontinued_zero_stock'=>$discontinued,
            'stock_buffer'=>$buffer,
        ];
        update_option('asss_inventory_bridge_status',$status,false);
        ASSS_Logger::log('GitHub inventory bridge sync complete','info',$status);

        return [
            'rows_received'=>count($rows),
            'matched'=>$matched,
            'changed'=>$changed,
            'unchanged'=>$unchanged,
            'unmatched'=>$unmatched,
            'discontinued'=>$discontinued,
            'stock_buffer'=>$buffer,
            'message'=>'Inventory updates applied to linked WooCommerce variations.',
        ];
    }

    /**
     * Direct Host->SanMar inventory sync retained only as a fallback for hosts
     * that can reach SanMar's SFTP server. The GitHub bridge is preferred.
     */
    public function inventory_sync(): array {
        $s=$this->sanmar->settings();
        if(empty($s['hourly_inventory_sync'])) return ['ok'=>0,'fail'=>0];
        $managed=$this->variation_lookup();
        if(!$managed) { ASSS_Logger::log('Inventory sync skipped: no linked variations'); return ['ok'=>0,'fail'=>0]; }
        $file=$this->sanmar->inventory_file();
        if(is_wp_error($file)) {
            ASSS_Logger::log('Inventory file unavailable','error',['error'=>$file->get_error_message()]);
            return ['ok'=>0,'fail'=>1];
        }
        ASSS_Logger::log('Direct inventory sync started','info',['remote'=>$file['remote'],'headers'=>$file['headers']]);
        $rows=[];
        foreach($this->sanmar->iterate_csv($file['path']) as $row) {
            $unique=(string)$this->sanmar->first($row,['UNIQUE_KEY','UNIQUEKEY','PART_ID']);
            $inv=(string)$this->sanmar->first($row,['INVENTORY_KEY','INVENTORYKEY']);
            $style=(string)$this->sanmar->first($row,['STYLE#','STYLE']);
            $color=(string)$this->sanmar->first($row,['COLOR_NAME','COLOR','CATALOG_COLOR']);
            $size=(string)$this->sanmar->first($row,['SIZE']);
            $candidate=[
                'unique_key'=>$unique,
                'inventory_key'=>$inv,
                'size_index'=>(string)$this->sanmar->first($row,['SIZE_INDEX','SIZEINDEX']),
                'style'=>$style,
                'color'=>$color,
                'size'=>$size,
                'quantity'=>$this->sanmar->first($row,['QUANTITY','QTY','TOTAL_QTY','TOTAL_INVENTORY','INVENTORY_QTY'],''),
                'discontinued_code'=>(string)$this->sanmar->first($row,['DISCONTINUED_CODE','DISCONTINUED']),
            ];
            if($this->match_inventory_row($candidate,$managed)) $rows[]=$candidate;
        }
        @unlink($file['path']);
        if(!$rows) {
            ASSS_Logger::log('Direct inventory sync found no matching rows','warning',['remote'=>$file['remote']]);
            return ['ok'=>0,'fail'=>0];
        }
        $result=$this->apply_inventory_payload($rows,['source'=>'direct-file','source_file'=>$file['remote'],'headers'=>$file['headers']]);
        if(is_wp_error($result)) return ['ok'=>0,'fail'=>1];
        return ['ok'=>(int)$result['matched'],'fail'=>0];
    }
}
