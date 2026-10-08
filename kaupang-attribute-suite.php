<?php
/**
 * Plugin Name: Kaupang Attribute Suite
 * Plugin URI:  https://github.com/nytafar/kaupang-attribute-suite
 * Description: Enhance WooCommerce product attribute taxonomy pages with rich, translatable, and fully editable content using native WordPress tools.
 * Version:     2.1.0
 * Author:      Lasse Jellum
 * Author URI:  https://jellum.net
 * Text Domain: kaupang-attribute-suite
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.5
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 11.1
 * License: GPL-2.0-or-later
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('KAUPANG_ATTRIBUTE_SUITE_VERSION', '2.1.0');
define('KAUPANG_ATTRIBUTE_SUITE_FILE', __FILE__);
define('KAUPANG_ATTRIBUTE_SUITE_DIR', plugin_dir_path(__FILE__));
define('KAUPANG_ATTRIBUTE_SUITE_URL', plugin_dir_url(__FILE__));

// Declare HPOS (custom order tables) and cart/checkout blocks compatibility.
// This plugin never reads or writes order data, and only hooks the single
// product page (variations, gallery thumbnails, attribute taxonomies), so
// neither order storage nor the block cart/checkout is affected.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Include core files. `Requires Plugins` only guards the plugins UI and activation; the
// loader and `wp plugin deactivate woocommerce` don't honour it, so bail here (inert plugin).
function kaupang_attribute_suite_init() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/template-loader.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/cpt-attribute-page.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-config.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-meta.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-taxonomies.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-render.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/frontend-hooks.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-frontend.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/block-patterns.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/admin-hooks.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-admin.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-admin-certifications.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/variation-improvements.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/archived-variations.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/inline-variation-description.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/variation-gallery-transition.php';
    require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/gtin-links.php';
}
add_action('plugins_loaded', 'kaupang_attribute_suite_init');

// Explicit catalogue load (languages/). Belt and braces: core already registers Text Domain +
// Domain Path from the header, so this only matters if that registration is ever bypassed.
add_action('init', function () {
    load_plugin_textdomain('kaupang-attribute-suite', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 0);

// Plugin activation hook
register_activation_hook(__FILE__, 'kaupang_attribute_suite_activate');
function kaupang_attribute_suite_activate() {
    // Schedule rewrite rules flush for next init
    update_option('kaupang_attribute_suite_flush_rewrite_rules', true);
    // Orphaned rows from the pre-rename plugin (woocommerce-rich-attribute-suite).
    delete_option('wc_ras_rewrite_version');
    delete_option('wc_ras_flush_rewrite_rules');
}

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'kaupang_attribute_suite_deactivate');
function kaupang_attribute_suite_deactivate() {
    // Flush rewrite rules on deactivation
    flush_rewrite_rules();
}
