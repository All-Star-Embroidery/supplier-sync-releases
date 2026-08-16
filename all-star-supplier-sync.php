<?php
/**
 * Plugin Name: All Star Supplier Sync
 * Description: Curated supplier-to-WooCommerce synchronization framework. SanMar, S&S Activewear, and Momentec production supplier connectors with full catalog browsing through GitHub Actions.
 * Version: 2.0.12
 * Author: All Star
 * Update URI: https://github.com/rolejarczyk/ASE.SupplierSync-Releases
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 */

if (!defined('ABSPATH')) exit;

define('ASSS_VERSION', '2.0.12');
define('ASSS_FILE', __FILE__);
define('ASSS_DIR', plugin_dir_path(__FILE__));
define('ASSS_URL', plugin_dir_url(__FILE__));

require_once ASSS_DIR . 'includes/class-asss-logger.php';
require_once ASSS_DIR . 'includes/class-asss-sanmar.php';
require_once ASSS_DIR . 'includes/class-asss-ss.php';
require_once ASSS_DIR . 'includes/class-asss-momentec.php';
require_once ASSS_DIR . 'includes/class-asss-multi.php';
require_once ASSS_DIR . 'includes/class-asss-importer.php';
require_once ASSS_DIR . 'includes/class-asss-sync.php';
require_once ASSS_DIR . 'includes/class-asss-bridge.php';
require_once ASSS_DIR . 'includes/class-asss-admin.php';
require_once ASSS_DIR . 'includes/class-asss-updater.php';

final class ASSS_Plugin {
    private static $instance;
    public ASSS_SanMar $sanmar;
    public ASSS_SS $ss;
    public ASSS_Momentec $momentec;
    public ASSS_MultiSupplier $multi;
    public ASSS_Importer $importer;
    public ASSS_Sync $sync;
    public ASSS_Bridge $bridge;
    public ASSS_Admin $admin;
    public ASSS_Updater $updater;

