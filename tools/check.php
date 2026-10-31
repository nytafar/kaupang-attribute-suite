<?php
/**
 * Runnable check for Kaupang Attribute Suite: loading (README "Load map"), registration, cross-context hooks, the
 * admin foreign-screen rule and, when kaupang-assets is active, the storefront budget. Read-only: its one write
 * (the autoload migration) runs inside a rolled-back transaction. Exits non-zero on failure.
 *
 *   sudo -u <sysuser> wp --path=/var/www/<site>/htdocs \
 *     eval-file wp-content/plugins/kaupang-attribute-suite/tools/check.php
 *
 * Feature checks stay separate: tools/check-attribute-archives.php, check-gtin-links.php, check-archived-variations.php, check-origin-schema.php
 * (staging only, writes a fixture product).
 *
 * No declare(strict_types=1): `wp eval-file` evals the source. $GLOBALS, not `global`: eval-file runs inside a method.
 */

PHP_SAPI === 'cli' || exit;
defined('ABSPATH') || exit;

$GLOBALS['kas_check_fails'] = 0;
$GLOBALS['kas_check_count'] = 0;

function kas_check($name, $ok, $detail = '') {
    $GLOBALS['kas_check_count']++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . (!$ok && $detail !== '' ? " -- {$detail}" : '') . "\n";
    $GLOBALS['kas_check_fails'] += $ok ? 0 : 1;
}

/** Plugin files included so far, relative to the plugin dir. */
function kas_check_included() {
    $dir = KAUPANG_ATTRIBUTE_SUITE_DIR;
    $out = array();
    foreach (get_included_files() as $file) {
        if (strpos($file, $dir) === 0) {
            $out[] = substr($file, strlen($dir));
        }
    }
    return $out;
}

/** Callbacks on a hook that belong to this plugin (kaupang_attribute_suite_* functions, Kaupang_Attribute_Suite_* classes). */
function kas_check_own_callbacks($hook) {
    $own = array();
    foreach (isset($GLOBALS['wp_filter'][$hook]) ? $GLOBALS['wp_filter'][$hook]->callbacks : array() as $cbs) {
        foreach ($cbs as $cb) {
            $fn   = $cb['function'];
            $name = is_array($fn) ? (is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0]) : (is_string($fn) ? $fn : '');
            if (stripos($name, 'kaupang_attribute_suite') === 0) {
                $own[] = $fn;
            }
        }
    }
    return $own;
}

echo "Kaupang Attribute Suite check\n";

if (!function_exists('kaupang_attribute_suite_load')) {
    echo "  FAIL core not loaded (plugin inactive, WooCommerce missing, or < 2.4)\n";
    exit(1);
}

// ── Boot: core everywhere; CLI adds only the render/GTIN API. Runs first: later checks load modules. ──
$included = kas_check_included();
$on_demand = array('includes/variation-improvements.php', 'includes/inline-variation-description.php', 'includes/archived-variations.php',
    'includes/variation-gallery-transition.php', 'includes/admin-hooks.php', 'includes/origin/origin-admin.php', 'includes/origin/origin-admin-certifications.php',
    'includes/origin/origin-schema.php');
kas_check('core loaded', in_array('includes/core.php', $included, true));
kas_check('outside wp-admin, no variation/admin module at boot', array_intersect($on_demand, $included) === array(), implode(', ', array_intersect($on_demand, $included)));
kas_check('CLI has the public API kaupang-trace calls', function_exists('kaupang_attribute_suite_render_origin_sheet') && function_exists('kaupang_attribute_suite_build_origin_struct') && function_exists('kaupang_attribute_suite_load_template') && function_exists('kaupang_attribute_suite_gtin14'));
kas_check('storefront predicate false outside a product page', kaupang_attribute_suite_is_variable_product_page() === false);
kas_check('storefront gate on wp', has_action('wp', 'kaupang_attribute_suite_route_front') !== false);

// ── Registration on init, every context ──
kas_check('attribute_page CPT + origin taxonomies registered', post_type_exists('attribute_page') && taxonomy_exists('origin_country') && taxonomy_exists('certification'));
$meta = get_registered_meta_keys('post', 'attribute_page');
kas_check('origin meta registered for REST', isset($meta['taste_profile'], $meta['altitude'], $meta['region']) && !empty($meta['taste_profile']['show_in_rest']));
kas_check('meta sanitizer runs (core, any context)', sanitize_meta('taste_profile', array('acidity' => 12, 'nope' => 3), 'post', 'attribute_page') === array('acidity' => 10));
kas_check('certification term meta registered', isset(get_registered_meta_keys('term', 'certification')['icon_svg_id']));
$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered('kaupang-attribute-suite/origin-starter');
kas_check('starter pattern registered, content read from its file', $pattern && strpos((string) $pattern['content'], 'wp:columns') !== false);
do_action('rest_api_init');
kas_check('REST: attribute_page route registered', isset(rest_get_server()->get_routes()['/wp/v2/attribute_page']));

