<?php
if (!defined('ABSPATH')) exit;

/**
 * Momentec API adapter groundwork.
 *
 * Momentec publicly documents API / digital customer integrations, but the
 * account-specific authentication contract and live schema must be confirmed
 * after All Star receives credentials. This adapter intentionally does not
 * guess endpoint paths or auth headers. It provides a safe configuration and
 * capability boundary so live catalog/inventory support can be added without
 * changing the multi-supplier data model again.
 */
class ASSS_Momentec {
    public const KEY = 'momentec';
    public const LABEL = 'Momentec Brands';

    public function settings(): array {
        $s = get_option('asss_settings', []);
        return is_array($s) ? $s : [];
    }

    public function configured(): bool {
        $s = $this->settings();
        return !empty($s['momentec_enabled'])
            && trim((string)($s['momentec_api_base'] ?? '')) !== ''
            && (trim((string)($s['momentec_api_key'] ?? '')) !== '' || trim((string)($s['momentec_username'] ?? '')) !== '');
    }

    public function environment(): string {
        $v = sanitize_key((string)($this->settings()['momentec_environment'] ?? 'staging'));
        return in_array($v, ['staging','production'], true) ? $v : 'staging';
    }

    public function status(): array {
        return [
            'supplier' => self::KEY,
            'label' => self::LABEL,
            'configured' => $this->configured(),
            'enabled' => !empty($this->settings()['momentec_enabled']),
            'environment' => $this->environment(),
            'state' => $this->configured() ? 'credentials-ready' : 'awaiting-api-credentials',
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
