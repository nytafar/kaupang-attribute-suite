<?php
/**
 * Single template for the attribute_page CPT.
 *
 * Rendered at /opprinnelser/{slug}/ unless the theme overrides via standard
 * WP template hierarchy (single-attribute_page.php in the active theme).
 *
 * Structure:
 *   <main id="content" class="content"> — same page parent as the theme's
 *     page.php, so the theme's content-parent rules (width, block-gap
 *     rhythm) and its skip link reach this page.
 *   <article>
 *     <div class="header"> — complex grid (NB: <div>, ikke <header>,
 *       fordi mange WP-temaer (inkl. ousia) har header>* / section>*-
 *       regler som tvinger max-width + margin-inline:auto, som krymper
 *       våre flex/grid-containere):
 *       1. Hero: navn + tagline + flag-pille + altitude-pille
 *       2. Producers & Cultivation: producer_type, producer_count, variety
 *       3. Post-harvest: fermentering + tørking (hybrid layout)
 *       4. Flavour: radar-SVG + frihåndsnotater (smak)
 *       5. Hero-image: polaroid (mobil sist; desktop høyre kolonne)
 *     </div>
 *     6. the_content() — Gutenberg blocks (direct children of <article>)
 *     7. Related products (deferred — hook for theme/site integration)
 *   </article>
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

get_header();
?>
<main id="content" class="content">
<?php
while (have_posts()) :
    the_post();

    $origin = kaupang_attribute_suite_build_origin_struct(get_post());
    if (!$origin) {
        continue;
    }

    $region_label = implode(', ', array_filter(array($origin['country'], $origin['region'])));
    $alt_str      = $origin['altitude'] ? kaupang_attribute_suite_format_altitude($origin['altitude']) : '';
    $producer_types = function_exists('kaupang_attribute_suite_producer_types') ? kaupang_attribute_suite_producer_types() : array();
    ?>
    <article <?php post_class('wc-ras-origin-single'); ?>>
        <?php /* <div> i stedet for <header> — Ousia (og mange WP-temaer)
                har "header > *" / "section > * { max-width: var(--max-width);
                margin-inline: auto }". Det krymper alle våre flex/grid-
                containere til max-content og sentrerer dem i grid-cellen.
                Bytter vi tag, slipper vi unna uten override-spesifisitet. */ ?>
        <div class="header">
            <?php
            if ($origin['featured_image_url']) :
                $thumb_id    = (int) get_post_thumbnail_id();
                $thumb_cap   = $thumb_id ? wp_get_attachment_caption($thumb_id) : '';
                // Bare eksplisitt attachment-caption: opprinnelse-navnet
                // ekkoer h1, og region_label er for langt for fotostripa.
                $fig_caption = $thumb_cap;
                ?>
                <figure class="hero-image">
                    <img src="<?php echo esc_url($origin['featured_image_url']); ?>"
                         alt="<?php echo esc_attr($origin['name']); ?>" />
                    <?php if ($fig_caption !== '') : ?>
                        <figcaption><?php echo esc_html($fig_caption); ?></figcaption>
                    <?php endif; ?>
                </figure>
            <?php endif; ?>

            <div class="hero">
                <h1><?php echo esc_html($origin['name']); ?></h1>

                <?php if (!empty($origin['excerpt'])) : ?>
                    <p class="tagline"><?php echo esc_html($origin['excerpt']); ?></p>
                <?php endif; ?>

                <?php if ($region_label || $alt_str !== '') : ?>
                    <div class="meta">
                        <?php if ($region_label) : ?>
                            <span class="pill flag">
                                <?php if ($origin['country_flag_url']) : ?>
                                    <img src="<?php echo esc_url($origin['country_flag_url']); ?>" alt="" />
                                <?php endif; ?>
                                <span><?php echo esc_html($region_label); ?></span>
                            </span>
                        <?php endif; ?>

                        <?php if ($alt_str !== '') : ?>
                            <span class="pill altitude">
                                <?php echo kaupang_attribute_suite_origin_icon('altitude'); ?>
                                <span><?php echo esc_html($alt_str); ?></span>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            // ─── Producers & Cultivation ───
            $producer_type_label = $origin['producer_type'] && isset($producer_types[$origin['producer_type']])
                ? $producer_types[$origin['producer_type']]
                : null;

            $show_producer_line = (bool) $producer_type_label;
            $show_count_line    = $origin['producer_count'] !== null;
            $show_variety       = (bool) $origin['variety'];

            if ($show_producer_line || $show_count_line || $show_variety) :
                ?>
                <div class="section producers">
                    <h2><?php esc_html_e('Produsenter og dyrking', 'kaupang-attribute-suite'); ?></h2>

                    <?php if ($show_producer_line) : ?>
                        <p class="producer-line">
                            <?php echo kaupang_attribute_suite_origin_icon('producer'); ?>
                            <span><?php echo esc_html($producer_type_label); ?></span>
                        </p>
                    <?php endif; ?>

                    <?php if ($show_count_line) : ?>
                        <p class="producer-count">
                            <?php
                            printf(
                                /* translators: %d: number of producers */
                                esc_html(_n('%d produsent', '%d produsenter', (int) $origin['producer_count'], 'kaupang-attribute-suite')),
                                (int) $origin['producer_count']
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($show_variety) : ?>
                        <p class="variety">
                            <strong><?php esc_html_e('Varietet:', 'kaupang-attribute-suite'); ?></strong>
                            <?php echo esc_html($origin['variety']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // ─── Post-harvest ───
            $fermentation_value = kaupang_attribute_suite_format_fermentation_value(
                $origin['fermentation_type'],
                $origin['fermentation_days']
            );
            $ferm_item = kaupang_attribute_suite_render_postharvest_item(
                'fermentation',
                __('Fermentering', 'kaupang-attribute-suite'),
                $fermentation_value,
                (string) ($origin['fermentation_method'] ?? '')
            );
            $dry_item = kaupang_attribute_suite_render_postharvest_item(
                'drying',
                __('Tørking', 'kaupang-attribute-suite'),
                '',
                (string) ($origin['drying_method'] ?? '')
            );

            if ($ferm_item || $dry_item) :
                ?>
                <div class="section postharvest">
                    <h2><?php esc_html_e('Etter innhøsting', 'kaupang-attribute-suite'); ?></h2>
                    <?php
                    echo $ferm_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped inside helper
                    echo $dry_item;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped inside helper
                    ?>
                </div>
            <?php endif; ?>

            <?php
            // ─── Flavour ───
            $radar_svg = kaupang_attribute_suite_render_taste_radar_svg($origin['taste_profile']);
            $has_notes = !empty($origin['taste_notes']);

            if ($radar_svg || $has_notes) :
                ?>
                <div class="section flavour">
                    <?php if ($radar_svg) : ?>
                        <div class="radar"><?php echo $radar_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <?php endif; ?>
                    <?php if ($has_notes) : ?>
                        <div class="notes">
                            <h2><?php esc_html_e('Smaksnotater', 'kaupang-attribute-suite'); ?></h2>
                            <p><?php echo esc_html($origin['taste_notes']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // Sertifiserings-stempler vises ikke som egen seksjon her —
            // de eksponeres i variasjons-blurben/modalen og som inline-marker
            // i Gutenberg-flyten i stedet (jf. designdir. fra produkteier).
            // Dataen ($origin['certifications']) er fortsatt på structen og
            // kan plukkes opp av andre rendrere via wc_ras_origin-strukturen.
            ?>
        </div>

        <?php
        // ─── Gutenberg content ───
        // Direct children of <article> so block alignment (alignwide/alignfull)
        // and theme content styles apply without an extra wrapper.
        the_content();

        // ─── Related products hook ───
        // Intentionally no default rendering — hook leveraged by theme/site
        // integration. Kept for fase 3/4 when we ship the related-products
        // query that filters by pa_opprinnelse.
        do_action('kaupang/attribute-suite/origin_single_after_content', $origin);
        ?>
    </article>
    <?php
endwhile;
?>
</main>
<?php
get_footer();
