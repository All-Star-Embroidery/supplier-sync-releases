#!/usr/bin/env python3
from pathlib import Path
import re

root = Path('src')

def read(rel): return (root / rel).read_text(encoding='utf-8')
def write(rel, text): (root / rel).write_text(text, encoding='utf-8')

def replace_once(text, old, new, label):
    n = text.count(old)
    if n != 1:
        raise SystemExit(f'{label}: expected 1 literal match, found {n}')
    return text.replace(old, new, 1)

def sub_once(text, pattern, replacement, label, flags=re.S):
    out, n = re.subn(pattern, lambda m: replacement(m) if callable(replacement) else replacement, text, count=1, flags=flags)
    if n != 1:
        raise SystemExit(f'{label}: expected 1 regex match, found {n}')
    return out

# ---------------- Admin UI/actions ----------------
admin = read('includes/class-asss-admin.php')

# Make token regeneration require explicit acknowledgement + typed phrase.
pattern = r"        if \(!empty\(\$_POST\['asss_regen_bridge'\]\)\) \{.*?\n        \}\n\n\n        if \(!empty\(\$_POST\['asss_check_updates'\]\)\)"
replacement = """        if (!empty($_POST['asss_regen_bridge'])) {
            check_admin_referer('asss_settings');
            $ack = !empty($_POST['asss_regen_ack']);
            $phrase = strtoupper(trim(sanitize_text_field(wp_unslash((string)($_POST['asss_regen_confirm'] ?? '')))));
            if (!$ack || $phrase !== 'REGENERATE') {
                $this->redir('asss-settings', '', 'Bridge token was not changed. Check the confirmation box and type REGENERATE exactly to generate a new token.');
            }
            $new = $this->sanmar->settings();
            $new['bridge_token'] = wp_generate_password(48, false, false);
            $new['bridge_enabled'] = 1;
            update_option('asss_settings', $new, false);
            $this->redir('asss-settings', 'New GitHub bridge token generated. Update the ASSS_BRIDGE_TOKEN secret in GitHub before the next sync.');
        }


        if (!empty($_POST['asss_check_updates']))"""
admin = sub_once(admin, pattern, replacement, 'safer bridge token action')

# Remove regeneration button from the normal GitHub Bridge row, leaving token visibility/status there.
pattern = r"(<p class=\\\"description\\\">Store this in GitHub as <code>ASSS_BRIDGE_TOKEN</code>\. It is hidden by default on this screen\.</p>)<button class=\\\"button\\\" name=\\\"asss_regen_bridge\\\".*?>Generate New Token</button>"
admin = sub_once(admin, pattern, lambda m: m.group(1) + '<p class=\\"description\\">Regeneration controls are intentionally located in the Danger Zone at the bottom of this page.</p>', 'remove inline regen button')

# Add the Danger Zone at the absolute bottom of Settings, after ordinary Save Settings.
old = "        echo '</table><p><button class=\"button button-primary\" name=\"asss_save_settings\" value=\"1\">Save Settings</button></p></form>';"
new = """        echo '</table><p><button class=\"button button-primary\" name=\"asss_save_settings\" value=\"1\">Save Settings</button></p>';
        echo '<hr style=\"margin-top:38px\"><div style=\"max-width:920px;border:1px solid #d63638;border-left-width:4px;background:#fff;padding:18px 20px;margin:18px 0 8px\">';
        echo '<h2 style=\"margin-top:0;color:#b32d2e\">Danger Zone</h2><h3>Generate a New GitHub Bridge Token</h3>';
        echo '<p><strong>This immediately invalidates the current bridge token.</strong> SanMar, S&amp;S, and Momentec GitHub workflows will stop talking to WordPress until the new value is copied into the <code>ASSS_BRIDGE_TOKEN</code> GitHub Actions Secret.</p>';
        echo '<p><label><input type=\"checkbox\" name=\"asss_regen_ack\" value=\"1\" id=\"asss-regen-ack\"> I understand that generating a new token will interrupt supplier sync until GitHub Secrets is updated.</label></p>';
        echo '<p><label for=\"asss-regen-confirm\"><strong>Type <code>REGENERATE</code> to confirm:</strong></label><br><input id=\"asss-regen-confirm\" class=\"regular-text code\" name=\"asss_regen_confirm\" value=\"\" autocomplete=\"off\"></p>';
        echo '<p><button class=\"button\" style=\"border-color:#b32d2e;color:#b32d2e\" name=\"asss_regen_bridge\" value=\"1\" formnovalidate onclick=\"if(!document.getElementById(&quot;asss-regen-ack&quot;).checked||document.getElementById(&quot;asss-regen-confirm&quot;).value.trim().toUpperCase()!==&quot;REGENERATE&quot;){alert(&quot;Check the acknowledgement and type REGENERATE exactly before generating a new bridge token.&quot;);return false;}return confirm(&quot;Generate a new bridge token now? Supplier sync will pause until GitHub Secrets is updated.&quot;);\">Generate New Bridge Token</button></p>';
        echo '</div></form>';"""
