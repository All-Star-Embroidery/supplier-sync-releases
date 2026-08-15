<?php
if (!defined('ABSPATH')) exit;

/**
 * S&S Activewear supplier catalog adapter.
 *
 * V1.2 keeps credentials in GitHub Actions. WordPress stores only normalized,
 * read-only supplier catalog data that GitHub pushes through the bridge.
 */
class ASSS_SS {
    public function brand_catalog(): array {
        $catalog = get_option('asss_ss_brand_catalog', []);
        return is_array($catalog) ? $catalog : [];
    }

    public function save_brand_catalog(array $brands): array {
        $existing = $this->brand_catalog();
        $out = [];

        foreach ($brands as $item) {
            if (!is_array($item)) continue;
            $brand_id = absint($item['brand_id'] ?? $item['brandID'] ?? $item['brandId'] ?? 0);
            $brand = trim(sanitize_text_field((string)($item['brand'] ?? $item['name'] ?? $item['brandName'] ?? '')));
            if ($brand_id < 1 || $brand === '') continue;

            $was = null;
            foreach ($existing as $old_id => $old) {
                if ((int)$old_id === $brand_id) { $was = $old; break; }
                if (is_array($old) && strcasecmp((string)($old['brand'] ?? ''), $brand) === 0) { $was = $old; break; }
            }

            $out[(string)$brand_id] = [
                'brand_id' => $brand_id,
                'brand' => $brand,
                'enabled' => is_array($was) && !empty($was['enabled']) ? 1 : 0,
                'image' => esc_url_raw((string)($item['image'] ?? $item['brandImage'] ?? $item['brandImageUrl'] ?? '')),
                'e_retailing_restricted' => !empty($item['e_retailing_restricted']) || !empty($item['eRetailingRestricted']) ? 1 : 0,
                'discovered_at' => current_time('mysql'),
            ];
        }

        uasort($out, static fn($a, $b) => strnatcasecmp((string)($a['brand'] ?? ''), (string)($b['brand'] ?? '')));
        update_option('asss_ss_brand_catalog', $out, false);
        return $out;
    }

    public function set_enabled_brands(array $brand_ids): array {
        $catalog = $this->brand_catalog();
        $wanted = array_fill_keys(array_map('strval', array_map('absint', $brand_ids)), 1);
        foreach ($catalog as $id => &$meta) {
            $meta['enabled'] = isset($wanted[(string)$id]) ? 1 : 0;
        }
        unset($meta);
        update_option('asss_ss_brand_catalog', $catalog, false);
        return $catalog;
    }

    public function enabled_brand_catalog(): array {
        return array_filter($this->brand_catalog(), static fn($m) => !empty($m['enabled']));
    }

    public function brand_meta(int $brand_id): array {
        $catalog = $this->brand_catalog();
        $meta = $catalog[(string)$brand_id] ?? [];
        return is_array($meta) ? $meta : [];
    }

    private function cache_root(): string {
        $uploads = wp_upload_dir();
        return trailingslashit((string)$uploads['basedir']) . 'all-star-supplier-sync/ss';
    }

    private function live_brand_dir(int $brand_id): string {
        return trailingslashit($this->cache_root()) . 'brands/' . $brand_id;
    }

