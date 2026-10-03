<?php
/**
 * Origin module — frontend enqueue + template fallback.
 *
 * Owns:
 *   - CPT stylesheet enqueue (origin.css) on CPT archive, CPT single,
 *     origin_country archive, certification archive. No product-page
 *     styling — theme owns that in this pass.
 *   - origin-radar.js registration (handle used as dependency by modal).
 *   - Product-page origin-modal enqueue + render hook (fase 3).
 *   - Public inline render of the same card for other callers
 *     (kaupang_attribute_suite_render_origin_sheet()).
 *   - Template-include fallback so CPT archive/single use plugin
 *     templates when the theme does not override.
 *
 * Variation description enrichment is handled by
 * variation-improvements.php, which builds the HTML via
 * templates/parts/variation-description.php — not here.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Enqueue origin stylesheet on CPT contexts only. Product pages intentionally
 * omitted — variation description enrichment is theme-styled.
 */
function kaupang_attribute_suite_origin_enqueue_styles() {
    $should_load = is_post_type_archive('attribute_page')
        || is_singular('attribute_page')
        || is_tax('origin_country')
        || is_tax('certification');

    if (!$should_load) {
        return;
    }

    $css_path = KAUPANG_ATTRIBUTE_SUITE_DIR . 'assets/css/origin.css';
    $css_ver  = KAUPANG_ATTRIBUTE_SUITE_VERSION . '.' . (file_exists($css_path) ? filemtime($css_path) : 0);

    wp_enqueue_style(
        'wc-ras-origin',
        KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/css/origin.css',
        array(),
        $css_ver
    );
}
add_action('wp_enqueue_scripts', 'kaupang_attribute_suite_origin_enqueue_styles');

/**
 * Register the radar script. Callers enqueue on demand (modal in fase 3).
 */
function kaupang_attribute_suite_origin_register_radar_script() {
    $js_path = KAUPANG_ATTRIBUTE_SUITE_DIR . 'assets/js/origin-radar.js';
    $js_ver  = KAUPANG_ATTRIBUTE_SUITE_VERSION . '.' . (file_exists($js_path) ? filemtime($js_path) : 0);

    wp_register_script(
        'wc-ras-origin-radar',
        KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/js/origin-radar.js',
        array(),
        $js_ver,
        true
    );

    if (function_exists('kaupang_attribute_suite_taste_axes')) {
        wp_localize_script('wc-ras-origin-radar', 'wcRasTasteAxes', kaupang_attribute_suite_taste_axes());
    }
}
add_action('wp_enqueue_scripts', 'kaupang_attribute_suite_origin_register_radar_script');

function kaupang_attribute_suite_origin_product_has_attribute_pages($product) {
    if (!$product instanceof WC_Product || !$product->is_type('variable')) {
        return false;
    }

    $default_attrs = method_exists($product, 'get_default_attributes')
        ? $product->get_default_attributes()
        : array();
    $default_slug = isset($default_attrs['pa_opprinnelse']) ? (string) $default_attrs['pa_opprinnelse'] : '';

    if ($default_slug !== '' && kaupang_attribute_suite_get_cached_attribute_page($default_slug)) {
        return true;
    }

    foreach ($product->get_children() as $variation_id) {
        if (kaupang_attribute_suite_get_attribute_page_for_variation($variation_id)) {
            return true;
        }
    }

    return false;
}

/**
 * Product-page guard used by both modal enqueue and modal render.
 *
 * Returns the WC_Product when the current request is a product page for a
 * variable product whose variations include the `pa_opprinnelse` attribute.
 * Returns null otherwise, letting callers bail cheaply.
 *
 * @return WC_Product|null
 */
function kaupang_attribute_suite_origin_modal_product() {
    if (!function_exists('is_product') || !is_product()) {
        return null;
    }
    global $product;
    $candidate = $product instanceof WC_Product ? $product : wc_get_product(get_the_ID());
    if (!$candidate || !$candidate->is_type('variable')) {
        return null;
    }
    $attrs = $candidate->get_variation_attributes();
    if (empty($attrs['pa_opprinnelse'])) {
        return null;
    }
    if (!kaupang_attribute_suite_origin_product_has_attribute_pages($candidate)) {
        return null;
    }
    return $candidate;
}

/**
 * Resolve the initial origin to seed the server-rendered modal card.
 *
 * Strategy:
 *   1. Use the product's default pa_opprinnelse attribute if set.
 *   2. Otherwise, walk children and take the first variation whose
 *      attribute_page exists.
 *
 * The modal card is hydrated client-side on `found_variation`, so the
 * seed only matters for no-JS users (who go to CPT-single anyway) and
 * for avoiding an initial empty-shell flash on JS users.
 *
 * @param WC_Product $product Variable product.
 * @return array|null wc_ras_origin struct or null.
 */
function kaupang_attribute_suite_origin_modal_initial_origin($product) {
    $default_attrs = method_exists($product, 'get_default_attributes')
        ? $product->get_default_attributes()
        : array();
    $default_slug = isset($default_attrs['pa_opprinnelse']) ? (string) $default_attrs['pa_opprinnelse'] : '';

    if ($default_slug !== '') {
        $page = kaupang_attribute_suite_get_cached_attribute_page($default_slug);
        if ($page) {
            return kaupang_attribute_suite_build_origin_struct($page);
        }
    }

    foreach ($product->get_children() as $variation_id) {
        $page = kaupang_attribute_suite_get_attribute_page_for_variation($variation_id);
        if ($page) {
            return kaupang_attribute_suite_build_origin_struct($page);
        }
    }
    return null;
}

