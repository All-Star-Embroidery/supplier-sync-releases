#!/usr/bin/env python3
from pathlib import Path

root = Path('source')

# --- SanMar queue -----------------------------------------------------------
p = root / 'includes/class-asss-sanmar.php'
s = p.read_text()
insert = r'''

    /** v2.0.24: lightweight WordPress-side queue for GitHub image normalization. */
    private function storefront_image_queue(): array {
        $rows = get_option('asss_sanmar_storefront_image_queue_v1', []);
        return is_array($rows) ? $rows : [];
    }

    private function save_storefront_image_queue(array $rows): void {
        // Bound the queue so a broken remote image can never grow wp_options forever.
        if (count($rows) > 500) {
            uasort($rows, static fn($a,$b) => strcmp((string)($a['updated_at'] ?? ''), (string)($b['updated_at'] ?? '')));
            $rows = array_slice($rows, -500, null, true);
        }
        update_option('asss_sanmar_storefront_image_queue_v1', $rows, false);
    }

    public function queue_storefront_image_request(int $product_id, string $brand, string $style, string $source_url, string $normalizer_version='sanmar-square-v1'): string {
        $source_url = esc_url_raw(trim($source_url));
        $normalizer_version = sanitize_key($normalizer_version);
        if ($product_id < 1 || $source_url === '' || $normalizer_version === '') return '';
        $product = wc_get_product($product_id);
        if (!$product) return '';

        // Never spend GitHub work on a merchant-selected or another-supplier image.
        $current = (int)$product->get_image_id();
        if ($current) {
            $source = sanitize_key((string)get_post_meta($current, '_asss_image_source', true));
            if (!in_array($source, ['sanmar','sanmar-normalized'], true)) return '';
        }

        $done_source = (string)get_post_meta($product_id, '_asss_sanmar_normalized_source_url', true);
        $done_version = (string)get_post_meta($product_id, '_asss_sanmar_normalizer_version', true);
        $done_attachment = (int)get_post_meta($product_id, '_asss_sanmar_normalized_attachment_id', true);
        if ($done_source === $source_url && $done_version === $normalizer_version && $done_attachment > 0 && get_post($done_attachment)) {
            return '';
        }

        update_post_meta($product_id, '_asss_sanmar_normalize_source_url', $source_url);
        $request_id = substr(hash('sha256', $product_id.'|'.$source_url.'|'.$normalizer_version), 0, 32);
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
            'status'=>'pending',
            'attempts'=>(int)($existing['attempts'] ?? 0),
            'created_at'=>(string)($existing['created_at'] ?? $now),
            'updated_at'=>$now,
            'error'=>'',
        ];
        $this->save_storefront_image_queue($rows);
        return $request_id;
    }

    public function pending_storefront_image_requests(int $limit=6, string $normalizer_version='sanmar-square-v1'): array {
        $limit = max(1, min(12, $limit));
        $normalizer_version = sanitize_key($normalizer_version);
        $rows = $this->storefront_image_queue();
        $now = time();
        foreach ($rows as $id=>&$row) {
            if (!is_array($row)) { unset($rows[$id]); continue; }
            if (($row['status'] ?? '') === 'processing') {
                $updated = strtotime((string)($row['updated_at'] ?? '')) ?: 0;
                if ($updated && ($now - $updated) > 30 * MINUTE_IN_SECONDS) $row['status'] = 'pending';
            }
        }
        unset($row);
        uasort($rows, static fn($a,$b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
        $out = [];
        foreach ($rows as $id=>&$row) {
            if (count($out) >= $limit) break;
            if (($row['status'] ?? '') !== 'pending') continue;
            if ($normalizer_version !== '' && sanitize_key((string)($row['normalizer_version'] ?? '')) !== $normalizer_version) continue;
            $row['status'] = 'processing';
            $row['attempts'] = (int)($row['attempts'] ?? 0) + 1;
            $row['updated_at'] = gmdate('c');
            $out[] = $row;
        }
        unset($row);
        $this->save_storefront_image_queue($rows);
        return array_values($out);
    }

    public function storefront_image_request(string $request_id): array {
        $rows = $this->storefront_image_queue();
        $row = $rows[sanitize_text_field($request_id)] ?? [];
        return is_array($row) ? $row : [];
    }

    public function finish_storefront_image_request(string $request_id, string $status, string $error=''): void {
        $request_id = sanitize_text_field($request_id);
        $rows = $this->storefront_image_queue();
        if (!isset($rows[$request_id]) || !is_array($rows[$request_id])) return;
        if (in_array($status, ['success','stale','protected'], true)) {
            unset($rows[$request_id]);
        } else {
            $rows[$request_id]['status'] = 'failed';
            $rows[$request_id]['error'] = sanitize_text_field($error);
            $rows[$request_id]['updated_at'] = gmdate('c');
        }
        $this->save_storefront_image_queue($rows);
    }
'''
pos = s.rfind('\n}')
assert pos > 0
s = s[:pos] + insert + s[pos:]
p.write_text(s)

