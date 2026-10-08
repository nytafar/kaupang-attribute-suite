<?php
/**
 * Archived variations
 *
 * A disabled variation (post_status `private`) means "archived": kept in the database for order
 * history and re-enable-able, but invisible to customers and out of the merchant's way.
 *
 *  - Storefront: attribute options used only by archived variations are not offered (native select,
 *    and every chooser built from the same args, e.g. Ousia's tiles), and drop out of the
 *    "Additional information" table. A published variation with "any" for an attribute keeps all of
 *    that attribute's options. Server-side, so the AJAX variation threshold doesn't matter.
 *  - Admin: the product's Variations panel lists only published variations by default; a toolbar
 *    toggle ("Vis arkiverte (N)") reloads the list with the archived ones so they can be re-enabled.
 *    The panel's bulk actions follow what it shows (archived hidden → bulk actions skip them). Scoped to the
 *    panel's own `woocommerce_load_variations` / `woocommerce_bulk_edit_variations` requests — `wc_get_products()`
 *    elsewhere, REST and the cached `woocommerce_get_children` result are untouched.
 *
 * Nothing is ever unassigned or deleted: parent attribute terms and their attribute pages stay.
 *
 * Gated by `kaupang/attribute-suite/enable_variation_improvements` and
 * `kaupang/attribute-suite/enable_archived_variations` (both default true; read on `init`, so a theme can opt out).
 *
 * Check: tools/check-archived-variations.php
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Allowed option values per variation attribute, for a variable product that has archived variations.
 *
 * Keyed by sanitize_title( attribute name ) (`pa_mengde`, `farge`). An attribute is only present when it
 * needs filtering: absent means "offer everything" (no archived variations, or some published variation
 * has "any" for it). Values are term slugs for taxonomy attributes, the text value otherwise.
 *
 * @param WC_Product|mixed $product The variable product.
 * @return array<string, string[]>
 */
function kaupang_attribute_suite_archived_variation_allowed_values($product) {
    if (!$product instanceof WC_Product || !$product->is_type('variable')) {
        return array();
    }

    $children = $product->get_children();
    if (empty($children)) {
        return array();
    }

    // One query for all child posts + their meta instead of one per child.
    _prime_post_caches(array_map('absint', $children), false, true);

    $published = array();
    $archived  = false;
    foreach ($children as $child_id) {
        $status = get_post_status($child_id);
        if ('publish' === $status) {
            $published[] = (int) $child_id;
        } elseif ('private' === $status) {
            $archived = true;
        }
    }

    if (!$archived) {
        return array();
    }

    $allowed = array();
    foreach ($product->get_attributes() as $attribute) {
        if (!$attribute->get_variation()) {
            continue;
        }

        $meta_key = wc_variation_attribute_name($attribute->get_name());
        $values   = array();
        foreach ($published as $variation_id) {
            // ponytail: pre-2.4 products stored sanitized slugs for custom attributes; those won't match the text options here.
            $value = (string) get_post_meta($variation_id, $meta_key, true);
            if ('' === $value) {
                // "Any …" — every option of this attribute stays purchasable.
                $values = null;
                break;
            }
            $values[] = $value;
        }

        if (null !== $values) {
            $allowed[sanitize_title($attribute->get_name())] = array_values(array_unique($values));
        }
    }

    return $allowed;
}

/**
 * Drop the options of one attribute that only archived variations use.
 *
 * @param array      $options   Option values (term slugs or text values).
 * @param WC_Product $product   The variable product.
 * @param string     $attribute Attribute name (`pa_mengde`, `Farge`).
 * @return array Filtered options, original order, reindexed.
 */
function kaupang_attribute_suite_filter_archived_variation_options($options, $product, $attribute) {
    $allowed = kaupang_attribute_suite_archived_variation_allowed_values($product);
    $key     = sanitize_title((string) $attribute);
    if (!isset($allowed[$key]) || !is_array($options)) {
        return $options;
    }

    return array_values(array_filter($options, function ($option) use ($allowed, $key) {
        return in_array((string) $option, $allowed[$key], true);
    }));
}

