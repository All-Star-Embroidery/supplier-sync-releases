<?php
if (!defined('ABSPATH')) exit;

class ASSS_Bridge {
    private ASSS_SanMar $sanmar;
    private ASSS_SS $ss;
    private ASSS_Sync $sync;

    public function __construct(ASSS_SanMar $sanmar, ASSS_SS $ss, ASSS_Sync $sync) {
        $this->sanmar = $sanmar;
        $this->ss = $ss;
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
        ], 200);
    }
}
