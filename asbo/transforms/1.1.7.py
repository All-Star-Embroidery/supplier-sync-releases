#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: 1.1.7.py <plugin-folder>')

root = Path(sys.argv[1])
main = root / 'all-star-bulk-order-block.php'
block = root / 'block' / 'block.json'
readme = root / 'README.txt'

s = main.read_text()

replacements = [
    (' * Version: 1.1.6', ' * Version: 1.1.7'),
    ("    private const VERSION = '1.1.6';", "    private const VERSION = '1.1.7';"),
]
for old, new in replacements:
    if s.count(old) != 1:
        raise SystemExit(f'expected exactly one occurrence of: {old!r}; found {s.count(old)}')
    s = s.replace(old, new, 1)

old_markup = '''        <article class="asbo__product" data-product data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-product-name="<?php echo esc_attr( $display_name ); ?>" data-subcategories="<?php echo esc_attr( implode( ' ', array_unique( $product_filter_slugs ) ) ); ?>">
            <button type="button" class="asbo__product-trigger" aria-expanded="false">
                <span class="asbo__product-thumb"><?php echo wp_kses_post( $thumb ); ?></span>
                <span class="asbo__product-title-wrap">
                    <strong><?php echo esc_html( $display_name ); ?></strong>
                    <small class="asbo__product-meta">
                        <span data-product-quantity-label><?php esc_html_e( 'No pieces selected', 'all-star-bulk-order' ); ?></span>
                        <?php if ( null !== $starting_price ) : ?>
                            <span class="asbo__product-meta-separator" aria-hidden="true">•</span>
                            <span class="asbo__starting-price"><?php echo wp_kses_post( sprintf( __( 'Starting at %s', 'all-star-bulk-order' ), wc_price( $starting_price ) ) ); ?></span>
                        <?php endif; ?>
                    </small>
                </span>
                <span class="asbo__product-total-wrap">
                    <small><?php echo esc_html( $atts['product_total_label'] ); ?></small>
                    <strong class="asbo__product-subtotal" data-product-subtotal>$0.00</strong>
                </span>
                <span class="asbo__product-icon" aria-hidden="true">+</span>
            </button>

            <div class="asbo__product-panel" hidden>
                <script type="application/json" class="asbo__pricing-data"><?php echo wp_json_encode( $matrix ); ?></script>
                <div class="asbo__product-quick-actions">
                    <button type="button" class="asbo__details-button" data-product-details-open aria-haspopup="dialog" aria-controls="asbo-product-details-<?php echo esc_attr( $product->get_id() ); ?>">
                        <span><?php esc_html_e( 'Product details & size information', 'all-star-bulk-order' ); ?></span>
                        <span aria-hidden="true">↗</span>
                    </button>
                </div>

                <div class="asbo__pricing-section">'''

new_markup = '''        <article class="asbo__product" data-product data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-product-name="<?php echo esc_attr( $display_name ); ?>" data-subcategories="<?php echo esc_attr( implode( ' ', array_unique( $product_filter_slugs ) ) ); ?>">
            <div class="asbo__product-summary">
                <button
                    type="button"
                    class="asbo__product-trigger"
                    aria-expanded="false"
                    aria-label="<?php echo esc_attr( sprintf( __( 'Configure %s', 'all-star-bulk-order' ), $display_name ) ); ?>"
                ></button>
                <span class="asbo__product-thumb"><?php echo wp_kses_post( $thumb ); ?></span>
                <span class="asbo__product-title-wrap">
                    <span class="asbo__product-title-line">
                        <strong><?php echo esc_html( $display_name ); ?></strong>
                        <button
                            type="button"
                            class="asbo__details-chip"
                            data-product-details-open
                            aria-haspopup="dialog"
                            aria-controls="asbo-product-details-<?php echo esc_attr( $product->get_id() ); ?>"
                            aria-label="<?php echo esc_attr( sprintf( __( 'View details and sizing for %s', 'all-star-bulk-order' ), $display_name ) ); ?>"
                        ><?php esc_html_e( 'Details & sizing', 'all-star-bulk-order' ); ?></button>
                    </span>
                    <small class="asbo__product-meta">
                        <span data-product-quantity-label><?php esc_html_e( 'No pieces selected', 'all-star-bulk-order' ); ?></span>
                        <?php if ( null !== $starting_price ) : ?>
                            <span class="asbo__product-meta-separator" aria-hidden="true">•</span>
                            <span class="asbo__starting-price"><?php echo wp_kses_post( sprintf( __( 'Starting at %s', 'all-star-bulk-order' ), wc_price( $starting_price ) ) ); ?></span>
                        <?php endif; ?>
                    </small>
                </span>
                <span class="asbo__product-total-wrap">
                    <small><?php echo esc_html( $atts['product_total_label'] ); ?></small>
                    <strong class="asbo__product-subtotal" data-product-subtotal>$0.00</strong>
                </span>
                <span class="asbo__product-icon" aria-hidden="true">+</span>
            </div>

            <div class="asbo__product-panel" hidden>
                <script type="application/json" class="asbo__pricing-data"><?php echo wp_json_encode( $matrix ); ?></script>

                <div class="asbo__pricing-section">'''