/**
 * Published and archived (private) variation counts for a parent product. Uncached, like Woo's own count.
 *
 * @param int $product_id Parent product ID.
 * @return array{publish:int, private:int}
 */
function kaupang_attribute_suite_variation_status_counts($product_id) {
    global $wpdb;

    $counts = array('publish' => 0, 'private' => 0);
    $rows   = $wpdb->get_results($wpdb->prepare(
        "SELECT post_status, COUNT(ID) AS n FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status IN ('publish', 'private') GROUP BY post_status",
        $product_id
    ));
    foreach ((array) $rows as $row) {
        $counts[$row->post_status] = (int) $row->n;
    }

    return $counts;
}

/**
 * Hooks for archived variations.
 */
class Kaupang_Attribute_Suite_Archived_Variations {

    /** POST flag the admin toggle adds to `woocommerce_load_variations`. */
    const SHOW_PARAM = 'kaupang_attribute_suite_show_archived';

    /** Response header carrying "published,archived" counts back to the panel. */
    const COUNT_HEADER = 'X-Kaupang-Variation-Counts';

    /**
     * Archived count for the product being edited, captured from the count filter for the toolbar toggle.
     *
     * @var int
     */
    private $archived_count = 0;

    /**
     * Product ID of the pending load_variations narrowing (one-shot), or 0.
     *
     * @var int
     */
    private $narrow_product_id = 0;

    /**
     * Product ID of the pending bulk_edit_variations narrowing (one-shot), or 0.
     *
     * @var int
     */
    private $bulk_product_id = 0;

    /**
     * The instance (for tools/check-archived-variations.php and anyone who needs to unhook it).
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * @return self
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'maybe_init'), 20);
    }

    /**
     * Register hooks unless a theme or plugin opted out.
     */
    public function maybe_init() {
        if (!apply_filters('kaupang/attribute-suite/enable_variation_improvements', true)
            || !apply_filters('kaupang/attribute-suite/enable_archived_variations', true)) {
            return;
        }

        // Storefront.
        add_filter('woocommerce_dropdown_variation_attribute_options_args', array($this, 'filter_dropdown_args'), 20);
        add_filter('woocommerce_display_product_attributes', array($this, 'filter_product_attributes_table'), 20, 2);

        if (is_admin()) {
            $this->register_admin_hooks();
        }
    }

    /**
     * Admin: Variations panel hooks. Public (and idempotent) so the check can drive Woo's real AJAX handlers from WP-CLI.
     */
    public function register_admin_hooks() {
        add_filter('woocommerce_admin_meta_boxes_variations_count', array($this, 'filter_variations_count'), 20, 2);
        add_action('woocommerce_variable_product_before_variations', array($this, 'render_toggle'));
        // Priority 1: before Woo's own handlers (10).
        add_action('wp_ajax_woocommerce_load_variations', array($this, 'prepare_load_variations'), 1);
        add_action('wp_ajax_woocommerce_bulk_edit_variations', array($this, 'prepare_bulk_edit_variations'), 1);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_script'), 20); // after Woo registers its variation script
    }

    /**
     * Storefront: offer only options with a published (or "any") variation behind them.
     *
     * The tile chooser in Ousia reads `$args['options']` from these same args, so it follows suit.
     *
     * @param array $args wc_dropdown_variation_attribute_options() args.
     * @return array
     */
    public function filter_dropdown_args($args) {
        if (empty($args['product']) || !$args['product'] instanceof WC_Product || empty($args['attribute'])) {
            return $args;
        }

        $product   = $args['product'];
        $attribute = $args['attribute'];
        $options   = isset($args['options']) ? $args['options'] : array();
        if (empty($options)) {
            // Woo fills an empty list from get_variation_attributes() after this filter; do it here so we can filter it.
            $all     = $product->get_variation_attributes();
            $options = isset($all[$attribute]) ? $all[$attribute] : array();
        }

        $filtered = kaupang_attribute_suite_filter_archived_variation_options($options, $product, $attribute);
        // An empty list would make Woo refill it with every option (archived included); only possible when
        // published variations reference values the parent no longer offers, so leave that case alone.
        if (!empty($filtered)) {
            $args['options'] = $filtered;
        }

        return $args;
    }

