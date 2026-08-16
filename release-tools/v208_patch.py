#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: v208_patch.py <source-dir>')
root = Path(sys.argv[1])


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'v2.0.8 patch marker missing: {label}')
    return text.replace(old, new, 1)

# Version bump.
main = root / 'all-star-supplier-sync.php'
text = main.read_text(encoding='utf-8')
text = replace_once(text, 'Version: 2.0.7', 'Version: 2.0.8', 'plugin header version')
text = replace_once(text, "define('ASSS_VERSION', '2.0.7');", "define('ASSS_VERSION', '2.0.8');", 'ASSS_VERSION')
main.write_text(text, encoding='utf-8')

admin = root / 'includes/class-asss-admin.php'
text = admin.read_text(encoding='utf-8')

# Add single-row queue action before the existing bulk queue handler.
marker = "        if (!empty($_POST['asss_momentec_queue_styles'])) {\n"
addition = r'''        if (!empty($_POST['asss_momentec_queue_style'])) {
            check_admin_referer('asss_momentec_catalog');
            $style=sanitize_text_field(wp_unslash((string)$_POST['asss_momentec_queue_style']));
            if($style===''){
                wp_safe_redirect(add_query_arg(['page'=>'asss-suppliers','supplier'=>'momentec','asss_err'=>'Invalid Momentec style.'],admin_url('admin.php')));exit;
            }
            $result=$this->momentec->queue_style_requests([$style],get_current_user_id(),true);
            $args=[
                'page'=>'asss-suppliers',
                'supplier'=>'momentec',
                'q'=>sanitize_text_field(wp_unslash((string)($_POST['return_q'] ?? ''))),
                'brand'=>sanitize_text_field(wp_unslash((string)($_POST['return_brand'] ?? ''))),
                'category'=>sanitize_text_field(wp_unslash((string)($_POST['return_category'] ?? ''))),
                'catalog_page'=>max(1,absint($_POST['return_catalog_page'] ?? 1)),
            ];
            if(!empty($result['queued'])) $args['asss_msg']='Preparing Momentec style '.$style.' for import. GitHub will fetch customer-specific pricing, exact variations, inventory, and galleries automatically.';
            else $args['asss_err']='Momentec style '.$style.' could not be queued for import.';
            wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
        }

'''
text = replace_once(text, marker, addition + marker, 'single-row Momentec queue action')

# Preserve filters in the existing Momentec catalog form.
old_form = "            echo '<form method=\"post\">';wp_nonce_field('asss_momentec_catalog');\n"
new_form = "            echo '<form method=\"post\">';wp_nonce_field('asss_momentec_catalog');\n            echo '<input type=\"hidden\" name=\"return_q\" value=\"'.esc_attr($search).'\"><input type=\"hidden\" name=\"return_brand\" value=\"'.esc_attr($brand).'\"><input type=\"hidden\" name=\"return_category\" value=\"'.esc_attr($category).'\"><input type=\"hidden\" name=\"return_catalog_page\" value=\"'.(int)$catalog['page'].'\">';\n"
text = replace_once(text, old_form, new_form, 'Momentec catalog return filters')

# Make bulk control secondary and clearly optional.
text = text.replace(
    '<button class="button button-primary" name="asss_momentec_queue_styles" value="1">Fetch / Refresh Customer Details</button> <span class="description">The GitHub worker checks the queue every five minutes.</span>',
    '<button class="button" name="asss_momentec_queue_styles" value="1">Prepare Selected for Import</button> <span class="description">Optional bulk action. Each row also has its own Import button.</span>'
)
text = text.replace(
    '<button class="button button-primary" name="asss_momentec_queue_styles" value="1">Fetch / Refresh Customer Details</button>',
    '<button class="button" name="asss_momentec_queue_styles" value="1">Prepare Selected for Import</button>'
)

# Rewrite explanatory copy around row actions.
text = text.replace(
    'Select one or more styles and click <strong>Fetch / Refresh Customer Details</strong>. GitHub will securely hydrate them with your Momentec account pricing, exact Color+Size SKUs, inventory, and galleries; they will then become ready for Review &amp; Import.',
    'Every style now has its own <strong>Import</strong> action. If customer-specific details are not cached yet, clicking Import automatically queues that one style for secure GitHub hydration; once ready, Import opens the normal review/color-selection screen. You can still use the checkboxes for optional bulk preparation.'
)

old_action = r'''                if($hydrated){$review=add_query_arg(['page'=>'asss-momentec-review','style'=>$style],admin_url('admin.php'));echo '<a class="button button-primary" href="'.esc_url($review).'">Review &amp; Import</a>';}
                elseif(in_array($request_status,['pending','processing'],true))echo '<span class="description">Automatic GitHub hydration queued</span>';
                else echo '<span class="description">Select this row above</span>';
'''
new_action = r'''                if($hydrated){
                    $review=add_query_arg(['page'=>'asss-momentec-review','style'=>$style],admin_url('admin.php'));
                    echo '<a class="button button-primary" href="'.esc_url($review).'">Import</a>';
                } elseif(in_array($request_status,['pending','processing'],true)) {
                    echo '<button class="button" type="button" disabled>Import queued</button>';
                } elseif($request_status==='failed') {
                    echo '<button class="button button-primary" name="asss_momentec_queue_style" value="'.esc_attr($style).'">Retry Import</button>';
                } else {
                    echo '<button class="button button-primary" name="asss_momentec_queue_style" value="'.esc_attr($style).'">Import</button>';
                }
'''
text = replace_once(text, old_action, new_action, 'Momentec per-row action UI')

admin.write_text(text, encoding='utf-8')
