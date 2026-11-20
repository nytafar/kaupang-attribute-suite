<?php
/**
 * Origin status: active / former / unsold, derived from products and order history, never stored by hand.
 *
 * kaupang-docs kaupang-attribute-suite/origins-architecture-2026-11-20.md §3. One answer for the index, the origin
 * page, the card, the modal and kaupang-trace: kaupang_attribute_suite_origin_status(), also on the origin struct.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Old slug → current slug for every pa_opprinnelse term with `previous_slugs` (recorded on rename, core.php).
 * Order lines keep the slug the term had when it sold.
 *
 * @return array<string,string>
 */
function kaupang_attribute_suite_origin_previous_slug_map() {
    static $map = null;
    if (null !== $map) {
        return $map;
    }
    $map   = array();
    $terms = get_terms(array('taxonomy' => 'pa_opprinnelse', 'hide_empty' => false)); // primes term meta
    if (is_wp_error($terms)) {
        return $map;
    }
    foreach ($terms as $term) {
        foreach ((array) get_term_meta($term->term_id, 'previous_slugs', false) as $old) {
            $old = sanitize_title((string) $old);
            if ($old !== '' && $old !== $term->slug && !isset($map[$old])) {
                $map[$old] = $term->slug;
            }
        }
    }
    return $map;
}

/**
 * When each origin first and last sold: one aggregate over order line meta `pa_opprinnelse`, grouped by value,
 * against the authoritative order table (HPOS or posts), previous slugs folded into the current one.
 * Cached as one transient for 24 hours (a former origin's period doesn't move; an active one's isn't shown).
 *
 * @return array<string,array{first:string,last:string}> Slug → site-local dates `Y-m-d`.
 */
