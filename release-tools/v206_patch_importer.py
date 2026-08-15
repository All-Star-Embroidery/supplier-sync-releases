#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v206_patch_importer.py <source-dir>')
root = Path(sys.argv[1])
path = root / 'includes/class-asss-importer.php'
text = path.read_text(encoding='utf-8')

def replace_once(old, new, label):
    global text
    if old not in text:
        raise SystemExit(f'v2.0.6 importer marker missing: {label}')
    text = text.replace(old, new, 1)

replace_once(
"    private ASSS_SS $ss;\n    private ASSS_MultiSupplier $multi;",
"    private ASSS_SS $ss;\n    private ASSS_Momentec $momentec;\n    private ASSS_MultiSupplier $multi;",
'importer property')
replace_once(
"    public function __construct(ASSS_SanMar $sanmar, ASSS_SS $ss, ASSS_MultiSupplier $multi) {\n        $this->sanmar = $sanmar;\n        $this->ss = $ss;\n        $this->multi = $multi;\n    }",
"    public function __construct(ASSS_SanMar $sanmar, ASSS_SS $ss, ASSS_Momentec $momentec, ASSS_MultiSupplier $multi) {\n        $this->sanmar = $sanmar;\n        $this->ss = $ss;\n        $this->momentec = $momentec;\n        $this->multi = $multi;\n    }",
'importer constructor')
replace_once(
"        if ($supplier === 'momentec') return new WP_Error('momentec_not_live', 'Momentec product repair is staged but not live until API credentials and schema are verified.');",
"        if ($supplier === 'momentec') return $this->update_momentec_style($product_id);",
'Momentec update dispatch')
text = text.replace("['sanmar','ss','supplier']", "['sanmar','ss','momentec','supplier']")

# Multi-supplier repair needs to refresh Momentec too.
needle = "        if($errors)return new WP_Error('multi_repair',implode(' | ',$errors));"
if needle not in text:
    raise SystemExit('v2.0.6 importer marker missing: multi repair end')
momentec_multi = r'''        if (!empty($sources['momentec']['enabled'])) {
            $style=(string)($sources['momentec']['style'] ?? get_post_meta($product_id,'_asss_momentec_style',true));
            $colors=is_array($sources['momentec']['selected_colors'] ?? null)?$sources['momentec']['selected_colors']:[];
            $r=$this->link_momentec_style_to_product($product_id,$style,$colors);
            if(is_wp_error($r))$errors[]='Momentec: '.$r->get_error_message();
        }
'''
text = text.replace(needle, momentec_multi + needle, 1)

# Insert complete Momentec importer immediately before discontinued-product helper.
marker = "    private function hide_discontinued_product(WC_Product $product, string $status): void {"
if marker not in text:
    raise SystemExit('v2.0.6 importer marker missing: hide_discontinued_product')
