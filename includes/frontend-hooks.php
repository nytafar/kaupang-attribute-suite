<?php
/**
 * Frontend Hooks
 *
 * Owns:
 *   - Rewrite-flush gate (activation, version bump, attribute changes). Attribute
 *     term archives (/opprinnelse/{slug}/) are Woo's: on only for attributes with
 *     "Enable archives" ticked (Products → Attributes); this plugin never forces them.
 *   - Cached attribute_page lookup helper used by variation preload and
 *     everywhere we resolve a term slug to its rich-content CPT post.
 *   - Variation preload: injects the full `wc_ras_origin` struct into
 *     `woocommerce_available_variation` so blurb/modal can render without
 *     extra round-trips.
 *
 * Does NOT own (removed in 1.3.0):
 *   - template_include override for taxonomy archives — superseded by the
 *     CPT /opprinnelser/{slug}/ single template.
 *   - woocommerce_taxonomy_archive_description output — ditto.
 *   - variation-display.js enqueue — lives in variation-improvements.php.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Rewrite rules are rebuilt once after activation, a version bump, or a change to an attribute's
 * "Enable archives" (Woo registers pa_* taxonomies public with a rewrite only when that is on).
 *
 * On wp_loaded, not init: flush_rewrite_rules() before wp_loaded only queues itself for wp_loaded,
 * so clearing the flag at init lost the flush whenever the request exited in between — Jetpack's
 * dedicated sync request (/wp-json/jetpack/v4/sync/spawn-sync) exits at init 200, and was the first
 * PHP hit after activating on nyta.no. The flag is cleared only once the rules are written.
 */
function kaupang_attribute_suite_maybe_flush_rewrite_rules() {
    if (get_option('kaupang_attribute_suite_flush_rewrite_rules')) {
        flush_rewrite_rules();
        delete_option('kaupang_attribute_suite_flush_rewrite_rules');
    }
}
add_action('wp_loaded', 'kaupang_attribute_suite_maybe_flush_rewrite_rules', 99);

/**
 * Bump rewrite-flush on version change so new CPT/taxonomy rewrite rules
 * take effect without asking the admin to visit Permalinks manually.
 */
function kaupang_attribute_suite_check_rewrite_rules_version() {
    $current_version = get_option('kaupang_attribute_suite_rewrite_version', '0');

    if (version_compare($current_version, KAUPANG_ATTRIBUTE_SUITE_VERSION, '<')) {
        update_option('kaupang_attribute_suite_flush_rewrite_rules', true);
        update_option('kaupang_attribute_suite_rewrite_version', KAUPANG_ATTRIBUTE_SUITE_VERSION);
    }
}
add_action('wp_loaded', 'kaupang_attribute_suite_check_rewrite_rules_version', 98);

/**
 * Attribute added, edited ("Enable archives" toggled) or deleted: flush on the next request, which registers the
 * taxonomies with the new setting. Woo queues its own flush on WP-Cron, which waits for the system cron here.
 */
function kaupang_attribute_suite_queue_rewrite_flush() {
    update_option('kaupang_attribute_suite_flush_rewrite_rules', true);
}
add_action('woocommerce_attribute_added', 'kaupang_attribute_suite_queue_rewrite_flush');
add_action('woocommerce_attribute_updated', 'kaupang_attribute_suite_queue_rewrite_flush');
add_action('woocommerce_attribute_deleted', 'kaupang_attribute_suite_queue_rewrite_flush');

function kaupang_attribute_suite_force_client_side_variations_threshold($threshold, $product) {
    if (!$product instanceof WC_Product || !$product->is_type('variable')) {
        return $threshold;
    }

    $attrs = $product->get_variation_attributes();
    if (empty($attrs['pa_opprinnelse'])) {
        return $threshold;
    }

    return max((int) $threshold, count($product->get_children()));
}
add_filter('woocommerce_ajax_variation_threshold', 'kaupang_attribute_suite_force_client_side_variations_threshold', 99, 2);

/**
 * Get cached attribute_page by term slug.
 *
 * Cache group `kaupang_attribute_suite_attribute_page` — kept in sync with the legacy
 * `kaupang_attribute_suite_attribute_pages` group by kaupang_attribute_suite_invalidate_attribute_page_cache.
 *
 * @param string $slug The attribute term slug.
 * @return WP_Post|null The attribute page or null if not found.
 */
function kaupang_attribute_suite_get_cached_attribute_page($slug) {
    $key = 'attribute_page_' . md5($slug);
    $cached = wp_cache_get($key, 'kaupang_attribute_suite_attribute_page');

    if ($cached === false) {
        $post = get_page_by_path($slug, OBJECT, 'attribute_page');
        $cached = $post ? $post->ID : 0;
        wp_cache_set($key, $cached, 'kaupang_attribute_suite_attribute_page', HOUR_IN_SECONDS);
    }

    return $cached ? get_post($cached) : null;
}

