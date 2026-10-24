/**
 * Kaupang Attribute Suite — origin modal opener.
 *
 * The only origin-modal script that loads with the page (deferred). It remembers each variations form's last
 * variation event and, on the first click of an origin CTA, imports the modal code (origin-modal.js, an ES module)
 * and the radar, then hands both over. Hovering or focusing a CTA starts the import early.
 *
 * Config (inline, printed before this script): window.wcRasOriginModal = { modal: url, radar: url }.
 *
 * @package Kaupang\AttributeSuite
 */
(function ($) {
    'use strict';

    var cfg = window.wcRasOriginModal || {};
    var loading = null;

    function load() {
        if (!loading) {
            loading = Promise.all([import(cfg.modal), import(cfg.radar)]).then(function (mods) {
                return mods[0];
            });
            loading.catch(function () {
                loading = null; // let the next click retry
            });
        }
        return loading;
    }

    function ctaFrom(target) {
        return target && target.closest ? target.closest('[data-open-origin-modal]') : null;
    }

    // The modal mirrors the current selection: keep the last event so the first open can catch up.
    $(document).on('found_variation show_variation reset_data hide_variation', 'form.variations_form', function (e, variation) {
        $(this).data('wcRasOriginLast', { type: e.type, variation: variation || null });
    });

    document.addEventListener('click', function (e) {
        var cta = ctaFrom(e.target);
        var form = cta && cta.closest('form.variations_form');
        if (!form || !document.querySelector('[data-wc-ras-origin-modal]')) {
            return; // no dialog on the page: let the link navigate
        }
        e.preventDefault();
        load().then(function (modal) {
            modal.toggle(form, $(form).data('wcRasOriginLast'));
        });
    });

    function warm(e) {
        if (ctaFrom(e.target)) {
            load();
        }
    }
    document.addEventListener('pointerover', warm, { passive: true });
    document.addEventListener('focusin', warm);
})(jQuery);
