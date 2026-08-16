<?php
if (!defined('ABSPATH')) exit;

class ASSS_Importer {
    private ASSS_SanMar $sanmar;
    private ASSS_SS $ss;
    private ASSS_Momentec $momentec;
    private ASSS_MultiSupplier $multi;
    /** @var array<string,int> Request-local image URL => attachment ID cache. */
    private array $sideload_cache = [];

    public function __construct(ASSS_SanMar $sanmar, ASSS_SS $ss, ASSS_Momentec $momentec, ASSS_MultiSupplier $multi) {
        $this->sanmar = $sanmar;
        $this->ss = $ss;
        $this->momentec = $momentec;
        $this->multi = $multi;
        add_action('asss_momentec_variation_media_job', [$this, 'momentec_variation_media_job'], 10, 2);
        add_action('asss_momentec_parent_media_job', [$this, 'momentec_parent_media_job'], 10, 1);
        add_action('admin_init', [$this, 'migrate_supplier_categories_v2015'], 35);
        add_action('admin_init', [$this, 'migrate_storefront_media_and_sizes_v2018'], 36);
        add_action('asss_product_synced', [$this, 'normalize_product_storefront_sizes'], 45, 2);
        add_action('asss_product_synced', [$this, 'cleanup_invalid_supplier_storefront_media'], 50, 2);
    }

    private function supplier_product_key(string $brand, string $style): string {
        return 'sanmar|' . strtolower(trim($brand)) . '|' . strtolower(trim($style));
    }

    private function canonical_combo(string $color, string $size): string {
        $norm = static fn(string $v): string => strtolower(trim(preg_replace('/\s+/u', ' ', $v)));
        return $norm($color) . '|' . $norm($size);
    }

    /**
     * Verified, style-specific supplier size aliases used only while deciding
     * whether two supplier rows can share one WooCommerce variation.
     *
     * Raw supplier labels are always retained in each source record. A mapping
     * here never rewrites SanMar/S&S data and never applies across other styles.
     */
    private function canonical_supplier_size(string $brand, string $style, string $size): string {
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
     * Supplier descriptions are managed until the merchant edits them. Once a
     * manual edit is detected, future feeds are recorded as available but do not
     * overwrite the merchant's copy.
     */
    private function sync_supplier_description(WC_Product $product, string $description, bool $is_new = false): void {
        $description = trim($description);
        if ($description === '') return;
        $incoming = wp_kses_post($description);
        $incoming_hash = hash('sha256', $incoming);
        $current = (string)$product->get_description('edit');
        $previous_hash = (string)$product->get_meta('_asss_supplier_description_hash');
        $current_hash = hash('sha256', $current);

        if ($is_new || $current === '' || ($previous_hash !== '' && hash_equals($previous_hash, $current_hash))) {
            $product->set_description($incoming);
            $product->update_meta_data('_asss_supplier_description_hash', $incoming_hash);
            $product->delete_meta_data('_asss_supplier_description_update_available');
        } elseif ($previous_hash === '' && $current_hash === $incoming_hash) {
            // Adopt legacy supplier text as managed without changing it.
            $product->update_meta_data('_asss_supplier_description_hash', $incoming_hash);
            $product->delete_meta_data('_asss_supplier_description_update_available');
        } else {
            $product->update_meta_data('_asss_supplier_description_latest_hash', $incoming_hash);
            $product->update_meta_data('_asss_supplier_description_update_available', 'yes');
        }
    }

    private function row_richness_score(array $row): int {
        $score = 0;
        if ((string)$this->sanmar->first($row, ['UNIQUE_KEY','UNIQUEKEY','PART_ID']) !== '') $score += 8;
        if ($this->best_variation_image_url($row) !== '') $score += 6;
        $score += min(7, count($this->variation_gallery_urls($row)));
        if ((string)$this->sanmar->first($row, ['MAP_PRICE']) !== '') $score += 3;
        if ((string)$this->sanmar->first($row, ['PIECE_WEIGHT']) !== '') $score += 2;
        if ((string)$this->sanmar->first($row, ['INVENTORY_KEY']) !== '') $score += 2;
        return $score;
    }

    /**
     * WooCommerce cannot represent two distinct children with identical
     * variation attributes. Treat exact supplier Color+Size rows as the sellable
     * matrix and keep the richest row if a feed contains duplicate combinations.
     */
    private function dedupe_supplier_rows(array $rows): array {
        $kept = [];
        $order = [];
        $duplicates = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $combo = $this->row_combo_key($row);
            $key = $combo !== 'combo:|' ? $combo : $this->row_identity($row);
            if (!isset($kept[$key])) {
                $kept[$key] = $row;
                $order[] = $key;
                continue;
            }
            $duplicates++;
            if ($this->row_richness_score($row) > $this->row_richness_score($kept[$key])) $kept[$key] = $row;
        }
        if ($duplicates) ASSS_Logger::log('Duplicate supplier Color/Size rows collapsed', 'warning', ['duplicates'=>$duplicates]);
        return array_values(array_map(static fn($key) => $kept[$key], $order));
    }