admin = replace_once(admin, old, new, 'settings danger zone')

# Momentec Add Products: introduce all/ready view before pagination.
old = """            $search=sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
            $brand=sanitize_text_field(wp_unslash($_GET['brand'] ?? ''));
            $category=sanitize_text_field(wp_unslash($_GET['category'] ?? ''));
            $page_num=max(1,absint($_GET['catalog_page'] ?? 1));
            $facets=$this->momentec->catalog_facets();
            $catalog=$this->momentec->catalog_search($search,$brand,$category,$page_num,50);
            $meta=$this->momentec->catalog_meta();"""
new = """            $search=sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
            $brand=sanitize_text_field(wp_unslash($_GET['brand'] ?? ''));
            $category=sanitize_text_field(wp_unslash($_GET['category'] ?? ''));
            $momentec_view=sanitize_key((string)($_GET['momentec_view'] ?? 'all'));
            if(!in_array($momentec_view,['all','ready'],true))$momentec_view='all';
            $page_num=max(1,absint($_GET['catalog_page'] ?? 1));
            $facets=$this->momentec->catalog_facets();
            $catalog=$this->momentec->catalog_search($search,$brand,$category,$page_num,50,$momentec_view);
            $meta=$this->momentec->catalog_meta();
            $ready_count=(int)($status['cached_styles'] ?? 0);"""
admin = replace_once(admin, old, new, 'momentec view vars')

# Add view tabs and preserve the selected view through filtering.
old = """            echo '<form method=\"get\"><input type=\"hidden\" name=\"page\" value=\"asss-suppliers\"><input type=\"hidden\" name=\"supplier\" value=\"momentec\"><table class=\"form-table\" style=\"max-width:1040px\"><tr><th style=\"width:150px\">Brand</th><td><select name=\"brand\"><option value=\"\">All brands</option>';"""
new = """            $all_url=add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','momentec_view'=>'all'],admin_url('admin.php'));
            $ready_url=add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','momentec_view'=>'ready'],admin_url('admin.php'));
            echo '<div style=\"display:flex;gap:8px;align-items:center;margin:18px 0 8px\"><a class=\"button '.($momentec_view==='all'?'button-primary':'').'\" href=\"'.esc_url($all_url).'\">All Momentec Products</a><a class=\"button '.($momentec_view==='ready'?'button-primary':'').'\" href=\"'.esc_url($ready_url).'\">Ready to Import <span style=\"display:inline-block;margin-left:4px;padding:0 6px;border-radius:10px;background:'.($momentec_view==='ready'?'rgba(255,255,255,.22)':'#d7f0df').'\">'.number_format_i18n($ready_count).'</span></a></div>';
            if($momentec_view==='ready')echo '<p class=\"description\" style=\"margin-top:0\">These styles have completed the secure GitHub hydration step and can go straight to color review/import. Newly completed styles appear here automatically.</p>';
            echo '<form method=\"get\"><input type=\"hidden\" name=\"page\" value=\"asss-suppliers\"><input type=\"hidden\" name=\"supplier\" value=\"momentec\"><input type=\"hidden\" name=\"momentec_view\" value=\"'.esc_attr($momentec_view).'\"><table class=\"form-table\" style=\"max-width:1040px\"><tr><th style=\"width:150px\">Brand</th><td><select name=\"brand\"><option value=\"\">All brands</option>';"""
