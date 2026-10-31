<?php
/**
 * Check: origin schema (`countryOfOrigin` on the Product JSON-LD node). Exits non-zero on failure.
 * Builds fixture origins (pa_opprinnelse terms, their attribute pages, origin_country terms) and draft products, and
 * deletes them again. Staging/dev only — it writes products and terms.
 *
 *   sudo -u <sysuser> wp --path=/var/www/<site>/htdocs \
 *     eval-file wp-content/plugins/kaupang-attribute-suite/tools/check-origin-schema.php
 *
 * No declare(strict_types=1): `wp eval-file` evals the source.
 */

PHP_SAPI === 'cli' || exit;
defined('ABSPATH') || exit;

$fail  = 0;
$check = function ($ok, $msg) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $msg . "\n";
    $fail |= !$ok;
};

if (!function_exists('kaupang_attribute_suite_lazy_origin_schema')) {
    echo "FAIL core gate missing (plugin inactive or < 2.4)\n";
    exit(1);
}
if ('production' === wp_get_environment_type()) {
    echo "FAIL refusing to run on production (writes products and terms)\n";
    exit(1);
}
$tax_id = wc_attribute_taxonomy_id_by_name('pa_opprinnelse');
if (!$tax_id) {
    echo "FAIL fixture needs the pa_opprinnelse attribute\n";
    exit(1);
}

$woo = function ($product) {
    $sd = new WC_Structured_Data(); // fresh instance: its data accumulates
    $sd->generate_product_data($product);
    foreach ($sd->get_data() as $node) {
        if ('Product' === $node['@type']) {
            return $node;
        }
    }
    return array();
};
$rank_math = function ($product) {
    $GLOBALS['post'] = get_post($product->get_id());
    setup_postdata($GLOBALS['post']);
    $node = apply_filters('rank_math/snippet/rich_snippet_product_entity', array('@type' => 'Product'));
    wp_reset_postdata();
    return $node;
};

$sfx      = substr(md5((string) microtime(true)), 0, 6);
$ids      = array(); // products, children first on delete
$terms    = array(); // pa_opprinnelse slug => term_id
$pages    = array();
$country  = array(); // origin_country term ids
$flag_on  = function () {
    return true;
};

