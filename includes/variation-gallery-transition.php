<?php
/**
 * Variation Gallery Transition
 *
 * Crossfades the product gallery's main image when a variation is selected.
 * Pure presentation layer — listens to WooCommerce's `found_variation` event,
 * snapshots the current image as a ghost overlay, then fades the ghost once
 * the new image is decoded. Theme tunes timing/easing via CSS custom
 * properties on the gallery wrapper.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Registers and conditionally enqueues the gallery transition assets.
 */
class Kaupang_Attribute_Suite_Variation_Gallery_Transition {

    public function __construct() {
        if (!apply_filters('kaupang/attribute-suite/enable_variation_gallery_transition', true)) {
            return;
        }
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Registers the stylesheet and script. Kept separate from enqueue so
     * other code can opt into the asset without re-declaring it.
     */
    public function register_assets() {
        $css_path = KAUPANG_ATTRIBUTE_SUITE_DIR . 'assets/css/variation-gallery-transition.css';
        $js_path  = KAUPANG_ATTRIBUTE_SUITE_DIR . 'assets/js/variation-gallery-transition.js';

        wp_register_style(
            'wc-ras-variation-gallery-transition',
            KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/css/variation-gallery-transition.css',
            array(),
            file_exists($css_path) ? filemtime($css_path) : KAUPANG_ATTRIBUTE_SUITE_VERSION
        );

        wp_register_script(
            'wc-ras-variation-gallery-transition',
            KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/js/variation-gallery-transition.js',
            array('jquery', 'wc-add-to-cart-variation'),
            file_exists($js_path) ? filemtime($js_path) : KAUPANG_ATTRIBUTE_SUITE_VERSION,
            array('in_footer' => true, 'strategy' => 'defer')
        );
    }

    /**
     * Enqueues the registered assets only on variable product pages (the only ones with variations to fade between).
     */
    public function enqueue_assets() {
        if (!kaupang_attribute_suite_is_variable_product_page()) {
            return;
        }
        wp_enqueue_style('wc-ras-variation-gallery-transition');
        wp_enqueue_script('wc-ras-variation-gallery-transition');
    }
}

new Kaupang_Attribute_Suite_Variation_Gallery_Transition();
