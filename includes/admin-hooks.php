<?php
/**
 * Admin Hooks
 *
 * Handles admin UI integration for attribute pages.
 *
 * @package Kaupang\AttributeSuite
 */

defined('ABSPATH') || exit;

/**
 * Add a link from attribute term edit screen to its content page
 *
 * @param WP_Term $term The term being edited
 */
function kaupang_attribute_suite_add_term_edit_link($term) {
    // Only proceed if this is a WooCommerce product attribute taxonomy
    if (strpos($term->taxonomy, 'pa_') !== 0) {
        return;
    }

    // Look for matching attribute page
    $linked_page = get_page_by_path($term->slug, OBJECT, 'attribute_page');
    
    if ($linked_page) {
        $edit_url = get_edit_post_link($linked_page->ID);
        ?>
        <tr class="form-field">
            <th scope="row" valign="top">
                <label><?php _e('Rich Content', 'kaupang-attribute-suite'); ?></label>
            </th>
            <td>
                <a href="<?php echo esc_url($edit_url); ?>" class="button">
                    <?php _e('Edit Rich Content', 'kaupang-attribute-suite'); ?>
                </a>
                <p class="description">
                    <?php _e('Edit the rich content page for this attribute term using the block editor.', 'kaupang-attribute-suite'); ?>
                </p>
            </td>
        </tr>
        <?php
    } else {
        // No page exists yet, offer to create one
        $create_url = admin_url('post-new.php?post_type=attribute_page&attribute_term=' . $term->slug . '&attribute_taxonomy=' . $term->taxonomy);
        ?>
        <tr class="form-field">
            <th scope="row" valign="top">
                <label><?php _e('Rich Content', 'kaupang-attribute-suite'); ?></label>
            </th>
            <td>
                <a href="<?php echo esc_url($create_url); ?>" class="button">
                    <?php _e('Create Rich Content Page', 'kaupang-attribute-suite'); ?>
                </a>
                <p class="description">
                    <?php _e('No rich content page exists for this attribute term. Click to create one.', 'kaupang-attribute-suite'); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}

// Add the link to all product attribute taxonomy term edit screens
function kaupang_attribute_suite_register_term_edit_hooks() {
    $attribute_taxonomies = wc_get_attribute_taxonomies();
    
    if (!empty($attribute_taxonomies)) {
        foreach ($attribute_taxonomies as $taxonomy) {
            $taxonomy_name = wc_attribute_taxonomy_name($taxonomy->attribute_name);
            add_action("{$taxonomy_name}_edit_form_fields", 'kaupang_attribute_suite_add_term_edit_link', 10, 1);
        }
    }
}
add_action('admin_init', 'kaupang_attribute_suite_register_term_edit_hooks');

/**
 * Add custom columns to the attribute_page post type admin list
 *
 * @param array $columns Existing columns
 * @return array Modified columns
 */
function kaupang_attribute_suite_add_attribute_page_columns($columns) {
    $new_columns = array();
    
    // Insert columns after title but before date
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        if ($key === 'title') {
            $new_columns['attribute_taxonomy'] = __('Attribute Type', 'kaupang-attribute-suite');
            $new_columns['attribute_term'] = __('Attribute Term', 'kaupang-attribute-suite');
            $new_columns['region'] = __('Region', 'kaupang-attribute-suite');
            $new_columns['smak'] = __('Smak', 'kaupang-attribute-suite');
            $new_columns['producer_type'] = __('Producer', 'kaupang-attribute-suite');
        }
    }
    
    return $new_columns;
}
add_filter('manage_attribute_page_posts_columns', 'kaupang_attribute_suite_add_attribute_page_columns');

/**
 * Populate custom columns in the attribute_page post type admin list
 *
 * @param string $column Column name
 * @param int    $post_id Post ID
 */
