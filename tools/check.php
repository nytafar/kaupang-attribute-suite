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
// #6: rules as a flush would write them now. The taxonomies register before the CPT, so its catch-all
// (`opprinnelser/[^/]+/([^/]+)/?$`, an attachment) can't take /opprinnelser/land/<country>/.
$kas_fresh_rules = $GLOBALS['wp_rewrite']->rewrite_rules();
$kas_route = static function ($path) use ($kas_fresh_rules) {
    foreach ($kas_fresh_rules as $match => $query) {
        if (preg_match('#^' . $match . '#', $path)) {
            return $query;
        }
    }
    return '';
};
kas_check('/opprinnelser/land/<country>/ routes to origin_country, not the CPT (#6)', strpos($kas_route('opprinnelser/land/peru/'), 'index.php?origin_country=') === 0, $kas_route('opprinnelser/land/peru/'));
kas_check('/opprinnelser/sertifiseringer/<term>/ routes to certification', strpos($kas_route('opprinnelser/sertifiseringer/organic/'), 'index.php?certification=') === 0, $kas_route('opprinnelser/sertifiseringer/organic/'));
kas_check('/opprinnelser/<slug>/ still routes to the attribute page', strpos($kas_route('opprinnelser/peru-qori-inti/'), 'index.php?attribute_page=') === 0, $kas_route('opprinnelser/peru-qori-inti/'));
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

