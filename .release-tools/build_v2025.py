#!/usr/bin/env python3
from pathlib import Path
import re

root = Path('source')
main = root / 'all-star-supplier-sync.php'
readme = root / 'readme.txt'
importer = root / 'includes/class-asss-importer.php'
sanmar = root / 'includes/class-asss-sanmar.php'
bridge = root / 'includes/class-asss-bridge.php'

# Version metadata.
s = main.read_text()
s = s.replace('Version: 2.0.24', 'Version: 2.0.25')
s = s.replace("define('ASSS_VERSION', '2.0.24');", "define('ASSS_VERSION', '2.0.25');")
main.write_text(s)

s = readme.read_text()
s = s.replace('Stable tag: 2.0.24', 'Stable tag: 2.0.25')
marker = '== Changelog ==\n\n'
entry = "= 2.0.25 =\n* Extends GitHub-side SanMar storefront image normalization from the featured image to Supplier Sync-owned parent product gallery images.\n* Gallery normalization is role-aware: featured and gallery jobs cannot overwrite one another, and merchant gallery uploads remain untouched.\n* Normalized SanMar attachments are preferred on later product refreshes so raw supplier images do not reappear after repair/sync.\n* Existing SanMar product galleries are queued once after upgrade; inventory-only sync remains image-free.\n\n"
assert marker in s
s = s.replace(marker, marker + entry, 1)
readme.write_text(s)

# SanMar queue: make jobs role-aware and allow a larger bounded queue for gallery backlog.
s = sanmar.read_text()
s = s.replace('if (count($rows) > 500) {', 'if (count($rows) > 2000) {', 1)
s = s.replace('$rows = array_slice($rows, -500, null, true);', '$rows = array_slice($rows, -2000, null, true);', 1)

new_queue = r'''    public function queue_storefront_image_request(int $product_id, string $brand, string $style, string $source_url, string $normalizer_version='sanmar-square-v1', string $role='featured'): string {
        $source_url = esc_url_raw(trim($source_url));
        $normalizer_version = sanitize_key($normalizer_version);
        $role = sanitize_key($role);
        if (!in_array($role, ['featured','gallery'], true)) $role = 'featured';
        if ($product_id < 1 || $source_url === '' || $normalizer_version === '') return '';
        $product = wc_get_product($product_id);
        if (!$product) return '';

        if ($role === 'featured') {
            // Never spend GitHub work on a merchant-selected or another-supplier featured image.
            $current = (int)$product->get_image_id();
            if ($current) {
                $source = sanitize_key((string)get_post_meta($current, '_asss_image_source', true));
                if (!in_array($source, ['sanmar','sanmar-normalized'], true)) return '';
            }
            $done_source = (string)get_post_meta($product_id, '_asss_sanmar_normalized_source_url', true);
            $done_version = (string)get_post_meta($product_id, '_asss_sanmar_normalizer_version', true);
            $done_attachment = (int)get_post_meta($product_id, '_asss_sanmar_normalized_attachment_id', true);
            if ($done_source === $source_url && $done_version === $normalizer_version && $done_attachment > 0 && get_post($done_attachment)) return '';
            update_post_meta($product_id, '_asss_sanmar_normalize_source_url', $source_url);
        } else {
            // Gallery work is allowed even when the merchant owns the featured image,
            // but only when this exact raw SanMar source is currently in the gallery.
            $has_raw_target = false;
            foreach ((array)$product->get_gallery_image_ids() as $id) {
                $id = (int)$id;
                if (!$id) continue;
                $image_source = sanitize_key((string)get_post_meta($id, '_asss_image_source', true));
                $image_url = (string)get_post_meta($id, '_asss_image_url', true);
                $image_version = sanitize_key((string)get_post_meta($id, '_asss_normalizer_version', true));
                if ($image_source === 'sanmar-normalized' && $image_url === $source_url && $image_version === $normalizer_version) return '';
                if ($image_source === 'sanmar' && $image_url === $source_url) $has_raw_target = true;
            }
            if (!$has_raw_target) return '';
        }

        $request_id = substr(hash('sha256', $product_id.'|'.$role.'|'.$source_url.'|'.$normalizer_version), 0, 32);
        $rows = $this->storefront_image_queue();
        $now = gmdate('c');
        $existing = isset($rows[$request_id]) && is_array($rows[$request_id]) ? $rows[$request_id] : [];
        $rows[$request_id] = [
            'request_id'=>$request_id,
            'product_id'=>$product_id,
            'brand'=>sanitize_text_field($brand),
            'style'=>sanitize_text_field($style),
            'source_url'=>$source_url,
            'normalizer_version'=>$normalizer_version,
            'role'=>$role,
            'status'=>'pending',
            'attempts'=>(int)($existing['attempts'] ?? 0),
            'created_at'=>(string)($existing['created_at'] ?? $now),
            'updated_at'=>$now,
            'error'=>'',
        ];
        $this->save_storefront_image_queue($rows);
        return $request_id;
    }

'''
pattern = re.compile(r"    public function queue_storefront_image_request\(.*?\n    public function pending_storefront_image_requests", re.S)
m = pattern.search(s)
assert m, 'SanMar queue function not found'
s = s[:m.start()] + new_queue + '    public function pending_storefront_image_requests' + s[m.end():]
sanmar.write_text(s)

