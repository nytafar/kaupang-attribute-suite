<?php
/**
 * Origin modal — native <dialog> shell with a single hydratable sourcing card.
 *
 * The dialog IS the sheet: no ground, no inner card. Contents, in DOM order
 * (the theme lays them out with grid areas per viewport):
 *   close button → hero (name + stamp cluster: country, certifications)
 *   → facts (variety, altitude, producers, fermentation) → radar → tasting
 *   notes → permalink → origin strip (JS-built from the preloaded
 *   variations; one tile per origin with a rich page, selected = current).
 *
 * The phone card is front-of-pack: region, producer count, fermentation
 * method and drying are in the markup but the theme hides them in modal
 * mode; the desktop sheet has room and shows them. Tagline and the
 * reference slug stay on the full origin page. Server-renders once with the default variation's
 * origin; `assets/js/origin-modal.js` updates it in place on every
 * `found_variation` / `show_variation` event.
 *
 * Hydration contract: each mutable element carries a `data-field` matching
 * a key on the wc_ras_origin struct; a fact cell carries `data-field-group`
 * so the JS can hide the whole cell. Elements without a value for the
 * default variation render with the `hidden` attribute so the initial and
 * hydrated states look identical. The plugin's structural CSS also hides a
 * cell whose fields are all hidden (`:has()`).
 *
 * Theme override path:
 *   {theme}/kaupang-attribute-suite/parts/origin-modal.php
 *
 * Expected variables:
 *   @var array|null $origin wc_ras_origin struct or null.
 *   @var bool       $inline Optional. True renders the card inline (see
 *                           kaupang_attribute_suite_render_origin_sheet()).
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/** @var array|null $origin */
$origin = isset($origin) && is_array($origin) ? $origin : null;

$name          = (string) ($origin['name']                ?? '');
$region        = (string) ($origin['region']              ?? '');
$producer_cnt  = (string) ($origin['producer_count_label'] ?? '');
$ferm_method   = (string) ($origin['fermentation_method'] ?? '');
$drying        = (string) ($origin['drying_method']       ?? '');
$country       = (string) ($origin['country']             ?? '');
$variety       = (string) ($origin['variety']             ?? '');
$flag_url      = (string) ($origin['country_flag_url']    ?? '');
$permalink     = (string) ($origin['permalink']           ?? '');
$taste_notes   = (string) ($origin['taste_notes']         ?? '');
$alt_str       = (string) ($origin['altitude_label']      ?? '');
$producer_type = (string) ($origin['producer_type_label'] ?? '');
$ferm_value    = (string) ($origin['fermentation_value']  ?? '');

$radar_svg = ($origin && !empty($origin['taste_profile']) && function_exists('kaupang_attribute_suite_render_taste_radar_svg'))
    ? kaupang_attribute_suite_render_taste_radar_svg($origin['taste_profile'])
    : '';

$certs = (is_array($origin['certifications'] ?? null)) ? $origin['certifications'] : array();

// Inline (kaupang_attribute_suite_render_origin_sheet()): the same card in the page flow, as a
// <section> without the dialog chrome (close, handle, origin strip) and without the JS hook.
$inline   = !empty($inline);
$title_id = $inline ? 'wc-ras-origin-sheet-title' : 'wc-ras-origin-modal-title';

$hidden_attr = function ($cond) {
    return $cond ? '' : ' hidden';
};
?>
<?php if ($inline) : ?>
<section class="wc-ras-origin-modal wc-ras-origin-modal--inline" aria-labelledby="<?php echo esc_attr($title_id); ?>">
<?php else : ?>
<dialog class="wc-ras-origin-modal" data-wc-ras-origin-modal aria-labelledby="<?php echo esc_attr($title_id); ?>">
    <button type="button"
            class="wc-ras-origin-modal__handle"
            data-origin-modal-handle
            data-origin-modal-close
            aria-label="<?php esc_attr_e('Lukk', 'kaupang-attribute-suite'); ?>"><span aria-hidden="true"></span></button>

    <button type="button"
            class="wc-ras-origin-modal__close"
            data-origin-modal-close
            aria-label="<?php esc_attr_e('Lukk', 'kaupang-attribute-suite'); ?>">
        <span aria-hidden="true">×</span>
    </button>
