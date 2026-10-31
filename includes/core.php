<?php
/**
 * Core: what every request needs, and the gates that load the rest.
 *
 * Loaded on plugins_loaded in every context (main file). Holds only registration (CPT, taxonomies, meta, block
 * pattern, rewrite rules), cache invalidation that any context can trigger, and cheap callbacks that load a module
 * when its context shows up. Everything else lives in modules (kaupang_attribute_suite_load()), see README "Load map".
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Load modules (idempotent). Files only define functions/classes and add their own hooks.
 *
 * @param string ...$modules variations | archived | gallery | sheet | gtin | admin | schema
 */
function kaupang_attribute_suite_load(...$modules) {
    static $map = array(
        // Variation data + description fallback + inline description + archived options (any context that builds variation data).
        'variations' => array('template-loader.php', 'frontend-hooks.php', 'origin/origin-render.php', 'variation-improvements.php', 'inline-variation-description.php', 'archived-variations.php'),
        'archived'   => array('archived-variations.php'),
        'gallery'    => array('variation-gallery-transition.php'),
        // Origin card/modal, origin pages (template_include, origin.css) and the public render API kaupang-trace calls.
        'sheet'      => array('template-loader.php', 'frontend-hooks.php', 'origin/origin-render.php', 'origin/origin-frontend.php'),
        'gtin'       => array('gtin-links.php'),
        'admin'      => array('admin-hooks.php', 'origin/origin-admin.php', 'origin/origin-admin-certifications.php', 'archived-variations.php'),
        // countryOfOrigin on the Product JSON-LD node (opt-in flag, see kaupang_attribute_suite_lazy_origin_schema()).
        'schema'     => array('frontend-hooks.php', 'archived-variations.php', 'origin/origin-schema.php'),
    );
    foreach ($modules as $module) {
        foreach ($map[$module] as $file) {
            require_once KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/' . $file;
        }
    }
}

// ── Gates ───────────────────────────────────────────────────────────────────

// wp-admin screens (admin_menu fires before admin_init, so decide now). is_admin() is also true on admin-ajax.php,
// which storefront scripts call: there the admin module loads at admin_init, and only for logged-in users (every
// handler it adds is wp_ajax_*, never nopriv).
if (is_admin() && !wp_doing_ajax()) {
    kaupang_attribute_suite_load('admin');
} elseif (wp_doing_ajax()) {
    add_action('admin_init', function () {
        if (is_user_logged_in()) {
            kaupang_attribute_suite_load('admin');
        }
    }, 0);
}

// WP-CLI: the public render/GTIN API (kaupang-trace's check calls it; eval-file scripts expect it).
if (defined('WP_CLI') && WP_CLI) {
    kaupang_attribute_suite_load('sheet', 'gtin');
}

/**
 * Single product page of a variable product (WC_Product_Variable covers variable subscriptions too).
 *
 * @return bool
 */
function kaupang_attribute_suite_is_variable_product_page() {
    return is_product() && wc_get_product(get_queried_object_id()) instanceof WC_Product_Variable;
}

/**
 * Storefront: decide once the main query is known, before wp_enqueue_scripts and template_redirect.
 */
function kaupang_attribute_suite_route_front() {
    if (kaupang_attribute_suite_is_variable_product_page()) {
        kaupang_attribute_suite_load('variations', 'gallery');
        $attributes = wc_get_product(get_queried_object_id())->get_variation_attributes();
        if (!empty($attributes['pa_opprinnelse'])) {
            kaupang_attribute_suite_load('sheet'); // origin modal
        }
    } elseif (is_singular('attribute_page') || is_post_type_archive('attribute_page') || is_tax(array('origin_country', 'certification'))) {
        kaupang_attribute_suite_load('sheet');
    }
    // GTIN links and kaupang-trace's batch pages (template_redirect 0, calls the render API) share /01/.
    if ((string) get_query_var('kaupang_gtin') !== '') {
        kaupang_attribute_suite_load('gtin', 'sheet');
    }
}
add_action('wp', 'kaupang_attribute_suite_route_front');

/**
 * Variation data can be built anywhere (wc-ajax get_variation, quick views, Mix and Match children, REST, CLI):
 * load the module first thing on the hook. Its callbacks register at later priorities, which WP_Hook runs in the
 * same pass.
 *
 * @param mixed $value Passed through.
 * @return mixed
 */