if s.count(old_markup) != 1:
    raise SystemExit(f'product row markup changed unexpectedly; expected one exact match, found {s.count(old_markup)}')
s = s.replace(old_markup, new_markup, 1)

old_filter = '''            if (openTrigger) {
              openTrigger.setAttribute('aria-expanded', 'false');
              const icon = openTrigger.querySelector('.asbo__product-icon');
              if (icon) icon.textContent = '+';
              productEl.classList.remove('is-open');
              animatePanel(openTrigger.nextElementSibling, false);
            }'''
new_filter = '''            if (openTrigger) {
              openTrigger.setAttribute('aria-expanded', 'false');
              const icon = productEl.querySelector('.asbo__product-icon');
              if (icon) icon.textContent = '+';
              productEl.classList.remove('is-open');
              animatePanel(productEl.querySelector('.asbo__product-panel'), false);
            }'''
if s.count(old_filter) != 1:
    raise SystemExit(f'subcategory-close logic changed unexpectedly; found {s.count(old_filter)} matches')
s = s.replace(old_filter, new_filter, 1)

old_accordion = '''      const trigger = event.target.closest('.asbo__product-trigger');
      if (trigger) {
        const panel = trigger.nextElementSibling;
        const expanded = trigger.getAttribute('aria-expanded') === 'true';
        const opening = !expanded;

        // Keep a single product expanded at a time on every viewport. The customer
        // keeps the current pricing task in context without accumulating long open
        // sections above and below the product they are actively configuring.
        if (opening) {
          root.querySelectorAll('.asbo__product-trigger[aria-expanded="true"]').forEach((otherTrigger) => {
            if (otherTrigger === trigger) return;
            const otherPanel = otherTrigger.nextElementSibling;
            otherTrigger.setAttribute('aria-expanded', 'false');
            const otherIcon = otherTrigger.querySelector('.asbo__product-icon');
            if (otherIcon) otherIcon.textContent = '+';
            otherTrigger.closest('[data-product]')?.classList.remove('is-open');
            animatePanel(otherPanel, false);
          });
        }

        trigger.setAttribute('aria-expanded', String(opening));
        trigger.querySelector('.asbo__product-icon').textContent = opening ? '−' : '+';
        trigger.closest('[data-product]')?.classList.toggle('is-open', opening);
        animatePanel(panel, opening);
        return;
      }'''

new_accordion = '''      const trigger = event.target.closest('.asbo__product-trigger');
      if (trigger) {
        const productEl = trigger.closest('[data-product]');
        const panel = productEl?.querySelector('.asbo__product-panel');
        if (!productEl || !panel) return;

        const expanded = trigger.getAttribute('aria-expanded') === 'true';
        const opening = !expanded;

        // Keep a single product expanded at a time on every viewport. The customer
        // keeps the current pricing task in context without accumulating long open
        // sections above and below the product they are actively configuring.
        if (opening) {
          root.querySelectorAll('.asbo__product-trigger[aria-expanded="true"]').forEach((otherTrigger) => {
            if (otherTrigger === trigger) return;
            const otherProduct = otherTrigger.closest('[data-product]');
            const otherPanel = otherProduct?.querySelector('.asbo__product-panel');
            otherTrigger.setAttribute('aria-expanded', 'false');
            const otherIcon = otherProduct?.querySelector('.asbo__product-icon');
            if (otherIcon) otherIcon.textContent = '+';
            otherProduct?.classList.remove('is-open');
            animatePanel(otherPanel, false);
          });
        }

        trigger.setAttribute('aria-expanded', String(opening));
        const icon = productEl.querySelector('.asbo__product-icon');
        if (icon) icon.textContent = opening ? '−' : '+';
        productEl.classList.toggle('is-open', opening);
        animatePanel(panel, opening);
        return;
      }'''
if s.count(old_accordion) != 1:
    raise SystemExit(f'accordion logic changed unexpectedly; found {s.count(old_accordion)} matches')
s = s.replace(old_accordion, new_accordion, 1)

