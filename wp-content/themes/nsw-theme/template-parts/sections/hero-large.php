<?php
/**
 * Large hero (homepage). Video background + overlay + title + subtitle + CTAs.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title    = (string) get_query_var( 'nsw_theme_hero_title' );
$subtitle = (string) get_query_var( 'nsw_theme_hero_subtitle' );
$children = (string) get_query_var( 'nsw_theme_hero_children' );
$bg_url   = (string) get_query_var( 'nsw_theme_hero_bg_url' );
$bg_mime  = (string) get_query_var( 'nsw_theme_hero_bg_mime' );

// Empty (background media was removed) -> fall back to the theme's shipped video.
if ( '' === $bg_url ) {
	$bg_url  = NSW_THEME_URI . 'assets/video/hero.mp4';
	$bg_mime = 'video/mp4';
}
// Decide image vs video from the mime, falling back to the file extension.
$is_video = ( false !== strpos( $bg_mime, 'video' ) )
	|| ( '' === $bg_mime && (bool) preg_match( '/\.(mp4|webm|ogv|mov)(\?.*)?$/i', $bg_url ) );
$poster = function_exists( 'nsw_theme_hero_poster_url' ) ? nsw_theme_hero_poster_url() : ( NSW_THEME_URI . 'assets/images/hero/hero-bg.jpg' );
?>
<section class="hero hero--large">
	<?php if ( ! $is_video ) : ?>
		<img class="hero--large__media" src="<?php echo esc_url( $bg_url ); ?>" alt="" aria-hidden="true" />
	<?php else : ?>
		<video
			class="hero--large__media"
			autoplay
			loop
			muted
			playsinline
			preload="metadata"
			disablepictureinpicture
			aria-hidden="true"
			poster="<?php echo esc_url( $poster ); ?>"
		>
			<source src="<?php echo esc_url( $bg_url ); ?>" type="<?php echo esc_attr( $bg_mime ?: 'video/mp4' ); ?>" />
		</video>
	<?php endif; ?>
	<div class="hero--large__overlay" aria-hidden="true"></div>
	<div class="hero--large__gradient" aria-hidden="true"></div>
	<div class="hero--large__content">
		<div class="hero--large__copy" data-reveal>
			<h1 class="hero--large__title"><?php echo esc_html( $title ); ?></h1>
			<?php if ( '' !== $subtitle ) : ?>
				<p class="hero--large__subtitle"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $children ) : ?>
				<div class="hero--large__actions"><?php echo $children; // intentional: prebuilt HTML from caller ?></div>
			<?php endif; ?>
		</div>
	</div>
</section>