function kaupang_attribute_suite_lazy_variations($value = null) {
    kaupang_attribute_suite_load('variations');
    return $value;
}
add_filter('woocommerce_available_variation', 'kaupang_attribute_suite_lazy_variations', PHP_INT_MIN);
add_filter('woocommerce_ajax_variation_threshold', 'kaupang_attribute_suite_lazy_variations', PHP_INT_MIN);
add_action('wc_mnm_child_item_details', 'kaupang_attribute_suite_lazy_variations', PHP_INT_MIN);

/**
 * Archived variations: option lists and the attributes table of variable products, and the attribute term archive
 * main query (plus any query passing `kaupang_attribute_suite_active_term`).
 *
 * @param mixed $value Passed through.
 * @return mixed
 */
function kaupang_attribute_suite_lazy_archived($value = null) {
    kaupang_attribute_suite_load('archived');
    return $value;
}
add_filter('woocommerce_dropdown_variation_attribute_options_args', 'kaupang_attribute_suite_lazy_archived', PHP_INT_MIN);
add_filter('woocommerce_display_product_attributes', function ($rows, $product) {
    if ($product instanceof WC_Product && $product->is_type('variable')) {
        kaupang_attribute_suite_load('archived');
    }
    return $rows;
}, PHP_INT_MIN, 2);
add_action('pre_get_posts', function ($query) {
    $load = (bool) $query->get('kaupang_attribute_suite_active_term');
    if (!$load && !is_admin() && $query->is_main_query() && $query->is_tax() && $query->tax_query) {
        // Parsed terms, not get_queried_object(): that would cache the object before later pre_get_posts callbacks.
        foreach (array_keys($query->tax_query->queried_terms) as $taxonomy) {
            $load = $load || taxonomy_is_product_attribute($taxonomy);
        }
    }
    if ($load) {
        kaupang_attribute_suite_load('archived'); // its posts_where runs later in this same query
    }
});

/**
 * Inline variation description: Woo prints its variation JS template wherever `wc-add-to-cart-variation` is
 * enqueued (product pages, and checkout when a third party enqueues it). Swap in the template without the
 * description div there, as before; the module's script fills the inline row on product pages.
 *
 * @param string $template      Template path.
 * @param string $template_name Template name.
 * @return string
 */
function kaupang_attribute_suite_variation_template($template, $template_name) {
    if ('single-product/add-to-cart/variation.php' === $template_name
        && apply_filters('kaupang/attribute-suite/enable_inline_variation_description', false)) {
        return KAUPANG_ATTRIBUTE_SUITE_DIR . 'templates/variation-no-description.php';
    }
    return $template;
}
add_filter('wc_get_template', 'kaupang_attribute_suite_variation_template', 10, 2);

/**
 * Origin schema (opt-in): `countryOfOrigin` on the Product node, from Woo's markup or, when Rank Math replaces it,
 * Rank Math's Product entity. The flag is read here, at hook time (theme filters are added after plugins_loaded);
 * with it off the module never loads.
 *
 * @param array|mixed           $node    Product node.
 * @param WC_Product|mixed|null $product Woo passes the product; Rank Math does not (null = current post).
 * @return array|mixed
 */
function kaupang_attribute_suite_lazy_origin_schema($node, $product = null) {
    if (!apply_filters('kaupang/attribute-suite/enable_origin_schema', false)) {
        return $node;
    }
    kaupang_attribute_suite_load('schema');
    return kaupang_attribute_suite_origin_schema_add($node, $product);
}
add_filter('woocommerce_structured_data_product', 'kaupang_attribute_suite_lazy_origin_schema', 10, 2);
add_filter('rank_math/snippet/rich_snippet_product_entity', 'kaupang_attribute_suite_lazy_origin_schema', 10, 1);

// ── Rewrite rules ───────────────────────────────────────────────────────────

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
 * Version bump → flush on the next request, so new CPT/taxonomy rewrite rules take effect without a visit to
 * Permalinks. On admin_init (the storefront never reads the version): a deploy takes effect on the next wp-admin
 * page load. Also moves a pre-2.4 autoloaded version row off autoload.
 */
function kaupang_attribute_suite_check_rewrite_rules_version() {
    $current_version = get_option('kaupang_attribute_suite_rewrite_version', '0');

    if (version_compare($current_version, KAUPANG_ATTRIBUTE_SUITE_VERSION, '<')) {
        update_option('kaupang_attribute_suite_flush_rewrite_rules', true, false);
        update_option('kaupang_attribute_suite_rewrite_version', KAUPANG_ATTRIBUTE_SUITE_VERSION, false);
    } elseif (array_key_exists('kaupang_attribute_suite_rewrite_version', wp_load_alloptions())) {
        wp_set_option_autoload_values(array('kaupang_attribute_suite_rewrite_version' => false));
    }
}
if (is_admin()) {
    add_action('admin_init', 'kaupang_attribute_suite_check_rewrite_rules_version');
}