<?php endif; ?>

    <article class="wc-ras-origin-modal__card" data-origin-modal-card>
        <div class="wc-ras-origin-modal__body">

        <header class="wc-ras-origin-modal__hero">
            <h2 id="<?php echo esc_attr($title_id); ?>"
                class="wc-ras-origin-modal__title"
                data-field="name"><?php echo esc_html($name); ?></h2>

            <div class="wc-ras-origin-modal__stamps">
                <span class="wc-ras-origin-modal__stamp wc-ras-origin-modal__stamp--country"
                      data-field-group="flag"
                      <?php echo $hidden_attr($country !== ''); ?>>
                    <img class="flag-img"
                         data-field="flag-image"
                         src="<?php echo esc_url($flag_url); ?>"
                         alt=""
                         <?php echo $hidden_attr($flag_url !== ''); ?> />
                    <span data-field="country"><?php echo esc_html($country); ?></span>
                </span>

                <span class="wc-ras-origin-modal__certs" data-field="certifications">
                    <?php foreach ($certs as $cert) : ?>
                        <?php if (empty($cert['icon_url'])) { continue; } ?>
                        <span class="wc-ras-origin-modal__stamp wc-ras-origin-modal__stamp--cert"
                              data-cert-slug="<?php echo esc_attr($cert['slug'] ?? ''); ?>"
                              title="<?php echo esc_attr($cert['name'] ?? ''); ?>">
                            <img src="<?php echo esc_url($cert['icon_url']); ?>"
                                 alt="<?php echo esc_attr($cert['name'] ?? ''); ?>" />
                        </span>
                    <?php endforeach; ?>
                </span>
            </div>
        </header>

        <dl class="wc-ras-origin-modal__facts">
            <div class="wc-ras-origin-modal__fact wc-ras-origin-modal__fact--wide" data-field-group="region"<?php echo $hidden_attr($region !== ''); ?>>
                <dt><?php esc_html_e('Region', 'kaupang-attribute-suite'); ?></dt>
                <dd data-field="region"<?php echo $hidden_attr($region !== ''); ?>><?php echo esc_html($region); ?></dd>
            </div>

            <div class="wc-ras-origin-modal__fact" data-field-group="variety"<?php echo $hidden_attr($variety !== ''); ?>>
                <dt><?php esc_html_e('Varietet', 'kaupang-attribute-suite'); ?></dt>
                <dd data-field="variety"<?php echo $hidden_attr($variety !== ''); ?>><?php echo esc_html($variety); ?></dd>
            </div>

            <div class="wc-ras-origin-modal__fact" data-field-group="altitude"<?php echo $hidden_attr($alt_str !== ''); ?>>
                <dt><?php esc_html_e('Høyde', 'kaupang-attribute-suite'); ?></dt>
                <dd data-field="altitude"<?php echo $hidden_attr($alt_str !== ''); ?>><?php echo esc_html($alt_str); ?></dd>
            </div>

            <div class="wc-ras-origin-modal__fact" data-field-group="producers"<?php echo $hidden_attr($producer_type !== ''); ?>>
                <dt><?php esc_html_e('Produsenter', 'kaupang-attribute-suite'); ?></dt>
                <dd>
                    <span data-field="producer-type"<?php echo $hidden_attr($producer_type !== ''); ?>><?php echo esc_html($producer_type); ?></span>
                    <span class="wc-ras-origin-modal__more" data-field="producer-count"<?php echo $hidden_attr($producer_cnt !== ''); ?>><?php echo esc_html($producer_cnt); ?></span>
                </dd>
            </div>

            <div class="wc-ras-origin-modal__fact" data-field-group="fermentation"<?php echo $hidden_attr($ferm_value !== ''); ?>>
                <dt><?php esc_html_e('Fermentering', 'kaupang-attribute-suite'); ?></dt>
                <dd>
                    <span data-field="fermentation-value"<?php echo $hidden_attr($ferm_value !== ''); ?>><?php echo esc_html($ferm_value); ?></span>
                    <span class="wc-ras-origin-modal__more freetext" data-field="fermentation-method"<?php echo $hidden_attr($ferm_method !== ''); ?>><?php echo esc_html($ferm_method); ?></span>
                </dd>
            </div>

            <div class="wc-ras-origin-modal__fact wc-ras-origin-modal__fact--more" data-field-group="drying"<?php echo $hidden_attr($drying !== ''); ?>>
                <dt><?php esc_html_e('Tørking', 'kaupang-attribute-suite'); ?></dt>
                <dd data-field="drying-method"<?php echo $hidden_attr($drying !== ''); ?>><?php echo esc_html($drying); ?></dd>
            </div>
        </dl>

        <p class="wc-ras-origin-modal__flavour-label"
           data-field="flavour-label"
           <?php echo $hidden_attr($radar_svg !== ''); ?>><?php esc_html_e('Smaksprofil', 'kaupang-attribute-suite'); ?></p>

        <div class="wc-ras-origin-modal__radar"
             data-field="radar"
             <?php echo $hidden_attr($radar_svg !== ''); ?>><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns safe inline SVG
                echo $radar_svg;
            ?></div>

        <p class="wc-ras-origin-modal__notes"
           data-field="taste-notes"
           <?php echo $hidden_attr($taste_notes !== ''); ?>><?php echo esc_html($taste_notes); ?></p>

        <p class="wc-ras-origin-modal__permalink"
           <?php echo $hidden_attr($permalink !== ''); ?>>
            <a data-field="permalink"
               href="<?php echo esc_url($permalink); ?>"
               class="term-page-link">
                <?php esc_html_e('Se hele siden →', 'kaupang-attribute-suite'); ?>
            </a>
        </p>

        </div>
    </article>
<?php if ($inline) : ?>
</section>
<?php else : ?>

    <nav class="wc-ras-origin-modal__strip wc-ras-origin-modal__tabs"
         data-origin-modal-strip
         aria-label="<?php esc_attr_e('Opprinnelser', 'kaupang-attribute-suite'); ?>"
         hidden></nav>
</dialog>
<?php endif; ?>
