# Changelog

All notable changes to the WooCommerce Rich Attribute Suite plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Inline origin card: `kaupang_attribute_suite_render_origin_sheet( array $origin, array $args = [] )` renders the modal's
  card from the same template as `<section class="wc-ras-origin-modal wc-ras-origin-modal--inline">` (no close, handle,
  strip or dialog role); `kaupang_attribute_suite_enqueue_origin_sheet_assets()` enqueues its stylesheet
  (`wc-ras-origin-modal`, now also carrying the `--inline` structure). The product-page modal output is unchanged.
- Archived variations (`includes/archived-variations.php`): a disabled (`private`) variation is archived — kept for order
  history and re-enable-able, nothing unassigned or deleted. Storefront: attribute options only archived variations use
  are no longer offered (`woocommerce_dropdown_variation_attribute_options_args`, so the native select and Ousia's tiles
  alike; a published "any" variation keeps them all) and drop out of the "Additional information" table
  (`woocommerce_display_product_attributes`). Admin: the Variations panel lists published variations only, with a
  "Vis arkiverte (N)" toggle in its toolbar; scoped to the panel's own `woocommerce_load_variations` request
  (`woocommerce_product_object_query_args`, one-shot), count via `woocommerce_admin_meta_boxes_variations_count`.
  Bulk actions act on what the panel shows: with archived hidden, "Toggle Enabled" and "Delete all variations" skip
  them (`woocommerce_bulk_edit_variations` request, one-shot `pre_get_posts`).
  Attribute term archives (`/opprinnelse/<slug>/`) list only products active for the term — a variable product needs
  a published variation with the term or "any" (`posts_where` on the main query; pagination agrees); the term page
  stays even with no products. Each collapsed variation row shows an "Aktivert" checkbox mirroring Woo's own
  `variable_enabled` field; unticked/archived rows are muted and labelled "Arkivert".
  Opt out with `kaupang/attribute-suite/enable_archived_variations`. Check: `tools/check-archived-variations.php`.

### Changed
- **Attribute term archives follow WooCommerce's per-attribute "Enable archives"** (Products → Attributes,
  `attribute_public`). The suite no longer forces every `pa_*` taxonomy public with a rewrite
  (`kaupang_attribute_suite_enable_attribute_archives` / `kaupang_attribute_suite_filter_attribute_taxonomy_args`
  removed). With archives off: no rewrite rules, the taxonomy isn't public, and the MnM "Learn more" fallback
  (`kaupang_attribute_suite_get_learn_more_url()`) returns `''` instead of an archive URL, so no link is printed.
  Rich pages (`/opprinnelser/<slug>/`), origin cards/modal, the variation description fallback, GTIN links, origin
  counts and archived variations don't depend on it. **Deploy:** tick "Enable archives" on the attributes that should
  keep term archive pages (myrvann.no: Opprinnelse) before or with this version, or `/opprinnelse/<slug>/` stops resolving. Rules are flushed on the first request after the version bump; deploying without one needs `wp rewrite flush`.
  Check: `tools/check-attribute-archives.php` (read-only).

