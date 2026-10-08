<?php
/**
 * Check: attribute term archives follow Woo's per-attribute "Enable archives" (Products → Attributes), and the
 * rewrite rules match. Exits non-zero on failure. Read-only, so safe on a live site.
 * Run: gp wp <site> eval-file wp-content/plugins/kaupang-attribute-suite/tools/check-attribute-archives.php
 * Pair with tools/check-archived-variations.php (staging) for the archived-variations feature.
 */

$fail  = 0;
$check = function ($ok, $msg) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $msg . "\n";
    $fail |= !$ok;
};

$flush = 'kaupang_attribute_suite_maybe_flush_rewrite_rules';
$check(has_action('init', $flush) === false && has_action('wp_loaded', $flush) !== false, 'rewrite flush runs on wp_loaded, not init (an exit between them used to lose it)');
$check(!get_option('kaupang_attribute_suite_flush_rewrite_rules'), 'no rewrite flush left pending after wp_loaded');

$rules = get_option('rewrite_rules') ?: array();
$check(isset($rules['^01/([^/]+)(/.*)?$']) && (bool) preg_grep('#^opprinnelser/#', array_keys($rules)), 'plugin rules present (/01/ GTIN, /opprinnelser/ pages)');

// Route a path the way a request would: the global WP knows the registered query vars.
$route = function ($path) {
    $server = $_SERVER;
    $_SERVER['REQUEST_URI'] = $path;
    unset($_SERVER['PATH_INFO']);
    $wp = clone $GLOBALS['wp'];
    $wp->query_vars = array();
    $wp->parse_request();
    $_SERVER = $server;
    return $wp->query_vars;
};

$base = wc_get_permalink_structure()['attribute_rewrite_slug'];
foreach (wc_get_attribute_taxonomies() as $attribute) {
    $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
    $on       = 1 === (int) $attribute->attribute_public;
    $state    = $on ? 'on' : 'off';
    $object   = get_taxonomy($taxonomy);
    $check($object && $object->public === $on && (bool) $object->query_var === $on && (bool) $object->rewrite === $on,
        "{$taxonomy} archives {$state}: registered public/query_var/rewrite {$state}");

    $prefix = ltrim(trailingslashit($base) . urldecode(sanitize_title($attribute->attribute_name)), '/') . '/';
    $check((bool) preg_grep('#^' . preg_quote($prefix, '#') . '#', array_keys($rules)) === $on,
        "{$taxonomy} archives {$state}: rewrite rules for /{$prefix} " . ($on ? 'present' : 'absent'));

    $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 50));
    if (empty($terms) || is_wp_error($terms)) {
        echo "skip {$taxonomy}: no terms\n";
        continue;
    }

    $vars = $route('/' . $prefix . $terms[0]->slug . '/');
    if ($on) {
        $query = new WP_Query($vars + array('posts_per_page' => 1, 'fields' => 'ids'));
        $hit   = $query->get_queried_object();
        $check(($vars[$taxonomy] ?? '') === $terms[0]->slug && $query->is_tax($taxonomy) && $hit instanceof WP_Term && $hit->term_id === $terms[0]->term_id,
            "{$taxonomy} archives on: /{$prefix}{$terms[0]->slug}/ resolves to its term archive");
    } else {
        $check(!isset($vars[$taxonomy]), "{$taxonomy} archives off: /{$prefix}{$terms[0]->slug}/ does not route to the term");
    }

    // "Learn more" for a term without a rich page: its archive when on, no link when off.
    foreach ($terms as $term) {
        if (kaupang_attribute_suite_get_cached_attribute_page($term->slug)) {
            continue;
        }
        $url  = kaupang_attribute_suite_get_learn_more_url($term);
        $want = $on ? get_term_link($term) : '';
        $check($url === $want, "{$taxonomy} archives {$state}: learn-more link for {$term->slug} = " . ($url === '' ? "'' (none)" : $url));
        break;
    }
}

exit($fail);
