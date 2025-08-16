<?php
get_header();

if (
	! function_exists('elementor_theme_do_location')
	||
	! elementor_theme_do_location('archive')
) {
	$prefix = blocksy_manager()->screen->get_prefix();
	$container_class = 'ct-container';
	
	echo blocksy_output_hero_section(['type' => 'type-2']);
	
	$section_class = '';
	if (! have_posts()) {
		$section_class = 'class="ct-no-results"';
	}
	?>
	<div class="<?php echo $container_class ?>" <?php echo wp_kses_post(blocksy_sidebar_position_attr()); ?> <?php echo blocksy_get_v_spacing() ?>>
		<section <?php echo $section_class ?>>
			<?php
				echo blocksy_output_hero_section(['type' => 'type-1']);
				echo blocksy_render_archive_cards();
			?>
		</section>
		
		<!-- Your custom content -->
		<div class="tc-combination-custom-content">
			<h1>Custom content after archive!</h1>
			<?php echo do_shortcode('[ct_content_block id="4152"]'); ?>
		</div>
		
		<?php get_sidebar(); ?>
	</div>
	<?php
}

get_footer();