// ── Cross-context events: registered in every context ──
$pa = wc_get_attribute_taxonomy_names();
kas_check('term create → attribute page sync, per pa_* taxonomy', $pa && has_action('created_' . $pa[0], 'kaupang_attribute_suite_sync_attribute_pages_on_term_create') !== false);
kas_check('attribute page cache invalidation (save, delete, origin terms)', has_action('save_post_attribute_page', 'kaupang_attribute_suite_invalidate_attribute_page_cache') !== false
    && has_action('deleted_post', 'kaupang_attribute_suite_invalidate_attribute_page_cache') !== false
    && has_action('set_object_terms', 'kaupang_attribute_suite_invalidate_origin_terms_cache') !== false);
kas_check('term link saved on REST saves too', has_action('save_post_attribute_page', 'kaupang_attribute_suite_save_attribute_page_term_link') !== false);
kas_check('origin count flush on product/variation changes', has_action('woocommerce_update_product', 'kaupang_attribute_suite_flush_origin_counts') !== false
    && has_action('woocommerce_update_product_variation', 'kaupang_attribute_suite_flush_origin_counts') !== false);
kas_check('rewrite flush on wp_loaded; attribute changes queue it', has_action('wp_loaded', 'kaupang_attribute_suite_maybe_flush_rewrite_rules') !== false
    && has_action('woocommerce_attribute_updated', 'kaupang_attribute_suite_queue_rewrite_flush') !== false);
kas_check('version check never on a storefront hook', has_action('wp_loaded', 'kaupang_attribute_suite_check_rewrite_rules_version') === false
    && has_action('init', 'kaupang_attribute_suite_check_rewrite_rules_version') === false);

// ── Autoload: the version marker's migration, rolled back ──
$wpdb   = $GLOBALS['wpdb'];
$option = 'kaupang_attribute_suite_rewrite_version';
$flush_caches = static function () use ($option) {
    foreach (array($option, 'alloptions', 'notoptions', 'kaupang_attribute_suite_flush_rewrite_rules') as $key) {
        wp_cache_delete($key, 'options');
    }
};
$now = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
echo '  info ' . $option . ' autoload now: ' . var_export($now, true) . " (a pre-2.4 row moves off on the next wp-admin page load)\n";
$wpdb->query('START TRANSACTION');
$wpdb->replace($wpdb->options, array('option_name' => $option, 'option_value' => KAUPANG_ATTRIBUTE_SUITE_VERSION, 'autoload' => 'on'));
$flush_caches();
kaupang_attribute_suite_check_rewrite_rules_version();
$migrated = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
$wpdb->query('ROLLBACK');
$flush_caches();
kas_check('admin_init routine moves an autoloaded version row off autoload', in_array($migrated, array('off', 'no', 'auto-off'), true), (string) $migrated);

// ── Lazy loaders: the module loads on the hook and its callbacks run in the same pass ──
$terms = taxonomy_exists('pa_opprinnelse') && is_taxonomy_viewable('pa_opprinnelse')
    ? get_terms(array('taxonomy' => 'pa_opprinnelse', 'number' => 1, 'hide_empty' => false)) : array();
if ($terms && !is_wp_error($terms)) {
    // The storefront main query of an attribute term archive, routed as a request would be.
    $_SERVER['REQUEST_URI'] = (string) wp_parse_url(get_term_link($terms[0]), PHP_URL_PATH);
    unset($_SERVER['PATH_INFO']);
    $GLOBALS['wp']->query_vars = array();
    $GLOBALS['wp']->parse_request();
    $GLOBALS['wp']->query_posts();
    kas_check('archived: loads on an attribute term archive main query and filters it (' . $_SERVER['REQUEST_URI'] . ')',
        is_tax('pa_opprinnelse') && class_exists('Kaupang_Attribute_Suite_Archived_Variations', false) && strpos($GLOBALS['wp_query']->request, 'kasv') !== false);
} else {
    echo "  skip attribute term archive: pa_opprinnelse has no archives on this site\n";
}
$query = new WP_Query(array(
    'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
    'kaupang_attribute_suite_active_term' => array('pa_opprinnelse', 'kaupang-check-none'),
));
kas_check('archived: a query with the active-term var is filtered (origin product counts)', strpos($query->request, 'kasv') !== false);
kas_check('variations module still not loaded', !class_exists('Kaupang_Attribute_Suite_Variation_Improvements', false));

