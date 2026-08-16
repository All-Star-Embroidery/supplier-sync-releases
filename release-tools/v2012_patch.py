#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v2012_patch.py <source-dir>')
root = Path(sys.argv[1])


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'v2.0.12 patch marker missing: {label}')
    return text.replace(old, new, 1)

main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = replace_once(text, 'Version: 2.0.11', 'Version: 2.0.12', 'plugin header version')
text = replace_once(text, "define('ASSS_VERSION', '2.0.11');", "define('ASSS_VERSION', '2.0.12');", 'ASSS_VERSION')
main.write_text(text, encoding='utf-8')

imp = root / 'includes/class-asss-importer.php'
text = imp.read_text(encoding='utf-8')

old_ctor = """        $this->momentec = $momentec;
        $this->multi = $multi;
    }
"""
new_ctor = """        $this->momentec = $momentec;
        $this->multi = $multi;
        add_action('asss_momentec_variation_media_job', [$this, 'momentec_variation_media_job'], 10, 2);
        add_action('asss_momentec_parent_media_job', [$this, 'momentec_parent_media_job'], 10, 1);
    }
"""
text = replace_once(text, old_ctor, new_ctor, 'Momentec background media hooks')

old_parent_media = """    private function sync_momentec_parent_media(int $product_id,array $data,array $variants): void {
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
"""
new_parent_media = """    private function momentec_parent_featured_url(array $data,array $variants): string {
        $candidates=[
            (string)($data['images']['product'] ?? ''),
            (string)($data['images']['thumbnail'] ?? ''),
        ];
        foreach($variants as $row){
            if(!is_array($row))continue;
            $candidates[]=(string)($row['primary_image'] ?? '');
            foreach((array)($row['gallery'] ?? []) as $raw)$candidates[]=(string)$raw;
            if(count($candidates)>=12)break;
        }
        foreach($candidates as $raw){$u=$this->momentec_media_url((string)$raw);if($u!=='')return $u;}
        return '';
    }

    /** Attach only one parent image during the interactive import request. */
    private function sync_momentec_parent_featured_image(int $product_id,array $data,array $variants): void {
        $product=wc_get_product($product_id);if(!$product)return;
        $current=(int)$product->get_image_id();
        if($current && !$this->is_supplier_attachment($current))return;
        $url=$this->momentec_parent_featured_url($data,$variants);if($url==='')return;
        $id=$this->sideload($url,$product_id,'momentec');if(!$id)return;
        $product->set_image_id($id);$product->save();
        update_post_meta($product_id,'_asss_momentec_featured_image_url',esc_url_raw($url));
    }

    private function sync_momentec_parent_media(int $product_id,array $data,array $variants): void {
        $product=wc_get_product($product_id);if(!$product)return;
        $urls=[];
        foreach([(string)($data['images']['product'] ?? ''),(string)($data['images']['thumbnail'] ?? '')] as $raw){$u=$this->momentec_media_url($raw);if($u!=='')$urls[$u]=true;}
        foreach($variants as $row){foreach((array)($row['gallery'] ?? []) as $raw){$u=$this->momentec_media_url((string)$raw);if($u!=='')$urls[$u]=true;}if(count($urls)>=8)break;}
        if(!$urls)return;$ids=[];
        foreach(array_slice(array_keys($urls),0,8) as $url){$id=$this->sideload($url,$product_id,'momentec');if($id)$ids[]=(int)$id;}
        $ids=array_values(array_unique($ids));if(!$ids)return;
        $current=(int)$product->get_image_id();if(!$current||$this->is_supplier_attachment($current))$product->set_image_id($ids[0]);
        $manual=[];foreach($product->get_gallery_image_ids() as $id)if((int)$id&&!$this->is_supplier_attachment((int)$id))$manual[]=(int)$id;
        $primary=(int)$product->get_image_id();$supplier_gallery=array_values(array_filter($ids,static fn($id)=>(int)$id!==$primary));
        $product->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_gallery,$manual))));$product->save();
    }

    private function momentec_row_for_variation(int $product_id,int $variation_id,array $variants): array {
        $v=wc_get_product($variation_id);if(!$v instanceof WC_Product_Variation||$v->get_parent_id()!==$product_id)return [];
        $want_ids=array_values(array_unique(array_filter([
            trim((string)$v->get_meta('_asss_momentec_sku_id')),
            trim((string)$v->get_meta('_asss_momentec_sku')),
        ])));
        $want_color=trim((string)$v->get_meta('_asss_momentec_color'));$want_size=trim((string)$v->get_meta('_asss_momentec_size'));
        foreach($variants as $row){
            if(!is_array($row))continue;
            $row_ids=array_values(array_unique(array_filter([
                trim((string)($row['unique_key'] ?? '')),
                trim((string)($row['supplier_sku_id'] ?? '')),
                trim((string)($row['sku'] ?? '')),
            ])));
            if($want_ids && array_intersect($want_ids,$row_ids))return $row;
            $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));
            if($want_color!==''&&$want_size!==''&&strcasecmp($want_color,$color)===0&&strcasecmp($want_size,$size)===0)return $row;
        }
        return [];
    }

    private function schedule_momentec_media_action(string $hook,array $args,int $delay=1): void {
        $delay=max(1,$delay);
        if(function_exists('as_schedule_single_action')){
            as_schedule_single_action(time()+$delay,$hook,$args,'all-star-supplier-sync-momentec-media',true);
            return;
        }
        if(!wp_next_scheduled($hook,$args))wp_schedule_single_event(time()+$delay,$hook,$args);
    }

    private function queue_momentec_media_jobs(int $product_id,array $variants): void {
        if(empty($this->sanmar->settings()['sync_images']))return;
        $queued=0;$delay=2;
        foreach($variants as $row){
            if(!is_array($row))continue;
            $gallery=array_values(array_filter((array)($row['gallery'] ?? [])));$primary=trim((string)($row['primary_image'] ?? ''));
            if(!$gallery&&$primary==='')continue;
            $supplier_id=trim((string)($row['unique_key'] ?? $row['supplier_sku_id'] ?? $row['sku'] ?? ''));
            $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));$size=trim((string)($row['size'] ?? ''));
            $variation_id=$this->find_momentec_variation($product_id,$supplier_id,$color,$size);if(!$variation_id)continue;
            update_post_meta($variation_id,'_asss_momentec_media_pending','yes');
            update_post_meta($variation_id,'_asss_variation_gallery_urls',array_values(array_unique(array_filter(array_map('esc_url_raw',$gallery)))));
            $this->schedule_momentec_media_action('asss_momentec_variation_media_job',[$product_id,$variation_id],$delay++);
            $queued++;
        }
        update_post_meta($product_id,'_asss_momentec_media_pending',$queued>0?'yes':'no');
        update_post_meta($product_id,'_asss_momentec_media_jobs_queued',$queued);
        update_post_meta($product_id,'_asss_momentec_media_queued_at',current_time('mysql'));
        $this->schedule_momentec_media_action('asss_momentec_parent_media_job',[$product_id],$delay+1);
        if(function_exists('spawn_cron'))spawn_cron();
        ASSS_Logger::log('Momentec media queued for background processing','info',['product_id'=>$product_id,'variation_jobs'=>$queued]);
    }

    public function momentec_variation_media_job(int $product_id,int $variation_id): void {
        $style=trim((string)get_post_meta($product_id,'_asss_momentec_style',true));if($style==='')return;
        $data=$this->momentec->style_product($style);if(is_wp_error($data))return;
        $variants=is_array($data['variants'] ?? null)?$data['variants']:[];$row=$this->momentec_row_for_variation($product_id,$variation_id,$variants);
        if(!$row){delete_post_meta($variation_id,'_asss_momentec_media_pending');ASSS_Logger::log('Momentec background media row not found','warning',['product_id'=>$product_id,'variation_id'=>$variation_id]);return;}
        $this->sync_momentec_variation_media($variation_id,$row);
        $v=wc_get_product($variation_id);
        if($v instanceof WC_Product_Variation && ($v->get_image_id() || empty($row['gallery']))){
            delete_post_meta($variation_id,'_asss_momentec_media_pending');
            delete_post_meta($variation_id,'_asss_momentec_media_attempts');
        }else{
            $attempts=absint(get_post_meta($variation_id,'_asss_momentec_media_attempts',true))+1;update_post_meta($variation_id,'_asss_momentec_media_attempts',$attempts);
            if($attempts<3)$this->schedule_momentec_media_action('asss_momentec_variation_media_job',[$product_id,$variation_id],300*$attempts);
            else ASSS_Logger::log('Momentec variation media exhausted retries','warning',['product_id'=>$product_id,'variation_id'=>$variation_id]);
        }
    }

    public function momentec_parent_media_job(int $product_id): void {
        $style=trim((string)get_post_meta($product_id,'_asss_momentec_style',true));if($style==='')return;
        $data=$this->momentec->style_product($style);if(is_wp_error($data))return;
        $variants=is_array($data['variants'] ?? null)?$data['variants']:[];
        $mode=(string)get_post_meta($product_id,'_asss_momentec_color_selection_mode',true);if($mode!==''&&$mode!=='all'){
            $selected=json_decode((string)get_post_meta($product_id,'_asss_momentec_selected_colors',true),true);
            if(is_array($selected)&&$selected){$lookup=array_fill_keys(array_map('strval',$selected),true);$variants=array_values(array_filter($variants,static function($row)use($lookup){$c=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));return $c!==''&&isset($lookup[$c]);}));}
        }
        $this->sync_momentec_parent_media($product_id,$data,$variants);
        $pending=0;foreach($this->variation_ids_direct($product_id) as $vid)if((string)get_post_meta($vid,'_asss_momentec_media_pending',true)==='yes')$pending++;
        update_post_meta($product_id,'_asss_momentec_media_jobs_pending',$pending);
        if($pending===0){update_post_meta($product_id,'_asss_momentec_media_pending','no');update_post_meta($product_id,'_asss_momentec_media_completed_at',current_time('mysql'));}
    }
"""
text = replace_once(text, old_parent_media, new_parent_media, 'Momentec parent/background media architecture')

