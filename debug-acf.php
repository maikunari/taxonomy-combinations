<?php
/**
 * Debug ACF Fields on Combination Pages
 * Upload to WordPress root and access via browser
 */

require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('Access denied');
}

echo "<h1>Debug ACF Fields for Combinations</h1>";

// Get a test combination
$combo = get_posts(array(
    'post_type' => 'tc_combination',
    'posts_per_page' => 1,
    'post_status' => 'publish',
    'name' => 'english-dentistry-in-tokyo'
))[0] ?? null;

if (!$combo) {
    $combo = get_posts(array(
        'post_type' => 'tc_combination',
        'posts_per_page' => 1,
        'post_status' => 'publish'
    ))[0] ?? null;
}

if (!$combo) {
    die('No combination posts found');
}

echo "<h2>Testing with: {$combo->post_title}</h2>";
echo "<p>Post ID: {$combo->ID}</p>";
echo "<p>URL: <a href='" . get_permalink($combo->ID) . "' target='_blank'>View Page</a></p>";

echo "<h3>ACF Function Check:</h3>";
echo "<p>get_field() exists: " . (function_exists('get_field') ? 'YES' : 'NO') . "</p>";
echo "<p>ACF plugin active: " . (class_exists('ACF') ? 'YES' : 'NO') . "</p>";

echo "<h3>ACF Field Values:</h3>";
if (function_exists('get_field')) {
    $brief = get_field('brief_intro', $combo->ID);
    $full = get_field('full_description', $combo->ID);
    
    echo "<p><strong>brief_intro:</strong> ";
    if ($brief) {
        echo htmlspecialchars(substr($brief, 0, 100)) . "...";
    } else {
        echo "EMPTY or NOT FOUND";
    }
    echo "</p>";
    
    echo "<p><strong>full_description:</strong> ";
    if ($full) {
        echo htmlspecialchars(substr($full, 0, 100)) . "...";
    } else {
        echo "EMPTY or NOT FOUND";
    }
    echo "</p>";
    
    // Try with field key
    echo "<h4>Trying with field keys (group_689f448186bb3):</h4>";
    $fields = get_field_objects($combo->ID);
    if ($fields) {
        echo "<pre>";
        foreach ($fields as $field_name => $field) {
            echo "Field: {$field_name}\n";
            echo "  Label: {$field['label']}\n";
            echo "  Key: {$field['key']}\n";
            echo "  Value: " . substr($field['value'], 0, 50) . "...\n\n";
        }
        echo "</pre>";
    } else {
        echo "<p>No ACF fields found for this post</p>";
    }
}

echo "<h3>Old Meta Values (for comparison):</h3>";
$old_brief = get_post_meta($combo->ID, '_tc_brief_intro', true);
$old_full = get_post_meta($combo->ID, '_tc_full_description', true);

echo "<p><strong>_tc_brief_intro:</strong> ";
echo $old_brief ? htmlspecialchars(substr($old_brief, 0, 100)) . "..." : "EMPTY";
echo "</p>";

echo "<p><strong>_tc_full_description:</strong> ";
echo $old_full ? htmlspecialchars(substr($old_full, 0, 100)) . "..." : "EMPTY";
echo "</p>";

echo "<h3>Template File Check:</h3>";
$theme_dir = get_template_directory();
$child_theme_dir = get_stylesheet_directory();

echo "<p>Theme directory: {$theme_dir}</p>";
echo "<p>Child theme directory: {$child_theme_dir}</p>";

$template_locations = array(
    $child_theme_dir . '/archive-tc_combination.php',
    $theme_dir . '/archive-tc_combination.php'
);

foreach ($template_locations as $location) {
    if (file_exists($location)) {
        echo "<p style='color: green;'>✓ Template found at: {$location}</p>";
        echo "<p>Last modified: " . date('Y-m-d H:i:s', filemtime($location)) . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Template NOT found at: {$location}</p>";
    }
}

echo "<h3>Cache Check:</h3>";
echo "<p>Page cache plugins active?</p>";
$cache_plugins = array(
    'wp-rocket/wp-rocket.php',
    'w3-total-cache/w3-total-cache.php',
    'wp-super-cache/wp-cache.php',
    'litespeed-cache/litespeed-cache.php'
);

$active_plugins = get_option('active_plugins');
foreach ($cache_plugins as $plugin) {
    if (in_array($plugin, $active_plugins)) {
        echo "<p style='color: orange;'>⚠ {$plugin} is active - CLEAR CACHE!</p>";
    }
}

echo "<hr>";
echo "<p><strong>Next steps:</strong></p>";
echo "<ol>";
echo "<li>Make sure ACF fields are set up correctly</li>";
echo "<li>Add content to the ACF fields</li>";
echo "<li>Clear ALL caches (plugin cache, browser cache, CDN)</li>";
echo "<li>Verify the template file is in the right location</li>";
echo "</ol>";
?>