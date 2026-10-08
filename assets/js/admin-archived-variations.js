/**
 * Archived variations — Variations panel toggle (includes/archived-variations.php).
 *
 * The panel lists published variations only; the toggle adds a flag to Woo's own
 * `woocommerce_load_variations` request and reloads through Woo's `reload` event, so Woo's
 * variation script is not forked. Every load answers with fresh "published,archived" counts in a
 * header; the panel's total, pagination and the toggle label follow them (a save that archives or
 * re-enables a variation changes both).
 */
/* global kaupangAttributeSuiteArchived, woocommerce_admin_meta_boxes_variations */
jQuery(function ($) {
    'use strict';

    var cfg = kaupangAttributeSuiteArchived;
    var $toggle = $('.kaupang-attribute-suite-archived-toggle');
    if (!$toggle.length) {
        return;
    }

    var showing = false;
    var archived = parseInt($toggle.attr('data-archived'), 10) || 0;
    var $panel = $('#variable_product_options');
    var $wrapper = $panel.find('.woocommerce_variations');

    // Into the top toolbar, next to "Add manually": the one toolbar Woo keeps visible on an empty list.
    $panel.find('.toolbar-top .add_variation_manually').after($toggle);

    function isLoadVariations(data) {
        return typeof data === 'string' && /(^|&)action=woocommerce_load_variations(&|$)/.test(data);
    }

    function renderToggle() {
        $toggle
            .text((showing ? cfg.hide : cfg.show).replace('%d', archived))
            .attr('aria-pressed', showing ? 'true' : 'false')
            .prop('hidden', !showing && archived === 0);
    }

    // Woo's pagenav helpers live in its closure; mirror what set_paginav() + change_classes() do.
    function setTotal(total) {
        var perPage = parseInt(woocommerce_admin_meta_boxes_variations.variations_per_page, 10) || 15;
        var pages = Math.max(Math.ceil(total / perPage), 1);
        var page = Math.min(parseInt($wrapper.attr('data-page'), 10) || 1, pages);
        var $nav = $('.variations-pagenav');
        var options = '';

        $wrapper.attr('data-total', total).attr('data-total_pages', Math.ceil(total / perPage));
        $nav.find('.displaying-num').text(
            total === 1
                ? woocommerce_admin_meta_boxes_variations.i18n_variation_count_single
                : woocommerce_admin_meta_boxes_variations.i18n_variation_count_plural.replace('%qty%', total)
        );
        $nav.find('.total-pages').text(pages);
        for (var i = 1; i <= pages; i++) {
            options += '<option value="' + i + '">' + i + '</option>';
        }
        $nav.find('.page-selector').html(options).val(page);
        $nav.find('.pagination-links').toggle(pages > 1);
        $nav.find('.first-page, .prev-page').toggleClass('disabled', page === 1);
        $nav.find('.next-page, .last-page').toggleClass('disabled', page === pages);
    }

    $.ajaxPrefilter(function (options) {
        if (showing && isLoadVariations(options.data)) {
            options.data += '&' + encodeURIComponent(cfg.param) + '=1';
        }
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!isLoadVariations(settings.data)) {
            return;
        }
        var counts = (xhr.getResponseHeader(cfg.header) || '').split(',');
        if (counts.length !== 2) {
            return;
        }
        var published = parseInt(counts[0], 10) || 0;
        archived = parseInt(counts[1], 10) || 0;
        if (archived === 0) {
            showing = false;
        }
        renderToggle();

        var total = published + (showing ? archived : 0);
        if (total !== parseInt($wrapper.attr('data-total'), 10)) {
            setTotal(total);
        }
    });

    $toggle.on('click', function () {
        if (
            $wrapper.find('.variation-needs-update').length &&
            !window.confirm(woocommerce_admin_meta_boxes_variations.i18n_edited_variations)
        ) {
            return;
        }
        var current = parseInt($wrapper.attr('data-total'), 10) || 0;
        showing = !showing;
        // Woo's reload: page 1, pagination rebuilt from data-total; the header then confirms the counts.
        $wrapper.attr('data-total', current + (showing ? archived : -archived));
        renderToggle();
        $panel.trigger('reload');
    });
});
