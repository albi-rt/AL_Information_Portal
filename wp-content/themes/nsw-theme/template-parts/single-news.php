<?php
/**
 * Single news article body (bespoke design): gradient hero, 2-column content
 * grid with related-articles sidebar. Rendered by the nsw-theme/single-post
 * block (single.html) — reuses the main loop for the current post.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Rendered by the single-post block, which sets up postdata for the queried
// post — so we render the current global $post directly (no main-query loop).
	$post_id = get_the_ID();
	$terms   = get_the_terms( $post_id, 'category' );
	$cat     = ( $terms && ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
	$author  = (string) get_post_meta( $post_id, '_nsw_theme_author_text', true );
	if ( '' === $author ) {
		$author = (string) get_the_author();
	}

	$related_args = array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 3,
		'post__not_in'   => array( $post_id ),
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	);

	if ( $cat ) {
		$related_args['tax_query'] = array(
			array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $cat->term_id,
			),
		);
	}

	// Polylang scopes related posts to the active language on its own.
	$related = new WP_Query( $related_args );
	?>
	<article <?php post_class( 'article' ); ?>>

		<header class="article__hero">
			<div class="container article__hero-inner">
				<a class="article__back" href="<?php echo esc_url( nsw_theme_path_url( 'news' ) ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
					<?php echo esc_html( nsw_theme_t( 'common.previous', 'Previous' ) ); ?>
				</a>

				<?php if ( $cat ) : ?>
					<span class="badge"><?php echo esc_html( translate( $cat->name, 'nsw-theme' ) ); ?></span>
				<?php endif; ?>

				<h1 class="article__title"><?php the_title(); ?></h1>

				<ul class="article__meta">
					<li>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
						<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					</li>
					<?php if ( $author ) : ?>
						<li>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
							<?php echo esc_html( $author ); ?>
						</li>
					<?php endif; ?>
				</ul>
			</div>
		</header>

		<section class="section article__content-section">
			<div class="container">
				<div class="article__layout">

					<div class="article__main">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'nsw-theme-hero', array( 'class' => 'article__cover', 'loading' => 'eager', 'decoding' => 'async', 'alt' => '' ) ); ?>
						<?php endif; ?>

						<div class="article__body">
							<?php the_content(); ?>
						</div>

						<hr class="article__separator">

						<div class="article__share">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
							<span><?php echo esc_html( nsw_theme_t( 'common.share', 'Share' ) ); ?></span>
						</div>
					</div>

					<?php if ( $related->have_posts() ) : ?>
						<aside class="article__sidebar" aria-label="<?php esc_attr_e( 'Related articles', 'nsw-theme' ); ?>">
							<h2 class="article__sidebar-title"><?php echo esc_html( nsw_theme_t( 'newsPage.relatedTitle', 'Related Articles' ) ); ?></h2>
							<div class="article__related">
								<?php while ( $related->have_posts() ) :
									$related->the_post();
									$rel_terms = get_the_terms( get_the_ID(), 'category' );
									$rel_cat   = ( $rel_terms && ! is_wp_error( $rel_terms ) && ! empty( $rel_terms ) ) ? $rel_terms[0]->name : '';
									?>
									<a class="article__related-card" href="<?php the_permalink(); ?>">
										<?php if ( $rel_cat ) : ?>
											<span class="badge article__related-badge"><?php echo esc_html( translate( $rel_cat, 'nsw-theme' ) ); ?></span>
										<?php endif; ?>
										<h3 class="article__related-title"><?php the_title(); ?></h3>
										<p class="article__related-date"><?php echo esc_html( get_the_date() ); ?></p>
									</a>
								<?php endwhile; ?>
							</div>
						</aside>
						<?php wp_reset_postdata(); ?>
					<?php endif; ?>

				</div>
			</div>
		</section>

	</article>
	<?php
