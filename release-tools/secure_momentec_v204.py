from pathlib import Path
import re
import sys

root = Path(sys.argv[1]).resolve()

main = root / "all-star-supplier-sync.php"
text = main.read_text(encoding="utf-8")
text = text.replace("Version: 2.0.3", "Version: 2.0.4", 1)
text = text.replace("define('ASSS_VERSION', '2.0.3');", "define('ASSS_VERSION', '2.0.4');", 1)
if "Version: 2.0.4" not in text or "define('ASSS_VERSION', '2.0.4');" not in text:
    raise SystemExit("Could not bump plugin version to 2.0.4")
main.write_text(text, encoding="utf-8")

momentec = root / "includes/class-asss-momentec.php"
momentec.write_text("""<?php
if (!defined('ABSPATH')) exit;

/**
 * Momentec API adapter groundwork.
 *
 * Sensitive credentials are intentionally NOT stored in the WordPress
 * database. Configure them in wp-config.php or as server environment
 * variables using ASSS_MOMENTEC_USERNAME and ASSS_MOMENTEC_PASSWORD.
 *
 * Constants take precedence over environment variables. Live API calls remain
 * disabled until Momentec's account-specific staging authentication contract
 * and response schema have been verified.
 */
class ASSS_Momentec {
    public const KEY = 'momentec';
    public const LABEL = 'Momentec Brands';
    public const USERNAME_KEY = 'ASSS_MOMENTEC_USERNAME';
    public const PASSWORD_KEY = 'ASSS_MOMENTEC_PASSWORD';

    public function __construct() {
        add_action('admin_init', [$this, 'purge_legacy_database_credentials'], 1);
    }

    public function settings(): array {
        $s = get_option('asss_settings', []);
        return is_array($s) ? $s : [];
    }

    private function secure_value(string $key): string {
        if (defined($key)) {
            $value = constant($key);
            return is_scalar($value) ? trim((string)$value) : '';
        }
        $value = getenv($key);
        return $value === false ? '' : trim((string)$value);
    }

    private function secure_source(string $key): string {
        if (defined($key) && $this->secure_value($key) !== '') return 'wp-config.php constant';
        $value = getenv($key);
        if ($value !== false && trim((string)$value) !== '') return 'server environment variable';
        return '';
    }

    public function username(): string {
        return $this->secure_value(self::USERNAME_KEY);
    }

    public function password(): string {
        return $this->secure_value(self::PASSWORD_KEY);
    }

    public function credentials_configured(): bool {
        return $this->username() !== '' && $this->password() !== '';
    }

    public function credential_source(): string {
        $user = $this->secure_source(self::USERNAME_KEY);
        $pass = $this->secure_source(self::PASSWORD_KEY);
        if ($user !== '' && $pass !== '' && $user === $pass) return $user;
        if ($user !== '' || $pass !== '') return 'mixed secure sources';
        return '';
    }

    public function configured(): bool {
        $s = $this->settings();
        return !empty($s['momentec_enabled'])
            && trim((string)($s['momentec_api_base'] ?? '')) !== ''
            && $this->credentials_configured();
    }

    public function environment(): string {
        $v = sanitize_key((string)($this->settings()['momentec_environment'] ?? 'staging'));
        return in_array($v, ['staging','production'], true) ? $v : 'staging';
    }

    public function purge_legacy_database_credentials(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $s = get_option('asss_settings', []);
        if (!is_array($s)) return;
        $changed = false;
        foreach (['momentec_username','momentec_password','momentec_api_key','momentec_secret'] as $key) {
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
            'enabled' => !empty($this->settings()['momentec_enabled']),
            'environment' => $this->environment(),
            'username_configured' => $this->username() !== '',
            'password_configured' => $this->password() !== '',
            'credential_source' => $this->credential_source(),
            'state' => $this->configured() ? 'credentials-ready' : 'awaiting-secure-api-credentials',
            'catalog_sync' => 'not-enabled-yet',
            'inventory_sync' => 'not-enabled-yet',
        ];
    }

    public function capabilities(): array {
        return [
            'catalog' => true,
            'inventory' => true,
            'orders' => true,
            'order_status' => true,
            'live_adapter_enabled' => false,
        ];
    }
}
""", encoding="utf-8")

admin = root / "includes/class-asss-admin.php"
text = admin.read_text(encoding="utf-8")

old_keys = "'github_update_repo','github_auto_updates','momentec_enabled','momentec_api_base','momentec_account','momentec_username','momentec_api_key','momentec_environment'"
new_keys = "'github_update_repo','github_auto_updates','momentec_enabled','momentec_api_base','momentec_account','momentec_environment'"
if old_keys not in text:
    raise SystemExit("Expected v2.0.3 Momentec settings key list not found")
text = text.replace(old_keys, new_keys, 1)

save_loop = "foreach ($keys as $k) $new[$k] = isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : 0;"
if save_loop not in text:
    raise SystemExit("Expected settings save loop not found")
text = text.replace(
    save_loop,
    save_loop + "\n            foreach (['momentec_username','momentec_password','momentec_api_key','momentec_secret'] as $legacy_key) unset($new[$legacy_key]);",
    1,
)

start_marker = "        echo '<hr><h2>Momentec Brands API (groundwork)</h2>"
end_marker = "        echo '<hr><h2>Direct Supplier File Connection (optional / fallback)</h2>"
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit("Expected Momentec settings UI markers not found")

secure_ui = r'''        echo '<hr><h2>Momentec Brands API</h2><p>Momentec is registered as Supplier #3. The public Momentec integration material describes staging account credentials. Username and password are now read only from secure server configuration and are never stored in the WordPress database.</p><table class="form-table">';
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

'''
text = text[:start] + secure_ui + text[end:]
admin.write_text(text, encoding="utf-8")

readme = root / "readme.txt"
text = readme.read_text(encoding="utf-8")
text = re.sub(r"^Stable tag:\s*2\.0\.3\s*$", "Stable tag: 2.0.4", text, count=1, flags=re.M)
if "== Changelog ==" in text and "= 2.0.4 =" not in text:
    entry = """== Changelog ==

= 2.0.4 =
* Security hardening: Momentec API username and password are no longer accepted or stored in the WordPress database.
* Momentec credentials now come from ASSS_MOMENTEC_USERNAME and ASSS_MOMENTEC_PASSWORD in wp-config.php or server environment variables.
* Automatically removes legacy Momentec credential-shaped values saved by the 2.0.3 groundwork screen.
* Replaces credential entry fields with secure configuration status and setup instructions.
"""
    text = text.replace("== Changelog ==", entry, 1)
readme.write_text(text, encoding="utf-8")

print("Patched exact v2.0.3 source to v2.0.4 secure Momentec credential handling")
