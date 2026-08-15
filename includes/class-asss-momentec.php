<?php
if (!defined('ABSPATH')) exit;

/**
 * Momentec normalized catalog cache.
 *
 * Security boundary: WordPress <-> GitHub Actions <-> Momentec production.
 * WordPress never stores or receives Momentec login credentials. GitHub calls
 * Momentec v2, normalizes exact supplier rows, and sends only supplier product,
 * pricing, image, and inventory data through the authenticated ASSS bridge.
 */
class ASSS_Momentec {
    public const KEY = 'momentec';
    public const LABEL = 'Momentec Brands';

    public function __construct() {
        add_action('admin_init', [$this, 'purge_legacy_wordpress_connection_values'], 1);
    }

    public function settings(): array {
        $s = get_option('asss_settings', []);
        return is_array($s) ? $s : [];
    }

    public function configured(): bool {
        return !empty($this->settings()['momentec_enabled']);
    }

    private function cache_root(): string {
        $uploads = wp_upload_dir();
        return trailingslashit((string)$uploads['basedir']) . 'all-star-supplier-sync/momentec';
    }

    private function styles_dir(): string {
        return trailingslashit($this->cache_root()) . 'styles';
    }

    private function ensure_dir(string $dir) {
        if (is_dir($dir)) return true;
        if (wp_mkdir_p($dir)) return true;
        return new WP_Error('momentec_cache_dir', 'Could not create the Momentec supplier cache directory.');
    }

    private function write_json_atomic(string $path, array $data) {
        $ok = $this->ensure_dir(dirname($path));
        if (is_wp_error($ok)) return $ok;
        $tmp = $path . '.tmp-' . wp_generate_password(8, false, false);
        $bytes = @file_put_contents($tmp, wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($bytes === false) return new WP_Error('momentec_cache_write', 'Could not write the Momentec supplier cache.');
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return new WP_Error('momentec_cache_publish', 'Could not publish the Momentec supplier cache file.');
        }
        return true;
    }

    private function style_key(string $style): string {
        return strtolower(trim($style));
    }

    private function style_filename(string $style): string {
        return sha1($this->style_key($style)) . '.json';
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

    private function summary(array $product): array {
        $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
        $colors = []; $sizes = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            $color = trim((string)($variant['color'] ?? $variant['catalog_color'] ?? ''));
            $size = trim((string)($variant['size'] ?? ''));
            if ($color !== '') $colors[mb_strtolower($color)] = $color;
            if ($size !== '') $sizes[mb_strtolower($size)] = $size;
        }
        $style = sanitize_text_field((string)($product['style'] ?? $product['supplier_style_id'] ?? ''));
        return [
            'style'=>$style,
            'title'=>sanitize_text_field((string)($product['title'] ?? $style)),
            'brand'=>sanitize_text_field((string)($product['brand'] ?? '')),
            'category'=>sanitize_text_field((string)($product['category'] ?? '')),
            'image'=>esc_url_raw($this->representative_product_image($product)),
            'color_count'=>count($colors),
            'size_count'=>count($sizes),
            'variant_count'=>count($variants),
            'sparse_missing'=>max(0, count($colors) * count($sizes) - count($variants)),
        ];
    }

