/**
 * Archived variations — Variations panel toggle (includes/archived-variations.php).
 *
 * The panel lists published variations only; the toggle adds a flag to Woo's own
 * `woocommerce_load_variations` request (and to `woocommerce_bulk_edit_variations`, so bulk actions act
 * on what the panel shows) and reloads through Woo's `reload` event, so Woo's variation script is not
 * forked. Every load answers with fresh "published,archived" counts in a header; the panel's total,
 * pagination and the toggle label follow them (a save that archives or re-enables a variation changes both).
 */
/* global kaupangAttributeSuiteArchived, woocommerce_admin_meta_boxes_variations */
jQuery(function ($) {
    'use strict';

    var cfg = kaupangAttributeSuiteArchived;
    var woo = woocommerce_admin_meta_boxes_variations;

    // Row-header "Aktivert": a mirror of Woo's variable_enabled[loop] (the field that gets saved). Ticking it
    // ticks the real one and fires Woo's change, which marks the row for Save; the real one updates the mirror.
    var enabled = 'input[name^="variable_enabled["]';
    $('#variable_product_options .woocommerce_variations')
        .on('click', '.kaupang-attribute-suite-variation-enabled', function (event) {
            event.stopPropagation(); // don't expand/collapse the row (Woo's handlers sit on an ancestor)
        })
        .on('change', '.kaupang-attribute-suite-variation-enabled input', function () {
            $(this).closest('.woocommerce_variation').find(enabled).prop('checked', this.checked).trigger('change');
        })
        .on('change', enabled, function () {
            $(this).closest('.woocommerce_variation').find('.kaupang-attribute-suite-variation-enabled input').prop('checked', this.checked);
        });

    var $toggle = $('.kaupang-attribute-suite-archived-toggle');
    if (!$toggle.length) {
        return;
    }

    var showing = false;
    var pendingToggle = false; // toggle once the save the merchant asked for has reloaded the list
    var archived = parseInt($toggle.attr('data-archived'), 10) || 0;
    var $panel = $('#variable_product_options');
    var $wrapper = $panel.find('.woocommerce_variations');

    // Into the top toolbar, next to "Add manually": the one toolbar Woo keeps visible on an empty list.
    $panel.find('.toolbar-top .add_variation_manually').after($toggle);

    function isAction(data, action) {
        return typeof data === 'string' && new RegExp('(^|&)action=' + action + '(&|$)').test(data);
    }

    function perPage() {
        return parseInt(woo.variations_per_page, 10) || 15;
    }

    function renderToggle() {
        $toggle
            .text((showing ? cfg.hide : cfg.show).replace('%d', archived))
            .attr('aria-pressed', showing ? 'true' : 'false')
            // jQuery's inline display, not `hidden`: `.wp-core-ui .button { display: inline-block }` beats the attribute.
            .toggle(showing || archived > 0);
    }

    // Woo's pagenav helpers live in its closure; mirror what set_paginav(), change_classes() and
    // show_hide_variation_empty_state() do.
    function setTotal(total) {
        var pages = Math.ceil(total / perPage());
        var page = Math.min(parseInt($wrapper.attr('data-page'), 10) || 1, Math.max(pages, 1));
        var $nav = $('.variations-pagenav');
        var $actions = $('.variation_actions');
        var options = '';

        $wrapper.attr('data-total', total).attr('data-total_pages', pages);
        $nav.find('.displaying-num').text(
            total === 1 ? woo.i18n_variation_count_single : woo.i18n_variation_count_plural.replace('%qty%', total)
        );
        $nav.find('.total-pages').text(pages);
        for (var i = 1; i <= pages; i++) {
            options += '<option value="' + i + '">' + i + '</option>';
        }
        $nav.find('.page-selector').html(options).val(page);
        $nav.find('.first-page, .prev-page').toggleClass('disabled', page === 1);
        $nav.find('.next-page, .last-page').toggleClass('disabled', page >= pages);

        $actions.val('bulk_actions');
        if (0 === total) {
            $panel.find('.toolbar').not('.toolbar-top, .toolbar-buttons').hide();
            $nav.hide();
            $('option, optgroup', $actions).hide();
            $('option[data-global="true"]', $actions).show();
        } else {
            $panel.find('.toolbar').show();
            $nav.show();
            $('option, optgroup', $actions).show();
            $nav.find('.pagination-links').toggle(pages > 1);
        }
        $('#variable_product_options_inner').toggleClass('no-variations', 0 === total);
        $('#field_to_edit').toggleClass('hidden', 0 === total);
    }

    function toggle() {
        var current = parseInt($wrapper.attr('data-total'), 10) || 0;
        showing = !showing;
        // Woo's reload: page 1, pagination rebuilt from data-total; the header then confirms the counts.
        $wrapper.attr('data-total', current + (showing ? archived : -archived));
        renderToggle();
        $panel.trigger('reload');
    }

    $.ajaxPrefilter(function (options) {
        if (showing && (isAction(options.data, 'woocommerce_load_variations') || isAction(options.data, 'woocommerce_bulk_edit_variations'))) {
            options.data += '&' + encodeURIComponent(cfg.param) + '=1';
        }
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!isAction(settings.data, 'woocommerce_load_variations')) {
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

        if (pendingToggle) {
            pendingToggle = false;
            toggle();
            return;
        }

        var total = published + (showing ? archived : 0);
        if (total !== parseInt($wrapper.attr('data-total'), 10)) {
            setTotal(total);
        }

        // Archiving every row of the last page leaves it empty: step back to the last page that exists.
        var pages = Math.ceil(total / perPage());
        if (pages > 0 && (parseInt($wrapper.attr('data-page'), 10) || 1) > pages) {
            $('.variations-pagenav .page-selector').val(pages).first().trigger('change');
        }
    });

    $toggle.on('click', function () {
        if ($wrapper.find('.variation-needs-update').length) {
            // Woo's own prompt ("Save changes before changing page?"), with Woo's page-selector meaning:
            // OK saves through Woo's save button, then toggles; Cancel stays put with the edits intact.
            if (!window.confirm(woo.i18n_edited_variations)) {
                return;
            }
            pendingToggle = true;
            $panel.find('.save-variation-changes').first().trigger('click');
            return;
        }
        toggle();
    });
});