// ── Origins: status, canonical URL, index, strip, purge (kaupang-docs origins-architecture-2026-11-20.md §9) ──
if (taxonomy_exists('pa_opprinnelse')) {
    kaupang_attribute_suite_flush_origin_sales();
    $kas_sales  = kaupang_attribute_suite_origin_sales();
    $kas_terms  = get_terms(array('taxonomy' => 'pa_opprinnelse', 'hide_empty' => false));
    $kas_by     = array('active' => array(), 'former' => array(), 'unsold' => array());
    $kas_wrong  = array();
    foreach ($kas_terms as $kas_term) {
        $kas_status = kaupang_attribute_suite_origin_status($kas_term->slug);
        $kas_want   = kaupang_attribute_suite_origin_product_count($kas_term->slug) > 0 ? 'active' : (isset($kas_sales[$kas_term->slug]) ? 'former' : 'unsold');
        if ($kas_status['state'] !== $kas_want) {
            $kas_wrong[] = "{$kas_term->slug}={$kas_status['state']}";
        }
        $kas_by[$kas_status['state']][] = $kas_term;
    }
    kas_check('origin status follows the rule for every term (active = products, former = sold before)', $kas_wrong === array(), implode(', ', $kas_wrong));
    $kas_unsold = kaupang_attribute_suite_origin_status('kaupang-check-none');
    kas_check('unsold: an origin with no products and no sales', 'unsold' === $kas_unsold['state'] && null === $kas_unsold['last_sold']);
    if ($kas_by['former']) {
        $kas_f = kaupang_attribute_suite_origin_status($kas_by['former'][0]->slug);
        kas_check('former: ' . $kas_by['former'][0]->slug . ' has a sold period (' . kaupang_attribute_suite_format_sold_period($kas_f) . ')',
            $kas_f['first_sold'] && $kas_f['last_sold'] >= $kas_f['first_sold'] && kaupang_attribute_suite_format_sold_period($kas_f) !== '');
    } else {
        echo "  skip former origin: none on this site\n";
    }
    foreach (kaupang_attribute_suite_origin_previous_slug_map() as $kas_old => $kas_new) {
        kas_check("previous slug {$kas_old} folds into {$kas_new}", !isset($kas_sales[$kas_old]));
    }
    $kas_page_of = static function ($term) {
        $page = kaupang_attribute_suite_get_cached_attribute_page($term->slug);
        return $page && 'publish' === $page->post_status ? $page : null;
    };

    // Term archive: 301 to a published page; former without one → index; otherwise stays.
    $kas_cases = array('page' => null, 'former-nopage' => null, 'active-nopage' => null);
    foreach ($kas_terms as $kas_term) {
        $kas_state = kaupang_attribute_suite_origin_status($kas_term->slug)['state'];
        $kas_key   = $kas_page_of($kas_term) ? 'page' : $kas_state . '-nopage';
        if (array_key_exists($kas_key, $kas_cases) && null === $kas_cases[$kas_key]) {
            $kas_cases[$kas_key] = $kas_term;
        }
    }
    $kas_want = array(
        'page'          => static function ($t) use ($kas_page_of) { return get_permalink($kas_page_of($t)); },
        'former-nopage' => static function () { return get_post_type_archive_link('attribute_page'); },
        'active-nopage' => static function () { return ''; },
    );
    foreach ($kas_cases as $kas_key => $kas_term) {
        if (!$kas_term) {
            echo "  skip term archive redirect ({$kas_key}): no such origin here\n";
            continue;
        }
        $kas_url = kaupang_attribute_suite_origin_term_redirect_url($kas_term);
        kas_check("term archive {$kas_term->slug} ({$kas_key}) → " . ($kas_url === '' ? 'stays (200)' : $kas_url), $kas_url === (string) $kas_want[$kas_key]($kas_term));
    }
    kas_check('term archive redirect on template_redirect', has_action('template_redirect', 'kaupang_attribute_suite_origin_term_redirect') !== false);

    // Index: active as cards, former newest first, unsold never.
    $kas_index  = kaupang_attribute_suite_origin_index();
    $kas_slugs  = static function ($entries) { return array_map(static function ($e) { return $e['term']->slug; }, $entries); };
    $kas_bySlug = static function ($terms) { return array_map(static function ($t) { return $t->slug; }, $terms); };
    kas_check('index «I sortimentet» = the active origins (' . count($kas_index['active']) . ')', $kas_slugs($kas_index['active']) === $kas_bySlug($kas_by['active']));
    $kas_fs = $kas_slugs($kas_index['former']);
    sort($kas_fs);
    $kas_fw = $kas_bySlug($kas_by['former']);
    sort($kas_fw);
    $kas_last = array_map(static function ($e) { return $e['status']['last_sold']; }, $kas_index['former']);
    $kas_sorted = $kas_last;
    rsort($kas_sorted);
    kas_check('index «Tidligere» = the former origins, newest last sale first (' . count($kas_index['former']) . ')', $kas_fs === $kas_fw && $kas_last === $kas_sorted);
    kas_check('index never lists an unsold origin', !array_intersect($kas_bySlug($kas_by['unsold']), array_merge($kas_slugs($kas_index['active']), $kas_fs)));
    kas_check('index title is «Opprinnelser» (filterable)', apply_filters('post_type_archive_title', 'x', 'attribute_page') === __('Opprinnelser', 'kaupang-attribute-suite'));

    // Strip: exactly the counted products, each linking with the origin preselected on variable products.
    if ($kas_by['active']) {
        $kas_slug  = $kas_by['active'][0]->slug;
        $kas_items = kaupang_attribute_suite_origin_strip_items($kas_slug);
        $kas_links = array_filter($kas_items, static function ($i) use ($kas_slug) {
            return !$i['product'] instanceof WC_Product_Variable || strpos($i['url'], 'attribute_pa_opprinnelse=' . $kas_slug) !== false;
        });
        kas_check("strip for {$kas_slug} lists exactly origin_product_count() products (" . count($kas_items) . ')',
            count($kas_items) === kaupang_attribute_suite_origin_product_count($kas_slug) && count($kas_links) === count($kas_items));
        $kas_hidden = array_filter($kas_items, static function ($i) { return 'hidden' === $i['product']->get_catalog_visibility() || 'search' === $i['product']->get_catalog_visibility(); });
        kas_check('strip and count leave catalog-hidden products out', $kas_hidden === array());
        ob_start();
        kaupang_attribute_suite_render_origin_products(array('slug' => $kas_slug, 'status' => kaupang_attribute_suite_origin_status($kas_slug)));
        $kas_html = ob_get_clean();
        kas_check('active origin page renders the product strip', substr_count($kas_html, '<li>') === count($kas_items) && strpos($kas_html, 'wc-ras-origin-products') !== false);
    }
    if ($kas_by['former']) {
        ob_start();
        kaupang_attribute_suite_render_origin_products(array('slug' => $kas_by['former'][0]->slug, 'status' => kaupang_attribute_suite_origin_status($kas_by['former'][0]->slug)));
        $kas_html = ob_get_clean();
        kas_check('former origin page renders the status line, no strip', strpos($kas_html, 'wc-ras-origin-status former') !== false && strpos($kas_html, '<li>') === false);
    }
    ob_start();
    kaupang_attribute_suite_render_origin_products(array('slug' => 'kaupang-check-none', 'status' => $kas_unsold));
    kas_check('unsold origin page renders nothing there', trim(ob_get_clean()) === '');
    kas_check('strip on origin_single_before_content', has_action('kaupang/attribute-suite/origin_single_before_content', 'kaupang_attribute_suite_render_origin_products') !== false);

    // Page cache: a count flush queues the origin; its page, term archive and the index purge at shutdown.
    if ($kas_cases['page']) {
        kaupang_attribute_suite_flush_origin_counts(0, array($kas_cases['page']->slug));
        $kas_urls = kaupang_attribute_suite_origin_purge_urls(array($kas_cases['page']->slug));
        kas_check('count flush queues a purge at shutdown of page + index' . (is_taxonomy_viewable('pa_opprinnelse') ? ' + term archive' : ''),
            has_action('shutdown', 'kaupang_attribute_suite_purge_origin_pages') !== false
            && in_array(get_permalink($kas_page_of($kas_cases['page'])), $kas_urls, true)
            && in_array(get_post_type_archive_link('attribute_page'), $kas_urls, true)
            && (!is_taxonomy_viewable('pa_opprinnelse') || in_array(get_term_link($kas_cases['page']), $kas_urls, true)));
        $GLOBALS['kaupang_attribute_suite_purge_queue'] = array(); // the check purges nothing
    }

    // Previous slugs: a rename records the old slug, renaming back takes it off. Writes a term: not on production.
    if ('production' === wp_get_environment_type()) {
        echo "  skip rename fixture: environment is production (it writes a term)\n";
    } else {
        $kas_fixture = wp_insert_term('kaupang check origin', 'pa_opprinnelse', array('slug' => 'kaupang-check-origin-a'));
        if (is_wp_error($kas_fixture)) {
            kas_check('rename fixture term created', false, $kas_fixture->get_error_message());
        } else {
            $kas_id = (int) $kas_fixture['term_id'];
            wp_update_term($kas_id, 'pa_opprinnelse', array('slug' => 'kaupang-check-origin-b'));
            $kas_after = get_term_meta($kas_id, 'previous_slugs', false);
            wp_update_term($kas_id, 'pa_opprinnelse', array('slug' => 'kaupang-check-origin-a'));
            $kas_back = get_term_meta($kas_id, 'previous_slugs', false);
            wp_delete_term($kas_id, 'pa_opprinnelse');
            kas_check('rename records the old slug in previous_slugs', $kas_after === array('kaupang-check-origin-a'), wp_json_encode($kas_after));
            kas_check('renaming back takes the slug off it again (keeps the other)', $kas_back === array('kaupang-check-origin-b'), wp_json_encode($kas_back));
        }
    }
} else {
    echo "  skip origins: pa_opprinnelse doesn't exist here\n";
}

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