/**
 * Attribute added, edited ("Enable archives" toggled) or deleted: flush on the next request, which registers the
 * taxonomies with the new setting. Woo queues its own flush on WP-Cron, which waits for the system cron here.
 */
function kaupang_attribute_suite_queue_rewrite_flush() {
    update_option('kaupang_attribute_suite_flush_rewrite_rules', true, false);
}
add_action('woocommerce_attribute_added', 'kaupang_attribute_suite_queue_rewrite_flush');
add_action('woocommerce_attribute_updated', 'kaupang_attribute_suite_queue_rewrite_flush');
add_action('woocommerce_attribute_deleted', 'kaupang_attribute_suite_queue_rewrite_flush');

// GS1 Digital Link /01/<GTIN>… (handler: gtin-links.php, loaded by kaupang_attribute_suite_route_front()).
add_action('init', function () {
    add_rewrite_rule('^01/([^/]+)(/.*)?$', 'index.php?kaupang_gtin=$matches[1]', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'kaupang_gtin';
    return $vars;
});

// ── Attribute page CPT ────────────────────────────────────────────────────────

/**
 * Register the attribute_page custom post type
 */
function kaupang_attribute_suite_register_attribute_page_cpt() {
    $labels = array(
        'name'                  => _x('Attribute Pages', 'Post type general name', 'kaupang-attribute-suite'),
        'singular_name'         => _x('Attribute Page', 'Post type singular name', 'kaupang-attribute-suite'),
        'menu_name'             => _x('Attribute Suite', 'Admin Menu text', 'kaupang-attribute-suite'),
        'name_admin_bar'        => _x('Attribute Page', 'Add New on Toolbar', 'kaupang-attribute-suite'),
        'add_new'               => __('Add New', 'kaupang-attribute-suite'),
        'add_new_item'          => __('Add New Attribute Page', 'kaupang-attribute-suite'),
        'new_item'              => __('New Attribute Page', 'kaupang-attribute-suite'),
        'edit_item'             => __('Edit Attribute Page', 'kaupang-attribute-suite'),
        'view_item'             => __('View Attribute Page', 'kaupang-attribute-suite'),
        'all_items'             => __('All Pages', 'kaupang-attribute-suite'),
        'search_items'          => __('Search Attribute Pages', 'kaupang-attribute-suite'),
        'parent_item_colon'     => __('Parent Attribute Pages:', 'kaupang-attribute-suite'),
        'not_found'             => __('No attribute pages found.', 'kaupang-attribute-suite'),
        'not_found_in_trash'    => __('No attribute pages found in Trash.', 'kaupang-attribute-suite'),
        'featured_image'        => _x('Attribute Image', 'Overrides the "Featured Image" phrase', 'kaupang-attribute-suite'),
        'set_featured_image'    => _x('Set attribute image', 'Overrides the "Set featured image" phrase', 'kaupang-attribute-suite'),
        'remove_featured_image' => _x('Remove attribute image', 'Overrides the "Remove featured image" phrase', 'kaupang-attribute-suite'),
        'use_featured_image'    => _x('Use as attribute image', 'Overrides the "Use as featured image" phrase', 'kaupang-attribute-suite'),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array(
            'slug'       => 'opprinnelser',
            'with_front' => false,
        ),
        'capability_type'    => 'post',
        'has_archive'        => 'opprinnelser',
        'hierarchical'       => false,
        'menu_position'      => null,
        'menu_icon'          => 'dashicons-tag',
        'supports'           => array('title', 'editor', 'thumbnail', 'excerpt', 'revisions'),
        'show_in_rest'       => true,  // Enable block editor
    );

    register_post_type('attribute_page', $args);
}
add_action('init', 'kaupang_attribute_suite_register_attribute_page_cpt');

/**
 * Register meta fields for attribute_page CPT
 */
function kaupang_attribute_suite_register_attribute_page_meta() {
    // Register region meta field
    register_post_meta('attribute_page', 'region', array(
        'type'              => 'string',
        'description'       => __('Region information for the attribute', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ));

    // Register smak (taste) meta field
    register_post_meta('attribute_page', 'smak', array(
        'type'              => 'string',
        'description'       => __('Taste profile for the attribute', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ));

    /**
     * Hook for registering additional meta fields
     * 
     * @param string $post_type The post type (attribute_page)
     */
    do_action('kaupang/attribute-suite/register_attribute_page_meta_fields', 'attribute_page');
}
add_action('init', 'kaupang_attribute_suite_register_attribute_page_meta');

/**
 * Auto-create attribute_page CPT for each relevant attribute term.
 * This creates a matching attribute page for new terms.
 *
 * @param int    $term_id  Term ID
 * @param string $taxonomy Taxonomy name
 */
function kaupang_attribute_suite_sync_attribute_pages_on_term_create($term_id, $taxonomy) {
    // Check if the taxonomy is a product attribute
    if (strpos($taxonomy, 'pa_') !== 0) {
        return;
    }

    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return;
    }

    // Check if an attribute page already exists with this slug
    $existing = get_page_by_path($term->slug, OBJECT, 'attribute_page');
    if (!$existing) {
        // Create new attribute page
        $post_id = wp_insert_post(array(
            'post_title'   => $term->name,
            'post_name'    => $term->slug,
            'post_type'    => 'attribute_page',
            'post_status'  => 'publish',
            'post_content' => '',
        ));

        if (!is_wp_error($post_id)) {
            // Store the taxonomy this page is related to
            update_post_meta($post_id, '_attribute_taxonomy', $taxonomy);
            update_post_meta($post_id, '_attribute_term_id', $term_id);
        }
    }
}

// Hook into all product attribute taxonomy term creation
function kaupang_attribute_suite_register_attribute_term_hooks() {
    $attribute_taxonomies = wc_get_attribute_taxonomies();
    
    if (!empty($attribute_taxonomies)) {
        foreach ($attribute_taxonomies as $taxonomy) {
            $taxonomy_name = wc_attribute_taxonomy_name($taxonomy->attribute_name);
            add_action("created_{$taxonomy_name}", 'kaupang_attribute_suite_sync_attribute_pages_on_term_create', 10, 2);
        }
    }
}
add_action('init', 'kaupang_attribute_suite_register_attribute_term_hooks', 20);

/**
 * Save attribute term meta when attribute page is created manually
 *
 * @param int $post_id Post ID
 */
function kaupang_attribute_suite_save_attribute_page_term_link($post_id) {
    // Check if this is an attribute_page
    if (get_post_type($post_id) !== 'attribute_page') {
        return;
    }
    
    // Check for pending attribute page data
    $pending_data = get_transient('kaupang_attribute_suite_pending_attribute_page_' . get_current_user_id());
    if (!$pending_data) {
        return;
    }
    
    // Only save if meta doesn't already exist
    if (!get_post_meta($post_id, '_attribute_term_id', true)) {
        update_post_meta($post_id, '_attribute_term_id', $pending_data['term_id']);
        update_post_meta($post_id, '_attribute_taxonomy', $pending_data['taxonomy']);
        
        // Also set the post slug to match the term slug if not already set
        $post = get_post($post_id);
        if ($post && empty($post->post_name)) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_name' => $pending_data['term_slug'],
            ));
        }
    }
    
    // Clean up transient
    delete_transient('kaupang_attribute_suite_pending_attribute_page_' . get_current_user_id());
}
add_action('save_post_attribute_page', 'kaupang_attribute_suite_save_attribute_page_term_link', 5);

