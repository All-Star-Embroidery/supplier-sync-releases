<?php
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
