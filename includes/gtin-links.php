<?php
/**
 * GS1 Digital Link for label QR codes: /01/<GTIN>[/10/<lot>][/21/<serial>] → product page with
 * the variation preselected (GTIN = Woo's native "GTIN, UPC, EAN or ISBN" field).
 *
 * Follows the cheap parts of the GS1-Conformant Resolver standard: bad GTIN → 400, unknown → 404,
 * query string passed through, trailing slash tolerated, qualifiers (/10/ lot, /21/ serial) accepted
 * and resolved one level up to the GTIN — so labels can carry a lot number before the batch ledger exists.
 * 307, not 301: browsers cache a 301 forever, and a label outlives the product structure.
 *
 * ponytail: no linkset / linkType / .well-known/gs1resolver — add with the batch ledger, or when
 * a third party (retailer, DPP) needs to query us.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

// Rewrite rule + query var: core.php (every request). Loaded by kaupang_attribute_suite_route_front() on /01/ URLs.

/**
 * GTIN-8/12/13/14 → GTIN-14, or null when the length or GS1 check digit is wrong.
 */
function kaupang_attribute_suite_gtin14($raw) {
    if (!preg_match('/^(\d{8}|\d{12,14})$/', $raw)) {
        return null;
    }
    $gtin = str_pad($raw, 14, '0', STR_PAD_LEFT);
    $sum  = 0;
    for ($i = 0; $i < 13; $i++) {
        $sum += (int) $gtin[$i] * ($i % 2 ? 1 : 3);
    }
    return (10 - $sum % 10) % 10 === (int) $gtin[13] ? $gtin : null;
}

/**
 * Product URL for a GTIN-14, or null when no published product has it. The field stores the
 * GTIN as typed (usually 13 digits), so try every zero-padded length.
 */
function kaupang_attribute_suite_gtin_target($gtin14) {
    $short = ltrim($gtin14, '0');
    foreach (array(13, 14, 12, 8) as $len) {
        if (strlen($short) > $len) {
            continue;
        }
        $id = wc_get_product_id_by_global_unique_id(str_pad($short, $len, '0', STR_PAD_LEFT));
        if (!$id) {
            continue;
        }
        $product = wc_get_product($id);
        if ($product && get_post_status($product->get_parent_id() ?: $id) === 'publish') {
            // Variation permalinks already carry ?attribute_pa_*=… for the preselect.
            return $product->get_permalink();
        }
    }
    return null;
}

add_action('template_redirect', function () {
    $raw = get_query_var('kaupang_gtin');
    if ($raw === '') {
        return;
    }

    // GridPane's page cache stores redirects and 404s for 30 days; Do-Not-Cache feeds its srcache_store_skip.
    nocache_headers();
    header('Do-Not-Cache: 1');

    $gtin   = kaupang_attribute_suite_gtin14($raw);
    $target = $gtin ? kaupang_attribute_suite_gtin_target($gtin) : null;

    if (!$target) {
        // Theme's 404 template either way; the standard wants 400 for a malformed GTIN.
        global $wp_query;
        $wp_query->set_404();
        status_header($gtin ? 404 : 400);
        return;
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if ($qs !== '') {
        $target .= (strpos($target, '?') === false ? '?' : '&') . $qs;
    }
    wp_safe_redirect($target, 307, 'kaupang-gtin-link');
    exit;
}, 1); // before redirect_canonical (10), which would 301 /01/<gtin> → /01/<gtin>/ first
