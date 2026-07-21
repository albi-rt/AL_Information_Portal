<?php
/**
 * Final fallback. Most routes use page.php / page-templates / archive-*.
 *
 * @package NSW_Theme
 */

get_header();
?>
<section class="section">
	<div class="container">
		<div class="prose">
			<?php
			if ( have_posts() ) :
				while ( have_posts() ) :
					the_post();
					?>
					<article>
						<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<?php the_excerpt(); ?>
					</article>
					<?php
				endwhile;
				the_posts_pagination();
			else :
				?>
				<h1><?php esc_html_e( 'Nothing here yet.', 'nsw-theme' ); ?></h1>
				<?php
			endif;
			?>
		</div>
	</div>
</section>
<?php
get_footer();