old_inline = """        if(!empty($this->sanmar->settings()['sync_images']))$this->sync_momentec_variation_media($variation_id,$row);
"""
new_inline = """        if(!empty($this->sanmar->settings()['sync_images'])&&(!empty($row['gallery'])||!empty($row['primary_image']))){
            update_post_meta($variation_id,'_asss_momentec_media_pending','yes');
            update_post_meta($variation_id,'_asss_variation_gallery_urls',array_values(array_unique(array_filter(array_map('esc_url_raw',(array)($row['gallery'] ?? []))))));
        }
"""
text = replace_once(text, old_inline, new_inline, 'remove synchronous Momentec variation downloads')

old_audit = """if(!empty($this->sanmar->settings()['sync_images'])&&!empty($row['gallery'])){$saved=$v->get_meta('_asss_variation_gallery_ids');if(is_string($saved)){$d=json_decode($saved,true);if(is_array($d))$saved=$d;}if(!$v->get_image_id())$missing_image++;if(count((array)$row['gallery'])>1&&count((array)$saved)<2)$missing_gallery++;}"""
new_audit = """if(!empty($this->sanmar->settings()['sync_images'])&&!empty($row['gallery'])&&(string)$v->get_meta('_asss_momentec_media_pending')!=='yes'){$saved=$v->get_meta('_asss_variation_gallery_ids');if(is_string($saved)){$d=json_decode($saved,true);if(is_array($d))$saved=$d;}if(!$v->get_image_id())$missing_image++;if(count((array)$row['gallery'])>1&&count((array)$saved)<2)$missing_gallery++;}"""
text = replace_once(text, old_audit, new_audit, 'pending-media-aware Momentec audit')

