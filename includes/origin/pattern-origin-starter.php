<?php
/**
 * Block pattern: origin starter (kaupang-attribute-suite/origin-starter), read via filePath when the editor asks.
 *
 * Gives editors a 3-column scaffold to flesh out the narrative sections below the strukturert header
 * (produsentene / stedet / håndverket). A starting point, not a rigid structure.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

$h_producers = esc_html__('Produsentene', 'kaupang-attribute-suite');
$h_place     = esc_html__('Stedet', 'kaupang-attribute-suite');
$h_craft     = esc_html__('Håndverket', 'kaupang-attribute-suite');

$p_producers = esc_html__('Hvem dyrker kakaoen? Kooperativet, familien, den sosiale virksomheten. Skriv om menneskene bak bønnene.', 'kaupang-attribute-suite');
$p_place     = esc_html__('Hvor ligger opprinnelsen? Klima, jordsmonn, landskap, høyde. Det stedet smaken kommer fra.', 'kaupang-attribute-suite');
$p_craft     = esc_html__('Hvordan behandles bønnene? Fermentering, tørking, lokal tradisjon. Det som gjør akkurat denne opprinnelsen distinkt.', 'kaupang-attribute-suite');

echo '<!-- wp:columns -->
<div class="wp-block-columns">
    <!-- wp:column -->
    <div class="wp-block-column">
        <!-- wp:heading {"level":2} --><h2>' . $h_producers . '</h2><!-- /wp:heading -->
        <!-- wp:paragraph --><p>' . $p_producers . '</p><!-- /wp:paragraph -->
    </div>
    <!-- /wp:column -->

    <!-- wp:column -->
    <div class="wp-block-column">
        <!-- wp:heading {"level":2} --><h2>' . $h_place . '</h2><!-- /wp:heading -->
        <!-- wp:paragraph --><p>' . $p_place . '</p><!-- /wp:paragraph -->
    </div>
    <!-- /wp:column -->

    <!-- wp:column -->
    <div class="wp-block-column">
        <!-- wp:heading {"level":2} --><h2>' . $h_craft . '</h2><!-- /wp:heading -->
        <!-- wp:paragraph --><p>' . $p_craft . '</p><!-- /wp:paragraph -->
    </div>
    <!-- /wp:column -->
</div>
<!-- /wp:columns -->';
