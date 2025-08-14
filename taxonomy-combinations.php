<?php
/**
 * Plugin Name: Taxonomy Combination Pages with Blocksy
 * Description: Creates virtual pages for taxonomy combinations with SEO support and Blocksy Content Blocks integration
 * Version: 2.0
 * Author: maikunari
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class TaxonomyCombinationPages {
    
    private $post_type = 'healthcare_provider'; // CHANGE THIS to your CPT
    private $taxonomy_1 = 'specialties';
    private $taxonomy_2 = 'location';
    private $url_base = ''; // Leave empty for root-level URLs
    private $url_pattern = 'combined'; // 'combined' or 'hierarchical'
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'taxonomy_combinations';
        
        // Core functionality
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_virtual_page'));
        
        // Admin interface
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // Yoast SEO Integration
        add_filter('wpseo_title', array($this, 'modify_yoast_title'));
        add_filter('wpseo_metadesc', array($this, 'modify_yoast_description'));
        add_filter('wpseo_canonical', array($this, 'modify_yoast_canonical'));
        add_filter('wpseo_opengraph_url', array($this, 'modify_yoast_canonical'));
        add_filter('wpseo_robots', array($this, 'modify_yoast_robots'));
        
        // Yoast XML Sitemap Integration
        add_filter('wpseo_sitemap_index', array($this, 'add_sitemap_index'));
        add_action('init', array($this, 'register_sitemap_endpoint'));
        add_filter('wpseo_sitemap_tc_combinations_content', array($this, 'generate_sitemap_content'));
        
        // Database setup
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
        
        // Auto-generate combinations
        add_action('created_term', array($this, 'handle_new_term'), 10, 3);
        add_action('delete_term', array($this, 'handle_deleted_term'), 10, 3);
        
        // Shortcodes for dynamic content
        add_shortcode('tc_field', array($this, 'shortcode_tc_field'));
        add_shortcode('tc_posts', array($this, 'shortcode_tc_posts'));
        
        // AJAX handlers
        add_action('wp_ajax_tc_get_combinations', array($this, 'ajax_get_combinations'));
        add_action('wp_ajax_tc_bulk_update', array($this, 'ajax_bulk_update'));
        
        // Blocksy integration hooks
        add_filter('blocksy:content-blocks:display-conditions', array($this, 'add_blocksy_conditions'));
        add_filter('blocksy:content-blocks:condition-match', array($this, 'check_blocksy_condition'), 10, 3);
    }
    
    /**
     * Plugin Activation
     */
    public function activate_plugin() {
        $this->create_database_table();
        $this->generate_all_combinations();
        $this->add_rewrite_rules();
        flush_rewrite_rules();
    }
    
    /**
     * Plugin Deactivation
     */
    public function deactivate_plugin() {
        flush_rewrite_rules();
    }
    
    /**
     * Create Database Table
     */
    public function create_database_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            location_id mediumint(9) NOT NULL,
            specialty_id mediumint(9) NOT NULL,
            custom_slug varchar(255) DEFAULT '',
            custom_title varchar(255) DEFAULT '',
            custom_description text,
            meta_title varchar(255) DEFAULT '',
            meta_description text,
            custom_content longtext,
            header_content_block_id mediumint(9) DEFAULT NULL,
            content_block_id mediumint(9) DEFAULT NULL,
            footer_content_block_id mediumint(9) DEFAULT NULL,
            use_global_template tinyint(1) DEFAULT 1,
            robots_index tinyint(1) DEFAULT 1,
            robots_follow tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY combination (location_id, specialty_id),
            KEY location_id (location_id),
            KEY specialty_id (specialty_id),
            KEY content_block_id (content_block_id),
            KEY custom_slug (custom_slug)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add version option for future updates
        add_option('tc_plugin_version', '2.0');
    }
    
    /**
     * Generate all combinations on activation
     */
    public function generate_all_combinations() {
        $locations = get_terms(array(
            'taxonomy' => $this->taxonomy_2,
            'hide_empty' => false
        ));
        
        $specialties = get_terms(array(
            'taxonomy' => $this->taxonomy_1,
            'hide_empty' => false
        ));
        
        if (!is_wp_error($locations) && !is_wp_error($specialties)) {
            foreach ($locations as $location) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($location->term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Rewrite Rules
     */
    public function add_rewrite_rules() {
        if ($this->url_pattern === 'combined') {
            // Pattern: /english-dentist-in-setagaya/
            // This requires combinations to have unique slugs
            add_rewrite_rule(
                '([^/]+)-in-([^/]+)/?$',
                'index.php?tc_specialty=$matches[1]&tc_location=$matches[2]&tc_combo=1',
                'top'
            );
            
            // With pagination
            add_rewrite_rule(
                '([^/]+)-in-([^/]+)/page/([0-9]+)/?$',
                'index.php?tc_specialty=$matches[1]&tc_location=$matches[2]&tc_combo=1&paged=$matches[3]',
                'top'
            );
        } else {
            // Hierarchical pattern: /services/location/specialty/ (if url_base is set)
            $base = !empty($this->url_base) ? $this->url_base . '/' : '';
            
            add_rewrite_rule(
                $base . '([^/]+)/([^/]+)/?$',
                'index.php?tc_location=$matches[1]&tc_specialty=$matches[2]&tc_combo=1',
                'top'
            );
            
            add_rewrite_rule(
                $base . '([^/]+)/([^/]+)/page/([0-9]+)/?$',
                'index.php?tc_location=$matches[1]&tc_specialty=$matches[2]&tc_combo=1&paged=$matches[3]',
                'top'
            );
        }
    }
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate URL slug based on pattern
        $custom_slug = $this->generate_combination_slug($specialty, $location);
        
        // Generate defaults with "English" prefix for better SEO
        $default_title = sprintf('English %s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('English %s in %s | Best English-Speaking %s Services', 
            $specialty->name, 
            $location->name,
            $specialty->name
        );
        $default_meta_desc = sprintf(
            'Find the best English-speaking %s in %s. Native English %s services with experienced professionals. Book your appointment today.',
            strtolower($specialty->name),
            $location->name,
            strtolower($specialty->name)
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_slug' => $custom_slug,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Generate Combination Slug
     */
    private function generate_combination_slug($specialty, $location) {
        // Generate slug like: english-dentist-in-setagaya
        $slug = sprintf('english-%s-in-%s', $specialty->slug, $location->slug);
        
        // Ensure uniqueness
        global $wpdb;
        $base_slug = $slug;
        $counter = 1;
        
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE custom_slug = %s",
            $slug
        )) > 0) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        // Get the custom slug for this combination
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']->custom_slug)) {
            $canonical = home_url($wp_query->tc_data['custom_data']->custom_slug . '/');
        } else {
            // Fallback to constructed URL
            if ($this->url_pattern === 'combined') {
                $canonical = home_url('english-' . $specialty_slug . '-in-' . $location_slug . '/');
            } else {
                $base = !empty($this->url_base) ? $this->url_base . '/' : '';
                $canonical = home_url($base . $location_slug . '/' . $specialty_slug . '/');
            }
        }
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <?php 
                                        $url = $this->get_combination_url(
                                            $combo->location_slug, 
                                            $combo->specialty_slug,
                                            $combo->custom_slug
                                        );
                                        ?>
                                        <a href="<?php echo esc_url($url); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <?php 
            $url = $this->get_combination_url(
                $combo->location_slug, 
                $combo->specialty_slug,
                $combo->custom_slug
            );
            ?>
            <a href="<?php echo esc_url($url); ?>" target="_blank">
                <?php echo esc_url($url); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_slug">URL Slug</label></th>
                            <td>
                                <input type="text" id="custom_slug" name="custom_slug" 
                                       value="<?php echo esc_attr($combo->custom_slug); ?>" class="regular-text" />
                                <p class="description">
                                    Custom URL slug for this combination. Leave as-is for default: 
                                    <code>english-<?php echo $combo->specialty_slug; ?>-in-<?php echo $combo->location_slug; ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        // Sanitize and validate custom slug
        $custom_slug = sanitize_title($data['custom_slug']);
        if (!empty($custom_slug)) {
            // Check for uniqueness (excluding current combination)
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE custom_slug = %s AND id != %d",
                $custom_slug,
                intval($data['combo_id'])
            ));
            
            if ($existing > 0) {
                echo '<div class="notice notice-error"><p>That URL slug is already in use. Please choose another.</p></div>';
                return;
            }
        }
        
        $update_data = array(
            'custom_slug' => $custom_slug,
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully! <a href="' . admin_url('admin.php?page=taxonomy-combinations') . '">← Back to list</a></p></div>';
        
        // Flush rewrite rules to ensure new slug works
        flush_rewrite_rules();
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
    
    /**
     * Sitemap: Add to Yoast Sitemap Index
     */
    public function add_sitemap_index($sitemap_index) {
        global $wpdb;
        
        // Get count of indexed combinations
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE robots_index = 1"
        );
        
        if ($count > 0) {
            $sitemap_entry = '<sitemap>' . "\n";
            $sitemap_entry .= '<loc>' . home_url('tc-combinations-sitemap.xml') . '</loc>' . "\n";
            $sitemap_entry .= '<lastmod>' . date('c') . '</lastmod>' . "\n";
            $sitemap_entry .= '</sitemap>' . "\n";
            
            $sitemap_index = str_replace('</sitemapindex>', $sitemap_entry . '</sitemapindex>', $sitemap_index);
        }
        
        return $sitemap_index;
    }
    
    /**
     * Sitemap: Register Custom Endpoint
     */
    public function register_sitemap_endpoint() {
        add_rewrite_rule('tc-combinations-sitemap\.xml

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_specialty=$matches[1]&tc_location=$matches[2]&tc_combo=1',
                'top'
            );
            
            // With pagination
            add_rewrite_rule(
                '([^/]+)-in-([^/]+)/page/([0-9]+)/?
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_specialty=$matches[1]&tc_location=$matches[2]&tc_combo=1&paged=$matches[3]',
                'top'
            );
        } else {
            // Hierarchical pattern: /services/location/specialty/ (if url_base is set)
            $base = !empty($this->url_base) ? $this->url_base . '/' : '';
            
            add_rewrite_rule(
                $base . '([^/]+)/([^/]+)/?
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_location=$matches[1]&tc_specialty=$matches[2]&tc_combo=1',
                'top'
            );
            
            add_rewrite_rule(
                $base . '([^/]+)/([^/]+)/page/([0-9]+)/?
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_location=$matches[1]&tc_specialty=$matches[2]&tc_combo=1&paged=$matches[3]',
                'top'
            );
        }
    }
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
}, 'index.php?tc_sitemap=1', 'top');
        add_filter('query_vars', function($vars) {
            $vars[] = 'tc_sitemap';
            return $vars;
        });
        
        // Handle the sitemap request
        add_action('template_redirect', array($this, 'handle_sitemap_request'), 1);
    }
    
    /**
     * Sitemap: Handle Request
     */
    public function handle_sitemap_request() {
        if (!get_query_var('tc_sitemap')) {
            return;
        }
        
        header('Content-Type: text/xml; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
        
        echo $this->generate_sitemap_content();
        exit;
    }
    
    /**
     * Sitemap: Generate Content
     */
    public function generate_sitemap_content() {
        global $wpdb;
        
        // Get all indexed combinations
        $combinations = $wpdb->get_results(
            "SELECT c.*, l.slug as location_slug, s.slug as specialty_slug
             FROM {$this->table_name} c
             LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
             LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
             WHERE c.robots_index = 1
             ORDER BY c.updated_at DESC"
        );
        
        $output = '<?xml version="1.0" encoding="UTF-8"?>';
        $output .= '<?xml-stylesheet type="text/xsl" href="' . esc_url(home_url('main-sitemap.xsl')) . '"?>';
        $output .= '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
        $output .= 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" ';
        $output .= 'xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 ';
        $output .= 'http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd ';
        $output .= 'http://www.google.com/schemas/sitemap-image/1.1 ';
        $output .= 'http://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd" ';
        $output .= 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($combinations as $combo) {
            // Generate URL based on custom slug or pattern
            if (!empty($combo->custom_slug)) {
                $url = home_url($combo->custom_slug . '/');
            } else if ($this->url_pattern === 'combined') {
                $url = home_url('english-' . $combo->specialty_slug . '-in-' . $combo->location_slug . '/');
            } else {
                $base = !empty($this->url_base) ? $this->url_base . '/' : '';
                $url = home_url($base . $combo->location_slug . '/' . $combo->specialty_slug . '/');
            }
            
            $output .= "\t<url>\n";
            $output .= "\t\t<loc>" . esc_url($url) . "</loc>\n";
            $output .= "\t\t<lastmod>" . date('c', strtotime($combo->updated_at)) . "</lastmod>\n";
            
            // Add priority based on content
            $priority = '0.7'; // Default priority
            if (!empty($combo->custom_content) || !empty($combo->content_block_id)) {
                $priority = '0.8'; // Higher priority for customized pages
            }
            $output .= "\t\t<priority>" . $priority . "</priority>\n";
            
            $output .= "\t</url>\n";
        }
        
        $output .= '</urlset>';
        
        return $output;
    }
    
    /**
     * Get Combination URL Helper
     */
    public function get_combination_url($location_slug, $specialty_slug, $custom_slug = '') {
        if (!empty($custom_slug)) {
            return home_url($custom_slug . '/');
        }
        
        if ($this->url_pattern === 'combined') {
            return home_url('english-' . $specialty_slug . '-in-' . $location_slug . '/');
        }
        
        $base = !empty($this->url_base) ? $this->url_base . '/' : '';
        return home_url($base . $location_slug . '/' . $specialty_slug . '/');
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_specialty=$matches[1]&tc_location=$matches[2]&tc_combo=1',
                'top'
            );
            
            // With pagination
            add_rewrite_rule(
                '([^/]+)-in-([^/]+)/page/([0-9]+)/?
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_specialty=$matches[1]&tc_location=$matches[2]&tc_combo=1&paged=$matches[3]',
                'top'
            );
        } else {
            // Hierarchical pattern: /services/location/specialty/ (if url_base is set)
            $base = !empty($this->url_base) ? $this->url_base . '/' : '';
            
            add_rewrite_rule(
                $base . '([^/]+)/([^/]+)/?
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_location=$matches[1]&tc_specialty=$matches[2]&tc_combo=1',
                'top'
            );
            
            add_rewrite_rule(
                $base . '([^/]+)/([^/]+)/page/([0-9]+)/?
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
},
                'index.php?tc_location=$matches[1]&tc_specialty=$matches[2]&tc_combo=1&paged=$matches[3]',
                'top'
            );
        }
    }
    
    /**
     * Query Variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'tc_location';
        $vars[] = 'tc_specialty';
        $vars[] = 'tc_combo';
        return $vars;
    }
    
    /**
     * Handle Virtual Page Display
     */
    public function handle_virtual_page() {
        if (!get_query_var('tc_combo')) {
            return;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        
        // Verify taxonomies exist
        $location = get_term_by('slug', $location_slug, $this->taxonomy_2);
        $specialty = get_term_by('slug', $specialty_slug, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
        
        // Set up the query
        global $wp_query;
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $paged,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => $this->taxonomy_1,
                    'field' => 'slug',
                    'terms' => $specialty_slug,
                ),
                array(
                    'taxonomy' => $this->taxonomy_2,
                    'field' => 'slug',
                    'terms' => $location_slug,
                )
            )
        );
        
        $wp_query = new WP_Query($args);
        
        // Store combination data
        $wp_query->tc_data = array(
            'location' => $location,
            'specialty' => $specialty,
            'combination_id' => $this->get_combination_id($location->term_id, $specialty->term_id),
            'custom_data' => $this->get_combination_data($location->term_id, $specialty->term_id),
            'plugin_instance' => $this
        );
        
        // Set is_archive to true for proper theme compatibility
        $wp_query->is_archive = true;
        $wp_query->is_tax = true;
        
        // Load template
        $this->load_template();
    }
    
    /**
     * Load Template File
     */
    public function load_template() {
        // Check for theme template first
        $templates = array(
            'taxonomy-combination.php',
            'archive-' . $this->post_type . '.php',
            'archive.php',
            'index.php'
        );
        
        // Allow themes to filter template hierarchy
        $templates = apply_filters('tc_template_hierarchy', $templates);
        
        $template = locate_template($templates);
        
        if (!$template) {
            // Use plugin's default template
            $template = plugin_dir_path(__FILE__) . 'templates/taxonomy-combination.php';
            
            // Create default template if it doesn't exist
            if (!file_exists($template)) {
                $this->create_default_template();
            }
        }
        
        if ($template && file_exists($template)) {
            include($template);
            exit;
        }
    }
    
    /**
     * Create Default Template
     */
    private function create_default_template() {
        $template_dir = plugin_dir_path(__FILE__) . 'templates';
        
        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }
        
        $template_content = '<?php
