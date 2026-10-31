<?php
/**
 * Origin schema: `countryOfOrigin` on the product's JSON-LD Product node.
 *
 * Owns (module `schema`, see core.php):
 *   - The country lookup: the product's offered `pa_opprinnelse` terms (minus options only archived variations use)
 *     → attribute_page (slug match) → its `origin_country` term.
 *   - Adding it to a Product node: WooCommerce's `woocommerce_structured_data_product` and, when Rank Math replaces
 *     Woo's markup, `rank_math/snippet/rich_snippet_product_entity`.
 *
 * The node's subject gets that subject's countries: on a variation URL (every `attribute_*` query arg set), where Woo
 * describes the one selected variation, only that variation's origin counts; Rank Math's entity always describes the
 * parent and its full offers, so it gets the parent's complete set.
 *
 * All or nothing: every offered origin must resolve to a country, else the node is left as is. A `countryOfOrigin`
 * already on the node (set in Rank Math, say) is never overwritten.
 *
 * Gated by `kaupang/attribute-suite/enable_origin_schema` (default false; read at hook time by the core gate, which
 * loads this file only when the flag is on).
 *
 * Check: tools/check-origin-schema.php
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * `countryOfOrigin` value for a product: one Country object, an array of them for several distinct countries, or null
 * when the product has no origins or any offered origin lacks an attribute page with an `origin_country` term.
 *
 * @param WC_Product|mixed $product   The product.
 * @param bool             $selected  Narrow to the variation the request selects (Woo's node), not the whole product.
 * @return array|null
 */
function kaupang_attribute_suite_origin_schema_countries($product, $selected = false) {
    if (!$product instanceof WC_Product) {
        return null;
    }

    $slugs = wc_get_product_terms($product->get_id(), 'pa_opprinnelse', array('fields' => 'slugs'));
    if ($product->is_type('variable')) {
        kaupang_attribute_suite_load('archived'); // already loaded on variable product pages (module `variations`)
        $allowed = kaupang_attribute_suite_archived_variation_allowed_values($product);
        if (isset($allowed['pa_opprinnelse'])) {
            $slugs = array_intersect($slugs, $allowed['pa_opprinnelse']);
        }
        $origin = $selected ? kaupang_attribute_suite_origin_schema_selected_origin($product) : null;
        if (null !== $origin) {
            $slugs = array_intersect($slugs, array($origin)); // a value outside the offered origins claims nothing
        }
    }
    if (!$slugs) {
        return null;
    }

    $countries = array();
    foreach ($slugs as $slug) {
        $page  = kaupang_attribute_suite_get_cached_attribute_page($slug);
        $terms = $page ? get_the_terms($page->ID, 'origin_country') : false;
        if (!$terms || is_wp_error($terms)) {
            return null; // no partial claims
        }
        $name             = reset($terms)->name; // first term, as the origin struct does
        $countries[$name] = array('@type' => 'Country', 'name' => $name);
    }

    return 1 === count($countries) ? reset($countries) : array_values($countries);
}

/**
 * The origin (`pa_opprinnelse` slug) of the single variation the request selects, as WooCommerce picks it for its
 * Product node: every variation attribute set in the query string, exactly one published variation matching, and it
 * has a price. Null when nothing is selected, the selection is ambiguous, or origin is not a variation attribute.
 *
 * ponytail: mirrors WC_Structured_Data::get_selected_variation() + count_matching_variations() (both private,
 * Woo 11.2); re-read them when Woo changes how it picks the variation.
 *
 * @param WC_Product_Variable $product Variable product.
 * @return string|null
 */
function kaupang_attribute_suite_origin_schema_selected_origin($product) {
    $attributes = $product->get_variation_attributes();
    if (empty($attributes['pa_opprinnelse']) || '' === $product->get_price()) {
        return null;
    }

    $match = array();
    foreach (array_keys($attributes) as $name) {
        $key = wc_variation_attribute_name($name);
        if (!isset($_GET[$key]) || '' === $_GET[$key]) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return null;
        }
        $match[$key] = wc_clean(wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    $variation_id = WC_Data_Store::load('product')->find_matching_product_variation($product, $match);
    if (!$variation_id) {
        return null;
    }

    $count = 0;
    foreach ($product->get_children() as $child_id) {
        $child = wc_get_product($child_id);
        if (!$child instanceof WC_Product_Variation || 'publish' !== $child->get_status()) {
            continue;
        }
        foreach ($child->get_variation_attributes() as $key => $value) {
            if ('' !== $value && (!isset($match[$key]) || $match[$key] !== $value)) {
                continue 2;
            }
        }
        $count++;
    }
    $variation = wc_get_product($variation_id);
    if (1 !== $count || !$variation instanceof WC_Product_Variation || '' === $variation->get_price()) {
        return null;
    }

    return $match['attribute_pa_opprinnelse'];
}

/**
 * Add `countryOfOrigin` to a Product node unless it already has one.
 *
 * @param array|mixed           $node    Product node (Woo markup or Rank Math entity).
 * @param WC_Product|mixed|null $product The product (Woo); null = the current post, the parent (Rank Math passes none).
 * @return array|mixed
 */
function kaupang_attribute_suite_origin_schema_add($node, $product = null) {
    if (!is_array($node) || isset($node['countryOfOrigin'])) {
        return $node;
    }
    $woo = null !== $product; // only Woo's node narrows to a selected variation
    if (!$woo) {
        $product = wc_get_product(get_the_ID());
    }

    $country = kaupang_attribute_suite_origin_schema_countries($product, $woo);
    if (null !== $country) {
        $node['countryOfOrigin'] = $country;
    }
    return $node;
}
