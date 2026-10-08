<?php
/**
 * Origin module — render helpers.
 *
 * Shared between blurb, archive cards, and single-page header.
 * PHP-only rendering (no enqueue). Keeps markup in helpers so both
 * template files and inline usage share output.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Resolve a flag URL for an origin_country term slug.
 *
 * Strategy:
 *   1. Filter `kaupang_attribute_suite_country_flag_map` can override with a slug→URL map
 *      (for CDN or attachment-based hosting).
 *   2. Otherwise, look for `/assets/flags/{slug}.svg` in the plugin.
 *   3. Return null if nothing found. Callers must null-handle.
 *
 * @param string $slug Country term slug.
 * @return string|null
 */
function kaupang_attribute_suite_country_flag_url($slug) {
    if (empty($slug)) {
        return null;
    }

    $map = function_exists('kaupang_attribute_suite_country_flag_map') ? kaupang_attribute_suite_country_flag_map() : array();
    if (!empty($map[$slug])) {
        return $map[$slug];
    }

    $rel  = 'assets/flags/' . $slug . '.svg';
    $path = KAUPANG_ATTRIBUTE_SUITE_DIR . $rel;
    if (file_exists($path)) {
        return KAUPANG_ATTRIBUTE_SUITE_URL . $rel;
    }

    return null;
}

/**
 * Canonical URL for an origin.
 *
 * @param string $slug Term slug (matches the attribute_page post slug).
 * @return string|null Permalink, or null if no matching CPT exists.
 */
function kaupang_attribute_suite_get_origin_url($slug) {
    $page = kaupang_attribute_suite_get_cached_attribute_page($slug);
    return $page ? get_permalink($page) : null;
}

/**
 * Format an altitude struct as a human-readable string.
 *
 * @param array|null $altitude { min, max, unit } or null.
 * @return string Empty string if input invalid, else "1200 moh" / "1200–1450 moh".
 */
function kaupang_attribute_suite_format_altitude($altitude) {
    if (!is_array($altitude)) {
        return '';
    }
    $min  = isset($altitude['min']) && $altitude['min'] !== '' ? (int) $altitude['min'] : null;
    $max  = isset($altitude['max']) && $altitude['max'] !== '' ? (int) $altitude['max'] : null;
    $unit = !empty($altitude['unit']) ? $altitude['unit'] : 'm';

    // Norwegian conventional suffix for metres above sea level; keep literal "ft" for imperial.
    $suffix = ($unit === 'ft') ? ' ft' : ' moh';

    if ($min !== null && $max !== null && $min !== $max) {
        return $min . '–' . $max . $suffix;
    }
    if ($max !== null) {
        return $max . $suffix;
    }
    if ($min !== null) {
        return $min . $suffix;
    }
    return '';
}

/**
 * Count published products active for a given opprinnelse term slug — the same set its term archive lists
 * (a variable product needs a published variation with the term; see archived-variations.php).
 * Cached for 1 hour per slug; a variation changing status clears its parent's origins.
 *
 * @param string $slug pa_opprinnelse term slug.
 * @return int
 */
function kaupang_attribute_suite_origin_product_count($slug) {
    if (empty($slug)) {
        return 0;
    }

    $cache_key = 'origin_active_product_count_' . md5($slug); // key bumped when archived variations stopped counting
    $cached = wp_cache_get($cache_key, 'kaupang_attribute_suite_origin');
    if ($cached !== false) {
        return (int) $cached;
    }

    $term = get_term_by('slug', $slug, 'pa_opprinnelse');
    if (!$term || is_wp_error($term)) {
        wp_cache_set($cache_key, 0, 'kaupang_attribute_suite_origin', HOUR_IN_SECONDS);
        return 0;
    }

    $query = new WP_Query(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        // All IDs, not found_posts: Scalability Pro strips SQL_CALC_FOUND_ROWS, which left found_posts at 0.
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'kaupang_attribute_suite_active_term' => array('pa_opprinnelse', $slug),
        'tax_query'      => array(array(
            'taxonomy' => 'pa_opprinnelse',
            'field'    => 'slug',
            'terms'    => $slug,
        )),
    ));

    $count = count($query->posts);
    wp_cache_set($cache_key, $count, 'kaupang_attribute_suite_origin', HOUR_IN_SECONDS);
    return $count;
}