# --- Bridge endpoints -------------------------------------------------------
p = root / 'includes/class-asss-bridge.php'
s = p.read_text()
route_marker = "        register_rest_route('asss/v1', '/bridge/status', [\n"
routes = r'''        // v2.0.24: GitHub performs CPU-heavy SanMar storefront-image normalization.
        register_rest_route('asss/v1', '/bridge/media/sanmar/requests', [
            'methods'=>'GET','callback'=>[$this,'sanmar_image_requests'],'permission_callback'=>[$this,'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/media/sanmar/result', [
            'methods'=>'POST','callback'=>[$this,'receive_sanmar_image_result'],'permission_callback'=>[$this,'authorize'],
        ]);

'''
assert route_marker in s
s = s.replace(route_marker, routes + route_marker, 1)

method_marker = "    public function status(WP_REST_Request $request) {\n"
methods = r'''    public function sanmar_image_requests(WP_REST_Request $request) {
        $limit = absint($request->get_param('limit') ?: 6);
        $version = sanitize_key((string)($request->get_param('normalizer_version') ?: 'sanmar-square-v1'));
        $rows = $this->sanmar->pending_storefront_image_requests($limit, $version);
        return rest_ensure_response(['ok'=>true,'supplier'=>'sanmar','count'=>count($rows),'requests'=>$rows]);
    }

    public function receive_sanmar_image_result(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('invalid_json','Request body must be JSON.',['status'=>400]);
        $request_id = sanitize_text_field((string)($payload['request_id'] ?? ''));
        $queued = $this->sanmar->storefront_image_request($request_id);
        if ($request_id === '' || !$queued) return new WP_Error('image_request','Unknown or expired SanMar image request.',['status'=>404]);

        $product_id = absint($payload['product_id'] ?? 0);
        $source_url = esc_url_raw((string)($payload['source_url'] ?? ''));
        $version = sanitize_key((string)($payload['normalizer_version'] ?? ''));
        if ($product_id !== (int)($queued['product_id'] ?? 0) || $source_url !== (string)($queued['source_url'] ?? '') || $version !== sanitize_key((string)($queued['normalizer_version'] ?? ''))) {
            return new WP_Error('image_request_mismatch','SanMar image result does not match the queued request.',['status'=>409]);
        }

        $error = trim((string)($payload['error'] ?? ''));
        if ($error !== '') {
            $this->sanmar->finish_storefront_image_request($request_id, 'failed', $error);
            ASSS_Logger::log('SanMar storefront image normalization failed','warning',['product_id'=>$product_id,'error'=>$error]);
            return rest_ensure_response(['ok'=>true,'accepted'=>true,'failed'=>true]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            $this->sanmar->finish_storefront_image_request($request_id, 'stale');
            return rest_ensure_response(['ok'=>true,'stale'=>true]);
        }
        $wanted_source = (string)get_post_meta($product_id, '_asss_sanmar_normalize_source_url', true);
        if ($wanted_source !== $source_url) {
            $this->sanmar->finish_storefront_image_request($request_id, 'stale');
            return rest_ensure_response(['ok'=>true,'stale'=>true]);
        }

        $current = (int)$product->get_image_id();
        if ($current) {
            $current_source = sanitize_key((string)get_post_meta($current, '_asss_image_source', true));
            if (!in_array($current_source, ['sanmar','sanmar-normalized'], true)) {
                $this->sanmar->finish_storefront_image_request($request_id, 'protected');
                return rest_ensure_response(['ok'=>true,'protected'=>true,'message'=>'Merchant/other-supplier featured image preserved.']);
            }
        }

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

        // The normalized derivative becomes the canonical attachment for this
        // supplier URL. Keep the old raw attachment traceable but out of sideload lookup.
        if ($current && sanitize_key((string)get_post_meta($current,'_asss_image_source',true)) === 'sanmar') {
            if ((string)get_post_meta($current,'_asss_image_url',true) === $source_url) {
                update_post_meta($current,'_asss_raw_image_url',$source_url);
                delete_post_meta($current,'_asss_image_url');
            }
        }
        update_post_meta((int)$attachment_id,'_asss_image_source','sanmar-normalized');
        update_post_meta((int)$attachment_id,'_asss_image_url',$source_url);
        update_post_meta((int)$attachment_id,'_asss_normalizer_version',$version);
        update_post_meta((int)$attachment_id,'_asss_normalized_sha256',hash('sha256',$bytes));
        update_post_meta((int)$attachment_id,'_asss_normalizer_meta',wp_json_encode((array)($payload['meta'] ?? [])));

        $product->set_image_id((int)$attachment_id);
        $product->save();
        update_post_meta($product_id,'_asss_sanmar_normalized_source_url',$source_url);
        update_post_meta($product_id,'_asss_sanmar_normalizer_version',$version);
        update_post_meta($product_id,'_asss_sanmar_normalized_attachment_id',(int)$attachment_id);
        $this->sanmar->finish_storefront_image_request($request_id, 'success');
        wc_delete_product_transients($product_id);
        ASSS_Logger::log('Applied GitHub-normalized SanMar storefront image','info',['product_id'=>$product_id,'attachment_id'=>(int)$attachment_id,'version'=>$version]);
        return rest_ensure_response(['ok'=>true,'product_id'=>$product_id,'attachment_id'=>(int)$attachment_id,'assigned'=>true]);
    }

'''
assert method_marker in s
s = s.replace(method_marker, methods + method_marker, 1)
p.write_text(s)