    /** Store one complete normalized Momentec style pushed by GitHub. */
    public function save_style(array $product, array $meta = []) {
        if (strtolower(trim((string)($product['supplier'] ?? ''))) !== 'momentec') {
            return new WP_Error('momentec_supplier', 'Momentec cache payload has the wrong supplier.', ['status'=>400]);
        }
        $style = trim(sanitize_text_field((string)($product['style'] ?? $product['supplier_style_id'] ?? '')));
        if ($style === '') return new WP_Error('momentec_style', 'Momentec cache payload is missing a style/product number.', ['status'=>400]);
        $variants = is_array($product['variants'] ?? null) ? array_values($product['variants']) : [];
        if (!$variants) return new WP_Error('momentec_variants', 'Momentec cache payload contains no exact supplier variations.', ['status'=>400]);

        $seen_skus = []; $seen_combos = [];
        foreach ($variants as $i=>$row) {
            if (!is_array($row)) return new WP_Error('momentec_variant', 'Momentec variation row is not an object.', ['status'=>400]);
            $sku = trim(sanitize_text_field((string)($row['sku'] ?? $row['unique_key'] ?? '')));
            $color = trim(sanitize_text_field((string)($row['color'] ?? $row['catalog_color'] ?? '')));
            $size = trim(sanitize_text_field((string)($row['size'] ?? '')));
            if ($sku === '' || $color === '' || $size === '') {
                return new WP_Error('momentec_variant_identity', 'Momentec variation row #' . ($i + 1) . ' is missing SKU, color, or size.', ['status'=>400]);
            }
            $sku_key = strtolower($sku);
            $combo = strtolower(preg_replace('/\s+/u', ' ', $color)) . '|' . strtolower(preg_replace('/\s+/u', ' ', $size));
            if (isset($seen_skus[$sku_key]) || isset($seen_combos[$combo])) {
                return new WP_Error('momentec_duplicate_variant', 'Momentec payload contains a duplicate exact SKU or Color+Size combination.', ['status'=>409]);
            }
            $seen_skus[$sku_key] = 1; $seen_combos[$combo] = 1;
        }

        $product['supplier'] = 'momentec';
        $product['supplier_name'] = self::LABEL;
        $product['style'] = $style;
        $product['supplier_style_id'] = $style;
        $product['received_at'] = current_time('mysql');
        $product['bridge_meta'] = [
            'source'=>sanitize_text_field((string)($meta['source'] ?? 'momentec-v2-production')),
            'source_timestamp'=>sanitize_text_field((string)($meta['source_timestamp'] ?? '')),
        ];

        $file = $this->style_filename($style);
        $write = $this->write_json_atomic($this->styles_dir() . '/' . $file, $product);
        if (is_wp_error($write)) return $write;

        $manifest = get_option('asss_momentec_style_manifest', []);
        if (!is_array($manifest)) $manifest = [];
        $summary = $this->summary($product);
        $summary['file'] = $file;
        $summary['received_at'] = current_time('mysql');
        $summary['source'] = sanitize_text_field((string)($meta['source'] ?? 'momentec-v2-production'));
        $manifest[$this->style_key($style)] = $summary;
        uasort($manifest, static fn($a,$b) => strnatcasecmp((string)($a['style'] ?? ''), (string)($b['style'] ?? '')));
        update_option('asss_momentec_style_manifest', $manifest, false);

        return ['style'=>$style,'variants'=>count($variants),'summary'=>$summary];
    }

    public function style_manifest(): array {
        $manifest = get_option('asss_momentec_style_manifest', []);
        return is_array($manifest) ? $manifest : [];
    }

    public function style_summaries(string $search = '', int $limit = 250): array {
        $needle = mb_strtolower(trim($search));
        $out = [];
        foreach ($this->style_manifest() as $summary) {
            if (!is_array($summary)) continue;
            if ($needle !== '') {
                $hay = mb_strtolower(implode(' ', [(string)($summary['style'] ?? ''),(string)($summary['title'] ?? ''),(string)($summary['brand'] ?? ''),(string)($summary['category'] ?? '')]));
                if (mb_strpos($hay, $needle) === false) continue;
            }
            $out[] = $summary;
            if ($limit > 0 && count($out) >= $limit) break;
        }
        return $out;
    }

    public function style_product(string $style) {
        $key = $this->style_key($style);
        $meta = $this->style_manifest()[$key] ?? [];
        if (!is_array($meta) || empty($meta['file'])) return new WP_Error('momentec_style_missing', 'Momentec style is not cached yet. Publish it from GitHub Actions first.');
        $path = $this->styles_dir() . '/' . basename((string)$meta['file']);
        if (!is_file($path)) return new WP_Error('momentec_style_file', 'Momentec cached style file is missing. Publish the style again from GitHub Actions.');
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) return new WP_Error('momentec_style_json', 'Momentec cached style file is invalid.');
        return $decoded;
    }

    public function purge_legacy_wordpress_connection_values(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $s = get_option('asss_settings', []);
        if (!is_array($s)) return;
        $changed = false;
        foreach (['momentec_username','momentec_password','momentec_api_key','momentec_secret','momentec_api_base','momentec_account','momentec_environment'] as $key) {
            if (array_key_exists($key, $s)) { unset($s[$key]); $changed = true; }
        }
        if ($changed) update_option('asss_settings', $s, false);
    }

    public function status(): array {
        $manifest = $this->style_manifest();
        $variants = 0;
        foreach ($manifest as $row) if (is_array($row)) $variants += (int)($row['variant_count'] ?? 0);
        return [
            'supplier'=>self::KEY,
            'label'=>self::LABEL,
            'configured'=>$this->configured(),
            'enabled'=>$this->configured(),
            'connection_owner'=>'github-actions',
            'credentials_location'=>'github-actions-secrets',
            'environment'=>'production',
            'state'=>$this->configured() ? 'production-github-bridge-enabled' : 'disabled',
            'catalog_sync'=>'production-v2-style-cache',
            'inventory_sync'=>'production-v2-targeted-inventory',
            'cached_styles'=>count($manifest),
            'cached_variants'=>$variants,
        ];
    }

    public function capabilities(): array {
        return [
            'catalog'=>true,
            'inventory'=>true,
            'orders'=>false,
            'order_status'=>false,
            'live_adapter_enabled'=>true,
            'supplier_auth_location'=>'github-actions',
            'api_version'=>'v2',
            'environment'=>'production',
        ];
    }
}