/**
 * Archiving or re-enabling a variation changes which origins its parent counts for.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       The post.
 */
function kaupang_attribute_suite_origin_count_on_variation_status($new_status, $old_status, $post) {
    if ('product_variation' !== $post->post_type || $new_status === $old_status || !$post->post_parent) {
        return;
    }
    foreach (wc_get_product_terms($post->post_parent, 'pa_opprinnelse', array('fields' => 'slugs')) as $slug) {
        wp_cache_delete('origin_active_product_count_' . md5($slug), 'kaupang_attribute_suite_origin');
    }
}
add_action('transition_post_status', 'kaupang_attribute_suite_origin_count_on_variation_status', 10, 3);

/**
 * Inline SVG glyph by key. Returns a string safe to echo in templates.
 * Uses currentColor so themes control hue.
 *
 * @param string $key   One of: fermentation, drying, producer, altitude, flag-placeholder.
 * @param string $class Extra CSS classes for the <svg>.
 * @return string
 */
function kaupang_attribute_suite_origin_icon($key, $class = '') {
    $class = $class ? ' ' . esc_attr($class) : '';
    $base  = 'wc-ras-icon wc-ras-icon--' . sanitize_html_class($key);

    switch ($key) {
        case 'fermentation':
            // Stylised bean / sprout
            return '<svg class="' . esc_attr($base) . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 5c2 3 2.5 7 0 10.5s-6.5 4-10 2.5c-2.5-1-3-3.5-2-6 1-3 3-4.5 5.5-5.5S15.5 2 17.5 5z"/><path d="M9 12c1.5-1 3-1 4.5 0"/></svg>';
        case 'drying':
            // Sun
            return '<svg class="' . esc_attr($base) . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="3" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="21"/><line x1="3" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="21" y2="12"/><line x1="5.6" y1="5.6" x2="7" y2="7"/><line x1="17" y1="17" x2="18.4" y2="18.4"/><line x1="5.6" y1="18.4" x2="7" y2="17"/><line x1="17" y1="7" x2="18.4" y2="5.6"/></svg>';
        case 'producer':
            // Person
            return '<svg class="' . esc_attr($base) . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.5-3.5 4.5-5 7-5s5.5 1.5 7 5"/></svg>';
        case 'altitude':
            // Mountain
            return '<svg class="' . esc_attr($base) . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 19l6-10 4 6 2-3 6 7z"/></svg>';
    }
    return '';
}

/**
 * Render a hybrid post-harvest item (icon + label + optional value + optional freetext).
 *
 * If both $value and $freetext are empty, returns empty string (caller skips).
 *
 * @param string $icon_key Icon key for kaupang_attribute_suite_origin_icon().
 * @param string $label    Translated label.
 * @param string $value    Structured value (e.g., "6 dager"). Optional.
 * @param string $freetext Free-text method description. Optional.
 * @return string
 */