    private function staging_brand_dir(string $batch_id, int $brand_id): string {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $batch_id) ?: 'batch';
        return trailingslashit($this->cache_root()) . 'staging/' . $safe . '/' . $brand_id;
    }

    private function ensure_dir(string $dir) {
        if (is_dir($dir)) return true;
        if (wp_mkdir_p($dir)) return true;
        return new WP_Error('ss_cache_dir', 'Could not create the S&S supplier cache directory.');
    }

    private function write_json_atomic(string $path, array $data) {
        $ok = $this->ensure_dir(dirname($path));
        if (is_wp_error($ok)) return $ok;
        $tmp = $path . '.tmp-' . wp_generate_password(8, false, false);
        $bytes = @file_put_contents($tmp, wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($bytes === false) return new WP_Error('ss_cache_write', 'Could not write the S&S supplier cache.');
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return new WP_Error('ss_cache_publish', 'Could not publish the S&S supplier cache file.');
        }
        return true;
    }

    private function remove_tree(string $dir): void {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) $this->remove_tree($path); else @unlink($path);
        }
        @rmdir($dir);
    }

    public function representative_product_image(array $product): string {
        foreach ((array)($product['variants'] ?? []) as $variant) {
            if (!is_array($variant)) continue;
            $primary = trim((string)($variant['primary_image'] ?? ''));
            if ($primary !== '') return $primary;
            foreach ((array)($variant['gallery'] ?? []) as $url) {
                $url = trim((string)$url);
                if ($url !== '') return $url;
            }
        }
        foreach ([(string)($product['images']['product'] ?? ''), (string)($product['images']['thumbnail'] ?? '')] as $url) {
            $url = trim($url);
            if ($url !== '') return $url;
        }
        return '';
    }

    private function product_summary(array $product): array {
        $variants = isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [];
        $colors = [];
        $sizes = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            $color = trim((string)($variant['color'] ?? $variant['catalog_color'] ?? ''));
            $size = trim((string)($variant['size'] ?? ''));
            if ($color !== '') $colors[mb_strtolower($color)] = $color;
            if ($size !== '') $sizes[mb_strtolower($size)] = $size;
        }
        $categories = isset($product['categories']) && is_array($product['categories']) ? array_values(array_filter(array_map('sanitize_text_field', $product['categories']))) : [];
        $base = trim(sanitize_text_field((string)($product['category'] ?? $product['base_category'] ?? '')));
        if ($base !== '' && !in_array($base, $categories, true)) array_unshift($categories, $base);
        $style_id = absint($product['supplier_style_id'] ?? $product['style_id'] ?? 0);
        return [
            'style_id' => $style_id,
            'style' => sanitize_text_field((string)($product['style'] ?? '')),
            'title' => sanitize_text_field((string)($product['title'] ?? '')),
            'brand' => sanitize_text_field((string)($product['brand'] ?? '')),
            'category' => $base,
            'categories' => array_values(array_unique($categories)),
            'image' => esc_url_raw($this->representative_product_image($product)),
            'color_count' => count($colors),
            'size_count' => count($sizes),
            'variant_count' => count($variants),
            'sparse_missing' => max(0, (count($colors) * max(1, count($sizes))) - count($variants)),
            'new_style' => !empty($product['new_style']) ? 1 : 0,
            'sustainable_style' => !empty($product['sustainable_style']) ? 1 : 0,
        ];
    }

    /**
     * Stage chunked normalized products and atomically publish only after every
     * expected chunk arrives. Existing live catalog remains untouched on partial
     * uploads, matching the safety model used by the SanMar V1 cache.
     */
    public function save_style_catalog_chunk(int $brand_id, string $brand, string $batch_id, int $chunk_index, int $chunk_count, array $products, array $meta = []) {
        if ($brand_id < 1 || $brand === '') return new WP_Error('ss_brand', 'A valid S&S brand is required.');
        if ($batch_id === '' || $chunk_index < 0 || $chunk_count < 1 || $chunk_index >= $chunk_count) {
            return new WP_Error('ss_batch', 'Invalid S&S catalog batch metadata.');
        }

        $stage = $this->staging_brand_dir($batch_id, $brand_id);
        $ok = $this->ensure_dir($stage);
        if (is_wp_error($ok)) return $ok;

        $state_path = $stage . '/state.json';
        $state = [];
        if (is_file($state_path)) {
            $decoded = json_decode((string)@file_get_contents($state_path), true);
            if (is_array($decoded)) $state = $decoded;
        }
        $state = wp_parse_args($state, [
            'batch_id' => $batch_id,
            'brand_id' => $brand_id,
            'brand' => $brand,
            'chunk_count' => $chunk_count,
            'received' => [],
            'summaries' => [],
            'variation_count' => 0,
        ]);
        if ((int)$state['chunk_count'] !== $chunk_count) return new WP_Error('ss_batch_count', 'S&S chunk count changed during an active batch.');

        $chunk_variants = 0;
        foreach ($products as $product) {
            if (!is_array($product)) continue;
            $supplier = strtolower(trim((string)($product['supplier'] ?? 'ss')));
            $style_id = absint($product['supplier_style_id'] ?? $product['style_id'] ?? 0);
            $style = trim(sanitize_text_field((string)($product['style'] ?? '')));
            if ($supplier !== 'ss' || $style_id < 1 || $style === '') continue;
            $product['supplier'] = 'ss';
            $product['supplier_name'] = 'S&S Activewear';
            $product['supplier_style_id'] = $style_id;
            $product['brand'] = sanitize_text_field((string)($product['brand'] ?? $brand));
            $variants = isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [];
            $chunk_variants += count($variants);
            $write = $this->write_json_atomic($stage . '/style-' . $style_id . '.json', $product);
            if (is_wp_error($write)) return $write;
            $state['summaries'][(string)$style_id] = $this->product_summary($product);
        }

        $state['received'][(string)$chunk_index] = 1;
        $state['variation_count'] = array_sum(array_map(static fn($s) => (int)($s['variant_count'] ?? 0), (array)$state['summaries']));
        $write = $this->write_json_atomic($state_path, $state);
        if (is_wp_error($write)) return $write;

        if (count($state['received']) < $chunk_count) {
            return [
                'complete' => false,
                'received_chunks' => count($state['received']),
                'chunk_count' => $chunk_count,
                'styles' => count($state['summaries']),
                'variants' => (int)$state['variation_count'],
            ];
        }

        ksort($state['summaries'], SORT_NATURAL);
        $index = [
            'supplier' => 'ss',
            'brand_id' => $brand_id,
            'brand' => $brand,
            'received_at' => current_time('mysql'),
            'styles' => array_values($state['summaries']),
            'style_count' => count($state['summaries']),
            'variant_count' => (int)$state['variation_count'],
            'source' => sanitize_text_field((string)($meta['source'] ?? 'github-actions')),
        ];
        $write = $this->write_json_atomic($stage . '/index.json', $index);
        if (is_wp_error($write)) return $write;
        @unlink($state_path);

        $live = $this->live_brand_dir($brand_id);
        $parent = dirname($live);
        $ok = $this->ensure_dir($parent);
        if (is_wp_error($ok)) return $ok;
        $backup = $live . '.old-' . time();
        if (is_dir($live)) @rename($live, $backup);
        if (!@rename($stage, $live)) {
            if (is_dir($backup)) @rename($backup, $live);
            return new WP_Error('ss_catalog_publish', 'Could not atomically publish the completed S&S brand catalog.');
        }
        $this->remove_tree($backup);

        $manifest = get_option('asss_ss_style_manifest', []);
        if (!is_array($manifest)) $manifest = [];
        $manifest[(string)$brand_id] = [
            'brand_id' => $brand_id,
            'brand' => $brand,
            'received_at' => current_time('mysql'),
            'style_count' => count($state['summaries']),
            'variant_count' => (int)$state['variation_count'],
            'batch_id' => $batch_id,
        ];
        update_option('asss_ss_style_manifest', $manifest, false);

        return [
            'complete' => true,
            'received_chunks' => $chunk_count,
            'chunk_count' => $chunk_count,
            'styles' => count($state['summaries']),
            'variants' => (int)$state['variation_count'],
        ];
    }

    public function style_manifest(): array {
        $manifest = get_option('asss_ss_style_manifest', []);
        return is_array($manifest) ? $manifest : [];
    }

    public function style_summaries(int $brand_id, string $search = '', string $category = '', int $limit = 250): array {
        $path = $this->live_brand_dir($brand_id) . '/index.json';
        if (!is_file($path)) return [];
        $index = json_decode((string)@file_get_contents($path), true);
        $styles = is_array($index) && isset($index['styles']) && is_array($index['styles']) ? $index['styles'] : [];
        $needle = mb_strtolower(trim($search));
        $cat = mb_strtolower(trim($category));
        $out = [];
        foreach ($styles as $style) {
            if (!is_array($style)) continue;
            if ($needle !== '') {
                $hay = mb_strtolower(implode(' ', [
                    (string)($style['style'] ?? ''), (string)($style['title'] ?? ''),
                    (string)($style['category'] ?? ''), implode(' ', (array)($style['categories'] ?? [])),
                ]));
                if (mb_strpos($hay, $needle) === false) continue;
            }
            if ($cat !== '') {
                $cats = array_map(static fn($v) => mb_strtolower((string)$v), (array)($style['categories'] ?? []));
                if (!in_array($cat, $cats, true)) continue;
            }
            $style_id = absint($style['style_id'] ?? 0);
            if ($style_id > 0) {
                $detail = $this->style_product($brand_id, $style_id);
                if (!is_wp_error($detail)) {
                    $representative = $this->representative_product_image($detail);
                    if ($representative !== '') $style['image'] = $representative;
                }
            }
            $out[] = $style;
            if ($limit > 0 && count($out) >= $limit) break;
        }
        return $out;
    }

    public function available_categories(int $brand_id): array {
        $styles = $this->style_summaries($brand_id, '', '', 0);
        $cats = [];
        foreach ($styles as $style) {
            foreach ((array)($style['categories'] ?? []) as $category) {
                $category = trim((string)$category);
                if ($category !== '') $cats[mb_strtolower($category)] = $category;
            }
        }
        natcasesort($cats);
        return array_values($cats);
    }

    public function style_product(int $brand_id, int $style_id) {
        if ($brand_id < 1 || $style_id < 1) return new WP_Error('ss_style', 'A valid S&S brand/style is required.');
        $path = $this->live_brand_dir($brand_id) . '/style-' . $style_id . '.json';
        if (!is_file($path)) return new WP_Error('ss_style_missing', 'This S&S style has not been cached yet. Run S&S Product Catalog Sync in GitHub Actions.');
        $product = json_decode((string)@file_get_contents($path), true);
        if (!is_array($product)) return new WP_Error('ss_style_decode', 'The cached S&S style data could not be read.');
        return $product;
    }
}
