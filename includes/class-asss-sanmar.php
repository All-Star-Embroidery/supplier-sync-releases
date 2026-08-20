<?php
if (!defined('ABSPATH')) exit;

class ASSS_SanMar {
    const SUPPLIER = 'sanmar';

    public function settings(): array {
        $stored = get_option('asss_settings', []);
        // Migrate the original FTP/FTPS-only setting without breaking existing installs.
        if (empty($stored['transfer_protocol'])) {
            $stored['transfer_protocol'] = !empty($stored['ftp_ssl']) ? 'ftps' : 'ftp';
        }

        // Versioned settings migrations. Supplier imports participate in ASBO
        // by default, and v0.4.0 moves automatic inventory off the direct SFTP
        // worker and onto the GitHub bridge for shared-host compatibility.
        $schema_version = (string)get_option('asss_settings_schema_version', '0.0.0');
        if (version_compare($schema_version, '0.3.3', '<')) {
            $stored['auto_enable_bulk_order'] = 1;
            $schema_version = '0.3.3';
        }
        if (version_compare($schema_version, '0.4.0', '<')) {
            $stored['bridge_inventory_enabled'] = 1;
            $stored['hourly_inventory_sync'] = 0;
            $schema_version = '0.4.0';
        }
        if (version_compare($schema_version, '0.5.0', '<')) {
            $stored['automatic_product_bridge'] = 1;
            $schema_version = '0.5.0';
        }
        if (version_compare($schema_version, '0.5.2', '<')) {
            // WooCommerce requires a non-empty variation price before a variation
            // is purchasable. Use ASBO's single-piece tier as a safe base price
            // while ASBO still owns the actual tiered cart pricing.
            $stored['sync_variation_base_prices'] = 1;
            $schema_version = '0.5.2';
        }
        if (version_compare($schema_version, '1.0.0', '<')) {
            // Production architecture: GitHub owns external SanMar transfers;
            // WordPress receives curated product/inventory payloads over HTTPS.
            $stored['bridge_inventory_enabled'] = 1;
            $stored['hourly_inventory_sync'] = 0;
            $stored['automatic_product_bridge'] = 1;
            // Product files arrive through the changed-only GitHub bridge. The
            // legacy WordPress daily cache-repair cron is intentionally off in V1.
            $stored['daily_product_sync'] = 0;
            $schema_version = '1.0.0';
        }
        if (version_compare($schema_version, '2.0.0', '<')) {
            $stored['multi_inventory_strategy'] = $stored['multi_inventory_strategy'] ?? 'combined';
            $stored['supplier_priority'] = $stored['supplier_priority'] ?? 'ss,sanmar';
            $stored['supplier_intelligence_enabled'] = 1;
            $schema_version = '2.0.0';
        }
        if (version_compare($schema_version, '2.0.1', '<')) {
            $stored['github_update_repo'] = $stored['github_update_repo'] ?? 'rolejarczyk/ASE.SupplierSync-Releases';
            $stored['github_auto_updates'] = $stored['github_auto_updates'] ?? 0;
            $schema_version = '2.0.1';
        }
        update_option('asss_settings', $stored, false);
        update_option('asss_settings_schema_version', $schema_version, false);
        return wp_parse_args($stored, [
            'transfer_protocol'=>'sftp',
            'ftp_host'=>'','ftp_port'=>22,'ftp_passive'=>1,'ftp_user'=>'','ftp_pass'=>'',
            'ftp_base_dir'=>'/SanMarPDD/SanMarPI','inventory_path'=>'',
            'ws_customer'=>'','ws_user'=>'','ws_pass'=>'','request_brand_files'=>0,
            'stock_buffer'=>0,'sync_images'=>1,'sync_description'=>1,'sync_new_variations'=>1,'sync_variation_base_prices'=>1,
            'daily_product_sync'=>0,'hourly_inventory_sync'=>0,'bridge_inventory_enabled'=>1,'admin_notify'=>get_option('admin_email'),
            'bridge_enabled'=>1,'bridge_token'=>'','automatic_product_bridge'=>1,
            'sync_bulk_order_fields'=>1,'auto_enable_bulk_order'=>1,
            'fallback_length_in'=>'','fallback_width_in'=>'','fallback_height_in'=>'',
            'multi_inventory_strategy'=>'combined','supplier_priority'=>'ss,sanmar','supplier_intelligence_enabled'=>1,
            'github_update_repo'=>'rolejarczyk/ASE.SupplierSync-Releases','github_auto_updates'=>0,
        ]);
    }

    private string $last_transfer_error = '';

    private function protocol(): string {
        $p = strtolower((string)$this->settings()['transfer_protocol']);
        return in_array($p, ['sftp','ftp','ftps'], true) ? $p : 'sftp';
    }

    /**
     * File transfers intentionally use PHP cURL rather than the optional PHP ssh2
     * extension. Many shared hosts (including common cPanel environments) compile
     * cURL with libssh2/SFTP support without exposing ssh2_connect().
     */
    private function connect() {
        $s = $this->settings();
        $protocol = $this->protocol();
        if (!$s['ftp_host'] || !$s['ftp_user']) {
            return new WP_Error('missing_transfer', 'Host and username are required.');
        }
        if (!function_exists('curl_init') || !function_exists('curl_version')) {
            return new WP_Error('curl_missing', 'PHP cURL is not enabled on this web server. cURL is required for supplier file connections.');
        }

        $info = curl_version();
        $supported = array_map('strtolower', (array)($info['protocols'] ?? []));
        $required = $protocol === 'ftps' ? 'ftp' : $protocol;
        if (!in_array($required, $supported, true)) {
            return new WP_Error(
                'curl_protocol',
                'PHP cURL is enabled, but this server\'s cURL build does not support '.strtoupper($protocol).'. Supported protocols: '.implode(', ', $supported)
            );
        }

        $port = (int)$s['ftp_port'];
        if ($port <= 0) $port = $protocol === 'sftp' ? 22 : 21;

        return [
            'protocol' => $protocol,
            'host'     => $this->clean_host((string)$s['ftp_host']),
            'port'     => $port,
            'user'     => (string)$s['ftp_user'],
            'pass'     => (string)$s['ftp_pass'],
            'passive'  => !empty($s['ftp_passive']),
        ];
    }

