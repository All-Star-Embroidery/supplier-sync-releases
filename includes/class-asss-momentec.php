<?php
if (!defined('ABSPATH')) exit;

/**
 * Momentec production supplier cache and catalog request queue.
 *
 * Security boundary: WordPress <-> GitHub Actions <-> Momentec production.
 * WordPress never stores or receives Momentec login credentials. The official
 * Momentec product-data CSV supplies browseable catalog discovery; GitHub uses
 * authenticated production v2 Style only after an administrator requests a
 * style, then sends normalized supplier data back through the ASSS bridge.
 */
class ASSS_Momentec {
    public const KEY = 'momentec';
    public const LABEL = 'Momentec Brands';
    private const CATALOG_META_OPTION = 'asss_momentec_catalog_meta';
    private const STYLE_MANIFEST_OPTION = 'asss_momentec_style_manifest';
    private const REQUEST_OPTION = 'asss_momentec_requests';

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

    private function catalog_path(): string {
        return trailingslashit($this->cache_root()) . 'catalog.json';
    }

    private function catalog_staging_dir(string $batch_id): string {
        return trailingslashit($this->cache_root()) . 'catalog-staging/' . sanitize_file_name($batch_id);
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

    private function clean_text_list($values, int $limit = 250): array {
        $out = [];
        foreach ((array)$values as $value) {
            $value = trim(sanitize_text_field((string)$value));
            if ($value === '') continue;
            $out[mb_strtolower($value)] = $value;
            if (count($out) >= $limit) break;
        }
        natcasesort($out);
        return array_values($out);
    }

    private function sanitize_catalog_summary(array $row): array {
        $style = trim(sanitize_text_field((string)($row['style'] ?? $row['parent_sku'] ?? '')));
        if ($style === '') return [];
        $colors = $this->clean_text_list($row['colors'] ?? []);
        $sizes = $this->clean_text_list($row['sizes'] ?? []);
        $categories = $this->clean_text_list($row['categories'] ?? []);
        $statuses = $this->clean_text_list($row['statuses'] ?? []);
        $countries = $this->clean_text_list($row['countries_of_origin'] ?? []);
        $exact = absint($row['exact_variation_count'] ?? $row['variant_count'] ?? 0);
        $theoretical = absint($row['theoretical_cartesian_count'] ?? (count($colors) * count($sizes)));
        return [
            'style'=>$style,
            'title'=>sanitize_text_field((string)($row['title'] ?? $style)),
            'brand'=>sanitize_text_field((string)($row['brand'] ?? '')),
            'division'=>sanitize_text_field((string)($row['division'] ?? '')),
            'category'=>sanitize_text_field((string)($row['category'] ?? ($categories[0] ?? ''))),
            'categories'=>$categories,
            'description'=>sanitize_textarea_field((string)($row['description'] ?? '')),
            'msrp'=>sanitize_text_field((string)($row['msrp'] ?? '')),
            'currency'=>sanitize_text_field((string)($row['currency'] ?? '')),
            'launch_date'=>sanitize_text_field((string)($row['launch_date'] ?? '')),
            'image'=>esc_url_raw((string)($row['image'] ?? '')),
            'swatch_image'=>esc_url_raw((string)($row['swatch_image'] ?? '')),
            'size_chart_image'=>esc_url_raw((string)($row['size_chart_image'] ?? '')),
            'product_video_url'=>esc_url_raw((string)($row['product_video_url'] ?? '')),
            'variation_theme'=>sanitize_text_field((string)($row['variation_theme'] ?? '')),
            'ribbon'=>sanitize_text_field((string)($row['ribbon'] ?? '')),
            'status'=>sanitize_text_field((string)($row['status'] ?? ($statuses[0] ?? ''))),
            'statuses'=>$statuses,
            'countries_of_origin'=>$countries,
            'colors'=>$colors,
            'sizes'=>$sizes,
            'color_count'=>count($colors),
            'size_count'=>count($sizes),
            'exact_variation_count'=>$exact,
            'theoretical_cartesian_count'=>$theoretical,
            'sparse_missing'=>max(0, $theoretical - $exact),
        ];
    }

    /** Receive one atomic chunk of the official Momentec browse catalog. */
    public function save_catalog_chunk(string $batch_id, int $index, int $count, array $products, array $meta = []) {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '' || $index < 0 || $count < 1 || $index >= $count || $count > 100) {
            return new WP_Error('momentec_catalog_batch', 'Invalid Momentec catalog batch metadata.', ['status'=>400]);
        }
        if (!$products || count($products) > 500) {
            return new WP_Error('momentec_catalog_chunk', 'Momentec catalog chunk is empty or unexpectedly large.', ['status'=>400]);
        }
        $clean = [];
        foreach ($products as $row) {
            if (!is_array($row)) continue;
            $summary = $this->sanitize_catalog_summary($row);
            if ($summary) $clean[] = $summary;
        }
        if (!$clean) return new WP_Error('momentec_catalog_rows', 'Momentec catalog chunk contains no valid styles.', ['status'=>400]);

        $dir = $this->catalog_staging_dir($batch_id);
        $ok = $this->ensure_dir($dir);
        if (is_wp_error($ok)) return $ok;
        $write = $this->write_json_atomic($dir . '/chunk-' . $index . '.json', [
            'batch_id'=>$batch_id,'chunk_index'=>$index,'chunk_count'=>$count,'products'=>$clean,'meta'=>$meta,
        ]);
        if (is_wp_error($write)) return $write;

        $all_present = true;
        for ($i=0; $i<$count; $i++) if (!is_file($dir . '/chunk-' . $i . '.json')) { $all_present = false; break; }
        if (!$all_present) return ['complete'=>false,'chunk_index'=>$index,'chunk_count'=>$count,'received'=>count($clean)];

        $catalog = [];
        $final_meta = [];
        for ($i=0; $i<$count; $i++) {
            $decoded = json_decode((string)@file_get_contents($dir . '/chunk-' . $i . '.json'), true);
            if (!is_array($decoded) || !is_array($decoded['products'] ?? null)) {
                return new WP_Error('momentec_catalog_stage', 'A staged Momentec catalog chunk is invalid.', ['status'=>500]);
            }
            if (!$final_meta && is_array($decoded['meta'] ?? null)) $final_meta = $decoded['meta'];
            foreach ($decoded['products'] as $row) {
                if (!is_array($row) || empty($row['style'])) continue;
                $catalog[$this->style_key((string)$row['style'])] = $row;
            }
        }
        uasort($catalog, static function($a,$b){
            $brand = strnatcasecmp((string)($a['brand'] ?? ''), (string)($b['brand'] ?? ''));
            return $brand !== 0 ? $brand : strnatcasecmp((string)($a['style'] ?? ''), (string)($b['style'] ?? ''));
        });
        $published_at = current_time('mysql');
        $payload = ['meta'=>[
            'batch_id'=>$batch_id,
            'received_at'=>$published_at,
            'source'=>sanitize_text_field((string)($final_meta['source'] ?? 'momentec-official-product-feed')),
            'source_url'=>esc_url_raw((string)($final_meta['source_url'] ?? '')),
            'source_timestamp'=>sanitize_text_field((string)($final_meta['source_timestamp'] ?? '')),
            'source_rows'=>absint($final_meta['source_rows'] ?? 0),
            'feed_bytes'=>absint($final_meta['feed_bytes'] ?? 0),
            'style_count'=>count($catalog),
        ], 'products'=>array_values($catalog)];
        $write = $this->write_json_atomic($this->catalog_path(), $payload);
        if (is_wp_error($write)) return $write;
        update_option(self::CATALOG_META_OPTION, $payload['meta'], false);

        foreach (glob($dir . '/*.json') ?: [] as $file) @unlink($file);
        @rmdir($dir);
        return ['complete'=>true,'styles'=>count($catalog),'source_rows'=>(int)$payload['meta']['source_rows'],'received_at'=>$published_at];
    }

