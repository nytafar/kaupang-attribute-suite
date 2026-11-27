<?php
/**
 * Check: GS1 Digital Link routing. Exits non-zero on failure.
 * Run: gp wp <site> eval-file wp-content/plugins/kaupang-attribute-suite/tools/check-gtin-links.php [<gtin> <expected-substring>]
 */

PHP_SAPI === 'cli' || exit; // a check, never a web page (conventions.md: tests and tools)

$fail  = 0;
$check = function ($ok, $msg) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $msg . "\n";
    $fail |= !$ok;
};

$rules = get_option('rewrite_rules') ?: array();
$check(isset($rules['^01/([^/]+)(/.*)?$']), '/01/ rewrite rule registered');

$check(kaupang_attribute_suite_gtin14('4006381333931') === '04006381333931', 'valid GTIN-13 → GTIN-14');
$check(kaupang_attribute_suite_gtin14('04006381333931') === '04006381333931', 'valid GTIN-14 kept');
$check(kaupang_attribute_suite_gtin14('96385074') === '00000096385074', 'valid GTIN-8 padded');
$check(kaupang_attribute_suite_gtin14('4006381333932') === null, 'bad check digit rejected');
$check(kaupang_attribute_suite_gtin14('40063813339') === null, 'bad length rejected');
$check(kaupang_attribute_suite_gtin14('PQI') === null, 'non-digits rejected');
$check(kaupang_attribute_suite_gtin_target('04006381333931') === null, 'unknown GTIN → null (404)');

if (!empty($args[0])) {
    $gtin = kaupang_attribute_suite_gtin14($args[0]);
    $url  = $gtin ? kaupang_attribute_suite_gtin_target($gtin) : null;
    $check($url && strpos($url, $args[1] ?? '') !== false, "{$args[0]} → " . ($url ?: 'null'));
}

exit($fail);
