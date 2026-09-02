from pathlib import Path


def must_replace(text, old, new, label, count=1):
    actual = text.count(old)
    if actual < count:
        raise SystemExit(f'{label}: expected at least {count}, found {actual}')
    return text.replace(old, new, count)

main = Path('all-star-supplier-sync.php')
t = main.read_text()
t = must_replace(t, 'Version: 2.0.33', 'Version: 2.0.34', 'version header')
t = must_replace(t, "define('ASSS_VERSION', '2.0.33');", "define('ASSS_VERSION', '2.0.34');", 'version constant')
main.write_text(t)

imp = Path('includes/class-asss-importer.php')
t = imp.read_text()
t = must_replace(
    t,
    "        add_action('admin_init', [$this, 'migrate_global_apparel_tag_v2030'], 46);",
    "        add_action('admin_init', [$this, 'migrate_global_apparel_tag_v2030'], 46);\n        add_action('admin_init', [$this, 'migrate_storefront_showcase_images_v2034'], 47);",
    'migration hook'
)
t = must_replace(
    t,
    "        add_action('asss_product_synced', [$this, 'queue_sanmar_product_gallery_normalization'], 55, 2);",
    "        add_action('asss_product_synced', [$this, 'queue_sanmar_product_gallery_normalization'], 55, 2);\n        add_action('asss_product_synced', [$this, 'apply_storefront_showcase_image'], 60, 2);",
    'showcase hook'
)

anchor = "    private function is_supplier_attachment(int $attachment_id): bool {"
insert = r'''
    /**
     * Choose a stable but visually varied storefront image from real variation media.
     * Merchant-uploaded featured images are protected. Neutral colors are used only
     * when no more colorful supplier variation image is available.
     */
    public function apply_storefront_showcase_image(int $product_id, string $supplier = ''): void {
        if ($product_id < 1) return;
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product_Variable) return;
        if ((string)$product->get_meta('_asss_supplier') === '') return;

        $current_id = (int)$product->get_image_id();
        if ($current_id > 0 && !$this->is_supplier_attachment($current_id)) {
            // A merchant deliberately replaced the supplier-managed featured image.
            $product->update_meta_data('_asss_showcase_schema', '2.0.34');
            $product->update_meta_data('_asss_showcase_mode', 'manual-protected');
            $product->save();
            return;
        }

        $candidates = [];
        foreach ($this->variation_ids_direct($product_id) as $variation_id) {
            $variation = wc_get_product((int)$variation_id);
            if (!$variation instanceof WC_Product_Variation) continue;
            $image_id = (int)$variation->get_image_id();
            if ($image_id < 1 || $this->invalid_storefront_attachment_for_product($image_id, $product_id)) continue;

            $color = trim((string)$variation->get_attribute('pa_color'));
            if ($color === '') $color = trim((string)$variation->get_meta('attribute_pa_color'));
            if ($color === '') continue;
            $term = get_term_by('slug', $color, 'pa_color');
            if ($term && !is_wp_error($term)) $color = (string)$term->name;
            $key = strtolower(preg_replace('/\s+/u', ' ', trim($color)));
            if ($key === '') continue;
            if (!isset($candidates[$image_id])) $candidates[$image_id] = ['id'=>$image_id,'color'=>$color,'key'=>$key];
        }

        if (!$candidates) {
            $product->update_meta_data('_asss_showcase_schema', '2.0.34');
            $product->update_meta_data('_asss_showcase_mode', 'no-variation-media');
            $product->save();
            return;
        }

        $neutral_markers = ['black','white','grey','gray','charcoal','graphite','ash','silver','natural','stone','sand','cream','ivory','heather grey','heather gray'];
        $colorful = [];
        foreach ($candidates as $candidate) {
            $neutral = false;
            foreach ($neutral_markers as $marker) {
                if (str_contains($candidate['key'], $marker)) { $neutral = true; break; }
            }
            if (!$neutral) $colorful[] = $candidate;
        }
        $pool = $colorful ?: array_values($candidates);

        // Deterministic pseudo-random ranking: varied across products, stable across page loads.
        usort($pool, static function(array $a, array $b) use ($product_id): int {
            $sa = sprintf('%u', crc32($product_id . '|' . $a['key'] . '|' . $a['id']));
            $sb = sprintf('%u', crc32($product_id . '|' . $b['key'] . '|' . $b['id']));
            return (int)$sb <=> (int)$sa;
        });
        $chosen = $pool[0] ?? null;
        if (!$chosen || empty($chosen['id'])) return;

        $chosen_id = (int)$chosen['id'];
        if ($current_id !== $chosen_id) $product->set_image_id($chosen_id);
        $product->update_meta_data('_asss_showcase_color', sanitize_text_field((string)$chosen['color']));
        $product->update_meta_data('_asss_showcase_attachment_id', $chosen_id);
        $product->update_meta_data('_asss_showcase_schema', '2.0.34');
        $product->update_meta_data('_asss_showcase_mode', $colorful ? 'automatic-colorful' : 'automatic-any-color');
        $product->save();
        wc_delete_product_transients($product_id);
    }

    /**
     * Incrementally repair existing supplier products without creating a large
     * one-request migration. Each WooCommerce admin request processes up to 25.
     */
    public function migrate_storefront_showcase_images_v2034(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if ((string)get_option('asss_v2034_showcase_images_migrated', '') === 'yes') return;

        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 25,
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_asss_supplier', 'compare' => 'EXISTS'],
                [
                    'relation' => 'OR',
                    ['key' => '_asss_showcase_schema', 'compare' => 'NOT EXISTS'],
                    ['key' => '_asss_showcase_schema', 'value' => '2.0.34', 'compare' => '!='],
                ],
            ],
        ]);

        if (!$ids) {
            update_option('asss_v2034_showcase_images_migrated', 'yes', false);
            return;
        }
        foreach ($ids as $product_id) $this->apply_storefront_showcase_image((int)$product_id, 'v2034-migration');
        ASSS_Logger::log('v2.0.34 storefront showcase image migration batch complete', 'info', ['products'=>count($ids)]);
    }

'''
if anchor not in t:
    raise SystemExit('supplier attachment anchor missing')
t = t.replace(anchor, insert + anchor, 1)
imp.write_text(t)
