<?php
/**
 * Default page template.
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
?>
<section class="section">
	<div class="container">
		<div class="prose">
			<?php
			if ( have_posts() ) :
				while ( have_posts() ) :
					the_post();
					the_content();
				endwhile;
			endif;
			?>
		</div>
	</div>
</section>
<?php
get_template_part( 'template-parts/sections/cta' );
get_footer();
