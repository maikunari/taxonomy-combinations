<?php
/**
 * Archive template for Taxonomy Combination pages
 * 
 * Copy this to your theme directory (wp-content/themes/blocksy-child/)
 * This template makes combination pages display as archives with content blocks
 *
 * @package Blocksy
 */

get_header();

// Get the combination post data for text content
global $post;
$combination_post = $post;

// Get ACF field content (or fallback to old meta if ACF not available)
if (function_exists('get_field')) {
    // Use ACF fields
    $brief_intro = get_field('brief_intro', $combination_post->ID);
    $full_description = get_field('full_description', $combination_post->ID);
} else {
    // Fallback to old meta fields
    $brief_intro = get_post_meta($combination_post->ID, '_tc_brief_intro', true);
    $full_description = get_post_meta($combination_post->ID, '_tc_full_description', true);
}

if (
	! function_exists('elementor_theme_do_location')
	||
	! elementor_theme_do_location('archive')
) {
	?>
	<div class="tc-combination-archive">
		<?php
		// Display brief intro if set
		if (!empty($brief_intro)) {
			echo '<div class="tc-brief-intro ct-container">';
			echo '<div class="ct-container">';
			echo wpautop($brief_intro);
			echo '</div>';
			echo '</div>';
		}
		?>
		
		<?php
		// Use Blocksy's archive template part for the main content (provider listings)
		get_template_part('template-parts/archive');
		?>
		
		<?php
		// Display full description if set
		if (!empty($full_description)) {
			echo '<div class="tc-full-description ct-container">';
			echo '<div class="ct-container">';
			echo wpautop($full_description);
			echo '</div>';
			echo '</div>';
		}
		?>
	</div>
	<?php
}

get_footer();