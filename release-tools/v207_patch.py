#!/usr/bin/env python3
from pathlib import Path
import re
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v207_patch.py <source-dir>')
root = Path(sys.argv[1])


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'v2.0.7 patch marker missing: {label}')
    return text.replace(old, new, 1)


# Version.
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = replace_once(text, 'Version: 2.0.6', 'Version: 2.0.7', 'plugin header version')
text = replace_once(text, "define('ASSS_VERSION', '2.0.6');", "define('ASSS_VERSION', '2.0.7');", 'ASSS_VERSION')
text = text.replace(
    'SanMar, S&S Activewear, and Momentec production supplier connectors with GitHub Actions bridge synchronization.',
    'SanMar, S&S Activewear, and Momentec production supplier connectors with full catalog browsing through GitHub Actions.'
)
main.write_text(text, encoding='utf-8')


# Momentec: full official-feed catalog + v2 hydrated style cache + request queue.
momentec = root / 'includes/class-asss-momentec.php'
momentec.write_text(r'''<?php
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
        $row['status']=$status; $row['updated_at']=current_time('mysql'); $row['message']=sanitize_text_field(mb_substr($message,0,300));
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
''', encoding='utf-8')


# Bridge routes/methods/status.
bridge = root / 'includes/class-asss-bridge.php'
text = bridge.read_text(encoding='utf-8')
route_marker = "        register_rest_route('asss/v1', '/bridge/momentec/style', [\n"
route_add = r'''        register_rest_route('asss/v1', '/bridge/momentec/catalog/batch', [
            'methods' => 'POST', 'callback' => [$this, 'receive_momentec_catalog_batch'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/momentec/requests', [
            'methods' => 'GET', 'callback' => [$this, 'momentec_requests'], 'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('asss/v1', '/bridge/momentec/request-status', [
            'methods' => 'POST', 'callback' => [$this, 'momentec_request_status'], 'permission_callback' => [$this, 'authorize'],
        ]);
'''
text = replace_once(text, route_marker, route_add + route_marker, 'Momentec catalog routes')
method_marker = "    public function receive_momentec_style(WP_REST_Request $request) {\n"
method_add = r'''    public function receive_momentec_catalog_batch(WP_REST_Request $request) {
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

'''
text = replace_once(text, method_marker, method_add + method_marker, 'Momentec catalog methods')
old_status = "            'ss_inventory'=>get_option('asss_ss_inventory_bridge_status', []),\n        ], 200);"
new_status = "            'ss_inventory'=>get_option('asss_ss_inventory_bridge_status', []),\n            'momentec'=>$this->momentec->status(),\n        ], 200);"
text = replace_once(text, old_status, new_status, 'bridge Momentec status')
bridge.write_text(text, encoding='utf-8')


# Admin actions and browse UI.
admin = root / 'includes/class-asss-admin.php'
text = admin.read_text(encoding='utf-8')
action_marker = "        if (!empty($_POST['asss_import_momentec_style'])) {\n"
action_add = r'''        if (!empty($_POST['asss_momentec_queue_styles'])) {
            check_admin_referer('asss_momentec_catalog');
            $styles=array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)($_POST['momentec_styles'] ?? [])))));
            if(!$styles){
                wp_safe_redirect(add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','asss_err'=>'Choose at least one Momentec style first.'],admin_url('admin.php')));exit;
            }
            $result=$this->momentec->queue_style_requests($styles,get_current_user_id(),true);
            $message='Queued '.(int)$result['queued'].' Momentec style'.((int)$result['queued']===1?'':'s').' for customer-specific production details.';
            if(!empty($result['unknown']))$message.=' '.count($result['unknown']).' unknown style(s) were skipped.';
            wp_safe_redirect(add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','asss_msg'=>$message],admin_url('admin.php')));exit;
        }

'''
text = replace_once(text, action_marker, action_add + action_marker, 'Momentec queue admin action')

