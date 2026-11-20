<?php
/**
 * Origin page: the product strip (active origin) or the status line (former origin).
 *
 * Expected variables (kaupang_attribute_suite_render_origin_products()):
 *   $origin (array) — origin struct.
 *   $status (array) — kaupang_attribute_suite_origin_status(): state 'active' | 'former'.
 *   $items  (array) — kaupang_attribute_suite_origin_strip_items(), empty unless active.
 *
 * Theme override: {theme}/kaupang-attribute-suite/parts/origin-products.php.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/** @var array $origin */
/** @var array $status */
/** @var array $items */

if ('former' === $status['state']) {
    $period = kaupang_attribute_suite_format_sold_period($status);
    ?>
    <p class="wc-ras-origin-status former">
        <?php
        echo esc_html($period !== ''
            /* translators: %s: period the origin was sold, e.g. "jul 2025 – feb 2026" */
            ? sprintf(__('Ikke i sortimentet nå · solgt %s', 'kaupang-attribute-suite'), $period)
            : __('Ikke i sortimentet nå', 'kaupang-attribute-suite'));
        ?>
    </p>
    <?php
    return;
}

if (!$items) {
    return;
}
$count = count($items);
?>
<section class="wc-ras-origin-products" aria-labelledby="wc-ras-origin-products-title">
    <h2 id="wc-ras-origin-products-title">
        <?php
        printf(
            /* translators: %d: number of chocolates/products using this origin */
            esc_html(_n('Brukes i %d sjokolade', 'Brukes i %d sjokolader', $count, 'kaupang-attribute-suite')),
            (int) $count
        );
        ?>
    </h2>
    <ul class="strip">
        <?php foreach ($items as $item) : ?>
            <?php /** @var WC_Product $product */ $product = $item['product']; ?>
            <li>
                <a href="<?php echo esc_url($item['url']); ?>">
                    <?php echo $product->get_image('woocommerce_thumbnail', array('loading' => 'lazy', 'alt' => '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span class="name"><?php echo esc_html($product->get_name()); ?></span>
                    <?php if ($item['price_html'] !== '') : ?>
                        <span class="price"><?php echo wp_kses_post($item['price_html']); ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