function kaupang_attribute_suite_populate_attribute_page_columns($column, $post_id) {
    switch ($column) {
        case 'attribute_taxonomy':
            $taxonomy = get_post_meta($post_id, '_attribute_taxonomy', true);
            if ($taxonomy) {
                $taxonomy_label = str_replace('pa_', '', $taxonomy);
                echo esc_html(ucfirst($taxonomy_label));
            } else {
                echo '—';
            }
            break;
            
        case 'attribute_term':
            $term_id = get_post_meta($post_id, '_attribute_term_id', true);
            $taxonomy = get_post_meta($post_id, '_attribute_taxonomy', true);
            
            if ($term_id && $taxonomy) {
                $term = get_term($term_id, $taxonomy);
                if (!is_wp_error($term) && $term) {
                    $term_url = get_edit_term_link($term_id, $taxonomy);
                    echo '<a href="' . esc_url($term_url) . '">' . esc_html($term->name) . '</a>';
                } else {
                    echo esc_html(get_the_title($post_id));
                }
            } else {
                // Fallback to post slug if no term info stored
                $post = get_post($post_id);
                echo esc_html($post->post_name);
            }
            break;
            
        case 'region':
        case 'smak':
            // Display meta value
            $value = get_post_meta($post_id, $column, true);
            echo esc_html($value ? $value : '—');
            break;

        case 'producer_type':
            $value = get_post_meta($post_id, 'producer_type', true);
            if (!$value) {
                echo '—';
                break;
            }
            $types = function_exists('kaupang_attribute_suite_producer_types') ? kaupang_attribute_suite_producer_types() : array();
            echo esc_html(isset($types[$value]) ? $types[$value] : $value);
            break;
    }
}
add_action('manage_attribute_page_posts_custom_column', 'kaupang_attribute_suite_populate_attribute_page_columns', 10, 2);

/**
 * Add meta box for attribute page fields
 */
