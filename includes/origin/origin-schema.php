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
 * @param WC_Product|mixed $product The product.
 * @return array|null
 */
function kaupang_attribute_suite_origin_schema_countries($product) {
    if (!$product instanceof WC_Product) {
        return null;
    }

    $slugs   = wc_get_product_terms($product->get_id(), 'pa_opprinnelse', array('fields' => 'slugs'));
    $allowed = kaupang_attribute_suite_archived_variation_allowed_values($product);
    if (isset($allowed['pa_opprinnelse'])) {
        $slugs = array_intersect($slugs, $allowed['pa_opprinnelse']);
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
 * Add `countryOfOrigin` to a Product node unless it already has one.
 *
 * @param array|mixed           $node    Product node (Woo markup or Rank Math entity).
 * @param WC_Product|mixed|null $product The product; null = the current post (Rank Math passes no product).
 * @return array|mixed
 */
function kaupang_attribute_suite_origin_schema_add($node, $product = null) {
    if (!is_array($node) || isset($node['countryOfOrigin'])) {
        return $node;
    }
    if (null === $product) {
        $product = wc_get_product(get_the_ID());
    }

    $country = kaupang_attribute_suite_origin_schema_countries($product);
    if (null !== $country) {
        $node['countryOfOrigin'] = $country;
    }
    return $node;
}
