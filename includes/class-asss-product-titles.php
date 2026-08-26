<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer-facing supplier product title normalization.
 *
 * Supplier feeds often provide merchandising names that omit the actual
 * brand/model customers use to identify a blank. This layer keeps raw
 * supplier data intact while ensuring WooCommerce and Supplier Sync-owned
 * ASBO display names expose useful Brand + Style/Model information.
 */
final class ASSS_Product_Titles {
    private const MIGRATION_OPTION = 'asss_v2028_canonical_product_titles_migrated';
    private const MANAGED_TITLE_META = '_asss_managed_product_title';
    private const MANAGED_ASBO_TITLE_META = '_asss_managed_asbo_display_name';

    public static function init(): void {
        // Update the older v2.0.23 special-case rule before the importer
        // applies it, then add the general cross-supplier naming layer.
        add_filter('asss_canonical_product_titles', [self::class, 'legacy_title_overrides'], 20, 2);
        add_action('asss_product_synced', [self::class, 'normalize_synced_product'], 44, 2);
        add_action('admin_init', [self::class, 'migrate_existing_products'], 45);
    }

    public static function legacy_title_overrides($rules, int $product_id = 0): array {
        if (!is_array($rules)) $rules = [];
        $rules['richardson|112'] = 'Richardson 112 Snapback Trucker Cap';
        return $rules;
    }

    public static function normalize_synced_product(int $product_id, string $supplier = ''): void {
        self::normalize_product($product_id, $supplier ?: 'sync');
    }