/**
 * Build the full wc_ras_origin struct for an attribute_page post.
 *
 * Single source of truth for the payload shape consumed by blurb, modal,
 * archive cards, and single-page headers. Keeps null semantics explicit so
 * the render layer can apply "hide whole UI if minimum missing" uniformly.
 *
 * Expected example output:
 *   array(
 *     'name'                 => 'Qori Inti',
 *     'slug'                 => 'peru-qoriinti',
 *     'excerpt'              => 'Solens Bønn(e)',
 *     'permalink'            => 'https://site/opprinnelser/peru-qoriinti/',
 *     'country'              => 'Peru',
 *     'country_slug'         => 'peru',
 *     'country_flag_url'     => 'https://site/wp-content/plugins/.../flags/peru.svg' | null,
 *     'region'               => 'Cusco, Quillabamba',
 *     'region_label'         => 'Peru, Cusco, Quillabamba',
 *     'variety'              => 'Chuncho',
 *     'altitude'             => array('min' => 1200, 'max' => 1450, 'unit' => 'm') | null,
 *     'altitude_label'       => '1200–1450 moh',
 *     'taste_notes'          => 'Mango, krem, macadamia',
 *     'producer_type'        => 'cooperative' | null,
 *     'producer_type_label'  => 'Cooperative',
 *     'producer_count'       => 45 | null,
 *     'producer_count_label' => '45 produsenter',
 *     'fermentation_type'    => 'centralized' | null,
 *     'fermentation_days'    => 6 | null,
 *     'fermentation_value'   => 'Sentralisert · 6 dager',
 *     'fermentation_method'  => '3-tier cascade...' | null,
 *     'drying_method'        => 'Sun-dried on raised beds' | null,
 *     'taste_profile'        => array('acidity' => 6, 'sweetness' => null, ...),
 *     'certifications'       => array(array('slug' => 'organic', 'name' => 'Organic', 'icon_url' => '...'), ...),
 *     'featured_image_url'   => 'https://...' | null,
 *     'has_rich_page'        => true,
 *   )
 *
 * The `*_label` / `*_value` fields are derived (formatted + translated)
 * strings intended for client-side hydration. Null inputs produce empty
 * strings in the derived fields so consumers can treat them uniformly.
 *
 * @param WP_Post|null $page Attribute page post.
 * @return array|null Origin struct, or null if $page is null.
 */