/**
 * Get or create an attribute page for a specific term
 *
 * @param string $term_slug The term slug
 * @return WP_Post|null The attribute page post or null if not found/created
 */
function kaupang_attribute_suite_get_attribute_page($term_slug) {
    $cache_key = 'attribute_page_' . md5($term_slug);
    $page_id = wp_cache_get($cache_key, 'kaupang_attribute_suite_attribute_pages');

    if (false === $page_id) {
        $page = get_page_by_path($term_slug, OBJECT, 'attribute_page');
        $page_id = $page ? $page->ID : 0;
        wp_cache_set($cache_key, $page_id, 'kaupang_attribute_suite_attribute_pages');
    }

    return $page_id ? get_post($page_id) : null;
}

/**
 * Invalidate cached attribute_page lookups when a post is saved or deleted.
 *
 * Two cache groups exist historically (`kaupang_attribute_suite_attribute_page` in
 * frontend-hooks.php and `kaupang_attribute_suite_attribute_pages` in this file). Both are
 * cleared here so variation preload and archive fallbacks always see the
 * latest state without waiting for the 1-hour TTL.
 *
 * @param int $post_id Post ID.
 */
function kaupang_attribute_suite_invalidate_attribute_page_cache($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'attribute_page' || empty($post->post_name)) {
        return;
    }
    $key = 'attribute_page_' . md5($post->post_name);
    wp_cache_delete($key, 'kaupang_attribute_suite_attribute_page');
    wp_cache_delete($key, 'kaupang_attribute_suite_attribute_pages');
}
add_action('save_post_attribute_page', 'kaupang_attribute_suite_invalidate_attribute_page_cache');
add_action('deleted_post', 'kaupang_attribute_suite_invalidate_attribute_page_cache');

