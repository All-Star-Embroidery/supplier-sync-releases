#!/usr/bin/env python3
from pathlib import Path
import re
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v206_patch_core.py <source-dir>')
root = Path(sys.argv[1])

def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'v2.0.6 patch marker missing: {label}')
    return text.replace(old, new, 1)

def insert_before_class_close(path, addition):
    text = path.read_text(encoding='utf-8')
    pos = text.rfind('\n}')
    if pos < 0:
        raise SystemExit(f'Could not locate class close in {path}')
    path.write_text(text[:pos] + '\n' + addition.rstrip() + '\n' + text[pos:], encoding='utf-8')

# Main plugin bootstrap/version/dependencies.
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = replace_once(text, 'Version: 2.0.5', 'Version: 2.0.6', 'plugin header version')
text = replace_once(text, "define('ASSS_VERSION', '2.0.5');", "define('ASSS_VERSION', '2.0.6');", 'ASSS_VERSION')
text = text.replace('SanMar and S&S Activewear connectors included, with Momentec API groundwork.', 'SanMar, S&S Activewear, and Momentec production supplier connectors with GitHub Actions bridge synchronization.')
text = replace_once(text,
    '$this->importer = new ASSS_Importer($this->sanmar, $this->ss, $this->multi);',
    '$this->importer = new ASSS_Importer($this->sanmar, $this->ss, $this->momentec, $this->multi);',
    'importer constructor')
text = replace_once(text,
    '$this->bridge = new ASSS_Bridge($this->sanmar, $this->ss, $this->sync);',
    '$this->bridge = new ASSS_Bridge($this->sanmar, $this->ss, $this->momentec, $this->sync);',
    'bridge constructor')
# Remove obsolete connection fields from fresh-install defaults. Existing values
# are already purged by ASSS_Momentec on admin load.
for old in [
    "            'momentec_api_base' => '',\n",
    "            'momentec_account' => '',\n",
    "            'momentec_username' => '',\n",
    "            'momentec_api_key' => '',\n",
    "            'momentec_environment' => 'staging',\n",
]:
    text = text.replace(old, '')
main.write_text(text, encoding='utf-8')

# Replace Momentec groundwork with WordPress-side normalized cache only.
momentec = root / 'includes/class-asss-momentec.php'
momentec.write_text(r'''<?php
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
''', encoding='utf-8')

# Bridge: inject Momentec dependency, routes, and normalized handlers.
bridge = root / 'includes/class-asss-bridge.php'
text = bridge.read_text(encoding='utf-8')
text = replace_once(text,
    "    private ASSS_SS $ss;\n    private ASSS_Sync $sync;\n\n    public function __construct(ASSS_SanMar $sanmar, ASSS_SS $ss, ASSS_Sync $sync) {\n        $this->sanmar = $sanmar;\n        $this->ss = $ss;\n        $this->sync = $sync;",
    "    private ASSS_SS $ss;\n    private ASSS_Momentec $momentec;\n    private ASSS_Sync $sync;\n\n    public function __construct(ASSS_SanMar $sanmar, ASSS_SS $ss, ASSS_Momentec $momentec, ASSS_Sync $sync) {\n        $this->sanmar = $sanmar;\n        $this->ss = $ss;\n        $this->momentec = $momentec;\n        $this->sync = $sync;",
    'bridge dependency')
# Insert routes immediately before bridge status route registration.
route_marker = "        register_rest_route('all-star-supplier-sync/v1', '/bridge/status', ["
route_add = r'''        register_rest_route('all-star-supplier-sync/v1', '/bridge/momentec/style', [
            'methods' => 'POST', 'callback' => [$this, 'receive_momentec_style'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('all-star-supplier-sync/v1', '/bridge/momentec/product-targets', [
            'methods' => 'GET', 'callback' => [$this, 'momentec_product_targets'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('all-star-supplier-sync/v1', '/bridge/inventory/momentec/targets', [
            'methods' => 'GET', 'callback' => [$this, 'momentec_inventory_targets'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('all-star-supplier-sync/v1', '/bridge/inventory/momentec', [
            'methods' => 'POST', 'callback' => [$this, 'receive_momentec_inventory'], 'permission_callback' => [$this, 'authorize'],
        ]);

'''
text = replace_once(text, route_marker, route_add + route_marker, 'Momentec bridge routes')
bridge.write_text(text, encoding='utf-8')