function kaupang_attribute_suite_render_postharvest_item($icon_key, $label, $value = '', $freetext = '') {
    $value    = (string) $value;
    $freetext = (string) $freetext;

    if ($value === '' && $freetext === '') {
        return '';
    }

    ob_start();
    ?>
    <div class="postharvest-item">
        <div class="icon"><?php echo kaupang_attribute_suite_origin_icon($icon_key); ?></div>
        <div class="body">
            <div class="label"><?php echo esc_html($label); ?></div>
            <?php if ($value !== '') : ?>
                <div class="value"><?php echo esc_html($value); ?></div>
            <?php endif; ?>
            <?php if ($freetext !== '') : ?>
                <div class="freetext"><?php echo esc_html($freetext); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Format fermentation structured value (e.g. "Sentralisert · 6 dager").
 *
 * @param string|null $type  Enum slug (centralized|decentralized|mixed).
 * @param int|null    $days  Days count.
 * @return string
 */
function kaupang_attribute_suite_format_fermentation_value($type, $days) {
    $parts = array();
    if ($type) {
        $types = function_exists('kaupang_attribute_suite_fermentation_types') ? kaupang_attribute_suite_fermentation_types() : array();
        $parts[] = isset($types[$type]) ? $types[$type] : $type;
    }
    if ($days) {
        $parts[] = sprintf(
            _n('%d dag', '%d dager', (int) $days, 'kaupang-attribute-suite'),
            (int) $days
        );
    }
    return implode(' · ', $parts);
}

/**
 * Render the 8-axis taste radar as inline SVG.
 *
 * Drawn as printed ink: hairline rings (quarter dotted, half dashed, full
 * solid), spokes, a filled polygon with round joins, vertex marks and
 * uppercase labels. The viewBox is 5:4 (wider than tall) so side labels have
 * room without shrinking the chart. Every part carries a class the theme
 * can paint (`wc-ras-radar__ring/spoke/polygon/vertex/label`).
 *
 * Semantics:
 *   - Rings and spokes are drawn for ALL axes in kaupang_attribute_suite_taste_axes().
 *   - Value polygon connects only axes with non-null scores, in axis order.
 *     Null means "not rated" — the polygon has fewer sides; points don't
 *     collapse to center.
 *   - Labels render only for axes with non-null scores; the axis/axes at the
 *     highest score carry `wc-ras-radar__label--peak`.
 *   - If every axis is null (or the profile is empty), returns empty string.
 *
 * Mirror of window.WcRasOriginRadar.render() in assets/js/origin-radar.js —
 * both MUST produce identical markup for the same input.
 *
 * @param array $taste_profile { axis_key => 0..10 | null }.
 * @param array $options       { size:int (height; width is 1.25×), show_labels:bool, show_grid:bool }.
 * @return string Inline SVG or empty string.
 */
function kaupang_attribute_suite_render_taste_radar_svg($taste_profile, $options = array()) {
    $defaults = array(
        'size'        => 320,
        'show_labels' => true,
        'show_grid'   => true,
        'max_value'   => 10,
    );
    $opt = array_merge($defaults, $options);

    $axes = function_exists('kaupang_attribute_suite_taste_axes') ? kaupang_attribute_suite_taste_axes() : array();
    if (empty($axes) || !is_array($taste_profile)) {
        return '';
    }

    // Count rated axes
    $rated = array();
    foreach ($axes as $key => $label) {
        $val = array_key_exists($key, $taste_profile) ? $taste_profile[$key] : null;
        if ($val === null || $val === '') {
            continue;
        }
        $val = max(0, min((int) $val, $opt['max_value']));
        $rated[$key] = array('value' => $val, 'label' => $label);
    }
    if (empty($rated)) {
        return '';
    }
    $peak = max(array_column($rated, 'value'));

    $size    = (int) $opt['size'];
    $width   = (int) round($size * 1.25);
    $cx      = $width / 2;
    $cy      = $size / 2;
    $padding = $opt['show_labels'] ? max(40, $size * 0.15) : 12;
    $radius  = ($size / 2) - $padding;
    $n       = count($axes);

    // Build axis positions keyed by axis slug
    $axis_keys = array_keys($axes);
    $positions = array();
    foreach ($axis_keys as $i => $key) {
        $theta = -M_PI / 2 + (2 * M_PI * $i) / $n;
        $positions[$key] = array(
            'theta' => $theta,
            'ex'    => round($cx + $radius * cos($theta), 2),   // endpoint at value = max
            'ey'    => round($cy + $radius * sin($theta), 2),
            'label' => $axes[$key],
        );
    }

    $svg = '<svg class="wc-ras-radar" viewBox="0 0 ' . $width . ' ' . $size . '" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">';

    // Grid: half ring dashed, full ring solid, spokes
    if ($opt['show_grid']) {
        $svg .= '<g class="wc-ras-radar__grid">';
        foreach (array(0.25, 0.75) as $level) {
            $svg .= sprintf(
                '<circle class="wc-ras-radar__ring wc-ras-radar__ring--quarter" cx="%s" cy="%s" r="%s" fill="none" stroke="currentColor" stroke-opacity="0.3" stroke-width="0.75" stroke-dasharray="1 3"/>',
                $cx, $cy, round($radius * $level, 2)
            );
        }
        $svg .= sprintf(
            '<circle class="wc-ras-radar__ring wc-ras-radar__ring--half" cx="%s" cy="%s" r="%s" fill="none" stroke="currentColor" stroke-opacity="0.42" stroke-width="0.75" stroke-dasharray="2 4"/>',
            $cx, $cy, round($radius * 0.5, 2)
        );
        $svg .= sprintf(
            '<circle class="wc-ras-radar__ring wc-ras-radar__ring--full" cx="%s" cy="%s" r="%s" fill="none" stroke="currentColor" stroke-opacity="0.45" stroke-width="1"/>',
            $cx, $cy, $radius
        );
        foreach ($positions as $pos) {
            $svg .= sprintf(
                '<line class="wc-ras-radar__spoke" x1="%s" y1="%s" x2="%s" y2="%s" stroke="currentColor" stroke-opacity="0.22" stroke-width="0.75"/>',
                $cx, $cy, $pos['ex'], $pos['ey']
            );
        }
        $svg .= '</g>';
    }

    // Value polygon — rated axes only, in kaupang_attribute_suite_taste_axes() order
    $points = array();
    foreach ($axis_keys as $key) {
        if (!isset($rated[$key])) {
            continue;
        }
        $v     = $rated[$key]['value'];
        $ratio = $v / $opt['max_value'];
        $theta = $positions[$key]['theta'];
        $px    = $cx + $radius * $ratio * cos($theta);
        $py    = $cy + $radius * $ratio * sin($theta);
        $points[] = round($px, 2) . ',' . round($py, 2);
    }

    if (count($points) >= 3) {
        $svg .= '<polygon class="wc-ras-radar__polygon" points="' . implode(' ', $points) . '" fill="currentColor" fill-opacity="0.42" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>';
    } elseif (count($points) === 2) {
        // Degenerate: line only
        list($p1, $p2) = $points;
        list($x1, $y1) = explode(',', $p1);
        list($x2, $y2) = explode(',', $p2);
        $svg .= sprintf('<line class="wc-ras-radar__line" x1="%s" y1="%s" x2="%s" y2="%s" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>', $x1, $y1, $x2, $y2);
    } else {
        // Single point
        list($x, $y) = explode(',', $points[0]);
        $svg .= sprintf('<circle class="wc-ras-radar__point" cx="%s" cy="%s" r="3.5" fill="currentColor"/>', $x, $y);
    }

    // Vertex marks on rated axes
    foreach ($points as $pt) {
        list($x, $y) = explode(',', $pt);
        $svg .= sprintf('<circle class="wc-ras-radar__vertex" cx="%s" cy="%s" r="3.5" fill="currentColor"/>', $x, $y);
    }

    // Labels (only for rated axes)
    if ($opt['show_labels']) {
        $svg .= '<g class="wc-ras-radar__labels">';
        foreach ($positions as $key => $pos) {
            if (!isset($rated[$key])) {
                continue;
            }
            // Place label beyond the axis endpoint
            $label_ratio = 1.16;
            $lx = $cx + $radius * $label_ratio * cos($pos['theta']);
            $ly = $cy + $radius * $label_ratio * sin($pos['theta']);
            // text-anchor based on horizontal position
            $anchor = 'middle';
            if ($lx > $cx + 2) {
                $anchor = 'start';
            } elseif ($lx < $cx - 2) {
                $anchor = 'end';
            }
            $class = 'wc-ras-radar__label' . ($rated[$key]['value'] === $peak && $peak > 0 ? ' wc-ras-radar__label--peak' : '');
            $svg .= sprintf(
                '<text class="%s" x="%s" y="%s" text-anchor="%s" dominant-baseline="middle" font-size="12" fill="currentColor">%s</text>',
                $class, round($lx, 2), round($ly, 2), $anchor, esc_html($pos['label'])
            );
        }
        $svg .= '</g>';
    }

    $svg .= '</svg>';

    return $svg;
}