function kaupang_attribute_suite_add_attribute_page_meta_box() {
    add_meta_box(
        'kaupang_attribute_suite_attribute_meta',
        __('Attribute Properties', 'kaupang-attribute-suite'),
        'kaupang_attribute_suite_render_attribute_page_meta_box',
        'attribute_page',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'kaupang_attribute_suite_add_attribute_page_meta_box');

/**
 * Render meta box for attribute page fields
 *
 * @param WP_Post $post Current post object
 */
function kaupang_attribute_suite_render_attribute_page_meta_box($post) {
    wp_nonce_field('kaupang_attribute_suite_save_attribute_meta', 'kaupang_attribute_suite_attribute_meta_nonce');
    
    $region = get_post_meta($post->ID, 'region', true);
    $smak = get_post_meta($post->ID, 'smak', true);
    
    ?>
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="kaupang_attribute_suite_region"><?php _e('Region', 'kaupang-attribute-suite'); ?></label>
            </th>
            <td>
                <input type="text" id="kaupang_attribute_suite_region" name="kaupang_attribute_suite_region" value="<?php echo esc_attr($region); ?>" class="regular-text" />
                <p class="description"><?php _e('The region for this attribute (e.g., geographical location)', 'kaupang-attribute-suite'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="kaupang_attribute_suite_smak"><?php _e('Smak', 'kaupang-attribute-suite'); ?></label>
            </th>
            <td>
                <input type="text" id="kaupang_attribute_suite_smak" name="kaupang_attribute_suite_smak" value="<?php echo esc_attr($smak); ?>" class="regular-text" />
                <p class="description"><?php _e('The taste profile for this attribute', 'kaupang-attribute-suite'); ?></p>
            </td>
        </tr>
    </table>
    <?php
    
    /**
     * Hook for adding additional fields to the attribute page meta box
     * 
     * @param WP_Post $post Current post object
     */
    do_action('kaupang/attribute-suite/attribute_page_meta_box_fields', $post);
}

/**
 * Save meta box data
 *
 * @param int $post_id Post ID
 */
function kaupang_attribute_suite_save_attribute_meta($post_id) {
    // Check if our nonce is set and verify it
    if (!isset($_POST['kaupang_attribute_suite_attribute_meta_nonce']) || !wp_verify_nonce($_POST['kaupang_attribute_suite_attribute_meta_nonce'], 'kaupang_attribute_suite_save_attribute_meta')) {
        return;
    }
    
    // Check user permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Save region
    if (isset($_POST['kaupang_attribute_suite_region'])) {
        update_post_meta($post_id, 'region', sanitize_text_field($_POST['kaupang_attribute_suite_region']));
    }
    
    // Save smak
    if (isset($_POST['kaupang_attribute_suite_smak'])) {
        update_post_meta($post_id, 'smak', sanitize_text_field($_POST['kaupang_attribute_suite_smak']));
    }
    
    /**
     * Hook for saving additional fields from the attribute page meta box
     * 
     * @param int $post_id Post ID
     */
    do_action('kaupang/attribute-suite/save_attribute_page_meta', $post_id);
}
add_action('save_post_attribute_page', 'kaupang_attribute_suite_save_attribute_meta');

/**
 * Add custom columns to product attribute term list tables
 *
 * @param array $columns Existing columns
 * @return array Modified columns
 */
function kaupang_attribute_suite_add_term_columns($columns) {
    // Only add Rich Content column - WooCommerce already has Description column
    $new_columns = array();
    
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        // Insert Rich Content column after the description column
        if ($key === 'description') {
            $new_columns['rich_content'] = __('Rich Content', 'kaupang-attribute-suite');
        }
    }
    
    return $new_columns;
}

/**
 * Populate custom columns in product attribute term list tables
 *
 * @param string $content     Column content
 * @param string $column_name Column name
 * @param int    $term_id     Term ID
 * @return string Modified column content
 */
function kaupang_attribute_suite_populate_term_columns($content, $column_name, $term_id) {
    $term = get_term($term_id);
    if (!$term || is_wp_error($term)) {
        return '—';
    }
    
    switch ($column_name) {
        case 'rich_content':
            // Look for matching attribute page
            $linked_page = get_page_by_path($term->slug, OBJECT, 'attribute_page');
            
            if ($linked_page) {
                $edit_url = get_edit_post_link($linked_page->ID);
                return '<a href="' . esc_url($edit_url) . '" class="button button-small">' . 
                       esc_html__('Edit', 'kaupang-attribute-suite') . '</a>';
            } else {
                $create_url = admin_url('post-new.php?post_type=attribute_page&attribute_term=' . $term->slug . '&attribute_taxonomy=' . $term->taxonomy);
                return '<a href="' . esc_url($create_url) . '" class="button button-small button-secondary" title="' . 
                       esc_attr__('No rich content page exists. Click to create one.', 'kaupang-attribute-suite') . '">' . 
                       esc_html__('Create', 'kaupang-attribute-suite') . '</a>';
            }
    }
    
    return $content;
}

/**
 * Register term column hooks for all product attribute taxonomies
 */
function kaupang_attribute_suite_register_term_column_hooks() {
    $attribute_taxonomies = wc_get_attribute_taxonomies();
    
    if (empty($attribute_taxonomies)) {
        return;
    }
    
    foreach ($attribute_taxonomies as $taxonomy) {
        $taxonomy_name = wc_attribute_taxonomy_name($taxonomy->attribute_name);
        add_filter("manage_edit-{$taxonomy_name}_columns", 'kaupang_attribute_suite_add_term_columns');
        add_filter("manage_{$taxonomy_name}_custom_column", 'kaupang_attribute_suite_populate_term_columns', 10, 3);
    }
}
add_action('admin_init', 'kaupang_attribute_suite_register_term_column_hooks');

/**
 * Add description textarea to quick edit form via JavaScript
 * 
 * WooCommerce disables quick edit for attribute terms by default.
 * We inject the description field via JavaScript since the quick_edit_custom_box
 * hook doesn't fire for taxonomies with show_in_quick_edit = false.
 */
function kaupang_attribute_suite_add_quick_edit_description_js() {
    global $pagenow;
    
    if ($pagenow !== 'edit-tags.php') {
        return;
    }
    
    $taxonomy = isset($_GET['taxonomy']) ? sanitize_text_field($_GET['taxonomy']) : '';
    if (strpos($taxonomy, 'pa_') !== 0) {
        return;
    }
    
    $taxonomy = sanitize_text_field($_GET['taxonomy']);
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var taxonomy = '<?php echo esc_js($taxonomy); ?>';
        
        // Hook into inlineEditTax to add our field
        if (typeof inlineEditTax !== 'undefined') {
            var originalEdit = inlineEditTax.edit;
            var originalSave = inlineEditTax.save;
            
            inlineEditTax.edit = function(id) {
                // Call original
                originalEdit.apply(this, arguments);
                
                if (typeof id === 'object') {
                    id = this.getId(id);
                }
                
                var $editRow = $('#edit-' + id);
                var $tagRow = $('#tag-' + id);
                
                // Check if we already added the description field
                if ($editRow.find('textarea[name="kaupang_attribute_suite_description"]').length === 0) {
                    // Create description field
                    var descriptionHtml = '<fieldset class="wc-ras-description-field">' +
                        '<div class="inline-edit-col">' +
                        '<label>' +
                        '<span class="title"><?php echo esc_js(__('Description', 'kaupang-attribute-suite')); ?></span>' +
                        '<span class="input-text-wrap">' +
                        '<textarea name="kaupang_attribute_suite_description" rows="3" class="ptitle" style="width:100%;"></textarea>' +
                        '</span>' +
                        '</label>' +
                        '</div>' +
                        '</fieldset>';
                    
                    // Insert after slug field
                    var $slugField = $editRow.find('input[name="slug"]').closest('label');
                    if ($slugField.length) {
                        $slugField.closest('fieldset').after(descriptionHtml);
                    }
                }
                
                // Get description from the description column
                var description = $tagRow.find('td.description').text().trim();
                if (description === 'No description' || description === '—') {
                    description = '';
                }
                $editRow.find('textarea[name="kaupang_attribute_suite_description"]').val(description);
                
                // Store term ID for save
                $editRow.data('wc-ras-term-id', id);
            };
            
            // Override save to also save description
            inlineEditTax.save = function(id) {
                if (typeof id === 'object') {
                    id = this.getId(id);
                }
                
                var $editRow = $('#edit-' + id);
                var description = $editRow.find('textarea[name="kaupang_attribute_suite_description"]').val();
                var termId = $editRow.data('wc-ras-term-id') || id;
                
                // Save description via our custom AJAX
                if (typeof wcRasDescNonce !== 'undefined') {
                    $.post(ajaxurl, {
                        action: 'kaupang_attribute_suite_save_term_description',
                        nonce: wcRasDescNonce,
                        term_id: termId,
                        taxonomy: taxonomy,
                        description: description
                    });
                }
                
                // Call original save
                return originalSave.apply(this, arguments);
            };
        }
    });
    </script>
    <?php
}
add_action('admin_footer', 'kaupang_attribute_suite_add_quick_edit_description_js');