    public static function instance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'boot']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function boot(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function(){
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p><strong>All Star Supplier Sync</strong> requires WooCommerce.</p></div>';
                }
            });
            return;
        }

        $this->sanmar = new ASSS_SanMar();
        $this->ss = new ASSS_SS();
        $this->momentec = new ASSS_Momentec();
        $this->multi = new ASSS_MultiSupplier($this->sanmar);
        $this->importer = new ASSS_Importer($this->sanmar, $this->ss, $this->momentec, $this->multi);
        $this->sync = new ASSS_Sync($this->sanmar, $this->importer, $this->multi);
        $this->bridge = new ASSS_Bridge($this->sanmar, $this->ss, $this->momentec, $this->sync);
        $this->updater = new ASSS_Updater($this->sanmar, ASSS_FILE);
        $this->admin = new ASSS_Admin($this->sanmar, $this->ss, $this->momentec, $this->importer, $this->sync, $this->multi, $this->updater);
        add_action('admin_init', [$this, 'maybe_migrate_managed_asbo_pricing_v209']);
        add_action('admin_init', [$this, 'enforce_sitewide_asbo_pricing_v210'], 30);
        $this->reconcile_fallback_schedules();

        // WooCommerce 10.9+ supports native variation galleries. Supplier Sync
        // fills those galleries when available and exposes a compact read-only
        // preview in the classic variation editor for easier auditing.
        add_action('admin_notices', [$this, 'variation_gallery_feature_notice']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_gallery_admin'], 20, 3);
        add_filter('woocommerce_available_variation', [$this, 'expose_variation_gallery_data'], 20, 3);
        // Front-end integrations such as ASBO often call get_children() directly.
        // Keep historical private/stale supplier rows in WooCommerce for orders,
        // but do not expose them as selectable storefront children.
        add_filter('woocommerce_get_children', [$this, 'filter_supplier_children_for_frontend'], 20, 3);

        // If an admin deliberately republishes a supplier-archived product, treat
        // that as an explicit restore. Automatic supplier jobs never republish an
        // archived parent on their own.
        add_action('woocommerce_admin_process_product_object', [$this, 'maybe_restore_supplier_product'], 5);
    }


    /**
     * v2.0.9 changes only the quantity-discount ladder for matrices that are
     * still owned by Supplier Sync. Merchant-edited matrices are detected by
     * the importer and permanently left alone.
     */
    public function maybe_migrate_managed_asbo_pricing_v209(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_managed_asbo_pricing_schema', '') === '2.0.9') return;
        $result = $this->importer->migrate_managed_asbo_pricing_v209();
        if (!empty($result['complete'])) {
            update_option('asss_managed_asbo_pricing_schema', '2.0.9', false);
        }
    }


    /**
     * v2.0.10 makes the All Star quantity ladder a site-wide ASBO policy.
     * Every bulk-order-enabled product is normalized, regardless of whether it
     * came from Supplier Sync or was created manually. This runs in WooCommerce
     * admin requests so newly created/manual products are covered too.
     */
    public function enforce_sitewide_asbo_pricing_v210(): void {
        if (!current_user_can('manage_woocommerce')) return;
        static $ran = false;
        if ($ran) return;
        $ran = true;
        $this->importer->enforce_sitewide_asbo_pricing_v210();
        update_option('asss_sitewide_asbo_pricing_schema', '2.0.10', false);
    }


    private function reconcile_fallback_schedules(): void {
        $settings = $this->sanmar->settings();
        if (!empty($settings['hourly_inventory_sync'])) {
            if (!wp_next_scheduled('asss_hourly_inventory')) wp_schedule_event(time() + 300, 'hourly', 'asss_hourly_inventory');
        } else {
            wp_clear_scheduled_hook('asss_hourly_inventory');
        }

        if (!empty($settings['daily_product_sync'])) {
            if (!wp_next_scheduled('asss_daily_products')) wp_schedule_event(time() + 600, 'daily', 'asss_daily_products');
        } else {
            wp_clear_scheduled_hook('asss_daily_products');
            wp_clear_scheduled_hook('asss_delayed_product_ingest');
        }
    }

    private function variation_gallery_ids(int $variation_id): array {
        $variation = wc_get_product($variation_id);
        $ids = [];
        if ($variation instanceof WC_Product_Variation && method_exists($variation, 'get_gallery_image_ids')) {
            $primary = (int)$variation->get_image_id();
            if ($primary) $ids[] = $primary;
            foreach ((array)$variation->get_gallery_image_ids() as $id) {
                $id = (int)$id;
                if ($id) $ids[] = $id;
            }
        }
        if (!$ids) {
            $fallback = get_post_meta($variation_id, '_asss_variation_gallery_ids', true);
            if (is_string($fallback)) {
                $decoded = json_decode($fallback, true);
                $fallback = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $fallback);
            }
            foreach ((array)$fallback as $id) {
                $id = (int)$id;
                if ($id) $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public function expose_variation_gallery_data(array $data, $product, $variation): array {
        if (!$variation instanceof WC_Product_Variation) return $data;
        $gallery = [];
        foreach ($this->variation_gallery_ids($variation->get_id()) as $id) {
            $full = wp_get_attachment_image_url($id, 'full');
            if (!$full) continue;
            $gallery[] = [
                'id' => $id,
                'src' => $full,
                'thumb' => wp_get_attachment_image_url($id, 'woocommerce_gallery_thumbnail') ?: $full,
                'alt' => get_post_meta($id, '_wp_attachment_image_alt', true),
            ];
        }
        if ($gallery) $data['asss_variation_gallery'] = $gallery;
        return $data;
    }

    public function render_variation_gallery_admin($loop, $variation_data, $variation): void {
        $variation_id = is_object($variation) ? (int)$variation->ID : 0;
        if (!$variation_id || (string)get_post_meta($variation_id, '_asss_supplier', true) === '') return;
        $ids = $this->variation_gallery_ids($variation_id);
        if (!$ids) return;
        echo '<div class="form-row form-row-full" style="padding:8px 0 2px;clear:both">';
        echo '<strong>Supplier variation gallery</strong> <span class="description">(' . count($ids) . ' images; managed by All Star Supplier Sync)</span><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:7px">';
        foreach ($ids as $id) {
            echo wp_get_attachment_image($id, [62,62], false, ['style'=>'width:62px;height:62px;object-fit:contain;border:1px solid #dcdcde;background:#fff;border-radius:3px']);
        }
        echo '</div></div>';
    }

    public function variation_gallery_feature_notice(): void {
        if (!defined('WC_VERSION') || version_compare(WC_VERSION, '10.9', '<')) return;
        if ((string)get_option('wc_feature_woocommerce_additional_variation_images_enabled') === 'yes') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        $relevant = $screen->post_type === 'product' || strpos((string)$screen->id, 'asss') !== false;
        if (!$relevant || !current_user_can('manage_woocommerce')) return;
        echo "<div class=\"notice notice-warning\"><p><strong>All Star Supplier Sync:</strong> Multiple supplier images are being imported for each variation, but WooCommerce's native <strong>Variation Gallery</strong> feature is currently off. Enable it under <strong>WooCommerce → Settings → Advanced → Features → Variation Gallery</strong> to show each color's full image set on product pages.</p></div>";
    }

    public function activate(): void {
        $defaults = [
            'transfer_protocol' => 'sftp', 'ftp_host' => '', 'ftp_port' => 22, 'ftp_passive' => 1,
            'ftp_user' => '', 'ftp_pass' => '', 'ftp_base_dir' => '/SanMarPDD/SanMarPI',
            'inventory_path' => '',
            'ws_customer' => '', 'ws_user' => '', 'ws_pass' => '',
            'request_brand_files' => 0,
            'stock_buffer' => 0,
            'sync_images' => 1,
            'sync_description' => 1,
            'sync_new_variations' => 1,
            'sync_variation_base_prices' => 1,
            'daily_product_sync' => 0,
            'hourly_inventory_sync' => 0,
            'bridge_inventory_enabled' => 1,
            'admin_notify' => get_option('admin_email'),
            'bridge_token' => wp_generate_password(48, false, false),
            'bridge_enabled' => 1,
            'sync_bulk_order_fields' => 1,
            'auto_enable_bulk_order' => 1,
            'fallback_length_in' => '',
            'fallback_width_in' => '',
            'fallback_height_in' => '',
            'multi_inventory_strategy' => 'combined',
            'supplier_priority' => 'ss,sanmar,momentec',
            'supplier_intelligence_enabled' => 1,
            'github_update_repo' => 'rolejarczyk/ASE.SupplierSync-Releases',
            'github_auto_updates' => 0,
            'momentec_enabled' => 0,
        ];
        if (!get_option('asss_settings')) add_option('asss_settings', $defaults, '', false);
        ASSS_Logger::install();
        // GitHub Actions is the V2 production scheduler. Legacy direct WordPress
        // transfer crons are created on boot only when an administrator explicitly
        // enables the fallback settings.
    }

    public function filter_supplier_children_for_frontend(array $children, $product, $visible_only = false): array {
        if (is_admin()) return $children;
        if (!$product instanceof WC_Product_Variable) return $children;
        if ((string)$product->get_meta('_asss_supplier') === '') return $children;
        return array_values(array_filter(array_map('intval', $children), static function(int $variation_id): bool {
            if ($variation_id < 1) return false;
            if (get_post_status($variation_id) !== 'publish') return false;
            if ((string)get_post_meta($variation_id, '_asss_stale_variation', true) === 'yes') return false;
            if ((string)get_post_meta($variation_id, '_asss_discontinued_variation', true) === 'yes') return false;
            return true;
        }));
    }

    public function maybe_restore_supplier_product(WC_Product $product): void {
        if ((string)$product->get_meta('_asss_supplier_archived') !== 'yes') return;
        if ($product->get_status('edit') !== 'publish') return;

        $product->delete_meta_data('_asss_supplier_archived');
        $product->delete_meta_data('_asss_supplier_archived_at');
        $product->delete_meta_data('_asss_discontinued');
        $product->delete_meta_data('_asss_discontinued_status');
        $product->delete_meta_data('_asss_supplier_reactivated');
        $product->delete_meta_data('_asss_supplier_reactivated_at');
        if ($product->get_catalog_visibility('edit') === 'hidden') {
            $product->set_catalog_visibility('visible');
        }
        ASSS_Logger::log('Supplier product manually restored by administrator', 'info', [
            'product_id' => $product->get_id(),
            'supplier' => (string)$product->get_meta('_asss_supplier'),
        ]);
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook('asss_hourly_inventory');
        wp_clear_scheduled_hook('asss_daily_products');
        wp_clear_scheduled_hook('asss_delayed_product_ingest');
    }
}

ASSS_Plugin::instance();
