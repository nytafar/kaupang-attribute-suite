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

    // 4. Admin panel query: published only by default, archived on the toggle, nothing else narrowed.
    $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
    wp_set_current_user($admins ? (int) $admins[0] : 0);
    $panel = Kaupang_Attribute_Suite_Archived_Variations::instance();
    $woo_args = array( // WC_AJAX::load_variations()
        'status'  => array('private', 'publish'),
        'type'    => 'variation',
        'parent'  => $parent_id,
        'limit'   => 15,
        'page'    => 1,
        'orderby' => array('menu_order' => 'ASC', 'ID' => 'DESC'),
        'return'  => 'ids',
    );

    $count = $panel->filter_variations_count(3, $parent_id);
    $check(2 === $count, "panel count = published only (got {$count}, want 2)");

    $_POST = array('product_id' => $parent_id);
    $panel->prepare_load_variations();
    $listed = wc_get_products($woo_args);
    $check(!in_array($v3->get_id(), $listed, true) && count($listed) === 2, 'load_variations hides the archived variation by default');
    $again = wc_get_products($woo_args);
    $check(count($again) === 3, 'narrowing is one-shot: a later identical query sees all 3');

    $_POST = array('product_id' => $parent_id, Kaupang_Attribute_Suite_Archived_Variations::SHOW_PARAM => '1');
    $panel->prepare_load_variations();
    $listed = wc_get_products($woo_args);
    $check(in_array($v3->get_id(), $listed, true) && count($listed) === 3, 'toggle on: load_variations lists the archived variation');

    $_POST = array('product_id' => $parent_id);
    $panel->prepare_load_variations();
    $other = wc_get_products(array_merge($woo_args, array('parent' => $parent_id + 999999)));
    $listed = wc_get_products($woo_args);
    $check(count($listed) === 2, 'a non-matching query in between does not consume or get the narrowing');
    $_POST = array();

    $check(count($fresh()->get_children()) === 3, 'get_children() still returns all 3 (object cache untouched)');
} finally {
    foreach (array_reverse($ids) as $id) {
        wp_delete_post($id, true);
    }
    wc_delete_product_transients(isset($parent_id) ? $parent_id : 0);
    $left = isset($parent_id) ? get_posts(array('post_parent' => $parent_id, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids')) : array();
    $check(isset($parent_id) && !get_post($parent_id) && empty($left), 'fixture deleted');
}

exit($fail);