# Importer: queue all Supplier Sync-owned SanMar parent gallery images after any product sync,
# and queue existing galleries once after upgrade.
s = importer.read_text()
ctor_needle = "        add_action('admin_init', [$this, 'migrate_sanmar_storefront_images_v2024'], 41);\n"
assert ctor_needle in s
s = s.replace(ctor_needle, ctor_needle + "        add_action('admin_init', [$this, 'migrate_sanmar_gallery_images_v2025'], 42);\n", 1)
hook_needle = "        add_action('asss_product_synced', [$this, 'cleanup_invalid_supplier_storefront_media'], 50, 2);\n"
assert hook_needle in s
s = s.replace(hook_needle, hook_needle + "        add_action('asss_product_synced', [$this, 'queue_sanmar_product_gallery_normalization'], 55, 2);\n", 1)

methods = r'''
    /** v2.0.25: queue the current Supplier Sync-owned SanMar parent gallery for GitHub normalization. */
    public function queue_sanmar_product_gallery_normalization(int $product_id, string $supplier = ''): void {
        if ($product_id < 1 || empty($this->sanmar->settings()['sync_images'])) return;
        $brand = trim((string)get_post_meta($product_id, '_asss_sanmar_brand', true));
        $style = trim((string)get_post_meta($product_id, '_asss_sanmar_style', true));
        if ($brand === '' || $style === '') return;
        $product = wc_get_product($product_id);
        if (!$product) return;

        // Featured remains the v2.0.24 behavior. This also repairs a raw featured
        // image if a product refresh ever reintroduced one.
        $featured = (int)$product->get_image_id();
        if ($featured && sanitize_key((string)get_post_meta($featured, '_asss_image_source', true)) === 'sanmar') {
            $url = trim((string)get_post_meta($featured, '_asss_image_url', true));
            if ($url !== '') $this->sanmar->queue_storefront_image_request($product_id, $brand, $style, $url, 'sanmar-square-v1', 'featured');
        }

        // Parent gallery only: do not crawl the whole Media Library and do not
        // queue merchant uploads or S&S/Momentec imagery on multi-supplier products.
        $queued = 0;
        foreach ((array)$product->get_gallery_image_ids() as $id) {
            $id = (int)$id;
            if (!$id || sanitize_key((string)get_post_meta($id, '_asss_image_source', true)) !== 'sanmar') continue;
            $url = trim((string)get_post_meta($id, '_asss_image_url', true));
            if ($url === '' || $this->is_invalid_storefront_media_url($url)) continue;
            if ($this->sanmar->queue_storefront_image_request($product_id, $brand, $style, $url, 'sanmar-square-v1', 'gallery') !== '') $queued++;
        }
        if ($queued) ASSS_Logger::log('Queued SanMar parent gallery images for GitHub normalization','info',['product_id'=>$product_id,'queued'=>$queued]);
    }

    /** v2.0.25: one-time queue of existing SanMar parent galleries. */
    public function migrate_sanmar_gallery_images_v2025(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2025_sanmar_gallery_images_migrated','') === 'yes') return;
        if (empty($this->sanmar->settings()['sync_images'])) { update_option('asss_v2025_sanmar_gallery_images_migrated','yes',false); return; }
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_key'=>'_asss_sanmar_style','meta_compare'=>'EXISTS',
        ]);
        foreach ((array)$ids as $product_id) $this->queue_sanmar_product_gallery_normalization((int)$product_id, 'v2025-migration');
        update_option('asss_v2025_sanmar_gallery_images_migrated','yes',false);
        ASSS_Logger::log('v2.0.25 queued existing SanMar parent galleries for GitHub normalization','info',['products'=>count((array)$ids)]);
    }

'''
insert_at = s.find("    /** v2.0.24: queue existing SanMar-owned featured images once after upgrade. */")
assert insert_at != -1, 'v2.0.24 migration marker not found'
s = s[:insert_at] + methods + s[insert_at:]

