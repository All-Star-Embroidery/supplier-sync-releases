#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v2011_patch.py <source-dir>')
root = Path(sys.argv[1])


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'v2.0.11 patch marker missing: {label}')
    return text.replace(old, new, 1)

main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = replace_once(text, 'Version: 2.0.10', 'Version: 2.0.11', 'plugin header version')
text = replace_once(text, "define('ASSS_VERSION', '2.0.10');", "define('ASSS_VERSION', '2.0.11');", 'ASSS_VERSION')
main.write_text(text, encoding='utf-8')

admin = root / 'includes/class-asss-admin.php'
text = admin.read_text(encoding='utf-8')

old_vars = """        $ss_targets = $this->sync->inventory_targets_ss();
        $ss_target_count = is_array($ss_targets) ? count($ss_targets) : 0;
        $sanmar_inventory_status = get_option('asss_inventory_bridge_status', []);
        $ss_inventory_status = get_option('asss_ss_inventory_bridge_status', []);
"""
new_vars = """        $ss_targets = $this->sync->inventory_targets_ss();
        $ss_target_count = is_array($ss_targets) ? count($ss_targets) : 0;
        $momentec_targets = $this->sync->inventory_targets_momentec();
        $momentec_target_count = is_array($momentec_targets) ? count($momentec_targets) : 0;
        $sanmar_inventory_status = get_option('asss_inventory_bridge_status', []);
        $ss_inventory_status = get_option('asss_ss_inventory_bridge_status', []);
        $momentec_inventory_status = get_option('asss_momentec_inventory_bridge_status', []);
        $sanmar_linked_count = count($this->sync->linked_product_ids(false, 'sanmar'));
        $ss_linked_count = count($this->sync->linked_product_ids(false, 'ss'));
        $momentec_linked_count = count($this->sync->linked_product_ids(false, 'momentec'));
        $momentec_catalog_count = $this->momentec->catalog_count();
        $momentec_catalog_meta = $this->momentec->catalog_meta();
        $momentec_request_counts = ['pending'=>0,'processing'=>0,'failed'=>0,'complete'=>0];
        foreach ($this->momentec->request_manifest() as $request_row) {
            if (!is_array($request_row)) continue;
            $request_status = sanitize_key((string)($request_row['status'] ?? ''));
            if (isset($momentec_request_counts[$request_status])) $momentec_request_counts[$request_status]++;
        }
"""
text = replace_once(text, old_vars, new_vars, 'status supplier variables')

old_active = """            ['Active linked products', count($active_ids) . ' products / ' . $target_count . ' SanMar targets / ' . $ss_target_count . ' S&S targets', true, 'Both suppliers use exact active WooCommerce variations as their hourly inventory target set.'],
            ['Multi-supplier coverage', (int)$source_counts['multi_products'] . ' multi-source / ' . (int)$source_counts['total_products'] . ' linked products', true, 'Products can carry SanMar and S&S simultaneously without duplicate storefront listings.'],
"""
new_active = """            ['Active linked products', count($active_ids) . ' products / ' . $target_count . ' SanMar / ' . $ss_target_count . ' S&S / ' . $momentec_target_count . ' Momentec inventory targets', true, 'All three suppliers use exact active WooCommerce variations as their inventory target set.'],
            ['SanMar supplier', $sanmar_linked_count . ' linked product' . ($sanmar_linked_count === 1 ? '' : 's') . ' / ' . $target_count . ' active variation target' . ($target_count === 1 ? '' : 's'), count($catalog) > 0, count($catalog) . ' canonical brands discovered; ' . count($enabled_brands) . ' enabled for catalog/product sync.'],
            ['S&S Activewear supplier', $ss_linked_count . ' linked product' . ($ss_linked_count === 1 ? '' : 's') . ' / ' . $ss_target_count . ' active variation target' . ($ss_target_count === 1 ? '' : 's'), count($ss_catalog) > 0, count($ss_catalog) . ' brands discovered; ' . count($ss_enabled_brands) . ' enabled.'],
            ['Momentec supplier', $momentec_linked_count . ' linked product' . ($momentec_linked_count === 1 ? '' : 's') . ' / ' . $momentec_target_count . ' active variation target' . ($momentec_target_count === 1 ? '' : 's'), $momentec_catalog_count > 0, $momentec_catalog_count . ' browseable styles cached from the official Momentec product feed.'],
            ['Multi-supplier coverage', (int)$source_counts['multi_products'] . ' multi-source / ' . (int)$source_counts['total_products'] . ' linked products', true, 'Products can carry SanMar, S&S, and Momentec sources simultaneously without duplicate storefront listings.'],
"""
text = replace_once(text, old_active, new_active, 'all supplier coverage rows')