old_import_media = """        $audit=$this->reconcile_momentec_variations($product_id,$variants,true);if(!empty($this->sanmar->settings()['sync_images']))$this->sync_momentec_parent_media($product_id,$data,$variants);$this->sync_managed_pricing_for_product($product_id);
"""
new_import_media = """        $audit=$this->reconcile_momentec_variations($product_id,$variants,true);if(!empty($this->sanmar->settings()['sync_images'])){$this->sync_momentec_parent_featured_image($product_id,$data,$variants);$this->queue_momentec_media_jobs($product_id,$variants);}$this->sync_managed_pricing_for_product($product_id);
"""
text = replace_once(text, old_import_media, new_import_media, 'fast Momentec initial import media')

old_update_media = """$product->save();$this->reconcile_momentec_variations($product_id,$variants,!empty($this->sanmar->settings()['sync_new_variations']));if(!empty($this->sanmar->settings()['sync_images']))$this->sync_momentec_parent_media($product_id,$data,$variants);$this->sync_managed_pricing_for_product($product_id);WC_Product_Variable::sync($product_id);"""
new_update_media = """$product->save();$this->reconcile_momentec_variations($product_id,$variants,!empty($this->sanmar->settings()['sync_new_variations']));if(!empty($this->sanmar->settings()['sync_images'])){$this->sync_momentec_parent_featured_image($product_id,$data,$variants);$this->queue_momentec_media_jobs($product_id,$variants);}$this->sync_managed_pricing_for_product($product_id);WC_Product_Variable::sync($product_id);"""
text = replace_once(text, old_update_media, new_update_media, 'fast Momentec repair media')

