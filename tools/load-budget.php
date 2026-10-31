<?php
// Read by `wp kaupang-assets audit --budget`. Unlisted page types fall back to '*', then to the default (2 files, nothing else).
// Every page: main file, includes/core.php (CPT/taxonomy/meta registration, cache invalidation, gates) and the nb_NO
// catalogue, which the CPT and taxonomy labels translated on init load. See README "Load map" for the rest.
$variation_template = 4; // + templates/variation-no-description.php: Woo prints its variation JS template wherever
                         // `wc-add-to-cart-variation` is enqueued (by a third party on these pages), and the suite swaps it.
// Origin schema (opt-in, read here inside WP after the theme loads): + includes/origin/origin-schema.php on product pages.
$origin_schema = apply_filters('kaupang/attribute-suite/enable_origin_schema', false) ? 1 : 0;
return [
    'autoload' => [],
    'pages'    => [
        '*'                => ['files' => 3, 'handles' => [], 'modules' => []],
        'product_simple'   => ['files' => $variation_template + $origin_schema],
        'checkout'         => ['files' => $variation_template],
        'order_received'   => ['files' => $variation_template],
        // Variable product: module `variations` (6 files) + `gallery` + the variation template.
        'product_variable' => ['files' => 11 + $origin_schema, 'handles' => ['wc-ras-variation-gallery-transition', 'wc-ras-inline-description']],
        // myrvann-cacao, a variable product with pa_opprinnelse: + `sheet` (origin-frontend) and its two templates; the
        // modal stylesheet + deferred opener (`wc-ras-origin-modal`); modal and radar code are imported on first open.
        'faq'              => ['files' => 14 + $origin_schema, 'handles' => ['wc-ras-variation-gallery-transition', 'wc-ras-inline-description', 'wc-ras-origin-modal']],
        // kaupang-trace batch URL (/01/…): `gtin` + `sheet` + the origin card template; the card's stylesheet.
        'trace'            => ['files' => 9, 'handles' => ['wc-ras-origin-modal']],
    ],
];
