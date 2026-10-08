<?php
/**
 * Check: archived (private) variations. Exits non-zero on failure.
 * Builds a draft fixture product (pa_mengde + a custom "Farge"), flips variation statuses, and deletes it again.
 * Staging/dev only — it writes products.
 * Run: gp wp <site> eval-file wp-content/plugins/kaupang-attribute-suite/tools/check-archived-variations.php
 */

$fail  = 0;
$check = function ($ok, $msg) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $msg . "\n";
    $fail |= !$ok;
};

if (!function_exists('kaupang_attribute_suite_archived_variation_allowed_values')) {
    echo "FAIL archived variations not loaded (plugin inactive or enable_archived_variations off)\n";
    exit(1);
}

$tax_id = wc_attribute_taxonomy_id_by_name('pa_mengde');
$slugs  = array('288g', '1400g', '2000g');
$terms  = array();
foreach ($slugs as $slug) {
    $term = get_term_by('slug', $slug, 'pa_mengde');
    $terms[$slug] = $term ? $term : null;
}
if (!$tax_id || in_array(null, $terms, true)) {
    echo "FAIL fixture needs pa_mengde with terms 288g, 1400g, 2000g\n";
    exit(1);
}

$ids = array();
try {
    $product = new WC_Product_Variable();
    $product->set_name('kaupang check-archived-variations fixture');
    $product->set_status('draft');
    $mengde = new WC_Product_Attribute();
    $mengde->set_id($tax_id);
    $mengde->set_name('pa_mengde');
    $mengde->set_options(wp_list_pluck(array_values($terms), 'term_id'));
    $mengde->set_visible(true);
    $mengde->set_variation(true);
    $farge = new WC_Product_Attribute();
    $farge->set_name('Farge');
    $farge->set_options(array('Rød', 'Blå', 'Grønn'));
    $farge->set_visible(true);
    $farge->set_variation(true);
    $product->set_attributes(array($mengde, $farge));
    $parent_id = $product->save();
    $ids[]     = $parent_id;

    $make = function ($attrs, $status) use ($parent_id, &$ids) {
        $v = new WC_Product_Variation();
        $v->set_parent_id($parent_id);
        $v->set_attributes($attrs);
        $v->set_regular_price('10');
        $v->set_status($status);
        $ids[] = $v->save();
        return $v;
    };
    $v1 = $make(array('pa_mengde' => '288g', 'farge' => 'Rød'), 'publish');
    $v2 = $make(array('pa_mengde' => '1400g', 'farge' => ''), 'publish'); // "Any Farge"
    $v3 = $make(array('pa_mengde' => '2000g', 'farge' => 'Blå'), 'private'); // archived
    WC_Product_Variable::sync($parent_id);

    $fresh = function () use ($parent_id) {
        clean_post_cache($parent_id);
        return wc_get_product($parent_id);
    };
    $dropdown = function ($attribute) use ($fresh) {
        $product = $fresh();
        $all     = $product->get_variation_attributes();
        ob_start();
        wc_dropdown_variation_attribute_options(array('options' => $all[$attribute], 'attribute' => $attribute, 'product' => $product));
        return ob_get_clean();
    };
    $table = function () use ($fresh) {
        ob_start();
        wc_display_product_attributes($fresh());
        return ob_get_clean();
    };

    // 1. One archived variation: its own value goes, values with a published or "any" variation stay.
    $html = $dropdown('pa_mengde');
    $check(strpos($html, 'value="2000g"') === false, 'archived-only option 2000g removed from the select');
    $check(strpos($html, 'value="288g"') !== false && strpos($html, 'value="1400g"') !== false, 'options with a published variation (288g, 1400g) kept');
    if (strpos($html, 'ousia-tile-input') !== false) {
        $check(substr_count($html, 'ousia-tile-input') === 2, 'Ousia tiles follow the filtered options (2 tiles)');
    }
    $html = $dropdown('Farge');
    $check(strpos($html, 'value="Blå"') !== false, 'Blå kept: a published variation has "any" Farge');
    $out = $table();
    $check(strpos($out, '2kg') === false && strpos($out, '288g') !== false, 'Additional information drops 2kg, keeps 288g');
    $check(strpos($out, 'Blå') !== false, 'Additional information keeps Blå ("any")');

    // 2. Archive the "any" variation too: Farge is now filtered to what v1 uses.
    $v2->set_status('private');
    $v2->save();
    $html = $dropdown('Farge');
    $check(strpos($html, 'value="Blå"') === false && strpos($html, 'value="Grønn"') === false, 'without the "any" variation, archived-only Farge values removed');
    $check(strpos($html, 'value="Rød"') !== false, 'Rød kept (published)');
    $out = $table();
    $check(strpos($out, 'Grønn') === false && strpos($out, 'Rød') !== false, 'Additional information Farge row filtered to Rød');

    // 3. Re-enable: everything back, nothing was unassigned from the parent.
    $v2->set_status('publish');
    $v2->save();
    $v3->set_status('publish');
    $v3->save();
    $html = $dropdown('pa_mengde');
    $check(strpos($html, 'value="2000g"') !== false, 're-enabled variation brings 2000g back');
    $check(count(wc_get_product_terms($parent_id, 'pa_mengde', array('fields' => 'slugs'))) === 3, 'parent keeps all three pa_mengde terms');
    $v3->set_status('private');
    $v3->save();

    // 3b. Attribute term archives list only products active for the term (run as the main query, like /opprinnelse/<slug>/).
    $simple = new WC_Product_Simple();
    $simple->set_name('kaupang check-archived-variations simple fixture');
    $simple->set_status('draft');
    $simple_attr = new WC_Product_Attribute();
    $simple_attr->set_id($tax_id);
    $simple_attr->set_name('pa_mengde');
    $simple_attr->set_options(array($terms['2000g']->term_id));
    $simple_attr->set_visible(true);
    $simple->set_attributes(array($simple_attr));
    $simple_id = $simple->save();
    $ids[]     = $simple_id;

    $archive = function ($taxonomy, $slug, $statuses) {
        global $wp_the_query, $wp_query;
        $saved        = array($wp_the_query, $wp_query);
        $wp_the_query = $wp_query = new WP_Query();
        // Woo's main product query forces 'publish'; the fixtures are drafts so customers never see them.
        $status = function ($query) use ($statuses) {
            $query->set('post_status', $statuses);
        };
        add_action('pre_get_posts', $status, PHP_INT_MAX);
        // tax_query, not the `pa_*` query var: that only exists while the attribute has "Enable archives" on.
        $found = $wp_query->query(array('tax_query' => array(array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $slug)), 'post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => -1));
        remove_action('pre_get_posts', $status, PHP_INT_MAX);
        list($wp_the_query, $wp_query) = $saved;
        return array_map('intval', $found);
    };
    $drafts = array('draft');
    $check(!in_array($parent_id, $archive('pa_mengde', '2000g', $drafts), true), 'term archive 2000g: variable product with only an archived 2000g variation not listed');
    $check(in_array($simple_id, $archive('pa_mengde', '2000g', $drafts), true), 'term archive 2000g: simple product with the term still listed');
    $check(in_array($parent_id, $archive('pa_mengde', '288g', $drafts), true), 'term archive 288g: variable product with a published 288g variation listed');
    update_post_meta($v2->get_id(), 'attribute_pa_mengde', ''); // "Any Mengde"
    $check(in_array($parent_id, $archive('pa_mengde', '2000g', $drafts), true), 'term archive 2000g: listed once a published variation has "any" Mengde');
    update_post_meta($v2->get_id(), 'attribute_pa_mengde', '1400g');

    // The origin count helper agrees with what each origin archive lists (live data, read-only).
    foreach (get_terms(array('taxonomy' => 'pa_opprinnelse', 'hide_empty' => false)) as $origin) {
        wp_cache_delete('origin_active_product_count_' . md5($origin->slug), 'kaupang_attribute_suite_origin');
        $listed  = count($archive('pa_opprinnelse', $origin->slug, array('publish')));
        $counted = kaupang_attribute_suite_origin_product_count($origin->slug);
        $check($listed === $counted, "origin {$origin->slug}: count {$counted} = archive {$listed}");
    }

    // Count cache follows a deletion without a manual flush. Needs a *published* product (the count is publish-only):
    // catalog-hidden, on an origin with no products, alive for a moment only.
    $origin_slug = 'peru-nativo-blanco';
    $origin_term = get_term_by('slug', $origin_slug, 'pa_opprinnelse');
    $baseline    = $origin_term ? kaupang_attribute_suite_origin_product_count($origin_slug) : -1;
    if ('production' === wp_get_environment_type()) {
        // A published product, however briefly, reaches Fiken, feeds and webhooks on a live store.
        echo "skip count-cache fixture: environment is production (it publishes a product)\n";
    } elseif ($origin_term && 0 === $baseline) {
        $live = new WC_Product_Variable();
        $live->set_name('kaupang check-archived-variations count fixture');
        $live->set_catalog_visibility('hidden');
        $origin_attr = new WC_Product_Attribute();
        $origin_attr->set_id(wc_attribute_taxonomy_id_by_name('pa_opprinnelse'));
        $origin_attr->set_name('pa_opprinnelse');
        $origin_attr->set_options(array($origin_term->term_id));
        $origin_attr->set_variation(true);
        $live->set_attributes(array($origin_attr));
        $live->set_status('draft');
        $ids[] = $live_id = $live->save();
        $only  = new WC_Product_Variation();
        $only->set_parent_id($live_id);
        $only->set_attributes(array('pa_opprinnelse' => $origin_slug));
        $only->set_regular_price('10');
        $ids[] = $only->save();
        $live = wc_get_product($live_id);
        $live->set_status('publish');
        $live->save();
        $before = kaupang_attribute_suite_origin_product_count($origin_slug); // caches 1
        $only->delete(true);
        $after = kaupang_attribute_suite_origin_product_count($origin_slug);
        wp_delete_post($live_id, true);
        $check(1 === $before && 0 === $after, "origin count drops when its only published variation is deleted ({$before} → {$after}, no manual flush)");
    } else {
        $check(false, "count-cache fixture needs pa_opprinnelse '{$origin_slug}' with 0 products (got {$baseline})");
    }

    // 4. Admin panel query: published only by default, archived on the toggle, nothing else narrowed.
    $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
    wp_set_current_user($admins ? (int) $admins[0] : 0);
    $panel = Kaupang_Attribute_Suite_Archived_Variations::instance();
    $panel->register_admin_hooks(); // is_admin() is false under WP-CLI
    // Loaded by wp-admin, not WP-CLI; the variation rows need them.
    require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
    require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
    $show  = Kaupang_Attribute_Suite_Archived_Variations::SHOW_PARAM;

    $count = $panel->filter_variations_count(3, $parent_id);
    $check(2 === $count, "panel count = published only (got {$count}, want 2)");

    // Drive Woo's real AJAX handlers (WC_AJAX::load_variations / bulk_edit_variations): if Woo changes their
    // queries so the narrowing no longer matches, the assertions below fail. wp_die() is turned into an exception.
    $ajax = function ($action, array $post) use (&$fail) {
        $_POST = $_REQUEST = $post;
        $die   = function () {
            return function () {
                throw new RuntimeException('wp_die');
            };
        };
        add_filter('wp_die_ajax_handler', $die, PHP_INT_MAX);
        add_filter('wp_die_handler', $die, PHP_INT_MAX);
        $level = ob_get_level();
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (RuntimeException $e) {
            // wp_die() — the handler finished.
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $out = '';
        while (ob_get_level() > $level) {
            $out = ob_get_clean() . $out;
        }
        remove_filter('wp_die_ajax_handler', $die, PHP_INT_MAX);
        remove_filter('wp_die_handler', $die, PHP_INT_MAX);
        $_POST = $_REQUEST = array();
        if (isset($error)) {
            echo "FAIL {$action} threw: {$error}\n";
            $fail = 1;
        }
        return $out;
    };
    $load = function ($show_archived) use ($ajax, $parent_id, $show) {
        $post = array('security' => wp_create_nonce('load-variations'), 'product_id' => $parent_id, 'per_page' => 15, 'page' => 1);
        if ($show_archived) {
            $post[$show] = '1';
        }
        preg_match_all('/name="variable_post_id\[\d+\]" value="(\d+)"/', $ajax('woocommerce_load_variations', $post), $m);
        $ids = array_map('intval', $m[1]);
        sort($ids);
        return $ids;
    };
    $bulk = function ($action, $show_archived, $data = array()) use ($ajax, $parent_id, $show) {
        $post = array('security' => wp_create_nonce('bulk-edit-variations'), 'product_id' => $parent_id, 'bulk_action' => $action, 'data' => $data);
        if ($show_archived) {
            $post[$show] = '1';
        }
        $ajax('woocommerce_bulk_edit_variations', $post);
    };
    $status = function ($variation) {
        clean_post_cache($variation->get_id());
        return (string) get_post_status($variation->get_id());
    };
    $set = function (array $map) {
        foreach ($map as $s => $variations) {
            foreach ($variations as $variation) {
                $variation = wc_get_product($variation->get_id());
                $variation->set_status($s);
                $variation->save();
            }
        }
    };
    $published = array($v1->get_id(), $v2->get_id());
    sort($published);
    $all = array_merge($published, array($v3->get_id()));
    sort($all);

    $check(has_action('wp_ajax_woocommerce_load_variations', array('WC_AJAX', 'load_variations')) !== false
        && has_action('wp_ajax_woocommerce_bulk_edit_variations', array('WC_AJAX', 'bulk_edit_variations')) !== false, 'Woo AJAX handlers registered');
    $check($load(false) === $published, 'load_variations (real handler) hides the archived variation by default');
    $check($load(true) === $all, 'toggle on: load_variations lists the archived variation');
    $load(false);
    $after = wc_get_products(array('type' => 'variation', 'parent' => $parent_id, 'status' => array('private', 'publish'), 'limit' => -1, 'return' => 'ids'));
    $check(count($after) === 3, 'narrowing is one-shot: a later wc_get_products() in the request sees all 3');

    $bulk('toggle_enabled', false);
    $check('private' === $status($v3), 'bulk "Toggle Enabled" with archived hidden leaves the archived variation archived');
    $check('private' === $status($v1) && 'private' === $status($v2), '…and toggles the listed ones');
    $set(array('publish' => array($v1, $v2)));

    $bulk('toggle_enabled', true);
    $check('publish' === $status($v3), 'bulk "Toggle Enabled" with archived shown re-enables it');
    $set(array('publish' => array($v1, $v2), 'private' => array($v3)));

    $bulk('delete_all', false, array('allowed' => 'true'));
    $check('private' === $status($v3), 'bulk "Delete all" with archived hidden keeps the archived variation');
    $check(!get_post($v1->get_id()) && !get_post($v2->get_id()), '…and deletes the listed ones');
    $_POST = array();

    $check(array_map('intval', $fresh()->get_children()) === array($v3->get_id()), 'get_children() reflects the survivor (nothing filtered globally)');
} finally {
    foreach (array_reverse($ids) as $id) {
        wp_delete_post($id, true);
    }
    wc_delete_product_transients(isset($parent_id) ? $parent_id : 0);
    $left = isset($parent_id) ? get_posts(array('post_parent' => $parent_id, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids')) : array();
    $check(isset($parent_id) && !get_post($parent_id) && empty($left), 'fixture deleted');
}

exit($fail);
