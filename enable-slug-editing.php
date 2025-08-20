<?php
/**
 * Plugin Name: Enable Slug Editing for TC Combinations
 * Description: Makes the slug field editable for tc_combination posts
 * Version: 1.0
 */

// Enable slug editing in Gutenberg editor
add_action('init', function() {
    // Ensure the post type supports slug editing
    $post_type = 'tc_combination';
    $post_type_object = get_post_type_object($post_type);
    
    if ($post_type_object) {
        // Add slug support if not already present
        if (!in_array('slug', $post_type_object->supports)) {
            add_post_type_support($post_type, 'slug');
        }
        
        // Ensure it's shown in REST
        $post_type_object->show_in_rest = true;
    }
}, 100);

// Enable slug meta box in classic editor
add_action('add_meta_boxes', function() {
    add_meta_box(
        'slugdiv',
        __('Slug'),
        'post_slug_meta_box',
        'tc_combination',
        'normal',
        'core'
    );
});

// Make sure slug field is editable in Quick Edit
add_filter('quick_edit_show_taxonomy', function($show, $taxonomy, $post_type) {
    if ($post_type === 'tc_combination') {
        return true;
    }
    return $show;
}, 10, 3);

// Enable slug editing in the admin
add_filter('wp_insert_post_data', function($data, $postarr) {
    if ($data['post_type'] === 'tc_combination' && isset($postarr['post_name'])) {
        $data['post_name'] = sanitize_title($postarr['post_name']);
    }
    return $data;
}, 10, 2);

// Add JavaScript to make slug field editable in Gutenberg
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'tc_combination') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // For Classic Editor
            if ($('#slugdiv').length) {
                $('#slugdiv').show();
                $('#edit-slug-box').show();
                $('.edit-slug').show();
            }
            
            // For Gutenberg - wait for it to load
            if (wp && wp.data && wp.data.subscribe) {
                wp.data.subscribe(function() {
                    // Make sure slug panel is visible
                    var slugPanel = document.querySelector('.edit-post-post-slug');
                    if (slugPanel) {
                        var input = slugPanel.querySelector('input');
                        if (input) {
                            input.removeAttribute('readonly');
                            input.removeAttribute('disabled');
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }
});

// Add admin notice
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'tc_combination' && $screen->base === 'post') {
        ?>
        <div class="notice notice-info">
            <p>💡 <strong>Tip:</strong> You can now edit the slug/permalink. Click on the URL below the title or look for the Slug field in the sidebar.</p>
        </div>
        <?php
    }
});
?>