try {
    // Fixtures: origins a, b, c with countries X, Y, X; origin d with an attribute page but no country.
    foreach (array('X', 'Y') as $c) {
        $t           = wp_insert_term("Kas Land {$c} {$sfx}", 'origin_country');
        $country[$c] = $t['term_id'];
    }
    foreach (array('a' => 'X', 'b' => 'Y', 'c' => 'X', 'd' => null) as $o => $c) {
        $slug = "kas-schema-{$o}-{$sfx}";
        $t    = wp_insert_term("Kas schema {$o} {$sfx}", 'pa_opprinnelse', array('slug' => $slug));
        $terms[$o] = $t['term_id'];
        $page      = get_page_by_path($slug, OBJECT, 'attribute_page'); // auto-created on term create
        if (!$page) {
            $page = get_post(wp_insert_post(array('post_type' => 'attribute_page', 'post_name' => $slug, 'post_title' => $slug, 'post_status' => 'publish')));
        }
        $pages[] = $page->ID;
        if ($c) {
            wp_set_object_terms($page->ID, $country[$c], 'origin_country');
        }
    }
    $name = function ($c) use ($sfx) {
        return array('@type' => 'Country', 'name' => "Kas Land {$c} {$sfx}");
    };

    $origin_attr = function ($origins, $variation = false) use ($tax_id, $terms) {
        $a = new WC_Product_Attribute();
        $a->set_id($tax_id);
        $a->set_name('pa_opprinnelse');
        $a->set_options(array_map(function ($o) use ($terms) {
            return $terms[$o];
        }, $origins));
        $a->set_visible(true);
        $a->set_variation($variation);
        return $a;
    };
    $simple = function ($origins) use ($origin_attr, &$ids) {
        $p = new WC_Product_Simple();
        $p->set_name('kaupang check-origin-schema fixture');
        $p->set_status('draft');
        $p->set_regular_price('10');
        $p->set_attributes(array($origin_attr($origins)));
        $ids[] = $p->save();
        return wc_get_product($p->get_id());
    };

    $single   = $simple(array('a', 'c'));
    $multi    = $simple(array('a', 'b'));
    $partial  = $simple(array('a', 'd'));

    $variable = new WC_Product_Variable();
    $variable->set_name('kaupang check-origin-schema variable fixture');
    $variable->set_status('draft');
    $variable->set_attributes(array($origin_attr(array('a', 'd'), true)));
    $parent_id = $variable->save();
    $ids[]     = $parent_id;
    foreach (array('a' => 'publish', 'd' => 'private') as $o => $status) { // d: archived-only, unresolved
        $v = new WC_Product_Variation();
        $v->set_parent_id($parent_id);
        $v->set_attributes(array('pa_opprinnelse' => "kas-schema-{$o}-{$sfx}"));
        $v->set_regular_price('10');
        $v->set_status($status);
        array_unshift($ids, $v->save());
    }
    WC_Product_Variable::sync($parent_id);
    clean_post_cache($parent_id);
    $variable = wc_get_product($parent_id);

    // Variable with two published origins (countries X, Y): a variation URL selects one.
    $pick = new WC_Product_Variable();
    $pick->set_name('kaupang check-origin-schema variation-url fixture');
    $pick->set_status('draft');
    $pick->set_attributes(array($origin_attr(array('a', 'b'), true)));
    $pick_id = $pick->save();
    $ids[]   = $pick_id;
    foreach (array('a', 'b') as $o) {
        $v = new WC_Product_Variation();
        $v->set_parent_id($pick_id);
        $v->set_attributes(array('pa_opprinnelse' => "kas-schema-{$o}-{$sfx}"));
        $v->set_regular_price('10');
        $v->set_status('publish');
        array_unshift($ids, $v->save());
    }
    WC_Product_Variable::sync($pick_id);
    clean_post_cache($pick_id);
    $pick = wc_get_product($pick_id);

    // 1. Flag off (default): nothing loads, nothing changes.
    $check(!apply_filters('kaupang/attribute-suite/enable_origin_schema', false), 'flag off by default');
    $check(!isset($woo($single)['countryOfOrigin']), 'flag off: Woo Product node unchanged');
    $check($rank_math($single) === array('@type' => 'Product'), 'flag off: Rank Math entity unchanged');
    $check(!function_exists('kaupang_attribute_suite_origin_schema_add')
        && !in_array(KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/origin-schema.php', get_included_files(), true), 'flag off: module not loaded');

    // 2. Flag on.
    add_filter('kaupang/attribute-suite/enable_origin_schema', $flag_on);
    $node = $woo($single);
    $check(isset($node['countryOfOrigin']) && $node['countryOfOrigin'] === $name('X'), 'Woo: two origins, one country → single Country object');
    $check(function_exists('kaupang_attribute_suite_origin_schema_add'), 'flag on: module loaded by the gate');
    $node = $woo($multi);
    $check(isset($node['countryOfOrigin']) && $node['countryOfOrigin'] === array($name('X'), $name('Y')), 'Woo: two countries → array of Country objects');
    $check(!isset($woo($partial)['countryOfOrigin']), 'Woo: one unresolved origin → nothing emitted');
    $node = $woo($variable);
    $check(isset($node['countryOfOrigin']) && $node['countryOfOrigin'] === $name('X'), 'Woo: origin used only by an archived variation excluded');
    $node = $woo($pick);
    $check(isset($node['countryOfOrigin']) && $node['countryOfOrigin'] === array($name('X'), $name('Y')), 'Woo: product URL lists both variations\' countries');
    $_GET['attribute_pa_opprinnelse'] = "kas-schema-b-{$sfx}";
    $node = $woo($pick);
    $check(isset($node['inProductGroupWithID']), 'Woo: variation URL → Woo describes the selected variation');
    $check(isset($node['countryOfOrigin']) && $node['countryOfOrigin'] === $name('Y'), 'Woo: variation URL → only the selected variation\'s country');
    $_GET['attribute_pa_opprinnelse'] = "kas-schema-c-{$sfx}"; // matches no variation: Woo keeps the parent
    $node = $woo($pick);
    $check(!isset($node['inProductGroupWithID']) && isset($node['countryOfOrigin']) && $node['countryOfOrigin'] === array($name('X'), $name('Y')), 'Woo: unmatched variation args → parent\'s countries');
    unset($_GET['attribute_pa_opprinnelse']);

    $existing = array('@type' => 'Country', 'name' => 'Set by hand');
    $node     = apply_filters('woocommerce_structured_data_product', array('@type' => 'Product', 'countryOfOrigin' => $existing), $single);
    $check($node['countryOfOrigin'] === $existing, 'Woo: existing countryOfOrigin preserved');

    $node = $rank_math($multi);
    $check(isset($node['countryOfOrigin']) && $node['countryOfOrigin'] === array($name('X'), $name('Y')), 'Rank Math entity: countries from the current post');
    $GLOBALS['post'] = get_post($single->get_id());
    setup_postdata($GLOBALS['post']);
    $node = apply_filters('rank_math/snippet/rich_snippet_product_entity', array('@type' => 'Product', 'countryOfOrigin' => $existing));
    wp_reset_postdata();
    $check($node['countryOfOrigin'] === $existing, 'Rank Math entity: existing countryOfOrigin preserved');
    $check(!isset($rank_math($partial)['countryOfOrigin']), 'Rank Math entity: unresolved origin → nothing emitted');
} catch (Throwable $e) {
    $check(false, 'exception: ' . $e->getMessage());
} finally {
    unset($_GET['attribute_pa_opprinnelse']);
    remove_filter('kaupang/attribute-suite/enable_origin_schema', $flag_on);
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
    foreach ($pages as $id) {
        wp_delete_post($id, true);
    }
    foreach ($terms as $id) {
        wp_delete_term($id, 'pa_opprinnelse');
    }
    foreach ($country as $id) {
        wp_delete_term($id, 'origin_country');
    }
    $left = array_filter(array_map('get_post', array_merge($ids, $pages)));
    $left = array_merge($left, get_terms(array('taxonomy' => array('pa_opprinnelse', 'origin_country'), 'search' => $sfx, 'hide_empty' => false, 'fields' => 'ids')));
    $check(!$left, 'fixtures deleted');
}

exit($fail ? 1 : 0);
