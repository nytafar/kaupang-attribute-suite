# Kaupang Attribute Suite

![Kaupang Attribute Suite](https://img.shields.io/badge/WooCommerce-Rich%20Attribute%20Suite-7f54b3.svg)
![WooCommerce 6.0+](https://img.shields.io/badge/WooCommerce-6.0+-a46497.svg)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4+-8892BF.svg)

## Description

Enhance WooCommerce product attribute taxonomy pages with rich, translatable, and fully editable content using native WordPress tools — without any external dependencies or plugins.

Kaupang Attribute Suite transforms standard attribute taxonomy pages into rich content experiences. It creates a seamless bridge between WooCommerce's attribute system and WordPress's powerful content editing capabilities.

## ✨ Features

- **Block Editor Support**: Full Gutenberg editing for each attribute term
- **Automatic Content Linkage**: CPT attribute_page automatically matches attribute terms by slug
- **Meta Fields per Term**: Add native fields like region, smak, etc., stored in the CPT
- **Rich Pages**: Each term's matching CPT renders at `/opprinnelser/<slug>/`
- **Admin UX Integration**: Link from term edit screen to its content page, list meta in CPT admin list
- **Translatable**: Fully compatible with WPML/Polylang, matches content by slug per language
- **Variation-level Access**: Fetch region/smak on a product variation using linked attribute page
- **Performance-optimized**: All lookups cacheable using wp_cache_get() for high-scale environments
- **Variation Description Fallback**: Automatically use attribute term descriptions when variation descriptions are empty
- **Mix and Match Support**: Extends description fallback to WooCommerce Mix and Match products
- **Inline Variation Description**: Render descriptions directly in the variations table, eliminating CLS and DOM manipulation issues
- **Attribute Term Archives follow WooCommerce**: an attribute's term archives (e.g. `/opprinnelse/<slug>/`) exist only when "Enable archives" is ticked on it (Products → Attributes); with it off there are no rewrite rules and no links to them. Rewrite rules are rebuilt on the next request after activation, deactivation or an attribute change; after a version bump, on the request after the next wp-admin page load. Check: `tools/check-attribute-archives.php`
- **Term List Enhancements**: Description and Rich Content columns in attribute term list tables
- **Quick Edit Description**: Edit term descriptions directly from the term list using Quick Edit

## 🔧 Technical Design

| Area | Implementation |
|------|----------------|
| Term → Content Mapping | Slug-matched CPT (attribute_page) |
| Content Storage | post_content + native post_meta |
| Performance | Object cache integration for all lookups |
| Editing UI | Block editor + meta box or custom fields |
| URL structure | Rich pages at `/opprinnelser/<slug>/`; WooCommerce term archives only where "Enable archives" is on |
| Extendability | Developers can register new meta fields or templates via hooks |

## Installation

1. Upload the `kaupang-attribute-suite` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Optional: in Products → Attributes, tick "Enable archives" on an attribute that should have term archive pages (e.g. `/opprinnelse/<slug>/`)
4. Edit an attribute term to access its rich content page

## 📋 Requirements

- WordPress 6.5 or newer (block editor enabled; `Requires Plugins` enforces WooCommerce)
- WooCommerce 6.0 or newer
- PHP 7.4+ (recommended 8.x)
- Object cache (optional, but performance boost)

## 🔌 Usage

### For Store Administrators

1. **Edit Attribute Content**: Go to Products → Attributes → [Your Attribute] → [Term] → Edit, then click "Edit Rich Content"
2. **Add Meta Information**: Use the "Attribute Properties" meta box to add region, taste profiles, etc.
3. **Design Rich Content**: Use the full power of the WordPress block editor to create compelling attribute pages

### For Developers

#### Adding Custom Meta Fields

```php
// Register a new meta field for attribute pages
add_action('kaupang/attribute-suite/register_attribute_page_meta_fields', function($post_type) {
    register_post_meta($post_type, 'altitude', [
        'type' => 'string',
        'description' => 'Altitude information',
        'single' => true,
        'show_in_rest' => true,
        'sanitize_callback' => 'sanitize_text_field',
    ]);
});

// Add the field to the meta box
add_action('kaupang/attribute-suite/attribute_page_meta_box_fields', function($post) {
    $altitude = get_post_meta($post->ID, 'altitude', true);
    ?>
    <tr>
        <th><label for="kaupang_attribute_suite_altitude">Altitude</label></th>
        <td>
            <input type="text" id="kaupang_attribute_suite_altitude" name="kaupang_attribute_suite_altitude" 
                   value="<?php echo esc_attr($altitude); ?>" class="regular-text">
        </td>
    </tr>
    <?php
});

// Save the field
add_action('kaupang/attribute-suite/save_attribute_page_meta', function($post_id) {
    if (isset($_POST['kaupang_attribute_suite_altitude'])) {
        update_post_meta($post_id, 'altitude', sanitize_text_field($_POST['kaupang_attribute_suite_altitude']));
    }
});
```

#### Enabling Inline Variation Description

The inline variation description feature renders descriptions directly within the variations table, eliminating Cumulative Layout Shift (CLS) and DOM manipulation issues. This feature must be explicitly enabled by the theme.

```php
// Enable inline variation description (add to theme's functions.php)
add_filter('kaupang/attribute-suite/enable_inline_variation_description', '__return_true');

// Optional: Configure which attribute to show description after
add_filter('kaupang/attribute-suite/inline_variation_description_config', function($config) {
    return array(
        // Specify attribute taxonomy, or leave empty for auto-detection
        'target_attribute' => 'pa_opprinnelse',
        // Auto-detect first attribute with term descriptions
        'auto_detect' => true,
    );
});

// Optional: Customize animation duration (in milliseconds)
add_filter('kaupang/attribute-suite/inline_description_animation_duration', function() {
    return 150; // Faster animation
});
```

**How it works:**
1. The plugin injects a hidden row into the variations table after the target attribute
2. WooCommerce's default variation template is overridden to remove the description div
3. When a variation is selected, JavaScript updates the inline row content
4. No DOM manipulation or element moving required - eliminates CLS completely

**Important:** When enabling this feature, remove any existing theme JavaScript that moves or clones the variation description element. The plugin handles everything automatically.

#### Rendering the origin card inline

The product page's origin card, without the dialog (no close button, handle or origin strip, no dialog role), for pages
of your own. Same template as the modal (`parts/origin-modal.php`), so a theme override covers both.

```php
// Before wp_head, so the stylesheet lands in <head> (the render enqueues it too, as a fallback).
kaupang_attribute_suite_enqueue_origin_sheet_assets();

$page = kaupang_attribute_suite_get_attribute_page('belize-maya-mountain');
echo kaupang_attribute_suite_render_origin_sheet(kaupang_attribute_suite_build_origin_struct($page));
// → <section class="wc-ras-origin-modal wc-ras-origin-modal--inline" …>
```

#### Origin country in structured data

Opt-in: adds `countryOfOrigin` to the product's JSON-LD Product node. Turn it on from the theme:

```php
add_filter('kaupang/attribute-suite/enable_origin_schema', '__return_true');
```

The countries come from the product's `pa_opprinnelse` terms that are actually offered (options used only by archived
variations don't count): term → attribute page (same slug) → its `origin_country` term. All or nothing: if any offered
origin has no attribute page or no country, nothing is added. One country gives `{"@type":"Country","name":…}`,
several give an array of them. On a variation URL (every `attribute_*` query arg set, as product feeds link), where
Woo describes that one variation, only the selected variation's origin counts in Woo's node; Rank Math's entity always
describes the parent, so it keeps the parent's full set. A `countryOfOrigin` already on the node
is never overwritten.

WooCommerce's markup gets it through `woocommerce_structured_data_product`. When Rank Math replaces Woo's markup that
filter never fires, so the same logic also runs on Rank Math's `rank_math/snippet/rich_snippet_product_entity` (product
from the current post); a country set by hand in Rank Math wins. Check (staging, writes fixtures):
`tools/check-origin-schema.php`.

## 🧩 Hooks and Filters

### Attribute Page Hooks

| Hook | Description |
|------|-------------|
| `kaupang_attribute_suite_register_attribute_page_meta_fields` | Register additional meta fields for attribute pages |
| `kaupang_attribute_suite_attribute_page_meta_box_fields` | Add fields to the attribute page meta box |
| `kaupang_attribute_suite_save_attribute_page_meta` | Process additional meta fields when saving |

### Variation Improvement Hooks

| Hook | Description |
|------|-------------|
| `kaupang_attribute_suite_enable_variation_improvements` | Master toggle for all variation improvements (default: true) |
| `kaupang_attribute_suite_enable_variation_description_fallback` | Enable/disable description fallback feature (default: true) |
| `kaupang_attribute_suite_enable_mnm_description_support` | Enable/disable Mix and Match support (default: true) |
| `kaupang_attribute_suite_show_variation_description_links` | Show/hide "Learn more" links in descriptions (default: true) |
| `kaupang_attribute_suite_combine_all_term_descriptions` | Combine multiple term descriptions instead of using just the first one (default: false) |
| `kaupang_attribute_suite_enable_variation_meta_display` | Enable/disable display of variation meta (region, smak) in the product summary (default: false) |
| `kaupang_attribute_suite_enable_inline_variation_description` | Enable inline description rendering in variations table (default: false, must be enabled by theme) |
| `kaupang_attribute_suite_inline_variation_description_config` | Configure inline description behavior (target_attribute, auto_detect) |
| `kaupang_attribute_suite_inline_description_animation_duration` | Animation duration in ms for showing/hiding inline description (default: 200) |
| `kaupang/attribute-suite/enable_archived_variations` | Treat disabled (private) variations as archived: hide their options on the storefront and from the admin Variations list, with a "Vis arkiverte (N)" toggle (default: true). Check: `tools/check-archived-variations.php` |
| `kaupang/attribute-suite/enable_origin_schema` | Add `countryOfOrigin` to the Product JSON-LD node (Woo and Rank Math), only when every offered origin resolves to a country (default: false, must be enabled by theme). Check: `tools/check-origin-schema.php` |

## Load map

`includes/core.php` loads on every request: CPT, taxonomy, meta and block-pattern registration, rewrite rules, cache
invalidation, and the gates below. Every other file is a module, loaded by `kaupang_attribute_suite_load()` where its
context shows up (decided at `wp` on the storefront, so before `wp_enqueue_scripts` and `template_redirect`).

| Context | Modules (files beyond main + core) | Assets | Autoloaded options |
|---|---|---|---|
| Guest page that renders nothing of ours (front page, shop, category, cart, account, pages) | none (+ the nb_NO catalogue: CPT/taxonomy labels are translated on `init`) | none | none |
| Simple product, checkout, order received | + `templates/variation-no-description.php` when a third party enqueues `wc-add-to-cart-variation` (Woo prints its variation JS template; core swaps it, as before) | none | none |
| Variable product | `variations` (template-loader, frontend-hooks, origin-render, variation-improvements, inline-variation-description, archived-variations) + `gallery` | `wc-ras-inline-description`, `wc-ras-variation-gallery-transition` (JS + CSS); JS deferred | none |
| Variable product with `pa_opprinnelse` and attribute pages | + `sheet` (origin-frontend) and the modal/description templates | + `wc-ras-origin-modal`: stylesheet + 2 KB deferred opener. `origin-modal.js` (ES module) and `origin-radar.js` are imported on the first click of an origin CTA (warmed on hover/focus) | none |
| Attribute term archive (`/opprinnelse/<slug>/`, where "Enable archives" is on) | `archived` (its `posts_where` filters the main query) | none | none |
| Origin pages (`/opprinnelser/`, single, `origin_country`/`certification` archives) | `sheet` (+ `archived` for the product counts) | `wc-ras-origin` | none |
| `/01/<GTIN>…` (GS1 link, kaupang-trace batch page) | `gtin` + `sheet` (kaupang-trace calls the render API at `template_redirect` 0) | `wc-ras-origin-modal` stylesheet when trace renders the inline card | none |
| Variation data elsewhere (`wc-ajax=get_variation`, quick views, Mix and Match children, REST, CLI) | `variations`, loaded on the first `woocommerce_available_variation` / `woocommerce_ajax_variation_threshold` / `wc_mnm_child_item_details`; `archived` on variation option lists and variable products' attributes table | none | none |
| wp-admin screens | `admin` (admin-hooks, origin-admin, origin-admin-certifications, archived-variations). Assets on their own screens only: cert picker on `certification` term screens, description nonce + quick-edit script on the `pa_*` term list, archived-variations script on the product edit screen | as listed | none |
| admin-ajax | `admin` at `admin_init`, logged-in users only (all its handlers are `wp_ajax_*`) | — | none |
| Product structured data (Woo `woocommerce_structured_data_product`, Rank Math `rank_math/snippet/rich_snippet_product_entity`), only with `enable_origin_schema` on | `schema` (origin-schema, one file; `archived` for variable products, already loaded there by `variations`), loaded when the hook fires. `tools/load-budget.php` adds the file on product pages when the flag is on | none | none |
| WP-CLI | `sheet` + `gtin` (the public API; kaupang-trace's check calls it) | — | none |
| REST, cron | core only; modules load through the lazy hooks above | — | none |

Options: `kaupang_attribute_suite_rewrite_version` is read only on `admin_init` (not autoloaded; the version check moves
a pre-2.4 autoloaded row off on the next wp-admin page load). `kaupang_attribute_suite_flush_rewrite_rules` exists only
between a queued flush and the next request's `wp_loaded`, written with autoload off.

Checks: `tools/check.php` (loading, registration, cross-context hooks, admin foreign-screen rule, storefront budget via
`wp kaupang-assets audit --plugins=kaupang-attribute-suite --budget`; budget in `tools/load-budget.php`), run as the site
user: `sudo -u <sysuser> wp --path=/var/www/<site>/htdocs eval-file wp-content/plugins/kaupang-attribute-suite/tools/check.php`.

## 🔄 Compatibility

- **Multilingual Plugins**: Compatible with WPML and Polylang
- **Cache Plugins**: Works with object cache implementations (Redis, Memcached)
- **Theme Compatibility**: Works with any WooCommerce-compatible theme

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed history of changes.

## Releasing

Suite kit: https://github.com/nytafar/kaupang-docs/tree/main/kit. The header `Version:` is the only version source; the
pre-commit hook syncs `KAUPANG_ATTRIBUTE_SUITE_VERSION` and regenerates readme.txt (never edit it). Add lines under `## [Unreleased]` in
CHANGELOG.md, then release with `sudo -u myrvann tools/release patch|minor|major [--push]`.

## 👨‍💻 Author

**Lasse Jellum** - [jellum.net](https://jellum.net)

## 📄 License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.