pattern = re.compile(r"        if \(\$supplier === 'momentec'\) \{.*?        \}\n\n        if \(\$supplier === 'ss'\) \{", re.S)
new_block = r'''        if ($supplier === 'momentec') {
            $status=$this->momentec->status();
            $search=sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
            $brand=sanitize_text_field(wp_unslash($_GET['brand'] ?? ''));
            $category=sanitize_text_field(wp_unslash($_GET['category'] ?? ''));
            $page_num=max(1,absint($_GET['catalog_page'] ?? 1));
            $facets=$this->momentec->catalog_facets();
            $catalog=$this->momentec->catalog_search($search,$brand,$category,$page_num,50);
            $meta=$this->momentec->catalog_meta();

            echo '<div class="notice notice-success inline"><p><strong>Momentec production is connected through GitHub Actions.</strong> The full browse catalog comes from Momentec\'s official product-data feed; customer-specific cost/details are fetched securely through production v2 only after you select a style.</p></div>';
            echo '<p><strong>Browse catalog:</strong> '.number_format_i18n((int)($status['catalog_styles'] ?? 0)).' styles &nbsp; <strong>Customer-detail cache:</strong> '.number_format_i18n((int)($status['cached_styles'] ?? 0)).' styles / '.number_format_i18n((int)($status['cached_variants'] ?? 0)).' exact variations';
            if(!empty($meta['received_at']))echo ' &nbsp; <strong>Catalog updated:</strong> '.esc_html((string)$meta['received_at']);
            echo '.</p>';
            if(empty($status['catalog_styles'])){
                echo '<div class="notice notice-warning inline"><p>The official Momentec browse catalog has not reached WordPress yet. Run <strong>Momentec Production Catalog Sync</strong> in <code>rolejarczyk/ASE.ProductSync</code>. Once it publishes, this page will show the full catalog automatically.</p></div>';
                $this->wrap_end();return;
            }

            echo '<form method="get"><input type="hidden" name="page" value="asss-suppliers"><input type="hidden" name="supplier" value="momentec"><table class="form-table" style="max-width:1040px"><tr><th style="width:150px">Brand</th><td><select name="brand"><option value="">All brands</option>';
            foreach((array)($facets['brands'] ?? []) as $value)echo '<option '.selected($brand,$value,false).' value="'.esc_attr($value).'">'.esc_html($value).'</option>';
            echo '</select></td></tr><tr><th>Category</th><td><select name="category"><option value="">All categories</option>';
            foreach((array)($facets['categories'] ?? []) as $value)echo '<option '.selected($category,$value,false).' value="'.esc_attr($value).'">'.esc_html($value).'</option>';
            echo '</select></td></tr><tr><th>Search</th><td><input class="regular-text" name="q" value="'.esc_attr($search).'" placeholder="Style, product, brand, division, or category"> <button class="button button-primary">Browse Catalog</button></td></tr></table></form>';

            echo '<p><strong>'.number_format_i18n((int)$catalog['total']).'</strong> matching styles. Select one or more styles and click <strong>Fetch / Refresh Customer Details</strong>. GitHub will securely hydrate them with your Momentec account pricing, exact Color+Size SKUs, inventory, and galleries; they will then become ready for Review &amp; Import.</p>';
            if(empty($catalog['rows'])){echo '<p><em>No Momentec catalog styles match these filters.</em></p>';$this->wrap_end();return;}

            echo '<style>.asss-momentec-table{width:100%;border-collapse:collapse}.asss-momentec-table th,.asss-momentec-table td{text-align:left;vertical-align:middle;padding:10px 12px;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7}.asss-momentec-table th:last-child,.asss-momentec-table td:last-child{border-right:0}.asss-momentec-status{display:inline-block;padding:3px 7px;border-radius:999px;background:#f0f0f1;font-size:12px}.asss-momentec-status.ready{background:#d7f0df}.asss-momentec-status.pending{background:#fff3cd}.asss-momentec-status.failed{background:#f8d7da}</style>';
            echo '<form method="post">';wp_nonce_field('asss_momentec_catalog');
            echo '<div style="margin:12px 0"><button class="button button-primary" name="asss_momentec_queue_styles" value="1">Fetch / Refresh Customer Details</button> <span class="description">The GitHub worker checks the queue every five minutes.</span></div>';
            echo '<table class="widefat striped asss-momentec-table"><thead><tr><th style="width:34px"><input type="checkbox" onclick="document.querySelectorAll(\'.asss-momentec-pick\').forEach(el=>el.checked=this.checked)"></th><th style="width:68px">Image</th><th>Product</th><th>Style</th><th>Brand</th><th>Category</th><th>Colors</th><th>Sizes</th><th>Exact SKUs</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            foreach($catalog['rows'] as $row){
                $style=(string)($row['style'] ?? '');$image=(string)($row['image'] ?? '');$hydrated=!empty($row['hydrated']);$request_status=(string)($row['request_status'] ?? '');
                echo '<tr><td><input class="asss-momentec-pick" type="checkbox" name="momentec_styles[]" value="'.esc_attr($style).'"></td><td>'.($image!==''?'<img src="'.esc_url($image).'" alt="" onerror="this.style.display=&quot;none&quot;" style="width:54px;height:54px;object-fit:contain;background:#fff;border:1px solid #e2e4e7;border-radius:4px">':'—').'</td>';
                echo '<td><strong>'.esc_html((string)($row['title'] ?? $style)).'</strong>';if(!empty($row['division']))echo '<br><small>'.esc_html((string)$row['division']).'</small>';echo '</td><td><code>'.esc_html($style).'</code></td><td>'.esc_html((string)($row['brand'] ?? '')).'</td><td>'.esc_html((string)($row['category'] ?? '—')).'</td><td>'.(int)($row['color_count'] ?? 0).'</td><td>'.(int)($row['size_count'] ?? 0).'</td><td><strong>'.(int)($row['exact_variation_count'] ?? 0).'</strong>';
                if(!empty($row['sparse_missing']))echo '<br><small>'.number_format_i18n((int)$row['sparse_missing']).' fake Cartesian combos avoided</small>';echo '</td><td>';
                if($hydrated)echo '<span class="asss-momentec-status ready">Ready</span>';
                elseif($request_status==='processing')echo '<span class="asss-momentec-status pending">Fetching…</span>';
                elseif($request_status==='pending')echo '<span class="asss-momentec-status pending">Queued</span>';
                elseif($request_status==='failed'){echo '<span class="asss-momentec-status failed">Failed</span>';if(!empty($row['request_message']))echo '<br><small>'.esc_html((string)$row['request_message']).'</small>';}
                else echo '<span class="asss-momentec-status">Needs details</span>';
                echo '</td><td>';
                if($hydrated){$review=add_query_arg(['page'=>'asss-momentec-review','style'=>$style],admin_url('admin.php'));echo '<a class="button button-primary" href="'.esc_url($review).'">Review &amp; Import</a>';}
                elseif(in_array($request_status,['pending','processing'],true))echo '<span class="description">Automatic GitHub hydration queued</span>';
                else echo '<span class="description">Select this row above</span>';
                echo '</td></tr>';
            }
            echo '</tbody></table><div style="margin:12px 0"><button class="button button-primary" name="asss_momentec_queue_styles" value="1">Fetch / Refresh Customer Details</button></div></form>';

            if((int)$catalog['pages']>1){
                echo '<div class="tablenav"><div class="tablenav-pages">';
                for($p=1;$p<=(int)$catalog['pages'];$p++){
                    if($p!==1 && $p!==(int)$catalog['pages'] && abs($p-(int)$catalog['page'])>2)continue;
                    $url=add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','q'=>$search,'brand'=>$brand,'category'=>$category,'catalog_page'=>$p],admin_url('admin.php'));
                    echo $p===(int)$catalog['page']?'<span class="button disabled">'.$p.'</span> ':'<a class="button" href="'.esc_url($url).'">'.$p.'</a> ';
                }
                echo '</div></div>';
            }
            $this->wrap_end(); return;
        }

        if ($supplier === 'ss') {'''
text, n = pattern.subn(new_block, text, count=1)
if n != 1:
    raise SystemExit(f'v2.0.7 Momentec admin browse block replacement count={n}')
admin.write_text(text, encoding='utf-8')

print('Applied All Star Supplier Sync v2.0.7 Momentec full catalog patch.')