# Prefer a previously-normalized SanMar attachment when sideloading the same URL.
old = """        $existing = get_posts([\n            'post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>1,\n            'meta_key'=>'_asss_image_url','meta_value'=>$url,'no_found_rows'=>true,\n        ]);\n        if (!empty($existing[0])) return $this->sideload_cache[$url] = (int)$existing[0];\n"""
new = """        $existing = get_posts([\n            'post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>10,\n            'meta_key'=>'_asss_image_url','meta_value'=>$url,'no_found_rows'=>true,\n        ]);\n        if ($existing) {\n            if ($source === 'sanmar') {\n                foreach ((array)$existing as $existing_id) {\n                    if (sanitize_key((string)get_post_meta((int)$existing_id,'_asss_image_source',true)) === 'sanmar-normalized') {\n                        return $this->sideload_cache[$url] = (int)$existing_id;\n                    }\n                }\n            }\n            return $this->sideload_cache[$url] = (int)$existing[0];\n        }\n"""
assert old in s, 'sideload existing-attachment block not found'
s = s.replace(old, new, 1)
importer.write_text(s)

# Bridge: role-aware result application. Gallery jobs replace only raw SanMar IDs,
# preserve ordering/manual media, and reuse any existing normalized attachment.
s = bridge.read_text()
new_result = r'''    public function receive_sanmar_image_result(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('invalid_json','Request body must be JSON.',['status'=>400]);
        $request_id = sanitize_text_field((string)($payload['request_id'] ?? ''));
        $queued = $this->sanmar->storefront_image_request($request_id);
        if ($request_id === '' || !$queued) return new WP_Error('image_request','Unknown or expired SanMar image request.',['status'=>404]);

        $product_id = absint($payload['product_id'] ?? 0);
        $source_url = esc_url_raw((string)($payload['source_url'] ?? ''));
        $version = sanitize_key((string)($payload['normalizer_version'] ?? ''));
        $role = sanitize_key((string)($queued['role'] ?? 'featured'));
        if (!in_array($role, ['featured','gallery'], true)) $role = 'featured';
        if ($product_id !== (int)($queued['product_id'] ?? 0) || $source_url !== (string)($queued['source_url'] ?? '') || $version !== sanitize_key((string)($queued['normalizer_version'] ?? ''))) {
            return new WP_Error('image_request_mismatch','SanMar image result does not match the queued request.',['status'=>409]);
        }

        $error = trim((string)($payload['error'] ?? ''));
        if ($error !== '') {
            $this->sanmar->finish_storefront_image_request($request_id, 'failed', $error);
            ASSS_Logger::log('SanMar storefront image normalization failed','warning',['product_id'=>$product_id,'role'=>$role,'error'=>$error]);
            return rest_ensure_response(['ok'=>true,'accepted'=>true,'failed'=>true]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            $this->sanmar->finish_storefront_image_request($request_id, 'stale');
            return rest_ensure_response(['ok'=>true,'stale'=>true]);
        }

        $raw_ids = [];
        $current = (int)$product->get_image_id();
        if ($role === 'featured') {
            $wanted_source = (string)get_post_meta($product_id, '_asss_sanmar_normalize_source_url', true);
            if ($wanted_source !== $source_url) {
                $this->sanmar->finish_storefront_image_request($request_id, 'stale');
                return rest_ensure_response(['ok'=>true,'stale'=>true]);
            }
            if ($current) {
                $current_source = sanitize_key((string)get_post_meta($current, '_asss_image_source', true));
                if (!in_array($current_source, ['sanmar','sanmar-normalized'], true)) {
                    $this->sanmar->finish_storefront_image_request($request_id, 'protected');
                    return rest_ensure_response(['ok'=>true,'protected'=>true,'message'=>'Merchant/other-supplier featured image preserved.']);
                }
            }
        } else {
            $already = 0;
            foreach ((array)$product->get_gallery_image_ids() as $id) {
                $id = (int)$id;
                if (!$id) continue;
                $src = sanitize_key((string)get_post_meta($id,'_asss_image_source',true));
                $url = (string)get_post_meta($id,'_asss_image_url',true);
                $ver = sanitize_key((string)get_post_meta($id,'_asss_normalizer_version',true));
                if ($src === 'sanmar-normalized' && $url === $source_url && $ver === $version) $already = $id;
                if ($src === 'sanmar' && $url === $source_url) $raw_ids[] = $id;
            }
            if ($already) {
                $this->sanmar->finish_storefront_image_request($request_id, 'success');
                return rest_ensure_response(['ok'=>true,'product_id'=>$product_id,'attachment_id'=>$already,'assigned'=>true,'role'=>'gallery','reused'=>true]);
            }
            if (!$raw_ids) {
                $this->sanmar->finish_storefront_image_request($request_id, 'stale');
                return rest_ensure_response(['ok'=>true,'stale'=>true,'role'=>'gallery']);
            }
        }

        // Reuse a normalized attachment for this source/version when one already exists.
        $normalized_ids = get_posts([
            'post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>10,'no_found_rows'=>true,
            'meta_query'=>['relation'=>'AND',
                ['key'=>'_asss_image_url','value'=>$source_url],
                ['key'=>'_asss_image_source','value'=>'sanmar-normalized'],
                ['key'=>'_asss_normalizer_version','value'=>$version],
            ],
        ]);
        $attachment_id = !empty($normalized_ids[0]) ? (int)$normalized_ids[0] : 0;

        if (!$attachment_id) {
            $encoded = (string)($payload['image_base64'] ?? '');
            if ($encoded === '' || strlen($encoded) > 1800000) return new WP_Error('image_payload','Normalized image payload is empty or too large.',['status'=>413]);
            $bytes = base64_decode($encoded, true);
            if ($bytes === false || strlen($bytes) < 1000 || strlen($bytes) > 1000000) return new WP_Error('image_payload','Normalized image bytes are invalid.',['status'=>400]);
            $expected_sha = strtolower(preg_replace('/[^a-f0-9]/i','',(string)($payload['sha256'] ?? '')));
            if ($expected_sha !== '' && !hash_equals($expected_sha, hash('sha256',$bytes))) return new WP_Error('image_sha','Normalized image checksum mismatch.',['status'=>400]);

            $filename = sanitize_file_name((string)($payload['filename'] ?? ('sanmar-'.$product_id.'.jpg')));
            if (!str_ends_with(strtolower($filename), '.jpg') && !str_ends_with(strtolower($filename), '.jpeg')) $filename .= '.jpg';
            $upload = wp_upload_bits($filename, null, $bytes);
            if (!empty($upload['error'])) return new WP_Error('image_upload',(string)$upload['error'],['status'=>500]);

            require_once ABSPATH.'wp-admin/includes/image.php';
            $attachment_id = wp_insert_attachment([
                'post_mime_type'=>'image/jpeg','post_title'=>sanitize_text_field(pathinfo($filename,PATHINFO_FILENAME)),
                'post_content'=>'','post_status'=>'inherit',
            ], $upload['file'], $product_id, true);
            if (is_wp_error($attachment_id)) { @unlink($upload['file']); return $attachment_id; }
            $metadata = wp_generate_attachment_metadata((int)$attachment_id, $upload['file']);
            if (is_array($metadata)) wp_update_attachment_metadata((int)$attachment_id, $metadata);
            update_post_meta((int)$attachment_id,'_asss_image_source','sanmar-normalized');
            update_post_meta((int)$attachment_id,'_asss_image_url',$source_url);
            update_post_meta((int)$attachment_id,'_asss_normalizer_version',$version);
            update_post_meta((int)$attachment_id,'_asss_normalized_sha256',hash('sha256',$bytes));
            update_post_meta((int)$attachment_id,'_asss_normalizer_meta',wp_json_encode((array)($payload['meta'] ?? [])));
        }

        if ($role === 'featured') {
            if ($current && sanitize_key((string)get_post_meta($current,'_asss_image_source',true)) === 'sanmar' && (string)get_post_meta($current,'_asss_image_url',true) === $source_url) {
                update_post_meta($current,'_asss_raw_image_url',$source_url);
            }
            $product->set_image_id((int)$attachment_id);
            $product->save();
            update_post_meta($product_id,'_asss_sanmar_normalized_source_url',$source_url);
            update_post_meta($product_id,'_asss_sanmar_normalizer_version',$version);
            update_post_meta($product_id,'_asss_sanmar_normalized_attachment_id',(int)$attachment_id);
        } else {
            $raw_lookup = array_fill_keys(array_map('intval',$raw_ids), true);
            $gallery = [];
            foreach ((array)$product->get_gallery_image_ids() as $id) {
                $id = (int)$id;
                $gallery[] = isset($raw_lookup[$id]) ? (int)$attachment_id : $id;
            }
            $product->set_gallery_image_ids(array_values(array_unique(array_filter($gallery))));
            $product->save();
            foreach ($raw_ids as $raw_id) update_post_meta((int)$raw_id,'_asss_raw_image_url',$source_url);

            // If a variation happens to reuse the exact same attachment ID, replace
            // that reference too. This is ID-only and never touches unrelated/manual media.
            $variation_ids = get_posts(['post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,'post_parent'=>$product_id]);
            foreach ((array)$variation_ids as $variation_id) {
                $v = wc_get_product((int)$variation_id);
                if (!$v instanceof WC_Product_Variation) continue;
                $changed = false;
                if (isset($raw_lookup[(int)$v->get_image_id()])) { $v->set_image_id((int)$attachment_id); $changed = true; }
                if (method_exists($v,'get_gallery_image_ids') && method_exists($v,'set_gallery_image_ids')) {
                    $ids = [];
                    foreach ((array)$v->get_gallery_image_ids() as $id) $ids[] = isset($raw_lookup[(int)$id]) ? (int)$attachment_id : (int)$id;
                    $ids = array_values(array_unique(array_filter($ids)));
                    if ($ids !== array_values(array_unique(array_filter(array_map('intval',(array)$v->get_gallery_image_ids()))))) { $v->set_gallery_image_ids($ids); $changed = true; }
                }
                $stored = $v->get_meta('_asss_variation_gallery_ids');
                if (is_array($stored)) {
                    $ids=[]; foreach ($stored as $id) $ids[] = isset($raw_lookup[(int)$id]) ? (int)$attachment_id : (int)$id;
                    $v->update_meta_data('_asss_variation_gallery_ids', array_values(array_unique(array_filter($ids))));
                    $changed = true;
                }
                if ($changed) $v->save();
            }
            update_post_meta($product_id,'_asss_sanmar_gallery_normalized_at',current_time('mysql'));
        }

        $this->sanmar->finish_storefront_image_request($request_id, 'success');
        wc_delete_product_transients($product_id);
        ASSS_Logger::log('Applied GitHub-normalized SanMar storefront image','info',['product_id'=>$product_id,'attachment_id'=>(int)$attachment_id,'version'=>$version,'role'=>$role]);
        return rest_ensure_response(['ok'=>true,'product_id'=>$product_id,'attachment_id'=>(int)$attachment_id,'assigned'=>true,'role'=>$role]);
    }

'''
pattern = re.compile(r"    public function receive_sanmar_image_result\(WP_REST_Request \$request\) \{.*?\n    public function status\(WP_REST_Request \$request\)", re.S)
m = pattern.search(s)
assert m, 'bridge SanMar image result function not found'
s = s[:m.start()] + new_result + '    public function status(WP_REST_Request $request)' + s[m.end():]
bridge.write_text(s)

print('Applied v2.0.25 SanMar parent-gallery normalization patch')
