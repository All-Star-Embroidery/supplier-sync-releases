<?php
if (!defined('ABSPATH')) exit;

class ASSS_Admin {
    private ASSS_SanMar $sanmar;
    private ASSS_SS $ss;
    private ASSS_Momentec $momentec;
    private ASSS_Importer $importer;
    private ASSS_Sync $sync;
    private ASSS_MultiSupplier $multi;
    private ASSS_Updater $updater;

    public function __construct($s, $ss, $momentec, $i, $y, $m, $u) {
        $this->sanmar = $s;
        $this->ss = $ss;
        $this->momentec = $momentec;
        $this->importer = $i;
        $this->sync = $y;
        $this->multi = $m;
        $this->updater = $u;
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'actions']);
        add_action('add_meta_boxes', [$this, 'meta_box']);
        add_action('admin_notices', [$this, 'product_import_notice']);
    }

    public function menu(): void {
        add_menu_page('Suppliers', 'Suppliers', 'manage_woocommerce', 'asss-suppliers', [$this, 'add_products'], 'dashicons-update', 56);
        add_submenu_page('asss-suppliers', 'Add Products', 'Add Products', 'manage_woocommerce', 'asss-suppliers', [$this, 'add_products']);
        add_submenu_page('asss-suppliers', 'Active Products', 'Active Products', 'manage_woocommerce', 'asss-active-products', [$this, 'active_products_page']);
        add_submenu_page(null, 'Import Product', 'Import Product', 'manage_woocommerce', 'asss-import', [$this, 'import_page']);
        add_submenu_page(null, 'Review S&S Product', 'Review S&S Product', 'manage_woocommerce', 'asss-ss-review', [$this, 'ss_review_page']);
        add_submenu_page('asss-suppliers', 'Brands', 'Brands', 'manage_woocommerce', 'asss-brands', [$this, 'brands_page']);
        add_submenu_page('asss-suppliers', 'Sync', 'Sync', 'manage_woocommerce', 'asss-sync', [$this, 'sync_page']);
        add_submenu_page('asss-suppliers', 'Supplier Intelligence', 'Supplier Intelligence', 'manage_woocommerce', 'asss-intelligence', [$this, 'intelligence_page']);
        add_submenu_page(null, 'Manage Product Suppliers', 'Manage Product Suppliers', 'manage_woocommerce', 'asss-manage-suppliers', [$this, 'manage_suppliers_page']);
        add_submenu_page('asss-suppliers', 'System Status', 'System Status', 'manage_woocommerce', 'asss-status', [$this, 'status_page']);
        add_submenu_page('asss-suppliers', 'Logs', 'Logs', 'manage_woocommerce', 'asss-logs', [$this, 'logs']);
        add_submenu_page('asss-suppliers', 'Settings', 'Settings', 'manage_woocommerce', 'asss-settings', [$this, 'settings']);
    }

    private function notice(): void {
        if (!empty($_GET['asss_msg'])) echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash($_GET['asss_msg'])) . '</p></div>';
        if (!empty($_GET['asss_err'])) echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['asss_err'])) . '</p></div>';
    }

    public function product_import_notice(): void {
        if (empty($_GET['asss_imported']) || empty($_GET['post'])) return;
        $post_id = absint($_GET['post']);
        if (!$post_id || get_post_type($post_id) !== 'product') return;
        echo '<div class="notice notice-success is-dismissible"><p><strong>Supplier import complete.</strong> Product structure, attributes, exact variations, taxonomy data, supplier images, supplier metadata, and Supplier Sync-managed pricing were created. Review the result before publishing; manual price edits will be preserved on future syncs.</p></div>';
    }

    public function actions(): void {
        if (!current_user_can('manage_woocommerce')) return;

        if (!empty($_POST['asss_save_settings'])) {
            check_admin_referer('asss_settings');
            $old = $this->sanmar->settings();
            $keys = ['automatic_product_bridge','transfer_protocol','ftp_host','ftp_port','ftp_passive','ftp_user','ftp_pass','ftp_base_dir','inventory_path','ws_customer','ws_user','ws_pass','request_brand_files','stock_buffer','sync_images','sync_description','sync_new_variations','sync_variation_base_prices','daily_product_sync','hourly_inventory_sync','bridge_inventory_enabled','admin_notify','bridge_enabled','sync_bulk_order_fields','auto_enable_bulk_order','fallback_length_in','fallback_width_in','fallback_height_in','multi_inventory_strategy','supplier_priority','supplier_intelligence_enabled','github_update_repo','github_auto_updates','momentec_enabled','momentec_api_base','momentec_account','momentec_environment'];
            $new = $old;
            foreach ($keys as $k) $new[$k] = isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : 0;
            foreach (['momentec_username','momentec_password','momentec_api_key','momentec_secret'] as $legacy_key) unset($new[$legacy_key]);
            update_option('asss_settings', $new, false);
            $this->redir('asss-settings', 'Settings saved.');
        }

        if (!empty($_POST['asss_regen_bridge'])) {
            check_admin_referer('asss_settings');
            $new = $this->sanmar->settings();
            $new['bridge_token'] = wp_generate_password(48, false, false);
            $new['bridge_enabled'] = 1;
            update_option('asss_settings', $new, false);
            $this->redir('asss-settings', 'New GitHub bridge token generated. Update the ASSS_BRIDGE_TOKEN secret in GitHub before the next sync.');
        }


        if (!empty($_POST['asss_check_updates'])) {
            check_admin_referer('asss_settings');
            $this->updater->clear_cache();
            $release = $this->updater->latest_release(true);
            delete_site_transient('update_plugins');
            if (is_wp_error($release)) $this->redir('asss-settings', '', 'GitHub update check failed: ' . $release->get_error_message());
            $message = version_compare((string)$release['version'], ASSS_VERSION, '>')
                ? 'GitHub update available: v' . (string)$release['version'] . '. Open Dashboard → Updates or Plugins to install it.'
                : 'All Star Supplier Sync is up to date. Current release: v' . ASSS_VERSION . '.';
            $this->redir('asss-settings', $message);
        }

        if (!empty($_POST['asss_test_ftp'])) {
            check_admin_referer('asss_settings');
            $r = $this->sanmar->test_connection();
            is_wp_error($r) ? $this->redir('asss-settings', '', $r->get_error_message()) : $this->redir('asss-settings', $r['protocol'] . ' connected. Found ' . $r['count'] . ' items in ' . $r['directory'] . '.');
        }

        if (!empty($_POST['asss_test_inventory'])) {
            check_admin_referer('asss_settings');
            $r = $this->sanmar->inventory_file();
            if (is_wp_error($r)) $this->redir('asss-settings', '', $r->get_error_message());
            @unlink($r['path']);
            $this->redir('asss-settings', 'Inventory file found: ' . $r['remote'] . ' | Headers: ' . implode(', ', array_slice($r['headers'], 0, 12)));
        }

        if (!empty($_POST['asss_save_brands'])) {
            check_admin_referer('asss_brands');
            $supplier = sanitize_key((string)($_POST['supplier'] ?? 'sanmar'));
            if ($supplier === 'ss') {
                $enabled = array_values(array_filter(array_map('absint', (array)($_POST['enabled_brands'] ?? []))));
                $this->ss->set_enabled_brands($enabled);
                wp_safe_redirect(add_query_arg(['page'=>'asss-brands','supplier'=>'ss','asss_msg'=>'S&S brand selections saved.'], admin_url('admin.php')));
                exit;
            }
            $enabled=array_values(array_filter(array_map('sanitize_text_field',(array)($_POST['enabled_brands'] ?? []))));
            $this->sanmar->set_enabled_brands($enabled);
            wp_safe_redirect(add_query_arg(['page'=>'asss-brands','supplier'=>'sanmar','asss_msg'=>'SanMar brand selections saved. Automatic product sync will only process enabled brands.'], admin_url('admin.php')));
            exit;
        }

        if (!empty($_POST['asss_import_style'])) {
            check_admin_referer('asss_import');
            $brand = sanitize_text_field(wp_unslash($_POST['brand'] ?? ''));
            $style = sanitize_text_field(wp_unslash($_POST['style'] ?? ''));
            $colors = array_values(array_filter(array_map('sanitize_text_field', (array)($_POST['colors'] ?? []))));
            if (!$colors) {
                $this->redir_import($brand, $style, '', 'Choose at least one color before importing.');
            }
            $r = $this->importer->import_style($brand, $style, $colors);
            if (is_wp_error($r)) $this->redir_import($brand, $style, '', $r->get_error_message());
            wp_safe_redirect(add_query_arg(['post' => (int)$r, 'action' => 'edit', 'asss_imported' => 1], admin_url('post.php')));
            exit;
        }

        if (!empty($_POST['asss_import_ss_style'])) {
            check_admin_referer('asss_ss_import');
            $brand_id = absint($_POST['brand_id'] ?? 0);
            $style_id = absint($_POST['style_id'] ?? 0);
            $colors = array_values(array_filter(array_map('sanitize_text_field', (array)($_POST['colors'] ?? []))));
            if (!$colors) $this->redir_ss_review($brand_id, $style_id, '', 'Choose at least one color before importing.');
            $r = $this->importer->import_ss_style($brand_id, $style_id, $colors);
            if (is_wp_error($r)) $this->redir_ss_review($brand_id, $style_id, '', $r->get_error_message());
            wp_safe_redirect(add_query_arg(['post'=>(int)$r,'action'=>'edit','asss_imported'=>1], admin_url('post.php')));
            exit;
        }

        if (!empty($_POST['asss_link_ss_existing'])) {
            check_admin_referer('asss_ss_import');
            $brand_id=absint($_POST['brand_id'] ?? 0); $style_id=absint($_POST['style_id'] ?? 0); $product_id=absint($_POST['product_id'] ?? 0);
            $colors=array_values(array_filter(array_map('sanitize_text_field',(array)($_POST['colors'] ?? []))));
            $r=$this->importer->link_ss_style_to_product($product_id,$brand_id,$style_id,$colors);
            if(is_wp_error($r))$this->redir_ss_review($brand_id,$style_id,'',$r->get_error_message());
            wp_safe_redirect(add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>(int)$r,'asss_msg'=>'S&S linked to the existing WooCommerce product.'],admin_url('admin.php'))); exit;
        }

        if (!empty($_POST['asss_link_sanmar_existing'])) {
            check_admin_referer('asss_import');
            $brand=sanitize_text_field(wp_unslash($_POST['brand'] ?? '')); $style=sanitize_text_field(wp_unslash($_POST['style'] ?? '')); $product_id=absint($_POST['product_id'] ?? 0);
            $colors=array_values(array_filter(array_map('sanitize_text_field',(array)($_POST['colors'] ?? []))));
            $r=$this->importer->link_sanmar_style_to_product($product_id,$brand,$style,$colors);
            if(is_wp_error($r))$this->redir_import($brand,$style,'',$r->get_error_message());
            wp_safe_redirect(add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>(int)$r,'asss_msg'=>'SanMar linked to the existing WooCommerce product.'],admin_url('admin.php'))); exit;
        }

        if (!empty($_POST['asss_save_product_supplier_settings'])) {
            check_admin_referer('asss_manage_suppliers');
            $product_id=absint($_POST['product_id'] ?? 0);
            if(!$product_id || get_post_type($product_id)!=='product')$this->redir('asss-intelligence','','Invalid product.');
            $strategy=sanitize_key((string)($_POST['inventory_strategy'] ?? ''));
            if(!in_array($strategy,['inherit','combined','preferred'],true))$strategy='inherit';
            update_post_meta($product_id,'_asss_inventory_strategy_override',$strategy);
            $preferred=sanitize_key((string)($_POST['preferred_supplier'] ?? ''));
            if(!in_array($preferred,['','ss','sanmar','momentec'],true))$preferred='';
            update_post_meta($product_id,'_asss_preferred_supplier',$preferred);
            $this->multi->recalculate_product_inventory($product_id);
            $this->importer->sync_managed_pricing_for_product($product_id);
            wp_safe_redirect(add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>$product_id,'asss_msg'=>'Supplier preferences saved and effective inventory recalculated.'],admin_url('admin.php')));exit;
        }

        if (!empty($_POST['asss_disconnect_product_supplier'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
            if (!wp_verify_nonce($nonce, 'asss_manage_suppliers') && !wp_verify_nonce($nonce, 'asss_active_products')) wp_die('Invalid Supplier Sync request.');
            $encoded = sanitize_text_field(wp_unslash((string)($_POST['asss_disconnect_product_supplier'] ?? '')));
            $product_id=absint($_POST['product_id'] ?? 0);
            $supplier=sanitize_key((string)($_POST['disconnect_supplier'] ?? ''));
            if ((!$product_id || !$supplier) && strpos($encoded, '|') !== false) {
                [$raw_id, $raw_supplier] = array_pad(explode('|', $encoded, 2), 2, '');
                $product_id = absint($raw_id);
                $supplier = sanitize_key($raw_supplier);
            }
            if(!$product_id || !in_array($supplier,['sanmar','ss','momentec'],true)) $this->redir('asss-intelligence','','Invalid product or supplier.');
            if(!$this->multi->remove_product_source($product_id,$supplier,'administrator-disconnected-source')) {
                $this->redir('asss-active-products','','That supplier is not currently active on the selected product.');
            }
            $remaining = array_filter($this->multi->product_sources($product_id), static fn($v)=>is_array($v)&&!empty($v['enabled']));
            if ($remaining) $this->importer->sync_managed_pricing_for_product($product_id);
            $message = (ASSS_MultiSupplier::suppliers()[$supplier] ?? $supplier) . ' disconnected. Supplier-only variations were removed from the active storefront; shared variations and the WooCommerce product were preserved.';
            $return = sanitize_key((string)($_POST['disconnect_return'] ?? ''));
            if ($return === 'active') $this->redir('asss-active-products', $message);
            if ($return === 'sanmar_add') {
                wp_safe_redirect(add_query_arg(['page'=>'asss-suppliers','supplier'=>'sanmar','brand'=>sanitize_text_field(wp_unslash($_POST['return_brand'] ?? '')),'asss_msg'=>$message],admin_url('admin.php'))); exit;
            }
            if ($return === 'ss_add') {
                wp_safe_redirect(add_query_arg(['page'=>'asss-suppliers','supplier'=>'ss','brand_id'=>absint($_POST['return_brand_id'] ?? 0),'asss_msg'=>$message],admin_url('admin.php'))); exit;
            }
            if (!$remaining) $this->redir('asss-active-products', $message);
            wp_safe_redirect(add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>$product_id,'asss_msg'=>$message],admin_url('admin.php'))); exit;
        }

        if (!empty($_POST['asss_quick_repair_product'])) {
            check_admin_referer('asss_active_products');
            $product_id = absint($_POST['product_id'] ?? 0);
            if (!$product_id || !$this->is_active_linked_product($product_id)) $this->redir('asss-active-products', '', 'Choose a valid active linked product.');
            $r = $this->importer->update_style($product_id);
            if (is_wp_error($r)) $this->redir('asss-active-products', '', 'Quick repair failed: ' . $r->get_error_message());
            $this->redir('asss-active-products', 'Quick repair finished for product #' . $product_id . '. Existing color selections were preserved.');
        }

        if (!empty($_POST['asss_repair_selected_products'])) {
            check_admin_referer('asss_active_products');
            $ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['product_ids'] ?? [])))));
            $ids = array_values(array_filter($ids, fn($product_id) => $this->is_active_linked_product((int)$product_id)));
            if (!$ids) $this->redir('asss-active-products', '', 'Select at least one active product to repair.');
            $result = $this->sync->queue_product_repairs($ids);
            $this->redir('asss-active-products', 'Bulk repair queued: ' . (int)$result['queued'] . ' product(s) scheduled, ' . (int)$result['skipped'] . ' already queued or skipped. Existing color selections are preserved. You can leave this page while repairs run.');
        }

        if (!empty($_POST['asss_sync_products'])) {
            check_admin_referer('asss_sync');
            $r = $this->sync->product_sync();
            $this->redir('asss-sync', 'Product sync finished: ' . $r['ok'] . ' updated, ' . $r['fail'] . ' failed.');
        }

        if (!empty($_POST['asss_sync_inventory'])) {
            check_admin_referer('asss_sync');
            $r = $this->sync->inventory_sync();
            $this->redir('asss-sync', 'Inventory sync finished: ' . $r['ok'] . ' matched rows.');
        }
    }

    private function redir($page, $msg = '', $err = ''): void {
        wp_safe_redirect(add_query_arg(array_filter(['page' => $page, 'asss_msg' => $msg, 'asss_err' => $err]), admin_url('admin.php')));
        exit;
    }

    private function redir_import(string $brand, string $style, string $msg = '', string $err = ''): void {
        wp_safe_redirect(add_query_arg(array_filter([
            'page' => 'asss-import',
            'brand' => $brand,
            'style' => $style,
            'asss_msg' => $msg,
            'asss_err' => $err,
        ]), admin_url('admin.php')));
        exit;
    }

    private function redir_ss_review(int $brand_id, int $style_id, string $msg = '', string $err = ''): void {
        wp_safe_redirect(add_query_arg(array_filter([
            'page'=>'asss-ss-review','brand_id'=>$brand_id,'style_id'=>$style_id,'asss_msg'=>$msg,'asss_err'=>$err,
        ]), admin_url('admin.php')));
        exit;
    }

    public function add_products(): void {
        $supplier = sanitize_key((string)($_GET['supplier'] ?? 'sanmar'));
        if (!in_array($supplier, ['sanmar','ss','momentec'], true)) $supplier = 'sanmar';
        $page_title = $supplier === 'ss' ? 'Add S&S Activewear Products' : ($supplier === 'momentec' ? 'Add Momentec Products' : 'Add SanMar Products');
        $this->wrap_start($page_title);
        $this->notice();

        $base = admin_url('admin.php?page=asss-suppliers');
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
        echo '<a class="nav-tab ' . ($supplier === 'sanmar' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('supplier','sanmar',$base)) . '">SanMar</a>';
        echo '<a class="nav-tab ' . ($supplier === 'ss' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('supplier','ss',$base)) . '">S&amp;S Activewear</a>';
        echo '<a class="nav-tab ' . ($supplier === 'momentec' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('supplier','momentec',$base)) . '">Momentec Brands</a>';
        echo '</nav>';

        if ($supplier === 'momentec') {
            $status = $this->momentec->status();
            echo '<div class="notice notice-info inline"><p><strong>Momentec adapter groundwork is installed.</strong> Live catalog browsing stays off until All Star receives Momentec API credentials and we confirm the exact staging authentication/endpoints. Current state: <code>' . esc_html((string)$status['state']) . '</code>.</p></div>';
            echo '<p>When credentials arrive, this tab will use the same safe flow as S&amp;S: enabled brands → cached catalog → exact supplier Color+Size rows → review → intentional WooCommerce import/link.</p>';
            $this->wrap_end(); return;
        }

        if ($supplier === 'ss') {
            $enabled = $this->ss->enabled_brand_catalog();
            if (!$enabled) {
                echo '<div class="notice notice-info inline"><p>No S&amp;S brands are enabled yet. Open <a href="' . esc_url(admin_url('admin.php?page=asss-brands&supplier=ss')) . '"><strong>Suppliers → Brands → S&amp;S Activewear</strong></a>, enable the brands you want, then run <strong>S&amp;S Product Catalog Sync</strong> in GitHub Actions.</p></div>';
                $this->wrap_end(); return;
            }

            $brand_id = absint($_GET['brand_id'] ?? 0);
            if ($brand_id && !isset($enabled[(string)$brand_id])) $brand_id = 0;
            $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
            $category = sanitize_text_field(wp_unslash($_GET['category'] ?? ''));

            echo '<p>Browse the cached S&amp;S catalog here. Open a style to review its exact supplier colors/sizes and intentionally import only the colors you want.</p>';
            echo '<form method="get"><input type="hidden" name="page" value="asss-suppliers"><input type="hidden" name="supplier" value="ss">';
            echo '<table class="form-table" style="max-width:900px"><tr><th style="width:150px">Brand</th><td><select name="brand_id"><option value="">Choose a brand…</option>';
            foreach ($enabled as $id=>$meta) {
                echo '<option ' . selected($brand_id, (int)$id, false) . ' value="' . (int)$id . '">' . esc_html((string)($meta['brand'] ?? '')) . '</option>';
            }
            echo '</select></td></tr>';
            if ($brand_id) {
                $cats = $this->ss->available_categories($brand_id);
                echo '<tr><th>Category</th><td><select name="category"><option value="">All categories</option>';
                foreach ($cats as $cat) echo '<option ' . selected($category, $cat, false) . ' value="' . esc_attr($cat) . '">' . esc_html($cat) . '</option>';
                echo '</select></td></tr>';
            }
            echo '<tr><th>Search</th><td><input class="regular-text" name="q" value="' . esc_attr($q) . '" placeholder="Style, title, or category"> <button class="button button-primary">Browse Catalog</button></td></tr></table></form>';

            if ($brand_id) {
                $brand_meta = $enabled[(string)$brand_id] ?? [];
                $styles = $this->ss->style_summaries($brand_id, $q, $category);
                $manifest = $this->ss->style_manifest();
                $meta = $manifest[(string)$brand_id] ?? [];
                if (!$styles && empty($meta)) {
                    echo '<div class="notice notice-warning inline"><p><strong>' . esc_html((string)($brand_meta['brand'] ?? 'This brand')) . '</strong> is enabled, but its product catalog has not reached WordPress yet. Run <strong>S&amp;S Product Catalog Sync</strong> in GitHub Actions.</p></div>';
                    $this->wrap_end(); return;
                }
                echo '<p><strong>' . count($styles) . '</strong> matching styles shown. Cached catalog: <strong>' . (int)($meta['style_count'] ?? count($styles)) . '</strong> styles / <strong>' . (int)($meta['variant_count'] ?? 0) . '</strong> exact supplier variations. Last received: ' . esc_html((string)($meta['received_at'] ?? '—')) . '.</p>';
                echo '<style>.asss-catalog-table{width:auto;min-width:980px;max-width:100%;border-collapse:collapse}.asss-catalog-table th,.asss-catalog-table td{text-align:left;vertical-align:middle;padding:10px 12px;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7}.asss-catalog-table th:last-child,.asss-catalog-table td:last-child{border-right:0}.asss-catalog-table .asss-prod{min-width:310px}.asss-catalog-table .asss-num{width:75px;white-space:nowrap}</style>';
                echo '<table class="widefat striped asss-catalog-table"><thead><tr><th class="asss-prod">Product</th><th>Style</th><th>Categories</th><th class="asss-num">Colors</th><th class="asss-num">Sizes</th><th class="asss-num">Real Variations</th><th>Flags</th><th>Action</th></tr></thead><tbody>';
                foreach ($styles as $row) {
                    $style_id = absint($row['style_id'] ?? 0);
                    $style = (string)($row['style'] ?? '');
                    $image = (string)($row['image'] ?? '');
                    $categories = implode(', ', (array)($row['categories'] ?? []));
                    $review = add_query_arg(['page'=>'asss-ss-review','brand_id'=>$brand_id,'style_id'=>$style_id], admin_url('admin.php'));
                    $existing_ss = $this->importer->find_ss_product($style_id, $brand_id);
                    $possible_existing = $this->importer->find_product($style, (string)($brand_meta['brand'] ?? ''));
                    $flags = [];
                    if (!empty($row['new_style'])) $flags[] = 'New';
                    if (!empty($row['sustainable_style'])) $flags[] = 'Sustainable';
                    if ($existing_ss) $flags[] = 'S&S product #' . $existing_ss . ' active';
                    elseif ($possible_existing) $flags[] = 'SanMar product #' . $possible_existing . ' may match';
                    echo '<tr><td class="asss-prod">';
                    if ($image) echo '<img src="' . esc_url($this->ss_image_url($image)) . '" alt="" onerror="this.style.display=&quot;none&quot;" style="width:54px;height:54px;object-fit:contain;background:#fff;border:1px solid #e2e4e7;border-radius:4px;vertical-align:middle;margin-right:10px">';
                    echo '<span style="display:inline-block;vertical-align:middle"><strong>' . esc_html((string)($row['title'] ?? $style)) . '</strong><br><small>S&amp;S Style ID ' . $style_id . '</small></span></td>';
                    echo '<td><code>' . esc_html($style) . '</code></td><td style="max-width:320px">' . esc_html($categories ?: '—') . '</td>';
                    echo '<td class="asss-num">' . (int)($row['color_count'] ?? 0) . '</td><td class="asss-num">' . (int)($row['size_count'] ?? 0) . '</td><td class="asss-num"><strong>' . (int)($row['variant_count'] ?? 0) . '</strong></td>';
                    echo '<td>' . esc_html($flags ? implode(' • ', $flags) : '—') . '</td><td>';
                    if ($existing_ss) echo '<a class="button" href="' . esc_url(get_edit_post_link($existing_ss)) . '">Edit WooCommerce</a> ';
                    echo '<a class="button button-primary" href="' . esc_url($review) . '">' . ($existing_ss ? 'Review / Update Colors' : 'Review &amp; Import') . '</a> ';
                    if ($existing_ss) {
                        echo '<form method="post" style="display:inline;margin-left:4px">'; wp_nonce_field('asss_manage_suppliers');
                        echo '<input type="hidden" name="product_id" value="' . (int)$existing_ss . '"><input type="hidden" name="disconnect_supplier" value="ss"><input type="hidden" name="disconnect_return" value="ss_add"><input type="hidden" name="return_brand_id" value="' . (int)$brand_id . '">';
                        echo '<button class="button" name="asss_disconnect_product_supplier" value="1" onclick="return confirm(&quot;Disconnect S&amp;S from this WooCommerce product? S&amp;S-only variations will leave the active storefront, but the product and any SanMar source will stay.&quot;);">Disconnect S&amp;S</button></form>';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }
            $this->wrap_end(); return;
        }

        $files = $this->sanmar->list_brand_files();
        if (!$files) {
            echo '<div class="notice notice-warning inline"><p>No supplier data has reached WordPress yet. Run the GitHub brand workflow after configuring the GitHub Bridge under <a href="' . esc_url(admin_url('admin.php?page=asss-settings')) . '">Settings</a>.</p></div>';
            $this->wrap_end();
            return;
        }

        $brand = sanitize_text_field(wp_unslash($_GET['brand'] ?? ''));
        $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $category = sanitize_text_field(wp_unslash($_GET['category'] ?? ''));
        echo '<p>Browse the cached SanMar catalog here. Nothing becomes a WooCommerce product until you intentionally import it. The layout now mirrors the faster S&amp;S workflow.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="asss-suppliers"><input type="hidden" name="supplier" value="sanmar"><table class="form-table"><tr><th>Brand</th><td><select name="brand"><option value="">Choose a brand…</option>';
        foreach ($files as $b => $f) echo '<option ' . selected($brand, $b, false) . ' value="' . esc_attr($b) . '">' . esc_html(str_replace('_', ' ', $b)) . ' — ' . esc_html($f['date']) . '</option>';
        echo '</select></td></tr>';
        if ($brand) {
            $all_styles_for_categories = $this->sanmar->style_summaries($brand, '');
            $cats=[]; foreach($all_styles_for_categories as $r){$c=trim((string)($r['category']??''));if($c!=='')$cats[mb_strtolower($c)]=$c;} natcasesort($cats);
            echo '<tr><th>Category</th><td><select name="category"><option value="">All categories</option>'; foreach($cats as $cat)echo '<option '.selected($category,$cat,false).' value="'.esc_attr($cat).'">'.esc_html($cat).'</option>'; echo '</select></td></tr>';
        }
        echo '<tr><th>Search</th><td><input class="regular-text" name="q" value="' . esc_attr($q) . '" placeholder="Style, product name, or category"> <button class="button button-primary">Browse Catalog</button></td></tr></table></form>';

        if ($brand) {
            $styles = $this->sanmar->style_summaries($brand, $q);
            if($category!=='')$styles=array_values(array_filter($styles,static fn($r)=>strcasecmp(trim((string)($r['category']??'')),$category)===0));
            echo '<p><strong>' . count($styles) . '</strong> matching styles shown (up to 200).</p><style>.asss-sanmar-table{width:100%;border-collapse:collapse}.asss-sanmar-table th,.asss-sanmar-table td{text-align:left;vertical-align:middle;padding:10px 12px;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7}.asss-sanmar-table th:last-child,.asss-sanmar-table td:last-child{border-right:0}</style><table class="widefat striped asss-sanmar-table"><thead><tr><th>Product</th><th>Style</th><th>Category</th><th>Colors</th><th>Sizes</th><th>Real Variations</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            foreach ($styles as $s) {
                $existing = $this->importer->find_product($s['style'], $brand);
                $review_url = add_query_arg(['page' => 'asss-import', 'brand' => $brand, 'style' => $s['style']], admin_url('admin.php'));
                echo '<tr><td>';
                if(!empty($s['image']))echo '<img src="'.esc_url((string)$s['image']).'" alt="" onerror="this.style.display=&quot;none&quot;" style="width:54px;height:54px;object-fit:contain;background:#fff;border:1px solid #e2e4e7;border-radius:4px;vertical-align:middle;margin-right:10px">';
                echo '<span style="display:inline-block;vertical-align:middle"><strong>' . esc_html($s['title']) . '</strong></span></td><td><code>' . esc_html($s['style']) . '</code></td><td>' . esc_html($s['category']?:'—') . '</td><td>' . (int)$s['color_count'] . '</td><td>' . (int)$s['size_count'] . '</td><td><strong>' . (int)($s['variant_count']??0) . '</strong></td><td>' . esc_html($s['status']) . '</td><td>';
                if ($existing) {
                    echo '<a class="button" href="' . esc_url(get_edit_post_link($existing)) . '">Edit WooCommerce</a> ';
                    echo '<a class="button" href="' . esc_url($review_url) . '">Review Colors</a> ';
                    echo '<form method="post" style="display:inline">';
                    wp_nonce_field('asss_active_products');
                    echo '<input type="hidden" name="product_id" value="' . (int)$existing . '">';
                    echo '<button class="button" name="asss_quick_repair_product" value="1">Quick Repair</button></form> ';
                    echo '<form method="post" style="display:inline">'; wp_nonce_field('asss_manage_suppliers');
                    echo '<input type="hidden" name="product_id" value="' . (int)$existing . '"><input type="hidden" name="disconnect_supplier" value="sanmar"><input type="hidden" name="disconnect_return" value="sanmar_add"><input type="hidden" name="return_brand" value="' . esc_attr($brand) . '">';
                    echo '<button class="button" name="asss_disconnect_product_supplier" value="1" onclick="return confirm(&quot;Disconnect SanMar from this WooCommerce product? SanMar-only variations will leave the active storefront, but the product and any S&amp;S source will stay.&quot;);">Disconnect SanMar</button></form>';
                } else {
                    echo '<a class="button button-primary" href="' . esc_url($review_url) . '">Choose &amp; Import</a>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $this->wrap_end();
    }

    private function ss_image_url(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        return 'https://www.ssactivewear.com/' . ltrim($url, '/');
    }

    public function ss_review_page(): void {
        $brand_id = absint($_GET['brand_id'] ?? 0);
        $style_id = absint($_GET['style_id'] ?? 0);
        $product = $this->ss->style_product($brand_id, $style_id);
        $this->wrap_start('Review & Import S&S Product');
        $this->notice();
        if (is_wp_error($product)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($product->get_error_message()) . '</p></div>';
            $this->wrap_end(); return;
        }

        $brand = (string)($product['brand'] ?? '');
        $style = (string)($product['style'] ?? '');
        $title = (string)($product['title'] ?? $style);
        $description = (string)($product['description'] ?? '');
        $categories = array_values(array_unique(array_filter(array_map('strval', (array)($product['categories'] ?? [])))));
        $variants = isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [];
        $colors = [];
        $sizes = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            $color = trim((string)($variant['color'] ?? ''));
            $size = trim((string)($variant['size'] ?? ''));
            if ($color !== '') $colors[$color][] = $variant;
            if ($size !== '') $sizes[$size] = true;
        }
        uksort($colors, 'strnatcasecmp');
        $theoretical = count($colors) * max(1, count($sizes));
        $missing = max(0, $theoretical - count($variants));
        $image = $this->ss_image_url($this->ss->representative_product_image($product));
        $existing_ss = $this->importer->find_ss_product($style_id, $brand_id);
        $existing_sanmar = $this->importer->find_product($style, $brand);
        $saved_colors = [];
        $saved_mode = 'all';
        if ($existing_ss) {
            $saved_mode = (string)get_post_meta($existing_ss, '_asss_ss_color_selection_mode', true) ?: ((string)get_post_meta($existing_ss, '_asss_color_selection_mode', true) ?: 'all');
            $saved_raw = (string)get_post_meta($existing_ss, '_asss_ss_selected_colors', true) ?: (string)get_post_meta($existing_ss, '_asss_selected_colors', true);
            $saved = json_decode($saved_raw, true);
            if (is_array($saved)) $saved_colors = array_map('strval', $saved);
        }
        $default_checked = ($existing_ss && $saved_mode === 'selected' && $saved_colors)
            ? array_fill_keys($saved_colors, true)
            : array_fill_keys(array_keys($colors), true);

        echo '<p><a href="' . esc_url(admin_url('admin.php?page=asss-suppliers&supplier=ss&brand_id=' . $brand_id)) . '">← Back to S&amp;S catalog</a></p>';
        $brand_meta = $this->ss->brand_meta($brand_id);
        if (!empty($brand_meta['e_retailing_restricted'])) {
            echo '<div class="notice notice-warning inline"><p><strong>S&amp;S eRetailing restriction flag:</strong> this brand is marked restricted in the supplier feed. Supplier Sync will keep imports as Draft; confirm your S&amp;S authorization/brand requirements before publishing online.</p></div>';
        }
        if ($existing_ss) {
            echo '<div class="notice notice-success inline"><p><strong>Already imported:</strong> this S&amp;S style is linked to WooCommerce product <a href="' . esc_url(get_edit_post_link($existing_ss)) . '">#' . (int)$existing_ss . '</a>. Saving below updates the selected-color policy and performs a full structural repair.</p></div>';
        } elseif ($existing_sanmar) {
            echo '<div class="notice notice-info inline"><p><strong>Existing product detected:</strong> SanMar already links ' . esc_html($brand . ' ' . $style) . ' to WooCommerce product <a href="' . esc_url(get_edit_post_link($existing_sanmar)) . '">#' . (int)$existing_sanmar . '</a>. You can now link S&amp;S as a second supplier to that same product. Existing storefront content, ASBO pricing, manual images, and the primary SKU are preserved.</p></div>';
        } else {
            echo '<div class="notice notice-info inline"><p><strong>Controlled import:</strong> choose the S&amp;S colors you want. The product is created as a <strong>WooCommerce Draft</strong>, only exact supplier Color + Size rows are created, and you review pricing before publishing.</p></div>';
        }

        echo '<div style="display:flex;gap:24px;align-items:flex-start;max-width:1100px;margin:20px 0">';
        if ($image) echo '<img src="' . esc_url($image) . '" alt="" onerror="this.style.display=&quot;none&quot;" style="width:180px;height:180px;object-fit:contain;background:#fff;border:1px solid #dcdcde;border-radius:6px">';
        echo '<div><h2 style="margin:0 0 8px">' . esc_html($brand . ' ' . $style . ' — ' . $title) . '</h2>';
        echo '<p><strong>S&amp;S Style ID:</strong> ' . $style_id . ' &nbsp; <strong>Part #:</strong> ' . esc_html((string)($product['part_number'] ?? '—')) . '</p>';
        echo '<p><strong>Categories:</strong> ' . esc_html($categories ? implode(' • ', $categories) : '—') . '</p>';
        echo '<p><strong>' . count($colors) . '</strong> colors &nbsp; <strong>' . count($sizes) . '</strong> global size values &nbsp; <strong>' . count($variants) . '</strong> real supplier variations</p>';
        if ($missing) echo '<p style="padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1"><strong>Sparse matrix confirmed:</strong> ' . $missing . ' theoretical Color × Size combinations do not exist and will never be synthesized.</p>';
        if ($description) echo '<div style="max-width:760px;line-height:1.5">' . wp_kses_post($description) . '</div>';
        echo '</div></div>';

        echo '<div style="max-width:1100px;padding:12px 14px;background:#fff8e5;border-left:4px solid #dba617;margin:14px 0"><strong>Pricing rule:</strong> supplier cost is stored as private supplier metadata, never used as the storefront price. A variation uses your ASBO base price when one exists; otherwise the Draft gets the higher of S&amp;S MAP / suggested retail as a safe review price. Confirm normal and ASBO pricing before publishing.</div>';
        echo '<div style="max-width:1100px;padding:12px 14px;background:#f0f6fc;border-left:4px solid #72aee6;margin:14px 0"><strong>Category rule:</strong> all category names supplied by S&amp;S are assigned to the WooCommerce product, while categories you add manually later are preserved.</div>';

        $can_import = true;
        if ($can_import) {
            echo '<form method="post" id="asss-ss-import-form">';
            wp_nonce_field('asss_ss_import');
            echo '<input type="hidden" name="brand_id" value="' . $brand_id . '"><input type="hidden" name="style_id" value="' . $style_id . '">';
            if ($existing_sanmar && !$existing_ss) echo '<input type="hidden" name="product_id" value="' . (int)$existing_sanmar . '">';
            echo '<div style="display:flex;gap:8px;align-items:center;margin:18px 0 10px"><button type="button" class="button" id="asss-ss-all">Select all colors</button><button type="button" class="button" id="asss-ss-none">Select none</button><span id="asss-ss-count" style="margin-left:4px"></span></div>';
        }

        echo '<h2>Exact supplier colors &amp; sizes</h2><p>Each card is one color family. Only the listed sizes are real S&amp;S SKUs for that color.</p>';
        echo '<style>.asss-ss-review{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;max-width:1200px}.asss-ss-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px}.asss-ss-card-head{display:flex;gap:10px;align-items:center}.asss-ss-card img{width:62px;height:62px;object-fit:contain;border:1px solid #e2e4e7;border-radius:4px;background:#fff}.asss-size-pills{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.asss-size-pill{display:inline-block;padding:3px 7px;border:1px solid #dcdcde;border-radius:12px;background:#f6f7f7;font-size:12px}.asss-ss-pick{display:flex;align-items:flex-start;gap:8px}.asss-ss-pick input{margin-top:3px}</style>';
        echo '<div class="asss-ss-review">';
        foreach ($colors as $color=>$rows) {
            $qty = 0; $size_names = []; $max_images = 0; $costs = []; $retails = [];
            $thumb = '';
            foreach ($rows as $row) {
                $size_names[] = (string)($row['size'] ?? '');
                if (isset($row['qty']) && is_numeric($row['qty'])) $qty += (int)$row['qty'];
                if (isset($row['customer_price']) && is_numeric($row['customer_price'])) $costs[] = (float)$row['customer_price'];
                if (isset($row['retail_price']) && is_numeric($row['retail_price'])) $retails[] = (float)$row['retail_price'];
                $max_images = max($max_images, count((array)($row['gallery'] ?? [])));
                if ($thumb === '') $thumb = $this->ss_image_url((string)($row['primary_image'] ?? ''));
            }
            $cost_label = $costs ? '$' . number_format(min($costs), 2) . (max($costs) !== min($costs) ? '–$' . number_format(max($costs), 2) : '') : '—';
            $retail_label = $retails ? '$' . number_format(min($retails), 2) . (max($retails) !== min($retails) ? '–$' . number_format(max($retails), 2) : '') : '—';
            echo '<div class="asss-ss-card">';
            echo '<div class="asss-ss-pick">';
            if ($can_import) echo '<input class="asss-ss-color-check" type="checkbox" name="colors[]" value="' . esc_attr($color) . '" ' . checked(isset($default_checked[$color]), true, false) . '>';
            echo '<div style="flex:1"><div class="asss-ss-card-head">';
            if ($thumb) echo '<img src="' . esc_url($thumb) . '" alt="">';
            echo '<div><strong>' . esc_html($color) . '</strong><br><small>' . count($rows) . ' real SKU' . (count($rows) === 1 ? '' : 's') . ' • ' . $max_images . ' gallery image' . ($max_images === 1 ? '' : 's') . '</small></div></div>';
            echo '<div class="asss-size-pills">'; foreach ($size_names as $size) echo '<span class="asss-size-pill">' . esc_html($size) . '</span>'; echo '</div>';
            echo '<p style="margin:9px 0 0"><small><strong>Supplier inventory snapshot:</strong> ' . number_format($qty) . '<br><strong>Customer cost:</strong> ' . esc_html($cost_label) . '<br><strong>S&amp;S suggested retail:</strong> ' . esc_html($retail_label) . '</small></p></div></div></div>';
        }
        echo '</div>';

        if ($can_import) {
            $button_name = ($existing_sanmar && !$existing_ss) ? 'asss_link_ss_existing' : 'asss_import_ss_style';
            $button_label = $existing_ss ? 'Update Selection & Repair S&S Product' : (($existing_sanmar && !$existing_ss) ? 'Link S&S to Existing WooCommerce Product' : 'Create WooCommerce Draft');
            echo '<div style="position:sticky;bottom:0;background:#f0f0f1;border-top:1px solid #c3c4c7;padding:14px 0;margin-top:18px;z-index:5"><button class="button button-primary button-hero" name="' . esc_attr($button_name) . '" value="1">' . esc_html($button_label) . '</button> <span style="margin-left:8px">Only checked colors and their real supplier sizes will be synchronized.</span></div>';
            echo '</form>';
            echo '<script>document.addEventListener("DOMContentLoaded",function(){const boxes=[...document.querySelectorAll(".asss-ss-color-check")];const count=document.getElementById("asss-ss-count");function update(){const n=boxes.filter(b=>b.checked).length;if(count)count.textContent=n+" of "+boxes.length+" colors selected";}document.getElementById("asss-ss-all")?.addEventListener("click",()=>{boxes.forEach(b=>b.checked=true);update();});document.getElementById("asss-ss-none")?.addEventListener("click",()=>{boxes.forEach(b=>b.checked=false);update();});boxes.forEach(b=>b.addEventListener("change",update));update();});</script>';
        }
        $this->wrap_end();
    }


    public function active_products_page(): void {
        $this->wrap_start('Active Supplier Products');
        $this->notice();

        $ids = array_values(array_filter($this->sync->linked_product_ids(), function($id) {
            return $this->is_active_linked_product((int)$id);
        }));

        echo '<p>This page shows currently active supplier-linked WooCommerce products from every configured supplier. <strong>Quick Repair</strong> refreshes one product immediately. <strong>Repair Selected</strong> queues larger batches in the background so a large catalog does not time out. Both preserve the product\'s existing color-selection policy.</p>';
        echo '<p><strong>' . count($ids) . '</strong> active linked product' . (count($ids) === 1 ? '' : 's') . '.</p>';

        if (!$ids) {
            echo '<div class="notice notice-info inline"><p>No active supplier-linked products were found yet.</p></div>';
            $this->wrap_end();
            return;
        }

        echo '<form method="post" id="asss-active-products-form">';
        wp_nonce_field('asss_active_products');
        echo '<div style="display:flex;gap:8px;align-items:center;margin:14px 0">';
        echo '<button type="button" class="button" id="asss-active-select-all">Select all</button> ';
        echo '<button type="button" class="button" id="asss-active-select-none">Select none</button> ';
        echo '<button class="button button-primary" name="asss_repair_selected_products" value="1" onclick="return confirm(\'Refresh the selected supplier products now? Existing color selections will be preserved.\');">Repair Selected</button>';
        echo '</div>';

        echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="asss-active-master"></td><th>Product</th><th>Supplier</th><th>Woo status</th><th>Colors</th><th>Variations</th><th>Health</th><th>Last sync</th><th>Actions</th></tr></thead><tbody>';
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) continue;
            $supplier = (string)get_post_meta($id, '_asss_supplier', true);
            $sources = $this->multi->product_sources($id);
            $active_sources = array_filter($sources, static fn($v)=>is_array($v)&&!empty($v['enabled']));
            $supplier_labels = [];
            foreach (array_keys($active_sources) as $source_key) $supplier_labels[] = ASSS_MultiSupplier::suppliers()[$source_key] ?? $source_key;
            $all_supplier_label = $supplier_labels ? implode(' + ', $supplier_labels) : (ASSS_MultiSupplier::suppliers()[$supplier] ?? $supplier);
            if ($supplier === 'multi' || count($active_sources) > 1) {
                $brand = (string)(($sources['sanmar']['brand'] ?? '') ?: ($sources['ss']['brand'] ?? ''));
                $style = (string)(($sources['sanmar']['style'] ?? '') ?: ($sources['ss']['style'] ?? ''));
                $review_url = add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>$id], admin_url('admin.php'));
                $supplier_label = $all_supplier_label;
            } elseif ($supplier === 'ss') {
                $brand = (string)get_post_meta($id, '_asss_ss_brand', true);
                $style = (string)get_post_meta($id, '_asss_ss_style', true);
                $brand_id = absint(get_post_meta($id, '_asss_ss_brand_id', true));
                $style_id = absint(get_post_meta($id, '_asss_ss_style_id', true));
                $review_url = add_query_arg(['page'=>'asss-ss-review','brand_id'=>$brand_id,'style_id'=>$style_id], admin_url('admin.php'));
                $supplier_label = $all_supplier_label;
            } elseif ($supplier === 'momentec') {
                $brand = (string)get_post_meta($id, '_asss_momentec_brand', true);
                $style = (string)get_post_meta($id, '_asss_momentec_style', true);
                $review_url = add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>$id], admin_url('admin.php'));
                $supplier_label = $all_supplier_label;
            } else {
                $brand = (string)get_post_meta($id, '_asss_sanmar_brand', true);
                $style = (string)get_post_meta($id, '_asss_sanmar_style', true);
                $review_url = add_query_arg(['page'=>'asss-import','brand'=>$brand,'style'=>$style], admin_url('admin.php'));
                $supplier_label = $all_supplier_label;
            }
            if ($supplier === 'ss') {
                $mode = (string)get_post_meta($id, '_asss_ss_color_selection_mode', true) ?: ((string)get_post_meta($id, '_asss_color_selection_mode', true) ?: 'all');
                $selected_raw = (string)get_post_meta($id, '_asss_ss_selected_colors', true) ?: (string)get_post_meta($id, '_asss_selected_colors', true);
            } elseif ($supplier === 'sanmar') {
                $mode = (string)get_post_meta($id, '_asss_sanmar_color_selection_mode', true) ?: ((string)get_post_meta($id, '_asss_color_selection_mode', true) ?: 'all');
                $selected_raw = (string)get_post_meta($id, '_asss_sanmar_selected_colors', true) ?: (string)get_post_meta($id, '_asss_selected_colors', true);
            } else {
                $mode = 'multi'; $selected_raw = '[]';
            }
            $selected = json_decode($selected_raw, true);
            $selected_count = is_array($selected) ? count($selected) : 0;
            $last_sync = (string)get_post_meta($id, '_asss_last_product_sync', true);
            $health = $this->product_health($product);
            $thumb = $product->get_image_id() ? wp_get_attachment_image($product->get_image_id(), [44,44], false, ['style'=>'width:44px;height:44px;object-fit:contain;vertical-align:middle;margin-right:8px']) : '';

            $color_label = $supplier === 'multi' ? 'Per-supplier selections' : ($mode === 'all' ? 'All supplier colors' : ($selected_count . ' selected'));
            $status_label = ucfirst((string)$product->get_status());
            $health_bits = [];
            if ($health['blank_prices'] > 0) $health_bits[] = '<span style="color:#b32d2e"><strong>' . (int)$health['blank_prices'] . '</strong> missing prices</span>';
            if ($health['missing_images'] > 0) $health_bits[] = '<span style="color:#996800"><strong>' . (int)$health['missing_images'] . '</strong> missing images</span>';
            if ($health['missing_skus'] > 0) $health_bits[] = '<span style="color:#996800"><strong>' . (int)$health['missing_skus'] . '</strong> missing SKUs</span>';
            if (!empty($health['asbo_matrix_missing'])) $health_bits[] = '<span style="color:#b32d2e"><strong>ASBO pricing matrix missing</strong> — bulk-order plugin will not render this product</span>';
            if (!empty($health['extra_variation_attributes'])) $health_bits[] = '<span style="color:#996800"><strong>Custom variation attribute needs review:</strong> ' . esc_html(implode(', ', $health['extra_variation_attributes'])) . '</span>';
            if (!empty($health['audit']['missing_expected'])) $health_bits[] = '<span style="color:#b32d2e"><strong>' . (int)$health['audit']['missing_expected'] . '</strong> missing expected variations</span>';
            if (!empty($health['audit']['attribute_mismatch'])) $health_bits[] = '<span style="color:#b32d2e"><strong>' . (int)$health['audit']['attribute_mismatch'] . '</strong> attribute mismatches</span>';
            if (!empty($health['audit']['active_phantom_combinations'])) $health_bits[] = '<span style="color:#b32d2e"><strong>' . (int)$health['audit']['active_phantom_combinations'] . '</strong> invalid color/size combinations</span>';
            if (!empty($health['audit']['phantom_cartesian_disabled'])) $health_bits[] = '<span style="color:#996800"><strong>' . (int)$health['audit']['phantom_cartesian_disabled'] . '</strong> phantom combinations disabled on last repair</span>';
            if (!empty($health['audit']['missing_variation_gallery'])) $health_bits[] = '<span style="color:#996800"><strong>' . (int)$health['audit']['missing_variation_gallery'] . '</strong> missing variation galleries</span>';
            if (!empty($health['audit']['incomplete_variation_gallery'])) $health_bits[] = '<span style="color:#996800"><strong>' . (int)$health['audit']['incomplete_variation_gallery'] . '</strong> incomplete variation galleries</span>';
            if (!$health_bits) $health_bits[] = '<span style="color:#008a20"><strong>Looks good</strong></span>';

            echo '<tr>';
            echo '<th class="check-column"><input class="asss-active-check" type="checkbox" name="product_ids[]" value="' . (int)$id . '"></th>';
            echo '<td>' . $thumb . '<strong>' . esc_html($product->get_name()) . '</strong><br><small>Product #' . (int)$id . '</small></td>';
            echo '<td><strong>' . esc_html($supplier_label) . '</strong><br>' . esc_html($brand) . ' <code>' . esc_html($style) . '</code></td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '<td>' . esc_html($color_label) . '</td>';
            echo '<td>' . (int)$health['variation_count'] . '</td>';
            echo '<td>' . implode('<br>', $health_bits) . '</td>';
            echo '<td>' . esc_html($last_sync ?: 'Not yet') . '</td>';
            $review_label = $supplier === 'multi' ? 'Manage Suppliers' : 'Review Colors';
            echo '<td style="white-space:nowrap"><a class="button" href="' . esc_url(get_edit_post_link($id)) . '">Edit</a> <a class="button" href="' . esc_url($review_url) . '">' . esc_html($review_label) . '</a> ';
            echo '<button class="button button-primary" name="asss_quick_repair_product" value="1" formaction="" onclick="this.form.product_id.value=\'' . (int)$id . '\';">Quick Repair</button> ';
            foreach (array_filter($sources, static fn($v)=>is_array($v)&&!empty($v['enabled'])) as $src=>$src_data) {
                $src_label = ASSS_MultiSupplier::suppliers()[$src] ?? $src;
                echo '<button class="button" style="margin-top:5px" name="asss_disconnect_product_supplier" value="' . (int)$id . '|' . esc_attr($src) . '" onclick="return confirm(\'Disconnect ' . esc_js($src_label) . '? Supplier-only variations will leave the active storefront; the WooCommerce product and other supplier sources stay.\');">Disconnect ' . esc_html($src_label) . '</button> ';
            }
            echo '<input type="hidden" name="disconnect_return" value="active"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<input type="hidden" name="product_id" value="0">';
        echo '<p style="margin-top:14px"><button class="button button-primary" name="asss_repair_selected_products" value="1" onclick="return confirm(\'Refresh the selected supplier products now? Existing color selections will be preserved.\');">Repair Selected</button></p>';
        echo '</form>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){const boxes=[...document.querySelectorAll(".asss-active-check")];const master=document.getElementById("asss-active-master");const set=v=>{boxes.forEach(b=>b.checked=v);if(master)master.checked=v};document.getElementById("asss-active-select-all")?.addEventListener("click",()=>set(true));document.getElementById("asss-active-select-none")?.addEventListener("click",()=>set(false));master?.addEventListener("change",()=>set(master.checked));});</script>';
        $this->wrap_end();
    }

    private function is_active_linked_product(int $product_id): bool {
        if ($product_id <= 0 || get_post_type($product_id) !== 'product') return false;
        if (!in_array((string)get_post_meta($product_id, '_asss_supplier', true), ['sanmar','ss','momentec','multi'], true)) return false;
        if ((string)get_post_meta($product_id, '_asss_sync_enabled', true) !== 'yes') return false;
        if ((string)get_post_meta($product_id, '_asss_supplier_archived', true) === 'yes') return false;
        return get_post_status($product_id) !== 'trash';
    }

    private function product_health(WC_Product $product): array {
        $variation_count = 0;
        $blank_prices = 0;
        $missing_images = 0;
        $missing_skus = 0;
        if ($product instanceof WC_Product_Variable) {
            // Direct query avoids stale WooCommerce child caches after a repair.
            $ids = get_posts([
                'post_type' => 'product_variation',
                'post_status' => ['publish','private','draft','pending'],
                'post_parent' => $product->get_id(),
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
            ]);
            $check_images = !empty($this->sanmar->settings()['sync_images']);
            foreach ($ids as $variation_id) {
                $v = wc_get_product((int)$variation_id);
                if (!$v instanceof WC_Product_Variation) continue;
                if (!$this->multi->variation_sources((int)$variation_id)) continue;
                if ((string)$v->get_meta('_asss_stale_variation') === 'yes') continue;
                if ((string)$v->get_meta('_asss_discontinued_variation') === 'yes') continue;
                if ($v->get_status('edit') !== 'publish') continue;
                $variation_count++;
                if ($v->get_regular_price('edit') === '' || $v->get_price('edit') === '') $blank_prices++;
                if ($check_images && !$v->get_image_id()) $missing_images++;
                if ($v->get_sku('edit') === '') $missing_skus++;
            }
        }
        $audit = json_decode((string)get_post_meta($product->get_id(), '_asss_last_variation_audit', true), true);
        if (!is_array($audit)) $audit = [];
        $asbo_enabled = (string)$product->get_meta('_asbo_enabled') === 'yes';
        $asbo_matrix_missing = $asbo_enabled && trim((string)$product->get_meta('_asbo_pricing_matrix')) === '';

        $extra_variation_attributes = [];
        foreach ($product->get_attributes('edit') as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_variation()) continue;
            $name = (string)$attribute->get_name();
            $normalized = $attribute->is_taxonomy()
                ? sanitize_title(preg_replace('/^pa_/', '', $name))
                : sanitize_title($name);
            if ($name === 'pa_color' || $name === 'pa_size' || $normalized === 'color' || $normalized === 'size') continue;
            $extra_variation_attributes[] = wc_attribute_label($name, $product) ?: $name;
        }

        return compact('variation_count', 'blank_prices', 'missing_images', 'missing_skus', 'audit', 'asbo_enabled', 'asbo_matrix_missing', 'extra_variation_attributes');
    }


    public function intelligence_page(): void {
        $this->wrap_start('Supplier Intelligence');
        $this->notice();
        echo '<p>One storefront product can carry SanMar, S&amp;S Activewear, or both. Supplier cost and inventory stay separate. Supplier Sync may manage WooCommerce/ASBO pricing from wholesale until you manually edit that pricing, at which point your custom values are preserved.</p>';
        $ids=array_values(array_filter($this->sync->linked_product_ids(false),fn($id)=>$this->is_active_linked_product((int)$id)));
        if(!$ids){echo '<div class="notice notice-info inline"><p>No active supplier-linked products yet.</p></div>';$this->wrap_end();return;}
        $global=$this->multi->settings();
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
        echo '<div class="card" style="padding:14px 18px"><strong>Inventory strategy</strong><br>'.esc_html(ucfirst($global['strategy'])).'</div>';
        echo '<div class="card" style="padding:14px 18px"><strong>Supplier priority</strong><br>'.esc_html(implode(' → ',array_map(fn($s)=>ASSS_MultiSupplier::suppliers()[$s]??$s,$global['priority']))).'</div>';
        echo '<div class="card" style="padding:14px 18px"><strong>Active products</strong><br>'.count($ids).'</div></div>';
        echo '<style>.asss-intel{width:100%;border-collapse:collapse}.asss-intel th,.asss-intel td{text-align:left;vertical-align:top;padding:10px 12px;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7}.asss-intel th:last-child,.asss-intel td:last-child{border-right:0}.asss-good{color:#008a20}.asss-warn{color:#996800}</style>';
        echo '<table class="widefat striped asss-intel"><thead><tr><th>Product</th><th>Sources</th><th>Variation coverage</th><th>Supplier inventory</th><th>Supplier cost range</th><th>Effective</th><th>Actions</th></tr></thead><tbody>';
        foreach($ids as $id){
            $product=wc_get_product($id);if(!$product)continue;
            $sources=array_filter($this->multi->product_sources($id),static fn($v)=>is_array($v)&&!empty($v['enabled']));
            $coverage=$this->multi->product_coverage($id);
            $totals=['sanmar'=>0,'ss'=>0];$costs=['sanmar'=>[],'ss'=>[]];$effective=[];
            $vids=get_posts(['post_type'=>'product_variation','post_status'=>['publish','private','draft','pending'],'post_parent'=>$id,'fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true]);
            foreach($vids as $vid){
                foreach($this->multi->variation_intelligence((int)$vid) as $supplier=>$row){
                    if(is_int($row['quantity']))$totals[$supplier]+=$row['quantity'];
                    if(is_float($row['cost'])||is_int($row['cost']))$costs[$supplier][]=(float)$row['cost'];
                }
                $eff=(string)get_post_meta((int)$vid,'_asss_effective_supplier',true);if($eff!=='')$effective[$eff]=($effective[$eff]??0)+1;
            }
            $source_labels=[];foreach(array_keys($sources) as $src)$source_labels[]=ASSS_MultiSupplier::suppliers()[$src]??$src;
            $cost_label=[];foreach($costs as $src=>$vals)if($vals)$cost_label[]=(ASSS_MultiSupplier::suppliers()[$src]??$src).': $'.number_format(min($vals),2).'–$'.number_format(max($vals),2);
            $eff_label=[];foreach($effective as $src=>$count)$eff_label[]=(ASSS_MultiSupplier::suppliers()[$src]??$src).' '.$count;
            echo '<tr><td><strong>'.esc_html($product->get_name()).'</strong><br><small>#'.(int)$id.'</small></td>';
            echo '<td>'.esc_html(implode(' + ',$source_labels)).'</td>';
            echo '<td><strong>'.(int)$coverage['total'].'</strong> store variations<br><small>'.(int)$coverage['both'].' both • '.(int)$coverage['sanmar_only'].' SanMar-only • '.(int)$coverage['ss_only'].' S&amp;S-only</small></td>';
            echo '<td>SanMar: <strong>'.number_format($totals['sanmar']).'</strong><br>S&amp;S: <strong>'.number_format($totals['ss']).'</strong></td>';
            echo '<td>'.($cost_label?implode('<br>',array_map('esc_html',$cost_label)):'—').'</td>';
            echo '<td>'.($eff_label?esc_html(implode(' • ',$eff_label)):'Awaiting inventory').'</td>';
            echo '<td><a class="button button-primary" href="'.esc_url(add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>$id],admin_url('admin.php'))).'">Manage Suppliers</a> <a class="button" href="'.esc_url(get_edit_post_link($id)).'">Edit Product</a></td></tr>';
        }
        echo '</tbody></table>';
        $this->wrap_end();
    }

    public function manage_suppliers_page(): void {
        $product_id=absint($_GET['product_id'] ?? 0);
        $product=$product_id?wc_get_product($product_id):null;
        $this->wrap_start('Manage Product Suppliers');$this->notice();
        if(!$product instanceof WC_Product_Variable){echo '<div class="notice notice-error inline"><p>Choose a valid supplier-linked variable product.</p></div>';$this->wrap_end();return;}
        $sources=array_filter($this->multi->product_sources($product_id),static fn($v)=>is_array($v)&&!empty($v['enabled']));
        $coverage=$this->multi->product_coverage($product_id);
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=asss-intelligence')).'">← Supplier Intelligence</a></p>';
        echo '<div style="display:flex;gap:16px;align-items:center;margin:14px 0 20px">'.($product->get_image_id()?wp_get_attachment_image($product->get_image_id(),[72,72],false,['style'=>'width:72px;height:72px;object-fit:contain;background:#fff;border:1px solid #dcdcde']):'').'<div><h2 style="margin:0">'.esc_html($product->get_name()).'</h2><p style="margin:5px 0 0">Product #'.(int)$product_id.' • '.(int)$coverage['total'].' active supplier variations</p></div></div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;max-width:1000px">';
        foreach(['sanmar','ss','momentec'] as $src){$d=$sources[$src]??null;if(!$d)continue;$label=ASSS_MultiSupplier::suppliers()[$src];echo '<div class="card" style="padding:16px"><h3 style="margin-top:0">'.esc_html($label).'</h3>';
            if($src==='sanmar')echo '<p><strong>Brand:</strong> '.esc_html((string)($d['brand']??'')).'<br><strong>Style:</strong> '.esc_html((string)($d['style']??'')).'</p>';
            else echo '<p><strong>Brand:</strong> '.esc_html((string)($d['brand']??'')).' (#'.absint($d['brand_id']??0).')<br><strong>Style:</strong> '.esc_html((string)($d['style']??'')).' (ID '.absint($d['style_id']??0).')</p>';
            $sel=(array)($d['selected_colors']??[]);echo '<p><strong>Color policy:</strong> '.esc_html((string)($d['selection_mode']??'all')).($sel?' • '.count($sel).' saved colors':'').'<br><strong>Last source update:</strong> '.esc_html((string)($d['updated_at']??'—')).'</p>';
            echo '<form method="post" style="margin-top:12px">';wp_nonce_field('asss_manage_suppliers');echo '<input type="hidden" name="product_id" value="'.(int)$product_id.'"><input type="hidden" name="disconnect_supplier" value="'.esc_attr($src).'"><button class="button" name="asss_disconnect_product_supplier" value="1" onclick="return confirm(\'Disconnect '.esc_js($label).' from this product? Supplier-only variations will leave the active storefront. Shared variations and the WooCommerce product will stay.\');">Disconnect '.esc_html($label).'</button></form>';
            echo '</div>';}
        echo '</div>';

        $override=(string)get_post_meta($product_id,'_asss_inventory_strategy_override',true)?:'inherit';
        $preferred=(string)get_post_meta($product_id,'_asss_preferred_supplier',true);
        echo '<form method="post" style="max-width:800px;margin:18px 0;background:#fff;border:1px solid #dcdcde;padding:16px">';wp_nonce_field('asss_manage_suppliers');echo '<input type="hidden" name="product_id" value="'.(int)$product_id.'"><h3 style="margin-top:0">Inventory behavior</h3><table class="form-table"><tr><th>Strategy</th><td><select name="inventory_strategy"><option value="inherit" '.selected($override,'inherit',false).'>Use global setting</option><option value="combined" '.selected($override,'combined',false).'>Combined inventory from all suppliers</option><option value="preferred" '.selected($override,'preferred',false).'>Preferred supplier inventory only</option></select></td></tr><tr><th>Preferred supplier</th><td><select name="preferred_supplier"><option value="">Use global priority</option><option value="ss" '.selected($preferred,'ss',false).'>S&amp;S Activewear</option><option value="sanmar" '.selected($preferred,'sanmar',false).'>SanMar</option></select><p class="description">This controls which supplier is recommended for fulfillment. It does not automatically place supplier orders.</p></td></tr></table><button class="button button-primary" name="asss_save_product_supplier_settings" value="1">Save Supplier Preferences</button></form>';

        echo '<h2>Variation supplier coverage</h2><p><strong>'.(int)$coverage['both'].'</strong> variations are carried by both suppliers; <strong>'.(int)$coverage['sanmar_only'].'</strong> are SanMar-only; <strong>'.(int)$coverage['ss_only'].'</strong> are S&amp;S-only.</p>';
        echo '<style>.asss-source-table{width:100%;border-collapse:collapse}.asss-source-table th,.asss-source-table td{text-align:left;vertical-align:top;padding:8px 10px;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7}.asss-source-table th:last-child,.asss-source-table td:last-child{border-right:0}</style>';
        echo '<table class="widefat striped asss-source-table"><thead><tr><th>Variation</th><th>Woo stock</th><th>SanMar</th><th>S&amp;S</th><th>Effective source</th></tr></thead><tbody>';
        $vids=get_posts(['post_type'=>'product_variation','post_status'=>['publish','private','draft','pending'],'post_parent'=>$product_id,'fields'=>'ids','posts_per_page'=>250,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true]);
        foreach($vids as $vid){$v=wc_get_product((int)$vid);if(!$v instanceof WC_Product_Variation)continue;$intel=$this->multi->variation_intelligence((int)$vid);if(!$intel)continue;$attrs=$v->get_variation_attributes();$labels=[];foreach($attrs as $tax=>$slug){$tax=str_replace('attribute_','',$tax);$term=taxonomy_exists($tax)?get_term_by('slug',$slug,$tax):null;$labels[]=($term&&!is_wp_error($term))?$term->name:$slug;}
            echo '<tr><td><strong>'.esc_html(implode(' / ',$labels)).'</strong><br><small>#'.(int)$vid.' • Woo SKU '.esc_html($v->get_sku()?:'—').'</small></td><td><strong>'.number_format((int)$v->get_stock_quantity()).'</strong></td>';
            foreach(['sanmar','ss','momentec'] as $src){$r=$intel[$src]??null;if(!$r){echo '<td>—</td>';continue;}echo '<td><strong>'.($r['quantity']===null?'—':number_format($r['quantity'])).'</strong> stock<br><small>Cost '.($r['cost']===null?'—':'$'.number_format($r['cost'],2)).'<br>SKU '.esc_html($r['sku']?:'—').($r['gtin']?'<br>GTIN '.esc_html($r['gtin']):'').'</small></td>';}
            $eff=(string)get_post_meta((int)$vid,'_asss_effective_supplier',true);echo '<td>'.esc_html(ASSS_MultiSupplier::suppliers()[$eff]??($eff?:'Awaiting inventory')).'</td></tr>';}
        echo '</tbody></table>';
        if(count($vids)>=250)echo '<p><em>Showing the first 250 variations for browser performance.</em></p>';
        $this->wrap_end();
    }

    public function status_page(): void {
        $this->wrap_start('Supplier Sync V2 System Status');
        $this->notice();
        $settings = $this->sanmar->settings();
        $catalog = $this->sanmar->brand_catalog();
        $enabled_brands = array_filter($catalog, static fn($meta) => !empty($meta['enabled']));
        $ss_catalog = $this->ss->brand_catalog();
        $ss_enabled_brands = $this->ss->enabled_brand_catalog();
        $manifest = $this->sanmar->bridge_manifest();
        $active_ids = array_values(array_filter($this->sync->linked_product_ids(false), function($id) {
            return $this->is_active_linked_product((int)$id);
        }));
        $targets = $this->sync->inventory_targets();
        $target_count = is_array($targets) ? count($targets) : 0;
        $ss_targets = $this->sync->inventory_targets_ss();
        $ss_target_count = is_array($ss_targets) ? count($ss_targets) : 0;
        $sanmar_inventory_status = get_option('asss_inventory_bridge_status', []);
        $ss_inventory_status = get_option('asss_ss_inventory_bridge_status', []);
        $asbo_detected = class_exists('ASBO_Plugin') || shortcode_exists('asbo_bulk_order');
        $variation_gallery_supported = defined('WC_VERSION') && version_compare(WC_VERSION, '10.9', '>=');
        $variation_gallery_enabled = !$variation_gallery_supported || (string)get_option('wc_feature_woocommerce_additional_variation_images_enabled') === 'yes';
        $uploads = wp_upload_dir();
        $cache_dir = trailingslashit($uploads['basedir']) . 'all-star-supplier-sync/sanmar';
        $cache_writable = is_dir($cache_dir) ? is_writable($cache_dir) : is_writable($uploads['basedir']);
        $hourly_fallback = (bool)wp_next_scheduled('asss_hourly_inventory');
        $daily_fallback = (bool)wp_next_scheduled('asss_daily_products');
        $next_sanmar = $this->next_nominal_hourly(17);
        $next_ss = $this->next_nominal_hourly(37);

        $product_issues = 0;
        $issue_details = [];
        foreach ($active_ids as $id) {
            $product = wc_get_product($id);
            if (!$product) continue;
            $health = $this->product_health($product);
            $issues = (int)$health['blank_prices'] + (int)$health['missing_images'] + (int)$health['missing_skus'];
            $issues += !empty($health['asbo_matrix_missing']) ? 1 : 0;
            $issues += !empty($health['extra_variation_attributes']) ? 1 : 0;
            foreach (['missing_expected','attribute_mismatch','active_phantom_combinations','missing_variation_gallery','incomplete_variation_gallery'] as $key) {
                $issues += (int)($health['audit'][$key] ?? 0);
            }
            if ($issues > 0) {
                $product_issues++;
                $issue_details[] = '<a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html($product->get_name()) . '</a> (' . (int)$issues . ' flag' . ($issues === 1 ? '' : 's') . ')';
            }
        }

        $source_counts=$this->multi->source_counts();
        $multi_settings=$this->multi->settings();

        $rows = [
            ['All Star Supplier Sync', defined('ASSS_VERSION') ? ASSS_VERSION : 'Unknown', defined('ASSS_VERSION'), 'Production V2 multi-supplier plugin loaded.'],
            ['S&S brand catalog', count($ss_catalog) . ' discovered / ' . count($ss_enabled_brands) . ' enabled', count($ss_catalog) > 0, 'Populated by GitHub from the S&S Brands API.'],
            ['WooCommerce', defined('WC_VERSION') ? WC_VERSION : 'Unknown', defined('WC_VERSION'), 'Required product/catalog engine.'],
            ['GitHub product bridge', !empty($settings['bridge_enabled']) && !empty($settings['automatic_product_bridge']) ? 'Enabled' : 'Disabled', !empty($settings['bridge_enabled']) && !empty($settings['automatic_product_bridge']), 'Changed enabled brands are received over HTTPS.'],
            ['GitHub inventory bridge', !empty($settings['bridge_inventory_enabled']) ? 'Enabled' : 'Disabled', !empty($settings['bridge_inventory_enabled']), 'Hourly inventory is applied through the authenticated bridge.'],
            ['Supplier cache', count($manifest) . ' synced brand' . (count($manifest) === 1 ? '' : 's'), $cache_writable, $cache_writable ? 'Per-style cache directory is writable.' : 'Uploads/cache directory is not writable.'],
            ['Live SanMar brand catalog', count($catalog) . ' canonical brands / ' . count($enabled_brands) . ' enabled', count($catalog) > 0, 'Brand discovery is case-normalized; only enabled changed brands download.'],
            ['Active linked products', count($active_ids) . ' products / ' . $target_count . ' SanMar targets / ' . $ss_target_count . ' S&S targets', true, 'Both suppliers use exact active WooCommerce variations as their hourly inventory target set.'],
            ['Multi-supplier coverage', (int)$source_counts['multi_products'] . ' multi-source / ' . (int)$source_counts['total_products'] . ' linked products', true, 'Products can carry SanMar and S&S simultaneously without duplicate storefront listings.'],
            ['Inventory strategy', ucfirst($multi_settings['strategy']) . ' · priority ' . implode(' → ', array_map(fn($k)=>ASSS_MultiSupplier::suppliers()[$k] ?? $k, $multi_settings['priority'])), true, 'Supplier inventories remain separate in metadata; Woo stock is calculated from the configured strategy.'],
            ['SanMar hourly inventory', $target_count < 1 ? 'No active targets' : (!empty($sanmar_inventory_status['received_at']) ? 'Last success: ' . $sanmar_inventory_status['received_at'] : 'Awaiting first run'), $target_count < 1 || !empty($sanmar_inventory_status['received_at']), $target_count < 1 ? 'No active SanMar variations currently need inventory.' : 'Next nominal check: ' . $next_sanmar . '. GitHub runs at :17 each hour and may start late; last matched ' . (int)($sanmar_inventory_status['matched'] ?? 0) . ' and changed ' . (int)($sanmar_inventory_status['changed'] ?? 0) . '.'],
            ['S&S hourly inventory', $ss_target_count < 1 ? 'No active targets' : (!empty($ss_inventory_status['received_at']) ? 'Last success: ' . $ss_inventory_status['received_at'] : 'Awaiting first run'), $ss_target_count < 1 || !empty($ss_inventory_status['received_at']), $ss_target_count < 1 ? 'No active S&S variations currently need inventory.' : 'Next nominal check: ' . $next_ss . '. GitHub runs at :37 each hour and may start late; last matched ' . (int)($ss_inventory_status['matched'] ?? 0) . ' and changed ' . (int)($ss_inventory_status['changed'] ?? 0) . '.'],
            ['ASBO integration', $asbo_detected ? 'Detected' : 'Meta contract ready', true, $asbo_detected ? 'Bulk-order shortcode/plugin is active.' : 'Supplier Sync still writes the ASBO meta contract when configured.'],
            ['Variation galleries', $variation_gallery_supported ? ($variation_gallery_enabled ? 'Enabled' : 'Feature available but OFF') : 'WooCommerce legacy/fallback mode', $variation_gallery_enabled, $variation_gallery_enabled ? 'Variation gallery storage is ready.' : 'Enable WooCommerce → Settings → Advanced → Features → Variation gallery.'],
            ['Legacy direct inventory cron', $hourly_fallback ? 'Scheduled' : 'Off', empty($settings['hourly_inventory_sync']) ? !$hourly_fallback : $hourly_fallback, 'V2 production uses GitHub; direct WordPress SFTP is fallback-only.'],
            ['Legacy direct product cron', $daily_fallback ? 'Scheduled' : 'Off', empty($settings['daily_product_sync']) ? !$daily_fallback : $daily_fallback, 'V2 production uses changed-only GitHub product sync.'],
            ['Active-product audit', $product_issues ? $product_issues . ' product(s) need review' : 'No current health flags', $product_issues === 0, $product_issues ? implode(' · ', array_slice($issue_details, 0, 8)) : 'Prices, images, SKUs, sparse matrix and gallery audits currently look clean.'],
        ];

        echo '<p>This is a read-only V2 readiness check across Supplier Sync, WooCommerce, the GitHub bridges, active supplier products, variation galleries and the ASBO integration contract.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Check</th><th>Status</th><th>Result</th><th>Notes</th></tr></thead><tbody>';
        foreach ($rows as [$label,$value,$ok,$notes]) {
            $badge = $ok ? '<span style="color:#008a20;font-weight:700">PASS</span>' : '<span style="color:#b32d2e;font-weight:700">REVIEW</span>';
            echo '<tr><td><strong>' . esc_html($label) . '</strong></td><td>' . esc_html((string)$value) . '</td><td>' . $badge . '</td><td>' . wp_kses_post($notes) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:18px"><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=asss-active-products')) . '">Review Active Products</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=asss-intelligence')) . '">Supplier Intelligence</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=asss-brands')) . '">Review Brands</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=asss-logs')) . '">Open Sync Logs</a></p>';
        $this->wrap_end();
    }

    public function import_page(): void {
        $brand = sanitize_text_field(wp_unslash($_GET['brand'] ?? ''));
        $style = sanitize_text_field(wp_unslash($_GET['style'] ?? ''));
        $this->wrap_start('Review Supplier Product');
        $this->notice();

        echo '<p><a href="' . esc_url(add_query_arg(['page' => 'asss-suppliers', 'brand' => $brand], admin_url('admin.php'))) . '">&larr; Back to supplier products</a></p>';
        if (!$brand || !$style) {
            echo '<div class="notice notice-error inline"><p>Brand and style are required.</p></div>';
            $this->wrap_end();
            return;
        }

        $data = $this->sanmar->rows_for_style($brand, $style);
        if (is_wp_error($data)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($data->get_error_message()) . '</p></div>';
            $this->wrap_end();
            return;
        }

        $rows = $data['rows'];
        $first = $rows[0] ?? [];
        $title = (string)$this->sanmar->first($first, ['PRODUCT_TITLE','PRODUCT_NAME','TITLE'], $style);
        $description = (string)$this->sanmar->first($first, ['PRODUCT_DESCRIPTION','DESCRIPTION']);
        $category = (string)$this->sanmar->first($first, ['CATEGORY']);
        $image = (string)$this->sanmar->first($first, ['COLOR_PRODUCT_IMAGE','FRONT_FLAT','FRONT_MODEL','THREE_Q_MODEL','PRODUCT_IMAGE']);
        $existing_id = $this->importer->find_product($style, $brand);
        $existing_ss_id = $this->importer->find_ss_product_by_brand_style($brand, $style);

        $colors = [];
        $sizes = [];
        foreach ($rows as $row) {
            $c = trim((string)$this->sanmar->first($row, ['COLOR_NAME','COLOR','CATALOG_COLOR']));
            $z = trim((string)$this->sanmar->first($row, ['SIZE']));
            if ($z !== '') $sizes[$z] = true;
            if ($c !== '' && !isset($colors[$c])) {
                $colors[$c] = [
                    'swatch' => (string)$this->sanmar->first($row, ['COLOR_SQUARE_IMAGE','COLOR_PRODUCT_IMAGE_THUMBNAIL']),
                    'image' => (string)$this->sanmar->first($row, ['COLOR_PRODUCT_IMAGE','FRONT_FLAT','PRODUCT_IMAGE','COLOR_PRODUCT_IMAGE_THUMBNAIL']),
                    'count' => 0,
                    'sizes' => [],
                ];
            }
            if ($c !== '') {
                $colors[$c]['count']++;
                if ($z !== '') $colors[$c]['sizes'][$z] = true;
                if (empty($colors[$c]['image'])) $colors[$c]['image'] = (string)$this->sanmar->first($row, ['COLOR_PRODUCT_IMAGE','FRONT_FLAT','PRODUCT_IMAGE','COLOR_PRODUCT_IMAGE_THUMBNAIL']);
            }
        }

        $selected_existing = [];
        if ($existing_id) {
            $mode = (string)get_post_meta($existing_id, '_asss_sanmar_color_selection_mode', true) ?: (string)get_post_meta($existing_id, '_asss_color_selection_mode', true);
            if ($mode === 'selected') {
                $stored_raw = (string)get_post_meta($existing_id, '_asss_sanmar_selected_colors', true) ?: (string)get_post_meta($existing_id, '_asss_selected_colors', true);
                $stored = json_decode($stored_raw, true);
                if (is_array($stored)) $selected_existing = array_fill_keys($stored, true);
            }
        }

        echo '<div style="display:flex;gap:24px;align-items:flex-start;margin:18px 0 24px;max-width:1000px">';
        if ($image) echo '<img src="' . esc_url($image) . '" alt="" onerror="this.style.display=&quot;none&quot;" style="width:150px;height:150px;object-fit:contain;background:#fff;border:1px solid #dcdcde;border-radius:4px">';
        echo '<div><h2 style="margin-top:0">' . esc_html($title ?: ($brand . ' ' . $style)) . '</h2><p><strong>Style:</strong> ' . esc_html($style) . ' &nbsp; <strong>Brand:</strong> ' . esc_html($brand) . ' &nbsp; <strong>Category:</strong> ' . esc_html($category ?: '—') . '</p>';
        if ($description) echo '<p style="max-width:760px">' . esc_html(wp_trim_words(wp_strip_all_tags($description), 45)) . '</p>';
        $possible_cartesian = count($colors) * max(1, count($sizes));
        $invalid_cartesian = max(0, $possible_cartesian - count($rows));
        echo '<p><strong>' . count($colors) . '</strong> colors &nbsp; <strong>' . count($sizes) . '</strong> global size options &nbsp; <strong>' . count($rows) . '</strong> actual supplier variations</p>';
        if ($invalid_cartesian > 0) {
            echo '<p style="margin-top:8px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1"><strong>Sparse variation matrix:</strong> sizes are not available for every color. Supplier Sync will create only the <strong>' . count($rows) . '</strong> exact color/size combinations SanMar actually lists — not all <strong>' . $possible_cartesian . '</strong> possible Color × Size combinations.</p>';
        }
        echo '</div></div>';

        if ($existing_id) {
            echo '<div class="notice notice-info inline"><p>This style is already linked to WooCommerce product #' . (int)$existing_id . '. Confirming below will <strong>repair/re-sync its supplier structure</strong> while preserving manually set retail prices and custom/manual images.</p></div>';
        } elseif ($existing_ss_id) {
            echo '<div class="notice notice-info inline"><p><strong>Existing S&amp;S product detected:</strong> WooCommerce product <a href="' . esc_url(get_edit_post_link($existing_ss_id)) . '">#' . (int)$existing_ss_id . '</a> appears to be the same Brand + Style. You can link SanMar to it as an additional supplier instead of creating a duplicate product.</p></div>';
        } else {
            echo '<div class="notice notice-info inline"><p>The product will be created as a <strong>Draft</strong>. Supplier cost and MAP data are stored for reference; this importer does not overwrite your retail selling prices.</p></div>';
        }

        echo '<form method="post" id="asss-import-form">';
        wp_nonce_field('asss_import');
        echo '<input type="hidden" name="brand" value="' . esc_attr($brand) . '"><input type="hidden" name="style" value="' . esc_attr($style) . '">';
        if ($existing_ss_id && !$existing_id) echo '<input type="hidden" name="product_id" value="' . (int)$existing_ss_id . '">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;max-width:1000px;margin-top:22px"><h2 style="margin:0">Choose colors</h2><div><button type="button" class="button" id="asss-select-all">Select all</button> <button type="button" class="button" id="asss-select-none">Select none</button></div></div>';
        echo '<p>Only supplier-listed sizes for each checked color will be imported. A size that exists for another color will <strong>not</strong> be synthesized for this color.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:10px;max-width:1100px">';
        foreach ($colors as $color => $info) {
            $checked = !$selected_existing || isset($selected_existing[$color]);
            $size_names = array_keys((array)($info['sizes'] ?? []));
            echo '<label style="display:grid;grid-template-columns:auto 68px 1fr;align-items:center;gap:10px;padding:11px 12px;background:#fff;border:1px solid #dcdcde;border-radius:6px;min-height:74px">';
            echo '<input class="asss-color-check" type="checkbox" name="colors[]" ' . checked($checked, true, false) . ' value="' . esc_attr($color) . '">';
            $card_image = (string)($info['image'] ?? $info['swatch'] ?? '');
            if ($card_image) echo '<img src="' . esc_url($card_image) . '" alt="" style="width:64px;height:64px;object-fit:contain;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:4px">';
            else echo '<div style="width:64px;height:64px;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:4px"></div>';
            echo '<span><strong>' . esc_html($color) . '</strong><br><small>' . (int)$info['count'] . ' real variation' . ((int)$info['count'] === 1 ? '' : 's') . '</small>';
            if ($size_names) { echo '<span style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px">'; foreach ($size_names as $sz) echo '<span style="font-size:11px;line-height:1;padding:4px 6px;background:#f0f0f1;border-radius:10px">' . esc_html($sz) . '</span>'; echo '</span>'; }
            echo '</span></label>';
        }
        echo '</div>';
        $button_name = ($existing_ss_id && !$existing_id) ? 'asss_link_sanmar_existing' : 'asss_import_style';
        $button_label = $existing_id ? 'Repair & Re-sync WooCommerce Product' : (($existing_ss_id && !$existing_id) ? 'Link SanMar to Existing WooCommerce Product' : 'Import to WooCommerce as Draft');
        echo '<p style="margin-top:22px"><button class="button button-primary button-hero" name="' . esc_attr($button_name) . '" value="1">' . esc_html($button_label) . '</button></p>';
        echo '</form>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){const boxes=[...document.querySelectorAll(".asss-color-check")];document.getElementById("asss-select-all")?.addEventListener("click",()=>boxes.forEach(b=>b.checked=true));document.getElementById("asss-select-none")?.addEventListener("click",()=>boxes.forEach(b=>b.checked=false));});</script>';
        $this->wrap_end();
    }

    public function brands_page(): void {
        $supplier = sanitize_key((string)($_GET['supplier'] ?? 'sanmar'));
        if (!in_array($supplier, ['sanmar','ss','momentec'], true)) $supplier = 'sanmar';
        $title = $supplier === 'ss' ? 'S&S Activewear Brands' : ($supplier === 'momentec' ? 'Momentec Brands' : 'SanMar Brands');
        $this->wrap_start($title);
        $this->notice();

        // Compact, left-aligned scan table for every supplier. Faint dividers keep
        // related fields visually grouped without turning the admin into a grid-heavy UI.
        echo '<style>.asss-brand-table{width:auto;min-width:760px;max-width:100%;border-collapse:collapse}.asss-brand-table th,.asss-brand-table td{text-align:left!important;vertical-align:middle;padding:10px 12px;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7}.asss-brand-table th:last-child,.asss-brand-table td:last-child{border-right:0}.asss-brand-table th{white-space:nowrap}.asss-brand-table code{white-space:nowrap}.asss-brand-name{min-width:210px}.asss-brand-file{min-width:280px;max-width:430px}</style>';
        $base = admin_url('admin.php?page=asss-brands');
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
        echo '<a class="nav-tab ' . ($supplier === 'sanmar' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('supplier','sanmar',$base)) . '">SanMar</a>';
        echo '<a class="nav-tab ' . ($supplier === 'ss' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('supplier','ss',$base)) . '">S&amp;S Activewear</a>';
        echo '<a class="nav-tab ' . ($supplier === 'momentec' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('supplier','momentec',$base)) . '">Momentec Brands</a>';
        echo '</nav>';

        if ($supplier === 'momentec') {
            $status = $this->momentec->status();
            echo '<div class="notice notice-info inline"><p><strong>Momentec adapter groundwork is installed.</strong> Live catalog browsing stays off until All Star receives Momentec API credentials and we confirm the exact staging authentication/endpoints. Current state: <code>' . esc_html((string)$status['state']) . '</code>.</p></div>';
            echo '<p>When credentials arrive, this tab will use the same safe flow as S&amp;S: enabled brands → cached catalog → exact supplier Color+Size rows → review → intentional WooCommerce import/link.</p>';
            $this->wrap_end(); return;
        }

        if ($supplier === 'ss') {
            $catalog = $this->ss->brand_catalog();
            if (!$catalog) {
                echo '<div class="notice notice-info inline"><p>No S&amp;S brands have reached WordPress yet. Run <strong>S&amp;S Brand Catalog Sync</strong> in GitHub Actions after installing this version.</p></div>';
                $this->wrap_end(); return;
            }
            $manifest = $this->ss->style_manifest();
            echo '<p>Select the S&amp;S brands you want available in Supplier Sync. Enabling a brand does <strong>not</strong> create WooCommerce products; it makes that brand eligible for the read-only S&amp;S catalog sync/browser.</p>';
            echo '<form method="post">'; wp_nonce_field('asss_brands');
            echo '<input type="hidden" name="supplier" value="ss">';
            echo '<table class="widefat striped asss-brand-table"><thead><tr><th style="width:70px">Enable</th><th class="asss-brand-name">Brand</th><th style="width:100px">Brand ID</th><th style="width:180px">eRetailing</th><th style="width:160px">Cached Styles</th></tr></thead><tbody>';
            foreach ($catalog as $id=>$meta) {
                $restricted = !empty($meta['e_retailing_restricted']);
                $cached = $manifest[(string)$id] ?? [];
                echo '<tr><td><input type="checkbox" name="enabled_brands[]" value="'.(int)$id.'" '.checked(!empty($meta['enabled']),true,false).'></td>';
                echo '<td class="asss-brand-name"><strong>'.esc_html((string)($meta['brand'] ?? '')).'</strong></td><td><code>'.(int)$id.'</code></td>';
                echo '<td>'.($restricted?'<span style="color:#996800">Restricted / review</span>':'<span style="color:#008a20">Allowed</span>').'</td>';
                echo '<td>' . (!empty($cached) ? '<strong>' . (int)($cached['style_count'] ?? 0) . '</strong> styles' : '<span style="color:#646970">Not cached yet</span>') . '</td></tr>';
            }
            echo '</tbody></table><p><button class="button button-primary" name="asss_save_brands" value="1">Save S&amp;S Brand Selections</button></p></form>';
            $this->wrap_end(); return;
        }

        $catalog=$this->sanmar->brand_catalog();
        if (!$catalog) {
            echo '<div class="notice notice-info inline"><p>No live SanMar brand list has been discovered yet. The GitHub automatic product workflow will populate this page from the actual SFTP folder.</p></div>';
            $this->wrap_end(); return;
        }
        echo '<p>Select the brands you want available for automatic product-data syncing. This does <strong>not</strong> create WooCommerce products; it only keeps those supplier catalogs fresh for browsing/importing.</p>';
        echo '<form method="post">'; wp_nonce_field('asss_brands');
        echo '<input type="hidden" name="supplier" value="sanmar">';
        echo '<table class="widefat striped asss-brand-table"><thead><tr><th style="width:70px">Sync</th><th class="asss-brand-name">Brand</th><th class="asss-brand-file">Latest SanMar file</th><th class="asss-brand-file">Last received by WordPress</th></tr></thead><tbody>';
        $manifest=$this->sanmar->bridge_manifest();
        foreach ($catalog as $brand=>$meta) {
            $received=''; foreach ($manifest as $mb=>$mm) if (strcasecmp((string)$mb,(string)$brand)===0) { $received=(string)($mm['source_file'] ?? ''); break; }
            echo '<tr><td><input type="checkbox" name="enabled_brands[]" value="'.esc_attr($brand).'" '.checked(!empty($meta['enabled']),true,false).'></td><td class="asss-brand-name"><strong>'.esc_html($brand).'</strong></td><td class="asss-brand-file"><code>'.esc_html((string)($meta['latest_file'] ?? '')).'</code></td><td class="asss-brand-file">'.($received?'<code>'.esc_html($received).'</code>':'<span style="color:#646970">Not synced yet</span>').'</td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary" name="asss_save_brands" value="1">Save Brand Selections</button></p></form>';
        $this->wrap_end();
    }

    public function sync_page(): void {
        $this->wrap_start('Supplier Sync');
        $this->notice();
        $ids = $this->sync->linked_product_ids();
        echo '<p><strong>' . count($ids) . '</strong> WooCommerce products are linked to Supplier Sync (SanMar and/or S&amp;S Activewear).</p><form method="post" style="display:inline-block;margin-right:10px">';
        wp_nonce_field('asss_sync');
        echo '<button class="button button-primary" name="asss_sync_products" value="1">Run Product Sync / Repair Now</button></form><form method="post" style="display:inline-block">';
        wp_nonce_field('asss_sync');
        echo '<button class="button" name="asss_sync_inventory" value="1">Run Direct Inventory Fallback</button></form>';
        $inventory_status = get_option('asss_inventory_bridge_status', []);
        $ss_inventory_status = get_option('asss_ss_inventory_bridge_status', []);
        $next_sanmar = $this->next_nominal_hourly(17);
        $next_ss = $this->next_nominal_hourly(37);
        echo '<h2>Automatic schedule</h2><p>Product information: whenever GitHub pushes fresh enabled-brand data; linked products are queued for repair automatically.<br>Inventory: SanMar runs hourly at a nominal <strong>:17</strong>; S&amp;S runs hourly at a nominal <strong>:37</strong>. GitHub may start scheduled jobs later than the nominal minute.</p>';
        echo '<h2>GitHub inventory bridges</h2><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;max-width:900px">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px"><h3 style="margin-top:0">SanMar</h3>';
        if (is_array($inventory_status) && !empty($inventory_status['received_at'])) {
            echo '<p><strong>Last successful:</strong> ' . esc_html($inventory_status['received_at']) . '<br><strong>Next nominal:</strong> ' . esc_html($next_sanmar) . '<br><strong>Matched:</strong> ' . esc_html((string)($inventory_status['matched'] ?? 0)) . '<br><strong>Changed:</strong> ' . esc_html((string)($inventory_status['changed'] ?? 0)) . '<br><strong>Source:</strong> <code>' . esc_html((string)($inventory_status['source_file'] ?? 'SanMar inventory feed')) . '</code></p>';
        } else echo '<p>No SanMar GitHub inventory payload has been received yet.</p>';
        echo '</div><div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px"><h3 style="margin-top:0">S&amp;S Activewear</h3>';
        if (is_array($ss_inventory_status) && !empty($ss_inventory_status['received_at'])) {
            echo '<p><strong>Last successful:</strong> ' . esc_html($ss_inventory_status['received_at']) . '<br><strong>Next nominal:</strong> ' . esc_html($next_ss) . '<br><strong>Matched:</strong> ' . esc_html((string)($ss_inventory_status['matched'] ?? 0)) . '<br><strong>Changed:</strong> ' . esc_html((string)($ss_inventory_status['changed'] ?? 0)) . '<br><strong>API requests:</strong> ' . esc_html((string)($ss_inventory_status['api_requests'] ?? 0)) . '<br><strong>Rate limit remaining:</strong> ' . esc_html((string)($ss_inventory_status['rate_limit_remaining'] ?? 'n/a')) . '</p>';
        } else echo '<p>No S&amp;S GitHub inventory payload has been received yet.</p>';
        echo '</div></div>';
        $this->wrap_end();
    }

    public function logs(): void {
        $this->wrap_start('Sync Logs');
        $this->notice();
        $rows = ASSS_Logger::recent(150);
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Details</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . esc_html($r['created_at']) . '</td><td>' . esc_html(strtoupper($r['level'])) . '</td><td>' . esc_html($r['message']) . '</td><td><code style="white-space:pre-wrap">' . esc_html($r['context'] ?: '') . '</code></td></tr>';
        echo '</tbody></table>';
        $this->wrap_end();
    }

    public function settings(): void {
        $s = $this->sanmar->settings();
        $this->wrap_start('Supplier Settings');
        $this->notice();
        echo '<form method="post">';
        wp_nonce_field('asss_settings');
        $bridge_url = rest_url('asss/v1/bridge/sanmar/{BRAND}');
        $ss_brands_url = rest_url('asss/v1/bridge/ss/brands');
        $inventory_targets_url = rest_url('asss/v1/bridge/inventory/sanmar/targets');
        $inventory_receive_url = rest_url('asss/v1/bridge/inventory/sanmar');
        $ss_inventory_targets_url = rest_url('asss/v1/bridge/inventory/ss/targets');
        $ss_inventory_receive_url = rest_url('asss/v1/bridge/inventory/ss');
        echo '<h2>GitHub Bridge (recommended)</h2><p>GitHub Actions connects to SanMar on port 2200, then securely pushes product and inventory data into this site. Your HostGator server does not need direct SFTP access.</p><table class="form-table">' . $this->check('Accept GitHub supplier updates', 'bridge_enabled', $s['bridge_enabled']) . $this->check('Accept GitHub inventory updates', 'bridge_inventory_enabled', $s['bridge_inventory_enabled']) . '<tr><th>SanMar Product Bridge URL</th><td><code style="user-select:all">' . esc_html($bridge_url) . '</code></td></tr><tr><th>S&amp;S Brand Bridge URL</th><td><code style="user-select:all">' . esc_html($ss_brands_url) . '</code></td></tr><tr><th>SanMar Inventory Target URL</th><td><code style="user-select:all">' . esc_html($inventory_targets_url) . '</code><p class="description">GitHub learns which exact SanMar variations this store currently sells.</p></td></tr><tr><th>SanMar Inventory Receive URL</th><td><code style="user-select:all">' . esc_html($inventory_receive_url) . '</code></td></tr><tr><th>S&amp;S Inventory Target URL</th><td><code style="user-select:all">' . esc_html($ss_inventory_targets_url) . '</code><p class="description">GitHub requests only exact active S&amp;S SKUs, keeping API usage and WordPress payloads small.</p></td></tr><tr><th>S&amp;S Inventory Receive URL</th><td><code style="user-select:all">' . esc_html($ss_inventory_receive_url) . '</code></td></tr><tr><th>Bridge Token</th><td><div style="display:flex;gap:8px;align-items:center;max-width:760px"><input id="asss-bridge-token" class="large-text code" type="password" readonly autocomplete="off" value="' . esc_attr($s['bridge_token']) . '"><button type="button" class="button" id="asss-toggle-token" onclick="var i=document.getElementById(&quot;asss-bridge-token&quot;);if(!i)return false;var show=i.getAttribute(&quot;type&quot;)===&quot;password&quot;;i.setAttribute(&quot;type&quot;,show?&quot;text&quot;:&quot;password&quot;);this.textContent=show?&quot;Hide&quot;:&quot;Show&quot;;return false;">Show</button></div><p class="description">Store this in GitHub as <code>ASSS_BRIDGE_TOKEN</code>. It is hidden by default on this screen.</p><button class="button" name="asss_regen_bridge" value="1" formnovalidate onclick="return confirm(\'Generate a new bridge token? The existing GitHub ASSS_BRIDGE_TOKEN secret will stop working until you update it.\');">Generate New Token</button></td></tr></table>';


        $latest_release = $this->updater->latest_release();
        echo '<hr><h2>Plugin Updates via GitHub</h2><p>Supplier Sync can update through a public GitHub Releases repository, so future versions appear in the normal WordPress Plugins / Updates screens without manually uploading a ZIP. No private-repository token is stored on this site.</p><table class="form-table">';
        echo $this->row('Public release repository', 'github_update_repo', $s['github_update_repo'] ?? 'rolejarczyk/ASE.SupplierSync-Releases');
        echo '<tr><th></th><td><p class="description">Use <code>owner/repository</code>. The repository must be public and its latest published release must contain the Supplier Sync ZIP asset.</p></td></tr>';
        echo $this->check('Install Supplier Sync updates automatically in the background', 'github_auto_updates', $s['github_auto_updates'] ?? 0);
        echo '<tr><th>Current plugin version</th><td><code>' . esc_html(ASSS_VERSION) . '</code></td></tr>';
        if (is_wp_error($latest_release)) {
            echo '<tr><th>GitHub release status</th><td><span style="color:#996800">Not connected yet</span><p class="description">' . esc_html($latest_release->get_error_message()) . '</p></td></tr>';
        } else {
            $available = version_compare((string)$latest_release['version'], ASSS_VERSION, '>');
            echo '<tr><th>Latest GitHub release</th><td><strong>v' . esc_html((string)$latest_release['version']) . '</strong> ' . ($available ? '<span style="color:#008a20">— update available</span>' : '<span style="color:#646970">— current</span>') . '</td></tr>';
        }
        echo '</table><p><button class="button" name="asss_check_updates" value="1" formnovalidate>Check GitHub for Updates Now</button></p>';

        $momentec = $this->momentec->status();
        echo '<hr><h2>Momentec Brands API</h2><p>Momentec is registered as Supplier #3. The public Momentec integration material describes staging account credentials. Username and password are now read only from secure server configuration and are never stored in the WordPress database.</p><table class="form-table">';
        echo $this->check('Enable Momentec adapter when credentials are ready', 'momentec_enabled', $s['momentec_enabled'] ?? 0);
        echo '<tr><th>Environment</th><td><select name="momentec_environment"><option value="staging" ' . selected($s['momentec_environment'] ?? 'staging','staging',false) . '>Staging / test</option><option value="production" ' . selected($s['momentec_environment'] ?? 'staging','production',false) . '>Production</option></select></td></tr>';
        echo $this->row('API base URL', 'momentec_api_base', $s['momentec_api_base'] ?? '');
        echo $this->row('Account / customer number', 'momentec_account', $s['momentec_account'] ?? '');
        $momentec_user_ready = !empty($momentec['username_configured']);
        $momentec_pass_ready = !empty($momentec['password_configured']);
        $momentec_source = (string)($momentec['credential_source'] ?? '');
        echo '<tr><th>API username</th><td><strong>' . ($momentec_user_ready ? 'Configured securely' : 'Not configured') . '</strong><p class="description">Set <code>ASSS_MOMENTEC_USERNAME</code> in wp-config.php or as a server environment variable. The username is not stored in WordPress.</p></td></tr>';
        echo '<tr><th>API password</th><td><strong>' . ($momentec_pass_ready ? 'Configured securely' : 'Not configured') . '</strong><p class="description">Set <code>ASSS_MOMENTEC_PASSWORD</code> in wp-config.php or as a server environment variable. The password is never displayed or stored in WordPress.</p></td></tr>';
        echo '<tr><th>Secure credential source</th><td>' . esc_html($momentec_source !== '' ? $momentec_source : 'Not configured') . '</td></tr>';
        echo '<tr><th>wp-config.php template</th><td><pre style="max-width:760px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde">define(&#039;ASSS_MOMENTEC_USERNAME&#039;, &#039;YOUR_MOMENTEC_API_USERNAME&#039;);\ndefine(&#039;ASSS_MOMENTEC_PASSWORD&#039;, &#039;YOUR_MOMENTEC_API_PASSWORD&#039;);</pre><p class="description">Add these above the “That’s all, stop editing” line in wp-config.php. Never paste the real values into GitHub, the WordPress settings form, support tickets, or chat.</p></td></tr>';
        echo '<tr><th>Adapter status</th><td><strong>' . esc_html(!empty($momentec['configured']) ? 'Secure credentials ready for staging adapter work' : 'Awaiting secure Momentec API credentials') . '</strong><p class="description">Supplier Sync still will not guess the exact login request or authentication header. We will verify Momentec staging first before enabling catalog or inventory calls.</p></td></tr></table>';

        echo '<hr><h2>Direct Supplier File Connection (optional / fallback)</h2><p>The GitHub bridge is recommended on shared hosting. These fields are only needed for direct server-side file access or manual fallback testing.</p><table class="form-table">';
        echo '<tr><th>Protocol</th><td><select name="transfer_protocol"><option value="sftp" ' . selected($s['transfer_protocol'], 'sftp', false) . '>SFTP</option><option value="ftps" ' . selected($s['transfer_protocol'], 'ftps', false) . '>FTPS</option><option value="ftp" ' . selected($s['transfer_protocol'], 'ftp', false) . '>FTP</option></select></td></tr>';
        echo $this->row('Host / Server', 'ftp_host', $s['ftp_host']);
        echo $this->row('Port', 'ftp_port', $s['ftp_port']);
        echo $this->row('Username', 'ftp_user', $s['ftp_user']);
        echo $this->row('Password', 'ftp_pass', $s['ftp_pass'], 'password');
        echo $this->row('Product CSV Folder (remote)', 'ftp_base_dir', $s['ftp_base_dir']);
        echo $this->row('Inventory File (remote, optional)', 'inventory_path', $s['inventory_path']);
        echo $this->check('Passive FTP/FTPS', 'ftp_passive', $s['ftp_passive']);
        echo '</table><p><button class="button" name="asss_test_ftp" value="1">Test File Connection</button> <button class="button" name="asss_test_inventory" value="1">Test Inventory File</button></p>';

        echo '<hr><h2>Sync behavior</h2><table class="form-table">';
        echo $this->check('Automatic GitHub product-data sync', 'automatic_product_bridge', $s['automatic_product_bridge']);
        echo $this->row('Inventory safety buffer', 'stock_buffer', $s['stock_buffer']);
        echo $this->check('Sync supplier images', 'sync_images', $s['sync_images']);
        echo $this->check('Sync descriptions', 'sync_description', $s['sync_description']);
        echo $this->check('Add new supplier variations automatically', 'sync_new_variations', $s['sync_new_variations']);
        echo $this->check('Manage WooCommerce variation pricing from supplier wholesale', 'sync_variation_base_prices', $s['sync_variation_base_prices']);
        echo '<tr><th></th><td><p class="description">Supplier-managed variation Main Price = preferred available supplier wholesale + $20. The setting also supports automatic ASBO pricing when Bulk Order integration is enabled. If you manually change a managed variation price, Supplier Sync detects the change and stops overwriting that price.</p></td></tr>';
        echo $this->check('Daily product sync fallback', 'daily_product_sync', $s['daily_product_sync']);
        echo $this->check('Hourly inventory sync (direct SFTP fallback only)', 'hourly_inventory_sync', $s['hourly_inventory_sync']);
        echo '<tr><th></th><td><p class="description">Leave this off on HostGator shared hosting. Automatic SanMar inventory will run through GitHub instead.</p></td></tr>';
        echo $this->row('Admin notification email', 'admin_notify', $s['admin_notify']);
        echo '</table>';

        echo '<hr><h2>Multi-supplier behavior</h2><p>When the same WooCommerce variation is carried by more than one supplier, Supplier Sync keeps each supplier\'s stock and cost separate, then calculates one safe WooCommerce stock quantity.</p><table class="form-table">';
        echo '<tr><th>Inventory strategy</th><td><select name="multi_inventory_strategy"><option value="combined" ' . selected($s['multi_inventory_strategy'] ?? 'combined','combined',false) . '>Combined inventory from all active suppliers</option><option value="preferred" ' . selected($s['multi_inventory_strategy'] ?? 'combined','preferred',false) . '>Preferred supplier inventory only</option></select><p class="description">Combined is the default: if either supplier can fulfill the variation, the store can keep selling it. Supplier orders are never placed automatically.</p></td></tr>';
        echo $this->row('Supplier priority', 'supplier_priority', $s['supplier_priority'] ?? 'ss,sanmar,momentec');
        echo '<tr><th></th><td><p class="description">Comma-separated supplier keys. Recommended while Momentec is inactive: <code>ss,sanmar,momentec</code>. Priority chooses the suggested fulfillment source, the source used by Preferred-only inventory mode, and the wholesale basis for automatic pricing when that supplier carries the exact variation.</p></td></tr>';
        echo $this->check('Enable Supplier Intelligence screens', 'supplier_intelligence_enabled', $s['supplier_intelligence_enabled'] ?? 1);
        echo '</table>';

        echo '<hr><h2>WooCommerce / Bulk Order integration</h2><p>These settings help imported supplier products work with your All Star Bulk Order product fields. Supplier-managed ASBO pricing uses Embroidery tiers of Main / Main−$2 / Main−$4 / Main−$6 at 1 / 20 / 50 / 100 units, with Patch always +$3. Existing merchant-edited pricing is preserved.</p><table class="form-table">';
        echo $this->check('Auto-fill All Star Bulk Order fields', 'sync_bulk_order_fields', $s['sync_bulk_order_fields']);
        echo $this->check('Automatically enable imported products on bulk order page', 'auto_enable_bulk_order', $s['auto_enable_bulk_order']);
        echo '<tr><th>Fallback shipping dimensions (inches)</th><td><div style="display:flex;gap:8px;align-items:center">';
        echo '<label>Length <input style="width:90px" type="number" min="0" step="0.01" name="fallback_length_in" value="' . esc_attr($s['fallback_length_in']) . '"></label>';
        echo '<label>Width <input style="width:90px" type="number" min="0" step="0.01" name="fallback_width_in" value="' . esc_attr($s['fallback_width_in']) . '"></label>';
        echo '<label>Height <input style="width:90px" type="number" min="0" step="0.01" name="fallback_height_in" value="' . esc_attr($s['fallback_height_in']) . '"></label>';
        echo '</div><p class="description">SanMar\'s Brand CSV provides weight but not WooCommerce shipping length/width/height. Leave these blank unless you want a store-defined fallback for imported products. Existing product dimensions are never overwritten by fallback values.</p></td></tr>';
        echo '</table><p><button class="button button-primary" name="asss_save_settings" value="1">Save Settings</button></p></form>';
        $this->wrap_end();
    }

    private function next_nominal_hourly(int $minute): string {
        $minute = max(0, min(59, $minute));
        $now = current_datetime();
        $next = $now->setTime((int)$now->format('G'), $minute, 0);
        if ($next <= $now) $next = $next->modify('+1 hour');
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next->getTimestamp(), wp_timezone());
    }

    private function row($label, $name, $value, $type = 'text'): string {
        return '<tr><th>' . esc_html($label) . '</th><td><input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function check($label, $name, $value): string {
        return '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($value), true, false) . '> Enabled</label></td></tr>';
    }

    private function wrap_start($title): void {
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
    }

    private function wrap_end(): void {
        echo '</div>';
    }

    public function meta_box(): void {
        add_meta_box('asss_supplier', 'Supplier Integration', [$this, 'render_meta'], 'product', 'side', 'default');
    }

    public function render_meta($post): void {
        $supplier = get_post_meta($post->ID, '_asss_supplier', true);
        if (!$supplier) {
            echo '<p>Not linked to a supplier.</p>';
            return;
        }
        if ($supplier === 'multi') {
            $sources=array_filter($this->multi->product_sources((int)$post->ID),static fn($v)=>is_array($v)&&!empty($v['enabled']));
            $labels=[];foreach(array_keys($sources) as $src)$labels[]=ASSS_MultiSupplier::suppliers()[$src]??$src;
            $coverage=$this->multi->product_coverage((int)$post->ID);
            echo '<p><strong>Suppliers:</strong> '.esc_html(implode(' + ',$labels)).'<br><strong>Coverage:</strong> '.(int)$coverage['both'].' both / '.(int)$coverage['sanmar_only'].' SanMar-only / '.(int)$coverage['ss_only'].' S&amp;S-only<br><strong>Last product sync:</strong> '.esc_html(get_post_meta($post->ID,'_asss_last_product_sync',true)?:'Not yet').'</p>';
            echo '<p><a class="button" href="'.esc_url(add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>$post->ID],admin_url('admin.php'))).'">Manage Suppliers</a></p>';
            return;
        } elseif ($supplier === 'ss') {
            $label = 'S&S Activewear';
            $brand = get_post_meta($post->ID, '_asss_ss_brand', true);
            $style = get_post_meta($post->ID, '_asss_ss_style', true);
        } elseif ($supplier === 'momentec') {
            $label = 'Momentec Brands';
            $brand = get_post_meta($post->ID, '_asss_momentec_brand', true);
            $style = get_post_meta($post->ID, '_asss_momentec_style', true);
        } else {
            $label = 'SanMar';
            $brand = get_post_meta($post->ID, '_asss_sanmar_brand', true);
            $style = get_post_meta($post->ID, '_asss_sanmar_style', true);
        }
        echo '<p><strong>Supplier:</strong> ' . esc_html($label) . '<br><strong>Brand:</strong> ' . esc_html($brand) . '<br><strong>Style:</strong> ' . esc_html($style) . '<br><strong>Color mode:</strong> ' . esc_html(get_post_meta($post->ID, '_asss_color_selection_mode', true) ?: 'all') . '<br><strong>Last product sync:</strong> ' . esc_html(get_post_meta($post->ID, '_asss_last_product_sync', true) ?: 'Not yet') . '</p>';
    }
}
