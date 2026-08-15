#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v206_patch_admin.py <source-dir>')
root=Path(sys.argv[1]); path=root/'includes/class-asss-admin.php'; text=path.read_text(encoding='utf-8')

def replace_once(old,new,label):
    global text
    if old not in text: raise SystemExit(f'v2.0.6 admin marker missing: {label}')
    text=text.replace(old,new,1)

# Hidden review route.
replace_once(
"        add_submenu_page(null, 'Review S&S Product', 'Review S&S Product', 'manage_woocommerce', 'asss-ss-review', [$this, 'ss_review_page']);",
"        add_submenu_page(null, 'Review S&S Product', 'Review S&S Product', 'manage_woocommerce', 'asss-ss-review', [$this, 'ss_review_page']);\n        add_submenu_page(null, 'Review Momentec Product', 'Review Momentec Product', 'manage_woocommerce', 'asss-momentec-review', [$this, 'momentec_review_page']);",
'Momentec review menu')

# Momentec import/link actions go before the existing S&S link action.
action_marker="        if (!empty($_POST['asss_link_ss_existing'])) {"
if action_marker not in text: raise SystemExit('v2.0.6 admin marker missing: S&S link action')
actions=r'''        if (!empty($_POST['asss_import_momentec_style'])) {
            check_admin_referer('asss_momentec_import');
            $style=sanitize_text_field(wp_unslash($_POST['style'] ?? ''));
            $colors=array_values(array_filter(array_map('sanitize_text_field',(array)($_POST['colors'] ?? []))));
            $r=$this->importer->import_momentec_style($style,$colors);
            if(is_wp_error($r)){
                wp_safe_redirect(add_query_arg(['page'=>'asss-momentec-review','style'=>$style,'asss_err'=>$r->get_error_message()],admin_url('admin.php')));exit;
            }
            wp_safe_redirect(add_query_arg(['post'=>(int)$r,'action'=>'edit','asss_imported'=>1],admin_url('post.php')));exit;
        }

        if (!empty($_POST['asss_link_momentec_existing'])) {
            check_admin_referer('asss_momentec_import');
            $style=sanitize_text_field(wp_unslash($_POST['style'] ?? ''));
            $product_id=absint($_POST['product_id'] ?? 0);
            $colors=array_values(array_filter(array_map('sanitize_text_field',(array)($_POST['colors'] ?? []))));
            $r=$this->importer->link_momentec_style_to_product($product_id,$style,$colors);
            if(is_wp_error($r)){
                wp_safe_redirect(add_query_arg(['page'=>'asss-momentec-review','style'=>$style,'asss_err'=>$r->get_error_message()],admin_url('admin.php')));exit;
            }
            wp_safe_redirect(add_query_arg(['page'=>'asss-manage-suppliers','product_id'=>(int)$r,'asss_msg'=>'Momentec linked to the existing WooCommerce product.'],admin_url('admin.php')));exit;
        }

'''
text=text.replace(action_marker,actions+action_marker,1)

# Replace Add Products Momentec placeholder with cached production style browser.
func=text.find('    public function add_products(): void {')
start=text.find("        if ($supplier === 'momentec') {",func)
end=text.find("        if ($supplier === 'ss') {",start)
if start<0 or end<0: raise SystemExit('Could not locate Momentec Add Products placeholder.')
add_block=r'''        if ($supplier === 'momentec') {
            $status=$this->momentec->status();
            $search=sanitize_text_field(wp_unslash($_GET['search'] ?? ''));
            $styles=$this->momentec->style_summaries($search,250);
            echo '<div class="notice notice-success inline"><p><strong>Momentec production v2 is connected through GitHub Actions.</strong> Credentials remain in GitHub Secrets. WordPress stores only normalized supplier catalog/pricing/inventory data.</p></div>';
            echo '<p>Momentec v2 does not expose a full catalog-list endpoint. To add a new style intentionally, run <strong>Publish Momentec Production Style</strong> in <code>rolejarczyk/ASE.ProductSync</code> and enter the Momentec style/product number. The normalized style will appear here for review.</p>';
            echo '<p><strong>Cached styles:</strong> '.esc_html((string)($status['cached_styles'] ?? 0)).' &nbsp; <strong>Exact variations:</strong> '.esc_html((string)($status['cached_variants'] ?? 0)).'</p>';
            echo '<form method="get" style="display:flex;gap:8px;align-items:center;margin:14px 0 18px"><input type="hidden" name="page" value="asss-suppliers"><input type="hidden" name="supplier" value="momentec"><input type="search" name="search" value="'.esc_attr($search).'" placeholder="Search style, title, or brand" style="min-width:320px"><button class="button">Search cached styles</button></form>';
            if(!$styles){echo '<p><em>No cached Momentec styles yet'.($search!==''?' matching this search':'').'.</em></p>';$this->wrap_end();return;}
            echo '<table class="widefat striped"><thead><tr><th style="width:74px">Image</th><th>Style</th><th>Product</th><th>Manufacturer</th><th>Colors</th><th>Sizes</th><th>Exact variations</th><th></th></tr></thead><tbody>';
            foreach($styles as $row){
                $style=(string)($row['style'] ?? '');$image=(string)($row['image'] ?? '');
                echo '<tr><td>'.($image!==''?'<img src="'.esc_url($image).'" alt="" style="width:58px;height:58px;object-fit:contain;background:#fff;border:1px solid #ddd">':'—').'</td>';
                echo '<td><code>'.esc_html($style).'</code></td><td><strong>'.esc_html((string)($row['title'] ?? '')).'</strong></td><td>'.esc_html((string)($row['brand'] ?? '')).'</td><td>'.esc_html((string)($row['color_count'] ?? 0)).'</td><td>'.esc_html((string)($row['size_count'] ?? 0)).'</td><td>'.esc_html((string)($row['variant_count'] ?? 0));
                if(!empty($row['sparse_missing']))echo '<br><span class="description">'.esc_html((string)$row['sparse_missing']).' nonexistent Cartesian combos avoided</span>';
                echo '</td><td><a class="button button-primary" href="'.esc_url(add_query_arg(['page'=>'asss-momentec-review','style'=>$style],admin_url('admin.php'))).'">Review &amp; Import</a></td></tr>';
            }
            echo '</tbody></table>';
            $this->wrap_end(); return;
        }

'''
text=text[:start]+add_block+text[end:]