# --- Importer queue triggers/migration --------------------------------------
p = root / 'includes/class-asss-importer.php'
s = p.read_text()
constructor_marker = "        add_action('admin_init', [$this, 'migrate_discovery_taxonomy_and_titles_v2023'], 40);\n"
assert constructor_marker in s
s = s.replace(constructor_marker, constructor_marker + "        add_action('admin_init', [$this, 'migrate_sanmar_storefront_images_v2024'], 41);\n", 1)

s = s.replace("['sanmar','ss','momentec','supplier']", "['sanmar','sanmar-normalized','ss','momentec','supplier']")

queue_marker = "        $gallery_urls = [];\n"
queue_code = r'''        // v2.0.24: queue the chosen SanMar storefront source for GitHub-side
        // normalization. Inventory-only syncs never enter this media path.
        if ($featured_url !== '') {
            $image_brand = trim((string)$this->sanmar->first($row, ['BRAND_NAME']));
            $image_style = trim((string)$this->sanmar->first($row, ['STYLE','STYLE#']));
            $this->sanmar->queue_storefront_image_request($product_id, $image_brand, $image_style, $featured_url, 'sanmar-square-v1');
        }

'''
# The first occurrence after sync_parent_media is the target; anchor from method.
method_pos = s.find("    private function sync_parent_media(int $product_id, array $row): void {")
assert method_pos >= 0
target_pos = s.find(queue_marker, method_pos)
assert target_pos >= 0
s = s[:target_pos] + queue_code + s[target_pos:]

migration_marker = "    private function sync_parent_media(int $product_id, array $row): void {\n"
migration = r'''    /** v2.0.24: queue existing SanMar-owned featured images once after upgrade. */
    public function migrate_sanmar_storefront_images_v2024(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2024_sanmar_storefront_images_migrated','') === 'yes') return;
        if (empty($this->sanmar->settings()['sync_images'])) { update_option('asss_v2024_sanmar_storefront_images_migrated','yes',false); return; }
        $ids = get_posts([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true,
            'meta_query'=>[['key'=>'_asss_sanmar_style','compare'=>'EXISTS']],
        ]);
        $queued = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            $product = wc_get_product($product_id);
            if (!$product) continue;
            $attachment_id = (int)$product->get_image_id();
            if ($attachment_id < 1) continue;
            $source = sanitize_key((string)get_post_meta($attachment_id,'_asss_image_source',true));
            if (!in_array($source,['sanmar','sanmar-normalized'],true)) continue;
            $url = trim((string)get_post_meta($attachment_id,'_asss_image_url',true));
            if ($url === '') $url = trim((string)get_post_meta($attachment_id,'_asss_raw_image_url',true));
            if ($url === '') continue;
            $brand = trim((string)get_post_meta($product_id,'_asss_sanmar_brand',true));
            $style = trim((string)get_post_meta($product_id,'_asss_sanmar_style',true));
            if ($this->sanmar->queue_storefront_image_request($product_id,$brand,$style,$url,'sanmar-square-v1') !== '') $queued++;
        }
        update_option('asss_v2024_sanmar_storefront_images_migrated','yes',false);
        ASSS_Logger::log('v2.0.24 queued existing SanMar storefront images for GitHub normalization','info',['products'=>count((array)$ids),'queued'=>$queued]);
    }

'''
assert migration_marker in s
s = s.replace(migration_marker, migration + migration_marker, 1)
p.write_text(s)

# --- Version/readme ----------------------------------------------------------
main = root / 'all-star-supplier-sync.php'
m = main.read_text().replace('Version: 2.0.23','Version: 2.0.24',1).replace("define('ASSS_VERSION', '2.0.23');","define('ASSS_VERSION', '2.0.24');",1)
main.write_text(m)

readme = root / 'readme.txt'
r = readme.read_text().replace('Stable tag: 2.0.23','Stable tag: 2.0.24',1)
anchor = '== Changelog ==\n\n'
entry = '''= 2.0.24 =\n* Adds GitHub-side SanMar storefront image normalization so expensive crop/re-centering work does not run on the WordPress server.\n* SanMar imports and Quick Repair queue featured images for a scheduled GitHub worker; inventory-only syncs do not process images.\n* Normalized square derivatives are cached by source URL + normalizer version, preserve merchant-selected images, and repair existing SanMar products once after upgrade.\n\n'''
assert anchor in r
r = r.replace(anchor, anchor + entry, 1)
readme.write_text(r)