    public function catalog_data(): array {
        $path = $this->catalog_path();
        if (!is_file($path)) return [];
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function catalog_meta(): array {
        $meta = get_option(self::CATALOG_META_OPTION, []);
        return is_array($meta) ? $meta : [];
    }

    public function catalog_count(): int {
        $data = $this->catalog_data();
        return is_array($data['products'] ?? null) ? count($data['products']) : 0;
    }

    public function catalog_facets(): array {
        $brands=[]; $categories=[];
        foreach ((array)($this->catalog_data()['products'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $brand=trim((string)($row['brand'] ?? '')); if($brand!=='')$brands[mb_strtolower($brand)]=$brand;
            foreach ((array)($row['categories'] ?? []) as $cat) { $cat=trim((string)$cat); if($cat!=='')$categories[mb_strtolower($cat)]=$cat; }
        }
        natcasesort($brands); natcasesort($categories);
        return ['brands'=>array_values($brands),'categories'=>array_values($categories)];
    }

    public function request_manifest(): array {
        $requests = get_option(self::REQUEST_OPTION, []);
        return is_array($requests) ? $requests : [];
    }

    public function request_for_style(string $style): array {
        $requests = $this->request_manifest();
        $row = $requests[$this->style_key($style)] ?? [];
        return is_array($row) ? $row : [];
    }

    public function queue_style_requests(array $styles, int $user_id = 0, bool $force = true): array {
        $catalog = [];
        foreach ((array)($this->catalog_data()['products'] ?? []) as $row) if (is_array($row) && !empty($row['style'])) $catalog[$this->style_key((string)$row['style'])] = true;
        foreach ($this->style_manifest() as $key=>$row) $catalog[(string)$key] = true;
        $requests = $this->request_manifest();
        $queued=0; $skipped=0; $unknown=[];
        foreach ($styles as $style) {
            $style=trim(sanitize_text_field((string)$style)); if($style==='')continue;
            $key=$this->style_key($style);
            if (!isset($catalog[$key])) { $unknown[]=$style; continue; }
            $existing = is_array($requests[$key] ?? null) ? $requests[$key] : [];
            if (!$force && in_array((string)($existing['status'] ?? ''), ['pending','processing'], true)) { $skipped++; continue; }
            $requests[$key]=[
                'request_id'=>wp_generate_uuid4(),
                'style'=>$style,
                'status'=>'pending',
                'requested_at'=>current_time('mysql'),
                'updated_at'=>current_time('mysql'),
                'requested_by'=>$user_id,
                'attempts'=>(int)($existing['attempts'] ?? 0),
                'message'=>'',
            ];
            $queued++;
        }
        update_option(self::REQUEST_OPTION, $requests, false);
        return ['queued'=>$queued,'skipped'=>$skipped,'unknown'=>$unknown];
    }

    public function pending_requests(int $limit = 3): array {
        $limit=max(1,min(10,$limit));
        $requests=$this->request_manifest();
        $changed=false; $out=[]; $now=time();
        foreach ($requests as $key=>$row) {
            if (!is_array($row)) continue;
            $status=(string)($row['status'] ?? '');
            if ($status==='processing') {
                $updated=strtotime((string)($row['updated_at'] ?? '')) ?: 0;
                if ($updated && ($now-$updated)>1800) { $row['status']='pending'; $row['message']='Recovered stale processing request.'; $requests[$key]=$row; $status='pending'; $changed=true; }
            }
            if ($status!=='pending') continue;
            $out[]=['request_id'=>(string)($row['request_id'] ?? ''),'style'=>(string)($row['style'] ?? '')];
            if(count($out)>=$limit)break;
        }
        if($changed)update_option(self::REQUEST_OPTION,$requests,false);
        return $out;
    }

    public function set_request_status(string $request_id, string $style, string $status, string $message = '') {
        $style=trim(sanitize_text_field($style)); $request_id=sanitize_text_field($request_id); $status=sanitize_key($status);
        if(!in_array($status,['pending','processing','failed','complete'],true))return new WP_Error('momentec_request_status','Invalid Momentec request status.',['status'=>400]);
        $key=$this->style_key($style); $requests=$this->request_manifest(); $row=$requests[$key] ?? null;
        if(!is_array($row) || !hash_equals((string)($row['request_id'] ?? ''),$request_id))return new WP_Error('momentec_request_id','Momentec request ID does not match the queued style.',['status'=>409]);
        $row['status']=$status; $row['updated_at']=current_time('mysql'); $row['message']=sanitize_text_field(substr($message,0,300));
        if($status==='processing')$row['attempts']=(int)($row['attempts'] ?? 0)+1;
        $requests[$key]=$row; update_option(self::REQUEST_OPTION,$requests,false);
        return $row;
    }

    public function mark_style_request_complete(string $style): void {
        $key=$this->style_key($style); $requests=$this->request_manifest();
        if(!is_array($requests[$key] ?? null))return;
        $requests[$key]['status']='complete'; $requests[$key]['updated_at']=current_time('mysql'); $requests[$key]['message']='';
        update_option(self::REQUEST_OPTION,$requests,false);
    }

    public function catalog_search(string $search='', string $brand='', string $category='', int $page=1, int $per_page=50): array {
        $needle=mb_strtolower(trim($search)); $brand=trim($brand); $category=trim($category); $rows=[];
        $hydrated=$this->style_manifest();
        foreach ((array)($this->catalog_data()['products'] ?? []) as $row) {
            if(!is_array($row))continue;
            if($brand!=='' && strcasecmp((string)($row['brand'] ?? ''),$brand)!==0)continue;
            if($category!==''){
                $match=false; foreach((array)($row['categories'] ?? []) as $cat)if(strcasecmp((string)$cat,$category)===0){$match=true;break;}
                if(!$match)continue;
            }
            if($needle!==''){
                $hay=mb_strtolower(implode(' ',[(string)($row['style'] ?? ''),(string)($row['title'] ?? ''),(string)($row['brand'] ?? ''),(string)($row['category'] ?? ''),(string)($row['division'] ?? '')]));
                if(mb_strpos($hay,$needle)===false)continue;
            }
            $style=(string)($row['style'] ?? ''); $key=$this->style_key($style); $request=$this->request_for_style($style);
            $row['hydrated']=isset($hydrated[$key]);
            $row['request_status']=sanitize_key((string)($request['status'] ?? ''));
            $row['request_message']=sanitize_text_field((string)($request['message'] ?? ''));
            $rows[]=$row;
        }
        $total=count($rows); $per_page=max(10,min(100,$per_page)); $pages=max(1,(int)ceil($total/$per_page)); $page=max(1,min($pages,$page));
        return ['rows'=>array_slice($rows,($page-1)*$per_page,$per_page),'total'=>$total,'pages'=>$pages,'page'=>$page,'per_page'=>$per_page];
    }

    public function representative_product_image(array $product): string {
        foreach ((array)($product['variants'] ?? []) as $variant) {
            if (!is_array($variant)) continue;
            $primary = trim((string)($variant['primary_image'] ?? ''));
            if ($primary !== '') return $primary;
            foreach ((array)($variant['gallery'] ?? []) as $url) { $url=trim((string)$url); if($url!=='')return $url; }
        }
        foreach ([(string)($product['images']['product'] ?? ''),(string)($product['images']['thumbnail'] ?? '')] as $url) { $url=trim($url); if($url!=='')return $url; }
        return '';
    }

    private function summary(array $product): array {
        $variants=is_array($product['variants'] ?? null)?$product['variants']:[]; $colors=[]; $sizes=[];
        foreach($variants as $variant){if(!is_array($variant))continue;$color=trim((string)($variant['color'] ?? $variant['catalog_color'] ?? ''));$size=trim((string)($variant['size'] ?? ''));if($color!=='')$colors[mb_strtolower($color)]=$color;if($size!=='')$sizes[mb_strtolower($size)]=$size;}
        $style=sanitize_text_field((string)($product['style'] ?? $product['supplier_style_id'] ?? ''));
        return ['style'=>$style,'title'=>sanitize_text_field((string)($product['title'] ?? $style)),'brand'=>sanitize_text_field((string)($product['brand'] ?? '')),'category'=>sanitize_text_field((string)($product['category'] ?? '')),'image'=>esc_url_raw($this->representative_product_image($product)),'color_count'=>count($colors),'size_count'=>count($sizes),'variant_count'=>count($variants),'sparse_missing'=>max(0,count($colors)*count($sizes)-count($variants))];
    }

    /** Store one complete customer-specific normalized Momentec style pushed by GitHub. */
    public function save_style(array $product, array $meta = []) {
        if(strtolower(trim((string)($product['supplier'] ?? '')))!=='momentec')return new WP_Error('momentec_supplier','Momentec cache payload has the wrong supplier.',['status'=>400]);
        $style=trim(sanitize_text_field((string)($product['style'] ?? $product['supplier_style_id'] ?? ''))); if($style==='')return new WP_Error('momentec_style','Momentec cache payload is missing a style/product number.',['status'=>400]);
        $variants=is_array($product['variants'] ?? null)?array_values($product['variants']):[]; if(!$variants)return new WP_Error('momentec_variants','Momentec cache payload contains no exact supplier variations.',['status'=>400]);
        $seen_skus=[];$seen_combos=[];
        foreach($variants as $i=>$row){
            if(!is_array($row))return new WP_Error('momentec_variant','Momentec variation row is not an object.',['status'=>400]);
            $sku=trim(sanitize_text_field((string)($row['sku'] ?? $row['unique_key'] ?? '')));$color=trim(sanitize_text_field((string)($row['color'] ?? $row['catalog_color'] ?? '')));$size=trim(sanitize_text_field((string)($row['size'] ?? '')));
            if($sku===''||$color===''||$size==='')return new WP_Error('momentec_variant_identity','Momentec variation row #'.($i+1).' is missing SKU, color, or size.',['status'=>400]);
            $sku_key=strtolower($sku);$combo=strtolower(preg_replace('/\s+/u',' ',$color)).'|'.strtolower(preg_replace('/\s+/u',' ',$size));
            if(isset($seen_skus[$sku_key])||isset($seen_combos[$combo]))return new WP_Error('momentec_duplicate_variant','Momentec payload contains a duplicate exact SKU or Color+Size combination.',['status'=>409]);
            $seen_skus[$sku_key]=1;$seen_combos[$combo]=1;
        }
        $product['supplier']='momentec';$product['supplier_name']=self::LABEL;$product['style']=$style;$product['supplier_style_id']=$style;$product['received_at']=current_time('mysql');
        $product['bridge_meta']=['source'=>sanitize_text_field((string)($meta['source'] ?? 'momentec-v2-production')),'source_timestamp'=>sanitize_text_field((string)($meta['source_timestamp'] ?? ''))];
        $file=$this->style_filename($style);$write=$this->write_json_atomic($this->styles_dir().'/'.$file,$product);if(is_wp_error($write))return $write;
        $manifest=$this->style_manifest();$summary=$this->summary($product);$summary['file']=$file;$summary['received_at']=current_time('mysql');$summary['source']=sanitize_text_field((string)($meta['source'] ?? 'momentec-v2-production'));
        $manifest[$this->style_key($style)]=$summary;uasort($manifest,static fn($a,$b)=>strnatcasecmp((string)($a['style'] ?? ''),(string)($b['style'] ?? '')));update_option(self::STYLE_MANIFEST_OPTION,$manifest,false);
        $this->mark_style_request_complete($style);
        return ['style'=>$style,'variants'=>count($variants),'summary'=>$summary];
    }

    public function style_manifest(): array { $manifest=get_option(self::STYLE_MANIFEST_OPTION,[]);return is_array($manifest)?$manifest:[]; }

    public function style_summaries(string $search='', int $limit=250): array {
        $needle=mb_strtolower(trim($search));$out=[];
        foreach($this->style_manifest() as $summary){if(!is_array($summary))continue;if($needle!==''){$hay=mb_strtolower(implode(' ',[(string)($summary['style'] ?? ''),(string)($summary['title'] ?? ''),(string)($summary['brand'] ?? ''),(string)($summary['category'] ?? '')]));if(mb_strpos($hay,$needle)===false)continue;}$out[]=$summary;if($limit>0&&count($out)>=$limit)break;}
        return $out;
    }

    public function style_product(string $style) {
        $key=$this->style_key($style);$meta=$this->style_manifest()[$key] ?? [];
        if(!is_array($meta)||empty($meta['file']))return new WP_Error('momentec_style_missing','Momentec customer-specific details are not cached yet. Queue this style from the Momentec catalog and let GitHub hydrate it.');
        $path=$this->styles_dir().'/'.basename((string)$meta['file']);if(!is_file($path))return new WP_Error('momentec_style_file','Momentec cached style file is missing. Queue the style for refresh.');
        $decoded=json_decode((string)@file_get_contents($path),true);if(!is_array($decoded))return new WP_Error('momentec_style_json','Momentec cached style file is invalid.');return $decoded;
    }

    public function purge_legacy_wordpress_connection_values(): void {
        if(!current_user_can('manage_woocommerce'))return;$s=get_option('asss_settings',[]);if(!is_array($s))return;$changed=false;
        foreach(['momentec_username','momentec_password','momentec_api_key','momentec_secret','momentec_api_base','momentec_account','momentec_environment'] as $key){if(array_key_exists($key,$s)){unset($s[$key]);$changed=true;}}
        if($changed)update_option('asss_settings',$s,false);
    }

    public function status(): array {
        $manifest=$this->style_manifest();$variants=0;foreach($manifest as $row)if(is_array($row))$variants+=(int)($row['variant_count'] ?? 0);
        $requests=$this->request_manifest();$counts=['pending'=>0,'processing'=>0,'failed'=>0,'complete'=>0];foreach($requests as $row)if(is_array($row)&&isset($counts[(string)($row['status'] ?? '')]))$counts[(string)$row['status']]++;
        $catalog_meta=$this->catalog_meta();
        return ['supplier'=>self::KEY,'label'=>self::LABEL,'configured'=>$this->configured(),'enabled'=>$this->configured(),'connection_owner'=>'github-actions','credentials_location'=>'github-actions-secrets','environment'=>'production','state'=>$this->configured()?'production-github-bridge-enabled':'disabled','catalog_sync'=>'official-product-feed-plus-v2-hydration','inventory_sync'=>'production-v2-targeted-inventory','catalog_styles'=>$this->catalog_count(),'catalog_received_at'=>(string)($catalog_meta['received_at'] ?? ''),'catalog_source_rows'=>(int)($catalog_meta['source_rows'] ?? 0),'cached_styles'=>count($manifest),'cached_variants'=>$variants,'requests'=>$counts];
    }

    public function capabilities(): array {
        return ['catalog'=>true,'full_catalog_browse'=>true,'customer_specific_style_hydration'=>true,'inventory'=>true,'orders'=>false,'order_status'=>false,'live_adapter_enabled'=>true,'supplier_auth_location'=>'github-actions','api_version'=>'v2','catalog_source'=>'official-csv-feed','environment'=>'production'];
    }
}