$fixture = null;
foreach (wc_get_products(array('type' => 'variable', 'status' => 'publish', 'limit' => 50)) as $candidate) {
    $attrs = $candidate->get_variation_attributes();
    if (!empty($attrs['pa_opprinnelse'])) {
        foreach ($candidate->get_children() as $child) {
            if (kaupang_attribute_suite_get_attribute_page_for_variation($child)) {
                $fixture = $candidate;
                break 2;
            }
        }
    }
}
if ($fixture) {
    // Same filter WC_AJAX::get_variation (wc-ajax=get_variation) and quick views run.
    $data   = $fixture->get_available_variations();
    $origin = array_filter($data, static function ($v) { return !empty($v['wc_ras_origin']['slug']); });
    kas_check('variations: woocommerce_available_variation loads the module', class_exists('Kaupang_Attribute_Suite_Variation_Improvements', false));
    kas_check('variations: wc_ras_origin struct on ' . $fixture->get_slug(), $origin !== array());
    if (apply_filters('kaupang/attribute-suite/enable_inline_variation_description', false)) {
        kas_check('variations: callbacks added mid-hook ran (inline flag, priority 15)', !empty($data[0]['kaupang_attribute_suite_inline_description']));
    }
    kas_check('variations: threshold keeps origin products client-side', apply_filters('woocommerce_ajax_variation_threshold', 1, $fixture) >= count($fixture->get_children()));
} else {
    echo "  skip variation data: no published variable product with a pa_opprinnelse attribute page\n";
}
kas_check('variation JS template swap only for the variation template', kaupang_attribute_suite_variation_template('x', 'cart/cart.php') === 'x'
    && (!apply_filters('kaupang/attribute-suite/enable_inline_variation_description', false)
        || kaupang_attribute_suite_variation_template('x', 'single-product/add-to-cart/variation.php') === KAUPANG_ATTRIBUTE_SUITE_DIR . 'templates/variation-no-description.php'));

// ── Admin: own screens only (Pattern E). CLI is not wp-admin: load the module by hand, call only this plugin's callbacks. ──
kaupang_attribute_suite_load('admin');
Kaupang_Attribute_Suite_Archived_Variations::instance()->register_admin_hooks();
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen('dashboard');
$GLOBALS['pagenow'] = 'index.php';
foreach (kas_check_own_callbacks('admin_enqueue_scripts') as $fn) {
    call_user_func($fn, 'index.php');
}
ob_start();
foreach (array('admin_head', 'admin_footer') as $hook) {
    foreach (kas_check_own_callbacks($hook) as $fn) {
        call_user_func($fn);
    }
}
$printed = ob_get_clean();
$leaked  = array_filter(array_merge(wp_scripts()->queue, wp_styles()->queue), static function ($h) {
    return strpos($h, 'kaupang-attribute-suite') === 0 || strpos($h, 'wc-ras') === 0;
});
kas_check('no admin assets on a foreign screen (index.php)', $leaked === array(), implode(', ', $leaked));
kas_check('no nonce or inline script on a foreign screen', trim($printed) === '', substr(trim($printed), 0, 80));
$GLOBALS['pagenow'] = 'edit-tags.php';
$_GET['taxonomy']   = 'pa_opprinnelse';
ob_start();
kaupang_attribute_suite_add_description_save_nonce();
$nonce = ob_get_clean();
unset($_GET['taxonomy']);
kas_check('description nonce prints on the pa_* term list', strpos($nonce, 'wcRasDescNonce') !== false);
kas_check('term-description AJAX handler registered with the admin module', has_action('wp_ajax_kaupang_attribute_suite_save_term_description') !== false);

// Storefront budget, measured by kaupang-assets' signed guest probe when it is there.
if (class_exists('WP_CLI') && is_array(WP_CLI::get_runner()->find_command_to_run(array('kaupang-assets', 'audit')))) {
    $audit = WP_CLI::runcommand('kaupang-assets audit --plugins=kaupang-attribute-suite --budget', array('return' => 'all', 'exit_error' => false));
    echo $audit->stdout . "\n";
    kas_check('kaupang-assets audit --budget', $audit->return_code === 0, trim($audit->stderr));
} else {
    echo "  skip storefront budget: `wp kaupang-assets audit` is not available here\n";
}

$n = $GLOBALS['kas_check_count'];
$f = $GLOBALS['kas_check_fails'];
echo $f === 0 ? "all {$n} passed\n" : "{$f} of {$n} failed\n";
if ($f > 0) {
    exit(1);
}