/**
 * AJAX handler to save term description
 */
function kaupang_attribute_suite_ajax_save_term_description() {
    // Verify nonce
    check_ajax_referer('kaupang_attribute_suite_save_description', 'nonce');
    
    $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
    $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
    $description = isset($_POST['description']) ? wp_kses_post($_POST['description']) : '';
    
    if (!$term_id || !$taxonomy) {
        wp_send_json_error('Missing required parameters');
    }
    
    // Verify taxonomy is a product attribute
    if (strpos($taxonomy, 'pa_') !== 0) {
        wp_send_json_error('Invalid taxonomy');
    }
    
    // Verify permission
    if (!current_user_can('edit_term', $term_id)) {
        wp_send_json_error('Permission denied');
    }
    
    // Update the term
    $result = wp_update_term($term_id, $taxonomy, array(
        'description' => $description,
    ));
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    
    wp_send_json_success(array(
        'description' => $description,
    ));
}
add_action('wp_ajax_kaupang_attribute_suite_save_term_description', 'kaupang_attribute_suite_ajax_save_term_description');

/**
 * Add nonce for description save AJAX
 */
function kaupang_attribute_suite_add_description_save_nonce() {
    global $pagenow;
    
    if ($pagenow !== 'edit-tags.php') {
        return;
    }
    
    $taxonomy = isset($_GET['taxonomy']) ? sanitize_text_field($_GET['taxonomy']) : '';
    if (strpos($taxonomy, 'pa_') !== 0) {
        return;
    }
    
    ?>
    <script type="text/javascript">
    var wcRasDescNonce = '<?php echo wp_create_nonce('kaupang_attribute_suite_save_description'); ?>';
    </script>
    <?php
}
add_action('admin_head', 'kaupang_attribute_suite_add_description_save_nonce');