    private function close($handle): void {
        // cURL requests are opened and closed per operation, so there is no
        // persistent transfer resource to close here.
    }

    private function clean_host(string $host): string {
        $host = trim($host);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $host)) {
            $parsed = wp_parse_url($host);
            if (!empty($parsed['host'])) return (string)$parsed['host'];
        }
        return trim($host, " /\\\t\n\r\0\x0B");
    }

    private function encode_remote_path(string $path, bool $directory = false): string {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            if ($segment !== '') $segment = rawurlencode(rawurldecode($segment));
        }
        unset($segment);
        $encoded = implode('/', $segments);
        if ($directory) $encoded = rtrim($encoded, '/').'/';
        return $encoded;
    }

    private function remote_url(array $handle, string $path, bool $directory = false): string {
        // Explicit FTPS uses the ftp:// URL scheme plus CURLOPT_USE_SSL.
        $scheme = $handle['protocol'] === 'sftp' ? 'sftp' : 'ftp';
        return $scheme.'://'.$handle['host'].':'.$handle['port'].$this->encode_remote_path($path, $directory);
    }

    private function apply_curl_options($ch, array $handle): void {
        curl_setopt($ch, CURLOPT_USERPWD, $handle['user'].':'.$handle['pass']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'All-Star-Supplier-Sync/'.(defined('ASSS_VERSION') ? ASSS_VERSION : 'unknown'));

        if ($handle['protocol'] === 'sftp') {
            if (defined('CURLOPT_SSH_AUTH_TYPES') && defined('CURLSSH_AUTH_PASSWORD')) {
                curl_setopt($ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
            }
        } else {
            // libcurl uses passive FTP by default. CURLOPT_FTPPORT switches to
            // active mode only when the administrator explicitly disables it.
            if (!$handle['passive'] && defined('CURLOPT_FTPPORT')) {
                curl_setopt($ch, CURLOPT_FTPPORT, '-');
            }
            if ($handle['protocol'] === 'ftps') {
                curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            }
        }
    }

    private function set_transfer_error($ch, string $prefix = ''): void {
        $message = trim((string)curl_error($ch));
        $code = (int)curl_errno($ch);
        if ($message === '') $message = 'Unknown cURL transfer error.';
        $this->last_transfer_error = trim($prefix.' '.$message.' (cURL '.$code.')');
    }

    private function remote_list($handle, string $dir) {
        if (!is_array($handle)) return false;
        $this->last_transfer_error = '';
        $ch = curl_init();
        if (!$ch) return false;
        $this->apply_curl_options($ch, $handle);
        curl_setopt($ch, CURLOPT_URL, $this->remote_url($handle, $dir, true));
        curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        if ($body === false) {
            $this->set_transfer_error($ch, 'Directory listing failed.');
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', trim((string)$body)) as $file) {
            $file = trim($file);
            if ($file === '' || $file === '.' || $file === '..') continue;
            // Some FTP servers may return a path even with DIRLISTONLY. Normalize
            // to the same full remote-path shape used elsewhere in the plugin.
            $file = basename(str_replace('\\', '/', $file));
            $out[] = rtrim($dir, '/').'/'.$file;
        }
        return $out;
    }

    private function remote_size($handle, string $path) {
        if (!is_array($handle)) return -1;
        $this->last_transfer_error = '';
        $ch = curl_init();
        if (!$ch) return -1;
        $this->apply_curl_options($ch, $handle);
        curl_setopt($ch, CURLOPT_URL, $this->remote_url($handle, $path));
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $ok = curl_exec($ch);
        if ($ok === false) {
            $this->set_transfer_error($ch, 'Remote file check failed.');
            curl_close($ch);
            return -1;
        }
        if (defined('CURLINFO_CONTENT_LENGTH_DOWNLOAD_T')) {
            $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
        } else {
            $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        }
        curl_close($ch);
        // A successful stat/check with an unknown size is still enough to prove
        // the path exists; return zero rather than treating it as missing.
        return is_numeric($size) && (float)$size >= 0 ? (int)$size : 0;
    }

    private function remote_download($handle, string $remote, string $local): bool {
        if (!is_array($handle)) return false;
        $this->last_transfer_error = '';
        $out = @fopen($local, 'wb');
        if (!$out) {
            $this->last_transfer_error = 'Could not open the local temporary file for writing.';
            return false;
        }
        $ch = curl_init();
        if (!$ch) {
            fclose($out);
            $this->last_transfer_error = 'Could not initialize PHP cURL.';
            return false;
        }
        $this->apply_curl_options($ch, $handle);
        curl_setopt($ch, CURLOPT_URL, $this->remote_url($handle, $remote));
        curl_setopt($ch, CURLOPT_FILE, $out);
        $ok = curl_exec($ch);
        if ($ok === false) $this->set_transfer_error($ch, 'Download failed.');
        curl_close($ch);
        fclose($out);
        if (!$ok) @unlink($local);
        return (bool)$ok;
    }

    public function test_connection() {
        $c=$this->connect();
        if (is_wp_error($c)) return $c;
        $s=$this->settings();
        $list=$this->remote_list($c,$s['ftp_base_dir']);
        $this->close($c);
        if ($list===false) {
            $detail = $this->last_transfer_error ? ' '.$this->last_transfer_error : '';
            if (strpos($this->last_transfer_error, '(cURL 7)') !== false) {
                $detail .= ' The connection was refused before SanMar authentication or folder access could occur. Verify the exact SanMar port. If the same host/port works from FileZilla but not from this website, your web host may be blocking outbound connections to that non-standard port and must allow it.';
            }
            return new WP_Error('transfer_dir',strtoupper($this->protocol()).' connection failed or the configured SanMar product directory could not be listed.'.$detail);
        }
        return ['count'=>count($list),'directory'=>$s['ftp_base_dir'],'protocol'=>strtoupper($this->protocol())];
    }



    public function brand_catalog(): array {
        $catalog = get_option('asss_sanmar_brand_catalog', []);
        return is_array($catalog) ? $catalog : [];
    }

    public function save_brand_catalog(array $brands): array {
        $existing = $this->brand_catalog();
        $in_use = [];
        $q = new WP_Query([
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,
            'meta_query'=>[['key'=>'_asss_supplier','value'=>'sanmar']],
        ]);
        foreach ($q->posts as $id) {
            $b=(string)get_post_meta((int)$id,'_asss_sanmar_brand',true);
            if ($b!=='') $in_use[strtolower(trim($b))]=1;
        }

        // SFTP can expose the same brand token with casing differences (e.g.
        // AllMade/Allmade). Collapse case-insensitively and keep the newest file.
        $deduped=[];
        foreach ($brands as $item) {
            if (!is_array($item)) continue;
            $brand=trim(sanitize_text_field((string)($item['brand'] ?? '')));
            if ($brand==='') continue;
            $key=strtolower($brand);
            $candidate=[
                'brand'=>$brand,
                'latest_file'=>sanitize_text_field((string)($item['latest_file'] ?? '')),
                'latest_date'=>sanitize_text_field((string)($item['latest_date'] ?? '')),
            ];
            if (!isset($deduped[$key]) || strcmp($candidate['latest_date'], (string)$deduped[$key]['latest_date']) > 0) {
                $deduped[$key]=$candidate;
            }
        }

        $out=[];
        foreach ($deduped as $key=>$item) {
            $brand=$item['brand'];
            $was=null;
            foreach ($existing as $oldBrand=>$old) {
                if (strcasecmp((string)$oldBrand,$brand)===0) { $was=$old; break; }
            }
            $enabled = is_array($was) && array_key_exists('enabled',$was) ? !empty($was['enabled']) : !empty($in_use[$key]);
            $out[$brand]=[
                'brand'=>$brand,'enabled'=>$enabled?1:0,
                'latest_file'=>$item['latest_file'],'latest_date'=>$item['latest_date'],
                'discovered_at'=>current_time('mysql'),
            ];
        }
        uksort($out, 'strnatcasecmp');
        update_option('asss_sanmar_brand_catalog',$out,false);
        return $out;
    }

    public function set_enabled_brands(array $brands): array {
        $catalog=$this->brand_catalog();
        $wanted=array_fill_keys(array_map('strtolower',array_map('strval',$brands)),1);
        foreach ($catalog as $brand=>&$meta) $meta['enabled']=isset($wanted[strtolower((string)$brand)]) ? 1 : 0;
        unset($meta);
        update_option('asss_sanmar_brand_catalog',$catalog,false);
        return $catalog;
    }

    public function enabled_brand_catalog(): array {
        return array_filter($this->brand_catalog(), static fn($m)=>!empty($m['enabled']));
    }

    public function bridge_manifest(): array {
        $manifest = get_option('asss_bridge_manifest', []);
        return is_array($manifest) ? $manifest : [];
    }

    private function bridge_dir(): string {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'all-star-supplier-sync/sanmar';
    }

    private function bridge_file(string $brand): string {
        // Lowercase cache filenames prevent case-only duplicate brand stores on
        // Linux filesystems while the manifest preserves the display casing.
        return trailingslashit($this->bridge_dir()) . sanitize_file_name(strtolower(trim($brand))) . '.json';
    }

    private function canonical_brand(string $brand): string {
        $brand=trim(sanitize_text_field($brand));
        foreach ($this->brand_catalog() as $known=>$known_meta) {
            if (strcasecmp((string)$known,$brand)===0) return (string)$known;
        }
        return $brand;
    }

    private function bridge_generation_dir(string $brand, string $generation): string {
        return trailingslashit($this->bridge_dir()) . '.generations/' . sanitize_file_name(strtolower(trim($brand))) . '/' . sanitize_file_name($generation);
    }

    private function bridge_style_filename(string $style): string {
        $safe=sanitize_file_name(strtolower(trim($style)));
        if ($safe==='') $safe='style';
        return $safe . '-' . substr(md5($style),0,10) . '.json';
    }

    private function representative_product_image(array $product): string {
        foreach ((array)($product['variants'] ?? []) as $variant) {
            if (!is_array($variant)) continue;
            $primary = trim((string)($variant['primary_image'] ?? ''));
            if ($primary !== '') return $primary;
            foreach ((array)($variant['gallery'] ?? []) as $url) {
                $url = trim((string)$url);
                if ($url !== '') return $url;
            }
            foreach (['color_product','front_flat','front_model','three_q_model','side_model','back_model','back_flat','color_product_thumbnail'] as $key) {
                $url = trim((string)($variant['images'][$key] ?? ''));
                if ($url !== '') return $url;
            }
        }
        foreach ([(string)($product['images']['product'] ?? ''), (string)($product['images']['thumbnail'] ?? '')] as $url) {
            $url = trim($url);
            if ($url !== '') return $url;
        }
        return '';
    }

    private function bridge_product_summary(array $product, string $detail_file): array {
        $colors=[]; $sizes=[];
        foreach ((array)($product['variants'] ?? []) as $v) {
            if (!is_array($v)) continue;
            $color=trim((string)($v['color'] ?? $v['catalog_color'] ?? ''));
            $size=trim((string)($v['size'] ?? ''));
            if ($color!=='') $colors[strtolower($color)]=1;
            if ($size!=='') $sizes[strtolower($size)]=1;
        }
        return [
            'style'=>(string)($product['style'] ?? ''),
            'brand'=>(string)($product['brand'] ?? ''),
            'title'=>(string)($product['title'] ?? ($product['style'] ?? '')),
            'category'=>(string)($product['category'] ?? ''),
            'status'=>(string)($product['status'] ?? ''),
            'available_sizes_text'=>(string)($product['available_sizes_text'] ?? ''),
            'keywords'=>(string)($product['keywords'] ?? ''),
            'images'=>(array)($product['images'] ?? []),
            'representative_image'=>$this->representative_product_image($product),
            'color_count'=>count($colors),
            'size_count'=>count($sizes),
            'variant_count'=>count((array)($product['variants'] ?? [])),
            'detail_file'=>$detail_file,
        ];
    }

    private function write_bridge_product_detail(string $generation_dir, array $product): array {
        $style=trim((string)($product['style'] ?? ''));
        if ($style==='') return [null,0];
        $styles_dir=trailingslashit($generation_dir).'styles';
        if (!wp_mkdir_p($styles_dir)) return [new WP_Error('bridge_style_dir','Could not create supplier style cache directory.'),0];
        $filename=$this->bridge_style_filename($style);
        $path=trailingslashit($styles_dir).$filename;
        $json=wp_json_encode($product, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if ($json===false || file_put_contents($path,$json,LOCK_EX)===false) return [new WP_Error('bridge_style_write','Could not save supplier style data.'),0];
        $relative=ltrim(str_replace(trailingslashit($this->bridge_dir()),'', $path),'/');
        return [$this->bridge_product_summary($product,$relative),count((array)($product['variants'] ?? []))];
    }

    private function publish_bridge_index(string $brand, array $summaries, array $meta=[]): array|WP_Error {
        $brand=$this->canonical_brand($brand);
        if ($brand==='') return new WP_Error('bridge_brand','Brand is required.');
        $dir=$this->bridge_dir();
        if (!wp_mkdir_p($dir)) return new WP_Error('bridge_dir','Could not create the supplier cache directory.');
        usort($summaries,static fn($a,$b)=>strnatcasecmp((string)($a['style']??''),(string)($b['style']??'')));
        $payload=[
            'supplier'=>'sanmar','brand'=>$brand,
            'received_at'=>$meta['received_at'] ?? current_time('mysql'),
            'source'=>$meta['source'] ?? 'bridge','storage'=>'per-style-v1',
            'products'=>array_values($summaries),
        ];
        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $file=$this->bridge_file($brand); $tmp=$file.'.tmp-'.wp_generate_password(8,false,false);
        if ($json===false || file_put_contents($tmp,$json,LOCK_EX)===false || !@rename($tmp,$file)) {
            @unlink($tmp);
            return new WP_Error('bridge_write','Could not atomically publish supplier brand index.');
        }

        $manifest=$this->bridge_manifest();
        foreach(array_keys($manifest) as $oldBrand) {
            if($oldBrand===$brand || strcasecmp((string)$oldBrand,$brand)!==0) continue;
            $oldFile=(string)($manifest[$oldBrand]['file']??''); unset($manifest[$oldBrand]);
            if($oldFile && $oldFile!==basename($file)) @unlink(trailingslashit($dir).basename($oldFile));
        }
        $variant_count=array_sum(array_map(static fn($x)=>(int)($x['variant_count']??0),$summaries));
        $manifest[$brand]=[
            'brand'=>$brand,'file'=>basename($file),'received_at'=>$payload['received_at'],
            'style_count'=>(int)($meta['style_count'] ?? count($summaries)),
            'variant_count'=>(int)($meta['variant_count'] ?? $variant_count),
            'source'=>$payload['source'],'source_file'=>sanitize_text_field((string)($meta['source_file']??'')),
            'storage'=>'per-style-v1','generation'=>sanitize_text_field((string)($meta['generation']??'')),
        ];
        uksort($manifest,'strnatcasecmp'); update_option('asss_bridge_manifest',$manifest,false);
        return $manifest[$brand];
    }

    private function cleanup_old_bridge_generations(string $brand, string $keep_generation): void {
        $root=dirname($this->bridge_generation_dir($brand,'placeholder'));
        if (!is_dir($root)) return;
        $dirs=glob(trailingslashit($root).'*',GLOB_ONLYDIR) ?: [];
        usort($dirs,static fn($a,$b)=>(@filemtime($b)?:0) <=> (@filemtime($a)?:0));
        $kept_previous=false;
        foreach ($dirs as $dir) {
            if (basename($dir)===$keep_generation) continue;
            // Retain one previous generation as a tiny rollback/race buffer. It
            // is removed after the next successful generation is published.
            if (!$kept_previous) { $kept_previous=true; continue; }
            $this->remove_bridge_tree($dir);
        }
    }

    private function remove_bridge_tree(string $dir): void {
        $base=realpath($this->bridge_dir()); $real=realpath($dir);
        if (!$base || !$real || strpos($real,$base)!==0 || !is_dir($real)) return;
        foreach (scandir($real) ?: [] as $name) {
            if ($name==='.' || $name==='..') continue;
            $path=$real.DIRECTORY_SEPARATOR.$name;
            if (is_dir($path)) $this->remove_bridge_tree($path); else @unlink($path);
        }
        @rmdir($real);
    }

    public function save_bridge_brand(string $brand, array $products, array $meta=[]) {
        $brand=$this->canonical_brand($brand);
        if ($brand==='') return new WP_Error('bridge_brand','Brand is required.');
        $generation='single-'.gmdate('YmdHis').'-'.substr(md5(wp_json_encode($meta).microtime(true)),0,8);
        $gen_dir=$this->bridge_generation_dir($brand,$generation);
        if (!wp_mkdir_p($gen_dir)) return new WP_Error('bridge_generation','Could not create supplier generation directory.');
        $summaries=[]; $variants=0;
        foreach($products as $product) {
            if(!is_array($product)) continue;
            [$summary,$count]=$this->write_bridge_product_detail($gen_dir,$product);
            if(is_wp_error($summary)) return $summary;
            if($summary){$summaries[]=$summary;$variants+=$count;}
        }
        if(!$summaries) return new WP_Error('bridge_products','No valid supplier styles were supplied.');
        $saved=$this->publish_bridge_index($brand,$summaries,array_merge($meta,[
            'generation'=>$generation,'style_count'=>count($summaries),'variant_count'=>$variants,
        ]));
        if(is_wp_error($saved)) return $saved;
        $this->cleanup_old_bridge_generations($brand,$generation);
        return $saved;
    }

    private function bridge_stage_dir(string $brand, string $batch_id): string {
        $safe_brand=sanitize_file_name(strtolower(trim($brand)));
        $safe_batch=preg_replace('/[^A-Za-z0-9._-]/','',$batch_id);
        return trailingslashit($this->bridge_dir()) . '.staging/' . $safe_brand . '/' . $safe_batch;
    }

    private function cleanup_stale_bridge_staging(string $brand): void {
        $root=dirname($this->bridge_stage_dir($brand,'placeholder'));
        if (!is_dir($root)) return;
        $cutoff=time()-(2*DAY_IN_SECONDS);
        foreach(glob(trailingslashit($root).'*',GLOB_ONLYDIR) ?: [] as $dir) {
            $mtime=@filemtime($dir) ?: 0;
            if($mtime && $mtime<$cutoff) $this->remove_bridge_tree($dir);
        }
    }

    /**
     * Store one product-data batch and atomically publish a lightweight brand
     * index only after every expected chunk is present. Full style/variation
     * payloads live in per-style generation files, so large brands do not need
     * to be JSON-decoded into one giant PHP array on shared hosting.
     */
    public function save_bridge_brand_chunk(string $brand, string $batch_id, int $index, int $count, array $products, array $meta=[]) {
        if ($count < 1 || $count > 500 || $index < 0 || $index >= $count) return new WP_Error('bridge_batch','Invalid batch index/count.');
        if (count($products) > 100) return new WP_Error('bridge_batch_size','A product-data chunk may contain at most 100 styles.');
        $batch_id=preg_replace('/[^A-Za-z0-9._-]/','',$batch_id);
        if ($batch_id==='') return new WP_Error('bridge_batch_id','Batch ID is required.');
        $this->cleanup_stale_bridge_staging($brand);
        $dir=$this->bridge_stage_dir($brand,$batch_id);
        if (!wp_mkdir_p($dir)) return new WP_Error('bridge_stage_dir','Could not create the supplier staging directory.');
        $chunk=['brand'=>$brand,'batch_id'=>$batch_id,'index'=>$index,'count'=>$count,'products'=>array_values($products),'meta'=>$meta];
        $json=wp_json_encode($chunk,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $path=trailingslashit($dir).sprintf('%04d.json',$index);
        if($json===false || file_put_contents($path,$json,LOCK_EX)===false) return new WP_Error('bridge_stage_write','Could not save supplier chunk.');

        for($i=0;$i<$count;$i++) if(!is_readable(trailingslashit($dir).sprintf('%04d.json',$i))) {
            return ['complete'=>false,'received_index'=>$index,'chunk_count'=>$count];
        }

        $brand=$this->canonical_brand($brand);
        $generation='batch-'.$batch_id;
        $gen_dir=$this->bridge_generation_dir($brand,$generation);
        if(!wp_mkdir_p($gen_dir)) return new WP_Error('bridge_generation','Could not create supplier generation directory.');
        $summaries=[]; $variant_count=0; $source_file='';
        for($i=0;$i<$count;$i++) {
            $part=json_decode((string)file_get_contents(trailingslashit($dir).sprintf('%04d.json',$i)),true);
            if(!is_array($part) || !isset($part['products']) || !is_array($part['products'])) return new WP_Error('bridge_stage_corrupt','A staged supplier chunk is invalid.');
            foreach($part['products'] as $product) {
                if(!is_array($product)) continue;
                [$summary,$vc]=$this->write_bridge_product_detail($gen_dir,$product);
                if(is_wp_error($summary)) return $summary;
                if($summary){$summaries[]=$summary;$variant_count+=$vc;}
            }
            if(!$source_file) $source_file=sanitize_text_field((string)($part['meta']['source_file']??''));
            unset($part);
        }
        $saved=$this->publish_bridge_index($brand,$summaries,[
            'source'=>'github-actions-batched','source_file'=>$source_file,'received_at'=>current_time('mysql'),
            'style_count'=>count($summaries),'variant_count'=>$variant_count,'generation'=>$generation,
        ]);
        if(is_wp_error($saved)) return $saved;
        foreach(glob(trailingslashit($dir).'*.json') ?: [] as $f) @unlink($f);
        @rmdir($dir);
        $this->cleanup_old_bridge_generations($brand,$generation);
        return ['complete'=>true,'styles'=>count($summaries),'variants'=>$variant_count,'manifest'=>$saved];
    }

    public function bridge_brand_products(string $brand): array {
        $file = $this->bridge_file($brand);
        if (!is_readable($file)) {
            // Brand tokens are case-sensitive on Linux filesystems; fall back to manifest mapping.
            foreach ($this->bridge_manifest() as $key=>$meta) {
                if (strcasecmp((string)$key, $brand) === 0) { $file = trailingslashit($this->bridge_dir()).$meta['file']; break; }
            }
        }
        if (!is_readable($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) return [];
        return isset($data['products']) && is_array($data['products']) ? $data['products'] : (array)$data;
    }

    private function bridge_detail_product(string $brand, array $entry): array {
        if (!empty($entry['variants']) && is_array($entry['variants'])) return $entry; // legacy/full cache
        $relative=trim((string)($entry['detail_file'] ?? ''));
        if ($relative==='') return [];
        $base=realpath($this->bridge_dir());
        $path=realpath(trailingslashit($this->bridge_dir()).ltrim($relative, '/\\'));
        if(!$base || !$path || strpos($path,$base)!==0 || !is_readable($path)) return [];
        $data=json_decode((string)file_get_contents($path),true);
        return is_array($data) ? $data : [];
    }

    private function bridge_manifest_for_brand(string $brand): array {
        foreach($this->bridge_manifest() as $known=>$meta) {
            if(strcasecmp((string)$known,$brand)===0) return is_array($meta)?$meta:[];
        }
        return [];
    }

    private function bridge_product_rows(array $product): array {
        $rows=[];
        $variants = !empty($product['variants']) && is_array($product['variants']) ? $product['variants'] : [[]];
        foreach ($variants as $v) {
            $images=(array)($product['images'] ?? []);
            $vimages=(array)($v['images'] ?? []);
            $rows[] = [
                'UNIQUE_KEY'=>(string)($v['unique_key'] ?? ''),
                'PRODUCT_TITLE'=>(string)($product['title'] ?? ''),
                'PRODUCT_DESCRIPTION'=>(string)($product['description'] ?? ''),
                'STYLE'=>(string)($product['style'] ?? ''),
                'AVAILABLE_SIZES'=>(string)($product['available_sizes_text'] ?? ''),
                'BRAND_LOGO_IMAGE'=>(string)($images['brand_logo'] ?? ''),
                'THUMBNAIL_IMAGE'=>(string)($images['thumbnail'] ?? ''),
                'COLOR_SWATCH_IMAGE'=>(string)($images['color_swatch'] ?? ''),
                'PRODUCT_IMAGE'=>(string)($images['product'] ?? ''),
                'SPEC_SHEET'=>(string)($images['spec_sheet'] ?? ''),
                'FRONT_FLAT'=>(string)($vimages['front_flat'] ?? ''),
                'BACK_FLAT'=>(string)($vimages['back_flat'] ?? ''),
                'FRONT_MODEL'=>(string)($vimages['front_model'] ?? ''),
                'BACK_MODEL'=>(string)($vimages['back_model'] ?? ''),
                'SIDE_MODEL'=>(string)($vimages['side_model'] ?? ''),
                'THREE_Q_MODEL'=>(string)($vimages['three_q_model'] ?? ''),
                'COLOR_NAME'=>(string)($v['color'] ?? ''),
                'COLOR_SQUARE_IMAGE'=>(string)($vimages['color_square'] ?? ''),
                'COLOR_PRODUCT_IMAGE'=>(string)($vimages['color_product'] ?? ''),
                'COLOR_PRODUCT_IMAGE_THUMBNAIL'=>(string)($vimages['color_product_thumbnail'] ?? ''),
                'SIZE'=>(string)($v['size'] ?? ''),
                'PIECE_WEIGHT'=>(string)($v['weight_lb'] ?? ''),
                'PIECE_PRICE'=>(string)($v['piece_price'] ?? ''),
                'CASE_PRICE'=>(string)($v['case_price'] ?? ''),
                'PIECE_SALE_PRICE'=>(string)($v['piece_sale_price'] ?? ''),
                'CASE_SALE_PRICE'=>(string)($v['case_sale_price'] ?? ''),
                'SALE_START_DATE'=>(string)($v['sale_start_date'] ?? ''),
                'SALE_END_DATE'=>(string)($v['sale_end_date'] ?? ''),
                'CASE_SIZE'=>(string)($v['case_size'] ?? ''),
                'INVENTORY_KEY'=>(string)($v['inventory_key'] ?? ''),
                'SIZE_INDEX'=>(string)($v['size_index'] ?? ''),
                'CATALOG_COLOR'=>(string)($v['catalog_color'] ?? ''),
                'PRICE_CODE'=>(string)($v['price_code'] ?? ''),
                'PRODUCT_STATUS'=>(string)($v['status'] ?? ($product['status'] ?? '')),
                'BRAND_NAME'=>(string)($product['brand'] ?? ''),
                'KEYWORDS'=>(string)($product['keywords'] ?? ''),
                'CATEGORY'=>(string)($product['category'] ?? ''),
                'MAP_PRICE'=>(string)($v['map_price'] ?? ''),
                // Explicit ordered gallery emitted by the GitHub normalizer.
                // Named image fields remain above for backwards compatibility
                // and direct/local supplier feed parsing.
                'VARIATION_GALLERY_URLS'=>wp_json_encode(array_values(array_filter((array)($v['gallery'] ?? [])))),
            ];
        }
        return $rows;
    }

    public function list_brand_files(): array {
        $out=[];
        foreach($this->bridge_manifest() as $brand=>$meta){
            $received=(string)($meta['received_at'] ?? '');
            $out[$brand]=['brand'=>$brand,'file'=>(string)($meta['file'] ?? ''),'timestamp'=>$received?strtotime($received):0,'date'=>$received?mysql2date('m-d-Y',$received):'Bridge cache','source'=>'github'];
        }
        if ($out) { uasort($out,fn($a,$b)=>strcasecmp($a['brand'],$b['brand'])); return $out; }
        $c=$this->connect(); if (is_wp_error($c)) return [];
        $dir=rtrim($this->settings()['ftp_base_dir'],'/');
        $list=$this->remote_list($c,$dir) ?: [];
        $this->close($c);
        foreach($list as $path){
            $file=basename($path);
            if (preg_match('/^Brand_(.+)_(\d{2}-\d{2}-\d{4})\.csv$/i',$file,$m)) {
                $brand=$m[1]; $ts=strtotime($m[2]); $key=strtolower($brand);
                if (!isset($out[$key]) || $ts>$out[$key]['timestamp']) {
                    $out[$key]=['brand'=>$brand,'file'=>$file,'timestamp'=>$ts,'date'=>$m[2]];
                }
            }
        }
        uasort($out,fn($a,$b)=>strcasecmp($a['brand'],$b['brand']));
        return $out;
    }

    public function latest_brand_file(string $brand): ?array {
        $files=$this->list_brand_files();
        foreach($files as $key=>$row) if (strcasecmp($key,$brand)===0 || strcasecmp($row['brand'],$brand)===0) return $row;
        return null;
    }

    public function download_remote(string $remote, string $prefix='asss-') {
        $c=$this->connect(); if (is_wp_error($c)) return $c;
        $tmp=wp_tempnam($prefix.basename($remote));
        if (!$tmp) { $this->close($c); return new WP_Error('tmp','Could not create a temporary file.'); }
        $ok=$this->remote_download($c,$remote,$tmp);
        $this->close($c);
        if (!$ok) { @unlink($tmp); return new WP_Error('transfer_get','Could not download '.basename($remote).' over '.strtoupper($this->protocol()).'.'.($this->last_transfer_error ? ' '.$this->last_transfer_error : '')); }
        return $tmp;
    }

    public function get_brand_csv(string $brand) {
        $latest=$this->latest_brand_file($brand);
        if (!$latest) return new WP_Error('brand_file','No Brand_'.$brand.'_*.csv file was found.');
        $remote=rtrim($this->settings()['ftp_base_dir'],'/').'/'.$latest['file'];
        $tmp=$this->download_remote($remote,'asss-brand-');
        if (is_wp_error($tmp)) return $tmp;
        return ['path'=>$tmp,'meta'=>$latest];
    }

    public function csv_headers(string $file): array {
        $h=fopen($file,'r'); if(!$h) return [];
        $headers=fgetcsv($h); fclose($h);
        if(!$headers) return [];
        return array_map([$this,'normalize_header'],$headers);
    }

    public function normalize_header($h): string {
        $h=preg_replace('/^\xEF\xBB\xBF/','',(string)$h);
        $h=strtoupper(trim($h));
        $h=preg_replace('/[^A-Z0-9]+/','_',$h);
        return trim($h,'_');
    }

    public function iterate_csv(string $file): Generator {
        $h=fopen($file,'r'); if(!$h) return;
        $raw=fgetcsv($h); if(!$raw){fclose($h);return;}
        $headers=array_map([$this,'normalize_header'],$raw);
        while(($row=fgetcsv($h))!==false){
            if(count($row)<count($headers)) $row=array_pad($row,count($headers),'');
            if(count($row)>count($headers)) $row=array_slice($row,0,count($headers));
            yield array_combine($headers,$row);
        }
        fclose($h);
    }

    public function first(array $row, array $keys, $default='') {
        foreach($keys as $k){ $k=$this->normalize_header($k); if(isset($row[$k]) && $row[$k] !== '') return $row[$k]; }
        return $default;
    }

    public function style_summaries(string $brand, string $search=''): array {
        $cached=$this->bridge_brand_products($brand);
        if($cached){
            $needle=strtolower(trim($search));$out=[];
            foreach($cached as $product){
                $style=(string)($product['style']??'');$title=(string)($product['title']??$style);
                if($needle && strpos(strtolower($style.' '.$title),$needle)===false)continue;
                if(isset($product['color_count']) || isset($product['size_count'])) {
                    $color_count=absint($product['color_count'] ?? 0); $size_count=absint($product['size_count'] ?? 0);
                } else {
                    $colors=[];$sizes=[];foreach((array)($product['variants']??[]) as $v){if(!empty($v['color']))$colors[(string)$v['color']]=1;if(!empty($v['size']))$sizes[(string)$v['size']]=1;}
                    $color_count=count($colors); $size_count=count($sizes);
                }
                $image = trim((string)($product['representative_image'] ?? ''));
                if ($image === '' && !empty($product['detail_file'])) {
                    $detail = $this->bridge_detail_product($brand, $product);
                    if (is_array($detail)) $image = $this->representative_product_image($detail);
                }
                if ($image === '') $image = (string)(($product['images']['thumbnail']??'')?:($product['images']['product']??''));
                $out[]=['style'=>$style,'title'=>$title,'brand'=>(string)($product['brand']??$brand),'category'=>(string)($product['category']??''),'image'=>$image,'color_count'=>$color_count,'size_count'=>$size_count,'status'=>(string)($product['status']??''),'variant_count'=>absint($product['variant_count'] ?? count((array)($product['variants']??[])))];
                if(count($out)>=200)break;
            }
            return $out;
        }
        $data=$this->get_brand_csv($brand); if(is_wp_error($data)) return [];
        $styles=[]; $needle=strtolower(trim($search));
        foreach($this->iterate_csv($data['path']) as $row){
            $style=(string)$this->first($row,['STYLE#','STYLE','PRODUCT_ID']);
            if(!$style) continue;
            $title=(string)$this->first($row,['PRODUCT_TITLE','PRODUCT_NAME','TITLE'],$style);
            if($needle && strpos(strtolower($style.' '.$title),$needle)===false) continue;
            if(!isset($styles[$style])) $styles[$style]=['style'=>$style,'title'=>$title,'brand'=>(string)$this->first($row,['BRAND_NAME','BRAND'],$brand),'category'=>(string)$this->first($row,['CATEGORY']),'image'=>(string)$this->first($row,['COLOR_PRODUCT_IMAGE','FRONT_FLAT','COLOR_PRODUCT_IMAGE_THUMBNAIL','PRODUCT_IMAGE','THUMBNAIL_IMAGE']),'colors'=>[],'sizes'=>[],'status'=>(string)$this->first($row,['PRODUCT_STATUS','STATUS'])];
            $color=(string)$this->first($row,['COLOR_NAME','COLOR','CATALOG_COLOR']); if($color) $styles[$style]['colors'][$color]=1;
            $size=(string)$this->first($row,['SIZE']); if($size) $styles[$style]['sizes'][$size]=1;
        }
        @unlink($data['path']);
        foreach($styles as &$item){$item['color_count']=count($item['colors']);$item['size_count']=count($item['sizes']);unset($item['colors'],$item['sizes']);}
        return array_slice(array_values($styles),0,200);
    }

    public function rows_for_style(string $brand,string $style) {
        $cached=$this->bridge_brand_products($brand);
        if($cached){
            foreach($cached as $entry){
                if(strcasecmp((string)($entry['style']??''),$style)===0){
                    $product=$this->bridge_detail_product($brand,$entry);
                    if(!$product) return new WP_Error('style_cache_missing','The supplier style detail cache is missing or unreadable for '.$brand.' '.$style.'. Run the product-data sync again.');
                    $meta=$this->bridge_manifest_for_brand($brand);
                    return ['rows'=>$this->bridge_product_rows($product),'meta'=>['file'=>(string)($meta['source_file']??$meta['file']??('GitHub bridge: '.$brand)),'date'=>(string)($meta['received_at']??'')]];
                }
            }
            return new WP_Error('style_not_found','Style '.$style.' was not found in the latest GitHub supplier data for '.$brand.'.');
        }
        $data=$this->get_brand_csv($brand); if(is_wp_error($data)) return $data;
        $rows=[];
        foreach($this->iterate_csv($data['path']) as $row){$rstyle=(string)$this->first($row,['STYLE#','STYLE','PRODUCT_ID']);if(strcasecmp($rstyle,$style)===0)$rows[]=$row;}
        @unlink($data['path']);
        if(!$rows)return new WP_Error('style_not_found','Style '.$style.' was not found in the latest '.$brand.' file.');
        return ['rows'=>$rows,'meta'=>$data['meta']];
    }

    public function request_brand_file(string $brand) {
        $s=$this->settings();
        if(!$s['ws_customer']||!$s['ws_user']||!$s['ws_pass']) return new WP_Error('ws_credentials','Web Services credentials are incomplete.');
        $endpoint='https://ws.sanmar.com:8080/SanMarWebService/SanMarProductInfoServicePort';
        $xml='<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:impl="http://impl.webservice.integration.sanmar.com/">'
            .'<soapenv:Header/><soapenv:Body><impl:getProductInfoByBrand><arg0><brandName>'.esc_html($brand).'</brandName></arg0><arg1>'
            .'<sanMarCustomerNumber>'.esc_html($s['ws_customer']).'</sanMarCustomerNumber>'
            .'<sanMarUserName>'.esc_html($s['ws_user']).'</sanMarUserName>'
            .'<sanMarUserPassword>'.esc_html($s['ws_pass']).'</sanMarUserPassword>'
            .'</arg1></impl:getProductInfoByBrand></soapenv:Body></soapenv:Envelope>';
        $r=wp_remote_post($endpoint,['timeout'=>30,'headers'=>['Content-Type'=>'text/xml; charset=utf-8','SOAPAction'=>''], 'body'=>$xml]);
        if(is_wp_error($r)) return $r;
        $code=wp_remote_retrieve_response_code($r); $body=wp_remote_retrieve_body($r);
        if($code<200||$code>=300) return new WP_Error('ws_http','SanMar returned HTTP '.$code.'.');
        if(stripos($body,'errorOccured>true')!==false || stripos($body,'errorOccurred>true')!==false) return new WP_Error('ws_error','SanMar reported an error while requesting the brand file.');
        return true;
    }

    public function locate_inventory_path() {
        $s=$this->settings(); if(!empty($s['inventory_path'])) return $s['inventory_path'];
        $c=$this->connect(); if(is_wp_error($c)) return $c;
        $candidates=['/SanMarPDD/sanmar_dip.txt','/SanMarPDD/SanMarPI/sanmar_dip.txt','/sanmar_dip.txt','/SanMarPDD/sanmar_epdd.csv','/SanMarPDD/SanMar_EPDD.csv'];
        foreach($candidates as $p){ $size=$this->remote_size($c,$p); if($size>=0){$this->close($c);return $p;} }
        $this->close($c);
        return new WP_Error('inventory_missing','Could not auto-detect sanmar_dip.txt. Enter its remote path in Settings.');
    }

    public function inventory_file() {
        $path=$this->locate_inventory_path(); if(is_wp_error($path)) return $path;
        $tmp=$this->download_remote($path,'asss-inventory-'); if(is_wp_error($tmp)) return $tmp;
        return ['path'=>$tmp,'remote'=>$path,'headers'=>$this->csv_headers($tmp)];
    }

    /** v2.0.24: lightweight WordPress-side queue for GitHub image normalization. */
    private function storefront_image_queue(): array {
        $rows = get_option('asss_sanmar_storefront_image_queue_v1', []);
        return is_array($rows) ? $rows : [];
    }

    private function save_storefront_image_queue(array $rows): void {
        // Bound the queue so a broken remote image can never grow wp_options forever.
        if (count($rows) > 2000) {
            uasort($rows, static fn($a,$b) => strcmp((string)($a['updated_at'] ?? ''), (string)($b['updated_at'] ?? '')));
            $rows = array_slice($rows, -2000, null, true);
        }
        update_option('asss_sanmar_storefront_image_queue_v1', $rows, false);
    }

    public function queue_storefront_image_request(int $product_id, string $brand, string $style, string $source_url, string $normalizer_version='sanmar-square-v1', string $role='featured'): string {
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

}