/**
 * Default Template for Taxonomy Combination Pages
 */

get_header();

global $wp_query;
$tc_data = $wp_query->tc_data;
$location = $tc_data["location"];
$specialty = $tc_data["specialty"];
$custom_data = $tc_data["custom_data"];
$plugin = $tc_data["plugin_instance"];

// Render header content block if assigned
$plugin->render_content_block("header");
?>

<div class="ct-container">
    <div class="combination-archive">
        <?php if (empty($custom_data->content_block_id)) : ?>
            <!-- Default layout when no content block is assigned -->
            <header class="page-header">
                <h1><?php echo esc_html($custom_data->custom_title); ?></h1>
                
                <?php if (!empty($custom_data->custom_description)) : ?>
                    <div class="archive-description">
                        <?php echo wpautop(esc_html($custom_data->custom_description)); ?>
                    </div>
                <?php endif; ?>
            </header>
            
            <?php if (!empty($custom_data->custom_content)) : ?>
                <div class="custom-content">
                    <?php echo wp_kses_post($custom_data->custom_content); ?>
                </div>
            <?php endif; ?>
            
            <?php if (have_posts()) : ?>
                <div class="entries">
                    <?php while (have_posts()) : the_post(); ?>
                        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="entry-summary">
                                <?php the_excerpt(); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <p>No <?php echo esc_html($specialty->name); ?> found in <?php echo esc_html($location->name); ?>.</p>
            <?php endif; ?>
            
        <?php else : ?>
            <!-- Render main content block -->
            <?php $plugin->render_content_block("main"); ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Render footer content block if assigned