/**
 * Add custom admin menu structure for Attribute Suite
 */
function kaupang_attribute_suite_customize_admin_menu() {
    global $submenu;
    
    // Get all product attributes
    $attribute_taxonomies = wc_get_attribute_taxonomies();
    
    if (!empty($attribute_taxonomies) && isset($submenu['edit.php?post_type=attribute_page'])) {
        // Remove the "Add New" submenu item
        foreach ($submenu['edit.php?post_type=attribute_page'] as $key => $item) {
            if ($item[2] === 'post-new.php?post_type=attribute_page') {
                unset($submenu['edit.php?post_type=attribute_page'][$key]);
                break;
            }
        }
        
        // Insert attribute term configuration links before "All Pages"
        $new_submenu = array();
        $inserted = false;
        
        foreach ($submenu['edit.php?post_type=attribute_page'] as $key => $item) {
            // Insert attribute links before "All Pages"
            if (!$inserted && $item[2] === 'edit.php?post_type=attribute_page') {
                // Add separator comment
                foreach ($attribute_taxonomies as $tax) {
                    $taxonomy_name = wc_attribute_taxonomy_name($tax->attribute_name);
                    $attribute_label = $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name;
                    
                    $new_submenu[] = array(
                        $attribute_label,
                        'manage_product_terms',
                        'edit-tags.php?taxonomy=' . $taxonomy_name . '&post_type=product'
                    );
                }
                $inserted = true;
            }
            
            $new_submenu[] = $item;
        }
        
        $submenu['edit.php?post_type=attribute_page'] = $new_submenu;
    }
}
add_action('admin_menu', 'kaupang_attribute_suite_customize_admin_menu', 999);

/**
 * Pre-fill attribute page data when creating from term edit screen
 * 
 * Captures URL parameters and sets up default title/slug
 */
function kaupang_attribute_suite_prefill_attribute_page_from_url() {
    global $pagenow;
    
    // Only on new post screen for attribute_page
    if ($pagenow !== 'post-new.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'attribute_page') {
        return;
    }
    
    // Check for attribute term parameters
    if (!isset($_GET['attribute_term']) || !isset($_GET['attribute_taxonomy'])) {
        return;
    }
    
    $term_slug = sanitize_text_field($_GET['attribute_term']);
    $taxonomy = sanitize_text_field($_GET['attribute_taxonomy']);
    
    // Get the term
    $term = get_term_by('slug', $term_slug, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return;
    }
    
    // Store in transient for use when post is saved
    set_transient('kaupang_attribute_suite_pending_attribute_page_' . get_current_user_id(), array(
        'term_id' => $term->term_id,
        'term_slug' => $term_slug,
        'term_name' => $term->name,
        'taxonomy' => $taxonomy,
    ), HOUR_IN_SECONDS);
    
    // Pre-fill title using JavaScript
    add_action('admin_footer', function() use ($term) {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // For classic editor
            if ($('#title').length && !$('#title').val()) {
                $('#title').val(<?php echo wp_json_encode($term->name); ?>);
            }
            // For block editor - set default title
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                var currentTitle = wp.data.select('core/editor').getEditedPostAttribute('title');
                if (!currentTitle) {
                    wp.data.dispatch('core/editor').editPost({ title: <?php echo wp_json_encode($term->name); ?> });
                }
            }
        });
        </script>
        <?php
    });
}
add_action('admin_init', 'kaupang_attribute_suite_prefill_attribute_page_from_url');
