<?php
if (!defined('ABSPATH')) exit;

class ASSS_Bridge {
    private ASSS_SanMar $sanmar;
    private ASSS_SS $ss;
    private ASSS_Momentec $momentec;
    private ASSS_Sync $sync;

    public function __construct(ASSS_SanMar $sanmar, ASSS_SS $ss, ASSS_Momentec $momentec, ASSS_Sync $sync) {
        $this->sanmar = $sanmar;
        $this->ss = $ss;
        $this->momentec = $momentec;
        $this->sync = $sync;
        $settings = $this->sanmar->settings();
        if (empty($settings['bridge_token'])) {
            $settings['bridge_token'] = wp_generate_password(48, false, false);
            $settings['bridge_enabled'] = 1;
            update_option('asss_settings', $settings, false);
        }
        add_action('rest_api_init', [$this, 'routes']);
        add_action('asss_bridge_product_sync', [$this->sync, 'queue_brand_product_sync']);
    }

    public function routes(): void {
        // Register the static /brands endpoint BEFORE the dynamic brand route.
        // Otherwise WordPress can treat the literal word "brands" as a brand
        // slug and send discovery payloads to receive_sanmar_brand().
        register_rest_route('asss/v1', '/bridge/sanmar/brands', [
            'methods' => ['GET','POST'],
            'callback' => [$this, 'brand_catalog'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route('asss/v1', '/bridge/ss/brands', [
            'methods' => ['GET','POST'],
            'callback' => [$this, 'ss_brand_catalog'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route('asss/v1', '/bridge/ss/catalog/(?P<brand_id>\d+)/batch', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_ss_catalog_batch'],
            'permission_callback' => [$this, 'authorize'],
            'args' => [
                'brand_id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('asss/v1', '/bridge/sanmar/(?P<brand>(?!brands$)[A-Za-z0-9+&_.-]+)/batch', [
            'methods'=>'POST','callback'=>[$this,'receive_sanmar_brand_batch'],
            'permission_callback'=>[$this,'authorize'],
            'args'=>['brand'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field']],
        ]);

        register_rest_route('asss/v1', '/bridge/sanmar/(?P<brand>(?!brands$)[A-Za-z0-9+&_.-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_sanmar_brand'],
            'permission_callback' => [$this, 'authorize'],
            'args' => [
                'brand' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // GitHub asks WordPress which supplier variations are actually sold on
        // this store. This lets the runner discard the rest of SanMar's very
        // large inventory feed before sending anything back to WordPress.
        register_rest_route('asss/v1', '/bridge/inventory/sanmar/targets', [
            'methods' => 'GET',
            'callback' => [$this, 'inventory_targets'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        // Receives a small, normalized inventory payload containing only the
        // supplier variations linked to this WooCommerce store.
        register_rest_route('asss/v1', '/bridge/inventory/sanmar', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_sanmar_inventory'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        // S&S inventory is pulled from the lightweight REST Inventory endpoint.
        // GitHub first asks for only the exact active WooCommerce S&S variations,
        // then requests those supplier SKUs and sends back a complete matched set.
        register_rest_route('asss/v1', '/bridge/inventory/ss/targets', [
            'methods' => 'GET',
            'callback' => [$this, 'ss_inventory_targets'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route('asss/v1', '/bridge/inventory/ss', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_ss_inventory'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route('asss/v1', '/bridge/momentec/catalog/batch', [
            'methods' => 'POST', 'callback' => [$this, 'receive_momentec_catalog_batch'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/momentec/requests', [
            'methods' => 'GET', 'callback' => [$this, 'momentec_requests'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/momentec/request-status', [
            'methods' => 'POST', 'callback' => [$this, 'momentec_request_status'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/momentec/style', [
            'methods' => 'POST', 'callback' => [$this, 'receive_momentec_style'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/momentec/product-targets', [
            'methods' => 'GET', 'callback' => [$this, 'momentec_product_targets'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/inventory/momentec/targets', [
            'methods' => 'GET', 'callback' => [$this, 'momentec_inventory_targets'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/inventory/momentec', [
            'methods' => 'POST', 'callback' => [$this, 'receive_momentec_inventory'], 'permission_callback' => [$this, 'authorize'],
        ]);

        // v2.0.24: GitHub performs CPU-heavy SanMar storefront-image normalization.
        register_rest_route('asss/v1', '/bridge/media/sanmar/requests', [
            'methods'=>'GET','callback'=>[$this,'sanmar_image_requests'],'permission_callback'=>[$this,'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/media/sanmar/result', [
            'methods'=>'POST','callback'=>[$this,'receive_sanmar_image_result'],'permission_callback'=>[$this,'authorize'],
        ]);

        register_rest_route('asss/v1', '/bridge/status', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(WP_REST_Request $request): bool {
        $settings = $this->sanmar->settings();
        if (empty($settings['bridge_enabled']) || empty($settings['bridge_token'])) return false;
        $provided = (string)$request->get_header('x-asss-token');
        return $provided !== '' && hash_equals((string)$settings['bridge_token'], $provided);
    }

    public function receive_sanmar_brand_batch(WP_REST_Request $request) {
        $brand=sanitize_text_field((string)$request['brand']);
        $payload=$request->get_json_params();
        if(!is_array($payload)) return new WP_Error('invalid_json','Request body must be JSON.',['status'=>400]);
        $products=isset($payload['products']) && is_array($payload['products']) ? $payload['products'] : [];
        $batch_id=sanitize_text_field((string)($payload['batch_id'] ?? ''));
        $index=(int)($payload['chunk_index'] ?? -1);
        $count=absint($payload['chunk_count'] ?? 0);
        if(!$products) return new WP_Error('empty_payload','No normalized products were supplied.',['status'=>400]);

        $clean=[];
        foreach($products as $product) {
            if(!is_array($product)) continue;
            $style=sanitize_text_field((string)($product['style'] ?? ''));
            if($style==='') continue;
            $supplier=strtolower(sanitize_text_field((string)($product['supplier'] ?? 'sanmar')));
            if($supplier!=='sanmar') continue;
            $product['style']=$style; $product['supplier']='sanmar';
            if(empty($product['brand'])) $product['brand']=$brand;
            $clean[]=$product;
        }
        if(!$clean) return new WP_Error('no_products','No valid SanMar products were found in this chunk.',['status'=>400]);
        $result=$this->sanmar->save_bridge_brand_chunk($brand,$batch_id,$index,$count,$clean,[
            'source_file'=>sanitize_text_field((string)($payload['source_file'] ?? $request->get_param('source_file'))),
        ]);
        if(is_wp_error($result)) return $result;
        if(!empty($result['complete'])) {
            wp_schedule_single_event(time()+10,'asss_bridge_product_sync',[$brand]);
            spawn_cron();
            ASSS_Logger::log('GitHub bridge completed batched SanMar brand data','info',[
                'brand'=>$brand,'styles'=>(int)$result['styles'],'variants'=>(int)$result['variants'],'chunks'=>$count,
            ]);
        }
        return new WP_REST_Response(array_merge(['ok'=>true,'brand'=>$brand],$result),!empty($result['complete'])?202:200);
    }

    public function receive_sanmar_brand(WP_REST_Request $request) {
        $brand = sanitize_text_field((string)$request['brand']);
        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('invalid_json', 'Request body must be JSON.', ['status'=>400]);

        // The GitHub normalizer currently sends a top-level array of products.
        $products = isset($payload['products']) && is_array($payload['products']) ? $payload['products'] : $payload;
        if (!is_array($products) || !$products) return new WP_Error('empty_payload', 'No normalized products were supplied.', ['status'=>400]);

        $clean = [];
        $variant_count = 0;
        foreach ($products as $product) {
            if (!is_array($product)) continue;
            $style = sanitize_text_field((string)($product['style'] ?? ''));
            if ($style === '') continue;
            $supplier = strtolower(sanitize_text_field((string)($product['supplier'] ?? 'sanmar')));
            if ($supplier !== 'sanmar') continue;
            $product['style'] = $style;
            $product['supplier'] = 'sanmar';
            if (empty($product['brand'])) $product['brand'] = $brand;
            if (!empty($product['variants']) && is_array($product['variants'])) $variant_count += count($product['variants']);
            $clean[] = $product;
        }
        if (!$clean) return new WP_Error('no_products', 'No valid SanMar products were found in the payload.', ['status'=>400]);

        $saved = $this->sanmar->save_bridge_brand($brand, $clean, [
            'source' => 'github-actions',
            'source_file' => sanitize_text_field((string)$request->get_param('source_file')),
            'received_at' => current_time('mysql'),
            'style_count' => count($clean),
            'variant_count' => $variant_count,
        ]);
        if (is_wp_error($saved)) return $saved;

        // Product updates happen after the REST request finishes so GitHub is not
        // forced to wait for image downloads and WooCommerce variation updates.
        wp_schedule_single_event(time() + 10, 'asss_bridge_product_sync', [$brand]);
        spawn_cron();

        ASSS_Logger::log('GitHub bridge received SanMar brand data', 'info', [
            'brand'=>$brand, 'styles'=>count($clean), 'variants'=>$variant_count,
        ]);

        return new WP_REST_Response([
            'ok'=>true,
            'brand'=>$brand,
            'styles'=>count($clean),
            'variants'=>$variant_count,
            'message'=>'Supplier data accepted. Linked WooCommerce products for this brand are queued for sync.',
        ], 202);
    }


    public function brand_catalog(WP_REST_Request $request) {
        if ($request->get_method() === 'POST') {
            $payload=$request->get_json_params();
            $brands=is_array($payload) && isset($payload['brands']) ? $payload['brands'] : [];
            if (!is_array($brands) || !$brands) return new WP_Error('empty_brands','No discovered SanMar brands were supplied.',['status'=>400]);
            $catalog=$this->sanmar->save_brand_catalog($brands);
            ASSS_Logger::log('SanMar brand discovery updated','info',['brands'=>count($catalog)]);
        } else {
            $catalog=$this->sanmar->brand_catalog();
        }
        $manifest=$this->sanmar->bridge_manifest();
        $rows=[];
        foreach ($catalog as $brand=>$meta) {
            $received=''; $storage='';
            foreach ($manifest as $mb=>$mm) if (strcasecmp((string)$mb,(string)$brand)===0) {
                $received=(string)($mm['source_file'] ?? '');
                $storage=(string)($mm['storage'] ?? 'legacy-full-cache');
                break;
            }
            $rows[]=[
                'brand'=>(string)$brand,
                'enabled'=>!empty($meta['enabled']),
                'latest_file'=>(string)($meta['latest_file'] ?? ''),
                'latest_date'=>(string)($meta['latest_date'] ?? ''),
                'received_source_file'=>$received,
                'received_storage'=>$storage,
            ];
        }
        $settings=$this->sanmar->settings();
        return new WP_REST_Response(['ok'=>true,'automatic_enabled'=>!empty($settings['automatic_product_bridge']),'count'=>count($rows),'brands'=>$rows],200);
    }

    public function ss_brand_catalog(WP_REST_Request $request) {
        if ($request->get_method() === 'POST') {
            $payload = $request->get_json_params();
            $brands = is_array($payload) && isset($payload['brands']) ? $payload['brands'] : [];
            if (!is_array($brands) || !$brands) {
                return new WP_Error('empty_ss_brands', 'No discovered S&S brands were supplied.', ['status'=>400]);
            }
            $catalog = $this->ss->save_brand_catalog($brands);
            ASSS_Logger::log('S&S brand discovery updated', 'info', ['brands'=>count($catalog)]);
        } else {
            $catalog = $this->ss->brand_catalog();
        }

        $rows = [];
        foreach ($catalog as $id => $meta) {
            $rows[] = [
                'brand_id' => (int)($meta['brand_id'] ?? $id),
                'brand' => (string)($meta['brand'] ?? ''),
                'enabled' => !empty($meta['enabled']),
                'image' => (string)($meta['image'] ?? ''),
                'e_retailing_restricted' => !empty($meta['e_retailing_restricted']),
            ];
        }
        return new WP_REST_Response([
            'ok' => true,
            'supplier' => 'ss',
            'count' => count($rows),
            'brands' => $rows,
        ], 200);
    }

    public function receive_ss_catalog_batch(WP_REST_Request $request) {
        $brand_id = absint($request['brand_id']);
        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('invalid_json', 'Request body must be JSON.', ['status'=>400]);

        $brand = sanitize_text_field((string)($payload['brand'] ?? ''));
        $batch_id = sanitize_text_field((string)($payload['batch_id'] ?? ''));
        $chunk_index = (int)($payload['chunk_index'] ?? -1);
        $chunk_count = absint($payload['chunk_count'] ?? 0);
        $products = isset($payload['products']) && is_array($payload['products']) ? $payload['products'] : [];
        if ($brand_id < 1 || $brand === '') return new WP_Error('ss_brand', 'A valid S&S brand is required.', ['status'=>400]);
        if (!$products) return new WP_Error('empty_ss_catalog', 'No normalized S&S products were supplied.', ['status'=>400]);

        $known = $this->ss->brand_meta($brand_id);
        if (!$known) return new WP_Error('unknown_ss_brand', 'This S&S brand has not been discovered in WordPress yet.', ['status'=>400]);
        if (empty($known['enabled'])) return new WP_Error('ss_brand_disabled', 'This S&S brand is not enabled in Supplier Sync.', ['status'=>409]);
        if (strcasecmp((string)($known['brand'] ?? ''), $brand) !== 0) {
            return new WP_Error('ss_brand_mismatch', 'S&S brand ID/name mismatch. Refusing ambiguous catalog data.', ['status'=>409]);
        }

        $result = $this->ss->save_style_catalog_chunk(
            $brand_id,
            $brand,
            $batch_id,
            $chunk_index,
            $chunk_count,
            $products,
            ['source'=>'github-actions']
        );
        if (is_wp_error($result)) return $result;

        if (!empty($result['complete'])) {
            ASSS_Logger::log('S&S enabled-brand product catalog published', 'info', [
                'brand_id'=>$brand_id,
                'brand'=>$brand,
                'styles'=>(int)($result['styles'] ?? 0),
                'variants'=>(int)($result['variants'] ?? 0),
                'chunks'=>(int)($result['chunk_count'] ?? 0),
            ]);
            // Refresh only WooCommerce products already linked to this S&S brand.
            // Catalog browsing remains lightweight; importing every supplier style is never automatic.
            $queued = $this->sync->queue_ss_brand_product_sync($brand_id);
            $result['linked_repairs_queued'] = (int)($queued['queued'] ?? 0);
            $result['linked_repairs_skipped'] = (int)($queued['skipped'] ?? 0);
        }

        return new WP_REST_Response(array_merge([
            'ok'=>true,
            'supplier'=>'ss',
            'brand_id'=>$brand_id,
            'brand'=>$brand,
        ], $result), !empty($result['complete']) ? 202 : 200);
    }

    public function inventory_targets(WP_REST_Request $request) {
        $settings = $this->sanmar->settings();
        if (empty($settings['bridge_inventory_enabled'])) {
            return new WP_Error('inventory_bridge_disabled', 'GitHub inventory updates are disabled in Supplier Settings.', ['status'=>403]);
        }
        $targets = $this->sync->inventory_targets();
        return new WP_REST_Response([
            'ok' => true,
            'supplier' => 'sanmar',
            'count' => count($targets),
            'targets' => $targets,
        ], 200);
    }

    public function receive_sanmar_inventory(WP_REST_Request $request) {
        $settings = $this->sanmar->settings();
        if (empty($settings['bridge_inventory_enabled'])) {
            return new WP_Error('inventory_bridge_disabled', 'GitHub inventory updates are disabled in Supplier Settings.', ['status'=>403]);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('invalid_json', 'Request body must be JSON.', ['status'=>400]);
        $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : [];
        if (!$rows) return new WP_Error('empty_inventory', 'No normalized inventory rows were supplied.', ['status'=>400]);

        // Keep the endpoint intentionally bounded. A curated store should never
        // need tens of thousands of inventory rows in one request.
        if (count($rows) > 10000) {
            return new WP_Error('inventory_too_large', 'Inventory payload is unexpectedly large. GitHub should send only linked store variations.', ['status'=>413]);
        }

        $result = $this->sync->apply_inventory_payload($rows, [
            'source' => sanitize_text_field((string)($payload['source'] ?? 'github-actions')),
            'source_file' => sanitize_text_field((string)($payload['source_file'] ?? '')),
            'source_timestamp' => sanitize_text_field((string)($payload['source_timestamp'] ?? '')),
            'headers' => array_values(array_map('sanitize_text_field', (array)($payload['headers'] ?? []))),
            'raw_rows_scanned' => absint($payload['raw_rows_scanned'] ?? 0),
        ]);

        if (is_wp_error($result)) return $result;
        return new WP_REST_Response(array_merge(['ok'=>true], $result), 200);
    }

    public function ss_inventory_targets(WP_REST_Request $request) {
        $settings = $this->sanmar->settings();
        if (empty($settings['bridge_inventory_enabled'])) {
            return new WP_Error('inventory_bridge_disabled', 'GitHub inventory updates are disabled in Supplier Settings.', ['status'=>403]);
        }
        $targets = $this->sync->inventory_targets_ss();
        return new WP_REST_Response([
            'ok'=>true,
            'supplier'=>'ss',
            'count'=>count($targets),
            'targets'=>$targets,
        ], 200);
    }

    public function receive_ss_inventory(WP_REST_Request $request) {
        $settings = $this->sanmar->settings();
        if (empty($settings['bridge_inventory_enabled'])) {
            return new WP_Error('inventory_bridge_disabled', 'GitHub inventory updates are disabled in Supplier Settings.', ['status'=>403]);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('invalid_json', 'Request body must be JSON.', ['status'=>400]);
        if (sanitize_key((string)($payload['supplier'] ?? '')) !== 'ss') {
            return new WP_Error('wrong_supplier', 'This endpoint accepts only S&S inventory payloads.', ['status'=>400]);
        }
        $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : [];
        if (!$rows) return new WP_Error('empty_inventory', 'No normalized S&S inventory rows were supplied.', ['status'=>400]);
        if (count($rows) > 10000) {
            return new WP_Error('inventory_too_large', 'S&S inventory payload is unexpectedly large. GitHub should send only linked store variations.', ['status'=>413]);
        }

        $result = $this->sync->apply_ss_inventory_payload($rows, [
            'source'=>sanitize_text_field((string)($payload['source'] ?? 'ss-inventory-api')),
            'source_timestamp'=>sanitize_text_field((string)($payload['source_timestamp'] ?? '')),
            'target_count'=>absint($payload['target_count'] ?? 0),
            'api_requests'=>absint($payload['api_requests'] ?? 0),
            'rate_limit_remaining'=>isset($payload['rate_limit_remaining']) && is_numeric($payload['rate_limit_remaining']) ? (int)$payload['rate_limit_remaining'] : null,
        ]);
        if (is_wp_error($result)) return $result;
        return new WP_REST_Response(array_merge(['ok'=>true,'supplier'=>'ss'], $result), 200);
    }

    public function sanmar_image_requests(WP_REST_Request $request) {
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

    public function status(WP_REST_Request $request) {
        return new WP_REST_Response([
            'ok'=>true,
            'version'=>defined('ASSS_VERSION') ? ASSS_VERSION : '',
            'brands'=>$this->sanmar->bridge_manifest(),
            'ss_brands'=>[
                'discovered'=>count($this->ss->brand_catalog()),
                'enabled'=>count($this->ss->enabled_brand_catalog()),
            ],
            'inventory'=>get_option('asss_inventory_bridge_status', []),
            'ss_inventory'=>get_option('asss_ss_inventory_bridge_status', []),
            'momentec'=>$this->momentec->status(),
        ], 200);
    }

    public function receive_momentec_catalog_batch(WP_REST_Request $request) {
        $payload=$request->get_json_params();
        if(!is_array($payload))return new WP_Error('momentec_catalog_payload','Momentec catalog payload must be JSON.',['status'=>400]);
        $result=$this->momentec->save_catalog_chunk(
            sanitize_text_field((string)($payload['batch_id'] ?? '')),
            (int)($payload['chunk_index'] ?? -1),
            absint($payload['chunk_count'] ?? 0),
            is_array($payload['products'] ?? null)?$payload['products']:[],
            is_array($payload['meta'] ?? null)?$payload['meta']:[]
        );
        if(is_wp_error($result))return $result;
        if(!empty($result['complete']))ASSS_Logger::log('Momentec official browse catalog updated','info',['styles'=>(int)($result['styles'] ?? 0),'source_rows'=>(int)($result['source_rows'] ?? 0)]);
        return rest_ensure_response(array_merge(['ok'=>true,'supplier'=>'momentec'],$result));
    }

    public function momentec_requests(WP_REST_Request $request) {
        $limit=absint($request->get_param('limit') ?: 3);
        $rows=$this->momentec->pending_requests($limit);
        return rest_ensure_response(['ok'=>true,'supplier'=>'momentec','count'=>count($rows),'requests'=>$rows]);
    }

    public function momentec_request_status(WP_REST_Request $request) {
        $payload=$request->get_json_params();
        if(!is_array($payload))return new WP_Error('momentec_request_payload','Momentec request status payload must be JSON.',['status'=>400]);
        $result=$this->momentec->set_request_status(
            sanitize_text_field((string)($payload['request_id'] ?? '')),
            sanitize_text_field((string)($payload['style'] ?? '')),
            sanitize_key((string)($payload['status'] ?? '')),
            sanitize_text_field((string)($payload['message'] ?? ''))
        );
        if(is_wp_error($result))return $result;
        return rest_ensure_response(['ok'=>true,'supplier'=>'momentec','style'=>(string)($result['style'] ?? ''),'status'=>(string)($result['status'] ?? '')]);
    }

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

}