old_inventory = """            ['S&S hourly inventory', $ss_target_count < 1 ? 'No active targets' : (!empty($ss_inventory_status['received_at']) ? 'Last success: ' . $ss_inventory_status['received_at'] : 'Awaiting first run'), $ss_target_count < 1 || !empty($ss_inventory_status['received_at']), $ss_target_count < 1 ? 'No active S&S variations currently need inventory.' : 'Next nominal check: ' . $next_ss . '. GitHub runs at :37 each hour and may start late; last matched ' . (int)($ss_inventory_status['matched'] ?? 0) . ' and changed ' . (int)($ss_inventory_status['changed'] ?? 0) . '.'],
            ['ASBO integration', $asbo_detected ? 'Detected' : 'Meta contract ready', true, $asbo_detected ? 'Bulk-order shortcode/plugin is active.' : 'Supplier Sync still writes the ASBO meta contract when configured.'],
"""
new_inventory = """            ['S&S hourly inventory', $ss_target_count < 1 ? 'No active targets' : (!empty($ss_inventory_status['received_at']) ? 'Last success: ' . $ss_inventory_status['received_at'] : 'Awaiting first run'), $ss_target_count < 1 || !empty($ss_inventory_status['received_at']), $ss_target_count < 1 ? 'No active S&S variations currently need inventory.' : 'Next nominal check: ' . $next_ss . '. GitHub runs at :37 each hour and may start late; last matched ' . (int)($ss_inventory_status['matched'] ?? 0) . ' and changed ' . (int)($ss_inventory_status['changed'] ?? 0) . '.'],
            ['Momentec catalog', $momentec_catalog_count < 1 ? 'Awaiting catalog' : $momentec_catalog_count . ' styles · last received ' . ((string)($momentec_catalog_meta['received_at'] ?? '') ?: 'unknown'), $momentec_catalog_count > 0, 'Daily GitHub catalog discovery uses Momentec\'s official product feed; selected styles are hydrated with customer-specific production v2 data.'],
            ['Momentec detail queue', (int)$momentec_request_counts['pending'] . ' pending / ' . (int)$momentec_request_counts['processing'] . ' processing / ' . (int)$momentec_request_counts['failed'] . ' failed', (int)$momentec_request_counts['failed'] === 0, 'The GitHub detail worker checks approximately every five minutes. Failed rows can be retried from Add Products.'],
            ['Momentec hourly inventory', $momentec_target_count < 1 ? 'No active targets' : (!empty($momentec_inventory_status['received_at']) ? 'Last success: ' . $momentec_inventory_status['received_at'] : 'Awaiting first run'), $momentec_target_count < 1 || !empty($momentec_inventory_status['received_at']), $momentec_target_count < 1 ? 'No active Momentec variations currently need inventory.' : 'GitHub runs at :43 each hour; last matched ' . (int)($momentec_inventory_status['matched'] ?? 0) . ' and changed ' . (int)($momentec_inventory_status['changed'] ?? 0) . '.'],
            ['ASBO integration', $asbo_detected ? 'Detected' : 'Meta contract ready', true, $asbo_detected ? 'Bulk-order shortcode/plugin is active.' : 'Supplier Sync still writes the ASBO meta contract when configured.'],
"""
text = replace_once(text, old_inventory, new_inventory, 'Momentec system status rows')

admin.write_text(text, encoding='utf-8')