old_link_inline = """$existing=wc_get_product($vid);if(!empty($this->sanmar->settings()['sync_images'])&&$existing instanceof WC_Product_Variation&&!$existing->get_image_id())$this->sync_momentec_variation_media($vid,$row);}"""
new_link_inline = """$existing=wc_get_product($vid);if(!empty($this->sanmar->settings()['sync_images'])&&$existing instanceof WC_Product_Variation&&(!empty($row['gallery'])||!empty($row['primary_image'])))update_post_meta($vid,'_asss_momentec_media_pending','yes');}"""
text = replace_once(text, old_link_inline, new_link_inline, 'remove synchronous Momentec link media')

old_link_finish = """        $this->disable_missing_source_variations($product_id,'momentec',$expected);$this->rebuild_attributes_from_variations($product_id);$this->sync_managed_pricing_for_product($product_id);$this->multi->recalculate_product_inventory($product_id);update_post_meta($product_id,'_asss_last_product_sync',current_time('mysql'));do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'multi-link']);"""
new_link_finish = """        $this->disable_missing_source_variations($product_id,'momentec',$expected);$this->rebuild_attributes_from_variations($product_id);if(!empty($this->sanmar->settings()['sync_images'])){$this->sync_momentec_parent_featured_image($product_id,$data,$variants);$this->queue_momentec_media_jobs($product_id,$variants);}$this->sync_managed_pricing_for_product($product_id);$this->multi->recalculate_product_inventory($product_id);update_post_meta($product_id,'_asss_last_product_sync',current_time('mysql'));do_action('asss_product_synced',$product_id,'momentec',['brand'=>$brand,'style'=>$style,'mode'=>'multi-link']);"""
text = replace_once(text, old_link_finish, new_link_finish, 'Momentec multi-link background media')

imp.write_text(text, encoding='utf-8')

admin = root / 'includes/class-asss-admin.php'
text = admin.read_text(encoding='utf-8')
old_notice = """        echo '<div class=\"notice notice-success is-dismissible\"><p><strong>Supplier import complete.</strong> Product structure, attributes, exact variations, taxonomy data, supplier images, supplier metadata, and Supplier Sync-managed pricing were created. Review the result before publishing; manual price edits will be preserved on future syncs.</p></div>';
"""
new_notice = """        $supplier=(string)get_post_meta($post_id,'_asss_supplier',true);
        $momentec_pending=$supplier==='momentec'&&(string)get_post_meta($post_id,'_asss_momentec_media_pending',true)==='yes';
        if($momentec_pending){
            echo '<div class=\"notice notice-success is-dismissible\"><p><strong>Momentec product structure imported.</strong> All selected exact variations, inventory, pricing, attributes and supplier metadata are ready. The featured product image is attached immediately when available; variation/gallery images are continuing in the background so the import does not time out.</p></div>';
        }else{
            echo '<div class=\"notice notice-success is-dismissible\"><p><strong>Supplier import complete.</strong> Product structure, attributes, exact variations, taxonomy data, supplier images, supplier metadata, and Supplier Sync-managed pricing were created. Review the result before publishing; manual price edits will be preserved on future syncs.</p></div>';
        }
"""
text = replace_once(text, old_notice, new_notice, 'Momentec background-media import notice')
admin.write_text(text, encoding='utf-8')