admin = replace_once(admin, old, new, 'momentec view tabs')

# Tailor count/empty copy for Ready view.
old = """            echo '<p><strong>'.number_format_i18n((int)$catalog['total']).'</strong> matching styles. Every style now has its own <strong>Import</strong> action. If customer-specific details are not cached yet, clicking Import automatically queues that one style for secure GitHub hydration; once ready, Import opens the normal review/color-selection screen. You can still use the checkboxes for optional bulk preparation.</p>';
            if(empty($catalog['rows'])){echo '<p><em>No Momentec catalog styles match these filters.</em></p>';$this->wrap_end();return;}"""
new = """            if($momentec_view==='ready') echo '<p><strong>'.number_format_i18n((int)$catalog['total']).'</strong> hydrated style'.((int)$catalog['total']===1?'':'s').' ready to import.</p>';
            else echo '<p><strong>'.number_format_i18n((int)$catalog['total']).'</strong> matching styles. Every style now has its own <strong>Import</strong> action. If customer-specific details are not cached yet, clicking Import automatically queues that one style for secure GitHub hydration; once ready, it appears under <strong>Ready to Import</strong>. You can still use the checkboxes for optional bulk preparation.</p>';
            if(empty($catalog['rows'])){echo $momentec_view==='ready'?'<p><em>No Momentec styles are ready yet. Queued styles will appear here after the GitHub detail worker completes.</em></p>':'<p><em>No Momentec catalog styles match these filters.</em></p>';$this->wrap_end();return;}"""
admin = replace_once(admin, old, new, 'momentec ready copy')

# Preserve Ready/All view through row/bulk POST actions.
old = """            echo '<input type=\"hidden\" name=\"return_q\" value=\"'.esc_attr($search).'\"><input type=\"hidden\" name=\"return_brand\" value=\"'.esc_attr($brand).'\"><input type=\"hidden\" name=\"return_category\" value=\"'.esc_attr($category).'\"><input type=\"hidden\" name=\"return_catalog_page\" value=\"'.(int)$catalog['page'].'\">';"""
new = """            echo '<input type=\"hidden\" name=\"return_q\" value=\"'.esc_attr($search).'\"><input type=\"hidden\" name=\"return_brand\" value=\"'.esc_attr($brand).'\"><input type=\"hidden\" name=\"return_category\" value=\"'.esc_attr($category).'\"><input type=\"hidden\" name=\"return_catalog_page\" value=\"'.(int)$catalog['page'].'\"><input type=\"hidden\" name=\"return_momentec_view\" value=\"'.esc_attr($momentec_view).'\">';"""
admin = replace_once(admin, old, new, 'momentec hidden return view')

# Single queue redirect: preserve view.
old = """                'catalog_page'=>max(1,absint($_POST['return_catalog_page'] ?? 1)),
            ];"""
new = """                'catalog_page'=>max(1,absint($_POST['return_catalog_page'] ?? 1)),
                'momentec_view'=>in_array(sanitize_key((string)($_POST['return_momentec_view'] ?? 'all')),['all','ready'],true)?sanitize_key((string)($_POST['return_momentec_view'] ?? 'all')):'all',
            ];"""
admin = replace_once(admin, old, new, 'single momentec queue preserve view')

# Bulk queue redirect: preserve filters/page/view instead of dumping back to catalog start.
old = """            wp_safe_redirect(add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','asss_msg'=>$message],admin_url('admin.php')));exit;"""
new = """            $view=in_array(sanitize_key((string)($_POST['return_momentec_view'] ?? 'all')),['all','ready'],true)?sanitize_key((string)($_POST['return_momentec_view'] ?? 'all')):'all';
            wp_safe_redirect(add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','q'=>sanitize_text_field(wp_unslash((string)($_POST['return_q'] ?? ''))),'brand'=>sanitize_text_field(wp_unslash((string)($_POST['return_brand'] ?? ''))),'category'=>sanitize_text_field(wp_unslash((string)($_POST['return_category'] ?? ''))),'catalog_page'=>max(1,absint($_POST['return_catalog_page'] ?? 1)),'momentec_view'=>$view,'asss_msg'=>$message],admin_url('admin.php')));exit;"""