// ── Origin configuration ──────────────────────────────────────────────────────

/**
 * Taste profile axes.
 *
 * Source of truth for the 8-axis taste radar. Keys are stable JSON keys
 * (English, lower_snake_case). Values are translatable labels shown in admin
 * inputs and on rendered radars. Adding/removing an axis requires NO database
 * migration: render code reads only the axes defined here at read time.
 *
 * @return array<string,string> Map of axis_key => translated label.
 */
function kaupang_attribute_suite_taste_axes() {
    $axes = array(
        'acidity'    => __('Syre', 'kaupang-attribute-suite'),
        'sweetness'  => __('Sødme', 'kaupang-attribute-suite'),
        'bitterness' => __('Bitter', 'kaupang-attribute-suite'),
        'body'       => __('Kropp', 'kaupang-attribute-suite'),
        'fruit'      => __('Frukt', 'kaupang-attribute-suite'),
        'floral'     => __('Blomst', 'kaupang-attribute-suite'),
        'earth'      => __('Jord', 'kaupang-attribute-suite'),
        'spice'      => __('Krydder', 'kaupang-attribute-suite'),
    );

    return apply_filters('kaupang/attribute-suite/taste_axes', $axes);
}

/**
 * Producer type enum.
 *
 * @return array<string,string> Map of enum_value => translated label.
 */
function kaupang_attribute_suite_producer_types() {
    $types = array(
        'single_estate'     => __('Single estate', 'kaupang-attribute-suite'),
        'family_farm'       => __('Family farm', 'kaupang-attribute-suite'),
        'cooperative'       => __('Cooperative', 'kaupang-attribute-suite'),
        'social_enterprise' => __('Social enterprise', 'kaupang-attribute-suite'),
        'smallholders'      => __('Smallholders', 'kaupang-attribute-suite'),
    );

    return apply_filters('kaupang/attribute-suite/producer_types', $types);
}

/**
 * Fermentation type enum.
 *
 * @return array<string,string> Map of enum_value => translated label.
 */
function kaupang_attribute_suite_fermentation_types() {
    $types = array(
        'centralized'   => __('Sentralisert', 'kaupang-attribute-suite'),
        'decentralized' => __('Desentralisert', 'kaupang-attribute-suite'),
        'mixed'         => __('Blandet', 'kaupang-attribute-suite'),
    );

    return apply_filters('kaupang/attribute-suite/fermentation_types', $types);
}

/**
 * Country flag mapping: origin_country term slug => flag SVG/image URL.
 *
 * Default is empty. Site owners register mappings via the filter if they
 * want to override the file-based resolution in
 * kaupang_attribute_suite_country_flag_url() (origin-render.php). Slug convention:
 * ISO-ish lowercase names (peru, tanzania, nicaragua, …).
 *
 * @return array<string,string>
 */
function kaupang_attribute_suite_country_flag_map() {
    return apply_filters('kaupang/attribute-suite/country_flag_map', array());
}

// ── Origin meta ───────────────────────────────────────────────────────────────

/**
 * Register origin post meta fields.
 *
 * @param string $post_type Post type slug (passed by the hook).
 */