### Fixed
- Rewrite rules after activation could miss the plugin's rules: the flush flag was cleared at `init` 99 while
  `flush_rewrite_rules()` only queues itself for `wp_loaded`, so a request exiting in between lost it for good (on
  nyta.no the first PHP hit after activating was Jetpack's dedicated sync request, which exits at `init` 200). The
  flush now runs on `wp_loaded` and clears the flag after writing. Deactivation empties `rewrite_rules` instead of
  flushing in a request that still has the plugin's rules registered (which rewrote them unchanged). Adding, editing
  or deleting an attribute queues the same flush, so toggling "Enable archives" applies on the next request rather
  than at Woo's WP-Cron flush.
- `tools/check-archived-variations.php` builds its term-archive queries with `tax_query`, so it runs with archives off.
- `kaupang_attribute_suite_origin_product_count()` returned 0 with Scalability Pro active (it strips
  `SQL_CALC_FOUND_ROWS`); it now counts IDs, matches the origin archive's list, and is cleared when a product or
  variation changes status, is deleted or saved, or its origin terms change.

## [2.1.0] - 2026-10-02

### Added
- GS1 Digital Link for label QR codes: `/01/<GTIN>[/10/<lot>][/21/<serial>]` → 307 to the product page with the
  variation preselected, looked up on Woo's native GTIN field (`includes/gtin-links.php`). Malformed GTIN or bad check
  digit → 400, unknown/unpublished → 404, query string passed through, qualifiers resolve up to the GTIN (batch ledger
  later). Responses carry `Do-Not-Cache` so GridPane's page cache doesn't pin them. Check: `tools/check-gtin-links.php`.

## [2.0.0] - 2026-09-29

Renamed WooCommerce Rich Attribute Suite → **Kaupang Attribute Suite** (Kaupang rename wave 2). Identity moved, the
content model and the front-end contract stay frozen. No back-compat aliases (all consumers are in-house; the myrvann theme
and the myrvann.no mu-plugin already listen on the new hook names).

### Changed
- **Breaking — moved:** folder/main file/text domain `kaupang-attribute-suite` (catalogues renamed, `.mo`/`.l10n.php` regenerated),
  constants `KAUPANG_ATTRIBUTE_SUITE_{VERSION,FILE,DIR,URL}`, functions `wc_ras_*` → `kaupang_attribute_suite_*`, classes
  `WC_RAS_*` → `Kaupang_Attribute_Suite_*`, public hooks `wc_ras_*` → `kaupang/attribute-suite/*`
  (`attribute_page_meta_box_fields`, `combine_all_term_descriptions`, `country_flag_map`, `enable_inline_variation_description`,
  `enable_mnm_description_support`, `enable_variation_description_fallback`, `enable_variation_gallery_transition`,
  `enable_variation_improvements`, `enable_variation_meta_display`, `fermentation_types`, `inline_description_animation_duration`,
  `inline_variation_description_config`, `origin_single_after_content`, `origin_struct`, `producer_types`,
  `register_attribute_page_meta_fields`, `save_attribute_page_meta`, `show_variation_description_links`, `taste_axes`),
  options `kaupang_attribute_suite_{rewrite_version,flush_rewrite_rules}` (activation deletes the old `wc_ras_*` rows),
  transient `kaupang_attribute_suite_pending_attribute_page_*`, admin nonces/AJAX (`kaupang_attribute_suite_save_term_description`),
  admin form field names and object-cache groups, admin handle `kaupang-attribute-suite-admin-cert-picker`, block pattern
  `kaupang-attribute-suite/origin-starter` + category `kaupang-attribute-suite`, theme override path
  `{theme}/kaupang-attribute-suite/…`, the `kaupang_attribute_suite_inline_description` flag in the
  `woocommerce_available_variation` struct (nothing reads it). Header per suite standard (GitHub Plugin URI,
  `@package Kaupang\AttributeSuite`, Requires at least 6.5); the `active_plugins` scan is replaced by
  `Requires Plugins: woocommerce` plus a `class_exists('WooCommerce')` bail that leaves the plugin inert without Woo.
  Explicit `load_plugin_textdomain` added as belt and braces (core already registers the header's Text Domain + Domain Path).
- **Kept (frozen contract):** CPT `attribute_page`, taxonomies `origin_country`/`certification`, all post/term meta, URL slugs
  `opprinnelser/*` and the forced `pa_*` rewrites, the `wc_ras_origin` key in the `woocommerce_available_variation` struct,
  front-end handles `wc-ras-*`, CSS `.wc-ras-*`/`--wc-ras-*`, JS globals `wcRas*` and `window.WcRasOriginRadar`, admin menu slugs.

## [1.3.1] - 2026-09-29

### Added
- Enriched variation description, CPT templates and an origin modal with a hydrated single card: front-of-pack card, taste radar, origin tabs, bottom sheet on phones.
- Variation gallery image fade with theme-tunable timing.
- Products with `pa_opprinnelse` use client-side variations; the modal/CTA only shows for rich origin pages.
- Declared HPOS (custom order tables) compatibility.

### Changed
- Attribute page single reworked as a paper substrate with polaroid hero; tighter, data-led mobile grid; inline spec-sheet producers section at ≥32rem.
- Modal layout: banner hero, flex-wrap body, intrinsic container-query grid, rendered as first child of the gallery wrapper; assets cache-busted by filemtime.
- Region label dropped from the variation-description flag pill.
- AJAX-loaded forms are detected with a MutationObserver instead of `ajaxComplete`.
- Adopted suite kit v2: version synced from the header, generated readme.txt, `tools/release`; standard header (GitHub Plugin URI, License GPL-2.0-or-later, Requires at least 5.5, Requires Plugins: woocommerce, WC tested up to 11.1) and cart/checkout blocks compatibility declared.
- Suite kit v2.1 (tooling, generated readme.txt); no runtime change.

### Fixed
- Modal background; prev/next selects the radio inputs correctly.
- Rewrite rules now re-flush on every version change (the gate was hard-coded to 1.3.0).

### Removed
- Stray tracked translation backup (`languages/*-backup-*.po~`).

## [1.3.0] - 2026-04-23

### Added
- Origin data model for `pa_opprinnelse`: variety, producer, fermentation, drying, 8-axis taste profile, altitude and references as registered post meta, plus `origin_country` and `certification` taxonomies and grouped admin meta boxes.
- `attribute_page` CPT is publicly queryable with an `/opprinnelser/` archive; Producer column in its list table.
- Variation data carries a nested `wc_ras_origin` struct (replaces the flat region/smak preload).

### Changed
- Admin menu renamed to "Attribute Suite" with dynamic attribute term links.
- Inline description JS migrated from jQuery to vanilla, with height and text fade on variation switch.
- Editor CSS expanded to readable source; canvas-ritual styles removed.

## [1.2.0] - 2026-01-14

### Added
- **Public Attribute Archives**: Automatically enables public archives for all WooCommerce product attribute taxonomies
  - Ensures `get_term_link()` returns proper permalink URLs instead of query-string URLs
  - Sets up rewrite rules for attribute term archives
  - Includes automatic rewrite flush on plugin activation
- **Term List Enhancements**: New columns in attribute term list tables (Products → Attributes → Configure terms)
  - Description column showing truncated term descriptions
  - Rich Content column with Edit/Create buttons for attribute pages
- **Quick Edit Description**: Edit term descriptions directly from the term list using Quick Edit
  - Adds description textarea to the quick edit form
  - Pre-populates with existing description
- **Improved Attribute Page Creation**: When creating an attribute page from the term edit screen
  - Title is pre-filled with the term name
  - Post slug is automatically set to match the term slug
  - Term linkage metadata is properly saved

### Technical
- New function `wc_ras_enable_attribute_archives()` to filter taxonomy registration
- New function `wc_ras_prefill_attribute_page_from_url()` for handling URL parameters
- New function `wc_ras_save_attribute_page_term_link()` for saving term linkage on manual creation
- New JavaScript `assets/js/admin-quick-edit.js` for quick edit description support
- Deferred rewrite flush using transient option

## [1.1.0] - 2026-01-14

### Added
- **Inline Variation Description**: New feature that renders variation descriptions directly within the variations table, eliminating Cumulative Layout Shift (CLS) and DOM manipulation issues
  - Must be explicitly enabled by theme using `add_filter('wc_ras_enable_inline_variation_description', '__return_true')`
  - Auto-detects which attribute has term descriptions, or can be configured via `wc_ras_inline_variation_description_config` filter
  - Overrides WooCommerce's variation template to remove default description div
  - Uses JavaScript to update inline content on variation change without DOM manipulation
- New filter hooks:
  - `wc_ras_enable_inline_variation_description` - Enable/disable inline description (default: false)
  - `wc_ras_inline_variation_description_config` - Configure target attribute and auto-detection
  - `wc_ras_inline_description_animation_duration` - Customize show/hide animation duration

### Technical
- New class `WC_RAS_Inline_Variation_Description` in `includes/inline-variation-description.php`
- New template `templates/variation-no-description.php` based on WooCommerce 9.3.0
- New JavaScript `assets/js/inline-variation-description.js` for handling variation updates

## [1.0.2] - 2025-05-13

### Added
- New hook `wc_ras_enable_variation_meta_display` to control whether variation meta fields are displayed in product summary (disabled by default)

### Fixed
- Fixed issue where variation meta display was not properly controlled by hooks
- Improved prioritization for variation descriptions (variation description → term description → attribute page content)
- Fixed newly created attributes to use term description instead of attribute page content
- Removed unnecessary wrapper div around attribute page content in taxonomy template

## [1.0.1] - 2025-05-13

### Added
- Variation description fallback feature that uses attribute term descriptions when variation descriptions are empty
- Support for Mix and Match products to display attribute term descriptions
- Multiple filter hooks to enable/disable specific variation improvement features:
  - `wc_ras_enable_variation_improvements` - Master toggle for all variation improvements
  - `wc_ras_enable_variation_description_fallback` - Toggle for description fallback feature
  - `wc_ras_enable_mnm_description_support` - Toggle for Mix and Match support
  - `wc_ras_show_variation_description_links` - Toggle for "Learn more" links in descriptions
  - `wc_ras_combine_all_term_descriptions` - Toggle for combining multiple term descriptions

## [1.0.0] - 2025-05-12

### Added
- Initial release of WooCommerce Rich Attribute Suite
- Custom Post Type (CPT) for attribute pages with full block editor support
- Automatic content linkage between attribute terms and CPT by slug
- Meta fields for region and taste profile (smak)
- Admin UI integration with links between term edit screen and content pages
- Custom columns in admin list view showing attribute metadata
- Frontend template override for attribute archives
- JavaScript for displaying attribute metadata on product variation changes
- Performance optimization with object caching for all attribute page lookups
- Full WooCommerce compatibility with attribute archives
- Support for all product attribute taxonomies
- Variation-level access to attribute metadata

### Technical
- Implemented helper function `wc_ras_is_product_attribute()` for detecting attribute taxonomy pages
- Added object cache integration for attribute page lookups
- Created extensible architecture with developer hooks for custom meta fields
- Implemented meta boxes for attribute properties in the admin interface
- Added template overrides with fallback to theme templates
