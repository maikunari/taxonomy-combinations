<?php
/**
 * Simple Archive template for Taxonomy Combination pages
 * 
 * Copy this to your theme directory (wp-content/themes/blocksy-child/)
 * The plugin will automatically inject ACF fields via hooks
 *
 * @package Blocksy
 */

get_header();

if (
	! function_exists('elementor_theme_do_location')
	||
	! elementor_theme_do_location('archive')
) {
	// Just use Blocksy's standard archive template
	// The plugin hooks will add brief_intro before and full_description after
	get_template_part('template-parts/archive');
}

get_footer();