addition = r'''
    public function find_momentec_product(string $style, string $brand = ''): int {
        $meta = [['key'=>'_asss_momentec_style','value'=>$style]];
        if ($brand !== '') $meta[] = ['key'=>'_asss_momentec_brand','value'=>$brand];
        $q = new WP_Query(['post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>3,'meta_query'=>$meta]);
        foreach ((array)$q->posts as $id) {
            $sources=$this->multi->product_sources((int)$id);
            if (!empty($sources['momentec']['enabled'])) return (int)$id;
        }
        return 0;
    }

    private function momentec_colors_from_variants(array $variants): array {
        $colors=[];
        foreach($variants as $row){
            if(!is_array($row))continue;
            $c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            if($c!=='')$colors[$c]=true;
        }
        return array_keys($colors);
    }

    private function maybe_set_momentec_parent_sku(WC_Product $product,string $brand,string $style): void {
        if($product->get_sku()!=='' || $style==='')return;
        $fallback=strtoupper(sanitize_title('MOMENTEC-'.$brand.'-'.$style));
        foreach(array_values(array_unique(array_filter([$style,$fallback]))) as $candidate){
            $existing=(int)wc_get_product_id_by_sku($candidate);
            if(!$existing || $existing===$product->get_id()){
                try{$product->set_sku($candidate);}catch(Exception $e){}
                if($product->get_sku()!=='')return;
            }
        }
    }

    private function momentec_variation_price(array $row): array {
        $cost=$row['customer_price'] ?? $row['piece_price'] ?? null;
        if($cost!==null && $cost!=='' && is_numeric($cost) && (float)$cost>0) return [(float)$cost+20.0,'supplier_markup:momentec'];
        $retail=$row['retail_price'] ?? null;
        return ($retail!==null && $retail!=='' && is_numeric($retail) && (float)$retail>0) ? [(float)$retail,'momentec_retail_fallback'] : [null,''];
    }

    private function find_momentec_variation(int $parent_id,string $supplier_id,string $color,string $size): int {
        if($supplier_id!==''){
            $q=new WP_Query(['post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'post_parent'=>$parent_id,'meta_query'=>[['key'=>'_asss_momentec_sku_id','value'=>$supplier_id]]]);
            if(!empty($q->posts[0]))return (int)$q->posts[0];
        }
        $q=new WP_Query(['post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'post_parent'=>$parent_id,'meta_query'=>[
            ['key'=>'_asss_momentec_color','value'=>$color],['key'=>'_asss_momentec_size','value'=>$size],
        ]]);
        return (int)($q->posts[0] ?? 0);
    }

    private function momentec_media_url(string $url): string {
        $url=trim($url);
        return preg_match('#^https?://#i',$url) ? esc_url_raw($url) : '';
    }

    private function sync_momentec_variation_media(int $variation_id,array $row): void {
        $urls=[];
        foreach((array)($row['gallery'] ?? []) as $raw){$u=$this->momentec_media_url((string)$raw);if($u!=='')$urls[$u]=true;}
        if(!$urls && !empty($row['primary_image'])){$u=$this->momentec_media_url((string)$row['primary_image']);if($u!=='')$urls[$u]=true;}
        $urls=array_keys($urls); if(!$urls)return;
        $v=wc_get_product($variation_id);if(!$v instanceof WC_Product_Variation)return;
        $supplier_ids=[];
        foreach($urls as $url){$aid=$this->sideload($url,$variation_id,'momentec');if($aid)$supplier_ids[]=(int)$aid;}
        $supplier_ids=array_values(array_unique(array_filter($supplier_ids)));if(!$supplier_ids)return;
        $current=(int)$v->get_image_id();$manual_primary=$current && !$this->is_supplier_attachment($current);
        if(!$manual_primary)$v->set_image_id($supplier_ids[0]);
        $manual_gallery=[];
        if(method_exists($v,'get_gallery_image_ids'))foreach((array)$v->get_gallery_image_ids() as $id)if((int)$id&&!$this->is_supplier_attachment((int)$id))$manual_gallery[]=(int)$id;
        $primary=$manual_primary?$current:$supplier_ids[0];
        $extra=array_values(array_filter($supplier_ids,static fn($id)=>(int)$id!==(int)$primary));
        if(method_exists($v,'set_gallery_image_ids'))$v->set_gallery_image_ids(array_values(array_unique(array_merge($extra,$manual_gallery))));
        $full=$manual_primary?array_values(array_unique(array_merge([$current],$supplier_ids,$manual_gallery))):array_values(array_unique(array_merge($supplier_ids,$manual_gallery)));
        $v->update_meta_data('_asss_variation_gallery_ids',$full);
        $v->update_meta_data('_asss_variation_gallery_urls',$urls);
        $v->update_meta_data('_asss_variation_gallery_supplier_count',count($supplier_ids));
        $v->update_meta_data('_asss_resolved_variation_image_url',esc_url_raw($urls[0]));
        $v->save();
    }

    private function sync_momentec_parent_media(int $product_id,array $data,array $variants): void {
        $product=wc_get_product($product_id);if(!$product)return;
        $urls=[];
        foreach([(string)($data['images']['product'] ?? ''),(string)($data['images']['thumbnail'] ?? '')] as $raw){$u=$this->momentec_media_url($raw);if($u!=='')$urls[$u]=true;}
        foreach($variants as $row){foreach((array)($row['gallery'] ?? []) as $raw){$u=$this->momentec_media_url((string)$raw);if($u!=='')$urls[$u]=true;}if(count($urls)>=8)break;}
        if(!$urls)return;$ids=[];
        foreach(array_keys($urls) as $url){$id=$this->sideload($url,$product_id,'momentec');if($id)$ids[]=(int)$id;}
        $ids=array_values(array_unique($ids));if(!$ids)return;
        $current=(int)$product->get_image_id();if(!$current||$this->is_supplier_attachment($current))$product->set_image_id($ids[0]);
        $manual=[];foreach($product->get_gallery_image_ids() as $id)if((int)$id&&!$this->is_supplier_attachment((int)$id))$manual[]=(int)$id;
        $primary=(int)$product->get_image_id();$supplier_gallery=array_values(array_filter($ids,static fn($id)=>(int)$id!==$primary));
        $product->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_gallery,$manual))));$product->save();
    }

    private function sync_momentec_bulk_order_fields(WC_Product_Variable $product,array $data,array $variants,bool $is_new): void {
        $settings=$this->sanmar->settings();$title=trim((string)($data['title'] ?? ''));$description=trim(wp_strip_all_tags((string)($data['description'] ?? '')));
        $brand=trim((string)($data['brand'] ?? ''));$style=trim((string)($data['style'] ?? ''));$sizes=[];
        foreach($variants as $row){$size=trim((string)($row['size'] ?? ''));if($size!=='')$sizes[$size]=true;}
        if(!empty($settings['sync_bulk_order_fields'])){
            if((string)$product->get_meta('_asbo_display_name')===''&&$title!=='')$product->update_meta_data('_asbo_display_name',$title);
            if((string)$product->get_meta('_asbo_short_description')===''&&$description!=='')$product->update_meta_data('_asbo_short_description',wp_trim_words($description,55,'…'));
            if((string)$product->get_meta('_asbo_size_chart')===''){
                $parts=[];if($sizes)$parts[]='<strong>Available sizes:</strong> '.esc_html(implode(', ',array_keys($sizes)));
                $label=trim('Momentec Brands'.($brand?' · '.$brand:'').($style?' · Style '.$style:''));if($label!=='')$parts[]='<strong>Supplier:</strong> '.esc_html($label);
                if($parts)$product->update_meta_data('_asbo_size_chart',implode("<br>\n",$parts));
            }
            if(!empty($settings['auto_enable_bulk_order'])&&(string)$product->get_meta('_asbo_enabled')==='')$product->update_meta_data('_asbo_enabled','yes');
        }
        $this->sync_parent_dimensions($product,$this->ss_common_rows($variants,$data));
    }

    private function sync_momentec_variation(int $product_id,array $row): array {
        $supplier_id=trim((string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? $row['sku'] ?? ''));
        $sku=trim((string)($row['sku'] ?? $row['inventory_key'] ?? $supplier_id));
        $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));
        if($color===''||$size===''||$sku==='')return ['created'=>false,'variation_id'=>0];
        $variation_id=$this->find_momentec_variation($product_id,$supplier_id,$color,$size);$created=!$variation_id;
        $v=$variation_id?wc_get_product($variation_id):new WC_Product_Variation();if(!$v instanceof WC_Product_Variation)return ['created'=>false,'variation_id'=>0];
        if($created)$v->set_parent_id($product_id);$v->set_status('publish');
        $v->set_attributes(['pa_color'=>$this->term_slug('pa_color',$color),'pa_size'=>$this->term_slug('pa_size',$size)]);
        if($v->get_sku('edit')===''&&$sku!==''){
            $candidate=$sku;$conflict=(int)wc_get_product_id_by_sku($candidate);if($conflict&&$conflict!==$v->get_id())$candidate='MOM-'.$sku;
            $conflict=(int)wc_get_product_id_by_sku($candidate);if(!$conflict||$conflict===$v->get_id())try{$v->set_sku($candidate);}catch(Exception $e){}
        }
        if(isset($row['qty'])&&$row['qty']!==null&&$row['qty']!==''&&is_numeric($row['qty'])){
            $qty=max(0,(int)$row['qty']);$v->set_manage_stock(true);$v->set_backorders('no');$v->set_stock_quantity($qty);$v->set_stock_status($qty>0?'instock':'outofstock');
        }
        [$price,$source]=$this->momentec_variation_price($row);
        if(!empty($this->sanmar->settings()['sync_variation_base_prices'])&&$price!==null&&$price>0){
            $current=$v->get_regular_price('edit');$managed=(string)$v->get_meta('_asss_base_price_managed')==='yes';
            if($current==='')$this->apply_managed_base_price($v,(float)$price,$source);
            elseif($managed&&$this->managed_price_can_update($v,(float)$price))$this->apply_managed_base_price($v,(float)$price,$source);
        }
        $brand=(string)get_post_meta($product_id,'_asss_momentec_brand',true);$style=(string)get_post_meta($product_id,'_asss_momentec_style',true);
        $v->update_meta_data('_asss_supplier','momentec');$v->update_meta_data('_asss_supplier_product_key','momentec|'.strtolower($brand).'|'.strtolower($style));
        $v->update_meta_data('_asss_momentec_sku_id',$supplier_id);$v->update_meta_data('_asss_momentec_sku',$sku);$v->update_meta_data('_asss_momentec_color',$color);$v->update_meta_data('_asss_momentec_size',$size);
        $v->update_meta_data('_asss_supplier_cost',(string)($row['customer_price'] ?? ''));$v->update_meta_data('_asss_momentec_cost',(string)($row['customer_price'] ?? ''));
        $v->update_meta_data('_asss_momentec_retail_price',(string)($row['retail_price'] ?? ''));$v->update_meta_data('_asss_momentec_availability',sanitize_text_field((string)($row['availability'] ?? '')));
        $v->update_meta_data('_asss_momentec_availability_date',sanitize_text_field((string)($row['availability_date'] ?? '')));$v->delete_meta_data('_asss_stale_variation');$v->delete_meta_data('_asss_stale_variation_reason');
        $variation_id=$v->save();
        $this->multi->register_variation_source($variation_id,'momentec',[
            'sku'=>$sku,'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '',
            'retail_price'=>$row['retail_price'] ?? '','inventory_qty'=>isset($row['qty'])&&is_numeric($row['qty'])?(int)$row['qty']:null,
            'availability'=>(string)($row['availability'] ?? ''),'availability_date'=>(string)($row['availability_date'] ?? ''),'gallery'=>(array)($row['gallery'] ?? []),
        ]);
        if(!empty($this->sanmar->settings()['sync_images']))$this->sync_momentec_variation_media($variation_id,$row);
        do_action('asss_variation_synced',$variation_id,$product_id,'momentec',['brand'=>$brand,'style'=>$style,'color'=>$color,'size'=>$size,'sku'=>$sku]);
        return ['created'=>$created,'variation_id'=>$variation_id];
    }

    private function reconcile_momentec_variations(int $product_id,array $rows,bool $allow_create=true): array {
        $expected=[];
        foreach($rows as $row){if(!is_array($row))continue;$color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));if($color===''||$size==='')continue;$key=$this->canonical_combo($color,$size);$expected[$key]=$row;}
        $created=0;$updated=0;$seen=[];
        foreach($expected as $combo=>$row){$existing=$this->find_momentec_variation($product_id,(string)($row['unique_key'] ?? $row['sku'] ?? ''),(string)($row['color'] ?? ''),(string)($row['size'] ?? ''));if(!$existing&&!$allow_create)continue;$r=$this->sync_momentec_variation($product_id,$row);if(!empty($r['created']))$created++;elseif(!empty($r['variation_id']))$updated++;if(!empty($r['variation_id']))$seen[$combo]=(int)$r['variation_id'];}
        $expected_source=[];foreach($rows as $row){$k=(string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? $row['sku'] ?? '');if($k==='')$k=$this->canonical_combo((string)($row['color'] ?? ''),(string)($row['size'] ?? ''));if($k!=='')$expected_source[$k]=true;}
        $this->disable_missing_source_variations($product_id,'momentec',$expected_source);
        $missing_price=0;$missing_image=0;$missing_gallery=0;$missing_sku=0;
        foreach($seen as $combo=>$vid){$v=wc_get_product($vid);if(!$v instanceof WC_Product_Variation)continue;$row=$expected[$combo];if($v->get_regular_price('edit')===''||$v->get_price('edit')==='')$missing_price++;if($v->get_sku('edit')==='')$missing_sku++;if(!empty($this->sanmar->settings()['sync_images'])&&!empty($row['gallery'])){$saved=$v->get_meta('_asss_variation_gallery_ids');if(is_string($saved)){$d=json_decode($saved,true);if(is_array($d))$saved=$d;}if(!$v->get_image_id())$missing_image++;if(count((array)$row['gallery'])>1&&count((array)$saved)<2)$missing_gallery++;}}
        $audit=['supplier'=>'momentec','expected'=>count($expected),'supplier_variations'=>count($seen),'missing_expected'=>max(0,count($expected)-count($seen)),'missing_price'=>$missing_price,'missing_image_when_available'=>$missing_image,'missing_variation_gallery'=>$missing_gallery,'missing_sku'=>$missing_sku,'created'=>$created,'updated'=>$updated,'checked_at'=>current_time('mysql')];
        update_post_meta($product_id,'_asss_last_variation_audit',wp_json_encode($audit));
        ASSS_Logger::log(array_sum([$audit['missing_expected'],$missing_price,$missing_image,$missing_gallery,$missing_sku])?'Momentec variation audit completed with issues':'Momentec variation audit passed',array_sum([$audit['missing_expected'],$missing_price,$missing_image,$missing_gallery,$missing_sku])?'warning':'info',['product_id'=>$product_id,'audit'=>$audit]);
        return $audit;
    }

    public function import_momentec_style(string $style,array $selected_colors=[]){
        $data=$this->momentec->style_product($style);if(is_wp_error($data))return $data;if(strtolower((string)($data['supplier'] ?? ''))!=='momentec')return new WP_Error('momentec_supplier','Cached product is not a Momentec product.');
        $brand=trim((string)($data['brand'] ?? ''));$style=trim((string)($data['style'] ?? $style));if($style==='')return new WP_Error('momentec_mapping','Cached Momentec product is missing its style number.');
        $variants=is_array($data['variants'] ?? null)?$data['variants']:[];if(!$variants)return new WP_Error('momentec_variants','No exact Momentec SKU rows were cached for this style.');
        $all_colors=$this->momentec_colors_from_variants($variants);$selected_colors=array_values(array_unique(array_filter(array_map('sanitize_text_field',$selected_colors))));if(!$selected_colors)return new WP_Error('no_colors','Choose at least one color before importing.');
        $lookup=array_fill_keys($selected_colors,true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));if(!$variants)return new WP_Error('no_variants','The selected colors contain no real Momentec variations.');
        $product_id=$this->find_momentec_product($style,$brand);if($product_id&&(string)get_post_meta($product_id,'_asss_supplier',true)==='multi')return $this->link_momentec_style_to_product($product_id,$style,$selected_colors);
        if(!$product_id){$other=$this->find_product($style,$brand);if(!$other)$other=$this->find_ss_product_by_brand_style($brand,$style);if($other)return new WP_Error('existing_other_supplier','Another supplier is already linked to a WooCommerce product that appears to match '.$brand.' '.$style.' (product #'.$other.'). Link Momentec to that product instead of creating a duplicate.');}
        $product=$product_id?wc_get_product($product_id):new WC_Product_Variable();if(!$product instanceof WC_Product_Variable)return new WP_Error('product','Could not initialize WooCommerce variable product.');$is_new=!$product_id;
        $title=trim((string)($data['title'] ?? $style));$description=trim((string)($data['description'] ?? ''));$categories=is_array($data['categories'] ?? null)?$data['categories']:[];$base=trim((string)($data['category'] ?? ''));if($base!=='')array_unshift($categories,$base);$categories=array_values(array_unique(array_filter(array_map('sanitize_text_field',$categories))));
        if($is_new){$product->set_name($title?:($brand.' '.$style));$product->set_status('draft');$product->set_catalog_visibility('visible');}elseif($product->get_name()==='')$product->set_name($title?:($brand.' '.$style));
        if(!empty($this->sanmar->settings()['sync_description']))$this->sync_supplier_description($product,$description,$is_new);$this->maybe_set_momentec_parent_sku($product,$brand,$style);
        $mode=count($selected_colors)>=count($all_colors)?'all':'selected';$product->update_meta_data('_asss_supplier','momentec');$product->update_meta_data('_asss_supplier_product_key','momentec|'.strtolower($brand).'|'.strtolower($style));$product->update_meta_data('_asss_momentec_brand',$brand);$product->update_meta_data('_asss_momentec_style',$style);$product->update_meta_data('_asss_momentec_specs',wp_json_encode((array)($data['specs'] ?? [])));$product->update_meta_data('_asss_sync_enabled','yes');$product->update_meta_data('_asss_color_selection_mode',$mode);$product->update_meta_data('_asss_selected_colors',wp_json_encode($selected_colors));$product_id=$product->save();
        $this->multi->register_product_source($product_id,'momentec',['brand'=>$brand,'style'=>$style,'selection_mode'=>$mode,'selected_colors'=>$selected_colors]);update_post_meta($product_id,'_asss_momentec_color_selection_mode',$mode);update_post_meta($product_id,'_asss_momentec_selected_colors',wp_json_encode($selected_colors));
        $this->sync_taxonomies($product_id,$brand,$categories,'',$is_new);$common=$this->ss_common_rows($variants,$data);$this->set_attributes($product,$common);$this->sync_parent_shipping($product,$common);$this->sync_momentec_bulk_order_fields($product,$data,$variants,$is_new);$product->save();
        $audit=$this->reconcile_momentec_variations($product_id,$variants,true);if(!empty($this->sanmar->settings()['sync_images']))$this->sync_momentec_parent_media($product_id,$data,$variants);$this->sync_managed_pricing_for_product($product_id);
        $product=wc_get_product($product_id);if($product instanceof WC_Product_Variable){$product->update_meta_data('_asss_last_product_sync',current_time('mysql'));$product->save();}WC_Product_Variable::sync($product_id);wc_delete_product_transients($product_id);do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'import']);ASSS_Logger::log('Imported/updated Momentec product','info',['product_id'=>$product_id,'brand'=>$brand,'style'=>$style,'selected_colors'=>count($selected_colors),'expected_variations'=>(int)($audit['expected'] ?? count($variants))]);return $product_id;
    }

    private function update_momentec_style(int $product_id){
        $style=trim((string)get_post_meta($product_id,'_asss_momentec_style',true));if($style==='')return new WP_Error('mapping','Product is missing its Momentec style mapping.');$data=$this->momentec->style_product($style);if(is_wp_error($data))return $data;$variants=is_array($data['variants'] ?? null)?$data['variants']:[];if(!$variants)return new WP_Error('momentec_variants','No exact Momentec SKU rows are cached for this style.');
        $mode=(string)get_post_meta($product_id,'_asss_momentec_color_selection_mode',true)?: (string)get_post_meta($product_id,'_asss_color_selection_mode',true);if($mode!==''&&$mode!=='all'){$sel=json_decode((string)get_post_meta($product_id,'_asss_momentec_selected_colors',true),true);if(is_array($sel)&&$sel){$lookup=array_fill_keys(array_map('strval',$sel),true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));}}
        if(!$variants)return new WP_Error('no_variants','No Momentec variations remain after the saved color selection.');$product=wc_get_product($product_id);if(!$product instanceof WC_Product_Variable)return new WP_Error('product','Product missing or is not variable.');$brand=trim((string)($data['brand'] ?? get_post_meta($product_id,'_asss_momentec_brand',true)));if(!empty($this->sanmar->settings()['sync_description']))$this->sync_supplier_description($product,(string)($data['description'] ?? ''),false);$this->maybe_set_momentec_parent_sku($product,$brand,$style);$product->update_meta_data('_asss_momentec_brand',$brand);$product->update_meta_data('_asss_momentec_specs',wp_json_encode((array)($data['specs'] ?? [])));$product->update_meta_data('_asss_last_product_sync',current_time('mysql'));
        $categories=is_array($data['categories'] ?? null)?$data['categories']:[];$base=trim((string)($data['category'] ?? ''));if($base!=='')array_unshift($categories,$base);$categories=array_values(array_unique(array_filter(array_map('sanitize_text_field',$categories))));$this->sync_taxonomies($product_id,$brand,$categories,'',false);$common=$this->ss_common_rows($variants,$data);$this->set_attributes($product,$common);$this->sync_parent_shipping($product,$common);$this->sync_momentec_bulk_order_fields($product,$data,$variants,false);$product->save();$this->reconcile_momentec_variations($product_id,$variants,!empty($this->sanmar->settings()['sync_new_variations']));if(!empty($this->sanmar->settings()['sync_images']))$this->sync_momentec_parent_media($product_id,$data,$variants);$this->sync_managed_pricing_for_product($product_id);WC_Product_Variable::sync($product_id);wc_delete_product_transients($product_id);do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'repair']);return $product_id;
    }

    public function link_momentec_style_to_product(int $product_id,string $style,array $selected_colors=[]){
        $product=wc_get_product($product_id);if(!$product instanceof WC_Product_Variable)return new WP_Error('product','The target WooCommerce product is missing or is not variable.');$data=$this->momentec->style_product($style);if(is_wp_error($data))return $data;$brand=trim((string)($data['brand'] ?? ''));$style=trim((string)($data['style'] ?? $style));$variants=is_array($data['variants'] ?? null)?$data['variants']:[];if(!$variants)return new WP_Error('momentec_variants','No Momentec variations are cached for this style.');
        $all=$this->momentec_colors_from_variants($variants);$selected_colors=array_values(array_unique(array_filter(array_map('sanitize_text_field',$selected_colors))));if(!$selected_colors)$selected_colors=$all;$lookup=array_fill_keys($selected_colors,true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));if(!$variants)return new WP_Error('no_variants','No Momentec variations remain for the selected colors.');
        $mode=count($selected_colors)>=count($all)?'all':'selected';update_post_meta($product_id,'_asss_momentec_brand',$brand);update_post_meta($product_id,'_asss_momentec_style',$style);update_post_meta($product_id,'_asss_momentec_specs',wp_json_encode((array)($data['specs'] ?? [])));update_post_meta($product_id,'_asss_momentec_color_selection_mode',$mode);update_post_meta($product_id,'_asss_momentec_selected_colors',wp_json_encode($selected_colors));update_post_meta($product_id,'_asss_sync_enabled','yes');$this->multi->register_product_source($product_id,'momentec',['brand'=>$brand,'style'=>$style,'selection_mode'=>$mode,'selected_colors'=>$selected_colors]);
        $categories=is_array($data['categories'] ?? null)?$data['categories']:[];$base=trim((string)($data['category'] ?? ''));if($base!=='')array_unshift($categories,$base);$this->add_supplier_categories($product_id,$categories,'momentec');$expected=[];$matched=0;$created=0;
        foreach($variants as $row){if(!is_array($row))continue;$color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));$key=(string)($row['unique_key'] ?? $row['sku'] ?? '');if($key==='')$key=$this->canonical_combo($color,$size);$expected[$key]=true;$supplier_id=(string)($row['unique_key'] ?? $row['sku'] ?? '');$vid=$this->find_momentec_variation($product_id,$supplier_id,$color,$size);if(!$vid)$vid=$this->find_variation_by_combo_any($product_id,$color,$size);if(!$vid)$vid=$this->find_variation_by_verified_size_alias($product_id,$brand,$style,$color,$size);
            if(!$vid){$r=$this->sync_momentec_variation($product_id,$row);$vid=(int)($r['variation_id'] ?? 0);if($vid)$created++;}else{$matched++;update_post_meta($vid,'_asss_momentec_sku_id',sanitize_text_field($supplier_id));update_post_meta($vid,'_asss_momentec_sku',sanitize_text_field((string)($row['sku'] ?? '')));update_post_meta($vid,'_asss_momentec_color',$color);update_post_meta($vid,'_asss_momentec_size',$size);update_post_meta($vid,'_asss_momentec_cost',(string)($row['customer_price'] ?? ''));update_post_meta($vid,'_asss_momentec_retail_price',(string)($row['retail_price'] ?? ''));$existing=wc_get_product($vid);if(!empty($this->sanmar->settings()['sync_images'])&&$existing instanceof WC_Product_Variation&&!$existing->get_image_id())$this->sync_momentec_variation_media($vid,$row);}
            if(!$vid)continue;$this->multi->register_variation_source($vid,'momentec',['sku'=>(string)($row['sku'] ?? ''),'sku_id'=>$supplier_id,'unique_key'=>$supplier_id,'color'=>$color,'size'=>$size,'cost'=>$row['customer_price'] ?? '','retail_price'=>$row['retail_price'] ?? '','inventory_qty'=>isset($row['qty'])&&is_numeric($row['qty'])?(int)$row['qty']:null,'availability'=>(string)($row['availability'] ?? ''),'availability_date'=>(string)($row['availability_date'] ?? ''),'gallery'=>(array)($row['gallery'] ?? [])]);$this->multi->recalculate_variation_inventory($vid);
        }
        $this->disable_missing_source_variations($product_id,'momentec',$expected);$this->rebuild_attributes_from_variations($product_id);$this->sync_managed_pricing_for_product($product_id);$this->multi->recalculate_product_inventory($product_id);update_post_meta($product_id,'_asss_last_product_sync',current_time('mysql'));do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'multi-link']);ASSS_Logger::log('Momentec linked as additional supplier','info',['product_id'=>$product_id,'matched_existing'=>$matched,'created_momentec_only'=>$created]);return $product_id;
    }

'''
text = text.replace(marker, addition + marker, 1)
path.write_text(text, encoding='utf-8')
print('Applied v2.0.6 Momentec WooCommerce importer and multi-supplier patches.')
