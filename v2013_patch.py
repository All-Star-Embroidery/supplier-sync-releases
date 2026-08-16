#!/usr/bin/env python3
from pathlib import Path
import re
import sys

root = Path(sys.argv[1]) if len(sys.argv) > 1 else Path('.')
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
if 'Version: 2.0.12' not in text or "define('ASSS_VERSION', '2.0.12');" not in text:
    raise SystemExit('Expected v2.0.12 source')
text = text.replace('Version: 2.0.12', 'Version: 2.0.13', 1)
text = text.replace("define('ASSS_VERSION', '2.0.12');", "define('ASSS_VERSION', '2.0.13');", 1)
main.write_text(text, encoding='utf-8')

imp = root / 'includes/class-asss-importer.php'
text = imp.read_text(encoding='utf-8')

pattern = re.compile(r"    private function momentec_parent_featured_url\(array \$data,array \$variants\): string \{.*?\n    \}\n\n    /\*\* Attach only one parent image", re.S)
replacement = '''    private function momentec_parent_featured_url(array $data,array $variants): string {
        // Momentec parent featured-image priority:
        // 1. Product-level images.thumbnail.
        // 2. First selected variation primary_image.
        // 3. Product-level images.product.
        // 4. No gallery fallback; leave it for manual selection.
        $thumbnail=$this->momentec_media_url((string)($data['images']['thumbnail'] ?? ''));
        if($thumbnail!=='')return $thumbnail;
        foreach($variants as $row){
            if(!is_array($row))continue;
            $primary=$this->momentec_media_url((string)($row['primary_image'] ?? ''));
            if($primary!=='')return $primary;
        }
        $product=$this->momentec_media_url((string)($data['images']['product'] ?? ''));
        if($product!=='')return $product;
        return '';
    }

    /** Attach only one parent image'''
text, n = pattern.subn(replacement, text, count=1)
if n != 1:
    raise SystemExit('Could not replace featured-image selector')

pattern = re.compile(r"    private function sync_momentec_parent_media\(int \$product_id,array \$data,array \$variants\): void \{.*?\n    \}\n\n    private function momentec_row_for_variation", re.S)
replacement = '''    private function sync_momentec_parent_media(int $product_id,array $data,array $variants): void {
        $product=wc_get_product($product_id);if(!$product)return;
        $urls=[];
        foreach([(string)($data['images']['thumbnail'] ?? ''),(string)($data['images']['product'] ?? '')] as $raw){$u=$this->momentec_media_url($raw);if($u!=='')$urls[$u]=true;}
        foreach($variants as $row){
            if(!is_array($row))continue;
            $p=$this->momentec_media_url((string)($row['primary_image'] ?? ''));if($p!=='')$urls[$p]=true;
            foreach((array)($row['gallery'] ?? []) as $raw){$u=$this->momentec_media_url((string)$raw);if($u!=='')$urls[$u]=true;}
            if(count($urls)>=8)break;
        }
        if(!$urls)return;$ids=[];
        foreach(array_slice(array_keys($urls),0,8) as $url){$id=$this->sideload($url,$product_id,'momentec');if($id)$ids[]=(int)$id;}
        $ids=array_values(array_unique($ids));if(!$ids)return;
        // Never promote a background gallery image to featured image. The
        // featured image has already been chosen by the explicit priority rule,
        // or intentionally left blank for the merchant to choose manually.
        $manual=[];foreach($product->get_gallery_image_ids() as $id)if((int)$id&&!$this->is_supplier_attachment((int)$id))$manual[]=(int)$id;
        $primary=(int)$product->get_image_id();$supplier_gallery=array_values(array_filter($ids,static fn($id)=>(int)$id!==$primary));
        $product->set_gallery_image_ids(array_values(array_unique(array_merge($supplier_gallery,$manual))));$product->save();
    }

    private function momentec_row_for_variation'''
text, n = pattern.subn(replacement, text, count=1)
if n != 1:
    raise SystemExit('Could not replace parent gallery sync')

imp.write_text(text, encoding='utf-8')