$plugin->render_content_block("footer");

get_footer();
';
        
        file_put_contents($template_dir . '/taxonomy-combination.php', $template_content);
    }
    
    /**
     * Render Blocksy Content Block
     */
    public function render_content_block($position = 'main') {
        global $wp_query;
        
        if (!isset($wp_query->tc_data)) {
            return;
        }
        
        $custom_data = $wp_query->tc_data['custom_data'];
        
        // Determine which block ID to use
        $block_id = null;
        switch ($position) {
            case 'header':
                $block_id = $custom_data->header_content_block_id;
                break;
            case 'main':
                $block_id = $custom_data->content_block_id;
                break;
            case 'footer':
                $block_id = $custom_data->footer_content_block_id;
                break;
        }
        
        if (empty($block_id)) {
            return;
        }
        
        // Check if Blocksy function exists
        if (function_exists('blc_render_content_block')) {
            echo blc_render_content_block($block_id);
        } elseif (class_exists('Blocksy\ContentBlocksRenderer')) {
            // Alternative method for newer Blocksy versions
            $renderer = new \Blocksy\ContentBlocksRenderer();
            echo $renderer->render_content_block($block_id);
        } else {
            // Fallback: render the content block manually
            $block = get_post($block_id);
            if ($block && $block->post_type === 'ct_content_block') {
                // Apply content filters to handle shortcodes and blocks
                echo apply_filters('the_content', $block->post_content);
            }
        }
    }
    
    /**
     * Get Combination Data
     */
    public function get_combination_data($location_id, $specialty_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE location_id = %d AND specialty_id = %d",
            $location_id,
            $specialty_id
        ));
        
        if (!$data) {
            $this->create_combination_entry($location_id, $specialty_id);
            return $this->get_combination_data($location_id, $specialty_id);
        }
        
        return $data;
    }
    
    /**
     * Create Combination Entry
     */
    public function create_combination_entry($location_id, $specialty_id) {
        global $wpdb;
        
        $location = get_term($location_id, $this->taxonomy_2);
        $specialty = get_term($specialty_id, $this->taxonomy_1);
        
        if (!$location || !$specialty || is_wp_error($location) || is_wp_error($specialty)) {
            return false;
        }
        
        // Generate defaults
        $default_title = sprintf('%s in %s', $specialty->name, $location->name);
        $default_meta_title = sprintf('%s Services in %s | %s', 
            $specialty->name, 
            $location->name,
            get_bloginfo('name')
        );
        $default_meta_desc = sprintf(
            'Find expert %s services in %s. Browse our qualified professionals and book an appointment today.',
            strtolower($specialty->name),
            $location->name
        );
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'location_id' => $location_id,
                'specialty_id' => $specialty_id,
                'custom_title' => $default_title,
                'meta_title' => $default_meta_title,
                'meta_description' => $default_meta_desc,
                'custom_description' => '',
                'custom_content' => '',
                'robots_index' => 1,
                'robots_follow' => 1
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Handle New Term Creation
     */
    public function handle_new_term($term_id, $tt_id, $taxonomy) {
        if ($taxonomy === $this->taxonomy_1) {
            // New specialty
            $locations = get_terms(array(
                'taxonomy' => $this->taxonomy_2,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($locations)) {
                foreach ($locations as $location) {
                    $this->create_combination_entry($location->term_id, $term_id);
                }
            }
        } elseif ($taxonomy === $this->taxonomy_2) {
            // New location
            $specialties = get_terms(array(
                'taxonomy' => $this->taxonomy_1,
                'hide_empty' => false
            ));
            
            if (!is_wp_error($specialties)) {
                foreach ($specialties as $specialty) {
                    $this->create_combination_entry($term_id, $specialty->term_id);
                }
            }
        }
    }
    
    /**
     * Handle Term Deletion
     */
    public function handle_deleted_term($term_id, $tt_id, $taxonomy) {
        global $wpdb;
        
        if ($taxonomy === $this->taxonomy_1) {
            $wpdb->delete($this->table_name, array('specialty_id' => $term_id), array('%d'));
        } elseif ($taxonomy === $this->taxonomy_2) {
            $wpdb->delete($this->table_name, array('location_id' => $term_id), array('%d'));
        }
    }
    
    /**
     * Get Combination ID
     */
    public function get_combination_id($location_id, $specialty_id) {
        return $location_id . '_' . $specialty_id;
    }
    
    /**
     * Shortcode: TC Field
     */
    public function shortcode_tc_field($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        if (!isset($wp_query->tc_data)) {
            return '';
        }
        
        $tc_data = $wp_query->tc_data;
        
        $atts = shortcode_atts(array(
            'field' => 'title',
            'format' => 'text'
        ), $atts);
        
        $output = '';
        
        switch ($atts['field']) {
            case 'title':
                $output = $tc_data['custom_data']->custom_title;
                break;
            case 'description':
                $output = $tc_data['custom_data']->custom_description;
                break;
            case 'content':
                $output = $tc_data['custom_data']->custom_content;
                break;
            case 'location':
            case 'location_name':
                $output = $tc_data['location']->name;
                break;
            case 'location_slug':
                $output = $tc_data['location']->slug;
                break;
            case 'location_description':
                $output = $tc_data['location']->description;
                break;
            case 'specialty':
            case 'specialty_name':
                $output = $tc_data['specialty']->name;
                break;
            case 'specialty_slug':
                $output = $tc_data['specialty']->slug;
                break;
            case 'specialty_description':
                $output = $tc_data['specialty']->description;
                break;
            case 'url':
                $output = home_url($this->url_base . '/' . $tc_data['location']->slug . '/' . $tc_data['specialty']->slug . '/');
                break;
            case 'post_count':
                $output = $wp_query->found_posts;
                break;
        }
        
        // Format output
        if ($atts['format'] === 'html' && !empty($output)) {
            $output = wpautop($output);
        }
        
        return apply_filters('tc_field_output', $output, $atts['field'], $tc_data);
    }
    
    /**
     * Shortcode: TC Posts
     */
    public function shortcode_tc_posts($atts) {
        if (!get_query_var('tc_combo')) {
            return '';
        }
        
        global $wp_query;
        
        $atts = shortcode_atts(array(
            'number' => get_option('posts_per_page', 10),
            'columns' => 1,
            'show_excerpt' => 'yes',
            'show_image' => 'yes',
            'image_size' => 'thumbnail',
            'show_date' => 'no',
            'show_author' => 'no'
        ), $atts);
        
        ob_start();
        
        if (have_posts()) {
            $column_class = 'tc-posts-grid columns-' . intval($atts['columns']);
            echo '<div class="' . esc_attr($column_class) . '">';
            
            while (have_posts()) {
                the_post();
                ?>
                <article class="tc-post-item">
                    <?php if ($atts['show_image'] === 'yes' && has_post_thumbnail()) : ?>
                        <div class="tc-post-thumbnail">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail($atts['image_size']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="tc-post-content">
                        <h3 class="tc-post-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>
                        
                        <?php if ($atts['show_date'] === 'yes' || $atts['show_author'] === 'yes') : ?>
                            <div class="tc-post-meta">
                                <?php if ($atts['show_date'] === 'yes') : ?>
                                    <span class="tc-post-date"><?php echo get_the_date(); ?></span>
                                <?php endif; ?>
                                <?php if ($atts['show_author'] === 'yes') : ?>
                                    <span class="tc-post-author">by <?php the_author(); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_excerpt'] === 'yes') : ?>
                            <div class="tc-post-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add basic CSS
            ?>
            <style>
                .tc-posts-grid { display: grid; gap: 2rem; }
                .tc-posts-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
                .tc-posts-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
                .tc-posts-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
                .tc-post-item { margin-bottom: 2rem; }
                .tc-post-thumbnail img { width: 100%; height: auto; }
                .tc-post-meta { color: #666; font-size: 0.9em; margin: 0.5rem 0; }
                @media (max-width: 768px) {
                    .tc-posts-grid { grid-template-columns: 1fr !important; }
                }
            </style>
            <?php
        } else {
            echo '<p>No posts found for this combination.</p>';
        }
        
        // Reset post data
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Yoast SEO: Title
     */
    public function modify_yoast_title($title) {
        if (!get_query_var('tc_combo')) {
            return $title;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_title)) {
            return $wp_query->tc_data['custom_data']->meta_title;
        }
        
        return $title;
    }
    
    /**
     * Yoast SEO: Description
     */
    public function modify_yoast_description($description) {
        if (!get_query_var('tc_combo')) {
            return $description;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data']) && !empty($wp_query->tc_data['custom_data']->meta_description)) {
            return $wp_query->tc_data['custom_data']->meta_description;
        }
        
        return $description;
    }
    
    /**
     * Yoast SEO: Canonical URL
     */
    public function modify_yoast_canonical($url) {
        if (!get_query_var('tc_combo')) {
            return $url;
        }
        
        $location_slug = get_query_var('tc_location');
        $specialty_slug = get_query_var('tc_specialty');
        $paged = get_query_var('paged');
        
        $canonical = home_url($this->url_base . '/' . $location_slug . '/' . $specialty_slug . '/');
        
        if ($paged > 1) {
            $canonical .= 'page/' . $paged . '/';
        }
        
        return $canonical;
    }
    
    /**
     * Yoast SEO: Robots
     */
    public function modify_yoast_robots($robots) {
        if (!get_query_var('tc_combo')) {
            return $robots;
        }
        
        global $wp_query;
        if (isset($wp_query->tc_data['custom_data'])) {
            $data = $wp_query->tc_data['custom_data'];
            
            if (!$data->robots_index) {
                $robots['index'] = 'noindex';
            }
            if (!$data->robots_follow) {
                $robots['follow'] = 'nofollow';
            }
        }
        
        return $robots;
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
            'All Combinations',
            'All Combinations',
            'manage_options',
            'taxonomy-combinations',
            array($this, 'admin_page')
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
    }
    
    /**
     * Admin Scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'taxonomy-combinations') === false && strpos($hook, 'tc-') === false) {
            return;
        }
        
        wp_enqueue_script('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '2.0', true);
        wp_enqueue_style('tc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '2.0');
        
        wp_localize_script('tc-admin', 'tc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_ajax_nonce')
        ));
    }
    
    /**
     * Admin Page
     */
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_update')) {
            $this->update_combination_data($_POST);
        }
        
        global $wpdb;
        
        // Get filter parameters
        $filter_location = isset($_GET['filter_location']) ? intval($_GET['filter_location']) : 0;
        $filter_specialty = isset($_GET['filter_specialty']) ? intval($_GET['filter_specialty']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $where_clauses = array('1=1');
        $where_values = array();
        
        if ($filter_location) {
            $where_clauses[] = 'c.location_id = %d';
            $where_values[] = $filter_location;
        }
        
        if ($filter_specialty) {
            $where_clauses[] = 'c.specialty_id = %d';
            $where_values[] = $filter_specialty;
        }
        
        if ($search) {
            $where_clauses[] = '(c.custom_title LIKE %s OR c.meta_title LIKE %s OR l.name LIKE %s OR s.name LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} c
                       LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                       LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                       WHERE $where_sql";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        
        // Get combinations
        $query = "SELECT c.*, l.name as location_name, l.slug as location_slug, 
                        s.name as specialty_name, s.slug as specialty_slug
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 WHERE $where_sql
                 ORDER BY l.name, s.name
                 LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        
        if (!empty($query_values)) {
            $query = $wpdb->prepare($query, $query_values);
        }
        
        $combinations = $wpdb->get_results($query);
        
        // Get all locations and specialties for filters
        $all_locations = get_terms(array('taxonomy' => $this->taxonomy_2, 'hide_empty' => false));
        $all_specialties = get_terms(array('taxonomy' => $this->taxonomy_1, 'hide_empty' => false));
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Pages</h1>
            
            <?php if (isset($_GET['edit'])) : 
                $combo_id = intval($_GET['edit']);
                $combo = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, l.name as location_name, l.slug as location_slug,
                            s.name as specialty_name, s.slug as specialty_slug
                    FROM {$this->table_name} c
                    LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                    LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                    WHERE c.id = %d",
                    $combo_id
                ));
                
                if ($combo) :
                    $this->render_edit_form($combo);
                endif;
            else : ?>
                
                <!-- Filters -->
                <div class="tablenav top">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="taxonomy-combinations">
                        
                        <div class="alignleft actions">
                            <select name="filter_location">
                                <option value="">All Locations</option>
                                <?php foreach ($all_locations as $location) : ?>
                                    <option value="<?php echo $location->term_id; ?>" <?php selected($filter_location, $location->term_id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="filter_specialty">
                                <option value="">All Specialties</option>
                                <?php foreach ($all_specialties as $specialty) : ?>
                                    <option value="<?php echo $specialty->term_id; ?>" <?php selected($filter_specialty, $specialty->term_id); ?>>
                                        <?php echo esc_html($specialty->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">
                            <input type="submit" class="button" value="Filter">
                            
                            <?php if ($filter_location || $filter_specialty || $search) : ?>
                                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Clear</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alignright">
                            <a href="<?php echo admin_url('admin.php?page=tc-bulk-edit'); ?>" class="button">Bulk Edit</a>
                        </div>
                    </form>
                </div>
                
                <!-- Table -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>Specialty</th>
                            <th>Location</th>
                            <th>Title</th>
                            <th>Content Block</th>
                            <th>SEO</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($combinations) : ?>
                            <?php foreach ($combinations as $combo) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="combo_ids[]" value="<?php echo $combo->id; ?>">
                                </td>
                                <td><?php echo esc_html($combo->specialty_name); ?></td>
                                <td><?php echo esc_html($combo->location_name); ?></td>
                                <td>
                                    <?php echo esc_html($combo->custom_title); ?>
                                    <br>
                                    <small>
                                        <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                                            View →
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($combo->content_block_id) : 
                                        $block = get_post($combo->content_block_id);
                                        if ($block) :
                                    ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php echo esc_html($block->post_title); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                                        Block #<?php echo $combo->content_block_id; ?> (deleted)
                                    <?php endif; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default layout
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($combo->meta_title) || !empty($combo->meta_description)) : ?>
                                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                                        Customized
                                    <?php else : ?>
                                        <span class="dashicons dashicons-minus"></span>
                                        Default
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations&edit=' . $combo->id); ?>" class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">No combinations found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Edit Form
     */
    private function render_edit_form($combo) {
        // Get available content blocks
        $content_blocks = $this->get_content_blocks();
        
        ?>
        <h2>
            Edit: <?php echo esc_html($combo->specialty_name . ' in ' . $combo->location_name); ?>
        </h2>
        
        <p>
            <strong>URL:</strong> 
            <a href="<?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>" target="_blank">
                <?php echo home_url($this->url_base . '/' . $combo->location_slug . '/' . $combo->specialty_slug . '/'); ?>
            </a>
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('tc_update', 'tc_nonce'); ?>
            <input type="hidden" name="combo_id" value="<?php echo $combo->id; ?>">
            
            <div class="tc-edit-form">
                <!-- Nav tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active">General</a>
                    <a href="#seo" class="nav-tab">SEO</a>
                    <a href="#blocksy" class="nav-tab">Blocksy Blocks</a>
                    <a href="#content" class="nav-tab">Custom Content</a>
                </h2>
                
                <!-- General Tab -->
                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_title">Page Title</label></th>
                            <td>
                                <input type="text" id="custom_title" name="custom_title" 
                                       value="<?php echo esc_attr($combo->custom_title); ?>" class="regular-text" />
                                <p class="description">The H1 title displayed on the page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="custom_description">Short Description</label></th>
                            <td>
                                <textarea id="custom_description" name="custom_description" rows="4" class="large-text">
                                    <?php echo esc_textarea($combo->custom_description); ?>
                                </textarea>
                                <p class="description">Brief description shown below the title.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- SEO Tab -->
                <div id="seo" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="meta_title">SEO Title</label></th>
                            <td>
                                <input type="text" id="meta_title" name="meta_title" 
                                       value="<?php echo esc_attr($combo->meta_title); ?>" class="large-text" />
                                <p class="description">
                                    Title tag for search engines. 
                                    <span id="title-length"><?php echo strlen($combo->meta_title); ?></span>/60 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="meta_description">Meta Description</label></th>
                            <td>
                                <textarea id="meta_description" name="meta_description" rows="3" class="large-text">
                                    <?php echo esc_textarea($combo->meta_description); ?>
                                </textarea>
                                <p class="description">
                                    Description for search results. 
                                    <span id="desc-length"><?php echo strlen($combo->meta_description); ?></span>/160 characters
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Robots Settings</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="robots_index" value="1" 
                                           <?php checked($combo->robots_index, 1); ?>>
                                    Allow search engines to index this page
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="robots_follow" value="1" 
                                           <?php checked($combo->robots_follow, 1); ?>>
                                    Allow search engines to follow links
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Blocksy Tab -->
                <div id="blocksy" class="tab-content" style="display:none;">
                    <?php if (!empty($content_blocks)) : ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_content_block_id">Header Content Block</label></th>
                                <td>
                                    <select name="header_content_block_id" id="header_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->header_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display before main content.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="content_block_id">Main Content Block</label></th>
                                <td>
                                    <select name="content_block_id" id="content_block_id">
                                        <option value="">— Default Layout —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Main content template. Use shortcodes: 
                                        <code>[tc_field field="title"]</code>, 
                                        <code>[tc_field field="location"]</code>, 
                                        <code>[tc_field field="specialty"]</code>,
                                        <code>[tc_posts]</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="footer_content_block_id">Footer Content Block</label></th>
                                <td>
                                    <select name="footer_content_block_id" id="footer_content_block_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($content_blocks as $block) : ?>
                                            <option value="<?php echo $block->ID; ?>" 
                                                    <?php selected($combo->footer_content_block_id, $block->ID); ?>>
                                                <?php echo esc_html($block->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Content block to display after main content.</p>
                                </td>
                            </tr>
                        </table>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>No Blocksy Content Blocks found. Please create Content Blocks first.</p>
                            <p><a href="<?php echo admin_url('edit.php?post_type=ct_content_block'); ?>" class="button">
                                Create Content Blocks
                            </a></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Custom Content Tab -->
                <div id="content" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="custom_content">Custom Content</label></th>
                            <td>
                                <?php 
                                wp_editor(
                                    $combo->custom_content, 
                                    'custom_content', 
                                    array(
                                        'textarea_rows' => 15,
                                        'media_buttons' => true
                                    )
                                ); 
                                ?>
                                <p class="description">
                                    Additional content to display on the page. 
                                    This is shown when no Content Block is selected.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Update Combination">
                <a href="<?php echo admin_url('admin.php?page=taxonomy-combinations'); ?>" class="button">Cancel</a>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
            
            // Character counters
            $('#meta_title').on('input', function() {
                $('#title-length').text($(this).val().length);
            });
            $('#meta_description').on('input', function() {
                $('#desc-length').text($(this).val().length);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get Content Blocks
     */
    private function get_content_blocks() {
        return get_posts(array(
            'post_type' => 'ct_content_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
    }
    
    /**
     * Update Combination Data
     */
    public function update_combination_data($data) {
        global $wpdb;
        
        $update_data = array(
            'custom_title' => sanitize_text_field($data['custom_title']),
            'custom_description' => sanitize_textarea_field($data['custom_description']),
            'meta_title' => sanitize_text_field($data['meta_title']),
            'meta_description' => sanitize_textarea_field($data['meta_description']),
            'custom_content' => wp_kses_post($data['custom_content']),
            'header_content_block_id' => !empty($data['header_content_block_id']) ? intval($data['header_content_block_id']) : null,
            'content_block_id' => !empty($data['content_block_id']) ? intval($data['content_block_id']) : null,
            'footer_content_block_id' => !empty($data['footer_content_block_id']) ? intval($data['footer_content_block_id']) : null,
            'robots_index' => isset($data['robots_index']) ? 1 : 0,
            'robots_follow' => isset($data['robots_follow']) ? 1 : 0
        );
        
        $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => intval($data['combo_id'])),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        echo '<div class="notice notice-success"><p>Combination updated successfully!</p></div>';
    }
    
    /**
     * Bulk Edit Page
     */
    public function bulk_edit_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Edit Combinations</h1>
            
            <form method="post" id="bulk-edit-form">
                <?php wp_nonce_field('tc_bulk_edit', 'tc_nonce'); ?>
                
                <div class="tc-bulk-filters">
                    <h3>Select Combinations</h3>
                    <table class="form-table">
                        <tr>
                            <th>Filter by Location</th>
                            <td>
                                <select name="filter_locations[]" multiple size="5">
                                    <?php
                                    $locations = get_terms(array(
                                        'taxonomy' => $this->taxonomy_2,
                                        'hide_empty' => false
                                    ));
                                    foreach ($locations as $location) :
                                    ?>
                                        <option value="<?php echo $location->term_id; ?>">
                                            <?php echo esc_html($location->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Filter by Specialty</th>
                            <td>
                                <select name="filter_specialties[]" multiple size="5">
                                    <?php
                                    $specialties = get_terms(array(
                                        'taxonomy' => $this->taxonomy_1,
                                        'hide_empty' => false
                                    ));
                                    foreach ($specialties as $specialty) :
                                    ?>
                                        <option value="<?php echo $specialty->term_id; ?>">
                                            <?php echo esc_html($specialty->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Hold Ctrl/Cmd to select multiple</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="tc-bulk-actions">
                    <h3>Bulk Actions</h3>
                    <table class="form-table">
                        <tr>
                            <th>Content Block</th>
                            <td>
                                <select name="bulk_content_block_id">
                                    <option value="">— No Change —</option>
                                    <option value="0">— Remove Block —</option>
                                    <?php
                                    $blocks = $this->get_content_blocks();
                                    foreach ($blocks as $block) :
                                    ?>
                                        <option value="<?php echo $block->ID; ?>">
                                            <?php echo esc_html($block->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>SEO Settings</th>
                            <td>
                                <select name="bulk_robots_index">
                                    <option value="">— No Change —</option>
                                    <option value="1">Index</option>
                                    <option value="0">NoIndex</option>
                                </select>
                                <select name="bulk_robots_follow">
                                    <option value="">— No Change —</option>
                                    <option value="1">Follow</option>
                                    <option value="0">NoFollow</option>
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
        
        // Handle bulk update
        if (isset($_POST['bulk_update']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_bulk_edit')) {
            $this->process_bulk_update($_POST);
        }
    }
    
    /**
     * Process Bulk Update
     */
    private function process_bulk_update($data) {
        global $wpdb;
        
        // Build WHERE clause
        $where_parts = array();
        $where_values = array();
        
        if (!empty($data['filter_locations'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_locations']), '%d'));
            $where_parts[] = "location_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_locations']));
        }
        
        if (!empty($data['filter_specialties'])) {
            $placeholders = implode(',', array_fill(0, count($data['filter_specialties']), '%d'));
            $where_parts[] = "specialty_id IN ($placeholders)";
            $where_values = array_merge($where_values, array_map('intval', $data['filter_specialties']));
        }
        
        if (empty($where_parts)) {
            echo '<div class="notice notice-error"><p>Please select at least one filter.</p></div>';
            return;
        }
        
        // Build UPDATE query
        $update_parts = array();
        $update_values = array();
        
        if (isset($data['bulk_content_block_id']) && $data['bulk_content_block_id'] !== '') {
            $update_parts[] = 'content_block_id = %d';
            $update_values[] = intval($data['bulk_content_block_id']);
        }
        
        if (isset($data['bulk_robots_index']) && $data['bulk_robots_index'] !== '') {
            $update_parts[] = 'robots_index = %d';
            $update_values[] = intval($data['bulk_robots_index']);
        }
        
        if (isset($data['bulk_robots_follow']) && $data['bulk_robots_follow'] !== '') {
            $update_parts[] = 'robots_follow = %d';
            $update_values[] = intval($data['bulk_robots_follow']);
        }
        
        if (empty($update_parts)) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
            return;
        }
        
        // Execute update
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $update_parts) . 
               " WHERE " . implode(' AND ', $where_parts);
        
        $all_values = array_merge($update_values, $where_values);
        $result = $wpdb->query($wpdb->prepare($sql, $all_values));
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf('%d combinations updated successfully!', $result) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating combinations.</p></div>';
        }
    }
    
    /**
     * Settings Page
     */
    public function settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['tc_nonce'], 'tc_settings')) {
            update_option('tc_url_base', sanitize_title($_POST['url_base']));
            echo '<div class="notice notice-success"><p>Settings saved! Please visit Settings > Permalinks to refresh rewrite rules.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Taxonomy Combination Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tc_settings', 'tc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="url_base">URL Base</label></th>
                        <td>
                            <input type="text" id="url_base" name="url_base" 
                                   value="<?php echo esc_attr(get_option('tc_url_base', $this->url_base)); ?>" />
                            <p class="description">
                                Base URL for combination pages. 
                                Example: <?php echo home_url('/[base]/location/specialty/'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2>Available Shortcodes</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tc_field field="title"]</code></td>
                            <td>Display combination title</td>
                            <td>Cardiology in New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="location"]</code></td>
                            <td>Display location name</td>
                            <td>New York</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="specialty"]</code></td>
                            <td>Display specialty name</td>
                            <td>Cardiology</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="description"]</code></td>
                            <td>Display custom description</td>
                            <td>Custom description text...</td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="url"]</code></td>
                            <td>Display page URL</td>
                            <td><?php echo home_url('/services/location/specialty/'); ?></td>
                        </tr>
                        <tr>
                            <td><code>[tc_field field="post_count"]</code></td>
                            <td>Number of posts found</td>
                            <td>15</td>
                        </tr>
                        <tr>
                            <td><code>[tc_posts number="6" columns="3"]</code></td>
                            <td>Display posts grid</td>
                            <td>Grid of posts</td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get Combinations
     */
    public function ajax_get_combinations() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $specialty_id = isset($_POST['specialty_id']) ? intval($_POST['specialty_id']) : 0;
        
        $where = array();
        $values = array();
        
        if ($location_id) {
            $where[] = 'location_id = %d';
            $values[] = $location_id;
        }
        
        if ($specialty_id) {
            $where[] = 'specialty_id = %d';
            $values[] = $specialty_id;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT c.*, l.name as location_name, s.name as specialty_name
                 FROM {$this->table_name} c
                 LEFT JOIN {$wpdb->terms} l ON c.location_id = l.term_id
                 LEFT JOIN {$wpdb->terms} s ON c.specialty_id = s.term_id
                 $where_sql";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        $results = $wpdb->get_results($query);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Bulk Update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('tc_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $combo_ids = isset($_POST['combo_ids']) ? array_map('intval', $_POST['combo_ids']) : array();
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $value = isset($_POST['bulk_value']) ? sanitize_text_field($_POST['bulk_value']) : '';
        
        if (empty($combo_ids) || empty($action)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        switch ($action) {
            case 'content_block':
                $update_data['content_block_id'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_index':
                $update_data['robots_index'] = intval($value);
                $format[] = '%d';
                break;
            case 'robots_follow':
                $update_data['robots_follow'] = intval($value);
                $format[] = '%d';
                break;
            default:
                wp_send_json_error('Invalid action');
        }
        
        $success_count = 0;
        foreach ($combo_ids as $combo_id) {
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('id' => $combo_id),
                $format,
                array('%d')
            );
            
            if ($result !== false) {
                $success_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('%d combinations updated', $success_count)
        ));
    }
    
    /**
     * Blocksy Integration: Add Display Conditions
     */
    public function add_blocksy_conditions($conditions) {
        if (!get_query_var('tc_combo')) {
            return $conditions;
        }
        
        $conditions[] = array(
            'type' => 'taxonomy_combination',
            'location' => get_query_var('tc_location'),
            'specialty' => get_query_var('tc_specialty')
        );
        
        return $conditions;
    }
    
    /**
     * Blocksy Integration: Check Condition Match
     */
    public function check_blocksy_condition($match, $condition, $block_id) {
        if (!isset($condition['type']) || $condition['type'] !== 'taxonomy_combination') {
            return $match;
        }
        
        $current_location = get_query_var('tc_location');
        $current_specialty = get_query_var('tc_specialty');
        
        if (!$current_location || !$current_specialty) {
            return false;
        }
        
        // Check if this block is assigned to the current combination
        global $wpdb;
        
        $location = get_term_by('slug', $current_location, $this->taxonomy_2);
        $specialty = get_term_by('slug', $current_specialty, $this->taxonomy_1);
        
        if (!$location || !$specialty) {
            return false;
        }
        
        $combo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE location_id = %d AND specialty_id = %d",
            $location->term_id,
            $specialty->term_id
        ));
        
        if ($combo) {
            return ($combo->content_block_id == $block_id || 
                    $combo->header_content_block_id == $block_id ||
                    $combo->footer_content_block_id == $block_id);
        }
        
        return false;
    }
}

// Initialize the plugin
new TaxonomyCombinationPages();

/**
 * Helper function to get combination data
 */
function tc_get_combination_data() {
    global $wp_query;
    
    if (isset($wp_query->tc_data)) {
        return $wp_query->tc_data;
    }
    
    return null;
}

/**
 * Helper function to check if on combination page
 */
function is_taxonomy_combination() {
    return (bool) get_query_var('tc_combo');
}

/**
 * Helper function to get combination URL
 */
function tc_get_combination_url($location_slug, $specialty_slug) {
    $base = get_option('tc_url_base', 'services');
    return home_url($base . '/' . $location_slug . '/' . $specialty_slug . '/');
}