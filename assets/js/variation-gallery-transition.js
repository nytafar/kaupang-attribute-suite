/**
 * Kaupang Attribute Suite — variation gallery transition.
 *
 * Crossfades the gallery's main image when a variation is selected.
 *
 * Strategy:
 *   1. On WC's `found_variation` (jQuery event on .variations_form), snapshot
 *      the current .wp-post-image as an absolutely-positioned ghost overlay
 *      on top of the gallery container. The ghost shows the OLD image.
 *   2. WC's `wc_variations_image_update` runs synchronously *right after* our
 *      handler returns, swapping src/srcset on the underlying .wp-post-image.
 *      The ghost masks that swap entirely.
 *   3. In a microtask (after the WC swap), await `.decode()` on the now-updated
 *      in-DOM image. Only once the new bitmap is decoded do we add `.is-leaving`
 *      to the ghost, triggering its fade-out and revealing the fully-rendered
 *      new image underneath.
 *
 * The decode() gate is what eliminates the "swap, pause, fade" symptom from
 * earlier implementations that timed the fade against an `<img onload>` race.
 *
 * Theme controls visual feel via CSS custom properties on the gallery wrapper:
 *   --wc-ras-gallery-fade-duration, --wc-ras-gallery-fade-ease,
 *   --wc-ras-gallery-ghost-scale, --wc-ras-gallery-ghost-blur.
 *
 * Vanilla JS for DOM work; jQuery only as the bridge to WC's variation events.
 *
 * @package Kaupang\AttributeSuite
 */