function kaupang_attribute_suite_build_origin_struct($page) {
    if (!$page instanceof WP_Post) {
        return null;
    }

    $page_id = $page->ID;

    // Country via origin_country taxonomy
    $country_name = null;
    $country_slug = null;
    $country_terms = get_the_terms($page_id, 'origin_country');
    if ($country_terms && !is_wp_error($country_terms)) {
        $first = reset($country_terms);
        $country_name = $first->name;
        $country_slug = $first->slug;
    }

    // Flag URL (null if no SVG present)
    $country_flag_url = $country_slug && function_exists('kaupang_attribute_suite_country_flag_url')
        ? kaupang_attribute_suite_country_flag_url($country_slug)
        : null;
    if (empty($country_flag_url)) {
        $country_flag_url = null;
    }

    // Certifications → array of lightweight records
    $certifications = array();
    $cert_terms = get_the_terms($page_id, 'certification');
    if ($cert_terms && !is_wp_error($cert_terms)) {
        foreach ($cert_terms as $term) {
            $icon_id = (int) get_term_meta($term->term_id, 'icon_svg_id', true);
            $icon_url = $icon_id ? wp_get_attachment_url($icon_id) : null;
            if (!$icon_url) {
                $icon_url = null;
            }
            $certifications[] = array(
                'slug'     => $term->slug,
                'name'     => $term->name,
                'icon_url' => $icon_url,
            );
        }
    }

    // Featured image
    $thumb_id = get_post_thumbnail_id($page_id);
    $featured_image_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : null;
    if (!$featured_image_url) {
        $featured_image_url = null;
    }

    // Altitude — return null when neither min nor max is set
    $altitude = get_post_meta($page_id, 'altitude', true);
    if (!is_array($altitude) || (empty($altitude['min']) && empty($altitude['max']))) {
        $altitude = null;
    }

    // Helper to normalise empty string → null
    $nullable = function ($value) {
        if ($value === '' || $value === false || $value === null) {
            return null;
        }
        return $value;
    };

    $producer_count   = get_post_meta($page_id, 'producer_count', true);
    $fermentation_days = get_post_meta($page_id, 'fermentation_days', true);

    $taste_profile = get_post_meta($page_id, 'taste_profile', true);
    if (!is_array($taste_profile)) {
        $taste_profile = array();
    }

    $region_raw            = $nullable(get_post_meta($page_id, 'region', true));
    $variety_raw           = $nullable(get_post_meta($page_id, 'variety', true));
    $producer_type_raw     = $nullable(get_post_meta($page_id, 'producer_type', true));
    $producer_count_value  = ($producer_count === '' || $producer_count === false) ? null : (int) $producer_count;
    $fermentation_type_raw = $nullable(get_post_meta($page_id, 'fermentation_type', true));
    $fermentation_days_val = ($fermentation_days === '' || $fermentation_days === false) ? null : (int) $fermentation_days;

    $region_label = implode(', ', array_filter(array($country_name, $region_raw)));

    $altitude_label = function_exists('kaupang_attribute_suite_format_altitude') ? kaupang_attribute_suite_format_altitude($altitude) : '';

    $producer_types_map = function_exists('kaupang_attribute_suite_producer_types') ? kaupang_attribute_suite_producer_types() : array();
    $producer_type_label = ($producer_type_raw && isset($producer_types_map[$producer_type_raw]))
        ? $producer_types_map[$producer_type_raw]
        : '';

    $producer_count_label = ($producer_count_value !== null)
        ? sprintf(
            /* translators: %d: number of producers */
            _n('%d produsent', '%d produsenter', $producer_count_value, 'kaupang-attribute-suite'),
            $producer_count_value
        )
        : '';

    $fermentation_value = function_exists('kaupang_attribute_suite_format_fermentation_value')
        ? kaupang_attribute_suite_format_fermentation_value($fermentation_type_raw, $fermentation_days_val)
        : '';

    $struct = array(
        'name'                 => $page->post_title,
        'slug'                 => $page->post_name,
        // Explicit tagline only: an auto-generated excerpt is 55 words of
        // body copy plus the theme's read-more anchor, which the modal/card
        // would print as literal markup.
        'excerpt'              => has_excerpt($page) ? wp_strip_all_tags(get_the_excerpt($page)) : '',
        'permalink'            => get_permalink($page),

        'country'              => $country_name,
        'country_slug'         => $country_slug,
        'country_flag_url'     => $country_flag_url,
        'region'               => $region_raw,
        'region_label'         => $region_label,
        'variety'              => $variety_raw,
        'altitude'             => $altitude,
        'altitude_label'       => $altitude_label,
        'taste_notes'          => $nullable(get_post_meta($page_id, 'smak', true)),

        'producer_type'        => $producer_type_raw,
        'producer_type_label'  => $producer_type_label,
        'producer_count'       => $producer_count_value,
        'producer_count_label' => $producer_count_label,

        'fermentation_type'    => $fermentation_type_raw,
        'fermentation_days'    => $fermentation_days_val,
        'fermentation_value'   => $fermentation_value,
        'fermentation_method'  => $nullable(get_post_meta($page_id, 'fermentation_method', true)),
        'drying_method'        => $nullable(get_post_meta($page_id, 'drying_method', true)),

        'taste_profile'        => $taste_profile,
        'certifications'       => $certifications,
        'featured_image_url'   => $featured_image_url,

        'has_rich_page'        => true,
    );

    /**
     * Filter the origin struct before it goes out to the variation data
     * or template helpers. Use for adding plugin-specific fields.
     *
     * @param array   $struct The built origin struct.
     * @param WP_Post $page   The source attribute_page post.
     */
    return apply_filters('kaupang/attribute-suite/origin_struct', $struct, $page);
}

/**
 * Resolve the attribute_page CPT for a given variation's opprinnelse term.
 *
 * @param int    $variation_id Variation ID.
 * @param string $attribute    Attribute taxonomy (default: pa_opprinnelse).
 * @return WP_Post|null Matching attribute_page post, or null.
 */
function kaupang_attribute_suite_get_attribute_page_for_variation($variation_id, $attribute = 'pa_opprinnelse') {
    $product = wc_get_product($variation_id);
    if (!$product || !$product->is_type('variation')) {
        return null;
    }

    $attributes = $product->get_attributes();
    if (!isset($attributes[$attribute])) {
        return null;
    }

    $term = get_term_by('slug', $attributes[$attribute], $attribute);
    if (!$term || is_wp_error($term)) {
        return null;
    }

    return kaupang_attribute_suite_get_cached_attribute_page($term->slug);
}

/**
 * Add the wc_ras_origin struct to variation data at priority 20.
 *
 * @param array                $data      Variation data.
 * @param WC_Product_Variable  $product   Product object.
 * @param WC_Product_Variation $variation Variation object.
 * @return array
 */
function kaupang_attribute_suite_add_origin_to_variation($data, $product, $variation) {
    $page = kaupang_attribute_suite_get_attribute_page_for_variation($variation->get_id());
    if (!$page) {
        return $data;
    }

    $struct = kaupang_attribute_suite_build_origin_struct($page);
    if ($struct) {
        $data['wc_ras_origin'] = $struct;
    }

    return $data;
}
add_filter('woocommerce_available_variation', 'kaupang_attribute_suite_add_origin_to_variation', 20, 3);
