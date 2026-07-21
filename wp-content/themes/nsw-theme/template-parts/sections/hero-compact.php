<?php
/**
 * Compact hero (sub-pages). Image bg + 30% black overlay + title + subtitle.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title    = (string) get_query_var( 'nsw_theme_hero_title' );
$subtitle = (string) get_query_var( 'nsw_theme_hero_subtitle' );
?>
<section class="hero hero--compact">
	<img
		class="hero--compact__bg"
		src="<?php echo esc_url( NSW_THEME_URI . 'assets/images/hero/hero-bg.jpg' ); ?>"
		alt=""
		width="1920"
		height="640"
		loading="eager"
		fetchpriority="high"
		decoding="async"
		aria-hidden="true"
	/>
	<div class="hero--compact__overlay" aria-hidden="true"></div>
	<div class="hero--compact__content">
		<div data-reveal>
			<h1 class="hero--compact__title"><?php echo esc_html( $title ); ?></h1>
			<?php if ( '' !== $subtitle ) : ?>
				<p class="hero--compact__subtitle"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
