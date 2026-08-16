#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v209_patch.py <source-dir>')
root = Path(sys.argv[1])


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'v2.0.9 patch marker missing: {label}')
    return text.replace(old, new, 1)

# Version bump.
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = replace_once(text, 'Version: 2.0.8', 'Version: 2.0.9', 'plugin header version')
text = replace_once(text, "define('ASSS_VERSION', '2.0.8');", "define('ASSS_VERSION', '2.0.9');", 'ASSS_VERSION')

# Run a safe one-time migration from the WordPress admin after the importer exists.
needle = "        $this->admin = new ASSS_Admin($this->sanmar, $this->ss, $this->momentec, $this->importer, $this->sync, $this->multi, $this->updater);\n        $this->reconcile_fallback_schedules();\n"
replacement = "        $this->admin = new ASSS_Admin($this->sanmar, $this->ss, $this->momentec, $this->importer, $this->sync, $this->multi, $this->updater);\n        add_action('admin_init', [$this, 'maybe_migrate_managed_asbo_pricing_v209']);\n        $this->reconcile_fallback_schedules();\n"
text = replace_once(text, needle, replacement, 'admin migration hook')

method_marker = "\n\n    private function reconcile_fallback_schedules(): void {\n"
method = r'''

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
'''
text = replace_once(text, method_marker, method + method_marker, 'main migration method')
main.write_text(text, encoding='utf-8')

# Pricing matrix + migration behavior.
importer = root / 'includes/class-asss-importer.php'
text = importer.read_text(encoding='utf-8')
old_matrix = "        $embroidery = [1=>$main_price, 20=>$main_price-2.0, 50=>$main_price-4.0, 100=>$main_price-6.0];\n"
new_matrix = "        // All Star bulk hat discount ladder: fixed dollars off per item.\n        $embroidery = [6=>$main_price, 9=>$main_price-1.0, 12=>$main_price-2.0, 24=>$main_price-3.0, 48=>$main_price-4.0, 96=>$main_price-6.0, 144=>$main_price-7.0, 288=>$main_price-9.0];\n"
text = replace_once(text, old_matrix, new_matrix, 'managed ASBO pricing ladder')

apply_marker = r'''    private function apply_managed_asbo_matrix(int $product_id, float $main_price, string $basis_supplier): void {
        $matrix = $this->build_managed_asbo_matrix($main_price);
        update_post_meta($product_id, '_asbo_pricing_matrix', $matrix);
        update_post_meta($product_id, '_asss_asbo_pricing_managed', 'yes');
        update_post_meta($product_id, '_asss_asbo_pricing_last_value', $matrix);
        update_post_meta($product_id, '_asss_asbo_pricing_basis_main', (string)wc_format_decimal($main_price, wc_get_price_decimals()));
        update_post_meta($product_id, '_asss_asbo_pricing_basis_supplier', sanitize_key($basis_supplier));
    }
'''
if apply_marker not in text:
    raise SystemExit('v2.0.9 patch marker missing: apply_managed_asbo_matrix')
migration = apply_marker + r'''

    /**
     * Upgrade existing Supplier Sync-owned ASBO matrices to the v2.0.9 ladder
     * without recalculating their base selling price. The saved pricing basis is
     * reused so this migration changes only quantity discounts.
     */
    public function migrate_managed_asbo_pricing_v209(): array {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish','draft','private','pending'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_asss_asbo_pricing_managed',
            'meta_value' => 'yes',
        ]);
        $updated = 0;
        $merchant_owned = 0;
        $deferred = 0;
        foreach ((array)$ids as $product_id) {
            $product_id = (int)$product_id;
            if ($product_id < 1) continue;
            $main = (float)get_post_meta($product_id, '_asss_asbo_pricing_basis_main', true);
            if ($main <= 0) {
                $deferred++;
                continue;
            }
            $basis_supplier = (string)get_post_meta($product_id, '_asss_asbo_pricing_basis_supplier', true);
            $candidate = $this->build_managed_asbo_matrix($main);
            if (!$this->managed_asbo_matrix_can_update($product_id, $candidate)) {
                $merchant_owned++;
                continue;
            }
            $this->apply_managed_asbo_matrix($product_id, $main, $basis_supplier ?: 'mixed');
            $updated++;
        }
        ASSS_Logger::log('v2.0.9 managed ASBO pricing ladder migration completed', 'info', [
            'updated'=>$updated,
            'merchant_owned'=>$merchant_owned,
            'deferred'=>$deferred,
        ]);
        return ['complete'=>true,'updated'=>$updated,'merchant_owned'=>$merchant_owned,'deferred'=>$deferred];
    }
'''
text = text.replace(apply_marker, migration, 1)
importer.write_text(text, encoding='utf-8')
