#!/usr/bin/env python3
from pathlib import Path
import json
import re
import sys

if len(sys.argv) != 3:
    raise SystemExit('usage: build_v205.py <source-dir> <release-dir>')

root = Path(sys.argv[1])
release = Path(sys.argv[2])

main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = text.replace('Version: 2.0.4', 'Version: 2.0.5', 1)
text = text.replace("define('ASSS_VERSION', '2.0.4');", "define('ASSS_VERSION', '2.0.5');", 1)
if 'Version: 2.0.5' not in text or "define('ASSS_VERSION', '2.0.5');" not in text:
    raise SystemExit('Could not safely bump plugin version to 2.0.5.')
main.write_text(text, encoding='utf-8')

momentec = root / 'includes/class-asss-momentec.php'
momentec.write_text("""<?php
if (!defined('ABSPATH')) exit;

/**
 * Momentec bridge registration.
 *
 * Architecture: WordPress <-> GitHub Actions <-> Momentec.
 * WordPress intentionally never stores, reads, or receives Momentec supplier
 * credentials. GitHub Actions owns supplier authentication, normalization,
 * validation, and authenticated delivery to the Supplier Sync bridge.
 */
class ASSS_Momentec {
    public const KEY = 'momentec';
    public const LABEL = 'Momentec Brands';

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

    public function purge_legacy_wordpress_connection_values(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $s = get_option('asss_settings', []);
        if (!is_array($s)) return;
        $changed = false;
        foreach ([
            'momentec_username',
            'momentec_password',
            'momentec_api_key',
            'momentec_secret',
            'momentec_api_base',
            'momentec_account',
            'momentec_environment',
        ] as $key) {
            if (array_key_exists($key, $s)) {
                unset($s[$key]);
                $changed = true;
            }
        }
        if ($changed) update_option('asss_settings', $s, false);
    }

    public function status(): array {
        return [
            'supplier' => self::KEY,
            'label' => self::LABEL,
            'configured' => $this->configured(),
            'enabled' => $this->configured(),
            'connection_owner' => 'github-actions',
            'credentials_location' => 'github-actions-secrets',
            'state' => $this->configured() ? 'github-bridge-enabled' : 'disabled',
            'catalog_sync' => 'github-bridge-groundwork',
            'inventory_sync' => 'github-bridge-groundwork',
        ];
    }

    public function capabilities(): array {
        return [
            'catalog' => true,
            'inventory' => true,
            'orders' => true,
            'order_status' => true,
            'live_adapter_enabled' => false,
            'supplier_auth_location' => 'github-actions',
        ];
    }
}
""", encoding='utf-8')

admin = root / 'includes/class-asss-admin.php'
text = admin.read_text(encoding='utf-8')
old_keys = "'github_update_repo','github_auto_updates','momentec_enabled','momentec_api_base','momentec_account','momentec_environment'"
new_keys = "'github_update_repo','github_auto_updates','momentec_enabled'"
if old_keys not in text:
    raise SystemExit('Expected v2.0.4 Momentec settings keys were not found.')
text = text.replace(old_keys, new_keys, 1)

old_purge = "foreach (['momentec_username','momentec_password','momentec_api_key','momentec_secret'] as $legacy_key) unset($new[$legacy_key]);"
new_purge = "foreach (['momentec_username','momentec_password','momentec_api_key','momentec_secret','momentec_api_base','momentec_account','momentec_environment'] as $legacy_key) unset($new[$legacy_key]);"
if old_purge not in text:
    raise SystemExit('Expected v2.0.4 credential purge was not found.')
text = text.replace(old_purge, new_purge, 1)

start_marker = "        echo '<hr><h2>Momentec Brands API (groundwork)</h2>"
end_marker = "        echo '<hr><h2>Direct Supplier File Connection (optional / fallback)</h2>"
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('Could not locate the v2.0.4 Momentec settings UI block.')