bridge_methods = r'''
    public function receive_momentec_style(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('momentec_payload', 'Momentec bridge payload must be JSON.', ['status'=>400]);
        $product = $payload['product'] ?? $payload;
        if (!is_array($product)) return new WP_Error('momentec_product', 'Momentec bridge payload is missing product data.', ['status'=>400]);
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $result = $this->momentec->save_style($product, $meta);
        if (is_wp_error($result)) return $result;
        $queued = ['queued'=>0,'skipped'=>0];
        if (!empty($payload['queue_repairs']) || !empty($meta['queue_repairs'])) {
            $queued = $this->sync->queue_momentec_style_product_sync((string)$result['style']);
        }
        ASSS_Logger::log('Momentec normalized style received from GitHub', 'info', [
            'style'=>(string)$result['style'],'variants'=>(int)$result['variants'],'queued_repairs'=>(int)($queued['queued'] ?? 0),
        ]);
        return rest_ensure_response(['ok'=>true,'supplier'=>'momentec','style'=>$result['style'],'variants'=>$result['variants'],'repair_queue'=>$queued]);
    }

    public function momentec_product_targets(WP_REST_Request $request) {
        $targets = $this->sync->momentec_product_targets();
        return rest_ensure_response(['ok'=>true,'supplier'=>'momentec','count'=>count($targets),'targets'=>$targets]);
    }

    public function momentec_inventory_targets(WP_REST_Request $request) {
        $targets = $this->sync->inventory_targets_momentec();
        return rest_ensure_response(['ok'=>true,'supplier'=>'momentec','count'=>count($targets),'targets'=>$targets]);
    }

    public function receive_momentec_inventory(WP_REST_Request $request) {
        $settings = $this->sanmar->settings();
        if (empty($settings['bridge_inventory_enabled'])) {
            return new WP_Error('inventory_bridge_disabled', 'GitHub inventory updates are disabled in Supplier Settings.', ['status'=>403]);
        }
        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('momentec_inventory_payload', 'Momentec inventory payload must be JSON.', ['status'=>400]);
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $result = $this->sync->apply_momentec_inventory_payload($rows, $meta);
        if (is_wp_error($result)) return $result;
        return rest_ensure_response(array_merge(['ok'=>true,'supplier'=>'momentec'], $result));
    }
'''
insert_before_class_close(bridge, bridge_methods)

