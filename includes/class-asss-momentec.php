<?php
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
