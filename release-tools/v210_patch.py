#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v210_patch.py <source-dir>')
root = Path(sys.argv[1])


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'v2.0.10 patch marker missing: {label}')
    return text.replace(old, new, 1)

# Version + site-wide enforcement hook.
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = replace_once(text, 'Version: 2.0.9', 'Version: 2.0.10', 'plugin header version')
text = replace_once(text, "define('ASSS_VERSION', '2.0.9');", "define('ASSS_VERSION', '2.0.10');", 'ASSS_VERSION')
text = replace_once(
    text,
    "        add_action('admin_init', [$this, 'maybe_migrate_managed_asbo_pricing_v209']);\n        $this->reconcile_fallback_schedules();\n",
    "        add_action('admin_init', [$this, 'maybe_migrate_managed_asbo_pricing_v209']);\n        add_action('admin_init', [$this, 'enforce_sitewide_asbo_pricing_v210'], 30);\n        $this->reconcile_fallback_schedules();\n",
    'site-wide ASBO admin enforcement hook',
)

marker = "\n\n    private function reconcile_fallback_schedules(): void {\n"
method = r'''

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
'''
text = replace_once(text, marker, method + marker, 'site-wide ASBO enforcement method')
main.write_text(text, encoding='utf-8')

# Add site-wide matrix normalization to importer. This intentionally preserves
# each decoration method's existing first-tier price while replacing only the
# quantity breakpoints / dollar-off ladder.
importer = root / 'includes/class-asss-importer.php'
text = importer.read_text(encoding='utf-8')

anchor = r'''    /**
     * 2.0.1 pricing engine.
'''
if anchor not in text:
    raise SystemExit('v2.0.10 patch marker missing: pricing engine anchor')