    public function import_style(string $brand, string $style, array $selected_colors = []) {
        $data = $this->sanmar->rows_for_style($brand, $style);
        if (is_wp_error($data)) return $data;

        $rows = $data['rows'];
        if (!$rows) return new WP_Error('empty_style', 'No supplier rows were found for this style.');

        $selected_colors = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_colors))));
        $all_colors = $this->colors_from_rows($rows);
        if (!$selected_colors) {
            return new WP_Error('no_colors', 'Choose at least one color before importing.');
        }

        $selected_lookup = array_fill_keys($selected_colors, true);
        $filtered_rows = array_values(array_filter($rows, function($row) use ($selected_lookup) {
            $color = (string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']);
            return $color !== '' && isset($selected_lookup[$color]);
        }));
        if (!$filtered_rows) return new WP_Error('no_variants', 'The selected colors did not contain any supplier variations.');

        // SanMar often only repeats the color imagery on one size row (commonly
        // OSFA). Propagate the best image URLs to every size of the same color so
        // all WooCommerce variations receive the correct color image.
        $filtered_rows = $this->dedupe_supplier_rows($this->hydrate_color_images($filtered_rows));

        $first = $filtered_rows[0];
        $product_id = $this->find_product($style, $brand);
        if($product_id && (string)get_post_meta($product_id,'_asss_supplier',true)==='multi') {
            return $this->link_sanmar_style_to_product($product_id,$brand,$style,$selected_colors);
        }
        if(!$product_id){
            $ss_match=$this->find_ss_product_by_brand_style($brand,$style);
            if($ss_match)return new WP_Error('existing_other_supplier','An S&S-linked WooCommerce product already appears to match '.$brand.' '.$style.' (product #'.$ss_match.'). Use the Link SanMar button on the review screen instead of creating a duplicate.');
        }
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Variable();
        if (!$product || !($product instanceof WC_Product_Variable)) {
            return new WP_Error('product', 'Could not initialize the WooCommerce variable product.');
        }

        $is_new = !$product_id;
        $title = (string)$this->sanmar->first($first, ['PRODUCT_TITLE','PRODUCT_NAME','TITLE'], $style);
        $desc = (string)$this->sanmar->first($first, ['PRODUCT_DESCRIPTION','DESCRIPTION']);
        $categories = $this->categories_from_rows($filtered_rows);
        $keywords = (string)$this->sanmar->first($first, ['KEYWORDS']);
        $supplier_brand = (string)$this->sanmar->first($first, ['BRAND_NAME'], $brand);
        $spec_sheet = (string)$this->sanmar->first($first, ['SPEC_SHEET']);

        if ($is_new) {
            $product->set_name($title ?: $style);
            $product->set_status('draft');
            $product->set_catalog_visibility('visible');
        } elseif ($product->get_name() === '') {
            $product->set_name($title ?: $style);
        }

        if (!empty($this->sanmar->settings()['sync_description'])) $this->sync_supplier_description($product, $desc, $is_new);
        $this->maybe_set_parent_sku($product, $style, $brand);

        $selection_mode = count($selected_colors) >= count($all_colors) ? 'all' : 'selected';
        $product->update_meta_data('_asss_supplier', 'sanmar');
        $product->update_meta_data('_asss_supplier_product_key', $this->supplier_product_key($brand, $style));
        $product->update_meta_data('_asss_sanmar_style', $style);
        $product->update_meta_data('_asss_sanmar_brand', $brand);
        $product->update_meta_data('_asss_sync_enabled', 'yes');
        $product->update_meta_data('_asss_last_product_file', $data['meta']['file'] ?? '');
        $product->update_meta_data('_asss_color_selection_mode', $selection_mode);
        $product->update_meta_data('_asss_selected_colors', wp_json_encode($selected_colors));
        $product->update_meta_data('_asss_spec_sheet', esc_url_raw($spec_sheet));
        $product_id = $product->save();
        $this->multi->register_product_source($product_id, 'sanmar', [
            'brand'=>$brand,'style'=>$style,'selection_mode'=>$selection_mode,'selected_colors'=>$selected_colors,
            'source_file'=>(string)($data['meta']['file'] ?? ''),
        ]);
        update_post_meta($product_id, '_asss_sanmar_color_selection_mode', $selection_mode);
        update_post_meta($product_id, '_asss_sanmar_selected_colors', wp_json_encode($selected_colors));

        $this->sync_taxonomies($product_id, $supplier_brand, $categories, $keywords, $is_new);
        $this->set_attributes($product, $filtered_rows);
        $this->sync_parent_shipping($product, $filtered_rows);
        $this->sync_bulk_order_fields($product, $first, $filtered_rows, $is_new);
        $product->save();

        $created = 0;
        $updated = 0;
        foreach ($filtered_rows as $row) {
            $result = $this->create_or_update_variation($product_id, $brand, $style, $row);
            if (is_wp_error($result)) {
                ASSS_Logger::log('Variation import failed', 'warning', [
                    'style' => $style,
                    'error' => $result->get_error_message(),
                ]);
                continue;
            }
            if (!empty($result['created'])) $created++; else $updated++;
        }

        // Final purchasability pass: every imported variation must have a
        // WooCommerce regular price. SanMar often supplies MAP only on the
        // OSFA row, so relying on row-level MAP can leave SM/L/XL variations
        // blank even though the ASBO product has valid pricing. Repair all
        // remaining blank variation prices from the product-level ASBO base
        // price (or a safe sibling/MAP fallback) before syncing the parent.
        // Deep reconciliation uses a direct child query instead of WooCommerce's
        // cached child list. This guarantees every expected supplier row is
        // represented and every variation is repaired, including non-OSFA sizes.
        $this->reconcile_variations($product_id, $brand, $style, $filtered_rows, true);

        if (!empty($this->sanmar->settings()['sync_images'])) {
            $this->sync_parent_media($product_id, $first);
        }
        $this->sync_managed_pricing_for_product($product_id);

        $product = wc_get_product($product_id);
        if ($product instanceof WC_Product_Variable) {
            $product->update_meta_data('_asss_last_product_sync', current_time('mysql'));
            $product->save();
        }

        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);

        do_action('asss_product_synced', $product_id, 'sanmar', ['brand'=>$brand, 'style'=>$style, 'mode'=>'import']);

        ASSS_Logger::log("Imported/updated SanMar {$brand} {$style}", 'info', [
            'product_id' => $product_id,
            'created_variations' => $created,
            'updated_variations' => $updated,
            'selected_colors' => count($selected_colors),
            'selection_mode' => $selection_mode,
        ]);

        return $product_id;
    }

    public function update_style(int $product_id) {
        $supplier = (string)get_post_meta($product_id, '_asss_supplier', true);
        if ($supplier === 'multi') return $this->update_multi_style($product_id);
        if ($supplier === 'ss') return $this->update_ss_style($product_id);
        if ($supplier === 'momentec') return $this->update_momentec_style($product_id);
        $brand = (string)get_post_meta($product_id, '_asss_sanmar_brand', true);
        $style = (string)get_post_meta($product_id, '_asss_sanmar_style', true);
        if (!$brand || !$style) return new WP_Error('mapping', 'Product is missing SanMar mapping.');

        $data = $this->sanmar->rows_for_style($brand, $style);
        if (is_wp_error($data)) return $data;
        $rows = $data['rows'];
        if (!$rows) return new WP_Error('empty_style', 'No supplier rows were found for this style.');

        $rows = $this->filter_rows_for_product($product_id, $rows);
        if (!$rows) return new WP_Error('no_variants', 'No supplier rows remain after applying this product\'s selected colors.');
        $rows = $this->dedupe_supplier_rows($this->hydrate_color_images($rows));

        $first = $rows[0];
        $product = wc_get_product($product_id);
        if (!$product || !($product instanceof WC_Product_Variable)) return new WP_Error('product', 'Product missing or is not variable.');

        $s = $this->sanmar->settings();
        if (!empty($s['sync_description'])) {
            $d = (string)$this->sanmar->first($first, ['PRODUCT_DESCRIPTION','DESCRIPTION']);
            $this->sync_supplier_description($product, $d, false);
        }

        $this->maybe_set_parent_sku($product, $style, $brand);
        $product->update_meta_data('_asss_supplier_product_key', $this->supplier_product_key($brand, $style));
        $product->update_meta_data('_asss_last_product_file', $data['meta']['file'] ?? '');
        $product->update_meta_data('_asss_last_product_sync', current_time('mysql'));
        $product->update_meta_data('_asss_spec_sheet', esc_url_raw((string)$this->sanmar->first($first, ['SPEC_SHEET'])));

        $status = strtolower((string)$this->sanmar->first($first, ['PRODUCT_STATUS','STATUS']));
        if (strpos($status, 'discontinued') !== false) {
            $this->hide_discontinued_product($product, $status);
            return $product_id;
        }

        // Never auto-publish a parent that was archived by supplier status or
        // inventory. If the product feed later reports a non-discontinued state,
        // flag it once for admin review; deliberate republishing in WooCommerce
        // clears the archive markers through the main plugin restore hook.
        if ($status !== '' && (string)$product->get_meta('_asss_supplier_archived') === 'yes' && (string)$product->get_meta('_asss_discontinued') === 'yes') {
            if ((string)$product->get_meta('_asss_supplier_reactivated') !== 'yes') {
                $product->update_meta_data('_asss_supplier_reactivated', 'yes');
                $product->update_meta_data('_asss_supplier_reactivated_at', current_time('mysql'));
                $product->save();
                $to = $this->sanmar->settings()['admin_notify'] ?: get_option('admin_email');
                wp_mail(
                    $to,
                    'Supplier product may be available again: ' . $product->get_name(),
                    "SanMar no longer reports this archived product as discontinued. It remains Draft/hidden until you review and publish it manually.\n\nProduct: " . $product->get_name() . "\nSupplier status: " . $status . "\nEdit: " . admin_url('post.php?post=' . $product->get_id() . '&action=edit')
                );
                ASSS_Logger::log('Archived supplier product appears active again; admin review required', 'warning', [
                    'product_id' => $product_id, 'status' => $status,
                ]);
            }
            return $product_id;
        }

        // Structural data is intentionally repaired on every supplier update. This
        // fixes products created by older plugin versions without overwriting the
        // merchant's retail prices or custom/manual imagery.
        $this->set_attributes($product, $rows);
        $this->sync_parent_shipping($product, $rows);
        $this->sync_bulk_order_fields($product, $first, $rows, false);
        $product->save();
        $this->sync_taxonomies(
            $product_id,
            (string)$this->sanmar->first($first, ['BRAND_NAME'], $brand),
            $this->categories_from_rows($rows),
            (string)$this->sanmar->first($first, ['KEYWORDS']),
            false
        );

        foreach ($rows as $row) {
            $unique = (string)$this->sanmar->first($row, ['UNIQUE_KEY','UNIQUEKEY','PART_ID']);
            $color = (string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']);
            $size = (string)$this->sanmar->first($row, ['SIZE']);
            $vid = $this->find_variation($product_id, $unique, $color, $size);

            if (!$vid && !empty($s['sync_new_variations'])) {
                $this->create_or_update_variation($product_id, $brand, $style, $row);
            } elseif ($vid) {
                $this->create_or_update_variation($product_id, $brand, $style, $row, $vid);
            }
        }

        // Run the same final repair on automatic/re-sync updates so older
        // imports with blank non-OSFA prices are corrected without deletion.
        $this->reconcile_variations($product_id, $brand, $style, $rows, !empty($s['sync_new_variations']));

        if (!empty($s['sync_images'])) $this->sync_parent_media($product_id, $first);
        $this->sync_managed_pricing_for_product($product_id);

        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
        do_action('asss_product_synced', $product_id, 'sanmar', ['brand'=>$brand, 'style'=>$style, 'mode'=>'update']);
        return $product_id;
    }

    /**
     * @return array{variation_id:int,created:bool}|WP_Error
     */
    private function create_or_update_variation(int $product_id, string $brand, string $style, array $row, int $variation_id = 0) {
        $color = (string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']);
        $size = (string)$this->sanmar->first($row, ['SIZE']);
        $unique = (string)$this->sanmar->first($row, ['UNIQUE_KEY','UNIQUEKEY','PART_ID']);

        if (!$variation_id) $variation_id = $this->find_variation($product_id, $unique, $color, $size);
        $created = !$variation_id;
        $v = $variation_id ? wc_get_product($variation_id) : new WC_Product_Variation();
        if (!$v || !($v instanceof WC_Product_Variation)) return new WP_Error('variation', 'Could not initialize a product variation.');
        if ($created) $v->set_parent_id($product_id);

        $attributes = [];
        if ($color !== '') $attributes['pa_color'] = $this->term_slug('pa_color', $color);
        if ($size !== '') $attributes['pa_size'] = $this->term_slug('pa_size', $size);
        $v->set_attributes($attributes);
        $v->set_status('publish');
        $v->set_manage_stock(true);
        $v->set_backorders('no');
        $v->delete_meta_data('_asss_stale_variation');
        $v->delete_meta_data('_asss_stale_variation_reason');
        $v->delete_meta_data('_asss_discontinued_variation');

        $weight = (string)$this->sanmar->first($row, ['PIECE_WEIGHT']);
        if ($weight !== '' && is_numeric($weight) && (float)$weight > 0) {
            $v->set_weight($this->weight_for_store((float)$weight));
            // Keep the supplier's original unit/value for debugging and future
            // unit changes. SanMar PIECE_WEIGHT is documented in pounds.
            $v->update_meta_data('_asss_supplier_weight_lb', (string)wc_format_decimal($weight));
        }

        $this->maybe_set_variation_sku($v, $unique);
        $this->sync_variation_base_price($v, $product_id, $row);

        $v->update_meta_data('_asss_supplier', 'sanmar');
        $v->update_meta_data('_asss_supplier_product_key', $this->supplier_product_key($brand, $style));
        $v->update_meta_data('_asss_sanmar_style', $style);
        $v->update_meta_data('_asss_sanmar_brand', $brand);
        $v->update_meta_data('_asss_sanmar_unique_key', $unique);
        $v->update_meta_data('_asss_sanmar_inventory_key', (string)$this->sanmar->first($row, ['INVENTORY_KEY','INVENTORYKEY']));
        $v->update_meta_data('_asss_sanmar_size_index', (string)$this->sanmar->first($row, ['SIZE_INDEX','SIZEINDEX']));
        $v->update_meta_data('_asss_sanmar_color', $color);
        $v->update_meta_data('_asss_sanmar_size', $size);
        $v->update_meta_data('_asss_supplier_cost', (string)$this->sanmar->first($row, ['PIECE_PRICE','PRICE']));
        $v->update_meta_data('_asss_sanmar_cost', (string)$this->sanmar->first($row, ['PIECE_PRICE','PRICE']));
        $v->update_meta_data('_asss_case_price', (string)$this->sanmar->first($row, ['CASE_PRICE']));
        $v->update_meta_data('_asss_sanmar_case_price', (string)$this->sanmar->first($row, ['CASE_PRICE']));
        $v->update_meta_data('_asss_supplier_sale_price', (string)$this->sanmar->first($row, ['PIECE_SALE_PRICE']));
        $v->update_meta_data('_asss_sanmar_sale_price', (string)$this->sanmar->first($row, ['PIECE_SALE_PRICE']));
        $v->update_meta_data('_asss_map_price', (string)$this->sanmar->first($row, ['MAP_PRICE']));
        $v->update_meta_data('_asss_sanmar_map_price', (string)$this->sanmar->first($row, ['MAP_PRICE']));
        $v->update_meta_data('_asss_product_status', (string)$this->sanmar->first($row, ['PRODUCT_STATUS','STATUS']));
        $v->update_meta_data('_asss_color_square_url', esc_url_raw((string)$this->sanmar->first($row, ['COLOR_SQUARE_IMAGE'])));
        $v->update_meta_data('_asss_front_flat_url', esc_url_raw((string)$this->sanmar->first($row, ['FRONT_FLAT'])));
        $v->update_meta_data('_asss_back_flat_url', esc_url_raw((string)$this->sanmar->first($row, ['BACK_FLAT'])));
        $v->update_meta_data('_asss_front_model_url', esc_url_raw((string)$this->sanmar->first($row, ['FRONT_MODEL'])));
        $v->update_meta_data('_asss_back_model_url', esc_url_raw((string)$this->sanmar->first($row, ['BACK_MODEL'])));
        $v->update_meta_data('_asss_side_model_url', esc_url_raw((string)$this->sanmar->first($row, ['SIDE_MODEL'])));
        $v->update_meta_data('_asss_three_q_model_url', esc_url_raw((string)$this->sanmar->first($row, ['THREE_Q_MODEL'])));
        $vid = $v->save();
        $this->multi->register_variation_source($vid, 'sanmar', [
            'brand'=>$brand,'style'=>$style,'unique_key'=>$unique,
            'inventory_key'=>(string)$this->sanmar->first($row, ['INVENTORY_KEY','INVENTORYKEY']),
            'size_index'=>(string)$this->sanmar->first($row, ['SIZE_INDEX','SIZEINDEX']),
            'color'=>$color,'size'=>$size,
            'cost'=>(string)$this->sanmar->first($row, ['PIECE_PRICE','PRICE']),
            'map_price'=>(string)$this->sanmar->first($row, ['MAP_PRICE']),
            'inventory_qty'=>(string)$v->get_meta('_asss_supplier_inventory_qty') !== '' ? (int)$v->get_meta('_asss_supplier_inventory_qty') : null,
        ]);

        if (!empty($this->sanmar->settings()['sync_images'])) $this->sync_variation_media($vid, $row);
        do_action('asss_variation_synced', $vid, $product_id, 'sanmar', ['brand'=>$brand, 'style'=>$style, 'color'=>$color, 'size'=>$size]);
        return ['variation_id' => $vid, 'created' => $created];
    }

    /**
     * Fill missing image fields on size rows from another supplier row for the
     * same color. SanMar feeds commonly provide the color photography only once
     * per color even when that color has multiple sizes.
     */
    private function hydrate_color_images(array $rows): array {
        $fields = [
            'COLOR_PRODUCT_IMAGE', 'COLOR_PRODUCT_IMAGE_THUMBNAIL',
            'COLOR_SQUARE_IMAGE', 'FRONT_FLAT', 'BACK_FLAT',
            'FRONT_MODEL', 'BACK_MODEL', 'SIDE_MODEL', 'THREE_Q_MODEL',
        ];
        $by_color = [];
        foreach ($rows as $row) {
            $color = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
            if ($color === '') continue;
            $key = strtolower($color);
            if (!isset($by_color[$key])) $by_color[$key] = [];
            foreach ($fields as $field) {
                if (empty($by_color[$key][$field]) && !empty($row[$field])) {
                    $by_color[$key][$field] = (string)$row[$field];
                }
            }
        }
        foreach ($rows as &$row) {
            $color = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
            $key = strtolower($color);
            if ($color === '' || empty($by_color[$key])) continue;
            foreach ($fields as $field) {
                if (empty($row[$field]) && !empty($by_color[$key][$field])) {
                    $row[$field] = $by_color[$key][$field];
                }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Supplier-managed WooCommerce pricing uses the current wholesale cost plus
     * a fixed $20 markup. The ownership markers below ensure a merchant edit
     * immediately takes control and future supplier syncs stop overwriting it.
     */
    private function price_values_equal($left, $right): bool {
        if (!is_numeric($left) || !is_numeric($right)) return false;
        $precision = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        return round((float)$left, $precision) === round((float)$right, $precision);
    }

    /**
     * A Supplier Sync managed regular price remains managed only while the
     * merchant has not edited it. V1 records the last value it wrote. For
     * legacy managed rows without that marker, a current price that differs
     * from the candidate is treated as merchant-owned rather than overwritten.
     */
    private function managed_price_can_update(WC_Product_Variation $variation, float $candidate): bool {
        if ((string)$variation->get_meta('_asss_base_price_managed') !== 'yes') return false;
        $current = $variation->get_regular_price('edit');
        if ($current === '') return true;
        $last = (string)$variation->get_meta('_asss_base_price_last_value');
        if ($last !== '' && $this->price_values_equal($current, $last)) return true;
        if ($last === '' && $this->price_values_equal($current, $candidate)) return true;

        // If the current value no longer equals the last value Supplier Sync
        // wrote, the merchant (or another pricing plugin) owns it now. Legacy
        // managed rows without a last-value marker receive the same protection
        // whenever their current price differs from the candidate.
        $variation->delete_meta_data('_asss_base_price_managed');
        $variation->delete_meta_data('_asss_base_price_source');
        $variation->delete_meta_data('_asss_base_price_last_value');
        ASSS_Logger::log('Variation base price ownership changed to merchant because the current value no longer matches the Supplier Sync managed value.', 'info', [
            'variation_id' => $variation->get_id(),
            'current_price' => $current,
            'last_managed_price' => $last,
            'candidate_price' => $candidate,
        ]);
        return false;
    }

    private function apply_managed_base_price(WC_Product_Variation $variation, float $price, string $source): void {
        $formatted = (string)wc_format_decimal($price, wc_get_price_decimals());
        $variation->set_regular_price($formatted);
        $sale = $variation->get_sale_price('edit');
        if ($sale !== '' && is_numeric($sale) && (float)$sale > 0) {
            // Sale price is merchant-owned; Supplier Sync does not erase it.
            $variation->set_price((string)wc_format_decimal($sale, wc_get_price_decimals()));
        } else {
            $variation->set_price($formatted);
        }
        $variation->update_meta_data('_asss_base_price_managed', 'yes');
        $variation->update_meta_data('_asss_base_price_source', $source);
        $variation->update_meta_data('_asss_base_price_last_value', $formatted);
    }

    private function sync_variation_base_price(WC_Product_Variation $variation, int $product_id, array $row): void {
        $settings = $this->sanmar->settings();
        if (empty($settings['sync_variation_base_prices'])) return;

        $wholesale = (string)$this->sanmar->first($row, ['PIECE_PRICE','PRICE']);
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

        $current = $variation->get_regular_price('edit');
        $managed = (string)$variation->get_meta('_asss_base_price_managed') === 'yes';
        if ($current !== '' && !$managed) return;
        if ($current !== '' && $managed && !$this->managed_price_can_update($variation, (float)$price)) return;
        $this->apply_managed_base_price($variation, (float)$price, $source);
        $variation->update_meta_data('_asss_pricing_wholesale_basis', is_numeric($wholesale) ? (string)wc_format_decimal((float)$wholesale, wc_get_price_decimals()) : '');
        $variation->update_meta_data('_asss_pricing_basis_supplier', 'sanmar');
    }

    /**
     * Return supplier priority for pricing. Product-level preference wins, then
     * the global multi-supplier order. A variation may still fall back to a
     * secondary supplier when the preferred supplier does not carry that exact
     * Color+Size combination.
     */
    private function pricing_supplier_order(int $product_id): array {
        $settings = $this->multi->settings();
        $order = [];
        $preferred = sanitize_key((string)get_post_meta($product_id, '_asss_preferred_supplier', true));
        if (in_array($preferred, ['ss','sanmar','momentec'], true)) $order[] = $preferred;
        foreach ((array)($settings['priority'] ?? ['ss','sanmar','momentec']) as $supplier) {
            $supplier = sanitize_key((string)$supplier);
            if (in_array($supplier, ['ss','sanmar','momentec'], true) && !in_array($supplier, $order, true)) $order[] = $supplier;
        }
        foreach (['ss','sanmar','momentec'] as $supplier) if (!in_array($supplier, $order, true)) $order[] = $supplier;
        return $order;
    }

    /** @return array{supplier:string,cost:float}|null */
    private function variation_pricing_basis(int $product_id, int $variation_id): ?array {
        $sources = $this->multi->variation_sources($variation_id);
        $read = static function($source): ?float {
            if (!is_array($source) || array_key_exists('enabled', $source) && empty($source['enabled'])) return null;
            foreach (['cost','customer_price','piece_price'] as $key) {
                $value = $source[$key] ?? null;
                if ($value !== null && $value !== '' && is_numeric($value) && (float)$value > 0) return (float)$value;
            }
            return null;
        };

        foreach ($this->pricing_supplier_order($product_id) as $supplier) {
            if (!isset($sources[$supplier])) continue;
            $cost = $read($sources[$supplier]);
            if ($cost !== null) return ['supplier'=>$supplier,'cost'=>$cost];
        }
        foreach ($sources as $supplier=>$source) {
            $cost = $read($source);
            if ($cost !== null) return ['supplier'=>sanitize_key((string)$supplier),'cost'=>$cost];
        }

        // Legacy safety for a pre-V2 variation whose source JSON has not yet
        // been hydrated. This is only used when a positive supplier cost exists.
        $legacy_cost = get_post_meta($variation_id, '_asss_supplier_cost', true);
        if ($legacy_cost !== '' && is_numeric($legacy_cost) && (float)$legacy_cost > 0) {
            $supplier = sanitize_key((string)get_post_meta($variation_id, '_asss_supplier', true));
            if (!in_array($supplier, ['ss','sanmar','momentec'], true)) $supplier = sanitize_key((string)get_post_meta($product_id, '_asss_supplier', true));
            if (!in_array($supplier, ['ss','sanmar','momentec'], true)) $supplier = $this->pricing_supplier_order($product_id)[0] ?? 'sanmar';
            return ['supplier'=>$supplier,'cost'=>(float)$legacy_cost];
        }
        return null;
    }

    private function build_managed_asbo_matrix(float $main_price): string {
        // All Star bulk hat discount ladder: fixed dollars off per item.
        $embroidery = [6=>$main_price, 9=>$main_price-1.0, 12=>$main_price-2.0, 24=>$main_price-3.0, 48=>$main_price-4.0, 96=>$main_price-6.0, 144=>$main_price-7.0, 288=>$main_price-9.0];
        $format = static function(float $value): string {
            return number_format(max(0.01, $value), 2, '.', '');
        };
        $emb = [];
        $patch = [];
        foreach ($embroidery as $qty=>$price) {
            $emb[] = $qty . ':' . $format($price);
            $patch[] = $qty . ':' . $format($price + 3.0);
        }
        return 'Embroidery|' . implode(',', $emb) . "
" . 'Patch|' . implode(',', $patch);
    }

    private function managed_asbo_matrix_can_update(int $product_id, string $candidate): bool {
        $managed = (string)get_post_meta($product_id, '_asss_asbo_pricing_managed', true) === 'yes';
        if (!$managed) return false;
        $current = trim((string)get_post_meta($product_id, '_asbo_pricing_matrix', true));
        if ($current === '') return true;
        $last = trim((string)get_post_meta($product_id, '_asss_asbo_pricing_last_value', true));
        if ($last !== '' && hash_equals(hash('sha256', $last), hash('sha256', $current))) return true;
        if ($last === '' && hash_equals(hash('sha256', $candidate), hash('sha256', $current))) return true;

        // A manual edit takes ownership permanently unless the merchant clears
        // the matrix and lets Supplier Sync generate it again.
        foreach (['_asss_asbo_pricing_managed','_asss_asbo_pricing_last_value','_asss_asbo_pricing_basis_main','_asss_asbo_pricing_basis_supplier'] as $key) {
            delete_post_meta($product_id, $key);
        }
        ASSS_Logger::log('ASBO pricing ownership changed to merchant because the matrix no longer matches Supplier Sync managed pricing.', 'info', ['product_id'=>$product_id]);
        return false;
    }

    private function apply_managed_asbo_matrix(int $product_id, float $main_price, string $basis_supplier): void {
        $matrix = $this->build_managed_asbo_matrix($main_price);
        update_post_meta($product_id, '_asbo_pricing_matrix', $matrix);
        update_post_meta($product_id, '_asss_asbo_pricing_managed', 'yes');
        update_post_meta($product_id, '_asss_asbo_pricing_last_value', $matrix);
        update_post_meta($product_id, '_asss_asbo_pricing_basis_main', (string)wc_format_decimal($main_price, wc_get_price_decimals()));
        update_post_meta($product_id, '_asss_asbo_pricing_basis_supplier', sanitize_key($basis_supplier));
    }


    /**
     * Upgrade existing Supplier Sync-owned ASBO matrices to the v2.0.9 ladder
     * without recalculating their base selling price. The saved pricing basis is
     * reused so this migration changes only quantity discounts.
     */
    public function migrate_managed_asbo_pricing_v209(): array {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish','draft','private','pending'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_asss_asbo_pricing_managed',
            'meta_value' => 'yes',
        ]);
        $updated = 0;
        $merchant_owned = 0;
        $deferred = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            if ($product_id < 1) continue;
            $main = (float)get_post_meta($product_id, '_asss_asbo_pricing_basis_main', true);
            if ($main <= 0) {
                $deferred++;
                continue;
            }
            $basis_supplier = (string)get_post_meta($product_id, '_asss_asbo_pricing_basis_supplier', true);
            $candidate = $this->build_managed_asbo_matrix($main);
            if (!$this->managed_asbo_matrix_can_update($product_id, $candidate)) {
                $merchant_owned++;
                continue;
            }
            $this->apply_managed_asbo_matrix($product_id, $main, $basis_supplier ?: 'mixed');
            $updated++;
        }
        ASSS_Logger::log('v2.0.9 managed ASBO pricing ladder migration completed', 'info', [
            'updated'=>$updated,
            'merchant_owned'=>$merchant_owned,
            'deferred'=>$deferred,
        ]);
        return ['complete'=>true,'updated'=>$updated,'merchant_owned'=>$merchant_owned,'deferred'=>$deferred];
    }


    /** Fixed per-item dollars-off ladder used across the entire ASBO catalog. */
    private function sitewide_asbo_discount_offsets(): array {
        return [6=>0.0, 9=>1.0, 12=>2.0, 24=>3.0, 48=>4.0, 96=>6.0, 144=>7.0, 288=>9.0];
    }

    private function sitewide_asbo_tier_string(float $base): string {
        $parts = [];
        foreach ($this->sitewide_asbo_discount_offsets() as $qty=>$discount) {
            $parts[] = $qty . ':' . number_format(max(0.01, $base - $discount), 2, '.', '');
        }
        return implode(',', $parts);
    }

    /**
     * Preserve every existing decoration method and its current starting price,
     * but force all methods onto the same All Star quantity ladder.
     */
    private function normalize_sitewide_asbo_matrix(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) continue;
            [$label, $tiers_raw] = array_map('trim', explode('|', $line, 2));
            if ($label === '') continue;
            $tiers = [];
            foreach (explode(',', $tiers_raw) as $pair) {
                if (strpos($pair, ':') === false) continue;
                [$qty, $price] = array_map('trim', explode(':', $pair, 2));
                $qty = absint($qty);
                if (!$qty || !is_numeric($price) || (float)$price <= 0) continue;
                $tiers[$qty] = (float)$price;
            }
            if (!$tiers) continue;
            ksort($tiers, SORT_NUMERIC);
            $first_qty = array_key_first($tiers);
            if ($first_qty === null) continue;
            $base = (float)$tiers[$first_qty];
            if ($base <= 0) continue;
            $out[] = $label . '|' . $this->sitewide_asbo_tier_string($base);
        }
        return $out ? implode("\n", $out) : null;
    }

    /**
     * Safe fallback for a bulk-order product that is enabled but has no usable
     * matrix yet. Supplier-managed products retain their saved basis; otherwise
     * use the highest current Woo regular price so a shared matrix cannot
     * underprice a higher-priced variation.
     */
    private function sitewide_asbo_fallback_base(int $product_id): ?float {
        $saved = (float)get_post_meta($product_id, '_asss_asbo_pricing_basis_main', true);
        if ($saved > 0) return $saved;
        $product = wc_get_product($product_id);
        if (!$product) return null;
        $prices = [];
        if ($product instanceof WC_Product_Variable) {
            foreach ($this->variation_ids_direct($product_id) as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation instanceof WC_Product_Variation) continue;
                $price = $variation->get_regular_price('edit');
                if ($price !== '' && is_numeric($price) && (float)$price > 0) $prices[] = (float)$price;
            }
        } else {
            $price = $product->get_regular_price('edit');
            if ($price === '') $price = $product->get_price('edit');
            if ($price !== '' && is_numeric($price) && (float)$price > 0) $prices[] = (float)$price;
        }
        return $prices ? max($prices) : null;
    }

    /**
     * Normalize one ASBO-enabled product. Manual edits to the base price are
     * respected: if a Supplier Sync-managed matrix was changed by the merchant,
     * Supplier Sync relinquishes price ownership but the universal ladder is
     * still applied using the merchant's new starting price.
     */
    public function enforce_sitewide_asbo_pricing_for_product(int $product_id): array {
        if ($product_id < 1 || (string)get_post_meta($product_id, '_asbo_enabled', true) !== 'yes') {
            return ['status'=>'not_enabled'];
        }
        $current = trim((string)get_post_meta($product_id, '_asbo_pricing_matrix', true));
        $managed = (string)get_post_meta($product_id, '_asss_asbo_pricing_managed', true) === 'yes';
        $last = trim((string)get_post_meta($product_id, '_asss_asbo_pricing_last_value', true));
        $merchant_changed = $managed && $last !== '' && !hash_equals(hash('sha256', $last), hash('sha256', $current));

        if ($merchant_changed) {
            foreach (['_asss_asbo_pricing_managed','_asss_asbo_pricing_last_value','_asss_asbo_pricing_basis_main','_asss_asbo_pricing_basis_supplier'] as $key) {
                delete_post_meta($product_id, $key);
            }
            $managed = false;
            ASSS_Logger::log('ASBO base pricing ownership changed to merchant; universal quantity ladder retained.', 'info', ['product_id'=>$product_id]);
        }

        $candidate = $this->normalize_sitewide_asbo_matrix($current);
        if ($candidate === null) {
            $base = $this->sitewide_asbo_fallback_base($product_id);
            if ($base === null || $base <= 0) return ['status'=>'skipped_no_base'];
            // No usable matrix exists, so use the standard All Star defaults.
            $candidate = $this->build_managed_asbo_matrix($base);
        }

        if ($current !== '' && hash_equals(hash('sha256', $current), hash('sha256', $candidate))) {
            update_post_meta($product_id, '_asss_asbo_sitewide_ladder', '2.0.10');
            return ['status'=>'unchanged'];
        }

        update_post_meta($product_id, '_asbo_pricing_matrix', $candidate);
        update_post_meta($product_id, '_asss_asbo_sitewide_ladder', '2.0.10');
        if ($managed) update_post_meta($product_id, '_asss_asbo_pricing_last_value', $candidate);
        return ['status'=>'updated'];
    }

    /** Apply the universal ladder to every product currently enabled for ASBO. */
    public function enforce_sitewide_asbo_pricing_v210(): array {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish','draft','private','pending'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_asbo_enabled',
            'meta_value' => 'yes',
        ]);
        $counts = ['updated'=>0,'unchanged'=>0,'skipped_no_base'=>0,'not_enabled'=>0];
        foreach ((array)$ids as $product_id) {
            $result = $this->enforce_sitewide_asbo_pricing_for_product((int)$product_id);
            $status = (string)($result['status'] ?? '');
            if (isset($counts[$status])) $counts[$status]++;
        }
        ASSS_Logger::log('v2.0.10 site-wide ASBO pricing ladder enforcement completed', 'info', $counts + ['products'=>count((array)$ids)]);
        return ['complete'=>true,'products'=>count((array)$ids)] + $counts;
    }

    /**
     * 2.0.1 pricing engine.
     *
     * - Woo main price per variation = preferred available supplier wholesale + $20.
     * - Product-level ASBO matrix uses the highest resulting Main Price across
     *   active variations so a higher-cost size/color cannot be underpriced by
     *   one matrix shared across the entire product.
     * - All ASBO products use $0/$1/$2/$3/$4/$6/$7/$9 off at 6/9/12/24/48/96/144/288 units.
     * - Patch is always +$3 over Embroidery at the matching tier.
     * - Merchant-edited Woo prices and ASBO matrices are never overwritten.
     */
    public function sync_managed_pricing_for_product(int $product_id): void {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product_Variable) return;
        $settings = $this->sanmar->settings();
        $mains = [];
        $basis_suppliers = [];

        foreach ($this->variation_ids_direct($product_id) as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation instanceof WC_Product_Variation) continue;
            if ($variation->get_status('edit') !== 'publish') continue;
            if ((string)$variation->get_meta('_asss_stale_variation') === 'yes' || (string)$variation->get_meta('_asss_discontinued_variation') === 'yes') continue;
            $basis = $this->variation_pricing_basis($product_id, $variation_id);
            if (!$basis) continue;
            $main = (float)$basis['cost'] + 20.0;
            if ($main <= 0) continue;
            $mains[] = $main;
            $basis_suppliers[$basis['supplier']] = true;

            if (!empty($settings['sync_variation_base_prices'])) {
                $current = $variation->get_regular_price('edit');
                $managed = (string)$variation->get_meta('_asss_base_price_managed') === 'yes';
                $can_apply = $current === '';
                if (!$can_apply && $managed) $can_apply = $this->managed_price_can_update($variation, $main);
                if ($can_apply) {
                    $this->apply_managed_base_price($variation, $main, 'supplier_markup:' . $basis['supplier']);
                    $variation->update_meta_data('_asss_pricing_wholesale_basis', (string)wc_format_decimal((float)$basis['cost'], wc_get_price_decimals()));
                    $variation->update_meta_data('_asss_pricing_basis_supplier', $basis['supplier']);
                    $variation->save();
                }
            }
        }

        if (!$mains || empty($settings['sync_bulk_order_fields'])) return;
        $main = max($mains);
        $basis_supplier = count($basis_suppliers) === 1 ? (string)array_key_first($basis_suppliers) : 'mixed';
        $candidate = $this->build_managed_asbo_matrix($main);
        $current = trim((string)get_post_meta($product_id, '_asbo_pricing_matrix', true));
        $managed = (string)get_post_meta($product_id, '_asss_asbo_pricing_managed', true) === 'yes';
        if ($current === '') {
            $this->apply_managed_asbo_matrix($product_id, $main, $basis_supplier);
        } elseif ($managed && $this->managed_asbo_matrix_can_update($product_id, $candidate)) {
            $this->apply_managed_asbo_matrix($product_id, $main, $basis_supplier);
        }
    }

    /**
     * Guarantee that every child variation has a WooCommerce regular price.
     *
     * ASBO's matrix is product-level, while SanMar's MAP value may appear only
     * on one size row for a color. WooCommerce requires a price on every
     * variation, so a row-by-row strategy can leave secondary sizes
     * unpurchasable. This final pass fills only blank prices and never replaces
     * a merchant-entered variation price.
     */
    private function repair_missing_variation_prices(int $product_id): void {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product_Variable) return;

        $children = $this->variation_ids_direct($product_id);
        if (!$children) return;
        $eligible = [];
        foreach ($children as $child_id) {
            $child = wc_get_product($child_id);
            if (!$child instanceof WC_Product_Variation) continue;
            if ((string)$child->get_meta('_asss_supplier') !== 'sanmar') continue;
            if ((string)$child->get_meta('_asss_stale_variation') === 'yes') continue;
            if ((string)$child->get_meta('_asss_discontinued_variation') === 'yes') continue;
            if ($child->get_status('edit') !== 'publish') continue;
            $eligible[] = $child;
        }
        if (!$eligible) return;

        $base = $this->asbo_base_price($product_id);
        $source = $base !== null ? 'asbo-repair' : '';
        if ($base === null) {
            foreach ($eligible as $child) {
                $price = $child->get_regular_price('edit');
                if ($price !== '' && is_numeric($price) && (float)$price > 0 && (string)$child->get_meta('_asss_base_price_managed') === 'yes') {
                    $base = (float)$price;
                    $source = 'managed-sibling-repair';
                    break;
                }
            }
        }
        if ($base === null) {
            $maps = [];
            foreach ($eligible as $child) {
                $map = $child->get_meta('_asss_map_price');
                if ($map !== '' && is_numeric($map) && (float)$map > 0) $maps[] = (float)$map;
            }
            if ($maps) {
                $base = max($maps);
                $source = 'map-sibling-repair';
            }
        }
        if ($base === null || $base <= 0) return;

        $formatted = (string)wc_format_decimal($base, wc_get_price_decimals());
        $repaired = 0;
        foreach ($eligible as $child) {
            if ($child->get_regular_price('edit') !== '' && $child->get_price('edit') !== '') continue;
            // Never replace a merchant-owned non-empty regular price.
            if ($child->get_regular_price('edit') !== '' && (string)$child->get_meta('_asss_base_price_managed') !== 'yes') {
                if ($child->get_price('edit') === '') {
                    $child->set_price($child->get_regular_price('edit'));
                    $child->save();
                    $repaired++;
                }
                continue;
            }
            $this->apply_managed_base_price($child, (float)$base, $source);
            $child->save();
            $repaired++;
        }
        if ($repaired > 0) ASSS_Logger::log('Repaired blank WooCommerce variation prices', 'info', [
            'product_id'=>$product_id, 'repaired'=>$repaired, 'base_price'=>$formatted, 'source'=>$source,
        ]);
    }

    private function variation_ids_direct(int $product_id): array {
        $ids = get_posts([
            'post_type' => 'product_variation',
            'post_status' => 'any',
            'post_parent' => $product_id,
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    private function row_identity(array $row): string {
        $unique = trim((string)$this->sanmar->first($row, ['UNIQUE_KEY','UNIQUEKEY','PART_ID']));
        if ($unique !== '') return 'u:' . $unique;
        $color = strtolower(trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR'])));
        $size = strtolower(trim((string)$this->sanmar->first($row, ['SIZE'])));
        return 'cs:' . $color . '|' . $size;
    }

    private function variation_identity(WC_Product_Variation $variation): string {
        $unique = trim((string)$variation->get_meta('_asss_sanmar_unique_key'));
        if ($unique !== '') return 'u:' . $unique;
        $color = strtolower(trim((string)$variation->get_meta('_asss_sanmar_color')));
        $size = strtolower(trim((string)$variation->get_meta('_asss_sanmar_size')));
        return 'cs:' . $color . '|' . $size;
    }

    /**
     * Parent attributes are only the UNION of supplier values. They are not a
     * promise that every Color x Size combination exists. These helpers encode
     * the exact sparse supplier matrix using WooCommerce attribute slugs.
     */
    private function row_combo_key(array $row): string {
        $color = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
        $size = trim((string)$this->sanmar->first($row, ['SIZE']));
        $color_slug = $color !== '' ? $this->term_slug('pa_color', $color) : '';
        $size_slug = $size !== '' ? $this->term_slug('pa_size', $size) : '';
        return 'combo:' . strtolower($color_slug) . '|' . strtolower($size_slug);
    }

    private function variation_combo_key(WC_Product_Variation $variation): string {
        $attrs = $variation->get_attributes();
        $color = strtolower(trim((string)($attrs['pa_color'] ?? '')));
        $size = strtolower(trim((string)($attrs['pa_size'] ?? '')));
        return 'combo:' . $color . '|' . $size;
    }

    private function variation_gallery_urls(array $row): array {
        $urls = [];
        $reference = [];
        foreach (['COLOR_SWATCH_IMAGE','COLOR_SQUARE_IMAGE'] as $key) {
            $url = trim((string)$this->sanmar->first($row, [$key]));
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) $reference[$url] = true;
        }

        // Prefer the explicit ordered gallery generated by GitHub when present,
        // but strip any URL that is also identified as supplier reference media.
        $raw_gallery = $this->sanmar->first($row, ['VARIATION_GALLERY_URLS']);
        if (is_string($raw_gallery) && trim($raw_gallery) !== '') {
            $decoded = json_decode($raw_gallery, true);
            if (is_array($decoded)) {
                foreach ($decoded as $url) {
                    $url = trim((string)$url);
                    if ($url && !isset($reference[$url]) && filter_var($url, FILTER_VALIDATE_URL)) $urls[$url] = true;
                }
            }
        }

        foreach ([
            'COLOR_PRODUCT_IMAGE','FRONT_FLAT','FRONT_MODEL','THREE_Q_MODEL',
            'SIDE_MODEL','BACK_MODEL','BACK_FLAT',
        ] as $key) {
            $url = trim((string)$this->sanmar->first($row, [$key]));
            if ($url && !isset($reference[$url]) && filter_var($url, FILTER_VALIDATE_URL)) $urls[$url] = true;
        }

        if (!$urls) {
            foreach (['COLOR_PRODUCT_IMAGE_THUMBNAIL','PRODUCT_IMAGE','THUMBNAIL_IMAGE'] as $key) {
                $url = trim((string)$this->sanmar->first($row, [$key]));
                if ($url && !isset($reference[$url]) && filter_var($url, FILTER_VALIDATE_URL)) $urls[$url] = true;
            }
        }
        return array_values(array_filter(array_keys($urls), fn($url) => !$this->is_invalid_storefront_media_url((string)$url)));
    }

    private function best_variation_image_url(array $row): string {
        $gallery = $this->variation_gallery_urls($row);
        return (string)($gallery[0] ?? '');
    }

    /**
     * Deeply reconcile expected supplier rows against WooCommerce variations.
     *
     * This is intentionally redundant with the normal import loop. Supplier
     * data is cheap to re-apply, while a missing variation price/image/attribute
     * makes the storefront unreliable. The direct reconciliation pass catches
     * cache timing, legacy imports, incomplete rows and any variation that was
     * missed by an older plugin version.
     */
    private function reconcile_variations(int $product_id, string $brand, string $style, array $rows, bool $allow_create = true): void {
        $rows = $this->dedupe_supplier_rows($this->hydrate_color_images($rows));
        $expected = [];
        $expected_combos = [];
        foreach ($rows as $row) {
            $key = $this->row_identity($row);
            $combo = $this->row_combo_key($row);
            if ($key === 'cs:|' || $combo === 'combo:|') continue;
            // One active Woo variation per exact attribute combination. Supplier
            // UNIQUE_KEY remains the preferred identity, but Color+Size is the
            // representable WooCommerce identity and therefore wins conflicts.
            if (isset($expected_combos[$combo])) {
                $old_key = $expected_combos[$combo];
                if ($this->row_richness_score($row) > $this->row_richness_score($expected[$old_key])) {
                    unset($expected[$old_key]);
                    $expected[$key] = $row;
                    $expected_combos[$combo] = $key;
                }
                continue;
            }
            $expected[$key] = $row;
            $expected_combos[$combo] = $key;
        }

        // Fresh direct map of all current children.
        $existing = [];
        foreach ($this->variation_ids_direct($product_id) as $vid) {
            clean_post_cache($vid);
            $v = wc_get_product($vid);
            if (!$v instanceof WC_Product_Variation) continue;
            $existing[$this->variation_identity($v)] = $vid;
        }

        foreach ($expected as $key => $row) {
            $vid = (int)($existing[$key] ?? 0);
            if (!$vid) {
                $unique = (string)$this->sanmar->first($row, ['UNIQUE_KEY','UNIQUEKEY','PART_ID']);
                $color = (string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']);
                $size = (string)$this->sanmar->first($row, ['SIZE']);
                $vid = $this->find_variation($product_id, $unique, $color, $size);
            }
            if (!$vid && !$allow_create) continue;

            $result = $this->create_or_update_variation($product_id, $brand, $style, $row, $vid);
            if (is_wp_error($result)) {
                ASSS_Logger::log('Deep variation reconcile failed', 'warning', [
                    'product_id' => $product_id,
                    'style' => $style,
                    'identity' => $key,
                    'error' => $result->get_error_message(),
                ]);
                continue;
            }
            $existing[$key] = (int)$result['variation_id'];
        }

        // Rebuild WooCommerce caches BEFORE the guaranteed field pass.
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
        clean_post_cache($product_id);

        $asbo_base = $this->asbo_base_price($product_id);
        $global_maps = [];
        foreach ($expected as $row) {
            $map = (string)$this->sanmar->first($row, ['MAP_PRICE']);
            if ($map !== '' && is_numeric($map) && (float)$map > 0) $global_maps[] = (float)$map;
        }
        $global_map = $global_maps ? max($global_maps) : null;

        // Collect existing valid prices by color as a safe non-wholesale fallback
        // for a blank sibling when ASBO/MAP are not available.
        $price_by_color = [];
        $any_managed_price = null;
        foreach ($this->variation_ids_direct($product_id) as $vid) {
            clean_post_cache($vid);
            $v = wc_get_product($vid);
            if (!$v instanceof WC_Product_Variation) continue;
            $price = $v->get_regular_price('edit');
            if ($price === '' || !is_numeric($price) || (float)$price <= 0) continue;
            $color = strtolower(trim((string)$v->get_meta('_asss_sanmar_color')));
            if ($color !== '') $price_by_color[$color][] = (float)$price;
            if ((string)$v->get_meta('_asss_base_price_managed') === 'yes') $any_managed_price = (float)$price;
        }

        $resolved_ids = [];
        $resolved_combos = [];
        foreach ($expected as $key => $row) {
            $vid = (int)($existing[$key] ?? 0);
            if (!$vid) continue;
            clean_post_cache($vid);
            $v = wc_get_product($vid);
            if (!$v instanceof WC_Product_Variation) continue;

            $color = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
            $size = trim((string)$this->sanmar->first($row, ['SIZE']));
            $unique = trim((string)$this->sanmar->first($row, ['UNIQUE_KEY','UNIQUEKEY','PART_ID']));

            // Re-assert the exact parent attribute combination.
            $attrs = [];
            if ($color !== '') $attrs['pa_color'] = $this->term_slug('pa_color', $color);
            if ($size !== '') $attrs['pa_size'] = $this->term_slug('pa_size', $size);
            $v->set_attributes($attrs);
            $v->set_status('publish');
            $v->set_manage_stock(true);
            $v->set_backorders('no');

            $this->maybe_set_variation_sku($v, $unique);
            $weight = (string)$this->sanmar->first($row, ['PIECE_WEIGHT']);
            if ($weight !== '' && is_numeric($weight) && (float)$weight > 0) {
                $v->set_weight($this->weight_for_store((float)$weight));
                $v->update_meta_data('_asss_supplier_weight_lb', (string)wc_format_decimal($weight));
            }

            // Price guarantee. ASBO is product-level and should make EVERY child
            // purchasable. Managed prices follow ASBO changes; manual prices are
            // never overwritten. If ASBO is absent, use row MAP, a same-color
            // sibling, global MAP, then an existing managed sibling. Wholesale
            // PIECE_PRICE is intentionally never exposed as retail price.
            $managed = (string)$v->get_meta('_asss_base_price_managed') === 'yes';
            $current = $v->get_regular_price('edit');
            $candidate = null;
            $source = '';
            if ($asbo_base !== null && $asbo_base > 0) {
                $candidate = $asbo_base;
                $source = 'asbo-deep-repair';
            } elseif ($current === '' || $managed) {
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

            if ($candidate !== null && $candidate > 0) {
                $can_apply = $current === '';
                if (!$can_apply && $managed) $can_apply = $this->managed_price_can_update($v, (float)$candidate);
                if ($can_apply) $this->apply_managed_base_price($v, (float)$candidate, $source);
            }

            $v->update_meta_data('_asss_supplier', 'sanmar');
            $v->update_meta_data('_asss_supplier_product_key', $this->supplier_product_key($brand, $style));
            $v->update_meta_data('_asss_sanmar_style', $style);
            $v->update_meta_data('_asss_sanmar_brand', $brand);
            $v->update_meta_data('_asss_sanmar_unique_key', $unique);
            $v->update_meta_data('_asss_sanmar_inventory_key', (string)$this->sanmar->first($row, ['INVENTORY_KEY','INVENTORYKEY']));
            $v->update_meta_data('_asss_sanmar_size_index', (string)$this->sanmar->first($row, ['SIZE_INDEX','SIZEINDEX']));
            $v->update_meta_data('_asss_sanmar_color', $color);
            $v->update_meta_data('_asss_sanmar_size', $size);
            $v->update_meta_data('_asss_supplier_cost', (string)$this->sanmar->first($row, ['PIECE_PRICE','PRICE']));
            $v->update_meta_data('_asss_case_price', (string)$this->sanmar->first($row, ['CASE_PRICE']));
            $v->update_meta_data('_asss_supplier_sale_price', (string)$this->sanmar->first($row, ['PIECE_SALE_PRICE']));
            $v->update_meta_data('_asss_map_price', (string)$this->sanmar->first($row, ['MAP_PRICE']));
            $v->update_meta_data('_asss_product_status', (string)$this->sanmar->first($row, ['PRODUCT_STATUS','STATUS']));
            $v->delete_meta_data('_asss_stale_variation');
            $v->delete_meta_data('_asss_stale_variation_reason');
            $v->delete_meta_data('_asss_discontinued_variation');
            $v->save();

            if (!empty($this->sanmar->settings()['sync_images'])) {
                $this->sync_variation_media($vid, $row);
            }
            $resolved_ids[$vid] = true;
            $resolved_combos[$this->row_combo_key($row)] = $vid;
        }

        // Disable stale/phantom combinations instead of deleting them. This is
        // critical for sparse supplier matrices: parent attributes contain the
        // union of sizes, but a Black OSFA-only row must not produce Black SM or
        // Black L/XL simply because those sizes exist for another color.
        $stale_disabled = 0;
        $phantom_disabled = 0;
        $duplicate_combo_disabled = 0;
        foreach ($this->variation_ids_direct($product_id) as $vid) {
            if (isset($resolved_ids[$vid])) continue;
            $v = wc_get_product($vid);
            if (!$v instanceof WC_Product_Variation) continue;
            if ((string)$v->get_meta('_asss_manual_variation_protect') === 'yes') continue;

            $combo = $this->variation_combo_key($v);
            $supplier_managed = (string)$v->get_meta('_asss_supplier') === 'sanmar';
            $reason = '';

            if ($combo !== 'combo:|' && !isset($expected_combos[$combo])) {
                $reason = 'phantom-cartesian-combination';
                $phantom_disabled++;
            } elseif ($combo !== 'combo:|' && isset($resolved_combos[$combo])) {
                $reason = 'duplicate-combination';
                $duplicate_combo_disabled++;
            } elseif ($supplier_managed) {
                $reason = 'supplier-row-no-longer-present';
            } else {
                continue;
            }

            $v->set_status('private');
            $v->set_stock_status('outofstock');
            $v->update_meta_data('_asss_stale_variation', 'yes');
            $v->update_meta_data('_asss_stale_variation_reason', $reason);
            $v->update_meta_data('_asss_stale_variation_at', current_time('mysql'));
            $v->save();
            $stale_disabled++;
        }

        // Run the fallback price repair only after phantom/stale children have
        // been disabled. This prevents synthetic Cartesian combinations from
        // ever receiving a purchasable price.
        $this->repair_missing_variation_prices($product_id);
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
        clean_post_cache($product_id);
        $this->audit_variations($product_id, $expected, $stale_disabled, $phantom_disabled, $duplicate_combo_disabled);
    }

    private function audit_variations(int $product_id, array $expected, int $stale_disabled = 0, int $phantom_disabled = 0, int $duplicate_combo_disabled = 0): void {
        $ids = $this->variation_ids_direct($product_id);
        $by_identity = [];
        $duplicate_identities = 0;
        $missing_price = 0;
        $missing_image = 0;
        $missing_gallery = 0;
        $incomplete_gallery = 0;
        $missing_sku = 0;
        $missing_weight = 0;
        $attribute_mismatch = 0;
        $active_phantom_combinations = 0;

        $expected_combos = [];
        foreach ($expected as $row) {
            $combo = $this->row_combo_key($row);
            if ($combo !== 'combo:|') $expected_combos[$combo] = true;
        }

        foreach ($ids as $vid) {
            clean_post_cache($vid);
            $v = wc_get_product($vid);
            if (!$v instanceof WC_Product_Variation) continue;
            $combo = $this->variation_combo_key($v);
            if ((string)$v->get_meta('_asss_stale_variation') !== 'yes'
                && (string)$v->get_meta('_asss_manual_variation_protect') !== 'yes'
                && $combo !== 'combo:|' && !isset($expected_combos[$combo])) {
                $active_phantom_combinations++;
            }
            if ((string)$v->get_meta('_asss_supplier') !== 'sanmar') continue;
            $identity = $this->variation_identity($v);
            if (isset($by_identity[$identity])) $duplicate_identities++;
            $by_identity[$identity] = $vid;
        }

        $missing_expected = 0;
        foreach ($expected as $key => $row) {
            $vid = (int)($by_identity[$key] ?? 0);
            if (!$vid) {
                $missing_expected++;
                continue;
            }
            $v = wc_get_product($vid);
            if (!$v instanceof WC_Product_Variation) {
                $missing_expected++;
                continue;
            }
            if ($v->get_regular_price('edit') === '' || $v->get_price('edit') === '') $missing_price++;
            if ($v->get_sku('edit') === '') $missing_sku++;
            $expected_weight = (string)$this->sanmar->first($row, ['PIECE_WEIGHT']);
            if ($expected_weight !== '' && is_numeric($expected_weight) && (float)$expected_weight > 0 && $v->get_weight('edit') === '') $missing_weight++;
            if (!empty($this->sanmar->settings()['sync_images'])) {
                if ($this->best_variation_image_url($row) !== '' && !$v->get_image_id()) $missing_image++;
                $expected_gallery = $this->variation_gallery_urls($row);
                if (count($expected_gallery) > 1) {
                    $saved_gallery = $v->get_meta('_asss_variation_gallery_ids');
                    $saved_gallery = is_array($saved_gallery) ? array_values(array_filter(array_map('intval', $saved_gallery))) : [];
                    if (!$saved_gallery) $missing_gallery++;
                    elseif (count($saved_gallery) < count($expected_gallery)) $incomplete_gallery++;
                }
            }

            $attrs = $v->get_attributes();
            $color = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
            $size = trim((string)$this->sanmar->first($row, ['SIZE']));
            if (($color !== '' && (($attrs['pa_color'] ?? '') !== $this->term_slug('pa_color', $color)))
                || ($size !== '' && (($attrs['pa_size'] ?? '') !== $this->term_slug('pa_size', $size)))) {
                $attribute_mismatch++;
            }
        }

        $audit = [
            'expected' => count($expected),
            'supplier_variations' => count($by_identity),
            'missing_expected' => $missing_expected,
            'duplicate_identities' => $duplicate_identities,
            'missing_price' => $missing_price,
            'missing_image_when_available' => $missing_image,
            'missing_variation_gallery' => $missing_gallery,
            'incomplete_variation_gallery' => $incomplete_gallery,
            'missing_sku' => $missing_sku,
            'missing_weight_when_available' => $missing_weight,
            'attribute_mismatch' => $attribute_mismatch,
            'active_phantom_combinations' => $active_phantom_combinations,
            'stale_disabled' => $stale_disabled,
            'phantom_cartesian_disabled' => $phantom_disabled,
            'duplicate_combinations_disabled' => $duplicate_combo_disabled,
            'checked_at' => current_time('mysql'),
        ];
        update_post_meta($product_id, '_asss_last_variation_audit', wp_json_encode($audit));

        $critical = $missing_expected + $duplicate_identities + $missing_price + $attribute_mismatch + $active_phantom_combinations;
        ASSS_Logger::log(
            $critical ? 'Variation audit completed with issues' : 'Variation audit passed',
            $critical ? 'warning' : 'info',
            ['product_id' => $product_id, 'audit' => $audit]
        );
    }

    private function asbo_base_price(int $product_id): ?float {
        $raw = trim((string)get_post_meta($product_id, '_asbo_pricing_matrix', true));
        if ($raw === '') return null;
        $candidates = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) continue;
            [, $tiers_raw] = array_map('trim', explode('|', $line, 2));
            $tiers = [];
            foreach (explode(',', $tiers_raw) as $pair) {
                if (strpos($pair, ':') === false) continue;
                [$qty, $price] = array_map('trim', explode(':', $pair, 2));
                $qty = absint($qty);
                if (!$qty || !is_numeric($price)) continue;
                $value = (float)$price;
                if ($value > 0) $tiers[$qty] = $value;
            }
            if (!$tiers) continue;
            ksort($tiers, SORT_NUMERIC);
            $first_qty = array_key_first($tiers);
            if ($first_qty !== null) $candidates[] = (float)$tiers[$first_qty];
        }
        return $candidates ? max($candidates) : null;
    }

    /**
     * SanMar's Brand CSV exposes PIECE_WEIGHT as approximate weight per piece
     * in pounds. WooCommerce's variable-product Shipping tab is parent-level,
     * while checkout can use variation-specific weights. We populate both:
     * each variation gets its exact supplier weight and the parent gets the
     * heaviest selected variation as a conservative fallback.
     *
     * Dimensions, shipping class, tariff number and country of origin are not
     * present in the Brand CSV feed, so this method intentionally leaves any
     * merchant-entered values for those fields untouched.
     */
    private function sync_parent_shipping(WC_Product_Variable $product, array $rows): void {
        $weights_lb = [];
        foreach ($rows as $row) {
            $weight = (string)$this->sanmar->first($row, ['PIECE_WEIGHT']);
            if ($weight !== '' && is_numeric($weight) && (float)$weight > 0) {
                $weights_lb[] = (float)$weight;
            }
        }
        if (!$weights_lb) return;

        $fallback_lb = max($weights_lb);
        $product->set_weight($this->weight_for_store($fallback_lb));
        $product->update_meta_data('_asss_supplier_weight_lb', (string)wc_format_decimal($fallback_lb));
        $product->update_meta_data('_asss_supplier_weight_strategy', 'max_selected_variation');
    }


    /**
     * Populate dimensions when a supplier provides them, otherwise optionally
     * use merchant-configured fallback dimensions. SanMar Brand CSVs currently
     * do not expose WooCommerce-ready length/width/height fields, so no values
     * are invented automatically.
     */
    private function sync_parent_dimensions(WC_Product_Variable $product, array $rows): void {
        $first = $rows[0] ?? [];
        $length = (string)$this->sanmar->first($first, ['PIECE_LENGTH','PRODUCT_LENGTH','LENGTH']);
        $width  = (string)$this->sanmar->first($first, ['PIECE_WIDTH','PRODUCT_WIDTH','WIDTH']);
        $height = (string)$this->sanmar->first($first, ['PIECE_HEIGHT','PRODUCT_HEIGHT','HEIGHT']);

        $has_supplier_dimensions = is_numeric($length) && (float)$length > 0
            && is_numeric($width) && (float)$width > 0
            && is_numeric($height) && (float)$height > 0;

        if ($has_supplier_dimensions) {
            $product->set_length($this->dimension_for_store((float)$length));
            $product->set_width($this->dimension_for_store((float)$width));
            $product->set_height($this->dimension_for_store((float)$height));
            $product->update_meta_data('_asss_dimension_source', 'supplier');
            return;
        }

        // Never replace dimensions a merchant has already entered.
        if ($product->get_length() !== '' || $product->get_width() !== '' || $product->get_height() !== '') return;

        $settings = $this->sanmar->settings();
        $fl = $settings['fallback_length_in'] ?? '';
        $fw = $settings['fallback_width_in'] ?? '';
        $fh = $settings['fallback_height_in'] ?? '';
        if (!is_numeric($fl) || !is_numeric($fw) || !is_numeric($fh) || (float)$fl <= 0 || (float)$fw <= 0 || (float)$fh <= 0) return;

        $product->set_length($this->dimension_for_store((float)$fl));
        $product->set_width($this->dimension_for_store((float)$fw));
        $product->set_height($this->dimension_for_store((float)$fh));
        $product->update_meta_data('_asss_dimension_source', 'merchant_fallback_inches');
    }

    private function dimension_for_store(float $inches): string {
        $store_unit = (string)get_option('woocommerce_dimension_unit', 'in');
        $converted = $inches;
        if (function_exists('wc_get_dimension')) {
            $converted = (float)wc_get_dimension($inches, $store_unit, 'in');
        }
        return (string)wc_format_decimal($converted);
    }

    /**
     * Integrate supplier imports with the All Star Bulk Order fields. These meta
     * keys are intentionally written only when blank, so merchant custom copy
     * and pricing remain authoritative.
     */
    private function sync_bulk_order_fields(WC_Product_Variable $product, array $first, array $rows, bool $is_new): void {
        $settings = $this->sanmar->settings();
        if (empty($settings['sync_bulk_order_fields'])) {
            $this->sync_parent_dimensions($product, $rows);
            return;
        }

        $title = trim((string)$this->sanmar->first($first, ['PRODUCT_TITLE','PRODUCT_NAME','TITLE']));
        $description = trim(wp_strip_all_tags((string)$this->sanmar->first($first, ['PRODUCT_DESCRIPTION','DESCRIPTION'])));
        $available_sizes = trim((string)$this->sanmar->first($first, ['AVAILABLE_SIZES']));
        $spec_sheet = esc_url_raw((string)$this->sanmar->first($first, ['SPEC_SHEET']));
        $style = trim((string)$this->sanmar->first($first, ['STYLE#','STYLE','STYLE_NUMBER']));
        $brand = trim((string)$this->sanmar->first($first, ['BRAND_NAME']));

        if ((string)$product->get_meta('_asbo_display_name') === '' && $title !== '') {
            $product->update_meta_data('_asbo_display_name', $title);
        }

        if ((string)$product->get_meta('_asbo_short_description') === '' && $description !== '') {
            $product->update_meta_data('_asbo_short_description', wp_trim_words($description, 55, '…'));
        }

        if ((string)$product->get_meta('_asbo_size_chart') === '') {
            $parts = [];
            if ($available_sizes !== '') $parts[] = '<strong>Available sizes:</strong> ' . esc_html($available_sizes);
            if ($brand !== '' || $style !== '') {
                $label = trim($brand . ($brand && $style ? ' · ' : '') . ($style ? 'Style ' . $style : ''));
                if ($label !== '') $parts[] = '<strong>Supplier:</strong> ' . esc_html($label);
            }
            if ($spec_sheet !== '') {
                $parts[] = '<a href="' . esc_url($spec_sheet) . '" target="_blank" rel="noopener noreferrer">View manufacturer product specifications (PDF)</a>';
            }
            if ($parts) $product->update_meta_data('_asbo_size_chart', implode("<br>
", $parts));
        }

        if (!empty($settings['auto_enable_bulk_order']) && (string)$product->get_meta('_asbo_enabled') === '') {
            $product->update_meta_data('_asbo_enabled', 'yes');
        }

        // The managed ASBO pricing matrix is generated after real variations
        // are reconciled, when their exact supplier wholesale costs are known.
        $this->sync_parent_dimensions($product, $rows);
    }

    /**
     * Convert SanMar pounds to the WooCommerce store's configured weight unit.
     * For this store the configured unit is lbs, so the value is unchanged.
     */
    private function weight_for_store(float $weight_lb): string {
        $store_unit = (string)get_option('woocommerce_weight_unit', 'lbs');
        $converted = $weight_lb;
        if (function_exists('wc_get_weight')) {
            $converted = (float)wc_get_weight($weight_lb, $store_unit, 'lbs');
        }
        return (string)wc_format_decimal($converted);
    }

    private function filter_rows_for_product(int $product_id, array $rows): array {
        $mode = (string)get_post_meta($product_id, '_asss_sanmar_color_selection_mode', true) ?: (string)get_post_meta($product_id, '_asss_color_selection_mode', true);
        if ($mode === '' || $mode === 'all') return $rows;

        $selected_raw = (string)get_post_meta($product_id, '_asss_sanmar_selected_colors', true) ?: (string)get_post_meta($product_id, '_asss_selected_colors', true);
        $selected = json_decode($selected_raw, true);
        if (!is_array($selected) || !$selected) return $rows;
        $lookup = array_fill_keys(array_map('strval', $selected), true);

        return array_values(array_filter($rows, function($row) use ($lookup) {
            $color = (string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']);
            return $color !== '' && isset($lookup[$color]);
        }));
    }

    private function colors_from_rows(array $rows): array {
        $colors = [];
        foreach ($rows as $row) {
            $color = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
            if ($color !== '') $colors[$color] = true;
        }
        return array_keys($colors);
    }

    private function set_attributes(WC_Product_Variable $product, array $rows): void {
        $colors = [];
        $sizes = [];
        foreach ($rows as $row) {
            $c = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
            $z = trim((string)$this->sanmar->first($row, ['SIZE']));
            if ($c !== '') $colors[$c] = true;
            if ($z !== '') $sizes[$z] = true;
        }

        // Supplier Sync owns Color and Size for supplier-linked products, but it
        // must not erase attributes added manually or by another plugin. Older
        // builds replaced the full parent attribute collection, which could
        // silently delete custom merchandising/specification attributes.
        $preserved = [];
        foreach ($product->get_attributes('edit') as $existing) {
            if (!$existing instanceof WC_Product_Attribute) continue;
            $name = (string)$existing->get_name();
            $normalized = $existing->is_taxonomy()
                ? sanitize_title(preg_replace('/^pa_/', '', $name))
                : sanitize_title($name);
            if ($name === 'pa_color' || $name === 'pa_size' || $normalized === 'color' || $normalized === 'size') {
                continue;
            }
            $preserved[] = $existing;
            if ($existing->get_variation()) {
                ASSS_Logger::log('Preserving a non-supplier variation attribute on a supplier product; review WooCommerce matching behavior.', 'warning', [
                    'product_id' => $product->get_id(),
                    'attribute' => $name,
                ]);
            }
        }

        $attrs = [];
        $position = 0;
        if ($colors) $attrs[] = $this->taxonomy_attribute('pa_color', 'Color', array_keys($colors), $position++);
        if ($sizes) $attrs[] = $this->taxonomy_attribute('pa_size', 'Size', array_keys($sizes), $position++);
        foreach ($preserved as $existing) {
            $existing->set_position($position++);
            $attrs[] = $existing;
        }
        $product->set_attributes($attrs);
    }

    private function taxonomy_attribute(string $taxonomy, string $label, array $values, int $position): WC_Product_Attribute {
        $attribute_id = $this->ensure_attribute_taxonomy($taxonomy, $label);
        $term_ids = [];
        foreach ($values as $value) {
            $term_id = $this->ensure_term($taxonomy, (string)$value);
            if ($term_id) $term_ids[] = $term_id;
        }

        $a = new WC_Product_Attribute();
        $a->set_id($attribute_id);
        $a->set_name($taxonomy);
        // Global taxonomy attributes must store term IDs on the parent. Older
        // versions of this plugin stored term names, which exported as blank
        // attribute values and left variations detached from the parent options.
        $a->set_options(array_values(array_unique(array_map('intval', $term_ids))));
        $a->set_position($position);
        $a->set_visible(true);
        $a->set_variation(true);
        return $a;
    }

    private function ensure_attribute_taxonomy(string $taxonomy, string $label): int {
        $attribute_id = (int)wc_attribute_taxonomy_id_by_name($taxonomy);
        if (!$attribute_id) {
            $slug = preg_replace('/^pa_/', '', $taxonomy);
            $created = wc_create_attribute([
                'name' => $label,
                'slug' => $slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);
            if (!is_wp_error($created)) $attribute_id = (int)$created;
            delete_transient('wc_attribute_taxonomies');
        }

        if (!taxonomy_exists($taxonomy)) {
            if (function_exists('wc_register_product_attributes')) wc_register_product_attributes();
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, ['product'], [
                    'hierarchical' => false,
                    'show_ui' => false,
                    'query_var' => true,
                    'rewrite' => false,
                    'labels' => ['name' => $label],
                ]);
            }
        }
        return $attribute_id;
    }

    private function ensure_term(string $taxonomy, string $value): int {
        if ($value === '') return 0;
        $existing = term_exists($value, $taxonomy);
        if (!$existing) $existing = wp_insert_term($value, $taxonomy);
        if (is_wp_error($existing)) {
            // A slug collision can occur with punctuation-heavy supplier color
            // names. Resolve the existing term by slug before giving up.
            $term = get_term_by('slug', sanitize_title($value), $taxonomy);
            return $term && !is_wp_error($term) ? (int)$term->term_id : 0;
        }
        return (int)(is_array($existing) ? $existing['term_id'] : $existing);
    }

    private function term_slug(string $taxonomy, string $value): string {
        if ($taxonomy === 'pa_size') {
            $size_key = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)));
            if (in_array($size_key, ['os','osfa','onesize','onesizefitsall'], true)) $value = 'OSFA';
        }
        $term_id = $this->ensure_term($taxonomy, $value);
        if ($term_id) {
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) return (string)$term->slug;
        }
        return sanitize_title($value);
    }

    /** Collect every distinct category signal present in supplier rows. */
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

    private function sync_tags(int $product_id, string $keywords): void {
        $new_tags = $this->parse_keywords($keywords);
        $previous = json_decode((string)get_post_meta($product_id, '_asss_supplier_tags', true), true);
        if (!is_array($previous)) $previous = [];
        $current = wp_get_object_terms($product_id, 'product_tag', ['fields' => 'names']);
        if (is_wp_error($current)) $current = [];
        $manual = array_values(array_diff(array_map('strval', $current), array_map('strval', $previous)));
        $merged = array_values(array_unique(array_merge($manual, $new_tags)));
        wp_set_object_terms($product_id, $merged, 'product_tag', false);
        update_post_meta($product_id, '_asss_supplier_tags', wp_json_encode($new_tags));
    }

    private function parse_keywords(string $keywords): array {
        $keywords = trim(wp_strip_all_tags($keywords));
        if ($keywords === '') return [];
        $parts = preg_split('/\s{2,}|[,;|]+/u', $keywords) ?: [];
        $tags = [];
        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part));
            if ($part !== '' && mb_strlen($part) <= 80) $tags[$part] = true;
        }
        return array_keys($tags);
    }

    private function sync_brand(int $product_id, string $brand): void {
        $brand = trim($brand);
        if ($brand === '') return;
        update_post_meta($product_id, '_asss_supplier_brand_name', $brand);
        if (!taxonomy_exists('product_brand')) return;
        $term = term_exists($brand, 'product_brand');
        if (!$term) $term = wp_insert_term($brand, 'product_brand');
        if (!is_wp_error($term)) {
            $term_id = (int)(is_array($term) ? $term['term_id'] : $term);
            wp_set_object_terms($product_id, [$term_id], 'product_brand', false);
        }
    }

    private function maybe_set_parent_sku(WC_Product $product, string $style, string $brand = ''): void {
        if ($product->get_sku() !== '' || $style === '') return;
        $candidates = [$style];
        $fallback = sanitize_title('SANMAR-' . $brand . '-' . $style);
        if ($fallback !== '') $candidates[] = strtoupper($fallback);
        foreach (array_values(array_unique($candidates)) as $candidate) {
            $existing = (int)wc_get_product_id_by_sku($candidate);
            if (!$existing || $existing === $product->get_id()) {
                try { $product->set_sku($candidate); } catch (Exception $e) {}
                if ($product->get_sku() !== '') return;
            }
        }
    }

    private function maybe_set_variation_sku(WC_Product_Variation $variation, string $unique): void {
        if ($variation->get_sku() !== '' || $unique === '') return;
        $existing = (int)wc_get_product_id_by_sku($unique);
        if (!$existing || $existing === $variation->get_id()) {
            try { $variation->set_sku($unique); } catch (Exception $e) {}
        }
    }

    public function find_product(string $style, string $brand = ''): int {
        $meta = [
            ['key'=>'_asss_sanmar_style','value'=>$style],
        ];
        if ($brand !== '') $meta[] = ['key'=>'_asss_sanmar_brand','value'=>$brand];
        $q = new WP_Query([
            'post_type'=>'product', 'post_status'=>'any', 'fields'=>'ids', 'posts_per_page'=>2,
            'meta_query'=>$meta,
        ]);
        foreach ((array)$q->posts as $id) {
            $sources=$this->multi->product_sources((int)$id);
            if (!empty($sources['sanmar']['enabled'])) return (int)$id;
        }

        // Legacy migration safety: older plugin versions did not include brand in
        // product identity. Adopt a style-only product only when it is unambiguous
        // and its stored brand is blank or matches this brand case-insensitively.
        if ($brand !== '') {
            $legacy = new WP_Query([
                'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>3,
                'meta_query'=>[
                    ['key'=>'_asss_sanmar_style','value'=>$style],
                ],
            ]);
            if (count($legacy->posts) === 1) {
                $id = (int)$legacy->posts[0];
                $stored = (string)get_post_meta($id, '_asss_sanmar_brand', true);
                if (($stored === '' || strcasecmp($stored, $brand) === 0) && !empty($this->multi->product_sources($id)['sanmar']['enabled'])) return $id;
            }
        }
        return 0;
    }

    private function find_variation(int $parent, string $unique, string $color, string $size): int {
        if ($unique) {
            $q = new WP_Query([
                'post_type' => 'product_variation',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'post_parent' => $parent,
                'meta_query' => [['key' => '_asss_sanmar_unique_key', 'value' => $unique]],
            ]);
            if (!empty($q->posts[0])) return (int)$q->posts[0];
        }
        $q = new WP_Query([
            'post_type' => 'product_variation',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'post_parent' => $parent,
            'meta_query' => [
                ['key' => '_asss_sanmar_color', 'value' => $color],
                ['key' => '_asss_sanmar_size', 'value' => $size],
            ],
        ]);
        return (int)($q->posts[0] ?? 0);
    }

    private function sync_parent_media(int $product_id, array $row): void {
        $product = wc_get_product($product_id);
        if (!$product) return;

        $reference = [];
        foreach (['COLOR_SWATCH_IMAGE','COLOR_SQUARE_IMAGE'] as $key) {
            $url = trim((string)$this->sanmar->first($row, [$key]));
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) $reference[$url] = true;
        }
        $this->save_supplier_reference_media($product_id, 'sanmar', array_keys($reference));

        $featured_url = '';
        foreach (['FRONT_FLAT','COLOR_PRODUCT_IMAGE','PRODUCT_IMAGE','THUMBNAIL_IMAGE'] as $key) {
            $candidate = trim((string)$this->sanmar->first($row, [$key]));
            if ($candidate && !isset($reference[$candidate]) && filter_var($candidate, FILTER_VALIDATE_URL) && !$this->is_invalid_storefront_media_url($candidate)) {
                $featured_url = $candidate;
                break;
            }
        }
        if ($featured_url !== '') {
            $current = (int)$product->get_image_id();
            if (!$current || get_post_meta($current, '_asss_image_source', true) === 'sanmar') {
                $aid = $this->sideload($featured_url, $product_id, 'sanmar');
                if ($aid) $product->set_image_id($aid);
            }
        }

        $gallery_urls = [];
        foreach (['FRONT_FLAT','COLOR_PRODUCT_IMAGE','BACK_FLAT','FRONT_MODEL','BACK_MODEL','SIDE_MODEL','THREE_Q_MODEL','PRODUCT_IMAGE','THUMBNAIL_IMAGE'] as $key) {
            $url = trim((string)$this->sanmar->first($row, [$key]));
            if ($url && $url !== $featured_url && !isset($reference[$url]) && filter_var($url, FILTER_VALIDATE_URL) && !$this->is_invalid_storefront_media_url($url)) $gallery_urls[$url] = true;
        }

        $supplier_gallery = [];
        foreach (array_keys($gallery_urls) as $url) {
            $aid = $this->sideload($url, $product_id, 'sanmar');
            if ($aid) $supplier_gallery[] = $aid;
        }

        $manual_gallery = [];
        foreach ($product->get_gallery_image_ids() as $id) {
            $id = (int)$id;
            if ($id && !$this->is_supplier_attachment($id)) $manual_gallery[] = $id;
        }
        $product->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_gallery, $manual_gallery))));
        $product->save();
    }

    private function sync_variation_media(int $variation_id, array $row): void {
        $urls = $this->variation_gallery_urls($row);
        if (!$urls) return;

        $v = wc_get_product($variation_id);
        if (!$v || !($v instanceof WC_Product_Variation)) return;

        $supplier_ids = [];
        foreach ($urls as $url) {
            $aid = $this->sideload($url, $variation_id);
            if ($aid) $supplier_ids[] = (int)$aid;
        }
        $supplier_ids = array_values(array_unique(array_filter($supplier_ids)));
        if (!$supplier_ids) return;

        // Preserve a merchant-selected primary image. Otherwise the first
        // supplier gallery image becomes the variation's primary image.
        $current_primary = (int)$v->get_image_id();
        $manual_primary = $current_primary && get_post_meta($current_primary, '_asss_image_source', true) !== 'sanmar';
        if (!$manual_primary) {
            $v->set_image_id($supplier_ids[0]);
            $v->update_meta_data('_asss_resolved_variation_image_url', esc_url_raw($urls[0]));
        }

        // WooCommerce 10.9+ exposes a native variation gallery. Preserve any
        // manually-added gallery attachments while replacing supplier-managed
        // angles with the current SanMar set.
        $manual_gallery = [];
        if (method_exists($v, 'get_gallery_image_ids')) {
            foreach ((array)$v->get_gallery_image_ids() as $id) {
                $id = (int)$id;
                if ($id && get_post_meta($id, '_asss_image_source', true) !== 'sanmar') $manual_gallery[] = $id;
            }
        }

        $primary_id = $manual_primary ? $current_primary : (int)$supplier_ids[0];
        $supplier_extra = array_values(array_filter($supplier_ids, fn($id) => (int)$id !== $primary_id));
        $native_gallery = array_values(array_unique(array_merge($supplier_extra, $manual_gallery)));
        if (method_exists($v, 'set_gallery_image_ids')) {
            $v->set_gallery_image_ids($native_gallery);
        }

        // Compatibility/fallback metadata stores the complete ordered gallery,
        // including the primary image. This is also convenient for ASBO/custom
        // storefront components even when WooCommerce's feature toggle is off.
        $full_order = $manual_primary
            ? array_values(array_unique(array_merge([$current_primary], $supplier_ids, $manual_gallery)))
            : array_values(array_unique(array_merge($supplier_ids, $manual_gallery)));
        $v->update_meta_data('_asss_variation_gallery_ids', $full_order);
        $v->update_meta_data('_asss_variation_gallery_urls', $urls);
        $v->update_meta_data('_asss_variation_gallery_supplier_count', count($supplier_ids));
        $v->save();
    }

    private function sideload(string $url, int $parent, string $source = 'sanmar'): int {
        $url = trim($url);
        if ($this->is_invalid_storefront_media_url($url)) return 0;
        if (!filter_var($url, FILTER_VALIDATE_URL)) return 0;
        if (isset($this->sideload_cache[$url])) return (int)$this->sideload_cache[$url];

        $existing = get_posts([
            'post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>1,
            'meta_key'=>'_asss_image_url','meta_value'=>$url,'no_found_rows'=>true,
        ]);
        if (!empty($existing[0])) return $this->sideload_cache[$url] = (int)$existing[0];

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $id = media_sideload_image($url, $parent, null, 'id');
        if (is_wp_error($id)) {
            ASSS_Logger::log('Image download failed', 'warning', ['url'=>$url,'error'=>$id->get_error_message()]);
            $this->sideload_cache[$url] = 0;
            return 0;
        }
        update_post_meta($id, '_asss_image_source', sanitize_key($source) ?: 'supplier');
        update_post_meta($id, '_asss_image_url', $url);
        return $this->sideload_cache[$url] = (int)$id;
    }

    /**
     * Import one cached S&S style into WooCommerce using only exact supplier SKU
     * rows. This intentionally refuses to create a duplicate when a SanMar-linked
     * product with the same Brand + Style already exists; multi-supplier linking
     * is handled separately so Step 6 cannot silently fork one storefront item.
     */
    public function import_ss_style(int $brand_id, int $style_id, array $selected_colors = []) {
        $data = $this->ss->style_product($brand_id, $style_id);
        if (is_wp_error($data)) return $data;
        if (strtolower((string)($data['supplier'] ?? 'ss')) !== 'ss') return new WP_Error('ss_supplier', 'Cached product is not an S&S product.');

        $brand = trim((string)($data['brand'] ?? ''));
        $style = trim((string)($data['style'] ?? ''));
        if ($brand === '' || $style === '') return new WP_Error('ss_mapping', 'Cached S&S product is missing its brand or style number.');

        $variants = isset($data['variants']) && is_array($data['variants']) ? $data['variants'] : [];
        if (!$variants) return new WP_Error('ss_variants', 'No real S&S SKU rows were cached for this style.');

        $all_colors = $this->ss_colors_from_variants($variants);
        $selected_colors = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_colors))));
        if (!$selected_colors) return new WP_Error('no_colors', 'Choose at least one color before importing.');
        $lookup = array_fill_keys(array_map('strval', $selected_colors), true);
        $variants = array_values(array_filter($variants, static function($row) use ($lookup) {
            $color = trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            return $color !== '' && isset($lookup[$color]);
        }));
        if (!$variants) return new WP_Error('no_variants', 'The selected colors did not contain any real S&S variations.');

        $product_id = $this->find_ss_product($style_id, $brand_id);
        if($product_id && (string)get_post_meta($product_id,'_asss_supplier',true)==='multi') {
            return $this->link_ss_style_to_product($product_id,$brand_id,$style_id,$selected_colors);
        }
        if (!$product_id) {
            $sanmar_match = $this->find_product($style, $brand);
            if ($sanmar_match) {
                return new WP_Error(
                    'existing_other_supplier',
                    'A SanMar-linked WooCommerce product already appears to match ' . $brand . ' ' . $style . ' (product #' . $sanmar_match . '). Supplier Sync will not create a duplicate. Use the Link S&S button on the review screen to attach S&S to that existing product instead of creating a duplicate.'
                );
            }
        }

        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Variable();
        if (!$product instanceof WC_Product_Variable) return new WP_Error('product', 'Could not initialize the WooCommerce variable product.');
        $is_new = !$product_id;

        $title = trim((string)($data['title'] ?? $style));
        $description = trim((string)($data['description'] ?? ''));
        $categories = $this->categories_from_normalized_product($data);
        $keywords = is_array($data['keywords'] ?? null) ? implode(', ', $data['keywords']) : (string)($data['keywords'] ?? '');

        if ($is_new) {
            $product->set_name($title ?: ($brand . ' ' . $style));
            $product->set_status('draft');
            $product->set_catalog_visibility('visible');
        } elseif ($product->get_name() === '') {
            $product->set_name($title ?: ($brand . ' ' . $style));
        }
        if (!empty($this->sanmar->settings()['sync_description'])) $this->sync_supplier_description($product, $description, $is_new);
        $this->maybe_set_ss_parent_sku($product, $brand, $style);

        $selection_mode = count($selected_colors) >= count($all_colors) ? 'all' : 'selected';
        $product->update_meta_data('_asss_supplier', 'ss');
        $product->update_meta_data('_asss_supplier_product_key', 'ss|' . $brand_id . '|' . $style_id);
        $product->update_meta_data('_asss_ss_brand_id', $brand_id);
        $product->update_meta_data('_asss_ss_brand', $brand);
        $product->update_meta_data('_asss_ss_style_id', $style_id);
        $product->update_meta_data('_asss_ss_style', $style);
        $product->update_meta_data('_asss_sync_enabled', 'yes');
        $product->update_meta_data('_asss_color_selection_mode', $selection_mode);
        $product->update_meta_data('_asss_selected_colors', wp_json_encode($selected_colors));
        $product->update_meta_data('_asss_ss_specs', wp_json_encode((array)($data['specs'] ?? [])));
        $product->update_meta_data('_asss_ss_part_number', sanitize_text_field((string)($data['part_number'] ?? '')));
        $brand_meta = $this->ss->brand_meta($brand_id);
        $product->update_meta_data('_asss_e_retailing_restricted', !empty($brand_meta['e_retailing_restricted']) ? 'yes' : 'no');
        $product->update_meta_data('_asss_ss_new_style', !empty($data['new_style']) ? 'yes' : 'no');
        $product->update_meta_data('_asss_ss_sustainable_style', !empty($data['sustainable_style']) ? 'yes' : 'no');
        $product_id = $product->save();
        $this->multi->register_product_source($product_id, 'ss', [
            'brand_id'=>$brand_id,'style_id'=>$style_id,'brand'=>$brand,'style'=>$style,
            'selection_mode'=>$selection_mode,'selected_colors'=>$selected_colors,
        ]);
        update_post_meta($product_id, '_asss_ss_color_selection_mode', $selection_mode);
        update_post_meta($product_id, '_asss_ss_selected_colors', wp_json_encode($selected_colors));

        $this->sync_taxonomies($product_id, $brand, $categories, $keywords, $is_new, 'ss');
        $common_rows = $this->ss_common_rows($variants, $data);
        $this->set_attributes($product, $common_rows);
        $this->sync_parent_shipping($product, $common_rows);
        $this->sync_ss_bulk_order_fields($product, $data, $variants, $is_new);
        $product->save();

        $audit = $this->reconcile_ss_variations($product_id, $variants, true);
        if (!empty($this->sanmar->settings()['sync_images'])) $this->sync_ss_parent_media($product_id, $data, $variants);
        $this->sync_managed_pricing_for_product($product_id);

        $product = wc_get_product($product_id);
        if ($product instanceof WC_Product_Variable) {
            $product->update_meta_data('_asss_last_product_sync', current_time('mysql'));
            $product->save();
        }
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
        do_action('asss_product_synced', $product_id, 'ss', ['brand_id'=>$brand_id,'brand'=>$brand,'style_id'=>$style_id,'style'=>$style,'mode'=>'import']);
        ASSS_Logger::log('Imported/updated S&S product', 'info', [
            'product_id'=>$product_id,'brand_id'=>$brand_id,'brand'=>$brand,'style_id'=>$style_id,'style'=>$style,
            'selected_colors'=>count($selected_colors),'expected_variations'=>(int)($audit['expected'] ?? count($variants)),
        ]);
        return $product_id;
    }

    public function find_ss_product(int $style_id, int $brand_id = 0): int {
        $meta = [
            ['key'=>'_asss_ss_style_id','value'=>$style_id],
        ];
        if ($brand_id > 0) $meta[] = ['key'=>'_asss_ss_brand_id','value'=>$brand_id];
        $q = new WP_Query([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>3,'meta_query'=>$meta,
        ]);
        foreach ((array)$q->posts as $id) if (!empty($this->multi->product_sources((int)$id)['ss']['enabled'])) return (int)$id;
        return 0;
    }

    public function find_ss_product_by_brand_style(string $brand, string $style): int {
        $q = new WP_Query([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>3,
            'meta_query'=>[
                ['key'=>'_asss_ss_brand','value'=>$brand],
                ['key'=>'_asss_ss_style','value'=>$style],
            ],
        ]);
        foreach ((array)$q->posts as $id) {
            $stored_brand=(string)get_post_meta((int)$id,'_asss_ss_brand',true);
            $stored_style=(string)get_post_meta((int)$id,'_asss_ss_style',true);
            if (strcasecmp($stored_brand,$brand)===0 && strcasecmp($stored_style,$style)===0 && !empty($this->multi->product_sources((int)$id)['ss']['enabled'])) return (int)$id;
        }
        return 0;
    }

    private function update_ss_style(int $product_id) {
        $brand_id = absint(get_post_meta($product_id, '_asss_ss_brand_id', true));
        $style_id = absint(get_post_meta($product_id, '_asss_ss_style_id', true));
        if (!$brand_id || !$style_id) return new WP_Error('mapping', 'Product is missing its S&S brand/style mapping.');
        $data = $this->ss->style_product($brand_id, $style_id);
        if (is_wp_error($data)) return $data;
        $variants = isset($data['variants']) && is_array($data['variants']) ? $data['variants'] : [];
        if (!$variants) return new WP_Error('ss_variants', 'No real S&S SKU rows were cached for this style.');

        $mode = (string)get_post_meta($product_id, '_asss_ss_color_selection_mode', true) ?: (string)get_post_meta($product_id, '_asss_color_selection_mode', true);
        if ($mode !== '' && $mode !== 'all') {
            $selected_raw = (string)get_post_meta($product_id, '_asss_ss_selected_colors', true) ?: (string)get_post_meta($product_id, '_asss_selected_colors', true);
            $selected = json_decode($selected_raw, true);
            if (is_array($selected) && $selected) {
                $lookup = array_fill_keys(array_map('strval', $selected), true);
                $variants = array_values(array_filter($variants, static function($row) use ($lookup) {
                    $color = trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
                    return $color !== '' && isset($lookup[$color]);
                }));
            }
        }
        if (!$variants) return new WP_Error('no_variants', 'No S&S variations remain after applying this product\'s saved color selection.');

        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product_Variable) return new WP_Error('product', 'Product missing or is not variable.');
        $brand = trim((string)($data['brand'] ?? get_post_meta($product_id, '_asss_ss_brand', true)));
        $style = trim((string)($data['style'] ?? get_post_meta($product_id, '_asss_ss_style', true)));
        if (!empty($this->sanmar->settings()['sync_description'])) $this->sync_supplier_description($product, (string)($data['description'] ?? ''), false);
        $this->maybe_set_ss_parent_sku($product, $brand, $style);
        $product->update_meta_data('_asss_supplier_product_key', 'ss|' . $brand_id . '|' . $style_id);
        $product->update_meta_data('_asss_ss_brand', $brand);
        $product->update_meta_data('_asss_ss_style', $style);
        $product->update_meta_data('_asss_ss_specs', wp_json_encode((array)($data['specs'] ?? [])));
        $product->update_meta_data('_asss_last_product_sync', current_time('mysql'));

        $categories = $this->categories_from_normalized_product($data);
        $keywords = is_array($data['keywords'] ?? null) ? implode(', ', $data['keywords']) : (string)($data['keywords'] ?? '');
        $this->sync_taxonomies($product_id, $brand, $categories, $keywords, false, 'ss');

        $common_rows = $this->ss_common_rows($variants, $data);
        $this->set_attributes($product, $common_rows);
        $this->sync_parent_shipping($product, $common_rows);
        $this->sync_ss_bulk_order_fields($product, $data, $variants, false);
        $product->save();
        $this->reconcile_ss_variations($product_id, $variants, !empty($this->sanmar->settings()['sync_new_variations']));
        if (!empty($this->sanmar->settings()['sync_images'])) $this->sync_ss_parent_media($product_id, $data, $variants);
        $this->sync_managed_pricing_for_product($product_id);
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
        do_action('asss_product_synced', $product_id, 'ss', ['brand_id'=>$brand_id,'brand'=>$brand,'style_id'=>$style_id,'style'=>$style,'mode'=>'repair']);
        return $product_id;
    }

    private function ss_colors_from_variants(array $variants): array {
        $colors = [];
        foreach ($variants as $row) {
            if (!is_array($row)) continue;
            $color = trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            if ($color !== '') $colors[$color] = true;
        }
        return array_keys($colors);
    }

    /** Convert normalized S&S rows into the small common field subset used by parent helpers. */
    private function ss_common_rows(array $variants, array $product = []): array {
        $rows = [];
        $sizes = [];
        foreach ($variants as $v) if (is_array($v) && trim((string)($v['size'] ?? '')) !== '') $sizes[trim((string)$v['size'])] = true;
        $available_sizes = implode(', ', array_keys($sizes));
        foreach ($variants as $v) {
            if (!is_array($v)) continue;
            $rows[] = [
                'UNIQUE_KEY'=>(string)($v['unique_key'] ?? $v['supplier_sku_id'] ?? ''),
                'INVENTORY_KEY'=>(string)($v['sku'] ?? $v['inventory_key'] ?? ''),
                'COLOR_NAME'=>(string)($v['color'] ?? $v['catalog_color'] ?? ''),
                'CATALOG_COLOR'=>(string)($v['catalog_color'] ?? $v['color'] ?? ''),
                'SIZE'=>(string)($v['size'] ?? ''),
                'PIECE_WEIGHT'=>$v['weight_lb'] ?? '',
                'MAP_PRICE'=>$v['map_price'] ?? '',
                'PRODUCT_STATUS'=>(string)($v['status'] ?? 'active'),
                'PRODUCT_TITLE'=>(string)($product['title'] ?? ''),
                'PRODUCT_DESCRIPTION'=>(string)($product['description'] ?? ''),
                'AVAILABLE_SIZES'=>$available_sizes,
                'STYLE#'=>(string)($product['style'] ?? ''),
                'BRAND_NAME'=>(string)($product['brand'] ?? ''),
            ];
        }
        return $rows;
    }

    private function maybe_set_ss_parent_sku(WC_Product $product, string $brand, string $style): void {
        if ($product->get_sku() !== '' || $style === '') return;
        $candidates = [$style, strtoupper(sanitize_title('SS-' . $brand . '-' . $style))];
        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            $existing = (int)wc_get_product_id_by_sku($candidate);
            if (!$existing || $existing === $product->get_id()) {
                try { $product->set_sku($candidate); } catch (Exception $e) {}
                if ($product->get_sku() !== '') return;
            }
        }
    }

    private function ss_variation_price(int $product_id, array $row): array {
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

    private function find_ss_variation(int $parent_id, string $supplier_id, string $color, string $size): int {
        if ($supplier_id !== '') {
            $q = new WP_Query([
                'post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'post_parent'=>$parent_id,
                'meta_query'=>[['key'=>'_asss_ss_sku_id','value'=>$supplier_id]],
            ]);
            if (!empty($q->posts[0])) return (int)$q->posts[0];
        }
        $q = new WP_Query([
            'post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'post_parent'=>$parent_id,
            'meta_query'=>[
                ['key'=>'_asss_ss_color','value'=>$color],
                ['key'=>'_asss_ss_size','value'=>$size],
            ],
        ]);
        return (int)($q->posts[0] ?? 0);
    }

    private function sync_ss_variation(int $product_id, array $row): array {
        $supplier_id = trim((string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? ''));
        $sku = trim((string)($row['sku'] ?? $row['inventory_key'] ?? ''));
        $color = trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
        $size = trim((string)($row['size'] ?? ''));
        if ($color === '' || $size === '') return ['created'=>false,'variation_id'=>0];
        $variation_id = $this->find_ss_variation($product_id, $supplier_id, $color, $size);
        $created = !$variation_id;
        $v = $variation_id ? wc_get_product($variation_id) : new WC_Product_Variation();
        if (!$v instanceof WC_Product_Variation) return ['created'=>false,'variation_id'=>0];
        if ($created) $v->set_parent_id($product_id);

        $v->set_status('publish');
        $v->set_attributes([
            'pa_color'=>$this->term_slug('pa_color', $color),
            'pa_size'=>$this->term_slug('pa_size', $size),
        ]);
        $existing_sku = $v->get_sku('edit');
        if ($existing_sku === '' && $sku !== '') {
            $candidate = $sku;
            $conflict = (int)wc_get_product_id_by_sku($candidate);
            if ($conflict && $conflict !== $v->get_id()) $candidate = 'SS-' . $sku;
            $conflict = (int)wc_get_product_id_by_sku($candidate);
            if (!$conflict || $conflict === $v->get_id()) {
                try { $v->set_sku($candidate); } catch (Exception $e) {}
            }
        }

        $weight = $row['weight_lb'] ?? null;
        if ($weight !== null && $weight !== '' && is_numeric($weight) && (float)$weight > 0) {
            $v->set_weight($this->weight_for_store((float)$weight));
            $v->update_meta_data('_asss_supplier_weight_lb', (string)wc_format_decimal((float)$weight));
        }
        $qty = $row['qty'] ?? null;
        if ($qty !== null && $qty !== '' && is_numeric($qty)) {
            $qty = max(0, (int)$qty);
            $v->set_manage_stock(true);
            $v->set_backorders('no');
            $v->set_stock_quantity($qty);
            $v->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            $v->update_meta_data('_asss_inventory_snapshot_at', current_time('mysql'));
        }

        [$price, $price_source] = $this->ss_variation_price($product_id, $row);
        if (!empty($this->sanmar->settings()['sync_variation_base_prices']) && $price !== null && $price > 0) {
            $current = $v->get_regular_price('edit');
            $managed = (string)$v->get_meta('_asss_base_price_managed') === 'yes';
            if ($current === '') {
                $this->apply_managed_base_price($v, (float)$price, $price_source);
            } elseif ($managed && $this->managed_price_can_update($v, (float)$price)) {
                $this->apply_managed_base_price($v, (float)$price, $price_source);
            }
        }

        $gtin = trim((string)($row['gtin'] ?? ''));
        if ($gtin !== '' && method_exists($v, 'set_global_unique_id')) {
            try {
                if ((string)$v->get_global_unique_id('edit') === '' || (string)$v->get_meta('_asss_gtin_managed') === 'yes') {
                    $v->set_global_unique_id($gtin);
                    $v->update_meta_data('_asss_gtin_managed', 'yes');
                }
            } catch (Exception $e) {}
        }

        $v->update_meta_data('_asss_supplier', 'ss');
        $v->update_meta_data('_asss_supplier_product_key', 'ss|' . absint(get_post_meta($product_id, '_asss_ss_brand_id', true)) . '|' . absint(get_post_meta($product_id, '_asss_ss_style_id', true)));
        $v->update_meta_data('_asss_ss_sku_id', $supplier_id);
        $v->update_meta_data('_asss_ss_sku', $sku);
        $v->update_meta_data('_asss_ss_gtin', $gtin);
        $v->update_meta_data('_asss_ss_color', $color);
        $v->update_meta_data('_asss_ss_size', $size);
        $v->update_meta_data('_asss_supplier_cost', (string)($row['customer_price'] ?? ''));
        $v->update_meta_data('_asss_ss_customer_price', (string)($row['customer_price'] ?? ''));
        $v->update_meta_data('_asss_piece_price', (string)($row['piece_price'] ?? ''));
        $v->update_meta_data('_asss_ss_piece_price', (string)($row['piece_price'] ?? ''));
        $v->update_meta_data('_asss_dozen_price', (string)($row['dozen_price'] ?? ''));
        $v->update_meta_data('_asss_ss_dozen_price', (string)($row['dozen_price'] ?? ''));
        $v->update_meta_data('_asss_case_price', (string)($row['case_price'] ?? ''));
        $v->update_meta_data('_asss_ss_case_price', (string)($row['case_price'] ?? ''));
        $v->update_meta_data('_asss_supplier_sale_price', (string)($row['sale_price'] ?? ''));
        $v->update_meta_data('_asss_ss_sale_price', (string)($row['sale_price'] ?? ''));
        $v->update_meta_data('_asss_map_price', (string)($row['map_price'] ?? ''));
        $v->update_meta_data('_asss_ss_map_price', (string)($row['map_price'] ?? ''));
        $v->update_meta_data('_asss_suggested_retail_price', (string)($row['retail_price'] ?? ''));
        $v->update_meta_data('_asss_ss_retail_price', (string)($row['retail_price'] ?? ''));
        $v->update_meta_data('_asss_country_of_origin', sanitize_text_field((string)($row['country_of_origin'] ?? '')));
        $v->update_meta_data('_asss_case_qty', sanitize_text_field((string)($row['case_qty'] ?? '')));
        $v->update_meta_data('_asss_case_weight_lb', sanitize_text_field((string)($row['case_weight_lb'] ?? '')));
        $v->update_meta_data('_asss_case_dimensions', wp_json_encode([
            'length'=>$row['case_length'] ?? null,'width'=>$row['case_width'] ?? null,'height'=>$row['case_height'] ?? null,
        ]));
        $v->update_meta_data('_asss_ss_warehouses', wp_json_encode((array)($row['warehouses'] ?? [])));
        $v->delete_meta_data('_asss_stale_variation');
        $v->delete_meta_data('_asss_stale_variation_reason');
        $variation_id = $v->save();
        $this->multi->register_variation_source($variation_id, 'ss', [
            'sku'=>(string)($row['sku'] ?? ''),'sku_id'=>(string)($row['supplier_sku_id'] ?? $row['unique_key'] ?? ''),
            'gtin'=>(string)($row['gtin'] ?? ''),'color'=>$color,'size'=>$size,
            'cost'=>$row['customer_price'] ?? '', 'map_price'=>$row['map_price'] ?? '', 'retail_price'=>$row['retail_price'] ?? '',
            'inventory_qty'=>isset($row['qty']) && is_numeric($row['qty']) ? (int)$row['qty'] : null,
            'gallery'=>(array)($row['gallery'] ?? []),
        ]);
        if (!empty($this->sanmar->settings()['sync_images'])) $this->sync_ss_variation_media($variation_id, $row);
        do_action('asss_variation_synced', $variation_id, $product_id, 'ss', [
            'brand_id'=>absint(get_post_meta($product_id, '_asss_ss_brand_id', true)),
            'style_id'=>absint(get_post_meta($product_id, '_asss_ss_style_id', true)),
            'color'=>$color,'size'=>$size,'sku'=>$sku,'supplier_sku_id'=>$supplier_id,
        ]);
        return ['created'=>$created,'variation_id'=>$variation_id];
    }

    private function reconcile_ss_variations(int $product_id, array $rows, bool $allow_create = true): array {
        $expected = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $color = trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            $size = trim((string)($row['size'] ?? ''));
            if ($color === '' || $size === '') continue;
            $key = strtolower($this->term_slug('pa_color', $color) . '|' . $this->term_slug('pa_size', $size));
            $expected[$key] = $row;
        }

        $created = 0; $updated = 0;
        foreach ($expected as $row) {
            $existing = $this->find_ss_variation($product_id, (string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? ''), (string)($row['color'] ?? ''), (string)($row['size'] ?? ''));
            if (!$existing && !$allow_create) continue;
            $result = $this->sync_ss_variation($product_id, $row);
            if (!empty($result['created'])) $created++; elseif (!empty($result['variation_id'])) $updated++;
        }

        $ids = get_posts([
            'post_type'=>'product_variation','post_status'=>['publish','private','draft','pending'],'post_parent'=>$product_id,
            'fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
        ]);
        $stale = 0; $missing_price = 0; $missing_image = 0; $missing_gallery = 0; $incomplete_gallery = 0; $missing_sku = 0; $missing_gtin = 0; $attribute_mismatch = 0;
        $seen = [];
        foreach ($ids as $variation_id) {
            $v = wc_get_product((int)$variation_id);
            if (!$v instanceof WC_Product_Variation || (string)$v->get_meta('_asss_supplier') !== 'ss') continue;
            $attrs = $v->get_attributes();
            $key = strtolower(trim((string)($attrs['pa_color'] ?? '')) . '|' . trim((string)($attrs['pa_size'] ?? '')));
            if (!isset($expected[$key]) || isset($seen[$key])) {
                $v->set_status('private');
                $v->set_manage_stock(true);
                $v->set_stock_quantity(0);
                $v->set_stock_status('outofstock');
                $v->update_meta_data('_asss_stale_variation', 'yes');
                $v->update_meta_data('_asss_stale_variation_reason', isset($seen[$key]) ? 'duplicate-color-size' : 'not-in-current-supplier-selection');
                $v->update_meta_data('_asss_stale_variation_at', current_time('mysql'));
                $v->save();
                $stale++;
                continue;
            }
            $seen[$key] = (int)$variation_id;
            $row = $expected[$key];
            if ($v->get_regular_price('edit') === '' || $v->get_price('edit') === '') $missing_price++;
            if ($v->get_sku('edit') === '') $missing_sku++;
            if (!empty($this->sanmar->settings()['sync_images'])) {
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
            if (trim((string)($row['gtin'] ?? '')) !== '' && trim((string)$v->get_meta('_asss_ss_gtin')) === '') $missing_gtin++;
            $want_color = $this->term_slug('pa_color', (string)($row['color'] ?? ''));
            $want_size = $this->term_slug('pa_size', (string)($row['size'] ?? ''));
            if (($attrs['pa_color'] ?? '') !== $want_color || ($attrs['pa_size'] ?? '') !== $want_size) $attribute_mismatch++;
        }
        $missing_expected = max(0, count($expected) - count($seen));
        $audit = [
            'supplier'=>'ss','expected'=>count($expected),'supplier_variations'=>count($seen),
            'missing_expected'=>$missing_expected,'missing_price'=>$missing_price,'missing_image_when_available'=>$missing_image,
            'missing_variation_gallery'=>$missing_gallery,'incomplete_variation_gallery'=>$incomplete_gallery,
            'missing_sku'=>$missing_sku,'missing_gtin'=>$missing_gtin,'attribute_mismatch'=>$attribute_mismatch,'stale_disabled'=>$stale,
            'created'=>$created,'updated'=>$updated,'checked_at'=>current_time('mysql'),
        ];
        update_post_meta($product_id, '_asss_last_variation_audit', wp_json_encode($audit));
        ASSS_Logger::log(($missing_expected+$missing_price+$missing_sku+$missing_gtin+$attribute_mismatch+$missing_gallery+$incomplete_gallery) ? 'S&S variation audit completed with issues' : 'S&S variation audit passed', ($missing_expected+$missing_price+$missing_sku+$missing_gtin+$attribute_mismatch+$missing_gallery+$incomplete_gallery) ? 'warning' : 'info', ['product_id'=>$product_id,'audit'=>$audit]);
        return $audit;
    }

    private function normalize_ss_media_url(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        $normalized = preg_match('#^https?://#i', $url) ? esc_url_raw($url) : esc_url_raw('https://www.ssactivewear.com/' . ltrim($url, '/'));
        return ($normalized !== '' && !$this->is_invalid_storefront_media_url($normalized)) ? $normalized : '';
    }

    private function is_supplier_attachment(int $attachment_id): bool {
        if ($attachment_id < 1) return false;
        if ((string)get_post_meta($attachment_id, '_asss_image_url', true) !== '') return true;
        return in_array((string)get_post_meta($attachment_id, '_asss_image_source', true), ['sanmar','ss','momentec','supplier'], true);
    }

    /** Store supplier-only visual references without exposing them in Woo galleries. */
    private function save_supplier_reference_media(int $post_id, string $supplier, array $urls): void {
        $supplier = sanitize_key($supplier) ?: 'supplier';
        $clean = [];
        foreach ($urls as $raw) {
            $url = trim((string)$raw);
            if ($supplier === 'ss') $url = $this->normalize_ss_media_url($url);
            elseif ($supplier === 'momentec') $url = $this->momentec_media_url($url);
            else $url = filter_var($url, FILTER_VALIDATE_URL) ? esc_url_raw($url) : '';
            if ($url !== '') $clean[$url] = true;
        }
        $key = '_asss_' . $supplier . '_reference_media_urls';
        if ($clean) update_post_meta($post_id, $key, wp_json_encode(array_keys($clean)));
        else delete_post_meta($post_id, $key);
    }

    private function ss_media_kind(string $value): string {
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
        $this->save_supplier_reference_media($post_id, 'ss', $urls);
    }

    private function sync_ss_variation_media(int $variation_id, array $row): void {
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

    private function ss_featured_color_rank(string $color,int $index): int {
        $normalized=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i',' ',$color)));
        $tokens=array_values(array_filter(preg_split('/\s+/', $normalized) ?: []));
        $rank=10000+$index;
        $priority=['black'=>0,'navy'=>100,'charcoal'=>200,'graphite'=>250,'gray'=>300,'grey'=>300,'white'=>400];
        foreach($priority as $token=>$score){
            if(in_array($token,$tokens,true))return $score+$index;
        }
        return $rank;
    }

    /** Return a known clean, non-model S&S product image for one exact variant. */
    private function ss_clean_variant_image_url(array $row,bool $front_only=false): string {
        $images=is_array($row['images'] ?? null)?$row['images']:[];
        $front=$this->normalize_ss_media_url((string)($images['front'] ?? ''));
        if($front!=='')return $front;
        if($front_only)return '';

        // The GitHub S&S normalizer preserves media type beside each gallery URL.
        // Only catalog/product angles may become a parent featured image. Model
        // and lifestyle photography stays available in galleries but is excluded here.
        $gallery=is_array($row['gallery'] ?? null)?array_values($row['gallery']):[];
        $types=is_array($row['gallery_types'] ?? null)?array_values($row['gallery_types']):[];
        $allowed=['front','directside','side','back'];
        foreach($gallery as $i=>$raw){
            $kind=strtolower((string)preg_replace('/[^a-z0-9]+/i','',(string)($types[$i] ?? '')));
            if(!in_array($kind,$allowed,true))continue;
            $url=$this->normalize_ss_media_url((string)$raw);
            if($url!=='')return $url;
        }

        // Explicit named non-model angles remain safe fallbacks for older caches.
        foreach(['direct_side','side','back'] as $key){
            $url=$this->normalize_ss_media_url((string)($images[$key] ?? ''));
            if($url!=='')return $url;
        }
        return '';
    }

    /** Pick the S&S image customers see first in shop/category/search results. */
    private function ss_parent_featured_url(array $data,array $variants): string {
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

    private function sync_ss_parent_media(int $product_id, array $data, array $variants): void {
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

    private function sync_ss_bulk_order_fields(WC_Product_Variable $product, array $data, array $variants, bool $is_new): void {
        $settings = $this->sanmar->settings();
        $title = trim((string)($data['title'] ?? ''));
        $description = trim(wp_strip_all_tags((string)($data['description'] ?? '')));
        $brand = trim((string)($data['brand'] ?? ''));
        $style = trim((string)($data['style'] ?? ''));
        $sizes = [];
        foreach ($variants as $row) {
            $size = trim((string)($row['size'] ?? ''));
            if ($size !== '') $sizes[$size] = true;
        }
        if (!empty($settings['sync_bulk_order_fields'])) {
            if ((string)$product->get_meta('_asbo_display_name') === '' && $title !== '') $product->update_meta_data('_asbo_display_name', $title);
            if ((string)$product->get_meta('_asbo_short_description') === '' && $description !== '') $product->update_meta_data('_asbo_short_description', wp_trim_words($description, 55, '…'));
            if ((string)$product->get_meta('_asbo_size_chart') === '') {
                $parts = [];
                if ($sizes) $parts[] = '<strong>Available sizes:</strong> ' . esc_html(implode(', ', array_keys($sizes)));
                $label = trim('S&S Activewear' . ($brand ? ' · ' . $brand : '') . ($style ? ' · Style ' . $style : ''));
                if ($label !== '') $parts[] = '<strong>Supplier:</strong> ' . esc_html($label);
                if ($parts) $product->update_meta_data('_asbo_size_chart', implode("<br>\n", $parts));
            }
            if (!empty($settings['auto_enable_bulk_order']) && (string)$product->get_meta('_asbo_enabled') === '') $product->update_meta_data('_asbo_enabled', 'yes');
        }
        // S&S case dimensions are shipping-case measurements, not a single blank
        // garment's dimensions. Do not copy them into WooCommerce piece dimensions.
        // Merchant fallback piece dimensions still apply when configured.
        $this->sync_parent_dimensions($product, $this->ss_common_rows($variants, $data));
    }


    /**
     * Attach S&S as an additional source to an existing WooCommerce product.
     * Existing storefront content/price/media wins; S&S backfills missing exact
     * variations and stores its own identities, cost and inventory independently.
     */
    public function link_ss_style_to_product(int $product_id, int $brand_id, int $style_id, array $selected_colors = []) {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product_Variable) return new WP_Error('product','The target WooCommerce product is missing or is not variable.');
        $data = $this->ss->style_product($brand_id, $style_id);
        if (is_wp_error($data)) return $data;
        $brand = trim((string)($data['brand'] ?? ''));
        $style = trim((string)($data['style'] ?? ''));
        $variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];
        if (!$variants) return new WP_Error('ss_variants','No S&S variations were cached for this style.');

        $all_colors = $this->ss_colors_from_variants($variants);
        $selected_colors = array_values(array_unique(array_filter(array_map('sanitize_text_field',$selected_colors))));
        if (!$selected_colors) $selected_colors = $all_colors;
        $lookup = array_fill_keys($selected_colors,true);
        $variants = array_values(array_filter($variants, static function($row) use ($lookup){
            $c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            return $c!=='' && isset($lookup[$c]);
        }));
        if (!$variants) return new WP_Error('no_variants','No S&S variations remain for the selected colors.');

        $mode = count($selected_colors) >= count($all_colors) ? 'all' : 'selected';
        update_post_meta($product_id,'_asss_ss_brand_id',$brand_id);
        update_post_meta($product_id,'_asss_ss_style_id',$style_id);
        update_post_meta($product_id,'_asss_ss_brand',$brand);
        update_post_meta($product_id,'_asss_ss_style',$style);
        update_post_meta($product_id,'_asss_ss_specs',wp_json_encode((array)($data['specs'] ?? [])));
        update_post_meta($product_id,'_asss_ss_color_selection_mode',$mode);
        update_post_meta($product_id,'_asss_ss_selected_colors',wp_json_encode($selected_colors));
        update_post_meta($product_id,'_asss_sync_enabled','yes');
        $this->multi->register_product_source($product_id,'ss',[
            'brand_id'=>$brand_id,'style_id'=>$style_id,'brand'=>$brand,'style'=>$style,
            'selection_mode'=>$mode,'selected_colors'=>$selected_colors,
        ]);

        $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
        $base = trim((string)($data['category'] ?? $data['base_category'] ?? ''));
        if ($base!=='') array_unshift($categories,$base);
        $this->add_supplier_categories($product_id,$categories,'ss');

        $expected=[]; $matched=0; $created=0;
        foreach ($variants as $row) {
            if (!is_array($row)) continue;
            $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            $size=trim((string)($row['size'] ?? ''));
            $key=(string)($row['supplier_sku_id'] ?? $row['unique_key'] ?? $row['sku'] ?? '');
            if ($key==='') $key=$this->canonical_combo($color,$size);
            $expected[$key]=true;
            // Prefer the permanent S&S identity, then exact GTIN, then exact
            // Woo Color+Size. Only after those fail may a verified, style-specific
            // size alias match (for example Richardson 112 OSFA <-> M/L).
            $supplier_id=(string)($row['supplier_sku_id'] ?? $row['unique_key'] ?? '');
            $vid=$this->find_ss_variation($product_id,$supplier_id,$color,$size);
            if(!$vid && !empty($row['gtin'])) $vid=$this->find_variation_by_gtin_any($product_id,(string)$row['gtin']);
            if(!$vid) $vid=$this->find_variation_by_combo_any($product_id,$color,$size);
            if(!$vid) $vid=$this->find_variation_by_verified_size_alias($product_id,$brand,$style,$color,$size);
            if (!$vid) {
                $r=$this->sync_ss_variation($product_id,$row);
                if (is_wp_error($r)) continue;
                $vid=(int)$r['variation_id']; $created++;
            } else {
                $matched++;
                // Preserve the existing variation's core SKU, image and retail price.
                update_post_meta($vid,'_asss_ss_sku_id',sanitize_text_field((string)($row['supplier_sku_id'] ?? $row['unique_key'] ?? '')));
                update_post_meta($vid,'_asss_ss_sku',sanitize_text_field((string)($row['sku'] ?? '')));
                update_post_meta($vid,'_asss_ss_gtin',sanitize_text_field((string)($row['gtin'] ?? '')));
                update_post_meta($vid,'_asss_ss_color',$color);
                update_post_meta($vid,'_asss_ss_size',$size);
                update_post_meta($vid,'_asss_ss_customer_price',(string)($row['customer_price'] ?? ''));
                update_post_meta($vid,'_asss_ss_piece_price',(string)($row['piece_price'] ?? ''));
                update_post_meta($vid,'_asss_ss_dozen_price',(string)($row['dozen_price'] ?? ''));
                update_post_meta($vid,'_asss_ss_case_price',(string)($row['case_price'] ?? ''));
                update_post_meta($vid,'_asss_ss_sale_price',(string)($row['sale_price'] ?? ''));
                update_post_meta($vid,'_asss_ss_map_price',(string)($row['map_price'] ?? ''));
                update_post_meta($vid,'_asss_ss_retail_price',(string)($row['retail_price'] ?? ''));
                update_post_meta($vid,'_asss_ss_country_of_origin',sanitize_text_field((string)($row['country_of_origin'] ?? '')));
                update_post_meta($vid,'_asss_ss_case_qty',sanitize_text_field((string)($row['case_qty'] ?? '')));
                update_post_meta($vid,'_asss_ss_case_weight_lb',sanitize_text_field((string)($row['case_weight_lb'] ?? '')));
                update_post_meta($vid,'_asss_ss_case_dimensions',wp_json_encode(['length'=>$row['case_length'] ?? null,'width'=>$row['case_width'] ?? null,'height'=>$row['case_height'] ?? null]));
                update_post_meta($vid,'_asss_ss_warehouses',wp_json_encode((array)($row['warehouses'] ?? [])));
                $existing_v=wc_get_product($vid);
                if($existing_v instanceof WC_Product_Variation && $existing_v->get_weight('edit')==='' && isset($row['weight_lb']) && is_numeric($row['weight_lb']) && (float)$row['weight_lb']>0){
                    $existing_v->set_weight($this->weight_for_store((float)$row['weight_lb']));
                    $existing_v->save();
                }
                if(!empty($this->sanmar->settings()['sync_images']) && $existing_v instanceof WC_Product_Variation) $this->sync_ss_variation_media($vid,$row);
            }
            $this->multi->register_variation_source($vid,'ss',[
                'sku'=>(string)($row['sku'] ?? ''),'sku_id'=>(string)($row['supplier_sku_id'] ?? $row['unique_key'] ?? ''),
                'gtin'=>(string)($row['gtin'] ?? ''),'color'=>$color,'size'=>$size,
                'weight_lb'=>$row['weight_lb'] ?? null,'country_of_origin'=>(string)($row['country_of_origin'] ?? ''),
                'cost'=>$row['customer_price'] ?? '', 'piece_price'=>$row['piece_price'] ?? '', 'dozen_price'=>$row['dozen_price'] ?? '',
                'case_price'=>$row['case_price'] ?? '', 'sale_price'=>$row['sale_price'] ?? '', 'map_price'=>$row['map_price'] ?? '', 'retail_price'=>$row['retail_price'] ?? '',
                'case_qty'=>$row['case_qty'] ?? '', 'case_weight_lb'=>$row['case_weight_lb'] ?? null,
                'case_length'=>$row['case_length'] ?? null,'case_width'=>$row['case_width'] ?? null,'case_height'=>$row['case_height'] ?? null,
                'inventory_qty'=>isset($row['qty'])&&is_numeric($row['qty'])?(int)$row['qty']:null,
                'warehouses'=>(array)($row['warehouses'] ?? []),'gallery'=>(array)($row['gallery'] ?? []),
            ]);
            $this->multi->recalculate_variation_inventory($vid);
        }
        $this->disable_missing_source_variations($product_id,'ss',$expected);
        $this->rebuild_attributes_from_variations($product_id);
        $this->sync_managed_pricing_for_product($product_id);
        $this->multi->recalculate_product_inventory($product_id);
        update_post_meta($product_id,'_asss_last_product_sync',current_time('mysql'));
        do_action('asss_product_synced',$product_id,'ss',['brand_id'=>$brand_id,'style_id'=>$style_id,'mode'=>'multi-link']);
        ASSS_Logger::log('S&S linked as additional supplier','info',['product_id'=>$product_id,'matched_existing'=>$matched,'created_ss_only'=>$created]);
        return $product_id;
    }

    /** Attach SanMar as an additional source to an existing S&S/multi product. */
    public function link_sanmar_style_to_product(int $product_id, string $brand, string $style, array $selected_colors = []) {
        $product=wc_get_product($product_id);
        if (!$product instanceof WC_Product_Variable) return new WP_Error('product','The target WooCommerce product is missing or is not variable.');
        $data=$this->sanmar->rows_for_style($brand,$style);
        if (is_wp_error($data)) return $data;
        $rows=is_array($data['rows'] ?? null)?$data['rows']:[];
        if (!$rows) return new WP_Error('empty_style','No SanMar rows were found for this style.');
        $all_colors=$this->colors_from_rows($rows);
        $selected_colors=array_values(array_unique(array_filter(array_map('sanitize_text_field',$selected_colors))));
        if (!$selected_colors) $selected_colors=$all_colors;
        $lookup=array_fill_keys($selected_colors,true);
        $rows=array_values(array_filter($rows,function($row)use($lookup){$c=(string)$this->sanmar->first($row,['COLOR_NAME','COLOR','CATALOG_COLOR']);return $c!==''&&isset($lookup[$c]);}));
        $rows=$this->dedupe_supplier_rows($this->hydrate_color_images($rows));
        if (!$rows) return new WP_Error('no_variants','No SanMar variations remain for the selected colors.');
        $mode=count($selected_colors)>=count($all_colors)?'all':'selected';
        update_post_meta($product_id,'_asss_sanmar_brand',$brand);
        update_post_meta($product_id,'_asss_sanmar_style',$style);
        update_post_meta($product_id,'_asss_sanmar_color_selection_mode',$mode);
        update_post_meta($product_id,'_asss_sanmar_selected_colors',wp_json_encode($selected_colors));
        update_post_meta($product_id,'_asss_sync_enabled','yes');
        $this->multi->register_product_source($product_id,'sanmar',['brand'=>$brand,'style'=>$style,'selection_mode'=>$mode,'selected_colors'=>$selected_colors,'source_file'=>(string)($data['meta']['file'] ?? '')]);
        $this->add_supplier_categories($product_id,$this->categories_from_rows($rows),'sanmar');

        $expected=[]; $matched=0; $created=0;
        foreach($rows as $row){
            $color=trim((string)$this->sanmar->first($row,['COLOR_NAME','COLOR','CATALOG_COLOR']));
            $size=trim((string)$this->sanmar->first($row,['SIZE']));
            $unique=trim((string)$this->sanmar->first($row,['UNIQUE_KEY','UNIQUEKEY','PART_ID']));
            $key=$unique!==''?$unique:$this->canonical_combo($color,$size); $expected[$key]=true;
            $vid=$this->find_variation_by_sanmar_identity_any($product_id,$unique);
            if(!$vid) $vid=$this->find_variation_by_combo_any($product_id,$color,$size);
            if(!$vid) $vid=$this->find_variation_by_verified_size_alias($product_id,$brand,$style,$color,$size);
            if(!$vid){$r=$this->create_or_update_variation($product_id,$brand,$style,$row);if(is_wp_error($r))continue;$vid=(int)$r['variation_id'];$created++;}
            else{
                $matched++;
                update_post_meta($vid,'_asss_sanmar_unique_key',$unique);
                update_post_meta($vid,'_asss_sanmar_inventory_key',(string)$this->sanmar->first($row,['INVENTORY_KEY','INVENTORYKEY']));
                update_post_meta($vid,'_asss_sanmar_size_index',(string)$this->sanmar->first($row,['SIZE_INDEX','SIZEINDEX']));
                update_post_meta($vid,'_asss_sanmar_brand',$brand);update_post_meta($vid,'_asss_sanmar_style',$style);
                update_post_meta($vid,'_asss_sanmar_color',$color);update_post_meta($vid,'_asss_sanmar_size',$size);
                update_post_meta($vid,'_asss_sanmar_cost',(string)$this->sanmar->first($row,['PIECE_PRICE','PRICE']));
                update_post_meta($vid,'_asss_sanmar_case_price',(string)$this->sanmar->first($row,['CASE_PRICE']));
                update_post_meta($vid,'_asss_sanmar_sale_price',(string)$this->sanmar->first($row,['PIECE_SALE_PRICE']));
                update_post_meta($vid,'_asss_sanmar_map_price',(string)$this->sanmar->first($row,['MAP_PRICE']));
                $existing_v=wc_get_product($vid);$weight=(string)$this->sanmar->first($row,['PIECE_WEIGHT']);
                if($existing_v instanceof WC_Product_Variation && $existing_v->get_weight('edit')==='' && $weight!=='' && is_numeric($weight) && (float)$weight>0){$existing_v->set_weight($this->weight_for_store((float)$weight));$existing_v->save();}
                if(!empty($this->sanmar->settings()['sync_images']) && $existing_v instanceof WC_Product_Variation && !$existing_v->get_image_id()) $this->sync_variation_media($vid,$row);
            }
            $this->multi->register_variation_source($vid,'sanmar',[
                'brand'=>$brand,'style'=>$style,'unique_key'=>$unique,
                'inventory_key'=>(string)$this->sanmar->first($row,['INVENTORY_KEY','INVENTORYKEY']),
                'size_index'=>(string)$this->sanmar->first($row,['SIZE_INDEX','SIZEINDEX']),
                'color'=>$color,'size'=>$size,'weight_lb'=>(string)$this->sanmar->first($row,['PIECE_WEIGHT']),
                'cost'=>(string)$this->sanmar->first($row,['PIECE_PRICE','PRICE']),
                'case_price'=>(string)$this->sanmar->first($row,['CASE_PRICE']),
                'sale_price'=>(string)$this->sanmar->first($row,['PIECE_SALE_PRICE']),
                'map_price'=>(string)$this->sanmar->first($row,['MAP_PRICE']),
                'product_status'=>(string)$this->sanmar->first($row,['PRODUCT_STATUS','STATUS']),
                'gallery'=>$this->sanmar_gallery_urls($row),
            ]);
        }
        $this->disable_missing_source_variations($product_id,'sanmar',$expected);
        $this->rebuild_attributes_from_variations($product_id);
        $this->sync_managed_pricing_for_product($product_id);
        $this->multi->recalculate_product_inventory($product_id);
        update_post_meta($product_id,'_asss_last_product_sync',current_time('mysql'));
        do_action('asss_product_synced',$product_id,'sanmar',['brand'=>$brand,'style'=>$style,'mode'=>'multi-link']);
        ASSS_Logger::log('SanMar linked as additional supplier','info',['product_id'=>$product_id,'matched_existing'=>$matched,'created_sanmar_only'=>$created]);
        return $product_id;
    }

    private function update_multi_style(int $product_id) {
        $sources=$this->multi->product_sources($product_id);
        $errors=[];
        if (!empty($sources['sanmar']['enabled'])) {
            $brand=(string)($sources['sanmar']['brand'] ?? get_post_meta($product_id,'_asss_sanmar_brand',true));
            $style=(string)($sources['sanmar']['style'] ?? get_post_meta($product_id,'_asss_sanmar_style',true));
            $colors=is_array($sources['sanmar']['selected_colors'] ?? null)?$sources['sanmar']['selected_colors']:[];
            $r=$this->link_sanmar_style_to_product($product_id,$brand,$style,$colors);
            if(is_wp_error($r))$errors[]='SanMar: '.$r->get_error_message();
        }
        if (!empty($sources['ss']['enabled'])) {
            $bid=absint($sources['ss']['brand_id'] ?? get_post_meta($product_id,'_asss_ss_brand_id',true));
            $sid=absint($sources['ss']['style_id'] ?? get_post_meta($product_id,'_asss_ss_style_id',true));
            $colors=is_array($sources['ss']['selected_colors'] ?? null)?$sources['ss']['selected_colors']:[];
            $r=$this->link_ss_style_to_product($product_id,$bid,$sid,$colors);
            if(is_wp_error($r))$errors[]='S&S: '.$r->get_error_message();
        }
        if (!empty($sources['momentec']['enabled'])) {
            $style=(string)($sources['momentec']['style'] ?? get_post_meta($product_id,'_asss_momentec_style',true));
            $colors=is_array($sources['momentec']['selected_colors'] ?? null)?$sources['momentec']['selected_colors']:[];
            $r=$this->link_momentec_style_to_product($product_id,$style,$colors);
            if(is_wp_error($r))$errors[]='Momentec: '.$r->get_error_message();
        }
        if($errors)return new WP_Error('multi_repair',implode(' | ',$errors));
        $this->sync_managed_pricing_for_product($product_id);
        return $product_id;
    }

    private function sanmar_gallery_urls(array $row): array {
        $urls=[];
        foreach(['COLOR_PRODUCT_IMAGE','FRONT_FLAT','THREE_Q_MODEL','SIDE_MODEL','BACK_MODEL','BACK_FLAT','FRONT_MODEL'] as $field){
            $url=trim((string)$this->sanmar->first($row,[$field]));
            if($url!=='' && !in_array($url,$urls,true))$urls[]=$url;
        }
        return $urls;
    }

    private function find_variation_by_sanmar_identity_any(int $product_id,string $unique): int {
        $unique=trim($unique);
        if($unique==='')return 0;
        foreach($this->variation_ids_direct($product_id) as $vid){
            if((string)get_post_meta($vid,'_asss_sanmar_unique_key',true)===$unique)return $vid;
            $sources=$this->multi->variation_sources($vid);
            if(isset($sources['sanmar']) && (string)($sources['sanmar']['unique_key'] ?? '')===$unique)return $vid;
        }
        return 0;
    }

    private function find_variation_by_gtin_any(int $product_id,string $gtin): int {
        $gtin=preg_replace('/[^0-9A-Za-z]/','',trim($gtin));
        if($gtin==='')return 0;
        foreach($this->variation_ids_direct($product_id) as $vid){
            $v=wc_get_product($vid); if(!$v instanceof WC_Product_Variation)continue;
            $candidates=[];
            if(method_exists($v,'get_global_unique_id')) $candidates[]=(string)$v->get_global_unique_id('edit');
            foreach(['_asss_ss_gtin','_gtin','_global_unique_id','_wc_gla_gtin','_alg_ean'] as $meta) $candidates[]=(string)get_post_meta($vid,$meta,true);
            $sources=$this->multi->variation_sources($vid);
            foreach($sources as $src) if(is_array($src)&&!empty($src['gtin'])) $candidates[]=(string)$src['gtin'];
            foreach($candidates as $candidate){
                $candidate=preg_replace('/[^0-9A-Za-z]/','',trim($candidate));
                if($candidate!=='' && hash_equals($gtin,$candidate)) return $vid;
            }
        }
        return 0;
    }

    private function find_variation_by_combo_any(int $product_id,string $color,string $size): int {
        $want_c=$this->term_slug('pa_color',$color); $want_s=$this->term_slug('pa_size',$size);
        foreach($this->variation_ids_direct($product_id) as $vid){
            $v=wc_get_product($vid); if(!$v instanceof WC_Product_Variation)continue;
            $a=$v->get_attributes();
            if((string)($a['pa_color'] ?? '')===$want_c && (string)($a['pa_size'] ?? '')===$want_s)return $vid;
        }
        return 0;
    }

    /**
     * Match a known cross-supplier size alias after the exact Color+Size match
     * has failed. Color remains exact; only explicitly verified style-specific
     * size labels can collapse onto one existing Woo variation.
     */
    private function find_variation_by_verified_size_alias(int $product_id,string $brand,string $style,string $color,string $size): int {
        $want_size = $this->canonical_supplier_size($brand,$style,$size);
        if ($want_size === '') return 0;
        $want_color = $this->term_slug('pa_color',$color);
        foreach($this->variation_ids_direct($product_id) as $vid){
            $v=wc_get_product($vid); if(!$v instanceof WC_Product_Variation)continue;
            $attrs=$v->get_attributes();
            if((string)($attrs['pa_color'] ?? '')!==$want_color)continue;

            // Prefer raw supplier labels already stored on the variation so the
            // comparison remains independent of whichever label Woo displays.
            $candidate_sizes=[];
            foreach($this->multi->variation_sources($vid) as $source){
                if(!is_array($source) || empty($source['enabled']))continue;
                $raw=trim((string)($source['size'] ?? ''));
                if($raw!=='')$candidate_sizes[]=$raw;
            }
            if(!$candidate_sizes && !empty($attrs['pa_size']))$candidate_sizes[]=(string)$attrs['pa_size'];
            foreach(array_unique($candidate_sizes) as $candidate){
                if($this->canonical_supplier_size($brand,$style,(string)$candidate)===$want_size)return $vid;
            }
        }
        return 0;
    }

    private function disable_missing_source_variations(int $product_id,string $supplier,array $expected): void {
        foreach($this->variation_ids_direct($product_id) as $vid){
            $sources=$this->multi->variation_sources($vid);
            if(empty($sources[$supplier]['enabled']))continue;
            $src=$sources[$supplier];
            if($supplier==='ss')$key=(string)($src['sku_id'] ?? $src['sku'] ?? $this->canonical_combo((string)($src['color'] ?? ''),(string)($src['size'] ?? '')));
            else $key=(string)($src['unique_key'] ?? $this->canonical_combo((string)($src['color'] ?? ''),(string)($src['size'] ?? '')));
            if($key!==''&&!isset($expected[$key]))$this->multi->remove_variation_source($vid,$supplier,'not-in-current-source-selection');
        }
    }

    private function rebuild_attributes_from_variations(int $product_id): void {
        $product=wc_get_product($product_id); if(!$product instanceof WC_Product_Variable)return;
        $rows=[];
        foreach($this->variation_ids_direct($product_id) as $vid){
            $v=wc_get_product($vid); if(!$v instanceof WC_Product_Variation)continue;
            $sources=array_filter($this->multi->variation_sources($vid),static fn($x)=>is_array($x)&&!empty($x['enabled']));
            if(!$sources || (string)$v->get_meta('_asss_stale_variation')==='yes')continue;
            $a=$v->get_attributes(); $c='';$z='';
            if(!empty($a['pa_color'])){$t=get_term_by('slug',$a['pa_color'],'pa_color');if($t&&!is_wp_error($t))$c=$t->name;}
            if(!empty($a['pa_size'])){$t=get_term_by('slug',$a['pa_size'],'pa_size');if($t&&!is_wp_error($t))$z=$t->name;}
            if($c!==''||$z!=='')$rows[]=['COLOR_NAME'=>$c,'SIZE'=>$z];
        }
        if($rows){$this->set_attributes($product,$rows);$product->save();}
    }

    private function add_supplier_categories(int $product_id,array $categories,string $supplier): void {
        $this->sync_supplier_categories($product_id, $categories, $supplier, false);
    }


    public function find_momentec_product(string $style, string $brand = ''): int {
        $meta = [['key'=>'_asss_momentec_style','value'=>$style]];
        if ($brand !== '') $meta[] = ['key'=>'_asss_momentec_brand','value'=>$brand];
        $q = new WP_Query(['post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>3,'meta_query'=>$meta]);
        foreach ((array)$q->posts as $id) {
            $sources=$this->multi->product_sources((int)$id);
            if (!empty($sources['momentec']['enabled'])) return (int)$id;
        }
        return 0;
    }

    private function momentec_colors_from_variants(array $variants): array {
        $colors=[];
        foreach($variants as $row){
            if(!is_array($row))continue;
            $c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            if($c!=='')$colors[$c]=true;
        }
        return array_keys($colors);
    }

    private function maybe_set_momentec_parent_sku(WC_Product $product,string $brand,string $style): void {
        if($product->get_sku()!=='' || $style==='')return;
        $fallback=strtoupper(sanitize_title('MOMENTEC-'.$brand.'-'.$style));
        foreach(array_values(array_unique(array_filter([$style,$fallback]))) as $candidate){
            $existing=(int)wc_get_product_id_by_sku($candidate);
            if(!$existing || $existing===$product->get_id()){
                try{$product->set_sku($candidate);}catch(Exception $e){}
                if($product->get_sku()!=='')return;
            }
        }
    }

    private function momentec_variation_price(array $row): array {
        $cost=$row['customer_price'] ?? $row['piece_price'] ?? null;
        if($cost!==null && $cost!=='' && is_numeric($cost) && (float)$cost>0) return [(float)$cost+20.0,'supplier_markup:momentec'];
        $retail=$row['retail_price'] ?? null;
        return ($retail!==null && $retail!=='' && is_numeric($retail) && (float)$retail>0) ? [(float)$retail,'momentec_retail_fallback'] : [null,''];
    }

    private function find_momentec_variation(int $parent_id,string $supplier_id,string $color,string $size): int {
        if($supplier_id!==''){
            $q=new WP_Query(['post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'post_parent'=>$parent_id,'meta_query'=>[['key'=>'_asss_momentec_sku_id','value'=>$supplier_id]]]);
            if(!empty($q->posts[0]))return (int)$q->posts[0];
        }
        $q=new WP_Query(['post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'post_parent'=>$parent_id,'meta_query'=>[
            ['key'=>'_asss_momentec_color','value'=>$color],['key'=>'_asss_momentec_size','value'=>$size],
        ]]);
        return (int)($q->posts[0] ?? 0);
    }

    private function momentec_media_url(string $url): string {
        $url = trim($url);
        $normalized = preg_match('#^https?://#i',$url) ? esc_url_raw($url) : '';
        return ($normalized !== '' && !$this->is_invalid_storefront_media_url($normalized)) ? $normalized : '';
    }

    /** Known Momentec photographic views are storefront media; alternate/unknown assets are reference-only. */
    private function momentec_classify_variant_media(array $row): array {
        $storefront=[];$reference=[];
        $allowed=array_fill_keys(['hero','lquarter','front','leftside','rightside','back'],true);
        $images=is_array($row['images'] ?? null)?$row['images']:[];
        foreach (['heroImage','hero_image','lquarter','front','lside','left_side','rside','right_side','back'] as $key) {
            $url=$this->momentec_media_url((string)($images[$key] ?? ''));
            if($url!=='')$storefront[$url]=true;
        }
        $gallery=is_array($row['gallery'] ?? null)?array_values($row['gallery']):[];
        $types=is_array($row['gallery_types'] ?? null)?array_values($row['gallery_types']):[];
        foreach($gallery as $i=>$raw){
            $url=$this->momentec_media_url((string)$raw);if($url==='')continue;
            $kind=strtolower((string)preg_replace('/[^a-z0-9]+/i','',(string)($types[$i] ?? '')));
            if(isset($allowed[$kind]) || isset($storefront[$url]))$storefront[$url]=true;
            else $reference[$url]=true;
        }
        foreach((array)($row['reference_media'] ?? []) as $raw){$url=$this->momentec_media_url((string)$raw);if($url!=='')$reference[$url]=true;}
        $primary=$this->momentec_media_url((string)($row['primary_image'] ?? ''));
        if($primary!=='' && !isset($storefront[$primary]))$reference[$primary]=true;
        foreach(array_keys($storefront) as $url)unset($reference[$url]);
        return ['storefront'=>array_keys($storefront),'reference'=>array_keys($reference)];
    }

    private function sync_momentec_variation_media(int $variation_id,array $row): void {
        $v=wc_get_product($variation_id);if(!$v instanceof WC_Product_Variation)return;
        $classified=$this->momentec_classify_variant_media($row);
        $urls=array_values((array)$classified['storefront']);
        $this->save_supplier_reference_media($variation_id,'momentec',(array)$classified['reference']);
        $current=(int)$v->get_image_id();$manual_primary=$current && !$this->is_supplier_attachment($current);
        $manual_gallery=[];
        if(method_exists($v,'get_gallery_image_ids'))foreach((array)$v->get_gallery_image_ids() as $id)if((int)$id&&!$this->is_supplier_attachment((int)$id))$manual_gallery[]=(int)$id;
        if(!$urls){
            if(!$manual_primary)$v->set_image_id(0);
            if(method_exists($v,'set_gallery_image_ids'))$v->set_gallery_image_ids(array_values(array_unique($manual_gallery)));
            $full=$manual_primary?array_values(array_unique(array_merge([$current],$manual_gallery))):array_values(array_unique($manual_gallery));
            $v->update_meta_data('_asss_variation_gallery_ids',$full);$v->update_meta_data('_asss_variation_gallery_urls',[]);$v->update_meta_data('_asss_variation_gallery_supplier_count',0);$v->delete_meta_data('_asss_resolved_variation_image_url');$v->save();return;
        }
        $supplier_ids=[];foreach($urls as $url){$aid=$this->sideload($url,$variation_id,'momentec');if($aid)$supplier_ids[]=(int)$aid;}
        $supplier_ids=array_values(array_unique(array_filter($supplier_ids)));if(!$supplier_ids)return;
        if(!$manual_primary)$v->set_image_id($supplier_ids[0]);
        $primary=$manual_primary?$current:$supplier_ids[0];$extra=array_values(array_filter($supplier_ids,static fn($id)=>(int)$id!==(int)$primary));
        if(method_exists($v,'set_gallery_image_ids'))$v->set_gallery_image_ids(array_values(array_unique(array_merge($extra,$manual_gallery))));
        $full=$manual_primary?array_values(array_unique(array_merge([$current],$supplier_ids,$manual_gallery))):array_values(array_unique(array_merge($supplier_ids,$manual_gallery)));
        $v->update_meta_data('_asss_variation_gallery_ids',$full);$v->update_meta_data('_asss_variation_gallery_urls',$urls);$v->update_meta_data('_asss_variation_gallery_supplier_count',count($supplier_ids));$v->update_meta_data('_asss_resolved_variation_image_url',esc_url_raw($urls[0]));$v->save();
    }

    private function momentec_parent_featured_url(array $data,array $variants): string {
        $reference=[];$known_storefront=[];
        foreach((array)($data['reference_media'] ?? []) as $raw){$u=$this->momentec_media_url((string)$raw);if($u!=='')$reference[$u]=true;}
        foreach($variants as $row){if(!is_array($row))continue;$classified=$this->momentec_classify_variant_media($row);foreach((array)$classified['storefront'] as $u)$known_storefront[$u]=true;foreach((array)$classified['reference'] as $u)$reference[$u]=true;}
        foreach(array_keys($known_storefront) as $u)unset($reference[$u]);
        // 1) Product-level thumbnail, but never if classified as reference.
        $thumbnail=$this->momentec_media_url((string)($data['images']['thumbnail'] ?? ''));if($thumbnail!==''&&!isset($reference[$thumbnail]))return $thumbnail;
        // 2) First selected variation's verified product photograph.
        foreach($variants as $row){if(!is_array($row))continue;$classified=$this->momentec_classify_variant_media($row);$first=(string)(((array)$classified['storefront'])[0] ?? '');if($first!=='')return $first;}
        // 3) Product image only when it is not known reference media.
        $product=$this->momentec_media_url((string)($data['images']['product'] ?? ''));if($product!==''&&!isset($reference[$product]))return $product;
        // 4) Leave blank for merchant selection rather than promoting reference media.
        return '';
    }

    /** Attach only one parent image during the interactive import request. */
    private function sync_momentec_parent_featured_image(int $product_id,array $data,array $variants): void {
        $product=wc_get_product($product_id);if(!$product)return;
        $current=(int)$product->get_image_id();
        if($current && !$this->is_supplier_attachment($current))return;
        $url=$this->momentec_parent_featured_url($data,$variants);if($url==='')return;
        $id=$this->sideload($url,$product_id,'momentec');if(!$id)return;
        $product->set_image_id($id);$product->save();
        update_post_meta($product_id,'_asss_momentec_featured_image_url',esc_url_raw($url));
    }

    private function sync_momentec_parent_media(int $product_id,array $data,array $variants): void {
        $product=wc_get_product($product_id);if(!$product)return;
        $storefront=[];$reference=[];
        foreach((array)($data['reference_media'] ?? []) as $raw){$u=$this->momentec_media_url((string)$raw);if($u!=='')$reference[$u]=true;}
        foreach($variants as $row){
            if(!is_array($row))continue;$classified=$this->momentec_classify_variant_media($row);
            foreach((array)$classified['storefront'] as $u)$storefront[$u]=true;
            foreach((array)$classified['reference'] as $u)$reference[$u]=true;
            if(count($storefront)>=8)break;
        }
        foreach(array_keys($storefront) as $u)unset($reference[$u]);
        // Product-level generic images are allowed only when they are not known reference media.
        foreach([(string)($data['images']['thumbnail'] ?? ''),(string)($data['images']['product'] ?? '')] as $raw){$u=$this->momentec_media_url($raw);if($u!==''&&!isset($reference[$u]))$storefront[$u]=true;}
        $this->save_supplier_reference_media($product_id,'momentec',array_keys($reference));
        $ids=[];foreach(array_slice(array_keys($storefront),0,8) as $url){$id=$this->sideload($url,$product_id,'momentec');if($id)$ids[]=(int)$id;}
        $ids=array_values(array_unique($ids));
        $manual=[];foreach($product->get_gallery_image_ids() as $id)if((int)$id&&!$this->is_supplier_attachment((int)$id))$manual[]=(int)$id;
        $primary=(int)$product->get_image_id();$supplier_gallery=array_values(array_filter($ids,static fn($id)=>(int)$id!==$primary));
        $product->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_gallery,$manual))));$product->save();
    }

    private function momentec_row_for_variation(int $product_id,int $variation_id,array $variants): array {
        $v=wc_get_product($variation_id);if(!$v instanceof WC_Product_Variation||$v->get_parent_id()!==$product_id)return [];
        $want_ids=array_values(array_unique(array_filter([
            trim((string)$v->get_meta('_asss_momentec_sku_id')),
            trim((string)$v->get_meta('_asss_momentec_sku')),
        ])));
        $want_color=trim((string)$v->get_meta('_asss_momentec_color'));$want_size=trim((string)$v->get_meta('_asss_momentec_size'));
        foreach($variants as $row){
            if(!is_array($row))continue;
            $row_ids=array_values(array_unique(array_filter([
                trim((string)($row['unique_key'] ?? '')),
                trim((string)($row['supplier_sku_id'] ?? '')),
                trim((string)($row['sku'] ?? '')),
            ])));
            if($want_ids && array_intersect($want_ids,$row_ids))return $row;
            $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));
            if($want_color!==''&&$want_size!==''&&strcasecmp($want_color,$color)===0&&strcasecmp($want_size,$size)===0)return $row;
        }
        return [];
    }

    private function schedule_momentec_media_action(string $hook,array $args,int $delay=1): void {
        $delay=max(1,$delay);
        if(function_exists('as_schedule_single_action')){
            as_schedule_single_action(time()+$delay,$hook,$args,'all-star-supplier-sync-momentec-media',true);
            return;
        }
        if(!wp_next_scheduled($hook,$args))wp_schedule_single_event(time()+$delay,$hook,$args);
    }

    private function queue_momentec_media_jobs(int $product_id,array $variants): void {
        if(empty($this->sanmar->settings()['sync_images']))return;
        $queued=0;$delay=2;
        foreach($variants as $row){
            if(!is_array($row))continue;
            $classified=$this->momentec_classify_variant_media($row);$gallery=array_values((array)$classified['storefront']);
            $supplier_id=trim((string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? $row['sku'] ?? ''));
            $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));
            $variation_id=$this->find_momentec_variation($product_id,$supplier_id,$color,$size);if(!$variation_id)continue;
            $this->save_supplier_reference_media($variation_id,'momentec',(array)$classified['reference']);
            if(!$gallery){$this->sync_momentec_variation_media($variation_id,$row);continue;}
            update_post_meta($variation_id,'_asss_momentec_media_pending','yes');
            update_post_meta($variation_id,'_asss_variation_gallery_urls',$gallery);
            $this->schedule_momentec_media_action('asss_momentec_variation_media_job',[$product_id,$variation_id],$delay++);
            $queued++;
        }
        update_post_meta($product_id,'_asss_momentec_media_pending',$queued>0?'yes':'no');
        update_post_meta($product_id,'_asss_momentec_media_jobs_queued',$queued);
        update_post_meta($product_id,'_asss_momentec_media_queued_at',current_time('mysql'));
        $this->schedule_momentec_media_action('asss_momentec_parent_media_job',[$product_id],$delay+1);
        if(function_exists('spawn_cron'))spawn_cron();
        ASSS_Logger::log('Momentec media queued for background processing','info',['product_id'=>$product_id,'variation_jobs'=>$queued]);
    }

    public function momentec_variation_media_job(int $product_id,int $variation_id): void {
        $style=trim((string)get_post_meta($product_id,'_asss_momentec_style',true));if($style==='')return;
        $data=$this->momentec->style_product($style);if(is_wp_error($data))return;
        $variants=is_array($data['variants'] ?? null)?$data['variants']:[];$row=$this->momentec_row_for_variation($product_id,$variation_id,$variants);
        if(!$row){delete_post_meta($variation_id,'_asss_momentec_media_pending');ASSS_Logger::log('Momentec background media row not found','warning',['product_id'=>$product_id,'variation_id'=>$variation_id]);return;}
        $this->sync_momentec_variation_media($variation_id,$row);
        $v=wc_get_product($variation_id);$classified=$this->momentec_classify_variant_media($row);
        if($v instanceof WC_Product_Variation && ($v->get_image_id() || empty($classified['storefront']))){
            delete_post_meta($variation_id,'_asss_momentec_media_pending');
            delete_post_meta($variation_id,'_asss_momentec_media_attempts');
        }else{
            $attempts=absint(get_post_meta($variation_id,'_asss_momentec_media_attempts',true))+1;update_post_meta($variation_id,'_asss_momentec_media_attempts',$attempts);
            if($attempts<3)$this->schedule_momentec_media_action('asss_momentec_variation_media_job',[$product_id,$variation_id],300*$attempts);
            else ASSS_Logger::log('Momentec variation media exhausted retries','warning',['product_id'=>$product_id,'variation_id'=>$variation_id]);
        }
    }

    public function momentec_parent_media_job(int $product_id): void {
        $style=trim((string)get_post_meta($product_id,'_asss_momentec_style',true));if($style==='')return;
        $data=$this->momentec->style_product($style);if(is_wp_error($data))return;
        $variants=is_array($data['variants'] ?? null)?$data['variants']:[];
        $mode=(string)get_post_meta($product_id,'_asss_momentec_color_selection_mode',true);if($mode!==''&&$mode!=='all'){
            $selected=json_decode((string)get_post_meta($product_id,'_asss_momentec_selected_colors',true),true);
            if(is_array($selected)&&$selected){$lookup=array_fill_keys(array_map('strval',$selected),true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));}
        }
        $this->sync_momentec_parent_media($product_id,$data,$variants);
        $pending=0;foreach($this->variation_ids_direct($product_id) as $vid)if((string)get_post_meta($vid,'_asss_momentec_media_pending',true)==='yes')$pending++;
        update_post_meta($product_id,'_asss_momentec_media_jobs_pending',$pending);
        if($pending===0){update_post_meta($product_id,'_asss_momentec_media_pending','no');update_post_meta($product_id,'_asss_momentec_media_completed_at',current_time('mysql'));}
    }

    private function sync_momentec_bulk_order_fields(WC_Product_Variable $product,array $data,array $variants,bool $is_new): void {
        $settings=$this->sanmar->settings();$title=trim((string)($data['title'] ?? ''));$description=trim(wp_strip_all_tags((string)($data['description'] ?? '')));
        $brand=trim((string)($data['brand'] ?? ''));$style=trim((string)($data['style'] ?? ''));$sizes=[];
        foreach($variants as $row){$size=trim((string)($row['size'] ?? ''));if($size!=='')$sizes[$size]=true;}
        if(!empty($settings['sync_bulk_order_fields'])){
            if((string)$product->get_meta('_asbo_display_name')===''&&$title!=='')$product->update_meta_data('_asbo_display_name',$title);
            if((string)$product->get_meta('_asbo_short_description')===''&&$description!=='')$product->update_meta_data('_asbo_short_description',wp_trim_words($description,55,'…'));
            if((string)$product->get_meta('_asbo_size_chart')===''){
                $parts=[];if($sizes)$parts[]='<strong>Available sizes:</strong> '.esc_html(implode(', ',array_keys($sizes)));
                $label=trim('Momentec Brands'.($brand?' · '.$brand:'').($style?' · Style '.$style:''));if($label!=='')$parts[]='<strong>Supplier:</strong> '.esc_html($label);
                if($parts)$product->update_meta_data('_asbo_size_chart',implode("<br>\n",$parts));
            }
            if(!empty($settings['auto_enable_bulk_order'])&&(string)$product->get_meta('_asbo_enabled')==='')$product->update_meta_data('_asbo_enabled','yes');
        }
        $this->sync_parent_dimensions($product,$this->ss_common_rows($variants,$data));
    }

    private function sync_momentec_variation(int $product_id,array $row): array {
        $supplier_id=trim((string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? $row['sku'] ?? ''));
        $sku=trim((string)($row['sku'] ?? $row['inventory_key'] ?? $supplier_id));
        $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));
        if($color===''||$size===''||$sku==='')return ['created'=>false,'variation_id'=>0];
        $variation_id=$this->find_momentec_variation($product_id,$supplier_id,$color,$size);$created=!$variation_id;
        $v=$variation_id?wc_get_product($variation_id):new WC_Product_Variation();if(!$v instanceof WC_Product_Variation)return ['created'=>false,'variation_id'=>0];
        if($created)$v->set_parent_id($product_id);$v->set_status('publish');
        $v->set_attributes(['pa_color'=>$this->term_slug('pa_color',$color),'pa_size'=>$this->term_slug('pa_size',$size)]);
        if($v->get_sku('edit')===''&&$sku!==''){
            $candidate=$sku;$conflict=(int)wc_get_product_id_by_sku($candidate);if($conflict&&$conflict!==$v->get_id())$candidate='MOM-'.$sku;
            $conflict=(int)wc_get_product_id_by_sku($candidate);if(!$conflict||$conflict===$v->get_id())try{$v->set_sku($candidate);}catch(Exception $e){}
        }
        if(isset($row['qty'])&&$row['qty']!==null&&$row['qty']!==''&&is_numeric($row['qty'])){
            $qty=max(0,(int)$row['qty']);$v->set_manage_stock(true);$v->set_backorders('no');$v->set_stock_quantity($qty);$v->set_stock_status($qty>0?'instock':'outofstock');
        }
        [$price,$source]=$this->momentec_variation_price($row);
        if(!empty($this->sanmar->settings()['sync_variation_base_prices'])&&$price!==null&&$price>0){
            $current=$v->get_regular_price('edit');$managed=(string)$v->get_meta('_asss_base_price_managed')==='yes';
            if($current==='')$this->apply_managed_base_price($v,(float)$price,$source);
            elseif($managed&&$this->managed_price_can_update($v,(float)$price))$this->apply_managed_base_price($v,(float)$price,$source);
        }
        $brand=(string)get_post_meta($product_id,'_asss_momentec_brand',true);$style=(string)get_post_meta($product_id,'_asss_momentec_style',true);
        $v->update_meta_data('_asss_supplier','momentec');$v->update_meta_data('_asss_supplier_product_key','momentec|'.strtolower($brand).'|'.strtolower($style));
        $v->update_meta_data('_asss_momentec_sku_id',$supplier_id);$v->update_meta_data('_asss_momentec_sku',$sku);$v->update_meta_data('_asss_momentec_color',$color);$v->update_meta_data('_asss_momentec_size',$size);
        $v->update_meta_data('_asss_supplier_cost',(string)($row['customer_price'] ?? ''));$v->update_meta_data('_asss_momentec_cost',(string)($row['customer_price'] ?? ''));
        $v->update_meta_data('_asss_momentec_retail_price',(string)($row['retail_price'] ?? ''));$v->update_meta_data('_asss_momentec_availability',sanitize_text_field((string)($row['availability'] ?? '')));
        $v->update_meta_data('_asss_momentec_availability_date',sanitize_text_field((string)($row['availability_date'] ?? '')));$v->delete_meta_data('_asss_stale_variation');$v->delete_meta_data('_asss_stale_variation_reason');
        $variation_id=$v->save();
        $this->multi->register_variation_source($variation_id,'momentec',[
            'sku'=>$sku,'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '',
            'retail_price'=>$row['retail_price'] ?? '','inventory_qty'=>isset($row['qty'])&&is_numeric($row['qty'])?(int)$row['qty']:null,
            'availability'=>(string)($row['availability'] ?? ''),'availability_date'=>(string)($row['availability_date'] ?? ''),'gallery'=>(array)($row['gallery'] ?? []),
        ]);
        if(!empty($this->sanmar->settings()['sync_images'])&&(!empty($row['gallery'])||!empty($row['primary_image']))){
            update_post_meta($variation_id,'_asss_momentec_media_pending','yes');
            update_post_meta($variation_id,'_asss_variation_gallery_urls',array_values(array_unique(array_filter(array_map('esc_url_raw',(array)($row['gallery'] ?? []))))));
        }
        do_action('asss_variation_synced',$variation_id,$product_id,'momentec',['brand'=>$brand,'style'=>$style,'color'=>$color,'size'=>$size,'sku'=>$sku]);
        return ['created'=>$created,'variation_id'=>$variation_id];
    }

    private function reconcile_momentec_variations(int $product_id,array $rows,bool $allow_create=true): array {
        $expected=[];
        foreach($rows as $row){if(!is_array($row))continue;$color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));if($color===''||$size==='')continue;$key=$this->canonical_combo($color,$size);$expected[$key]=$row;}
        $created=0;$updated=0;$seen=[];
        foreach($expected as $combo=>$row){$existing=$this->find_momentec_variation($product_id,(string)($row['unique_key'] ?? $row['sku'] ?? ''),(string)($row['color'] ?? ''),(string)($row['size'] ?? ''));if(!$existing&&!$allow_create)continue;$r=$this->sync_momentec_variation($product_id,$row);if(!empty($r['created']))$created++;elseif(!empty($r['variation_id']))$updated++;if(!empty($r['variation_id']))$seen[$combo]=(int)$r['variation_id'];}
        $expected_source=[];foreach($rows as $row){$k=(string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? $row['sku'] ?? '');if($k==='')$k=$this->canonical_combo((string)($row['color'] ?? ''),(string)($row['size'] ?? ''));if($k!=='')$expected_source[$k]=true;}
        $this->disable_missing_source_variations($product_id,'momentec',$expected_source);
        $missing_price=0;$missing_image=0;$missing_gallery=0;$missing_sku=0;
        foreach($seen as $combo=>$vid){$v=wc_get_product($vid);if(!$v instanceof WC_Product_Variation)continue;$row=$expected[$combo];if($v->get_regular_price('edit')===''||$v->get_price('edit')==='')$missing_price++;if($v->get_sku('edit')==='')$missing_sku++;if(!empty($this->sanmar->settings()['sync_images'])&&!empty($row['gallery'])&&(string)$v->get_meta('_asss_momentec_media_pending')!=='yes'){$saved=$v->get_meta('_asss_variation_gallery_ids');if(is_string($saved)){$d=json_decode($saved,true);if(is_array($d))$saved=$d;}if(!$v->get_image_id())$missing_image++;if(count((array)$row['gallery'])>1&&count((array)$saved)<2)$missing_gallery++;}}
        $audit=['supplier'=>'momentec','expected'=>count($expected),'supplier_variations'=>count($seen),'missing_expected'=>max(0,count($expected)-count($seen)),'missing_price'=>$missing_price,'missing_image_when_available'=>$missing_image,'missing_variation_gallery'=>$missing_gallery,'missing_sku'=>$missing_sku,'created'=>$created,'updated'=>$updated,'checked_at'=>current_time('mysql')];
        update_post_meta($product_id,'_asss_last_variation_audit',wp_json_encode($audit));
        ASSS_Logger::log(array_sum([$audit['missing_expected'],$missing_price,$missing_image,$missing_gallery,$missing_sku])?'Momentec variation audit completed with issues':'Momentec variation audit passed',array_sum([$audit['missing_expected'],$missing_price,$missing_image,$missing_gallery,$missing_sku])?'warning':'info',['product_id'=>$product_id,'audit'=>$audit]);
        return $audit;
    }

    public function import_momentec_style(string $style,array $selected_colors=[]){
        $data=$this->momentec->style_product($style);if(is_wp_error($data))return $data;if(strtolower((string)($data['supplier'] ?? ''))!=='momentec')return new WP_Error('momentec_supplier','Cached product is not a Momentec product.');
        $brand=trim((string)($data['brand'] ?? ''));$style=trim((string)($data['style'] ?? $style));if($style==='')return new WP_Error('momentec_mapping','Cached Momentec product is missing its style number.');
        $variants=is_array($data['variants'] ?? null)?$data['variants']:[];if(!$variants)return new WP_Error('momentec_variants','No exact Momentec SKU rows were cached for this style.');
        $all_colors=$this->momentec_colors_from_variants($variants);$selected_colors=array_values(array_unique(array_filter(array_map('sanitize_text_field',$selected_colors))));if(!$selected_colors)return new WP_Error('no_colors','Choose at least one color before importing.');
        $lookup=array_fill_keys($selected_colors,true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));if(!$variants)return new WP_Error('no_variants','The selected colors contain no real Momentec variations.');
        $product_id=$this->find_momentec_product($style,$brand);if($product_id&&(string)get_post_meta($product_id,'_asss_supplier',true)==='multi')return $this->link_momentec_style_to_product($product_id,$style,$selected_colors);
        if(!$product_id){$other=$this->find_product($style,$brand);if(!$other)$other=$this->find_ss_product_by_brand_style($brand,$style);if($other)return new WP_Error('existing_other_supplier','Another supplier is already linked to a WooCommerce product that appears to match '.$brand.' '.$style.' (product #'.$other.'). Link Momentec to that product instead of creating a duplicate.');}
        $product=$product_id?wc_get_product($product_id):new WC_Product_Variable();if(!$product instanceof WC_Product_Variable)return new WP_Error('product','Could not initialize WooCommerce variable product.');$is_new=!$product_id;
        $title=trim((string)($data['title'] ?? $style));$description=trim((string)($data['description'] ?? ''));$categories=$this->categories_from_normalized_product($data);
        if($is_new){$product->set_name($title?:($brand.' '.$style));$product->set_status('draft');$product->set_catalog_visibility('visible');}elseif($product->get_name()==='')$product->set_name($title?:($brand.' '.$style));
        if(!empty($this->sanmar->settings()['sync_description']))$this->sync_supplier_description($product,$description,$is_new);$this->maybe_set_momentec_parent_sku($product,$brand,$style);
        $mode=count($selected_colors)>=count($all_colors)?'all':'selected';$product->update_meta_data('_asss_supplier','momentec');$product->update_meta_data('_asss_supplier_product_key','momentec|'.strtolower($brand).'|'.strtolower($style));$product->update_meta_data('_asss_momentec_brand',$brand);$product->update_meta_data('_asss_momentec_style',$style);$product->update_meta_data('_asss_momentec_specs',wp_json_encode((array)($data['specs'] ?? [])));$product->update_meta_data('_asss_sync_enabled','yes');$product->update_meta_data('_asss_color_selection_mode',$mode);$product->update_meta_data('_asss_selected_colors',wp_json_encode($selected_colors));$product_id=$product->save();
        $this->multi->register_product_source($product_id,'momentec',['brand'=>$brand,'style'=>$style,'selection_mode'=>$mode,'selected_colors'=>$selected_colors]);update_post_meta($product_id,'_asss_momentec_color_selection_mode',$mode);update_post_meta($product_id,'_asss_momentec_selected_colors',wp_json_encode($selected_colors));
        $this->sync_taxonomies($product_id,$brand,$categories,'',$is_new,'momentec');$common=$this->ss_common_rows($variants,$data);$this->set_attributes($product,$common);$this->sync_parent_shipping($product,$common);$this->sync_momentec_bulk_order_fields($product,$data,$variants,$is_new);$product->save();
        $audit=$this->reconcile_momentec_variations($product_id,$variants,true);if(!empty($this->sanmar->settings()['sync_images'])){$this->sync_momentec_parent_featured_image($product_id,$data,$variants);$this->queue_momentec_media_jobs($product_id,$variants);}$this->sync_managed_pricing_for_product($product_id);
        $product=wc_get_product($product_id);if($product instanceof WC_Product_Variable){$product->update_meta_data('_asss_last_product_sync',current_time('mysql'));$product->save();}WC_Product_Variable::sync($product_id);wc_delete_product_transients($product_id);do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'import']);ASSS_Logger::log('Imported/updated Momentec product','info',['product_id'=>$product_id,'brand'=>$brand,'style'=>$style,'selected_colors'=>count($selected_colors),'expected_variations'=>(int)($audit['expected'] ?? count($variants))]);return $product_id;
    }

    private function update_momentec_style(int $product_id){
        $style=trim((string)get_post_meta($product_id,'_asss_momentec_style',true));if($style==='')return new WP_Error('mapping','Product is missing its Momentec style mapping.');$data=$this->momentec->style_product($style);if(is_wp_error($data))return $data;$variants=is_array($data['variants'] ?? null)?$data['variants']:[];if(!$variants)return new WP_Error('momentec_variants','No exact Momentec SKU rows are cached for this style.');
        $mode=(string)get_post_meta($product_id,'_asss_momentec_color_selection_mode',true)?: (string)get_post_meta($product_id,'_asss_color_selection_mode',true);if($mode!==''&&$mode!=='all'){$sel=json_decode((string)get_post_meta($product_id,'_asss_momentec_selected_colors',true),true);if(is_array($sel)&&$sel){$lookup=array_fill_keys(array_map('strval',$sel),true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));}}
        if(!$variants)return new WP_Error('no_variants','No Momentec variations remain after the saved color selection.');$product=wc_get_product($product_id);if(!$product instanceof WC_Product_Variable)return new WP_Error('product','Product missing or is not variable.');$brand=trim((string)($data['brand'] ?? get_post_meta($product_id,'_asss_momentec_brand',true)));if(!empty($this->sanmar->settings()['sync_description']))$this->sync_supplier_description($product,(string)($data['description'] ?? ''),false);$this->maybe_set_momentec_parent_sku($product,$brand,$style);$product->update_meta_data('_asss_momentec_brand',$brand);$product->update_meta_data('_asss_momentec_specs',wp_json_encode((array)($data['specs'] ?? [])));$product->update_meta_data('_asss_last_product_sync',current_time('mysql'));
        $categories=$this->categories_from_normalized_product($data);$this->sync_taxonomies($product_id,$brand,$categories,'',false,'momentec');$common=$this->ss_common_rows($variants,$data);$this->set_attributes($product,$common);$this->sync_parent_shipping($product,$common);$this->sync_momentec_bulk_order_fields($product,$data,$variants,false);$product->save();$this->reconcile_momentec_variations($product_id,$variants,!empty($this->sanmar->settings()['sync_new_variations']));if(!empty($this->sanmar->settings()['sync_images'])){$this->sync_momentec_parent_featured_image($product_id,$data,$variants);$this->queue_momentec_media_jobs($product_id,$variants);}$this->sync_managed_pricing_for_product($product_id);WC_Product_Variable::sync($product_id);wc_delete_product_transients($product_id);do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'repair']);return $product_id;
    }

    public function link_momentec_style_to_product(int $product_id,string $style,array $selected_colors=[]){
        $product=wc_get_product($product_id);if(!$product instanceof WC_Product_Variable)return new WP_Error('product','The target WooCommerce product is missing or is not variable.');$data=$this->momentec->style_product($style);if(is_wp_error($data))return $data;$brand=trim((string)($data['brand'] ?? ''));$style=trim((string)($data['style'] ?? $style));$variants=is_array($data['variants'] ?? null)?$data['variants']:[];if(!$variants)return new WP_Error('momentec_variants','No Momentec variations are cached for this style.');
        $all=$this->momentec_colors_from_variants($variants);$selected_colors=array_values(array_unique(array_filter(array_map('sanitize_text_field',$selected_colors))));if(!$selected_colors)$selected_colors=$all;$lookup=array_fill_keys($selected_colors,true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));if(!$variants)return new WP_Error('no_variants','No Momentec variations remain for the selected colors.');
        $mode=count($selected_colors)>=count($all)?'all':'selected';update_post_meta($product_id,'_asss_momentec_brand',$brand);update_post_meta($product_id,'_asss_momentec_style',$style);update_post_meta($product_id,'_asss_momentec_specs',wp_json_encode((array)($data['specs'] ?? [])));update_post_meta($product_id,'_asss_momentec_color_selection_mode',$mode);update_post_meta($product_id,'_asss_momentec_selected_colors',wp_json_encode($selected_colors));update_post_meta($product_id,'_asss_sync_enabled','yes');$this->multi->register_product_source($product_id,'momentec',['brand'=>$brand,'style'=>$style,'selection_mode'=>$mode,'selected_colors'=>$selected_colors]);
        $categories=$this->categories_from_normalized_product($data);$this->add_supplier_categories($product_id,$categories,'momentec');$expected=[];$matched=0;$created=0;
        foreach($variants as $row){if(!is_array($row))continue;$color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));$key=(string)($row['unique_key'] ?? $row['sku'] ?? '');if($key==='')$key=$this->canonical_combo($color,$size);$expected[$key]=true;$supplier_id=(string)($row['unique_key'] ?? $row['sku'] ?? '');$vid=$this->find_momentec_variation($product_id,$supplier_id,$color,$size);if(!$vid)$vid=$this->find_variation_by_combo_any($product_id,$color,$size);if(!$vid)$vid=$this->find_variation_by_verified_size_alias($product_id,$brand,$style,$color,$size);
            if(!$vid){$r=$this->sync_momentec_variation($product_id,$row);$vid=(int)($r['variation_id'] ?? 0);if($vid)$created++;}else{$matched++;update_post_meta($vid,'_asss_momentec_sku_id',sanitize_text_field($supplier_id));update_post_meta($vid,'_asss_momentec_sku',sanitize_text_field((string)($row['sku'] ?? '')));update_post_meta($vid,'_asss_momentec_color',$color);update_post_meta($vid,'_asss_momentec_size',$size);update_post_meta($vid,'_asss_momentec_cost',(string)($row['customer_price'] ?? ''));update_post_meta($vid,'_asss_momentec_retail_price',(string)($row['retail_price'] ?? ''));$existing=wc_get_product($vid);if(!empty($this->sanmar->settings()['sync_images'])&&$existing instanceof WC_Product_Variation&&(!empty($row['gallery'])||!empty($row['primary_image'])))update_post_meta($vid,'_asss_momentec_media_pending','yes');}
            if(!$vid)continue;$this->multi->register_variation_source($vid,'momentec',['sku'=>(string)($row['sku'] ?? ''),'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '','retail_price'=>$row['retail_price'] ?? '','inventory_qty'=>isset($row['qty'])&&is_numeric($row['qty'])?(int)$row['qty']:null,'availability'=>(string)($row['availability'] ?? ''),'availability_date'=>(string)($row['availability_date'] ?? ''),'gallery'=>(array)($row['gallery'] ?? [])]);$this->multi->recalculate_variation_inventory($vid);
        }
        $this->disable_missing_source_variations($product_id,'momentec',$expected);$this->rebuild_attributes_from_variations($product_id);if(!empty($this->sanmar->settings()['sync_images'])){$this->sync_momentec_parent_featured_image($product_id,$data,$variants);$this->queue_momentec_media_jobs($product_id,$variants);}$this->sync_managed_pricing_for_product($product_id);$this->multi->recalculate_product_inventory($product_id);update_post_meta($product_id,'_asss_last_product_sync',current_time('mysql'));do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'multi-link']);ASSS_Logger::log('Momentec linked as additional supplier','info',['product_id'=>$product_id,'matched_existing'=>$matched,'created_momentec_only'=>$created]);return $product_id;
    }

    private function hide_discontinued_product(WC_Product $product, string $status): void {
        $already_archived = (string)$product->get_meta('_asss_supplier_archived') === 'yes';
        $product->set_status('draft');
        $product->set_catalog_visibility('hidden');
        $product->set_stock_status('outofstock');
        $product->update_meta_data('_asss_discontinued', 'yes');
        $product->update_meta_data('_asss_supplier_archived', 'yes');
        if (!$already_archived) $product->update_meta_data('_asss_supplier_archived_at', current_time('mysql'));
        $product->update_meta_data('_asss_discontinued_status', $status);
        $product->delete_meta_data('_asss_supplier_reactivated');
        $product->delete_meta_data('_asss_supplier_reactivated_at');
        $product->save();

        if (!$already_archived) {
            $to = $this->sanmar->settings()['admin_notify'] ?: get_option('admin_email');
            wp_mail(
                $to,
                'Supplier product hidden: ' . $product->get_name(),
                "SanMar marked this product as discontinued. It was automatically archived from the storefront (Draft, hidden, and Out of stock).\n\nProduct: " . $product->get_name() . "\nEdit: " . admin_url('post.php?post=' . $product->get_id() . '&action=edit')
            );
        }
        ASSS_Logger::log('Discontinued product automatically archived', 'warning', [
            'product_id' => $product->get_id(), 'status' => $status, 'new_archive' => !$already_archived,
        ]);
    }
}