    /**
     * Storefront: rebuild "Additional information" rows of variation attributes without archived-only values.
     *
     * Rows are rebuilt exactly as wc_display_product_attributes() builds them, and only when a value is
     * actually dropped, so untouched rows keep whatever other filters did to them.
     *
     * @param array      $rows    Rows keyed `attribute_{name}` => [label, value].
     * @param WC_Product $product The product.
     * @return array
     */
    public function filter_product_attributes_table($rows, $product) {
        $allowed = kaupang_attribute_suite_archived_variation_allowed_values($product);
        if (empty($allowed)) {
            return $rows;
        }

        foreach ($product->get_attributes() as $attribute) {
            $key     = sanitize_title($attribute->get_name());
            $row_key = 'attribute_' . sanitize_title_with_dashes($attribute->get_name());
            if (!$attribute->get_variation() || !isset($allowed[$key], $rows[$row_key])) {
                continue;
            }

            $values  = array();
            $dropped = false;
            if ($attribute->is_taxonomy()) {
                $taxonomy = $attribute->get_taxonomy_object();
                foreach (wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'all')) as $term) {
                    if (!in_array($term->slug, $allowed[$key], true)) {
                        $dropped = true;
                        continue;
                    }
                    $name     = esc_html($term->name);
                    $values[] = ($taxonomy && $taxonomy->attribute_public)
                        ? '<a href="' . esc_url(get_term_link($term->term_id, $attribute->get_name())) . '" rel="tag">' . $name . '</a>'
                        : $name;
                }
            } else {
                foreach ($attribute->get_options() as $option) {
                    if (!in_array((string) $option, $allowed[$key], true)) {
                        $dropped = true;
                        continue;
                    }
                    $values[] = make_clickable(esc_html($option));
                }
            }

