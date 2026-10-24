/**
 * Kaupang Attribute Suite — origin modal.
 *
 * Model:
 *   One native <dialog>, one hydratable card. The card's content always
 *   mirrors the current pa_opprinnelse selection. The origin strip along
 *   the bottom (one tile per origin with a rich page, built here from the
 *   preloaded variations), arrow keys and swipe all commit a new selection
 *   through the same code path an attribute click uses — there is no
 *   local modal state; the strip's selected tile is the position indicator.
 *
 * Loading: an ES module, imported on the first CTA click by origin-modal-opener.js (the only script that loads with
 * the page), which calls toggle(form, lastEvent). The first call sets the form up and replays the last variation
 * event the opener saw, so the card matches the current selection.
 *
 * Pipeline:
 *   CTA click → open dialog (mobile showModal / desktop show, capped to
 *     the gallery's main image box).
 *   found_variation/show_variation → hydrateCard(origin) → updateStrip.
 *   strip tile/arrow/swipe → selectOrigin(slug) → WC fires
 *     found_variation → hydrateCard runs again.
 *
 * jQuery is used only as a bridge for WC variation events and for the
 * canonical .val().trigger('change') commit. All other DOM work is
 * vanilla.
 *
 * @package Kaupang\AttributeSuite
 */
    var DESKTOP_MQ = '(min-width: 1024px)';
    var SWIPE_THRESHOLD = 50;

    var contexts = new WeakMap();

    /**
     * Open or close the dialog for a form, setting the form up on first use.
     *
     * @param {HTMLFormElement} form
     * @param {{type:string, variation:Object|null}} [last] Last variation event on the form, from the opener.
     */
    export function toggle(form, last) {
        var ctx = contexts.get(form);
        if (!ctx) {
            ctx = setupForm(form);
            if (!ctx) {
                return;
            }
            contexts.set(form, ctx);
            if (last && (last.type === 'found_variation' || last.type === 'show_variation')) {
                if (last.variation && last.variation.wc_ras_origin) {
                    hydrateCard(ctx, last.variation.wc_ras_origin);
                }
            } else if (last) {
                hideAllGroups(ctx.card);
            }
        }
        if (ctx.dialog.open) {
            ctx.dialog.close();
        } else {
            openDialog(ctx);
        }
    }

    /**
     * Wire up a single form + its dialog.
     */
    function setupForm(form) {
        var dialog = document.querySelector('[data-wc-ras-origin-modal]');
        if (!dialog) {
            return null;
        }

        var card = dialog.querySelector('[data-origin-modal-card]');
        if (!card) {
            return null;
        }

        // PHP can only render the dialog inside .woocommerce-product-gallery__wrapper
        // (the one in-gallery filter). FlexSlider turns that wrapper into a
        // translated, overflow-hidden slide track and the main image paints over
        // it, so hoist the dialog to the gallery root (position:relative in WC
        // core) before first use. Closed dialogs are display:none — no layout shift.
        var gallery = dialog.closest('.woocommerce-product-gallery');
        if (gallery && dialog.parentNode !== gallery) {
            gallery.appendChild(dialog);
        }

        var ctx = {
            form: form,
            dialog: dialog,
            card: card,
            origins: readOrigins(form),
            gallery: gallery,
            lastMode: null
        };

        buildStrip(ctx);

        // CTA clicks: the opener calls toggle().

        // WC variation events → hydrate
        if (window.jQuery) {
            window.jQuery(form).on('found_variation show_variation', function (_event, variation) {
                var origin = variation && variation.wc_ras_origin;
                if (origin) {
                    hydrateCard(ctx, origin);
                } else if (dialog.open) {
                    // Selected origin has no rich page: nothing to mirror, so
                    // close rather than keep showing the previous origin.
                    dialog.close();
                }
            });
            window.jQuery(form).on('reset_data hide_variation', function () {
                hideAllGroups(ctx.card);
            });
        }

        // Close semantics
        dialog.addEventListener('click', function (e) {
            // Backdrop: clicking the dialog element itself (outside the card).
            if (e.target === dialog) {
                dialog.close();
                return;
            }
            var closer = e.target.closest && e.target.closest('[data-origin-modal-close]');
            if (closer) {
                e.preventDefault();
                dialog.close();
            }
        });

        // Strip tiles → selection
        dialog.addEventListener('click', function (e) {
            var tile = e.target.closest && e.target.closest('[data-origin-modal-select]');
            if (!tile) {
                return;
            }
            e.preventDefault();
            selectOrigin(ctx, tile.getAttribute('data-origin-modal-select'));
        });

        // Arrow keys while dialog is open. Escape is native only for
        // showModal(); the desktop show() path needs it wired by hand.
        dialog.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') {
                e.preventDefault();
                stepSelection(ctx, 1);
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                stepSelection(ctx, -1);
            } else if (e.key === 'Escape') {
                dialog.close();
            }
        });

        // Swipe gesture on the card
        wireSwipe(ctx);

        // Phone: drag the sheet down to close (bottom-sheet gesture)
        wireDragToClose(ctx);

        // Closing on the phone lands the user on the page's own origin
        // tiles, which mirror what was just chosen in the sheet.
        dialog.addEventListener('close', function () {
            dialog.style.transform = '';
            if (ctx.lastMode !== 'mobile') {
                return;
            }
            var ctrl = findOriginControl(form);
            var target = null;
            for (var i = 0; i < ctrl.radios.length; i++) {
                if (ctrl.radios[i].checked) {
                    target = ctrl.radios[i].closest('label') || ctrl.radios[i];
                    break;
                }
            }
            target = target || ctrl.select;
            if (target && typeof target.scrollIntoView === 'function') {
                target.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });

        // Window resize → re-evaluate desktop/mobile mode and the desktop size
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            if (resizeTimer) {
                window.clearTimeout(resizeTimer);
            }
            resizeTimer = window.setTimeout(function () {
                if (!dialog.open) {
                    return;
                }
                if (currentMode() !== ctx.lastMode) {
                    dialog.close();
                    openDialog(ctx);
                } else {
                    sizeToImage(ctx);
                }
            }, 200);
        });

        return ctx;
    }

    // ── Data helpers ────────────────────────────────────────────

    /**
     * Read the preloaded variation list and dedupe on origin slug.
     *
     * @return {Array<{slug:string,[key:string]:any}>}
     */
    function readOrigins(form) {
        var raw = form.dataset.product_variations;
        if (!raw) {
            return [];
        }
        var variations;
        try {
            variations = JSON.parse(raw);
        } catch (e) {
            return [];
        }
        if (!Array.isArray(variations)) {
            return [];
        }
        var seen = {};
        var out = [];
        for (var i = 0; i < variations.length; i++) {
            var v = variations[i];
            var origin = v && v.wc_ras_origin;
            if (origin && origin.slug && !seen[origin.slug]) {
                seen[origin.slug] = true;
                out.push(origin);
            }
        }
        return out;
    }

    /**
     * Resolve the origin attribute control(s) in the form.
     *
     * Woo's standard rendering is a single <select>, but themes and
     * plugins (e.g. Variation Swatches) often swap that for radio inputs
     * with the same name. Return both shapes so callers can branch.
     *
     * @return {{ select: HTMLSelectElement|null, radios: HTMLInputElement[] }}
     */
    function findOriginControl(form) {
        var select = form.querySelector('select[name="attribute_pa_opprinnelse"]');
        var radios = Array.prototype.slice.call(
            form.querySelectorAll('input[type="radio"][name="attribute_pa_opprinnelse"]')
        );
        return { select: select, radios: radios };
    }

    function currentOriginSlug(ctx) {
        var ctrl = findOriginControl(ctx.form);
        if (ctrl.select && ctrl.select.value) {
            return ctrl.select.value;
        }
        for (var i = 0; i < ctrl.radios.length; i++) {
            if (ctrl.radios[i].checked) {
                return ctrl.radios[i].value;
            }
        }
        // Fallback: the card's currently hydrated slug.
        return ctx.card.getAttribute('data-current-slug') || '';
    }

    /**
     * Commit a new origin choice through the WC variation pipeline.
     * found_variation will fire and hydrateCard runs.
     */
    function selectOrigin(ctx, slug) {
        if (!slug) {
            return;
        }
        var ctrl = findOriginControl(ctx.form);

        // Radios: find the specific input with this value, check it, fire change.
        if (ctrl.radios.length) {
            var target = null;
            for (var i = 0; i < ctrl.radios.length; i++) {
                if (ctrl.radios[i].value === slug) {
                    target = ctrl.radios[i];
                    break;
                }
            }
            if (!target || target.checked) {
                return;
            }
            target.checked = true;
            if (window.jQuery) {
                window.jQuery(target).trigger('change');
            } else {
                target.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }

        // Select: canonical WC path.
        if (ctrl.select && ctrl.select.value !== slug) {
            ctrl.select.value = slug;
            if (window.jQuery) {
                window.jQuery(ctrl.select).trigger('change');
            } else {
                ctrl.select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

    function stepSelection(ctx, dir) {
        var origins = ctx.origins;
        if (!origins.length || origins.length <= 1) {
            return;
        }
        var current = currentOriginSlug(ctx);
        var idx = 0;
        for (var i = 0; i < origins.length; i++) {
            if (origins[i].slug === current) {
                idx = i;
                break;
            }
        }
        var nextIdx = (idx + dir + origins.length) % origins.length;
        selectOrigin(ctx, origins[nextIdx].slug);
    }

    // ── Open / close ────────────────────────────────────────────

    function currentMode() {
        return window.matchMedia(DESKTOP_MQ).matches ? 'desktop' : 'mobile';
    }

    function openDialog(ctx) {
        var mode = currentMode();
        ctx.lastMode = mode;
        try {
            if (mode === 'desktop') {
                ctx.dialog.show();
            } else {
                ctx.dialog.showModal();
            }
        } catch (_err) {
            // Fallback: if showModal() throws (already open, etc.), force open attribute.
            ctx.dialog.setAttribute('open', '');
        }
        sizeToImage(ctx);
        updateStrip(ctx, currentOriginSlug(ctx));
    }

    /**
     * Desktop (show): the dialog is absolutely positioned over the whole
     * gallery, thumbnails included, which can run well past the viewport.
     * The sheet sizes to its content but never past the main image box
     * (FlexSlider's .flex-viewport, or the wrapper when there is no slider)
     * or what is visible below the gallery's top edge. Modal mode is
     * full-viewport by CSS; clear any inline cap there.
     */
    function sizeToImage(ctx) {
        var dialog = ctx.dialog;
        if (ctx.lastMode !== 'desktop' || !ctx.gallery) {
            dialog.style.maxHeight = '';
            return;
        }
        var box = ctx.gallery.querySelector('.flex-viewport') ||
            ctx.gallery.querySelector('.woocommerce-product-gallery__wrapper');
        if (!box) {
            dialog.style.maxHeight = '';
            return;
        }
        var rect = box.getBoundingClientRect();
        var visible = window.innerHeight - Math.max(rect.top, 0) - 8;
        dialog.style.maxHeight = Math.max(320, Math.min(rect.height, visible)) + 'px';
    }

    // ── Hydration ───────────────────────────────────────────────

    /**
     * Set the text content / attribute of a [data-field] element and
     * hide it when the value is empty.
     *
     * @param {HTMLElement} card
     * @param {string} field
     * @param {string} value
     * @param {'text'|'src'|'href'|'alt'} [mode='text']
     */
    function setField(card, field, value, mode) {
        var el = card.querySelector('[data-field="' + field + '"]');
        if (!el) {
            return;
        }
        var hasValue = value !== null && value !== undefined && value !== '';
        if (!hasValue) {
            el.hidden = true;
            return;
        }
        if (mode === 'src') {
            el.setAttribute('src', value);
        } else if (mode === 'href') {
            el.setAttribute('href', value);
        } else if (mode === 'alt') {
            el.setAttribute('alt', value);
        } else {
            el.textContent = value;
        }
        el.hidden = false;
    }

    function setGroup(card, groupName, visible) {
        var el = card.querySelector('[data-field-group="' + groupName + '"]');
        if (!el) {
            return;
        }
        el.hidden = !visible;
    }

    function s(v) {
        return (v === null || v === undefined) ? '' : String(v);
    }

    function hydrateCard(ctx, origin) {
        var card = ctx.card;

        // Track current slug so nav math works without relying on the
        // select's state (some themes swap select for buttons).
        if (origin.slug) {
            card.setAttribute('data-current-slug', origin.slug);
        }

        // Hero
        setField(card, 'name', s(origin.name));

        // Country stamp: flag + country name.
        var country = s(origin.country);
        var flagUrl = s(origin.country_flag_url);
        setGroup(card, 'flag', country !== '');
        var flagImgEl = card.querySelector('[data-field="flag-image"]');
        if (flagImgEl) {
            if (flagUrl) {
                flagImgEl.setAttribute('src', flagUrl);
                flagImgEl.hidden = false;
            } else {
                flagImgEl.hidden = true;
            }
        }
        setField(card, 'country', country);

        // Facts — a cell hides when it has nothing to say. The theme hides
        // the "more" cells (region, count, method, drying) on the phone.
        var region = s(origin.region);
        var variety = s(origin.variety);
        var altitude = s(origin.altitude_label);
        var producerType = s(origin.producer_type_label);
        var producerCount = s(origin.producer_count_label);
        var fermValue = s(origin.fermentation_value);
        var fermMethod = s(origin.fermentation_method);
        var drying = s(origin.drying_method);

        setField(card, 'region', region);
        setGroup(card, 'region', region !== '');
        setField(card, 'variety', variety);
        setGroup(card, 'variety', variety !== '');
        setField(card, 'altitude', altitude);
        setGroup(card, 'altitude', altitude !== '');
        setField(card, 'producer-type', producerType);
        setField(card, 'producer-count', producerCount);
        setGroup(card, 'producers', producerType !== '' || producerCount !== '');
        setField(card, 'fermentation-value', fermValue);
        setField(card, 'fermentation-method', fermMethod);
        setGroup(card, 'fermentation', fermValue !== '' || fermMethod !== '');
        setField(card, 'drying-method', drying);
        setGroup(card, 'drying', drying !== '');

        // Flavour
        var radarEl = card.querySelector('[data-field="radar"]');
        var radarSvg = '';
        if (origin.taste_profile && window.WcRasOriginRadar && typeof window.WcRasOriginRadar.render === 'function') {
            radarSvg = window.WcRasOriginRadar.render(origin.taste_profile) || '';
        }
        if (radarEl) {
            if (radarSvg) {
                radarEl.innerHTML = radarSvg;
                radarEl.hidden = false;
            } else {
                radarEl.innerHTML = '';
                radarEl.hidden = true;
            }
        }
        var flavourLabel = card.querySelector('[data-field="flavour-label"]');
        if (flavourLabel) {
            flavourLabel.hidden = radarSvg === '';
        }
        setField(card, 'taste-notes', s(origin.taste_notes));

        // Certification stamps
        renderCertifications(card, Array.isArray(origin.certifications) ? origin.certifications : []);

        setField(card, 'permalink', s(origin.permalink), 'href');

        updateStrip(ctx, origin.slug);
    }

    /**
     * Certification stamps: one stamp per certification with an icon.
     */
    function renderCertifications(card, certs) {
        var wrap = card.querySelector('[data-field="certifications"]');
        if (!wrap) {
            return;
        }
        wrap.innerHTML = '';
        certs.forEach(function (cert) {
            if (!cert.icon_url) {
                return;
            }
            var stamp = document.createElement('span');
            stamp.className = 'wc-ras-origin-modal__stamp wc-ras-origin-modal__stamp--cert';
            if (cert.slug) {
                stamp.setAttribute('data-cert-slug', cert.slug);
            }
            stamp.setAttribute('title', cert.name || '');
            var img = document.createElement('img');
            img.setAttribute('src', cert.icon_url);
            img.setAttribute('alt', cert.name || '');
            stamp.appendChild(img);
            wrap.appendChild(stamp);
        });
        wrap.hidden = wrap.childNodes.length === 0;
    }

    function hideAllGroups(card) {
        var rows = card.querySelectorAll('[data-field-group]');
        for (var i = 0; i < rows.length; i++) {
            rows[i].hidden = true;
        }
    }

    // ── Origin strip ────────────────────────────────────────────

    /**
     * One tile per origin with a rich page. Hidden when there is nothing
     * to switch to.
     */
    function buildStrip(ctx) {
        var strip = ctx.dialog.querySelector('[data-origin-modal-strip]');
        if (!strip) {
            return;
        }
        strip.innerHTML = '';
        ctx.origins.forEach(function (origin) {
            var tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'wc-ras-origin-modal__tile';
            tile.setAttribute('data-origin-modal-select', origin.slug);
            tile.textContent = origin.name || origin.slug;
            strip.appendChild(tile);
        });
        strip.hidden = ctx.origins.length <= 1;
    }

    /**
     * Mark the current origin's tile and keep it in view on a strip that
     * scrolls horizontally.
     */
    function updateStrip(ctx, slug) {
        var tiles = ctx.dialog.querySelectorAll('[data-origin-modal-select]');
        for (var i = 0; i < tiles.length; i++) {
            var current = tiles[i].getAttribute('data-origin-modal-select') === slug;
            if (current) {
                tiles[i].setAttribute('aria-current', 'true');
                if (ctx.dialog.open && typeof tiles[i].scrollIntoView === 'function') {
                    tiles[i].scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
            } else {
                tiles[i].removeAttribute('aria-current');
            }
        }
    }

    // ── Swipe ───────────────────────────────────────────────────

    function wireSwipe(ctx) {
        var startX = null;
        var startY = null;

        ctx.card.addEventListener('touchstart', function (e) {
            if (!e.touches || e.touches.length !== 1) {
                startX = null;
                return;
            }
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });

        ctx.card.addEventListener('touchend', function (e) {
            if (startX === null) {
                return;
            }
            var touch = (e.changedTouches && e.changedTouches[0]) || null;
            if (!touch) {
                startX = null;
                return;
            }
            var dx = touch.clientX - startX;
            var dy = touch.clientY - startY;
            startX = null;
            startY = null;
            if (Math.abs(dx) < SWIPE_THRESHOLD) {
                return;
            }
            // Require mostly-horizontal gesture so vertical scroll wins
            // when the intent is scrolling the card.
            if (Math.abs(dx) < Math.abs(dy)) {
                return;
            }
            stepSelection(ctx, dx < 0 ? 1 : -1);
        }, { passive: true });
    }

    // ── Drag to close (phone) ───────────────────────────────────

    var DRAG_CLOSE_THRESHOLD = 90;

    /**
     * A downward drag anywhere on the sheet (handle or card) pulls it down
     * and closes it past the threshold. Only while the card body is at its
     * scroll top, and only for gestures that are more vertical than
     * horizontal, so scrolling and the origin swipe keep working.
     */
    function wireDragToClose(ctx) {
        var body = ctx.card.querySelector('.wc-ras-origin-modal__body');
        var startX = null;
        var startY = null;
        var dy = 0;

        function start(e) {
            if (!e.touches || e.touches.length !== 1 || !ctx.dialog.matches(':modal') || (body && body.scrollTop > 0)) {
                startY = null;
                return;
            }
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            dy = 0;
        }

        function move(e) {
            if (startY === null) {
                return;
            }
            var dx = e.touches[0].clientX - startX;
            var y = e.touches[0].clientY - startY;
            if (y <= 0 || Math.abs(dx) > y) {
                // Upward or sideways: not a pull-down.
                if (dy === 0) {
                    return;
                }
            }
            dy = Math.max(0, y);
            ctx.dialog.style.transition = 'none';
            ctx.dialog.style.transform = 'translateY(' + dy + 'px)';
        }

        function end() {
            if (startY === null) {
                return;
            }
            startY = null;
            ctx.dialog.style.transition = '';
            if (dy > DRAG_CLOSE_THRESHOLD) {
                ctx.dialog.close();
            } else {
                ctx.dialog.style.transform = '';
            }
            dy = 0;
        }

        [ctx.dialog.querySelector('[data-origin-modal-handle]'), ctx.card].forEach(function (el) {
            if (!el) {
                return;
            }
            el.addEventListener('touchstart', start, { passive: true });
            el.addEventListener('touchmove', move, { passive: true });
            el.addEventListener('touchend', end, { passive: true });
            el.addEventListener('touchcancel', end, { passive: true });
        });
    }
