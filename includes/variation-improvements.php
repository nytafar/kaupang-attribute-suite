<?php
/**
 * Variation Improvements
 *
 * Enhances WooCommerce variations with additional functionality.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Class for managing variation improvements
 */
class Kaupang_Attribute_Suite_Variation_Improvements {

    /**
     * Constructor
     */
    public function __construct() {
        // Only initialize if enabled
        if (apply_filters('kaupang/attribute-suite/enable_variation_improvements', true)) {
            $this->init();
        }
    }

    /**
     * Initialize hooks
     */
    public function init() {
        // Enable description fallback if the feature is enabled
        if (apply_filters('kaupang/attribute-suite/enable_variation_description_fallback', true)) {
            add_filter('woocommerce_available_variation', array($this, 'variation_description_fallback'), 10, 3);
        }

        // Enable Mix and Match support if the feature is enabled
        if (apply_filters('kaupang/attribute-suite/enable_mnm_description_support', true) && class_exists('WC_Mix_and_Match')) {
            add_action('wc_mnm_child_item_details', array($this, 'mnm_variation_description_support'), 105, 2);
        }
        
        // Add meta display in product summary (disabled by default)
        if (apply_filters('kaupang/attribute-suite/enable_variation_meta_display', false)) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_variation_display_script'));
        }
        
