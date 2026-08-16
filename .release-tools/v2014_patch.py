#!/usr/bin/env python3
from pathlib import Path
import re
import sys

root = Path(sys.argv[1] if len(sys.argv) > 1 else 'source')
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = text.replace('Version: 2.0.13', 'Version: 2.0.14', 1)
text = text.replace("define('ASSS_VERSION', '2.0.13');", "define('ASSS_VERSION', '2.0.14');", 1)
main.write_text(text, encoding='utf-8')

imp = root / 'includes/class-asss-importer.php'
text = imp.read_text(encoding='utf-8')
pattern = re.compile(
    r"    private function sync_ss_parent_media\(int \$product_id, array \$data, array \$variants\): void \{.*?\n    \}\n\n    private function sync_ss_bulk_order_fields",
    re.S,
)
replacement = r'''    private function ss_featured_color_rank(string $color,int $index): int {
        $normalized=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i',' ',$color)));
        $tokens=array_values(array_filter(preg_split('/\s+/', $normalized) ?: []));
        $rank=10000+$index;
        $priority=['black'=>0,'navy'=>100,'charcoal'=>200,'graphite'=>250,'gray'=>300,'grey'=>300,'white'=>400];
        foreach($priority as $token=>$score){
            if(in_array($token,$tokens,true))return $score+$index;
        }
        return $rank;
    }

    /** Return a known clean, non-model S&S product image for one exact variant. */
    private function ss_clean_variant_image_url(array $row,bool $front_only=false): string {
        $images=is_array($row['images'] ?? null)?$row['images']:[];
        $front=$this->normalize_ss_media_url((string)($images['front'] ?? ''));
        if($front!=='')return $front;
        if($front_only)return '';

        // The GitHub S&S normalizer preserves media type beside each gallery URL.
        // Only catalog/product angles may become a parent featured image. Model
        // and lifestyle photography stays available in galleries but is excluded here.
        $gallery=is_array($row['gallery'] ?? null)?array_values($row['gallery']):[];
        $types=is_array($row['gallery_types'] ?? null)?array_values($row['gallery_types']):[];
        $allowed=['front','style','directside','side','back'];
        foreach($gallery as $i=>$raw){
            $kind=strtolower((string)preg_replace('/[^a-z0-9]+/i','',(string)($types[$i] ?? '')));
            if(!in_array($kind,$allowed,true))continue;
            $url=$this->normalize_ss_media_url((string)$raw);
            if($url!=='')return $url;
        }

        // Explicit named non-model angles remain safe fallbacks for older caches.
        foreach(['direct_side','side','back'] as $key){
            $url=$this->normalize_ss_media_url((string)($images[$key] ?? ''));
            if($url!=='')return $url;
        }
        return '';
    }

    /** Pick the S&S image customers see first in shop/category/search results. */
    private function ss_parent_featured_url(array $data,array $variants): string {
        $ranked=[];
        foreach(array_values($variants) as $index=>$row){
            if(!is_array($row))continue;
            $color=trim((string)($row['color'] ?? $row['catalog_color'] ?? ''));
            $ranked[]=['rank'=>$this->ss_featured_color_rank($color,(int)$index),'index'=>(int)$index,'row'=>$row];
        }
        usort($ranked,static function($a,$b){
            $cmp=((int)$a['rank'])<=>((int)$b['rank']);
            return $cmp!==0?$cmp:(((int)$a['index'])<=>((int)$b['index']));
        });

        // 1) Clean FRONT shot in a familiar neutral storefront color.
        foreach($ranked as $entry){
            if((int)$entry['rank']>=10000)continue;
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],true);
            if($url!=='')return $url;
        }
        // 2) Any clean FRONT shot from the selected supplier colors.
        foreach($ranked as $entry){
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],true);
            if($url!=='')return $url;
        }
        // 3) S&S style-level thumbnail.
        $thumbnail=$this->normalize_ss_media_url((string)($data['images']['thumbnail'] ?? ''));
        if($thumbnail!=='')return $thumbnail;
        // 4) A clean non-model product angle in a preferred neutral color.
        foreach($ranked as $entry){
            if((int)$entry['rank']>=10000)continue;
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],false);
            if($url!=='')return $url;
        }
        // 5) Any clean non-model product angle.
        foreach($ranked as $entry){
            $url=$this->ss_clean_variant_image_url((array)$entry['row'],false);
            if($url!=='')return $url;
        }
        // 6) Generic style/product image only after clean product photography.
        $product=$this->normalize_ss_media_url((string)($data['images']['product'] ?? ''));
        if($product!=='')return $product;
        // 7) No on-model/lifestyle fallback. Leave it for the merchant to choose.
        return '';
    }

    private function sync_ss_parent_media(int $product_id, array $data, array $variants): void {
        $product = wc_get_product($product_id);
        if (!$product) return;

        $current=(int)$product->get_image_id();
        $manual_featured=$current && !$this->is_supplier_attachment($current);
        $featured_id=$current;
        $featured_url='';
        if(!$manual_featured){
            $featured_url=$this->ss_parent_featured_url($data,$variants);
            if($featured_url!==''){
                $candidate=$this->sideload($featured_url,$product_id,'ss');
                if($candidate){$featured_id=(int)$candidate;$product->set_image_id($featured_id);}
            }
        }

        // Build a useful parent gallery independently from the featured-image rule.
        // Lifestyle/model media may appear here, but can never become featured.
        $urls=[];
        foreach([(string)($data['images']['thumbnail'] ?? ''),(string)($data['images']['product'] ?? '')] as $raw){
            $url=$this->normalize_ss_media_url($raw);if($url!=='')$urls[$url]=true;
        }
        foreach($variants as $row){
            if(!is_array($row))continue;
            $clean=$this->ss_clean_variant_image_url($row,false);if($clean!=='')$urls[$clean]=true;
            foreach((array)($row['gallery'] ?? []) as $raw){
                $url=$this->normalize_ss_media_url((string)$raw);if($url!=='')$urls[$url]=true;
            }
            if(count($urls)>=12)break;
        }
        $ids=[];
        foreach(array_slice(array_keys($urls),0,12) as $url){
            if($featured_url!=='' && hash_equals($featured_url,$url) && $featured_id){$ids[]=$featured_id;continue;}
            $id=$this->sideload($url,$product_id,'ss');if($id)$ids[]=(int)$id;
        }
        $ids=array_values(array_unique(array_filter($ids)));
        $manual_gallery=[];
        foreach($product->get_gallery_image_ids() as $id)if((int)$id&&!$this->is_supplier_attachment((int)$id))$manual_gallery[]=(int)$id;
        $primary=(int)$product->get_image_id();
        $supplier_gallery=array_values(array_filter($ids,static fn($id)=>(int)$id!==$primary));
        $product->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_gallery,$manual_gallery))));
        $product->save();
        if(!$manual_featured && $featured_url!=='')update_post_meta($product_id,'_asss_ss_featured_image_url',esc_url_raw($featured_url));
    }

    private function sync_ss_bulk_order_fields'''
text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    raise SystemExit('Could not patch S&S parent media block')
imp.write_text(text, encoding='utf-8')