admin = replace_once(admin, old, new, 'bulk momentec queue preserve context')
write('includes/class-asss-admin.php', admin)

# ---------------- Momentec catalog filtering ----------------
momentec = read('includes/class-asss-momentec.php')
old = """    public function catalog_search(string $search='', string $brand='', string $category='', int $page=1, int $per_page=50): array {
        $needle=mb_strtolower(trim($search)); $brand=trim($brand); $category=trim($category); $rows=[];
        $hydrated=$this->style_manifest();"""
new = """    public function catalog_search(string $search='', string $brand='', string $category='', int $page=1, int $per_page=50, string $view='all'): array {
        $needle=mb_strtolower(trim($search)); $brand=trim($brand); $category=trim($category); $rows=[];
        $view=in_array($view,['all','ready'],true)?$view:'all';
        $hydrated=$this->style_manifest();"""
momentec = replace_once(momentec, old, new, 'momentec catalog signature')
old = """            $style=(string)($row['style'] ?? ''); $key=$this->style_key($style); $request=$this->request_for_style($style);
            $row['hydrated']=isset($hydrated[$key]);"""
new = """            $style=(string)($row['style'] ?? ''); $key=$this->style_key($style); $request=$this->request_for_style($style);
            if($view==='ready' && !isset($hydrated[$key]))continue;
            $row['hydrated']=isset($hydrated[$key]);"""
momentec = replace_once(momentec, old, new, 'momentec ready filter')
write('includes/class-asss-momentec.php', momentec)

# ---------------- Default variation for NEW imports ----------------
imp = read('includes/class-asss-importer.php')
helper = r'''
    /**
     * Set a deterministic customer-facing default only for newly created supplier
     * products. Sort real variations by displayed Color, then Size, then any
     * remaining attributes; choosing an actual child guarantees the defaults are
     * a sellable combination rather than an impossible Cartesian pairing.
     */
    private function set_new_product_alphabetical_defaults(int $product_id): void {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product_Variable) return;
        $candidates = [];
        foreach ($this->variation_ids_direct($product_id) as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation instanceof WC_Product_Variation || $variation->get_status() === 'trash') continue;
            $attrs = array_filter((array)$variation->get_attributes(), static fn($v) => trim((string)$v) !== '');
            if (!$attrs) continue;
            $ordered_keys = [];
            foreach (['pa_color','color','pa_size','size'] as $preferred) if (array_key_exists($preferred,$attrs)) $ordered_keys[]=$preferred;
            $rest = array_values(array_diff(array_keys($attrs),$ordered_keys)); sort($rest,SORT_NATURAL|SORT_FLAG_CASE); $ordered_keys=array_merge($ordered_keys,$rest);
            $labels = [];
            foreach ($ordered_keys as $key) {
                $value=(string)$attrs[$key]; $label=$value;
                if (taxonomy_exists($key)) { $term=get_term_by('slug',$value,$key); if ($term && !is_wp_error($term)) $label=(string)$term->name; }
                $labels[] = mb_strtolower(wp_strip_all_tags(rawurldecode($label)));
            }
            $candidates[]=['id'=>(int)$variation_id,'attrs'=>$attrs,'labels'=>$labels];
        }
        if (!$candidates) return;
        usort($candidates, static function($a,$b){
            $max=max(count($a['labels']),count($b['labels']));
            for($i=0;$i<$max;$i++){ $cmp=strnatcasecmp((string)($a['labels'][$i]??''),(string)($b['labels'][$i]??'')); if($cmp!==0)return $cmp; }
            return (int)$a['id'] <=> (int)$b['id'];
        });
        $defaults=[]; foreach((array)$candidates[0]['attrs'] as $key=>$value) if(trim((string)$value)!=='')$defaults[$key]=(string)$value;
        if(!$defaults)return;
        $product->set_default_attributes($defaults); $product->save();
        ASSS_Logger::log('Set alphabetical default variation for new import','info',['product_id'=>$product_id,'variation_id'=>(int)$candidates[0]['id'],'defaults'=>$defaults]);
    }

'''
marker = "    private function hide_discontinued_product(WC_Product $product, string $status): void {"
imp = replace_once(imp, marker, helper + marker, 'insert default variation helper')