methods = r'''
    /** Fixed per-item dollars-off ladder used across the entire ASBO catalog. */
    private function sitewide_asbo_discount_offsets(): array {
        return [6=>0.0, 9=>1.0, 12=>2.0, 24=>3.0, 48=>4.0, 96=>6.0, 144=>7.0, 288=>9.0];
    }

    private function sitewide_asbo_tier_string(float $base): string {
        $parts = [];
        foreach ($this->sitewide_asbo_discount_offsets() as $qty=>$discount) {
            $parts[] = $qty . ':' . number_format(max(0.01, $base - $discount), 2, '.', '');
        }
        return implode(',', $parts);
    }

    /**
     * Preserve every existing decoration method and its current starting price,
     * but force all methods onto the same All Star quantity ladder.
     */
    private function normalize_sitewide_asbo_matrix(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) continue;
            [$label, $tiers_raw] = array_map('trim', explode('|', $line, 2));
            if ($label === '') continue;
            $tiers = [];
            foreach (explode(',', $tiers_raw) as $pair) {
                if (strpos($pair, ':') === false) continue;
                [$qty, $price] = array_map('trim', explode(':', $pair, 2));
                $qty = absint($qty);
                if (!$qty || !is_numeric($price) || (float)$price <= 0) continue;
                $tiers[$qty] = (float)$price;
            }
            if (!$tiers) continue;
            ksort($tiers, SORT_NUMERIC);
            $first_qty = array_key_first($tiers);
            if ($first_qty === null) continue;
            $base = (float)$tiers[$first_qty];
            if ($base <= 0) continue;
            $out[] = $label . '|' . $this->sitewide_asbo_tier_string($base);
        }
        return $out ? implode("\n", $out) : null;
    }

    /**
     * Safe fallback for a bulk-order product that is enabled but has no usable
     * matrix yet. Supplier-managed products retain their saved basis; otherwise
     * use the highest current Woo regular price so a shared matrix cannot
     * underprice a higher-priced variation.
     */
    private function sitewide_asbo_fallback_base(int $product_id): ?float {
        $saved = (float)get_post_meta($product_id, '_asss_asbo_pricing_basis_main', true);
        if ($saved > 0) return $saved;
        $product = wc_get_product($product_id);
        if (!$product) return null;
        $prices = [];
        if ($product instanceof WC_Product_Variable) {
            foreach ($this->variation_ids_direct($product_id) as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation instanceof WC_Product_Variation) continue;
                $price = $variation->get_regular_price('edit');
                if ($price !== '' && is_numeric($price) && (float)$price > 0) $prices[] = (float)$price;
            }
        } else {
            $price = $product->get_regular_price('edit');
            if ($price === '') $price = $product->get_price('edit');
            if ($price !== '' && is_numeric($price) && (float)$price > 0) $prices[] = (float)$price;
        }
        return $prices ? max($prices) : null;
    }

    /**
     * Normalize one ASBO-enabled product. Manual edits to the base price are
     * respected: if a Supplier Sync-managed matrix was changed by the merchant,
     * Supplier Sync relinquishes price ownership but the universal ladder is
     * still applied using the merchant's new starting price.
     */
    public function enforce_sitewide_asbo_pricing_for_product(int $product_id): array {
        if ($product_id < 1 || (string)get_post_meta($product_id, '_asbo_enabled', true) !== 'yes') {
            return ['status'=>'not_enabled'];
        }
        $current = trim((string)get_post_meta($product_id, '_asbo_pricing_matrix', true));
        $managed = (string)get_post_meta($product_id, '_asss_asbo_pricing_managed', true) === 'yes';
        $last = trim((string)get_post_meta($product_id, '_asss_asbo_pricing_last_value', true));
        $merchant_changed = $managed && $last !== '' && !hash_equals(hash('sha256', $last), hash('sha256', $current));

        if ($merchant_changed) {
            foreach (['_asss_asbo_pricing_managed','_asss_asbo_pricing_last_value','_asss_asbo_pricing_basis_main','_asss_asbo_pricing_basis_supplier'] as $key) {
                delete_post_meta($product_id, $key);
            }
            $managed = false;
            ASSS_Logger::log('ASBO base pricing ownership changed to merchant; universal quantity ladder retained.', 'info', ['product_id'=>$product_id]);
        }

        $candidate = $this->normalize_sitewide_asbo_matrix($current);
        if ($candidate === null) {
            $base = $this->sitewide_asbo_fallback_base($product_id);
            if ($base === null || $base <= 0) return ['status'=>'skipped_no_base'];
            // No usable matrix exists, so use the standard All Star defaults.
            $candidate = $this->build_managed_asbo_matrix($base);
        }

        if ($current !== '' && hash_equals(hash('sha256', $current), hash('sha256', $candidate))) {
            update_post_meta($product_id, '_asss_asbo_sitewide_ladder', '2.0.10');
            return ['status'=>'unchanged'];
        }

        update_post_meta($product_id, '_asbo_pricing_matrix', $candidate);
        update_post_meta($product_id, '_asss_asbo_sitewide_ladder', '2.0.10');
        if ($managed) update_post_meta($product_id, '_asss_asbo_pricing_last_value', $candidate);
        return ['status'=>'updated'];
    }

    /** Apply the universal ladder to every product currently enabled for ASBO. */
    public function enforce_sitewide_asbo_pricing_v210(): array {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish','draft','private','pending'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_asbo_enabled',
            'meta_value' => 'yes',
        ]);
        $counts = ['updated'=>0,'unchanged'=>0,'skipped_no_base'=>0,'not_enabled'=>0];
        foreach ((array)$ids as $product_id) {
            $result = $this->enforce_sitewide_asbo_pricing_for_product((int)$product_id);
            $status = (string)($result['status'] ?? '');
            if (isset($counts[$status])) $counts[$status]++;
        }
        ASSS_Logger::log('v2.0.10 site-wide ASBO pricing ladder enforcement completed', 'info', $counts + ['products'=>count((array)$ids)]);
        return ['complete'=>true,'products'=>count((array)$ids)] + $counts;
    }

'''
text = replace_once(text, anchor, methods + anchor, 'site-wide pricing methods')

# Refresh stale pricing-engine documentation so future work does not restore old tiers.
text = text.replace(
    '     * - Embroidery discounts are $0/$2/$4/$6 at 1/20/50/100 units.\n',
    '     * - All ASBO products use $0/$1/$2/$3/$4/$6/$7/$9 off at 6/9/12/24/48/96/144/288 units.\n',
    1,
)

importer.write_text(text, encoding='utf-8')
