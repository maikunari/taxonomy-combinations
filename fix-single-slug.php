<?php
/**
 * Fix Single Post Slug
 * Upload to WordPress root and run with ?post_id=XXX
 */

require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('Access denied');
}

$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

if (!$post_id) {
    ?>
    <h1>Fix Single Post Slug</h1>
    <form method="get">
        <p>
            <label>Post ID: <input type="number" name="post_id" required></label>
        </p>
        <p>Or enter the current slug to find it:</p>
        <p>
            <label>Current Slug: <input type="text" name="find_slug" placeholder="english-dentistry-in-tokyo"></label>
        </p>
        <button type="submit">Find Post</button>
    </form>
    <?php
    
    // If searching by slug
    if (isset($_GET['find_slug'])) {
        $slug = sanitize_text_field($_GET['find_slug']);
        $post = get_page_by_path($slug, OBJECT, 'tc_combination');
        if ($post) {
            echo "<p>Found: <strong>{$post->post_title}</strong> (ID: {$post->ID})</p>";
            echo "<p><a href='?post_id={$post->ID}'>Click here to edit this post</a></p>";
        } else {
            echo "<p style='color: red;'>No post found with slug: {$slug}</p>";
        }
    }
    
    die();
}

$post = get_post($post_id);

if (!$post) {
    die("Post ID {$post_id} not found");
}

if (isset($_POST['new_slug'])) {
    $new_slug = sanitize_title($_POST['new_slug']);
    $new_title = isset($_POST['new_title']) ? sanitize_text_field($_POST['new_title']) : $post->post_title;
    
    $result = wp_update_post(array(
        'ID' => $post_id,
        'post_name' => $new_slug,
        'post_title' => $new_title
    ));
    
    if ($result && !is_wp_error($result)) {
        echo "<div style='padding: 10px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb;'>";
        echo "✓ Successfully updated!<br>";
        echo "New Title: {$new_title}<br>";
        echo "New Slug: {$new_slug}<br>";
        echo "URL: <a href='" . get_permalink($post_id) . "' target='_blank'>" . get_permalink($post_id) . "</a>";
        echo "</div>";
        
        // Reload post data
        $post = get_post($post_id);
    } else {
        echo "<div style='padding: 10px; background: #f8d7da; color: #721c24;'>Error updating post</div>";
    }
}

?>
<h1>Edit Post Slug</h1>
<div style="background: #f5f5f5; padding: 20px; margin: 20px 0;">
    <h2>Current Post Details:</h2>
    <p><strong>ID:</strong> <?php echo $post->ID; ?></p>
    <p><strong>Title:</strong> <?php echo $post->post_title; ?></p>
    <p><strong>Current Slug:</strong> <code><?php echo $post->post_name; ?></code></p>
    <p><strong>Status:</strong> <?php echo $post->post_status; ?></p>
    <p><strong>Current URL:</strong> <a href="<?php echo get_permalink($post->ID); ?>" target="_blank"><?php echo get_permalink($post->ID); ?></a></p>
</div>

<form method="post" style="background: #fff; padding: 20px; border: 1px solid #ddd;">
    <h2>Update Post:</h2>
    
    <p>
        <label for="new_title">New Title:</label><br>
        <input type="text" id="new_title" name="new_title" value="<?php echo esc_attr(str_replace('Dentistry', 'Dentist', $post->post_title)); ?>" style="width: 100%; padding: 5px;">
    </p>
    
    <p>
        <label for="new_slug">New Slug:</label><br>
        <input type="text" id="new_slug" name="new_slug" value="<?php echo esc_attr(str_replace('dentistry', 'dentist', $post->post_name)); ?>" style="width: 100%; padding: 5px;">
        <small>This will be the URL slug (e.g., "english-dentist-in-tokyo")</small>
    </p>
    
    <button type="submit" style="background: #0073aa; color: white; padding: 10px 20px; border: none; cursor: pointer;">Update Post</button>
</form>

<div style="margin-top: 20px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107;">
    <strong>After updating:</strong>
    <ol>
        <li>Go to Settings → Permalinks and click "Save Changes" to flush rewrite rules</li>
        <li>Clear any caching plugins</li>
        <li>The old URL will no longer work - consider setting up a redirect</li>
    </ol>
</div>
<?php