function kaupang_attribute_suite_origin_sales() {
    static $sales = null;
    if (null !== $sales) {
        return $sales;
    }
    $rows = get_transient('kaupang_attribute_suite_origin_sales');
    if (!is_array($rows)) {
        global $wpdb;

        /**
         * Order statuses that count as a sale (without the `wc-` prefix). Default: Woo's paid statuses.
         *
         * @param string[] $statuses
         */
        $statuses = (array) apply_filters('kaupang/attribute-suite/origin_sale_statuses', wc_get_is_paid_statuses());
        $statuses = array_map(static function ($status) {
            return 'wc-' . preg_replace('/^wc-/', '', (string) $status);
        }, $statuses);
        $in = implode(', ', array_fill(0, count($statuses), '%s'));

        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        if ($hpos) {
            $orders = $wpdb->prefix . 'wc_orders';
            $join   = "JOIN {$orders} o ON o.id = oi.order_id";
            $where  = "o.type = 'shop_order' AND o.status IN ({$in})";
            $date   = 'o.date_created_gmt';
        } else {
            $join  = "JOIN {$wpdb->posts} o ON o.ID = oi.order_id";
            $where = "o.post_type = 'shop_order' AND o.post_status IN ({$in})";
            $date  = 'o.post_date_gmt';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and the IN list are built above
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT im.meta_value AS slug, MIN({$date}) AS first_gmt, MAX({$date}) AS last_gmt
               FROM {$wpdb->prefix}woocommerce_order_itemmeta im
               JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = im.order_item_id
               {$join}
              WHERE im.meta_key = 'pa_opprinnelse' AND {$where}
              GROUP BY im.meta_value",
            $statuses
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : array();
        set_transient('kaupang_attribute_suite_origin_sales', $rows, DAY_IN_SECONDS);
    }

    $map   = kaupang_attribute_suite_origin_previous_slug_map();
    $sales = array();
    foreach ($rows as $row) {
        $slug = isset($map[$row['slug']]) ? $map[$row['slug']] : $row['slug'];
        $first = get_date_from_gmt($row['first_gmt'], 'Y-m-d');
        $last  = get_date_from_gmt($row['last_gmt'], 'Y-m-d');
        if (isset($sales[$slug])) {
            $first = min($first, $sales[$slug]['first']);
            $last  = max($last, $sales[$slug]['last']);
        }
        $sales[$slug] = array('first' => $first, 'last' => $last);
    }
    return $sales;
}

/**
 * Drop the cached sales aggregate (previous slugs changed, or a check needs fresh numbers).
 */
function kaupang_attribute_suite_flush_origin_sales() {
    delete_transient('kaupang_attribute_suite_origin_sales');
}

/**
 * An origin's status.
 *
 *   active  ≥ 1 active product (kaupang_attribute_suite_origin_product_count())
 *   former  no active product, sold before (an order line carries the slug or one of its previous slugs)
 *   unsold  neither
 *
 * @param string $slug pa_opprinnelse term slug.
 * @return array{state:string, active_products:int, first_sold:?string, last_sold:?string} Dates site-local `Y-m-d`.
 */
function kaupang_attribute_suite_origin_status($slug) {
    $slug     = (string) $slug;
    $products = kaupang_attribute_suite_origin_product_count($slug);
    $sales    = kaupang_attribute_suite_origin_sales();
    $sold     = isset($sales[$slug]) ? $sales[$slug] : null;

    if ($products > 0) {
        $state = 'active';
    } elseif ($sold) {
        $state = 'former';
    } else {
        $state = 'unsold';
    }

    return array(
        'state'           => $state,
        'active_products' => $products,
        'first_sold'      => $sold ? $sold['first'] : null,
        'last_sold'       => $sold ? $sold['last'] : null,
    );
}

/**
 * The period an origin was sold, for people: "jul. 2025 – feb. 2026" (one month: "jul. 2025").
 *
 * @param array $status kaupang_attribute_suite_origin_status().
 * @return string Empty when it never sold.
 */
function kaupang_attribute_suite_format_sold_period(array $status) {
    if (empty($status['first_sold']) || empty($status['last_sold'])) {
        return '';
    }
    $tz    = wp_timezone();
    $first = date_create_immutable($status['first_sold'], $tz);
    $last  = date_create_immutable($status['last_sold'], $tz);
    if (!$first || !$last) {
        return '';
    }
    /* translators: month + year in a sold period, PHP date format */
    $format = _x('M Y', 'sold period month', 'kaupang-attribute-suite');
    $from   = wp_date($format, $first->getTimestamp(), $tz);
    $to     = wp_date($format, $last->getTimestamp(), $tz);
    return $from === $to ? $from : $from . ' – ' . $to;
}

/**
 * Where an origin's term archive (`/opprinnelse/<slug>/`) sends a visitor: one canonical URL per origin (§4).
 *
 *   published page          → the page, whatever the status
 *   former, no page         → the index /opprinnelser/
 *   active or unsold, none  → '' (the term archive is the view)
 *
 * A draft or private page never redirects.
 *
 * @param WP_Term $term pa_opprinnelse term.
 * @return string Absolute URL, or '' to stay.
 */
function kaupang_attribute_suite_origin_term_redirect_url($term) {
    if (!$term instanceof WP_Term || 'pa_opprinnelse' !== $term->taxonomy) {
        return '';
    }
    $page = kaupang_attribute_suite_get_cached_attribute_page($term->slug);
    if ($page && 'publish' === $page->post_status) {
        return (string) get_permalink($page);
    }
    if ('former' === kaupang_attribute_suite_origin_status($term->slug)['state']) {
        return (string) get_post_type_archive_link('attribute_page');
    }
    return '';
}

/**
 * The origins the index /opprinnelser/ lists, built from the terms and joined to their pages (§5).
 *
 *   active  every active origin, in the attribute's term order
 *   former  every former origin, newest last_sold first
 *
 * Unsold origins are left out. Each entry: `term` (WP_Term), `status`, `page` (published WP_Post or null) and
 * `origin` (the origin struct when there is a page, else null).
 *
 * @return array{active:array<int,array>, former:array<int,array>}
 */
function kaupang_attribute_suite_origin_index() {
    $index = array('active' => array(), 'former' => array());
    $terms = get_terms(array('taxonomy' => 'pa_opprinnelse', 'hide_empty' => false));
    if (is_wp_error($terms)) {
        return $index;
    }
    foreach ($terms as $term) {
        $status = kaupang_attribute_suite_origin_status($term->slug);
        if ('unsold' === $status['state']) {
            continue;
        }
        $page = kaupang_attribute_suite_get_cached_attribute_page($term->slug);
        $page = $page && 'publish' === $page->post_status ? $page : null;
        $index[$status['state']][] = array(
            'term'   => $term,
            'status' => $status,
            'page'   => $page,
            'origin' => $page ? kaupang_attribute_suite_build_origin_struct($page) : null,
        );
    }
    usort($index['former'], static function ($a, $b) {
        return strcmp((string) $b['status']['last_sold'], (string) $a['status']['last_sold']);
    });

    /**
     * Filter the origin index before the archive renders it.
     *
     * @param array $index { active: entry[], former: entry[] }
     */
    return apply_filters('kaupang/attribute-suite/origin_index', $index);
}
