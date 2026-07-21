<?php
/**
 * "What is NSW" feature strip (homepage).
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Block overrides come via query vars; empty falls back to the localized string.
$wisnsw_title = get_query_var( 'nsw_theme_wisnsw_title' ) ?: nsw_theme_t( 'whatIsNsw.title', 'What is the National Single Window?' );
$wisnsw_d1    = get_query_var( 'nsw_theme_wisnsw_desc1' ) ?: nsw_theme_t( 'whatIsNsw.description', '' );
$wisnsw_d2    = get_query_var( 'nsw_theme_wisnsw_desc2' ) ?: nsw_theme_t( 'whatIsNsw.description2', '' );
$wisnsw_btn   = get_query_var( 'nsw_theme_wisnsw_btnText' ) ?: nsw_theme_t( 'common.learnMore', 'Learn more' );
$wisnsw_url   = get_query_var( 'nsw_theme_wisnsw_btnUrl' ) ?: nsw_theme_path_url( 'how-it-works' );
?>
<section class="section">
	<div class="container">
		<div class="section-heading" data-reveal>
			<h2 class="section-heading__title"><?php echo esc_html( $wisnsw_title ); ?></h2>
			<?php if ( '' !== $wisnsw_d1 ) : ?><p class="section-heading__lede"><?php echo esc_html( $wisnsw_d1 ); ?></p><?php endif; ?>
			<?php if ( '' !== $wisnsw_d2 ) : ?><p class="section-heading__lede"><?php echo esc_html( $wisnsw_d2 ); ?></p><?php endif; ?>
		</div>

		<div class="grid grid--lg-3" style="margin-top:3rem">
			<?php
			$features = array(
				array(
					'icon'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
					'title' => get_query_var( 'nsw_theme_wisnsw_f1Title' ) ?: nsw_theme_t( 'whatIsNsw.feature1Title', 'Single Submission' ),
					'desc'  => get_query_var( 'nsw_theme_wisnsw_f1Desc' ) ?: nsw_theme_t( 'whatIsNsw.feature1Desc', 'Submit your data only once. NSW distributes it automatically to the relevant agencies.' ),
				),
				array(
					'icon'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
					'title' => get_query_var( 'nsw_theme_wisnsw_f2Title' ) ?: nsw_theme_t( 'whatIsNsw.feature2Title', 'Transparency' ),
					'desc'  => get_query_var( 'nsw_theme_wisnsw_f2Desc' ) ?: nsw_theme_t( 'whatIsNsw.feature2Desc', 'Track your application status in real time, at every step.' ),
				),
				array(
					'icon'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
					'title' => get_query_var( 'nsw_theme_wisnsw_f3Title' ) ?: nsw_theme_t( 'whatIsNsw.feature3Title', 'Speed' ),
					'desc'  => get_query_var( 'nsw_theme_wisnsw_f3Desc' ) ?: nsw_theme_t( 'whatIsNsw.feature3Desc', 'Parallel processing by regulatory agencies significantly reduces clearance time.' ),
				),
			);
			$i = 0;
			foreach ( $features as $f ) : $i++; ?>
				<div class="feature" data-reveal data-reveal-delay="<?php echo (int) $i; ?>">
					<div class="feature__icon"><?php echo $f['icon']; // svg ?></div>
					<h3 class="feature__title"><?php echo esc_html( $f['title'] ); ?></h3>
					<p class="feature__description"><?php echo esc_html( $f['desc'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="section-actions" data-reveal data-reveal-delay="3">
			<a class="btn btn--outline" href="<?php echo esc_url( $wisnsw_url ); ?>">
				<?php echo esc_html( $wisnsw_btn ); ?>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
			</a>
		</div>
	</div>
</section>
