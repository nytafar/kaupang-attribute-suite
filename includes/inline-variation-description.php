<?php
/**
 * Inline Variation Description
 *
 * Renders variation descriptions inline within the variations table,
 * eliminating CLS and DOM manipulation issues.
 *
 * @package Kaupang\AttributeSuite
 * @since 1.1.0
 */

defined('ABSPATH') || exit;

/**
 * Class for inline variation description rendering
 * 
 * This feature must be explicitly enabled by the theme using:
 * add_filter('kaupang/attribute-suite/enable_inline_variation_description', '__return_true');
 * 
 * When enabled, this class:
 * 1. Uses JavaScript to inject a placeholder row into the variations table
 * 2. Provides a custom WooCommerce variation template that omits the description div
 * 3. Updates the placeholder content on variation change without DOM manipulation
 * 
 * This approach eliminates:
 * - Cumulative Layout Shift (CLS) from DOM manipulation
 * - Description duplication issues
 * - Race conditions between WooCommerce's template rendering and theme JS
 */
class Kaupang_Attribute_Suite_Inline_Variation_Description {

    /**
     * Whether the feature is enabled (cached after first check)
     * 
     * @var bool|null
     */
    private $is_enabled = null;

    /**
     * Constructor
     */
    public function __construct() {
        // After init the theme's filters are registered: run now when loaded late (storefront gate or lazy loader).
        if (did_action('init')) {
            $this->maybe_init();
        } else {
            add_action('init', array($this, 'maybe_init'), 20);
        }
    }

    /**
     * Check if feature is enabled and initialize if so
     */
    public function maybe_init() {
        if (!$this->is_enabled()) {
            return;
        }

        $this->init();
    }

    /**
     * Check if the feature is enabled
     * 
     * @return bool
     */
    private function is_enabled() {
        if ($this->is_enabled === null) {
            $this->is_enabled = apply_filters('kaupang/attribute-suite/enable_inline_variation_description', false);
        }
        return $this->is_enabled;
    }

    /**
     * Initialize hooks
     */
    public function init() {
        // The variation template without the description div: kaupang_attribute_suite_variation_template() in core.php
        // (Woo prints that template wherever its variation script is enqueued, not only where this module loads).

        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add variation description data to AJAX response
        add_filter('woocommerce_available_variation', array($this, 'add_description_data_to_variation'), 15, 3);
    }

    /**
     * Enqueue scripts for inline description updates
     */
    public function enqueue_scripts() {
        if (!kaupang_attribute_suite_is_variable_product_page()) {
            return;
        }

        // Get configuration from filter
        $config = apply_filters('kaupang/attribute-suite/inline_variation_description_config', array(
            'target_attribute' => '', // Empty = auto-detect or first attribute
            'auto_detect' => true,    // Auto-detect attribute with description
        ));

        $script_version = KAUPANG_ATTRIBUTE_SUITE_VERSION . '.' . filemtime(
            KAUPANG_ATTRIBUTE_SUITE_DIR . 'assets/js/inline-variation-description.js'
        );

        wp_enqueue_script(
            'wc-ras-inline-description',
            KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/js/inline-variation-description.js',
            array('jquery', 'wc-add-to-cart-variation'),
            $script_version,
            array('in_footer' => true, 'strategy' => 'defer')
        );

        // Pass configuration to JS
        wp_localize_script('wc-ras-inline-description', 'wcRasInlineDesc', array(
            'animationDuration' => apply_filters('kaupang/attribute-suite/inline_description_animation_duration', 400),
            'targetAttribute' => $config['target_attribute'],
            'autoDetect' => $config['auto_detect'],
        ));
    }

    /**
     * Add description data to variation response
     * 
     * This ensures the description is available in the variation data
     * even when using the no-description template
     *
     * @param array                $variation_data The variation data
     * @param WC_Product_Variable  $product       The parent product
     * @param WC_Product_Variation $variation     The variation product
     * @return array Modified variation data
     */
    public function add_description_data_to_variation($variation_data, $product, $variation) {
        // The description should already be populated by variation_description_fallback
        // We just need to ensure it's marked for inline display
        $variation_data['kaupang_attribute_suite_inline_description'] = true;
        
        return $variation_data;
    }
}

// Initialize the class
new Kaupang_Attribute_Suite_Inline_Variation_Description();