        // Register scripts but don't enqueue them yet
        add_action('wp_enqueue_scripts', array($this, 'register_scripts'));
    }

    /**
     * Register scripts without enqueueing them
     */
    public function register_scripts() {
        wp_register_script(
            'wc-ras-variation-display',
            KAUPANG_ATTRIBUTE_SUITE_URL . 'assets/js/variation-display.js',
            array('jquery', 'wc-add-to-cart-variation'),
            KAUPANG_ATTRIBUTE_SUITE_VERSION,
            true
        );
    }
    
    /**
     * Enqueue variation display script for separate meta display
     */
    public function enqueue_variation_display_script() {
        // Only on product pages
        if (!is_product()) {
            return;
        }
        
        wp_enqueue_script('wc-ras-variation-display');
    }
    
    /**
     * Fallback to Attribute Term Description When Variation Description Is Absent
     * 
     * When a variation does not have a product-level description defined, this function
     * will provide a fallback to display the selected attribute term's description.
     *
     * @param array                $variation_data The variation data
     * @param WC_Product_Variable  $product       The parent product
     * @param WC_Product_Variation $variation     The variation product
     * @return array Modified variation data
     */
    public function variation_description_fallback($variation_data, $product, $variation) {
        // Respect an explicit admin-set variation description — skip enrichment.
        if (!empty($variation_data['variation_description'])) {
            return $variation_data;
        }

        $attributes = $variation->get_attributes();
        if (empty($attributes)) {
            return $variation_data;
        }

        $origin           = null;
        $description_text = '';
        $cta_url          = '';

        foreach ($attributes as $attribute_name => $attribute_value) {
            if (empty($attribute_value)) {
                continue;
            }
            $taxonomy = str_replace('attribute_', '', $attribute_name);
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $term = get_term_by('slug', $attribute_value, $taxonomy);
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $page = kaupang_attribute_suite_get_cached_attribute_page($term->slug);
            $is_origin_attribute = ($taxonomy === 'pa_opprinnelse');

            // First origin wins (typically pa_opprinnelse).
            if ($is_origin_attribute && $page && !$origin) {
                $origin = kaupang_attribute_suite_build_origin_struct($page);
                if (!empty($origin['permalink'])) {
                    $cta_url = $origin['permalink'];
                }
            }

            // Body text fallback — first non-empty across all attributes.
            if ($description_text === '') {
                $term_description = trim((string) $term->description);
                if ($term_description !== '') {
                    $description_text = $term_description;
                } elseif ($page) {
                    $content = !empty($page->post_excerpt) ? $page->post_excerpt : $page->post_content;
                    if (!empty($content)) {
                        $description_text = wp_trim_words($content, 30, '...');
                    }
                }
            }

            // CTA URL is only valid when the selected origin has a rich page.
            if ($cta_url === '' && $is_origin_attribute && $page) {
                $cta_url = (string) get_permalink($page);
            }
        }

        // Nothing to render.
        if (!$origin && $description_text === '') {
            return $variation_data;
        }

        if (!apply_filters('kaupang/attribute-suite/show_variation_description_links', true)) {
            $cta_url = '';
        }

        $html = kaupang_attribute_suite_load_template('parts/variation-description', array(
            'origin'           => $origin,
            'description_text' => $description_text,
            'cta_url'          => $cta_url,
            'cta_label'        => __('Lær mer', 'kaupang-attribute-suite'),
        ));

        if ($html !== '') {
            $variation_data['variation_description'] = $html;
        }

        return $variation_data;
    }

    /**
     * Add support for Mix and Match products to use attribute term descriptions
     * when variation descriptions are absent
     *
     * @param WC_MNM_Child_Item $child_item The child item
     * @param WC_Product        $product    The parent product
     */
    public function mnm_variation_description_support($child_item, $product) {
        // Check if short descriptions are enabled in Mix and Match settings
        if (!function_exists('wc_string_to_bool') || !wc_string_to_bool(get_option('wc_mnm_display_short_description', 'no'))) {
            return;
        }

        $child_product = $child_item->get_product();
        if (!$child_product || !$child_product->is_type('variation')) {
            return;
        }

        // Get variation description using WooCommerce's method
        $variation_description = $child_product->get_description();
        
        // If variation description is empty, try to get attribute term descriptions
        if (empty($variation_description)) {
            $term_descriptions = array();
            $term_page_links = array();
            
            // Get all attributes for this variation
            $attributes = $child_product->get_attributes();
            
            if (!empty($attributes)) {
                foreach ($attributes as $attribute_name => $attribute_value) {
                    // Skip if attribute value is empty
                    if (empty($attribute_value)) {
                        continue;
                    }
                    
                    // Get the attribute taxonomy name (e.g., 'pa_color')
                    $taxonomy = str_replace('attribute_', '', $attribute_name);
                    
                    // Skip non-taxonomy attributes
                    if (!taxonomy_exists($taxonomy)) {
                        continue;
                    }
                    
                    // Get the term object
                    $term = get_term_by('slug', $attribute_value, $taxonomy);
                    
                    // Skip if term doesn't exist
                    if (!$term || is_wp_error($term)) {
                        continue;
                    }
                    
                    // First check if term has a description
                    $term_description = trim($term->description);
                    
                    if (!empty($term_description)) {
                        // Use the term description
                        $term_descriptions[] = $term_description;
                        
                        // Get the term's linked page if any (legacy support)
                        $page_id = get_term_meta($term->term_id, 'linked_page_id', true);
                        $custom_url = get_term_meta($term->term_id, 'custom_page_url', true);
                        
                        // Add page link if available
                        if (!empty($page_id) || !empty($custom_url)) {
                            $link_url = !empty($page_id) ? get_permalink($page_id) : $custom_url;
                            $link_text = !empty($page_id) ? get_the_title($page_id) : __('Learn more', 'kaupang-attribute-suite');
                            
                            $term_page_links[] = '<a href="' . esc_url($link_url) . '" class="term-page-link">' . esc_html($link_text) . '</a>';
                        } else {
                            // Rich page, else term archive; '' keeps the index paired with its description.
                            $learn_more = kaupang_attribute_suite_get_learn_more_url($term);
                            $term_page_links[] = $learn_more === '' ? '' : '<a href="' . esc_url($learn_more) . '" class="term-page-link">' .
                                                 esc_html__('Learn more', 'kaupang-attribute-suite') . '</a>';
                        }
                    } else {
                        // Fallback to attribute page if no term description exists
                        $attribute_page = kaupang_attribute_suite_get_cached_attribute_page($term->slug);
                        
                        if ($attribute_page) {
                            // Use the attribute page excerpt if available, otherwise use the content
                            $content = !empty($attribute_page->post_excerpt) ? $attribute_page->post_excerpt : $attribute_page->post_content;
                            
                            if (!empty($content)) {
                                $term_descriptions[] = wp_trim_words($content, 30, '...');

                                // Add link to attribute page (CPT permalink)
                                $term_page_links[] = '<a href="' . esc_url(kaupang_attribute_suite_get_learn_more_url($term)) . '" class="term-page-link">' .
                                                     esc_html__('Learn more', 'kaupang-attribute-suite') . '</a>';
                            }
                        }
                    }
                }
            }

            // If we found any term descriptions, use the first one as fallback
            if (!empty($term_descriptions)) {
                // Format with paragraph tags to match standard variation descriptions
                $variation_description = '<p>' . $term_descriptions[0] . '</p>';
                
                // Add page link if available and enabled
                if (!empty($term_page_links[0]) && apply_filters('kaupang/attribute-suite/show_variation_description_links', true)) {
                    $variation_description .= '<p class="term-page-link-wrapper">' . $term_page_links[0] . '</p>';
                }
                
                // Allow combining all term descriptions if enabled
                if (apply_filters('kaupang/attribute-suite/combine_all_term_descriptions', false) && count($term_descriptions) > 1) {
                    $variation_description = '<p>' . implode('</p><p>', $term_descriptions) . '</p>';
                    
                    // Add all links if showing links is enabled
                    if (apply_filters('kaupang/attribute-suite/show_variation_description_links', true) && array_filter($term_page_links)) {
                        $variation_description .= '<p class="term-page-link-wrapper">' . 
                                                 implode(' | ', array_filter($term_page_links)) . '</p>';
                    }
                }
            }
        }
        
        // Display the description if we have one
        if (!empty($variation_description)) {
            echo '<div class="mnm-child-item-short-description">';
            echo wp_kses_post($variation_description);
            echo '</div>';
        }
    }
}

// Initialize the class
new Kaupang_Attribute_Suite_Variation_Improvements();

/**
 * Resolve the canonical "Learn more" URL for an attribute term.
 *
 * Priority: attribute_page CPT permalink (canonical as of 1.3.0), with
 * graceful fallback to the term archive if no matching CPT exists yet —
 * only when the attribute has "Enable archives" on; otherwise '' (no link).
 *
 * @param WP_Term $term
 * @return string
 */
function kaupang_attribute_suite_get_learn_more_url($term) {
    if (!$term || is_wp_error($term)) {
        return '';
    }
    $page = function_exists('kaupang_attribute_suite_get_cached_attribute_page')
        ? kaupang_attribute_suite_get_cached_attribute_page($term->slug)
        : null;
    if ($page) {
        return (string) get_permalink($page);
    }
    return is_taxonomy_viewable($term->taxonomy) ? (string) get_term_link($term) : '';
}
