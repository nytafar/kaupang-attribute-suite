<?php
/**
 * Plain origin card: an active origin without a published page (index «I sortimentet»).
 *
 * Expected variable:
 *   $entry (array) — one entry of kaupang_attribute_suite_origin_index(): term, status, page (null), origin (null).
 *
 * Name and product count, linked to the term archive when the attribute has archives. Same overlay link as
 * parts/origin-card.php. Theme override: {theme}/kaupang-attribute-suite/parts/origin-card-plain.php.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/** @var array|null $entry */
if (empty($entry['term']) || !$entry['term'] instanceof WP_Term) {
    return;
}

$term  = $entry['term'];
$count = (int) $entry['status']['active_products'];
$link  = is_taxonomy_viewable($term->taxonomy) ? get_term_link($term) : '';
$link  = is_wp_error($link) ? '' : (string) $link;
?>
<article class="wc-ras-origin-card plain">
    <div class="body">
        <h2><?php echo esc_html($term->name); ?></h2>
        <?php if ($count > 0) : ?>
            <p class="count">
                <?php
                printf(
                    /* translators: %d: number of chocolates/products using this origin */
                    esc_html(_n('Brukes i %d sjokolade', 'Brukes i %d sjokolader', $count, 'kaupang-attribute-suite')),
                    (int) $count
                );
                ?>
            </p>
        <?php endif; ?>
    </div>
    <?php if ($link !== '') : ?>
        <a class="link" href="<?php echo esc_url($link); ?>" aria-label="<?php echo esc_attr($term->name); ?>">
            <?php echo esc_html($term->name); ?>
        </a>
    <?php endif; ?>
</article>
