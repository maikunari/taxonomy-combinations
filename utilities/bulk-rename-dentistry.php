<?php
/**
 * Bulk Rename Dentistry to Dentist in Combination Pages
 * Upload to WordPress root and run once
 */

require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('Access denied');
}

echo "<h1>Bulk Rename: Dentistry → Dentist</h1>";

// Get all combination posts
$args = array(
    'post_type' => 'tc_combination',
    'posts_per_page' => -1,
    'post_status' => 'any',
    's' => 'Dentistry' // Search for posts containing "Dentistry"
);

$combinations = get_posts($args);

echo "<p>Found " . count($combinations) . " combination posts containing 'Dentistry'</p>";

$updated_count = 0;
$updated_posts = array();

foreach ($combinations as $post) {
    $old_title = $post->post_title;
    $old_slug = $post->post_name;
    
    // Check if title contains "Dentistry"
    if (strpos($old_title, 'Dentistry') !== false) {
        // Replace in title
        $new_title = str_replace('Dentistry', 'Dentist', $old_title);
        
        // Replace in slug (dentistry -> dentist)
        $new_slug = str_replace('dentistry', 'dentist', $old_slug);
        
        // Update the post
        $update_args = array(
            'ID' => $post->ID,
            'post_title' => $new_title,
            'post_name' => $new_slug
        );
        
        $result = wp_update_post($update_args);
        
        if ($result && !is_wp_error($result)) {
            $updated_count++;
            $updated_posts[] = array(
                'id' => $post->ID,
                'old_title' => $old_title,
                'new_title' => $new_title,
                'old_slug' => $old_slug,
                'new_slug' => $new_slug,
                'url' => get_permalink($post->ID)
            );
            
            echo "<div style='margin: 10px 0; padding: 10px; background: #f0f8ff; border-left: 4px solid #4CAF50;'>";
            echo "<strong>✓ Updated Post ID {$post->ID}:</strong><br>";
            echo "Title: {$old_title} → {$new_title}<br>";
            echo "Slug: {$old_slug} → {$new_slug}<br>";
            echo "<a href='" . get_permalink($post->ID) . "' target='_blank'>View Page</a>";
            echo "</div>";
        } else {
            echo "<div style='margin: 10px 0; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;'>";
            echo "<strong>⚠ Failed to update Post ID {$post->ID}:</strong> {$old_title}";
            echo "</div>";
        }
    }
}

// Also check content for any references to Dentistry
echo "<hr>";
echo "<h2>Checking ACF Fields and Content</h2>";

$content_updated = 0;

foreach ($combinations as $post) {
    $updates_needed = false;
    $field_updates = array();
    
    // Check ACF fields
    if (function_exists('get_field')) {
        $brief_intro = get_field('brief_intro', $post->ID);
        $full_description = get_field('full_description', $post->ID);
        
        if ($brief_intro && strpos($brief_intro, 'Dentistry') !== false) {
            $new_brief = str_replace('Dentistry', 'Dentist', $brief_intro);
            $new_brief = str_replace('dentistry', 'dentist', $new_brief);
            update_field('brief_intro', $new_brief, $post->ID);
            $field_updates[] = "brief_intro";
            $updates_needed = true;
        }
        
        if ($full_description && strpos($full_description, 'Dentistry') !== false) {
            $new_full = str_replace('Dentistry', 'Dentist', $full_description);
            $new_full = str_replace('dentistry', 'dentist', $new_full);
            update_field('full_description', $new_full, $post->ID);
            $field_updates[] = "full_description";
            $updates_needed = true;
        }
    }
    
    // Check post content
    if (strpos($post->post_content, 'Dentistry') !== false || strpos($post->post_content, 'dentistry') !== false) {
        $new_content = str_replace('Dentistry', 'Dentist', $post->post_content);
        $new_content = str_replace('dentistry', 'dentist', $new_content);
        
        wp_update_post(array(
            'ID' => $post->ID,
            'post_content' => $new_content
        ));
        
        $field_updates[] = "post_content";
        $updates_needed = true;
    }
    
    if ($updates_needed) {
        $content_updated++;
        echo "<div style='margin: 5px 0; padding: 5px; background: #e8f5e9;'>";
        echo "✓ Updated content fields in Post ID {$post->ID}: " . implode(', ', $field_updates);
        echo "</div>";
    }
}

// Summary
echo "<hr>";
echo "<h2>Summary</h2>";
echo "<div style='padding: 20px; background: #e3f2fd; border: 1px solid #2196F3;'>";
echo "<p><strong>Titles/Slugs Updated:</strong> {$updated_count} posts</p>";
echo "<p><strong>Content Fields Updated:</strong> {$content_updated} posts</p>";
echo "<p><strong>Total Processed:</strong> " . count($combinations) . " posts</p>";
echo "</div>";

// Important notes
echo "<h3>Important Next Steps:</h3>";
echo "<ol>";
echo "<li><strong>Flush Permalinks:</strong> Go to Settings → Permalinks and click 'Save Changes'</li>";
echo "<li><strong>Clear Caches:</strong> Clear any caching plugins, CDN cache, and browser cache</li>";
echo "<li><strong>Update External Links:</strong> If you have external links to the old URLs, set up redirects</li>";
echo "<li><strong>Update Menus:</strong> Check if any WordPress menus reference the old URLs</li>";
echo "</ol>";

// Optional: Set up redirects
echo "<h3>Redirect Rules (Optional)</h3>";
echo "<p>Add these to your .htaccess file or redirect plugin:</p>";
echo "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>";
foreach ($updated_posts as $post) {
    if ($post['old_slug'] !== $post['new_slug']) {
        $old_path = parse_url(str_replace($post['new_slug'], $post['old_slug'], $post['url']), PHP_URL_PATH);
        $new_path = parse_url($post['url'], PHP_URL_PATH);
        echo "Redirect 301 {$old_path} {$new_path}\n";
    }
}
echo "</pre>";

echo "<hr>";
echo "<p style='color: green; font-weight: bold;'>✓ Bulk rename complete!</p>";
echo "<p>You can now delete this file.</p>";
?>