# Add after post-import hooks so OSFA normalization has already run.
old = """        do_action('asss_product_synced', $product_id, 'sanmar', ['brand'=>$brand, 'style'=>$style, 'mode'=>'import']);

        ASSS_Logger::log("Imported/updated SanMar {$brand} {$style}", 'info', ["""
new = """        do_action('asss_product_synced', $product_id, 'sanmar', ['brand'=>$brand, 'style'=>$style, 'mode'=>'import']);
        if ($is_new) $this->set_new_product_alphabetical_defaults($product_id);

        ASSS_Logger::log("Imported/updated SanMar {$brand} {$style}", 'info', ["""
imp = replace_once(imp, old, new, 'SanMar new default')

old = """        do_action('asss_product_synced', $product_id, 'ss', ['brand_id'=>$brand_id,'brand'=>$brand,'style_id'=>$style_id,'style'=>$style,'mode'=>'import']);
        ASSS_Logger::log('Imported/updated S&S product', 'info', ["""
new = """        do_action('asss_product_synced', $product_id, 'ss', ['brand_id'=>$brand_id,'brand'=>$brand,'style_id'=>$style_id,'style'=>$style,'mode'=>'import']);
        if ($is_new) $this->set_new_product_alphabetical_defaults($product_id);
        ASSS_Logger::log('Imported/updated S&S product', 'info', ["""
imp = replace_once(imp, old, new, 'SS new default')

old = """WC_Product_Variable::sync($product_id);wc_delete_product_transients($product_id);do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'import']);ASSS_Logger::log('Imported/updated Momentec product'"""
new = """WC_Product_Variable::sync($product_id);wc_delete_product_transients($product_id);do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'import']);if($is_new)$this->set_new_product_alphabetical_defaults($product_id);ASSS_Logger::log('Imported/updated Momentec product'"""
imp = replace_once(imp, old, new, 'Momentec new default')
write('includes/class-asss-importer.php', imp)

# ---------------- Version/readme ----------------
main = read('all-star-supplier-sync.php')
main = replace_once(main, ' * Version: 2.0.19', ' * Version: 2.0.20', 'plugin header version')
main = replace_once(main, "define('ASSS_VERSION', '2.0.19');", "define('ASSS_VERSION', '2.0.20');", 'version constant')
write('all-star-supplier-sync.php', main)

readme = read('readme.txt')
readme = re.sub(r'^Stable tag: .*$', 'Stable tag: 2.0.20', readme, count=1, flags=re.M)
changelog = """= 2.0.20 =
* Moved bridge-token regeneration to a bottom-of-page Danger Zone and added acknowledgement, typed REGENERATE confirmation, browser confirmation, and server-side enforcement before a token can change.
* Newly created SanMar, S&S, and Momentec variable products now default to the first alphabetical real variation combination (Color, then Size), guaranteeing WooCommerce defaults point to a sellable child. Existing products/defaults are not changed.
* Added a Momentec Ready to Import view on Add Products for styles whose secure GitHub customer-detail hydration has completed.
* Momentec queue actions now preserve the current search, brand/category filters, catalog page, and All/Ready view so long catalog workflows do not reset unexpectedly.
* Updated plugin readme Stable Tag to match the current release.

"""
readme = replace_once(readme, '== Changelog ==\n\n', '== Changelog ==\n\n' + changelog, 'readme changelog')
write('readme.txt', readme)

print('v2.0.20 patch applied successfully')
