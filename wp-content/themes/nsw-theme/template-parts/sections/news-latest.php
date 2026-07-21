<?php
/**
 * Latest news strip (homepage). Queries the 3 most recent core Posts.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$locale = nsw_theme_current_locale();
$query  = new WP_Query(
	array(
		'post_type'      => 'post',
		'posts_per_page' => 3,
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);
?>
<section class="section">
	<div class="container">
		<div class="section-heading" data-reveal>
			<h2 class="section-heading__title"><?php echo esc_html( get_query_var( 'nsw_theme_news_title' ) ?: nsw_theme_t( 'newsSection.title', 'Latest News' ) ); ?></h2>
			<p class="section-heading__subtitle"><?php echo esc_html( get_query_var( 'nsw_theme_news_subtitle' ) ?: nsw_theme_t( 'newsSection.subtitle', 'Stay informed on the latest NSW developments' ) ); ?></p>
		</div>

		<div class="grid grid--lg-3" style="margin-top:3rem">
		<?php if ( $query->have_posts() ) : ?>
			<?php
			$i = 0;
			while ( $query->have_posts() ) :
				$query->the_post();
				$i++;
				set_query_var( 'nsw_theme_card_post', get_post() );
				set_query_var( 'nsw_theme_card_data', array() );
				set_query_var( 'nsw_theme_card_locale', $locale );
				?>
				<div data-reveal data-reveal-delay="<?php echo (int) min( $i, 3 ); ?>">
					<?php get_template_part( 'template-parts/cards/news', 'card' ); ?>
				</div>
				<?php
			endwhile;
			wp_reset_postdata();
			?>
		<?php else : ?>
			<?php
			$fallback = array(); // News is DB-backed; the empty state renders no cards.
			$i        = 0;
			foreach ( array_slice( $fallback, 0, 3 ) as $article ) :
				$i++;
				set_query_var( 'nsw_theme_card_post', null );
				set_query_var( 'nsw_theme_card_data', $article );
				set_query_var( 'nsw_theme_card_locale', $locale );
				?>
				<div data-reveal data-reveal-delay="<?php echo (int) min( $i, 3 ); ?>">
					<?php get_template_part( 'template-parts/cards/news', 'card' ); ?>
				</div>
				<?php
			endforeach;
			?>
		<?php endif; ?>
		</div>

		<div class="section-actions" data-reveal data-reveal-delay="3">
			<a class="btn btn--outline" href="<?php echo esc_url( get_query_var( 'nsw_theme_news_seeAllUrl' ) ?: nsw_theme_path_url( 'news' ) ); ?>">
				<?php echo esc_html( get_query_var( 'nsw_theme_news_seeAllText' ) ?: nsw_theme_t( 'newsSection.seeAll', 'View all news' ) ); ?>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
			</a>
		</div>
	</div>
</section>
