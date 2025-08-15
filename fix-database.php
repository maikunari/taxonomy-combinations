<?php
/**
 * Database Fix Script - Run this once to add missing columns
 * 
 * Usage: Add to WordPress root and access via browser, then delete
 */

// Load WordPress
require_once('wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Access denied');
}

global $wpdb;
$table_name = $wpdb->prefix . 'taxonomy_combinations';

echo "<h2>Database Fix for Taxonomy Combinations Plugin</h2>";

// Check if brief_intro column exists
$columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
$column_names = array_map(function($col) { return $col->Field; }, $columns);

echo "<h3>Current columns:</h3>";
echo "<pre>" . print_r($column_names, true) . "</pre>";

if (!in_array('brief_intro', $column_names)) {
    echo "<p>Adding brief_intro column...</p>";
    $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN brief_intro TEXT AFTER custom_description");
    if ($result !== false) {
        echo "<p style='color: green;'>✓ brief_intro column added successfully</p>";
    } else {
        echo "<p style='color: red;'>✗ Error adding brief_intro column: " . $wpdb->last_error . "</p>";
    }
} else {
    echo "<p>brief_intro column already exists</p>";
}

if (!in_array('full_description', $column_names)) {
    echo "<p>Adding full_description column...</p>";
    $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN full_description LONGTEXT AFTER brief_intro");
    if ($result !== false) {
        echo "<p style='color: green;'>✓ full_description column added successfully</p>";
    } else {
        echo "<p style='color: red;'>✗ Error adding full_description column: " . $wpdb->last_error . "</p>";
    }
} else {
    echo "<p>full_description column already exists</p>";
}

// Verify columns were added
$columns_after = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
$column_names_after = array_map(function($col) { return $col->Field; }, $columns_after);

echo "<h3>Columns after update:</h3>";
echo "<pre>" . print_r($column_names_after, true) . "</pre>";

echo "<h2>Testing Blocksy Functions</h2>";
echo "<p>Checking for Blocksy content block rendering functions...</p>";

if (function_exists('blocksy_render_content_block')) {
    echo "<p style='color: green;'>✓ blocksy_render_content_block() exists</p>";
} else {
    echo "<p style='color: orange;'>✗ blocksy_render_content_block() not found</p>";
}

if (function_exists('blc_render_content_block')) {
    echo "<p style='color: green;'>✓ blc_render_content_block() exists</p>";
} else {
    echo "<p style='color: orange;'>✗ blc_render_content_block() not found</p>";
}

if (function_exists('blocksy_render')) {
    echo "<p style='color: green;'>✓ blocksy_render() exists</p>";
} else {
    echo "<p style='color: orange;'>✗ blocksy_render() not found</p>";
}

// Check for Blocksy content blocks
$content_blocks = get_posts(array(
    'post_type' => 'ct_content_block',
    'posts_per_page' => -1,
    'post_status' => 'publish'
));

echo "<h3>Content Blocks Found: " . count($content_blocks) . "</h3>";
if (!empty($content_blocks)) {
    echo "<ul>";
    foreach ($content_blocks as $block) {
        echo "<li>ID: {$block->ID} - {$block->post_title}</li>";
    }
    echo "</ul>";
}

echo "<hr>";
echo "<p><strong>Next steps:</strong></p>";
echo "<ol>";
echo "<li>Delete this file after running</li>";
echo "<li>Deactivate and reactivate the plugin</li>";
echo "<li>Clear any caching plugins</li>";
echo "</ol>";
?>