# Replace Brands-page Momentec placeholder with cached-manufacturer overview.
func=text.find('    public function brands_page(): void {')
start=text.find("        if ($supplier === 'momentec') {",func)
end=text.find("        if ($supplier === 'ss') {",start)
if start<0 or end<0: raise SystemExit('Could not locate Momentec Brands placeholder.')
brands_block=r'''        if ($supplier === 'momentec') {
            $groups=[];
            foreach($this->momentec->style_summaries('',0) as $row){$brand=trim((string)($row['brand'] ?? ''));if($brand==='')$brand='Unknown manufacturer';if(!isset($groups[$brand]))$groups[$brand]=['styles'=>0,'variants'=>0];$groups[$brand]['styles']++;$groups[$brand]['variants']+=(int)($row['variant_count'] ?? 0);}
            ksort($groups,SORT_NATURAL|SORT_FLAG_CASE);
            echo '<div class="notice notice-info inline"><p><strong>Momentec v2 production does not provide a general manufacturer/catalog directory.</strong> This page therefore shows manufacturers discovered from styles you intentionally publish through GitHub Actions.</p></div>';
            if(!$groups){echo '<p>No Momentec manufacturers have been discovered yet. Publish a style from the GitHub workflow first.</p>';$this->wrap_end();return;}
            echo '<table class="asss-brand-table widefat"><thead><tr><th>Manufacturer</th><th>Cached styles</th><th>Exact variations</th></tr></thead><tbody>';
            foreach($groups as $brand=>$counts)echo '<tr><td class="asss-brand-name"><strong>'.esc_html($brand).'</strong></td><td>'.esc_html((string)$counts['styles']).'</td><td>'.esc_html((string)$counts['variants']).'</td></tr>';
            echo '</tbody></table>';$this->wrap_end();return;
        }

'''
text=text[:start]+brands_block+text[end:]

# Update old groundwork wording anywhere it remains in settings/status copy.
text=text.replace('Live Momentec catalog/inventory calls remain disabled until we verify the exact staging authentication endpoint and response schema. No speculative API key or authentication method is used.', 'Momentec production v2 username/password authentication is verified in GitHub Actions. Catalog style lookups and targeted inventory sync run from GitHub; WordPress never receives the credentials.')
text=text.replace('Momentec adapter groundwork is installed.', 'Momentec production GitHub bridge is installed.')
text=text.replace('Current state:', 'Current bridge state:')