# Sync methods: product targets, strict inventory targets/apply.
sync = root / 'includes/class-asss-sync.php'
sync_methods = r'''
    public function queue_momentec_style_product_sync(string $style): array {
        $ids = [];
        foreach ($this->linked_product_ids(false, 'momentec') as $id) {
            if (strcasecmp((string)get_post_meta($id, '_asss_momentec_style', true), $style) === 0) $ids[] = (int)$id;
        }
        $result = $this->queue_product_repairs($ids);
        ASSS_Logger::log('Momentec style product repairs queued', 'info', ['style'=>$style,'queued'=>$result['queued'],'skipped'=>$result['skipped']]);
        return $result;
    }

    public function momentec_product_targets(): array {
        $out = [];
        foreach ($this->linked_product_ids(false, 'momentec') as $product_id) {
            $style = trim((string)get_post_meta($product_id, '_asss_momentec_style', true));
            if ($style === '') continue;
            $key = strtolower($style);
            if (!isset($out[$key])) $out[$key] = ['style'=>$style,'product_ids'=>[]];
            $out[$key]['product_ids'][] = (int)$product_id;
        }
        foreach ($out as &$row) $row['product_ids'] = array_values(array_unique(array_map('intval', $row['product_ids'])));
        unset($row);
        return array_values($out);
    }

    public function inventory_targets_momentec(): array {
        $targets = [];
        foreach ($this->linked_product_ids(false, 'momentec') as $parent_id) {
            $parent = wc_get_product($parent_id);
            if (!$parent instanceof WC_Product_Variable) continue;
            $ids = get_posts([
                'post_type'=>'product_variation','post_status'=>['publish','private','draft','pending'],
                'post_parent'=>$parent_id,'fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            ]);
            foreach ($ids as $variation_id) {
                $v = wc_get_product((int)$variation_id);
                if (!$v instanceof WC_Product_Variation) continue;
                $sources = $this->multi->variation_sources((int)$variation_id);
                if (empty($sources['momentec']['enabled'])) continue;
                if ((string)$v->get_meta('_asss_stale_variation') === 'yes' || (string)$v->get_meta('_asss_discontinued_variation') === 'yes') continue;
                if ($v->get_status('edit') !== 'publish') continue;
                $src = $sources['momentec'];
                $sku = trim((string)($src['sku'] ?? $src['unique_key'] ?? $v->get_meta('_asss_momentec_sku')));
                if ($sku === '') continue;
                $targets[] = [
                    'variation_id'=>(int)$variation_id,'product_id'=>(int)$parent_id,'sku'=>$sku,
                    'style'=>(string)get_post_meta($parent_id, '_asss_momentec_style', true),
                    'color'=>(string)($src['color'] ?? $v->get_meta('_asss_momentec_color')),
                    'size'=>(string)($src['size'] ?? $v->get_meta('_asss_momentec_size')),
                ];
            }
        }
        return $targets;
    }

    /** Strict all-or-nothing Momentec inventory apply. Missing rows never mean zero. */
    public function apply_momentec_inventory_payload(array $rows, array $meta=[]): array {
        $targets = $this->inventory_targets_momentec();
        if (!$targets) return new WP_Error('no_inventory_targets', 'No linked active Momentec variations are available for inventory sync.');
        $by_id = [];
        foreach ($targets as $target) $by_id[(int)$target['variation_id']] = $target;
        $expected_ids = array_keys($by_id); sort($expected_ids, SORT_NUMERIC);
        $prepared = [];
        foreach ($rows as $row) {
            if (!is_array($row)) return new WP_Error('invalid_inventory_row', 'Momentec inventory payload contains a non-object row.', ['status'=>400]);
            $vid = absint($row['variation_id'] ?? 0);
            if (!$vid || !isset($by_id[$vid])) return new WP_Error('unexpected_inventory_target', 'Momentec inventory payload contains a non-active variation: ' . $vid, ['status'=>409]);
            if (isset($prepared[$vid])) return new WP_Error('duplicate_inventory_target', 'Momentec inventory payload contains duplicate variation #' . $vid . '.', ['status'=>409]);
            $target = $by_id[$vid];
            $sku = sanitize_text_field((string)($row['sku'] ?? ''));
            if ($sku === '' || strcasecmp((string)$target['sku'], $sku) !== 0) return new WP_Error('inventory_identity_mismatch', 'Momentec SKU mismatch for Woo variation #' . $vid . '.', ['status'=>409]);
            $raw_qty = $row['quantity'] ?? null;
            if ($raw_qty === null || $raw_qty === '' || !is_numeric($raw_qty)) return new WP_Error('invalid_inventory_quantity', 'Momentec inventory quantity is missing/non-numeric for Woo variation #' . $vid . '.', ['status'=>400]);
            $prepared[$vid] = [
                'quantity'=>max(0,(int)$raw_qty),
                'availability'=>sanitize_text_field((string)($row['availability'] ?? '')),
                'availability_date'=>sanitize_text_field((string)($row['availability_date'] ?? $row['availabilityDate'] ?? '')),
            ];
        }
        $received_ids = array_keys($prepared); sort($received_ids, SORT_NUMERIC);
        if ($expected_ids !== $received_ids) return new WP_Error('incomplete_inventory_coverage', 'Momentec inventory coverage changed before apply. No stock was changed.', ['status'=>409]);
        $declared = absint($meta['target_count'] ?? 0);
        if ($declared && $declared !== count($expected_ids)) return new WP_Error('inventory_target_count_changed', 'Momentec target count changed before apply. No stock was changed.', ['status'=>409]);

        $objects = [];
        foreach ($prepared as $vid=>$incoming) {
            $v = wc_get_product((int)$vid);
            if (!$v instanceof WC_Product_Variation || empty($this->multi->variation_sources((int)$vid)['momentec']['enabled'])) {
                return new WP_Error('inventory_target_changed', 'Momentec variation #' . (int)$vid . ' changed before inventory apply. No stock was changed.', ['status'=>409]);
            }
            $objects[(int)$vid] = $v;
        }

        $settings = $this->sanmar->settings();
        $buffer = max(0, (int)($settings['stock_buffer'] ?? 0));
        $matched=0; $changed=0; $unchanged=0; $parents=[];
        foreach ($prepared as $vid=>$incoming) {
            $v = $objects[(int)$vid];
            $supplier_qty = (int)$incoming['quantity'];
            $before = (int)$v->get_stock_quantity(); $before_status = (string)$v->get_stock_status();
            $result = $this->multi->set_source_inventory((int)$vid, 'momentec', $supplier_qty, [], [
                'source'=>sanitize_text_field((string)($meta['source'] ?? 'momentec-v2-production-inventory')),
                'availability'=>$incoming['availability'],'availability_date'=>$incoming['availability_date'],
            ]);
            $v = wc_get_product((int)$vid);
            $qty = (int)($result['quantity'] ?? max(0,$supplier_qty-$buffer));
            $after_status = $qty > 0 ? 'instock' : 'outofstock';
            if ($v instanceof WC_Product_Variation) {
                $v->update_meta_data('_asss_supplier_inventory_qty', $supplier_qty);
                $v->update_meta_data('_asss_inventory_source', sanitize_text_field((string)($meta['source'] ?? 'momentec-v2-production-inventory')));
                $v->update_meta_data('_asss_momentec_availability', $incoming['availability']);
                $v->update_meta_data('_asss_momentec_availability_date', $incoming['availability_date']);
                $v->save();
            }
            $matched++;
            if ($before !== $qty || $before_status !== $after_status) $changed++; else $unchanged++;
            $parents[$v->get_parent_id()] = 1;
        }
        foreach (array_keys($parents) as $parent_id) if ($parent_id > 0) { WC_Product_Variable::sync((int)$parent_id); wc_delete_product_transients((int)$parent_id); }
        $status = [
            'received_at'=>current_time('mysql'),'source'=>sanitize_text_field((string)($meta['source'] ?? 'momentec-v2-production-inventory')),
            'source_timestamp'=>sanitize_text_field((string)($meta['source_timestamp'] ?? '')),
            'target_count'=>count($targets),'api_requests'=>absint($meta['api_requests'] ?? 0),
            'rows_received'=>count($rows),'matched'=>$matched,'changed'=>$changed,'unchanged'=>$unchanged,'unmatched'=>0,'stock_buffer'=>$buffer,
        ];
        update_option('asss_momentec_inventory_bridge_status', $status, false);
        ASSS_Logger::log('Momentec GitHub inventory bridge sync complete', 'info', $status);
        return ['rows_received'=>count($rows),'matched'=>$matched,'changed'=>$changed,'unchanged'=>$unchanged,'unmatched'=>0,'stock_buffer'=>$buffer,'message'=>'Momentec inventory updates applied to exact linked WooCommerce variations.'];
    }
'''
insert_before_class_close(sync, sync_methods)

print('Applied v2.0.6 core, Momentec cache, bridge, and inventory sync patches.')