/**
 * Public: enqueue what an origin card needs, for callers rendering one outside the product page.
 *
 * The card's stylesheet (handle `wc-ras-origin-modal`, which also carries the inline variant). No
 * script: the inline card is static (the radar is server-rendered SVG) and the modal's script only
 * wires the product page's dialog. Idempotent; loads nothing unless called.
 */
function kaupang_attribute_suite_enqueue_origin_sheet_assets() {
    $css_path = KAUPANG_ATTRIBUTE_SUITE_DIR . 'assets/css/origin-modal.css';
    $css_ver  = KAUPANG_ATTRIBUTE_SUITE_VERSION . '.' . (file_exists($css_path) ? filemtime($css_path) : 0);

    wp_enqueue_style(
        'wc-ras-origin-modal',
        KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/css/origin-modal.css',
        array(),
        $css_ver
    );
}

/**
 * Public: the rich origin card inline, without the dialog chrome.
 *
 * Same template as the product page's modal (`parts/origin-modal.php`, theme-overridable), rendered
 * as `<section class="wc-ras-origin-modal wc-ras-origin-modal--inline">`: no close button, handle or
 * origin strip, no dialog role, not picked up by origin-modal.js. Enqueues the card's stylesheet
 * (call kaupang_attribute_suite_enqueue_origin_sheet_assets() before wp_head to keep it in <head>).
 *
 * @param array $origin wc_ras_origin struct (kaupang_attribute_suite_build_origin_struct()).
 * @param array $args   Extra variables for a theme's template override.
 * @return string HTML, or '' for an empty struct.
 */
function kaupang_attribute_suite_render_origin_sheet(array $origin, array $args = array()) {
    if (!$origin) {
        return '';
    }
    kaupang_attribute_suite_enqueue_origin_sheet_assets();
    return kaupang_attribute_suite_load_template('parts/origin-modal', array_merge($args, array(
        'origin' => $origin,
        'inline' => true,
    )));
}

/**
 * Register the modal stylesheet + enqueue the modal script on product
 * pages that have pa_opprinnelse variations. Radar JS is pulled in as a
 * dependency, so pages without the modal never pay for either.
 */
function kaupang_attribute_suite_origin_enqueue_modal_assets() {
    if (!kaupang_attribute_suite_origin_modal_product()) {
        return;
    }

    $js_path = KAUPANG_ATTRIBUTE_SUITE_DIR . 'assets/js/origin-modal.js';
    $js_ver  = KAUPANG_ATTRIBUTE_SUITE_VERSION . '.' . (file_exists($js_path) ? filemtime($js_path) : 0);

    kaupang_attribute_suite_enqueue_origin_sheet_assets();

    wp_enqueue_script(
        'wc-ras-origin-modal',
        KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/js/origin-modal.js',
        array('jquery', 'wc-add-to-cart-variation', 'wc-ras-origin-radar'),
        $js_ver,
        true
    );
}
add_action('wp_enqueue_scripts', 'kaupang_attribute_suite_origin_enqueue_modal_assets', 20);

/**
 * Inject the modal shell as the first child of
 * `.woocommerce-product-gallery__wrapper` by prepending it to the main
 * gallery image HTML — the only filter that fires inside the gallery.
 * origin-modal.js hoists the dialog to `.woocommerce-product-gallery`
 * on init: inside the wrapper it would sit in FlexSlider's translated,
 * overflow-hidden slide track and paint under the main image. Desktop
 * `dialog.show()` then overlays the gallery (position:relative in WC
 * core); mobile `showModal()` pulls to the top layer regardless.
 *
 * Idempotent: static flag guards against the filter firing for gallery
 * thumbnails too (in WC cores that route those through the same
 * filter).
 *
 * @param string $html         Thumbnail HTML from wc_get_gallery_image_html().
 * @param int    $thumbnail_id Attachment ID of the main image.
 * @return string
 */
function kaupang_attribute_suite_origin_prepend_modal($html, $thumbnail_id) {
    static $rendered = false;
    if ($rendered) {
        return $html;
    }
    $product = kaupang_attribute_suite_origin_modal_product();
    if (!$product) {
        return $html;
    }
    $rendered = true;

    $modal = kaupang_attribute_suite_load_template('parts/origin-modal', array(
        'origin' => kaupang_attribute_suite_origin_modal_initial_origin($product),
    ));

    return $modal . $html;
}
add_filter('woocommerce_single_product_image_thumbnail_html', 'kaupang_attribute_suite_origin_prepend_modal', 10, 2);

/**
 * Fall back to plugin templates when the active theme does not provide
 * archive/single templates for the attribute_page CPT.
 *
 * Theme override order (first match wins):
 *   1. {theme}/single-attribute_page.php / archive-attribute_page.php
 *   2. {plugin}/templates/single-attribute_page.php / archive-attribute_page.php
 *
 * @param string $template Original template path resolved by WP.
 * @return string
 */
function kaupang_attribute_suite_origin_template_include($template) {
    if (is_singular('attribute_page')) {
        $theme_hit = locate_template(array('single-attribute_page.php'));
        if ($theme_hit) {
            return $theme_hit;
        }
        $plugin_template = KAUPANG_ATTRIBUTE_SUITE_DIR . 'templates/single-attribute_page.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    if (is_post_type_archive('attribute_page')) {
        $theme_hit = locate_template(array('archive-attribute_page.php'));
        if ($theme_hit) {
            return $theme_hit;
        }
        $plugin_template = KAUPANG_ATTRIBUTE_SUITE_DIR . 'templates/archive-attribute_page.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    return $template;
}
add_filter('template_include', 'kaupang_attribute_suite_origin_template_include', 20);