    /** One-time repair for supplier-linked products created before v2.0.28. */
    public static function migrate_existing_products(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option(self::MIGRATION_OPTION, '') === 'yes') return;

        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_asss_sanmar_style',   'compare' => 'EXISTS'],
                ['key' => '_asss_ss_style',       'compare' => 'EXISTS'],
                ['key' => '_asss_momentec_style', 'compare' => 'EXISTS'],
            ],
        ]);

        $changed = 0;
        $asbo_changed = 0;
        $preserved = 0;
        foreach ((array)$ids as $product_id) {
            $result = self::normalize_product((int)$product_id, 'v2.0.28-migration');
            if (!empty($result['title_changed'])) $changed++;
            if (!empty($result['asbo_changed'])) $asbo_changed++;
            if (!empty($result['manual_preserved'])) $preserved++;
        }

        update_option(self::MIGRATION_OPTION, 'yes', false);
        self::log('v2.0.28 supplier product title migration complete', [
            'products' => count((array)$ids),
            'woo_titles_changed' => $changed,
            'asbo_titles_changed' => $asbo_changed,
            'manual_titles_preserved' => $preserved,
        ]);
    }

    /**
     * Normalize one supplier-linked product without modifying supplier
     * identity, pricing, inventory, taxonomy, descriptions, or media.
     */
    private static function normalize_product(int $product_id, string $context): array {
        $result = ['title_changed'=>false, 'asbo_changed'=>false, 'manual_preserved'=>false];
        if ($product_id < 1) return $result;
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product) return $result;

        $identity = self::best_identity($product_id);
        if (empty($identity['style'])) return $result;

        $current = trim((string)$product->get_name('edit'));
        $wanted = self::build_title($current, $identity);
        if ($wanted === '') return $result;

        $previous_managed = trim((string)$product->get_meta(self::MANAGED_TITLE_META));
        $legacy_managed = trim((string)$product->get_meta('_asss_canonical_title'));

        // Once v2.0.28 has written a title, a later merchant edit takes
        // ownership. The old importer canonical title also counts as
        // managed, so Richardson 112 can migrate cleanly from v2.0.23.
        $manual_title = $previous_managed !== ''
            && $current !== $previous_managed
            && ($legacy_managed === '' || $current !== $legacy_managed);

        // A pre-v2.0.28 name already containing both brand and model is
        // already useful (and may be merchant-curated), so preserve it.
        $already_specific = self::contains_identity($current, $identity);
        $special = self::special_title($identity);
        if ($previous_managed === '' && $special === '' && $already_specific) {
            $manual_title = true;
        }

        $asbo_before = trim((string)$product->get_meta('_asbo_display_name'));
        $previous_asbo = trim((string)$product->get_meta(self::MANAGED_ASBO_TITLE_META));
        $asbo_safe = $asbo_before === ''
            || ($previous_asbo !== '' && $asbo_before === $previous_asbo)
            || $asbo_before === $current
            || ($legacy_managed !== '' && $asbo_before === $legacy_managed)
            || $special !== '';

        if (!$manual_title && $current !== $wanted) {
            $product->set_name($wanted);
            $product->update_meta_data(self::MANAGED_TITLE_META, $wanted);
            $result['title_changed'] = true;
        } elseif (!$manual_title && $current === $wanted) {
            $product->update_meta_data(self::MANAGED_TITLE_META, $wanted);
        } elseif ($manual_title) {
            $result['manual_preserved'] = true;
        }

        if ($asbo_safe && $asbo_before !== $wanted) {
            $product->update_meta_data('_asbo_display_name', $wanted);
            $product->update_meta_data(self::MANAGED_ASBO_TITLE_META, $wanted);
            $result['asbo_changed'] = true;
        } elseif ($asbo_safe && $asbo_before === $wanted) {
            $product->update_meta_data(self::MANAGED_ASBO_TITLE_META, $wanted);
        }

        if ($result['title_changed'] || $result['asbo_changed'] || !$manual_title) {
            $product->save();
        }

        if ($result['title_changed'] || $result['asbo_changed']) {
            self::log('Normalized supplier storefront product title', [
                'product_id' => $product_id,
                'context' => $context,
                'source' => $identity['source'],
                'brand' => $identity['brand'],
                'style' => $identity['style'],
                'before' => $current,
                'after' => $wanted,
                'asbo_updated' => $result['asbo_changed'],
            ]);
        }
        return $result;
    }

    /** Choose the most useful Brand + Style identity already stored by Supplier Sync. */
    private static function best_identity(int $product_id): array {
        $sources = [
            'sanmar' => [
                trim((string)get_post_meta($product_id, '_asss_sanmar_brand', true)),
                trim((string)get_post_meta($product_id, '_asss_sanmar_style', true)),
            ],
            'ss' => [
                trim((string)get_post_meta($product_id, '_asss_ss_brand', true)),
                trim((string)get_post_meta($product_id, '_asss_ss_style', true)),
            ],
            'momentec' => [
                trim((string)get_post_meta($product_id, '_asss_momentec_brand', true)),
                trim((string)get_post_meta($product_id, '_asss_momentec_style', true)),
            ],
        ];

        $primary = strtolower(trim((string)get_post_meta($product_id, '_asss_supplier', true)));
        $order = in_array($primary, ['sanmar','ss','momentec'], true)
            ? array_values(array_unique(array_merge([$primary], array_keys($sources))))
            : ['ss','sanmar','momentec'];

        $fallback = ['source'=>'', 'brand'=>'', 'style'=>''];
        foreach ($order as $source) {
            [$brand, $style] = $sources[$source] ?? ['', ''];
            if ($style === '') continue;
            $candidate = [
                'source' => $source,
                'brand'  => self::display_brand($brand),
                'style'  => self::display_style($style),
            ];
            if ($fallback['style'] === '') $fallback = $candidate;
            if ($candidate['brand'] !== '' && !self::generic_supplier_brand($candidate['brand'])) {
                return $candidate;
            }
        }
        return $fallback;
    }

    private static function display_brand(string $brand): string {
        $brand = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($brand)));
        $key = self::key($brand);
        $aliases = [
            'richardsoncap' => 'Richardson',
            'richardsoncaps' => 'Richardson',
        ];
        $aliases = apply_filters('asss_storefront_brand_aliases', $aliases);
        if (is_array($aliases) && isset($aliases[$key])) {
            $brand = trim((string)$aliases[$key]);
        }
        return sanitize_text_field($brand);
    }

    private static function display_style(string $style): string {
        return sanitize_text_field(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($style))));
    }

    private static function generic_supplier_brand(string $brand): bool {
        return in_array(self::key($brand), [
            'momentec', 'momentecbrands', 'sanmar', 'ss', 'ssactivewear',
        ], true);
    }

    private static function special_title(array $identity): string {
        $key = self::key($identity['brand']) . '|' . self::key($identity['style']);
        $specials = [
            'richardson|112' => 'Richardson 112 Snapback Trucker Cap',
        ];
        $specials = apply_filters('asss_specific_storefront_product_titles', $specials, $identity);
        if (!is_array($specials) || empty($specials[$key])) return '';
        return sanitize_text_field((string)$specials[$key]);
    }

    private static function build_title(string $current, array $identity): string {
        $special = self::special_title($identity);
        if ($special !== '') return $special;

        $brand = trim((string)($identity['brand'] ?? ''));
        $style = trim((string)($identity['style'] ?? ''));
        if ($style === '') return '';

        // If the existing title already exposes both exact identifiers,
        // it is already customer-useful. Do not needlessly reformat it.
        if (self::contains_identity($current, $identity)) {
            return sanitize_text_field($current);
        }

        $descriptor = self::descriptor_from_current($current, $brand, $style);
        $parts = [];
        if ($brand !== '' && !self::generic_supplier_brand($brand)) $parts[] = $brand;
        $parts[] = $style;
        if ($descriptor !== '') $parts[] = $descriptor;
        return sanitize_text_field(trim(preg_replace('/\s+/u', ' ', implode(' ', $parts))));
    }

    private static function descriptor_from_current(string $current, string $brand, string $style): string {
        $text = trim(wp_strip_all_tags($current));
        if ($text === '') return '';

        foreach (array_unique(array_filter([$brand, self::display_brand($brand)])) as $remove) {
            $text = preg_replace('/' . preg_quote($remove, '/') . '/iu', ' ', $text);
        }
        if ($style !== '') {
            $text = preg_replace('/(?<![A-Za-z0-9])' . preg_quote($style, '/') . '(?![A-Za-z0-9])/iu', ' ', $text);
        }
        $text = preg_replace('/\(\s*\)/u', ' ', $text);
        $text = preg_replace('/^[\s\-–—|:·®™]+|[\s\-–—|:·®™]+$/u', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (in_array(self::key($text), ['', 'product', 'item', 'style'], true)) return '';
        return sanitize_text_field($text);
    }

    private static function contains_identity(string $title, array $identity): bool {
        $haystack = self::key($title);
        $brand = self::key((string)($identity['brand'] ?? ''));
        $style = self::key((string)($identity['style'] ?? ''));
        if ($style === '' || strpos($haystack, $style) === false) return false;
        if ($brand === '' || self::generic_supplier_brand((string)($identity['brand'] ?? ''))) return true;
        return strpos($haystack, $brand) !== false;
    }

    private static function key(string $value): string {
        $value = remove_accents(strtolower(trim($value)));
        return (string)preg_replace('/[^a-z0-9]+/i', '', $value);
    }

    private static function log(string $message, array $context = []): void {
        if (class_exists('ASSS_Logger')) {
            ASSS_Logger::log($message, 'info', $context);
        }
    }
}

ASSS_Product_Titles::init();