            if (!$dropped) {
                continue;
            }
            if (empty($values)) {
                unset($rows[$row_key]);
                continue;
            }
            // Same Woo filter the original row went through.
            $rows[$row_key]['value'] = apply_filters('woocommerce_attribute', wpautop(wptexturize(implode(', ', $values))), $attribute, $values);
        }

        return $rows;
    }

    /**
     * Admin: the panel's count (and so its pagination) counts published variations only.
     *
     * @param int $count      Woo's publish + private count.
     * @param int $product_id Parent product ID.
     * @return int
     */
    public function filter_variations_count($count, $product_id) {
        $counts               = kaupang_attribute_suite_variation_status_counts((int) $product_id);
        $this->archived_count = $counts['private'];

        return $counts['publish'];
    }

    /**
     * Admin: the "Vis arkiverte (N)" toggle. Rendered here, moved into the top toolbar by the script
     * (the only toolbar Woo keeps visible when the list is empty).
     */
    public function render_toggle() {
        printf(
            // Inline display:none, not `hidden`: `.wp-core-ui .button { display: inline-block }` beats the attribute.
            '<button type="button" class="button kaupang-attribute-suite-archived-toggle" data-archived="%1$d" aria-pressed="false"%2$s>%3$s</button>',
            (int) $this->archived_count,
            $this->archived_count > 0 ? '' : ' style="display:none"',
            esc_html($this->toggle_label(false, $this->archived_count))
        );
    }

    /**
     * Toggle label.
     *
     * @param bool $showing Archived variations currently listed.
     * @param int  $count   Archived count.
     * @return string
     */
    private function toggle_label($showing, $count) {
        return $showing
            /* translators: %d: number of archived (disabled) variations */
            ? sprintf(__('Skjul arkiverte (%d)', 'kaupang-attribute-suite'), $count)
            /* translators: %d: number of archived (disabled) variations */
            : sprintf(__('Vis arkiverte (%d)', 'kaupang-attribute-suite'), $count);
    }

    /**
     * Admin AJAX, before Woo's load_variations (priority 10): narrow its one wc_get_products() call to
     * published variations unless the toggle asked for archived ones, and report fresh counts in a header
     * so the panel's total and pagination follow saves that archive or re-enable variations.
     */
    public function prepare_load_variations() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo verifies the nonce in its own handler; this only narrows its query.
        if (!current_user_can('edit_products') || empty($_POST['product_id'])) {
            return;
        }
        $product_id = absint($_POST['product_id']);
        $show       = !empty($_POST[self::SHOW_PARAM]);
        // phpcs:enable

        if (!$show) {
            $this->narrow_product_id = $product_id;
            add_filter('woocommerce_product_object_query_args', array($this, 'narrow_load_variations_query'));
        }

        if (!headers_sent()) {
            $counts = kaupang_attribute_suite_variation_status_counts($product_id);
            header(self::COUNT_HEADER . ': ' . $counts['publish'] . ',' . $counts['private']);
        }
    }

    /**
     * Admin AJAX, before Woo's bulk_edit_variations: bulk actions act on what the panel shows. With archived
     * variations hidden, Woo's child query skips them, so "Toggle Enabled" can't re-publish them and
     * "Delete all variations" can't delete what is kept for order history.
     */
    public function prepare_bulk_edit_variations() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo verifies the nonce in its own handler; this only narrows its query.
        if (!current_user_can('edit_products') || empty($_POST['product_id']) || !empty($_POST[self::SHOW_PARAM])) {
            return;
        }
        $this->bulk_product_id = absint($_POST['product_id']);
        // phpcs:enable
        add_action('pre_get_posts', array($this, 'narrow_bulk_edit_query'));
    }

    /**
     * One-shot: only the get_posts() matching Woo's bulk_edit_variations (variations of this parent, publish + private, ids).
     *
     * @param WP_Query $query The query.
     */
    public function narrow_bulk_edit_query($query) {
        $status = (array) $query->get('post_status');
        if ($this->bulk_product_id
            && 'product_variation' === $query->get('post_type')
            && (int) $query->get('post_parent') === $this->bulk_product_id
            && 'ids' === $query->get('fields')
            && in_array('private', $status, true)) {
            $query->set('post_status', array_values(array_diff($status, array('private'))));
            $this->bulk_product_id = 0;
            remove_action('pre_get_posts', array($this, 'narrow_bulk_edit_query'));
        }
    }

    /**
     * One-shot: only the query matching Woo's load_variations (variations of this parent, publish + private).
     *
     * @param array $args WC_Product_Query vars.
     * @return array
     */
    public function narrow_load_variations_query($args) {
        $status = isset($args['status']) ? (array) $args['status'] : array();
        if ($this->narrow_product_id
            && isset($args['type'], $args['parent'])
            && 'variation' === $args['type']
            && (int) $args['parent'] === $this->narrow_product_id
            && in_array('private', $status, true)) {
            $args['status'] = array_values(array_diff($status, array('private')));
            $this->narrow_product_id = 0;
            remove_filter('woocommerce_product_object_query_args', array($this, 'narrow_load_variations_query'));
        }

        return $args;
    }

    /**
     * Admin: toggle script on the product edit screen.
     */
    public function enqueue_admin_script() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'product' !== $screen->id || !wp_script_is('wc-admin-variation-meta-boxes', 'registered')) {
            return;
        }

        wp_enqueue_script(
            'kaupang-attribute-suite-archived-variations',
            KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/js/admin-archived-variations.js',
            array('jquery', 'wc-admin-variation-meta-boxes'),
            KAUPANG_ATTRIBUTE_SUITE_VERSION,
            true
        );
        wp_localize_script('kaupang-attribute-suite-archived-variations', 'kaupangAttributeSuiteArchived', array(
            'param'  => self::SHOW_PARAM,
            'header' => self::COUNT_HEADER,
            /* translators: %d: number of archived (disabled) variations */
            'show'   => __('Vis arkiverte (%d)', 'kaupang-attribute-suite'),
            /* translators: %d: number of archived (disabled) variations */
            'hide'   => __('Skjul arkiverte (%d)', 'kaupang-attribute-suite'),
        ));
    }
}

Kaupang_Attribute_Suite_Archived_Variations::instance();