new_ui = """        echo '<hr><h2>Momentec Brands via GitHub bridge</h2><p><strong>Architecture:</strong> WordPress &harr; GitHub Actions &harr; Momentec. WordPress does not store, read, or receive the Momentec username/password. GitHub authenticates with Momentec and sends only normalized supplier data through the existing authenticated Supplier Sync bridge.</p><table class=\"form-table\">';
        echo $this->check('Enable Momentec supplier bridge', 'momentec_enabled', $s['momentec_enabled'] ?? 0);
        echo '<tr><th>Supplier credentials</th><td><strong>GitHub Actions Secrets</strong><p class=\"description\">In the private <code>rolejarczyk/ASE.ProductSync</code> repository, add <code>MOMENTEC_USERNAME</code> and <code>MOMENTEC_PASSWORD</code>. Do not enter the values into WordPress or commit them to the repository.</p></td></tr>';
        echo '<tr><th>WordPress bridge</th><td><code>ASSS_WP_URL</code> + <code>ASSS_BRIDGE_TOKEN</code><p class=\"description\">These existing GitHub Actions Secrets let GitHub request WooCommerce targets and send validated Momentec catalog/inventory payloads back to Supplier Sync.</p></td></tr>';
        echo '<tr><th>Credential check</th><td><strong>Run “Momentec Credentials Preflight” in GitHub Actions</strong><p class=\"description\">The preflight verifies that the required secrets exist without printing them, sending them to WordPress, or making a supplier API request.</p></td></tr>';
        echo '<tr><th>Adapter status</th><td><strong>' . esc_html(!empty($momentec['configured']) ? 'Momentec GitHub bridge enabled' : 'Momentec bridge disabled') . '</strong><p class=\"description\">Live Momentec catalog/inventory calls remain disabled until we verify the exact staging authentication endpoint and response schema. No speculative API key or authentication method is used.</p></td></tr></table>';

"""
text = text[:start] + new_ui + text[end:]
admin.write_text(text, encoding='utf-8')

readme = root / 'readme.txt'
text = readme.read_text(encoding='utf-8')
text = re.sub(r'^Stable tag:\s*2\.0\.4\s*$', 'Stable tag: 2.0.5', text, count=1, flags=re.M)
if '= 2.0.5 =' not in text:
    text = text.replace('== Changelog ==', "== Changelog ==\n\n= 2.0.5 =\n* Momentec now follows the project architecture: WordPress <> GitHub Actions <> supplier.\n* Momentec username/password belong only in GitHub Actions Secrets as MOMENTEC_USERNAME and MOMENTEC_PASSWORD.\n* Removed Momentec credential, API base URL, account number, and environment storage from WordPress.\n* Automatically purges legacy Momentec connection values left by 2.0.3/2.0.4.\n* WordPress Momentec settings now explain the GitHub bridge and credential preflight instead of accepting supplier connection details.\n", 1)
readme.write_text(text, encoding='utf-8')

latest = {
    'version': '2.0.5',
    'package': 'https://github.com/rolejarczyk/ASE.SupplierSync-Releases/releases/download/v2.0.5/all-star-supplier-sync-2.0.5.zip',
    'url': 'https://github.com/rolejarczyk/ASE.SupplierSync-Releases/releases/tag/v2.0.5',
    'name': 'All Star Supplier Sync v2.0.5',
}
(release / 'latest.json').write_text(json.dumps(latest, indent=2) + '\n', encoding='utf-8')
(release / 'RELEASE-2.0.5.md').write_text("""# All Star Supplier Sync v2.0.5

Momentec architecture correction: **WordPress <> GitHub Actions <> Momentec**.

- Momentec username/password are held only as GitHub Actions Secrets in the private development repository.
- WordPress no longer accepts or stores Momentec credentials or supplier connection details.
- Legacy Momentec username/password/API key/base URL/account/environment values are purged from `asss_settings`.
- WordPress shows the required GitHub secret names and points administrators to the Momentec Credentials Preflight workflow.
- Existing `ASSS_WP_URL` and `ASSS_BRIDGE_TOKEN` secrets remain the authenticated GitHub-to-WordPress transport.
- Live Momentec supplier calls remain disabled until its exact staging authentication contract is verified.
""", encoding='utf-8')

print('Prepared Supplier Sync v2.0.5 source and release metadata.')