# Append review screen.
pos=text.rfind('\n}')
if pos<0: raise SystemExit('Could not locate admin class close.')
review=r'''
    public function momentec_review_page(): void {
        $style=sanitize_text_field(wp_unslash($_GET['style'] ?? ''));
        $this->wrap_start('Review Momentec Product');$this->notice();
        if($style===''){echo '<p>No Momentec style was selected.</p>';$this->wrap_end();return;}
        $data=$this->momentec->style_product($style);
        if(is_wp_error($data)){echo '<div class="notice notice-error inline"><p>'.esc_html($data->get_error_message()).'</p></div>';$this->wrap_end();return;}
        $brand=trim((string)($data['brand'] ?? ''));$title=trim((string)($data['title'] ?? $style));$description=trim((string)($data['description'] ?? ''));$variants=is_array($data['variants'] ?? null)?$data['variants']:[];
        $colors=[];$sizes=[];$color_images=[];
        foreach($variants as $row){if(!is_array($row))continue;$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$s=trim((string)($row['size'] ?? ''));if($c!==''){$colors[$c]=($colors[$c]??0)+1;if(empty($color_images[$c]))$color_images[$c]=(string)($row['primary_image'] ?? ($row['gallery'][0] ?? ''));}if($s!=='')$sizes[$s]=true;}
        $image=$this->momentec->representative_product_image($data);$theoretical=count($colors)*count($sizes);$missing=max(0,$theoretical-count($variants));
        echo '<p><a href="'.esc_url(add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec'],admin_url('admin.php'))).'">&larr; Back to Momentec cached styles</a></p>';
        echo '<div style="display:grid;grid-template-columns:180px 1fr;gap:22px;align-items:start;max-width:1050px">';
        echo '<div>'.($image!==''?'<img src="'.esc_url($image).'" alt="" style="width:180px;height:180px;object-fit:contain;background:#fff;border:1px solid #ddd">':'').'</div><div><h2 style="margin-top:0">'.esc_html($title).'</h2><p><strong>Manufacturer:</strong> '.esc_html($brand?:'—').' &nbsp; <strong>Momentec style:</strong> <code>'.esc_html($style).'</code></p>';
        echo '<p><strong>'.count($variants).'</strong> exact supplier variations · '.count($colors).' colors · '.count($sizes).' sizes';if($missing)echo ' · <strong>'.esc_html((string)$missing).'</strong> nonexistent Cartesian combinations intentionally avoided';echo '</p>';
        if($description!=='')echo '<p>'.esc_html(wp_trim_words(wp_strip_all_tags($description),55,'…')).'</p>';echo '</div></div>';
        echo '<hr><h2>Choose colors to import/link</h2><p>Only checked colors are used, and only the exact Color+Size SKUs returned by Momentec are created.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;max-width:1100px;margin-bottom:18px">';
        foreach($colors as $color=>$count){$ci=(string)($color_images[$color] ?? '');echo '<label style="display:flex;gap:9px;align-items:center;border:1px solid #dcdcde;background:#fff;padding:9px;border-radius:4px">'.($ci!==''?'<img src="'.esc_url($ci).'" alt="" style="width:42px;height:42px;object-fit:contain">':'').'<input type="checkbox" class="asss-momentec-color" value="'.esc_attr($color).'" checked><span><strong>'.esc_html($color).'</strong><br><span class="description">'.esc_html((string)$count).' exact SKU'.($count===1?'':'s').'</span></span></label>';}
        echo '</div>';

        $momentec_existing=$this->importer->find_momentec_product($style,$brand);$sanmar_match=$this->importer->find_product($style,$brand);$ss_match=$this->importer->find_ss_product_by_brand_style($brand,$style);$auto_match=$sanmar_match?:$ss_match;
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start">';
        echo '<form method="post" class="asss-momentec-action" style="margin:0">';wp_nonce_field('asss_momentec_import');echo '<input type="hidden" name="asss_import_momentec_style" value="1"><input type="hidden" name="style" value="'.esc_attr($style).'"><div class="asss-selected-color-inputs"></div><button class="button button-primary button-hero">'.($momentec_existing?'Refresh / Import Selected Colors':'Create WooCommerce Product').'</button></form>';
        if($auto_match){$p=wc_get_product($auto_match);echo '<form method="post" class="asss-momentec-action" style="margin:0">';wp_nonce_field('asss_momentec_import');echo '<input type="hidden" name="asss_link_momentec_existing" value="1"><input type="hidden" name="style" value="'.esc_attr($style).'"><input type="hidden" name="product_id" value="'.esc_attr((string)$auto_match).'"><div class="asss-selected-color-inputs"></div><button class="button button-hero">Link to existing '.esc_html($p?$p->get_name():'#'.$auto_match).' (#'.esc_html((string)$auto_match).')</button></form>';}
        echo '</div>';
        echo '<details style="margin-top:18px;max-width:720px"><summary><strong>Link to a different existing WooCommerce product</strong></summary><form method="post" class="asss-momentec-action" style="margin-top:10px">';wp_nonce_field('asss_momentec_import');echo '<input type="hidden" name="asss_link_momentec_existing" value="1"><input type="hidden" name="style" value="'.esc_attr($style).'"><div class="asss-selected-color-inputs"></div><label>WooCommerce product ID <input type="number" min="1" name="product_id" required></label> <button class="button">Link Momentec</button></form></details>';
        echo '<script>(function(){function sync(){var vals=[];document.querySelectorAll(".asss-momentec-color:checked").forEach(function(c){vals.push(c.value);});document.querySelectorAll(".asss-selected-color-inputs").forEach(function(box){box.innerHTML="";vals.forEach(function(v){var i=document.createElement("input");i.type="hidden";i.name="colors[]";i.value=v;box.appendChild(i);});});}document.querySelectorAll(".asss-momentec-color").forEach(function(c){c.addEventListener("change",sync);});document.querySelectorAll(".asss-momentec-action").forEach(function(f){f.addEventListener("submit",sync);});sync();})();</script>';
        $this->wrap_end();
    }
'''
text=text[:pos]+'\n'+review.rstrip()+'\n'+text[pos:]
path.write_text(text,encoding='utf-8')
print('Applied v2.0.6 Momentec admin browser, review, import, and link flow.')
