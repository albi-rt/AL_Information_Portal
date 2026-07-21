<?php
/**
 * Template Name: Wide page (no prose container, full-width sections)
 *
 * @package NSW_Theme
 */

get_header();

$title    = (string) get_the_title();
$subtitle = has_excerpt() ? (string) get_the_excerpt() : '';

nsw_theme_render_hero(
	array(
		'variant'  => 'compact',
		'title'    => $title,
		'subtitle' => $subtitle,
	)
);

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		?>
		<section class="section">
			<div class="container"><?php the_content(); ?></div>
		</section>
		<?php
	endwhile;
endif;

get_footer();