(function () {
    'use strict';

    if (!window.jQuery) {
        return;
    }

    const $ = window.jQuery;
    const DEFAULT_DURATION_MS = 1200;
    const SAFETY_BUFFER_MS = 600;

    function normalizeUrl(value) {
        if (!value) { return ''; }
        try {
            return new URL(value, window.location.href).href;
        } catch (e) {
            return value;
        }
    }

    function parseDurationMs(value) {
        if (!value) { return 0; }
        const trimmed = String(value).trim();
        if (trimmed.endsWith('ms')) { return parseFloat(trimmed); }
        if (trimmed.endsWith('s')) { return parseFloat(trimmed) * 1000; }
        const fallback = parseFloat(trimmed);
        return isNaN(fallback) ? 0 : fallback;
    }

    function readDurationMs(container) {
        const cs = window.getComputedStyle(container);
        const raw = cs.getPropertyValue('--wc-ras-gallery-fade-duration');
        return parseDurationMs(raw) || DEFAULT_DURATION_MS;
    }

    function findGallery(form) {
        const product = form.closest('.product');
        return product ? product.querySelector('.woocommerce-product-gallery') : null;
    }

    function findFrame(container) {
        return container.querySelector(
            '.woocommerce-product-gallery__image, .woocommerce-product-gallery__image--placeholder'
        );
    }

    function findMainImage(frame) {
        return frame ? frame.querySelector('.wp-post-image') : null;
    }

    function createGhost(frame, image) {
        const frameRect = frame.getBoundingClientRect();
        const imageRect = image.getBoundingClientRect();
        if (!imageRect.width || !imageRect.height) {
            return null;
        }

        // The ghost shows the file on screen, as it is: currentSrc, no srcset to
        // choose from again. A clone with the srcset would fetch a new file when
        // the img sits in <picture> (Ousia's AVIF, ousia#42): the clone has no
        // <source>, so it picks the JPEG twin of what is showing.
        const ghost = image.cloneNode(false);
        if (image.currentSrc) {
            ghost.removeAttribute('srcset');
            ghost.removeAttribute('sizes');
            ghost.setAttribute('src', image.currentSrc);
        }
        ghost.removeAttribute('id');
        ghost.removeAttribute('loading');
        ghost.removeAttribute('decoding');
        ghost.classList.remove('wp-post-image');
        ghost.classList.add('wc-ras-variation-image-ghost');
        ghost.style.left = (imageRect.left - frameRect.left) + 'px';
        ghost.style.top = (imageRect.top - frameRect.top) + 'px';
        ghost.style.width = imageRect.width + 'px';
        ghost.style.height = imageRect.height + 'px';

        // The ghost is the image as it is drawn: a gallery that crops its
        // images (object-fit: cover in a fixed stage) would otherwise get a
        // ghost of the whole picture, smaller, on top of the cropped one.
        const drawn = window.getComputedStyle(image);
        ghost.style.objectFit = drawn.objectFit;
        ghost.style.objectPosition = drawn.objectPosition;

        frame.querySelectorAll('.wc-ras-variation-image-ghost').forEach(function (stale) {
            stale.remove();
        });
        frame.appendChild(ghost);

        // A theme rule for the gallery's images reaches the ghost too (Ousia
        // moves a stacked image sideways with a transform). Its place was
        // measured with that already applied, so it would be applied twice:
        // put the ghost back on the image by what it is off.
        const ghostRect = ghost.getBoundingClientRect();
        const offX = ghostRect.left - imageRect.left;
        const offY = ghostRect.top - imageRect.top;
        if (Math.abs(offX) > 0.01 || Math.abs(offY) > 0.01) {
            ghost.style.left = (imageRect.left - frameRect.left - offX) + 'px';
            ghost.style.top = (imageRect.top - frameRect.top - offY) + 'px';
        }
        return ghost;
    }

    function scheduleFadeOut(ghost) {
        window.requestAnimationFrame(function () {
            ghost.classList.add('is-leaving');
        });
    }

    function attachCleanup(ghost, durationMs) {
        let removed = false;
        const remove = function () {
            if (removed) { return; }
            removed = true;
            if (ghost.parentNode) {
                ghost.parentNode.removeChild(ghost);
            }
        };

        ghost.addEventListener('transitionend', function onEnd(event) {
            if (event.propertyName === 'opacity') {
                ghost.removeEventListener('transitionend', onEnd);
                remove();
            }
        });

        window.setTimeout(remove, durationMs + SAFETY_BUFFER_MS);
    }

    function runTransition(form, variationImage) {
        if (!variationImage || !variationImage.src) { return; }

        const container = findGallery(form);
        const frame = container ? findFrame(container) : null;
        const image = findMainImage(frame);
        if (!container || !frame || !image) { return; }

        // Nothing to fade when the variation's image is the one showing: the
        // page opens on its default variation, whose image is already there.
        // The src attribute says which image it is; currentSrc is the file the
        // browser chose from srcset (another width, or the AVIF twin), which
        // never equals the variation's src.
        const targetUrl = normalizeUrl(variationImage.src);
        const shownUrl = normalizeUrl(image.getAttribute('src'));
        const currentUrl = normalizeUrl(image.currentSrc || image.src);
        if (targetUrl && (shownUrl === targetUrl || currentUrl === targetUrl)) {
            return;
        }

        const ghost = createGhost(frame, image);
        if (!ghost) { return; }

        const durationMs = readDurationMs(container);
        attachCleanup(ghost, durationMs);

        // Microtask runs *after* WC's synchronous wc_variations_image_update,
        // so by the time decode() is called the in-DOM <img> already points
        // at the new src/srcset. We await decode and only then start the fade.
        window.queueMicrotask(function () {
            const liveImage = findMainImage(frame);
            if (!liveImage) {
                scheduleFadeOut(ghost);
                return;
            }
            if (typeof liveImage.decode !== 'function') {
                scheduleFadeOut(ghost);
                return;
            }
            liveImage.decode().then(
                function () { scheduleFadeOut(ghost); },
                function () { scheduleFadeOut(ghost); }
            );
        });
    }

    function init() {
        $('form.variations_form').each(function () {
            const $form = $(this);
            $form.on('found_variation.wcRasGalleryTransition', function (event, variation) {
                runTransition(this, variation && variation.image);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