css = r'''

/* v1.1.7 — directly accessible, low-contrast product details chip. */
.asbo__product-summary {
  position: relative;
  display: grid;
  grid-template-columns: 64px minmax(0, 1fr) auto 38px;
  gap: 13px;
  align-items: center;
  width: 100%;
  padding: 10px 0;
  background: #fff;
}

/* The full product row remains one large, keyboard-accessible accordion target.
   Visual content is rendered as siblings so the Details & sizing control can be
   a separate native button instead of an invalid button-inside-button. */
.asbo__product-summary .asbo__product-trigger {
  position: absolute;
  inset: 0;
  z-index: 1;
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
  border-radius: 0;
  padding: 0;
  background: transparent;
  cursor: pointer;
}

.asbo__product-summary .asbo__product-trigger:focus-visible {
  outline: 2px solid var(--asbo-gold-dark);
  outline-offset: -2px;
}

.asbo__product-summary .asbo__product-thumb,
.asbo__product-summary .asbo__product-title-wrap,
.asbo__product-summary .asbo__product-total-wrap,
.asbo__product-summary .asbo__product-icon {
  position: relative;
  z-index: 2;
  pointer-events: none;
}

.asbo__product-title-line {
  display: flex;
  flex-wrap: wrap;
  gap: 5px 9px;
  align-items: center;
  min-width: 0;
}

.asbo__product-title-line > strong {
  min-width: 0;
  max-width: 100%;
}

.asbo__details-chip {
  position: relative;
  z-index: 3;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: max-content;
  max-width: 100%;
  min-height: 28px;
  padding: 4px 10px;
  border: 1px solid #cfd6e2;
  border-radius: 999px;
  background: #f7f8fa;
  color: #263653;
  box-shadow: 0 1px 2px rgba(17, 25, 45, 0.04);
  font: inherit;
  font-size: clamp(11.5px, 0.16vw + 11px, 12.5px);
  font-weight: 750;
  line-height: 1.2;
  white-space: nowrap;
  pointer-events: auto;
  cursor: pointer;
  transition: background-color 0.16s ease, border-color 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
}

.asbo__details-chip:hover {
  border-color: #aeb9ca;
  background: #f1f3f6;
  color: var(--asbo-navy);
}

.asbo__details-chip:focus-visible {
  border-color: var(--asbo-gold-dark);
  outline: 2px solid color-mix(in srgb, var(--asbo-gold) 72%, #fff);
  outline-offset: 2px;
  background: #fffaf0;
  color: var(--asbo-navy);
}

.asbo__product.is-open .asbo__details-chip {
  border-color: #c3cad6;
  background: #f5f6f8;
}

@media (max-width: 767px) {
  .asbo__product-summary {
    grid-template-columns: 58px minmax(0, 1fr) 35px;
    gap: 10px;
    padding: 10px 0;
  }

  .asbo__product-summary .asbo__product-total-wrap {
    grid-column: 2;
    justify-items: start;
    text-align: left;
  }

  .asbo__product-summary .asbo__product-icon {
    grid-column: 3;
    grid-row: 1 / span 2;
    width: 34px;
    height: 34px;
  }

  .asbo__product-title-line {
    display: grid;
    justify-items: start;
    gap: 5px;
  }

  .asbo__details-chip {
    min-height: 29px;
    padding: 5px 9px;
    font-size: clamp(11.5px, 3.1vw, 12.5px);
  }
}
'''

css_marker = '\nCSS;\n    }\n\n    private static function inline_js'
if s.count(css_marker) != 1:
    raise SystemExit(f'inline CSS marker changed unexpectedly; found {s.count(css_marker)}')
s = s.replace(css_marker, css + css_marker, 1)

main.write_text(s)

b = block.read_text()
if b.count('"version": "1.1.6"') != 1:
    raise SystemExit('block.json version marker not found exactly once')
block.write_text(b.replace('"version": "1.1.6"', '"version": "1.1.7"', 1))

r = readme.read_text()
if r.count('All Star Bulk Order Block v1.1.6') != 1:
    raise SystemExit('README version marker not found exactly once')
r = r.replace('All Star Bulk Order Block v1.1.6', 'All Star Bulk Order Block v1.1.7', 1)
r += '''\n\nv1.1.7 product-details discoverability polish:\n- Adds a muted Details & sizing chip directly beside the product title on desktop and directly beneath it on mobile.\n- The chip opens the existing product-details/size-information modal without expanding the pricing accordion first.\n- Keeps the full product row as a separate keyboard-accessible accordion control; the details chip is a separate native button, avoiding nested interactive controls.\n- Removes the redundant Product details & size information link from the expanded pricing panel.\n- Uses a low-contrast warm-neutral chip with navy text, a soft gray border, and restrained gold focus treatment so it remains readable without competing with primary yellow actions.\n- No pricing, Supplier Sync, cart, artwork, savings, or checkout calculations changed.\n'''
readme.write_text(r)

print('ASBO v1.1.7 transform applied successfully')
