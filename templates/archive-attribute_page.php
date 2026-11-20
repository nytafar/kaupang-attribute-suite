<?php
/**
 * Origin index, /opprinnelser/ (the attribute_page CPT's archive URL).
 *
 * Built from the pa_opprinnelse terms, not the CPT query (kaupang_attribute_suite_origin_index(); kaupang-docs
 * kaupang-attribute-suite/origins-architecture-2026-11-20.md §5):
 *   «I sortimentet»: every active origin as a card. With a published page: parts/origin-card.php; without one:
 *     parts/origin-card-plain.php (name, product count, link to the term archive).
 *   «Tidligere»: former origins, newest last sale first: name, country when known, the period sold. Linked when
 *     there is a page.
 * Unsold origins don't show.
 *
 * Theme override: archive-attribute_page.php in the active theme (WP template hierarchy).
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

$index = kaupang_attribute_suite_origin_index();

get_header();
?>
<main id="content" class="content wc-ras-origin-archive">
    <header class="header">
        <h1><?php post_type_archive_title(); ?></h1>
        <?php
        $archive_desc = get_the_archive_description();
        if ($archive_desc) :
            ?>
            <div class="description"><?php echo wp_kses_post($archive_desc); ?></div>
        <?php endif; ?>
    </header>

    <?php if ($index['active']) : ?>
        <section class="active" aria-labelledby="wc-ras-origins-active">
            <h2 id="wc-ras-origins-active"><?php esc_html_e('I sortimentet', 'kaupang-attribute-suite'); ?></h2>
            <div class="grid">
                <?php
                foreach ($index['active'] as $entry) {
                    if ($entry['origin']) {
                        $card_origin = $entry['origin'];
                        include KAUPANG_ATTRIBUTE_SUITE_DIR . 'templates/parts/origin-card.php';
                    } else {
                        echo kaupang_attribute_suite_load_template('parts/origin-card-plain', array('entry' => $entry)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the part
                    }
                }
                ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($index['former']) : ?>
        <section class="former" aria-labelledby="wc-ras-origins-former">
            <h2 id="wc-ras-origins-former"><?php esc_html_e('Tidligere', 'kaupang-attribute-suite'); ?></h2>
            <ul class="list">
                <?php foreach ($index['former'] as $entry) : ?>
                    <?php
                    $name    = $entry['origin'] ? $entry['origin']['name'] : $entry['term']->name;
                    $country = $entry['origin'] ? (string) $entry['origin']['country'] : '';
                    $period  = kaupang_attribute_suite_format_sold_period($entry['status']);
                    ?>
                    <li>
                        <?php if ($entry['page']) : ?>
                            <a class="name" href="<?php echo esc_url(get_permalink($entry['page'])); ?>"><?php echo esc_html($name); ?></a>
                        <?php else : ?>
                            <span class="name"><?php echo esc_html($name); ?></span>
                        <?php endif; ?>
                        <?php if ($country !== '') : ?>
                            <span class="country"><?php echo esc_html($country); ?></span>
                        <?php endif; ?>
                        <?php if ($period !== '') : ?>
                            <span class="period"><?php echo esc_html($period); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if (!$index['active'] && !$index['former']) : ?>
        <p class="empty">
            <?php esc_html_e('Ingen opprinnelser publisert enda.', 'kaupang-attribute-suite'); ?>
        </p>
    <?php endif; ?>
</main>
<?php
get_footer();