function kaupang_attribute_suite_origin_register_meta($post_type) {
    if ($post_type !== 'attribute_page') {
        return;
    }

    // Cultivation
    register_post_meta('attribute_page', 'variety', array(
        'type'              => 'string',
        'description'       => __('Cacao variety (e.g., Chuncho, Criollo).', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ));

    // People & Producers
    register_post_meta('attribute_page', 'producer_type', array(
        'type'              => 'string',
        'description'       => __('Producer organization type.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'kaupang_attribute_suite_sanitize_producer_type',
    ));

    register_post_meta('attribute_page', 'producer_count', array(
        'type'              => 'integer',
        'description'       => __('Number of producers (nullable).', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'kaupang_attribute_suite_sanitize_nullable_int',
    ));

    // Post-harvest
    register_post_meta('attribute_page', 'fermentation_type', array(
        'type'              => 'string',
        'description'       => __('Fermentation organization type.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'kaupang_attribute_suite_sanitize_fermentation_type',
    ));

    register_post_meta('attribute_page', 'fermentation_days', array(
        'type'              => 'integer',
        'description'       => __('Fermentation duration in days (nullable).', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'kaupang_attribute_suite_sanitize_nullable_int',
    ));

    register_post_meta('attribute_page', 'fermentation_method', array(
        'type'              => 'string',
        'description'       => __('Free-text fermentation method description.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ));

    register_post_meta('attribute_page', 'drying_method', array(
        'type'              => 'string',
        'description'       => __('Free-text drying method description.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ));

    // Flavour — taste_profile stored as object keyed by axis slug
    register_post_meta('attribute_page', 'taste_profile', array(
        'type'              => 'object',
        'description'       => __('8-axis taste profile, values 0–10 per axis.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => array(
            'schema' => array(
                'type'                 => 'object',
                'additionalProperties' => array(
                    'type'    => array('integer', 'null'),
                    'minimum' => 0,
                    'maximum' => 10,
                ),
            ),
        ),
        'sanitize_callback' => 'kaupang_attribute_suite_sanitize_taste_profile',
    ));

    // Altitude — stored for future direct-trade partners, not frontend-rendered
    register_post_meta('attribute_page', 'altitude', array(
        'type'              => 'object',
        'description'       => __('Altitude range {min, max, unit}.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => array(
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'min'  => array('type' => array('integer', 'null')),
                    'max'  => array('type' => array('integer', 'null')),
                    'unit' => array('type' => 'string'),
                ),
            ),
        ),
        'sanitize_callback' => 'kaupang_attribute_suite_sanitize_altitude',
    ));

    // References
    register_post_meta('attribute_page', 'id_archivo', array(
        'type'              => 'string',
        'description'       => __('External archive identifier (e.g., Silva GT-047).', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ));
}
add_action('kaupang/attribute-suite/register_attribute_page_meta_fields', 'kaupang_attribute_suite_origin_register_meta');

/**
 * Sanitize producer_type against the configured enum.
 *
 * @param mixed $value Submitted value.
 * @return string Valid enum value, or empty string.
 */
function kaupang_attribute_suite_sanitize_producer_type($value) {
    $value = is_string($value) ? $value : '';
    $allowed = array_keys(kaupang_attribute_suite_producer_types());
    return in_array($value, $allowed, true) ? $value : '';
}

/**
 * Sanitize fermentation_type against the configured enum.
 *
 * @param mixed $value Submitted value.
 * @return string Valid enum value, or empty string.
 */
function kaupang_attribute_suite_sanitize_fermentation_type($value) {
    $value = is_string($value) ? $value : '';
    $allowed = array_keys(kaupang_attribute_suite_fermentation_types());
    return in_array($value, $allowed, true) ? $value : '';
}

/**
 * Sanitize an integer that may be null.
 *
 * Empty string / 0 / non-numeric becomes empty string so update_post_meta
 * can skip storing a value. Admin save layer deletes the meta when empty.
 *
 * @param mixed $value Submitted value.
 * @return int|string Sanitized int, or '' to signal "no value".
 */
function kaupang_attribute_suite_sanitize_nullable_int($value) {
    if ($value === '' || $value === null) {
        return '';
    }
    if (!is_numeric($value)) {
        return '';
    }
    return absint($value);
}

/**
 * Sanitize the taste_profile object.
 *
 * Keeps only keys that appear in kaupang_attribute_suite_taste_axes(). Values are clamped to
 * 0–10 or preserved as null. Any unknown axis or invalid value is dropped.
 *
 * @param mixed $value Submitted object/array.
 * @return array Sanitized map of axis_key => int|null.
 */
function kaupang_attribute_suite_sanitize_taste_profile($value) {
    if (!is_array($value)) {
        return array();
    }

    $allowed = array_keys(kaupang_attribute_suite_taste_axes());
    $out = array();

    foreach ($allowed as $axis) {
        if (!array_key_exists($axis, $value)) {
            continue;
        }
        $v = $value[$axis];
        if ($v === '' || $v === null) {
            $out[$axis] = null;
            continue;
        }
        if (!is_numeric($v)) {
            continue;
        }
        $n = (int) $v;
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 10) {
            $n = 10;
        }
        $out[$axis] = $n;
    }

    return $out;
}

/**
 * Sanitize the altitude object.
 *
 * @param mixed $value Submitted object/array.
 * @return array Sanitized {min, max, unit}.
 */
function kaupang_attribute_suite_sanitize_altitude($value) {
    if (!is_array($value)) {
        return array();
    }

    $out = array();

    if (isset($value['min']) && $value['min'] !== '' && is_numeric($value['min'])) {
        $out['min'] = absint($value['min']);
    }
    if (isset($value['max']) && $value['max'] !== '' && is_numeric($value['max'])) {
        $out['max'] = absint($value['max']);
    }
    $unit = isset($value['unit']) ? sanitize_text_field($value['unit']) : 'm';
    $out['unit'] = in_array($unit, array('m', 'ft'), true) ? $unit : 'm';

    // If both min and max missing, return empty to clear the meta entirely
    if (!isset($out['min']) && !isset($out['max'])) {
        return array();
    }

    return $out;
}

// ── Origin taxonomies ─────────────────────────────────────────────────────────

/**
 * Register origin_country and certification taxonomies.
 *
 * Hooked on init at priority 11 — after the CPT is registered at priority 10
 * (cpt-attribute-page.php), and before attribute-term sync hooks at priority 20.
 */
function kaupang_attribute_suite_register_origin_taxonomies() {
    register_taxonomy('origin_country', array('attribute_page'), array(
        'labels' => array(
            'name'              => _x('Countries', 'Taxonomy general name', 'kaupang-attribute-suite'),
            'singular_name'     => _x('Country', 'Taxonomy singular name', 'kaupang-attribute-suite'),
            'menu_name'         => __('Countries', 'kaupang-attribute-suite'),
            'all_items'         => __('All countries', 'kaupang-attribute-suite'),
            'edit_item'         => __('Edit country', 'kaupang-attribute-suite'),
            'view_item'         => __('View country', 'kaupang-attribute-suite'),
            'update_item'       => __('Update country', 'kaupang-attribute-suite'),
            'add_new_item'      => __('Add new country', 'kaupang-attribute-suite'),
            'new_item_name'     => __('New country name', 'kaupang-attribute-suite'),
            'search_items'      => __('Search countries', 'kaupang-attribute-suite'),
            'not_found'         => __('No countries found.', 'kaupang-attribute-suite'),
        ),
        'public'             => true,
        'publicly_queryable' => true,
        'hierarchical'       => false,
        'show_ui'            => true,
        'show_admin_column'  => true,
        'show_in_rest'       => true,
        'query_var'          => true,
        'rewrite'            => array(
            'slug'       => 'opprinnelser/land',
            'with_front' => false,
        ),
    ));

    register_taxonomy('certification', array('attribute_page'), array(
        'labels' => array(
            'name'              => _x('Certifications', 'Taxonomy general name', 'kaupang-attribute-suite'),
            'singular_name'     => _x('Certification', 'Taxonomy singular name', 'kaupang-attribute-suite'),
            'menu_name'         => __('Certifications', 'kaupang-attribute-suite'),
            'all_items'         => __('All certifications', 'kaupang-attribute-suite'),
            'edit_item'         => __('Edit certification', 'kaupang-attribute-suite'),
            'view_item'         => __('View certification', 'kaupang-attribute-suite'),
            'update_item'       => __('Update certification', 'kaupang-attribute-suite'),
            'add_new_item'      => __('Add new certification', 'kaupang-attribute-suite'),
            'new_item_name'     => __('New certification name', 'kaupang-attribute-suite'),
            'search_items'      => __('Search certifications', 'kaupang-attribute-suite'),
            'not_found'         => __('No certifications found.', 'kaupang-attribute-suite'),
        ),
        'public'             => true,
        'publicly_queryable' => true,
        'hierarchical'       => false,
        'show_ui'            => true,
        'show_admin_column'  => true,
        'show_in_rest'       => true,
        'query_var'          => true,
        'rewrite'            => array(
            'slug'       => 'opprinnelser/sertifiseringer',
            'with_front' => false,
        ),
    ));

    register_term_meta('certification', 'icon_svg_id', array(
        'type'              => 'integer',
        'description'       => __('Attachment ID of the certification icon SVG.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'absint',
    ));

    register_term_meta('certification', 'full_name', array(
        'type'              => 'string',
        'description'       => __('Full/official certification name.', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ));

    register_term_meta('certification', 'external_url', array(
        'type'              => 'string',
        'description'       => __('External URL (certification body or programme page).', 'kaupang-attribute-suite'),
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'esc_url_raw',
    ));
}
add_action('init', 'kaupang_attribute_suite_register_origin_taxonomies', 11);

/**
 * Invalidate the cached attribute_page on term changes so wc_ras_origin
 * preload reflects updated country/certification assignments immediately.
 *
 * @param int    $object_id Post ID whose terms changed.
 * @param array  $terms     Term IDs (unused).
 * @param array  $tt_ids    Term taxonomy IDs (unused).
 * @param string $taxonomy  Taxonomy name.
 */
function kaupang_attribute_suite_invalidate_origin_terms_cache($object_id, $terms, $tt_ids, $taxonomy) {
    if ($taxonomy !== 'origin_country' && $taxonomy !== 'certification') {
        return;
    }
    $post = get_post($object_id);
    if (!$post || $post->post_type !== 'attribute_page') {
        return;
    }
    if (function_exists('kaupang_attribute_suite_invalidate_attribute_page_cache')) {
        kaupang_attribute_suite_invalidate_attribute_page_cache($object_id);
    }
}
add_action('set_object_terms', 'kaupang_attribute_suite_invalidate_origin_terms_cache', 10, 4);

// ── Origin product counts ─────────────────────────────────────────────────────

/**
 * Clear cached origin counts for a product (or a variation's parent), or for the given slugs.
 *
 * @param int        $post_id Product or variation ID.
 * @param array|null $slugs   pa_opprinnelse slugs; default: the product's current ones.
 */
function kaupang_attribute_suite_flush_origin_counts($post_id, $slugs = null) {
    $parent = wp_get_post_parent_id($post_id);
    if (null === $slugs) {
        $slugs = wc_get_product_terms($parent ? $parent : $post_id, 'pa_opprinnelse', array('fields' => 'slugs'));
    }
    foreach ((array) $slugs as $slug) {
        wp_cache_delete('origin_active_product_count_' . md5($slug), 'kaupang_attribute_suite_origin');
    }
}

// Anything that changes which products an origin counts: status (incl. trash/untrash), deletion, a variation's
// attributes, the parent's save (type), and its origin terms (old and new).
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status !== $old_status && in_array($post->post_type, array('product', 'product_variation'), true)) {
        kaupang_attribute_suite_flush_origin_counts($post->ID);
    }
}, 10, 3);
add_action('before_delete_post', function ($post_id, $post) {
    if (in_array($post->post_type, array('product', 'product_variation'), true)) {
        kaupang_attribute_suite_flush_origin_counts($post_id);
    }
}, 10, 2);
add_action('woocommerce_update_product_variation', 'kaupang_attribute_suite_flush_origin_counts');
add_action('woocommerce_update_product', 'kaupang_attribute_suite_flush_origin_counts');
add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
    if ('pa_opprinnelse' !== $taxonomy) {
        return;
    }
    $slugs = array();
    foreach (array_unique(array_merge((array) $tt_ids, (array) $old_tt_ids)) as $tt_id) {
        $term = get_term_by('term_taxonomy_id', (int) $tt_id, 'pa_opprinnelse');
        if ($term) {
            $slugs[] = $term->slug;
        }
    }
    kaupang_attribute_suite_flush_origin_counts($object_id, $slugs);
}, 10, 6);

// ── Block pattern ───────────────────────────────────────────────────────────

/**
 * Starter pattern for origin pages. The markup file is read only when the editor asks for the pattern (filePath).
 */
function kaupang_attribute_suite_origin_register_block_patterns() {
    register_block_pattern_category('kaupang-attribute-suite', array(
        'label' => __('Kaupang Attribute Suite', 'kaupang-attribute-suite'),
    ));
    register_block_pattern('kaupang-attribute-suite/origin-starter', array(
        'title'       => __('Opprinnelse — 3-kolonne-start', 'kaupang-attribute-suite'),
        'description' => __('Startmal for opprinnelsesside: produsenter, sted, håndverk. Rediger fritt.', 'kaupang-attribute-suite'),
        'categories'  => array('kaupang-attribute-suite'),
        'keywords'    => array('opprinnelse', 'origin', 'columns'),
        'filePath'    => KAUPANG_ATTRIBUTE_SUITE_DIR . 'includes/origin/pattern-origin-starter.php',
    ));
}
add_action('init', 'kaupang_attribute_suite_origin_register_block_patterns', 20);
