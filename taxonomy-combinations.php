<?php
/**
 * Plugin Name: Taxonomy Combination Pages with Blocksy
 * Description: Creates real pages for taxonomy combinations with full WordPress compatibility
 * Version: 3.0
 * Author: maikunari
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class TaxonomyCombinationPages {
    
    // Configuration - Update these for your setup
    private $post_type = 'healthcare_provider';
    private $taxonomy_1 = 'specialties';
    private $taxonomy_2 = 'location';  // Note: singular 'location' not 'locations'
    private $cpt_slug = 'tc_combination';
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'taxonomy_combinations';
        
        // Core hooks
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_post_meta'));
        
        // Admin interface
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // Auto-generation hooks
        add_action('created_' . $this->taxonomy_1, array($this, 'generate_combinations_for_new_term'), 10, 2);
        add_action('created_' . $this->taxonomy_2, array($this, 'generate_combinations_for_new_term'), 10, 2);
        
        // Shortcodes
        add_shortcode('tc_field', array($this, 'shortcode_tc_field'));
        add_shortcode('tc_posts', array($this, 'shortcode_tc_posts'));
        
        // URL handling for english- prefix
        add_filter('post_type_link', array($this, 'modify_combination_permalink'), 10, 2);
        add_action('init', array($this, 'add_rewrite_rules'), 0);
        
        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
    }
    
    /**
     * Register Custom Post Type
     */
    public function register_post_type() {
        // Only register if not already registered by CPT UI
        if (!post_type_exists($this->cpt_slug)) {
            register_post_type($this->cpt_slug, array(
                'labels' => array(
                    'name' => 'Combinations',
                    'singular_name' => 'Combination',
                    'add_new' => 'Add New',
                    'add_new_item' => 'Add New Combination',
                    'edit_item' => 'Edit Combination',
                    'new_item' => 'New Combination',
                    'view_item' => 'View Combination',
                    'search_items' => 'Search Combinations',
                    'not_found' => 'No combinations found',
                    'not_found_in_trash' => 'No combinations found in trash'
                ),
                'public' => true,
                'publicly_queryable' => true,
                'show_ui' => false, // We'll manage through our own interface
                'show_in_menu' => false,
                'show_in_rest' => true,
                'rest_base' => 'tc_combination',
                'has_archive' => false,
                'rewrite' => false, // We'll handle our own rewrites
                'supports' => array('title', 'editor', 'custom-fields', 'revisions'),
                'capability_type' => 'page'
            ));
        }
    }
    
    /**
     * Register Post Meta Fields
     */
    public function register_post_meta() {
        // Register meta fields for REST API visibility
        $meta_fields = array(
            '_tc_location_id' => 'integer',
            '_tc_specialty_id' => 'integer',
            '_tc_brief_intro' => 'string',
            '_tc_full_description' => 'string',
            '_tc_header_block_id' => 'integer',
            '_tc_content_block_id' => 'integer',
            '_tc_footer_block_id' => 'integer',
            '_tc_seo_title' => 'string',
            '_tc_seo_description' => 'string'
        );
        
        foreach ($meta_fields as $meta_key => $type) {
            register_post_meta($this->cpt_slug, $meta_key, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => $type,
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                }
            ));
        }
    }
    
    /**
     * Add Rewrite Rules
     */
    public function add_rewrite_rules() {
        // Handle URLs like /english-dentistry-in-shibuya/
        add_rewrite_rule(
            '^english-([^/]+)-in-([^/]+)/?$',
            'index.php?post_type=' . $this->cpt_slug . '&name=english-$matches[1]-in-$matches[2]',
            'top'
        );
    }
    
    /**
     * Modify Combination Permalink
     */
    public function modify_combination_permalink($permalink, $post) {
        if ($post->post_type !== $this->cpt_slug) {
            return $permalink;
        }
        
        // Ensure URL has english- prefix
        if (strpos($post->post_name, 'english-') !== 0) {
            $permalink = home_url('/english-' . $post->post_name . '/');
        } else {
            $permalink = home_url('/' . $post->post_name . '/');
        }
        
        return $permalink;
    }
    
    /**
     * Plugin Activation
     */
    public function activate_plugin() {
        $this->register_post_type();
        flush_rewrite_rules();
        
        // Check if migration is needed
        $this->maybe_migrate_from_virtual_pages();
    }
    
    /**
     * Admin Menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Taxonomy Combinations',
            'Tax Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page'),
            'dashicons-networking',
            30
        );
        
        add_submenu_page(
            'taxonomy-combinations',
            'Bulk Edit',
            'Bulk Edit',
            'manage_options',
            'tc-bulk-edit',
            array($this, 'bulk_edit_page')
        );
        
        add_submenu_page(
            'taxonomy-combinations',
            'Settings',
            'Settings',
            'manage_options',
            'tc-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'taxonomy-combinations',
            'Migrate Data',
            'Migrate Data',
            'manage_options',
            'tc-migrate',
            array($this, 'migrate_page')
        );
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') !== false || strpos($hook, 'tc-') !== false) {
            wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '3.0');
            wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '3.0', true);
        }
    }
    
    /**
     * Main Admin Page
     */
    public function admin_page() {
        // Get all combination posts
        $args = array(
            'post_type' => $this->cpt_slug,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'any'
        );
        
        // Add filters if set
        if (!empty($_GET['location'])) {
            $args['meta_key'] = '_tc_location_id';
            $args['meta_value'] = intval($_GET['location']);
        }
        
        if (!empty($_GET['specialty'])) {
            $args['meta_key'] = '_tc_specialty_id';
            $args['meta_value'] = intval($_GET['specialty']);
        }
        
        $combinations = get_posts($args);
        
        ?>
        <div class="wrap">
            <h1>
                Taxonomy Combinations
                <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="page-title-action">Bulk Edit</a>
                <a href="<?php echo admin_url('admin.php?page=tc-generate'); ?>" class="page-title-action">Generate Missing</a>
            </h1>
            
            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" action="">
                    <input type="hidden" name="page" value="taxonomy-combinations">
                    
                    <select name="location">
                        <option value="">All Locations</option>
                        <?php
                        $locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
                        foreach ($locations as $location) {
                            $selected = (!empty($_GET['location']) && $_GET['location'] == $location->term_id) ? 'selected' : '';
                            echo '<option value="' . $location->term_id . '" ' . $selected . '>' . esc_html($location->name) . '</option>';
                        }
                        ?>
                    </select>
                    
                    <select name="specialty">
                        <option value="">All Specialties</option>
                        <?php
                        $specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
                        foreach ($specialties as $specialty) {
                            $selected = (!empty($_GET['specialty']) && $_GET['specialty'] == $specialty->term_id) ? 'selected' : '';
                            echo '<option value="' . $specialty->term_id . '" ' . $selected . '>' . esc_html($specialty->name) . '</option>';
                        }
                        ?>
                    </select>
                    
                    <input type="submit" class="button" value="Filter">
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Location</th>
                        <th>Specialty</th>
                        <th>Provider Count</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($combinations as $post) : 
                        $location_id = get_post_meta($post->ID, '_tc_location_id', true);
                        $specialty_id = get_post_meta($post->ID, '_tc_specialty_id', true);
                        $location = get_term($location_id, $this->taxonomy_2);
                        $specialty = get_term($specialty_id, $this->taxonomy_1);
                        
                        // Get provider count
                        $provider_query = new WP_Query(array(
                            'post_type' => $this->post_type,
                            'posts_per_page' => 1,
                            'tax_query' => array(
                                'relation' => 'AND',
                                array(
                                    'taxonomy' => $this->taxonomy_1,
                                    'field' => 'term_id',
                                    'terms' => $specialty_id
                                ),
                                array(
                                    'taxonomy' => $this->taxonomy_2,
                                    'field' => 'term_id',
                                    'terms' => $location_id
                                )
                            )
                        ));
                        $provider_count = $provider_query->found_posts;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($post->post_title); ?></strong>
                                <div class="row-actions">
                                    <span class="view">
                                        <a href="<?php echo get_permalink($post->ID); ?>" target="_blank">View</a> |
                                    </span>
                                    <span class="edit">
                                        <a href="<?php echo admin_url('post.php?post=' . $post->ID . '&action=edit'); ?>">Edit</a> |
                                    </span>
                                    <span class="trash">
                                        <a href="<?php echo get_delete_post_link($post->ID); ?>">Trash</a>
                                    </span>
                                </div>
                            </td>
                            <td><?php echo $location ? esc_html($location->name) : 'N/A'; ?></td>
                            <td><?php echo $specialty ? esc_html($specialty->name) : 'N/A'; ?></td>
                            <td style="text-align: center;"><?php echo $provider_count; ?></td>
                            <td><?php echo esc_html($post->post_status); ?></td>
                            <td>
                                <a href="<?php echo admin_url('post.php?post=' . $post->ID . '&action=edit'); ?>" class="button button-small">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        if (isset($_POST['bulk_update'])) {
            $this->process_bulk_update($_POST);
        }
        
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="10">
                                    <?php
                                    $locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
                                    foreach ($locations as $location) {
                                        echo '<option value="' . $location->term_id . '">' . esc_html($location->name) . '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="10">
                                    <?php
                                    $specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
                                    foreach ($specialties as $specialty) {
                                        echo '<option value="' . $specialty->term_id . '">' . esc_html($specialty->name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Header Content Block</th>
                            <td>
                                <select name="bulk_header_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove —</option>
                                    <?php
                                    $blocks = get_posts(array(
                                        'post_type' => 'ct_content_block',
                                        'posts_per_page' => -1,
                                        'post_status' => 'publish'
                                    ));
                                    foreach ($blocks as $block) {
                                        echo '<option value="' . $block->ID . '">' . esc_html($block->post_title) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Main Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove —</option>
                                    <?php foreach ($blocks as $block) : ?>
                                        <option value="<?php echo $block->ID; ?>"><?php echo esc_html($block->post_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Footer Content Block</th>
                            <td>
                                <select name="bulk_footer_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove —</option>
                                    <?php foreach ($blocks as $block) : ?>
                                        <option value="<?php echo $block->ID; ?>"><?php echo esc_html($block->post_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Post Status</th>
                            <td>
                                <select name="bulk_post_status">
                                    <option value="">— No Change —</option>
                                    <option value="publish">Published</option>
                                    <option value="draft">Draft</option>
                                    <option value="private">Private</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="bulk_update" class="button-primary" value="Apply Bulk Changes">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        if (!wp_verify_nonce($data['tc_nonce'], 'tc_bulk_edit')) {
            return;
        }
        
        // Build query args
        $args = array(
            'post_type' => $this->cpt_slug,
            'posts_per_page' => -1,
            'post_status' => 'any'
        );
        
        // Apply location filter
        if (!empty($data['filter_locations'])) {
            $args['meta_query'][] = array(
                'key' => '_tc_location_id',
                'value' => array_map('intval', $data['filter_locations']),
                'compare' => 'IN'
            );
        }
        
        // Apply specialty filter
        if (!empty($data['filter_specialties'])) {
            $args['meta_query'][] = array(
                'key' => '_tc_specialty_id',
                'value' => array_map('intval', $data['filter_specialties']),
                'compare' => 'IN'
            );
        }
        
        if (isset($args['meta_query']) && count($args['meta_query']) > 1) {
            $args['meta_query']['relation'] = 'AND';
        }
        
        $posts = get_posts($args);
        $updated = 0;
        
        foreach ($posts as $post) {
            // Update meta fields
            if ($data['bulk_header_block_id'] !== '') {
                update_post_meta($post->ID, '_tc_header_block_id', $data['bulk_header_block_id'] === '0' ? '' : intval($data['bulk_header_block_id']));
            }
            if ($data['bulk_content_block_id'] !== '') {
                update_post_meta($post->ID, '_tc_content_block_id', $data['bulk_content_block_id'] === '0' ? '' : intval($data['bulk_content_block_id']));
            }
            if ($data['bulk_footer_block_id'] !== '') {
                update_post_meta($post->ID, '_tc_footer_block_id', $data['bulk_footer_block_id'] === '0' ? '' : intval($data['bulk_footer_block_id']));
            }
            
            // Update post status
            if (!empty($data['bulk_post_status'])) {
                wp_update_post(array(
                    'ID' => $post->ID,
                    'post_status' => $data['bulk_post_status']
                ));
            }
            
            $updated++;
        }
        
        echo '<div class="notice notice-success"><p>Updated ' . $updated . ' combinations!</p></div>';
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('tc_default_header_block', intval($_POST['default_header_block']));
            update_option('tc_default_content_block', intval($_POST['default_content_block']));
            update_option('tc_default_footer_block', intval($_POST['default_footer_block']));
            update_option('tc_auto_generate', isset($_POST['auto_generate']) ? 1 : 0);
            update_option('tc_default_status', sanitize_text_field($_POST['default_status']));
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <h2>Default Content Blocks for New Combinations</h2>
                <table class="form-table">
                    <tr>
                        <th>Default Header Block</th>
                        <td>
                            <select name="default_header_block">
                                <option value="">— None —</option>
                                <?php
                                $blocks = get_posts(array('post_type' => 'ct_content_block', 'posts_per_page' => -1, 'post_status' => 'publish'));
                                $current = get_option('tc_default_header_block');
                                foreach ($blocks as $block) {
                                    $selected = ($current == $block->ID) ? 'selected' : '';
                                    echo '<option value="' . $block->ID . '" ' . $selected . '>' . esc_html($block->post_title) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Default Main Content Block</th>
                        <td>
                            <select name="default_content_block">
                                <option value="">— None —</option>
                                <?php
                                $current = get_option('tc_default_content_block');
                                foreach ($blocks as $block) {
                                    $selected = ($current == $block->ID) ? 'selected' : '';
                                    echo '<option value="' . $block->ID . '" ' . $selected . '>' . esc_html($block->post_title) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Default Footer Block</th>
                        <td>
                            <select name="default_footer_block">
                                <option value="">— None —</option>
                                <?php
                                $current = get_option('tc_default_footer_block');
                                foreach ($blocks as $block) {
                                    $selected = ($current == $block->ID) ? 'selected' : '';
                                    echo '<option value="' . $block->ID . '" ' . $selected . '>' . esc_html($block->post_title) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h2>Auto-Generation Settings</h2>
                <table class="form-table">
                    <tr>
                        <th>Auto-Generate Combinations</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_generate" value="1" <?php checked(get_option('tc_auto_generate', 1)); ?>>
                                Automatically create combination pages when new terms are added
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Default Post Status</th>
                        <td>
                            <select name="default_status">
                                <?php
                                $current = get_option('tc_default_status', 'publish');
                                ?>
                                <option value="publish" <?php selected($current, 'publish'); ?>>Published</option>
                                <option value="draft" <?php selected($current, 'draft'); ?>>Draft</option>
                                <option value="private" <?php selected($current, 'private'); ?>>Private</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
            
            <hr>
            
            <h2>Generate Missing Combinations</h2>
            <p>Click the button below to generate any missing combination pages.</p>
            <form method="post" action="<?php echo admin_url('admin.php?page=tc-migrate&action=generate'); ?>">
                <?php wp_nonce_field('tc_generate', 'tc_nonce'); ?>
                <p class="submit">
                    <input type="submit" name="generate" class="button-secondary" value="Generate Missing Combinations">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Migration Page
     */
    public function migrate_page() {
        // Handle generation request
        if (isset($_GET['action']) && $_GET['action'] === 'generate') {
            $this->generate_all_combinations();
            return;
        }
        
        // Handle migration request
        if (isset($_POST['migrate'])) {
            $this->migrate_from_database();
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>Data Migration</h1>
            
            <?php
            // Check if old table exists
            global $wpdb;
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
            
            if ($table_exists) :
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            ?>
                <div class="notice notice-info">
                    <p>Found <?php echo $count; ?> combinations in the old database table.</p>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('tc_migrate', 'tc_nonce'); ?>
                    <p>This will migrate all data from the old virtual pages system to real WordPress posts.</p>
                    <p class="submit">
                        <input type="submit" name="migrate" class="button-primary" value="Start Migration">
                    </p>
                </form>
            <?php else : ?>
                <div class="notice notice-success">
                    <p>No old data found. System is using the new post-based structure.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Migrate from Database Table to Posts
     */
    private function migrate_from_database() {
        global $wpdb;
        
        if (!wp_verify_nonce($_POST['tc_nonce'], 'tc_migrate')) {
            return;
        }
        
        $rows = $wpdb->get_results("SELECT * FROM {$this->table_name}");
        $migrated = 0;
        $skipped = 0;
        
        foreach ($rows as $row) {
            // Check if combination already exists as post
            $existing = get_posts(array(
                'post_type' => $this->cpt_slug,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => '_tc_location_id',
                        'value' => $row->location_id
                    ),
                    array(
                        'key' => '_tc_specialty_id',
                        'value' => $row->specialty_id
                    )
                ),
                'posts_per_page' => 1
            ));
            
            if (!empty($existing)) {
                $skipped++;
                continue;
            }
            
            // Get term objects
            $location = get_term($row->location_id, $this->taxonomy_2);
            $specialty = get_term($row->specialty_id, $this->taxonomy_1);
            
            if (!$location || !$specialty) {
                continue;
            }
            
            // Create post
            $post_data = array(
                'post_type' => $this->cpt_slug,
                'post_title' => !empty($row->custom_title) ? $row->custom_title : 'English ' . $specialty->name . ' in ' . $location->name,
                'post_name' => 'english-' . $specialty->slug . '-in-' . $location->slug,
                'post_content' => $row->custom_content ?: '',
                'post_status' => 'publish',
                'meta_input' => array(
                    '_tc_location_id' => $row->location_id,
                    '_tc_specialty_id' => $row->specialty_id,
                    '_tc_brief_intro' => $row->brief_intro ?: '',
                    '_tc_full_description' => $row->full_description ?: $row->custom_description ?: '',
                    '_tc_header_block_id' => $row->header_content_block_id ?: '',
                    '_tc_content_block_id' => $row->content_block_id ?: '',
                    '_tc_footer_block_id' => $row->footer_content_block_id ?: '',
                    '_tc_seo_title' => $row->meta_title ?: '',
                    '_tc_seo_description' => $row->meta_description ?: '',
                    '_tc_migrated' => true
                )
            );
            
            $post_id = wp_insert_post($post_data);
            
            if (!is_wp_error($post_id)) {
                $migrated++;
            }
        }
        
        echo '<div class="notice notice-success"><p>Migration complete! Migrated ' . $migrated . ' combinations. Skipped ' . $skipped . ' existing.</p></div>';
        
        // Option to delete old table
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('tc_cleanup', 'tc_nonce'); ?>
            <p>Migration successful. You can now safely delete the old database table.</p>
            <p class="submit">
                <input type="submit" name="delete_table" class="button-secondary" value="Delete Old Table" onclick="return confirm('Are you sure? This cannot be undone.');">
            </p>
        </form>
        <?php
        
        if (isset($_POST['delete_table']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_cleanup')) {
            $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
            echo '<div class="notice notice-success"><p>Old table deleted successfully!</p></div>';
        }
    }
    
    /**
     * Generate All Combinations
     */
    public function generate_all_combinations() {
        $locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        $created = 0;
        $existing = 0;
        
        foreach ($locations as $location) {
            foreach ($specialties as $specialty) {
                if ($this->create_combination_post($location->term_id, $specialty->term_id)) {
                    $created++;
                } else {
                    $existing++;
                }
            }
        }
        
        echo '<div class="notice notice-success"><p>Generation complete! Created ' . $created . ' new combinations. ' . $existing . ' already existed.</p></div>';
    }
    
    /**
     * Generate Combinations for New Term
     */
    public function generate_combinations_for_new_term($term_id, $tt_id) {
        if (!get_option('tc_auto_generate', 1)) {
            return;
        }
        
        $term = get_term($term_id);
        if (!$term) return;
        
        // Determine which taxonomy this is
        if ($term->taxonomy === $this->taxonomy_1) {
            // New specialty - create combinations with all locations
            $locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
            foreach ($locations as $location) {
                $this->create_combination_post($location->term_id, $term_id);
            }
        } elseif ($term->taxonomy === $this->taxonomy_2) {
            // New location - create combinations with all specialties
            $specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
            foreach ($specialties as $specialty) {
                $this->create_combination_post($term_id, $specialty->term_id);
            }
        }
    }
    
    /**
     * Create Combination Post
     */
    private function create_combination_post($location_id, $specialty_id) {
        // Check if already exists
        $existing = get_posts(array(
            'post_type' => $this->cpt_slug,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_tc_location_id',
                    'value' => $location_id
                ),
                array(
                    'key' => '_tc_specialty_id',
                    'value' => $specialty_id
                )
            ),
            'posts_per_page' => 1
        ));
        
        if (!empty($existing)) {
            return false;
        }
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        // Create post
        $post_data = array(
            'post_type' => $this->cpt_slug,
            'post_title' => 'English ' . $specialty->name . ' in ' . $location->name,
            'post_name' => 'english-' . $specialty->slug . '-in-' . $location->slug,
            'post_content' => '',
            'post_status' => get_option('tc_default_status', 'publish'),
            'meta_input' => array(
                '_tc_location_id' => $location_id,
                '_tc_specialty_id' => $specialty_id,
                '_tc_header_block_id' => get_option('tc_default_header_block', ''),
                '_tc_content_block_id' => get_option('tc_default_content_block', ''),
                '_tc_footer_block_id' => get_option('tc_default_footer_block', ''),
                '_tc_seo_title' => 'English ' . $specialty->name . ' in ' . $location->name . ' | Healthcare',
                '_tc_seo_description' => 'Find English-speaking ' . strtolower($specialty->name) . ' in ' . $location->name . '. Compare healthcare providers ranked by English communication ability.'
            )
        );
        
        $post_id = wp_insert_post($post_data);
        
        return !is_wp_error($post_id);
    }
    
    /**
     * Check if Migration is Needed
     */
    private function maybe_migrate_from_virtual_pages() {
        global $wpdb;
        
        // Check if old table exists with data
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if ($table_exists) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            if ($count > 0) {
                // Check if migration has been done
                $migrated_count = get_posts(array(
                    'post_type' => $this->cpt_slug,
                    'meta_key' => '_tc_migrated',
                    'posts_per_page' => 1
                ));
                
                if (empty($migrated_count)) {
                    // Show admin notice about migration
                    add_action('admin_notices', function() use ($count) {
                        ?>
                        <div class="notice notice-warning">
                            <p><strong>Taxonomy Combinations:</strong> Found <?php echo $count; ?> combinations that need to be migrated to the new system. 
                            <a href="<?php echo admin_url('admin.php?page=tc-migrate'); ?>">Migrate Now</a></p>
                        </div>
                        <?php
                    });
                }
            }
        }
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        global $post;
        
        if (!$post || $post->post_type !== $this->cpt_slug) {
            return '';
        }
        
        $atts = shortcode_atts(array(
            'field' => 'title'
        ), $atts);
        
        switch ($atts['field']) {
            case 'title':
                return $post->post_title;
                
            case 'brief_intro':
                return get_post_meta($post->ID, '_tc_brief_intro', true);
                
            case 'full_description':
                return get_post_meta($post->ID, '_tc_full_description', true);
                
            case 'location':
                $location_id = get_post_meta($post->ID, '_tc_location_id', true);
                $location = get_term($location_id, $this->taxonomy_2);
                return $location ? $location->name : '';
                
            case 'specialty':
                $specialty_id = get_post_meta($post->ID, '_tc_specialty_id', true);
                $specialty = get_term($specialty_id, $this->taxonomy_1);
                return $specialty ? $specialty->name : '';
                
            case 'url':
                return get_permalink($post->ID);
                
            default:
                return '';
        }
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        global $post;
        
        if (!$post || $post->post_type !== $this->cpt_slug) {
            return '';
        }
        
        $atts = shortcode_atts(array(
            'number' => 6,
            'columns' => 3,
            'show_excerpt' => 'yes',
            'show_image' => 'yes'
        ), $atts);
        
        $location_id = get_post_meta($post->ID, '_tc_location_id', true);
        $specialty_id = get_post_meta($post->ID, '_tc_specialty_id', true);
        
        $query = new WP_Query(array(
            'post_type' => $this->post_type,
            'posts_per_page' => intval($atts['number']),
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'term_id',
                    'terms' => $specialty_id
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'term_id',
                    'terms' => $location_id
                )
            )
        ));
        
        ob_start();
        
        if ($query->have_posts()) :
            echo '<div class="tc-posts-grid" style="display: grid; grid-template-columns: repeat(' . intval($atts['columns']) . ', 1fr); gap: 20px;">';
            
            while ($query->have_posts()) : $query->the_post();
                ?>
                <div class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-image">
                            <?php the_post_thumbnail('medium'); ?>
                        </div>
                    <?php endif; ?>
                    
                    <h3 class="tc-post-title">
                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                    </h3>
                    
                    <?php if ($atts['show_excerpt'] === 'yes') : ?>
                        <div class="tc-post-excerpt">
                            <?php the_excerpt(); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
            endwhile;
            
            echo '</div>';
        endif;
        
        wp_reset_postdata();
        
        return ob_get_clean();
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();