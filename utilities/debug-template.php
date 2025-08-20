<?php
/**
 * Debug Template Loading for Combination Pages
 * Upload to WordPress root and access via browser with ?post_id=4049
 */

require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('Access denied');
}

$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 4049;

echo "<h1>Debug Template Loading for Post ID: {$post_id}</h1>";

// Get the post
$post = get_post($post_id);
if (!$post) {
    die("Post not found");
}

echo "<h2>Post Details:</h2>";
echo "<p>Title: {$post->post_title}</p>";
echo "<p>Type: {$post->post_type}</p>";
echo "<p>Status: {$post->post_status}</p>";
echo "<p>URL: <a href='" . get_permalink($post_id) . "' target='_blank'>View Page</a></p>";

echo "<h2>Template Detection:</h2>";

// Set up query context
global $wp_query;
$original_query = $wp_query;
$wp_query = new WP_Query(array(
    'p' => $post_id,
    'post_type' => 'tc_combination'
));

if ($wp_query->have_posts()) {
    $wp_query->the_post();
    
    echo "<p>is_singular('tc_combination'): " . (is_singular('tc_combination') ? 'YES' : 'NO') . "</p>";
    echo "<p>is_single(): " . (is_single() ? 'YES' : 'NO') . "</p>";
    echo "<p>is_archive(): " . (is_archive() ? 'YES' : 'NO') . "</p>";
    
    // Check what template WordPress would use
    $templates = array();
    if (is_singular('tc_combination')) {
        $templates[] = "single-tc_combination.php";
        $templates[] = "archive-tc_combination.php";
        $templates[] = "single.php";
        $templates[] = "singular.php";
        $templates[] = "index.php";
    }
    
    echo "<h3>Template Hierarchy (in order):</h3>";
    echo "<ol>";
    foreach ($templates as $template) {
        $found = locate_template($template);
        if ($found) {
            echo "<li style='color: green;'>✓ {$template} - FOUND at: {$found}</li>";
        } else {
            echo "<li style='color: gray;'>✗ {$template} - not found</li>";
        }
    }
    echo "</ol>";
    
    // Check which template would actually be used
    $template = '';
    if (is_singular('tc_combination')) {
        $template = get_single_template();
    }
    echo "<p><strong>Template that would be used:</strong> {$template}</p>";
    
    // Check if our filter is working
    echo "<h3>Filter Check:</h3>";
    $filtered_template = apply_filters('template_include', $template);
    echo "<p><strong>After template_include filter:</strong> {$filtered_template}</p>";
}

// Restore original query
$wp_query = $original_query;
wp_reset_postdata();

echo "<h2>ACF Field Values (Direct Access):</h2>";
if (function_exists('get_field')) {
    $brief = get_field('brief_intro', $post_id);
    $full = get_field('full_description', $post_id);
    
    echo "<p><strong>brief_intro:</strong></p>";
    echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f5f5f5;'>";
    if ($brief) {
        echo nl2br(htmlspecialchars($brief));
    } else {
        echo "<em>EMPTY</em>";
    }
    echo "</div>";
    
    echo "<p><strong>full_description:</strong></p>";
    echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f5f5f5;'>";
    if ($full) {
        echo $full; // Already HTML
    } else {
        echo "<em>EMPTY</em>";
    }
    echo "</div>";
}

echo "<h2>Template File Contents Check:</h2>";
$template_path = get_stylesheet_directory() . '/archive-tc_combination.php';
if (file_exists($template_path)) {
    echo "<p style='color: green;'>✓ Template exists at: {$template_path}</p>";
    echo "<p>File size: " . filesize($template_path) . " bytes</p>";
    echo "<p>Last modified: " . date('Y-m-d H:i:s', filemtime($template_path)) . "</p>";
    
    // Check if it contains our ACF code
    $content = file_get_contents($template_path);
    if (strpos($content, 'get_field') !== false) {
        echo "<p style='color: green;'>✓ Template contains get_field() calls</p>";
    } else {
        echo "<p style='color: red;'>✗ Template does NOT contain get_field() calls</p>";
    }
    
    if (strpos($content, 'brief_intro') !== false) {
        echo "<p style='color: green;'>✓ Template references brief_intro field</p>";
    } else {
        echo "<p style='color: red;'>✗ Template does NOT reference brief_intro field</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Template NOT found at: {$template_path}</p>";
}

echo "<h2>Plugin Hook Status:</h2>";
global $wp_filter;

// Check if our plugin's filters are registered
$hooks_to_check = array(
    'template_include',
    'template_redirect',
    'get_the_archive_title',
    'pre_get_posts'
);

foreach ($hooks_to_check as $hook) {
    echo "<h4>{$hook}:</h4>";
    if (isset($wp_filter[$hook])) {
        $found_plugin = false;
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && is_object($callback['function'][0])) {
                    $class = get_class($callback['function'][0]);
                    if ($class === 'TaxonomyCombinationPages') {
                        echo "<p style='color: green;'>✓ Found TaxonomyCombinationPages::{$callback['function'][1]} at priority {$priority}</p>";
                        $found_plugin = true;
                    }
                }
            }
        }
        if (!$found_plugin) {
            echo "<p style='color: orange;'>No TaxonomyCombinationPages hooks found</p>";
        }
    } else {
        echo "<p style='color: red;'>Hook not registered</p>";
    }
}

echo "<hr>";
echo "<h2>Recommended Actions:</h2>";
echo "<ol>";
echo "<li>Check if single-tc_combination.php exists in your theme (it might be overriding archive-tc_combination.php)</li>";
echo "<li>Clear all caches (browser, CDN, WordPress)</li>";
echo "<li>Check if another plugin is interfering with template loading</li>";
echo "<li>Try adding die('TEMPLATE LOADED'); at the top of archive-tc_combination.php to verify it's being used</li>";
echo "</ol>";
?>