<?php
/**
 * News card. Renders the cover image at the top when one is set (MK-style),
 * otherwise falls back to a gradient banner with the category label. Drives
 * both WP_Post (CPT) and array (bundled JSON fallback) sources.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post   = get_query_var( 'nsw_theme_card_post' );
$data   = (array) get_query_var( 'nsw_theme_card_data' );
$locale = (string) get_query_var( 'nsw_theme_card_locale' );
if ( '' === $locale ) {
	$locale = nsw_theme_current_locale();
}

$thumb_url = '';

if ( $post instanceof WP_Post ) {
	$url      = (string) get_permalink( $post );
	$title    = (string) get_the_title( $post );
	$excerpt  = (string) get_the_excerpt( $post );
	$date     = (string) get_the_date( '', $post );
	$terms    = get_the_terms( $post->ID, 'category' );
	$cat_slug = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->slug : '';
	$category = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
	if ( has_post_thumbnail( $post ) ) {
		$thumb_url = (string) get_the_post_thumbnail_url( $post, 'nsw-theme-card' );
	}
} elseif ( ! empty( $data ) ) {
	$slug     = isset( $data['slug'] ) ? (string) $data['slug'] : '';
	$url      = trailingslashit( nsw_theme_path_url( 'news' ) ) . $slug . '/';
	$title    = (string) ( $data['title'] ?? '' );
	$excerpt  = (string) ( $data['excerpt'] ?? '' );
	$date     = ! empty( $data['date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $data['date'] ) ) : '';
	$cat_slug = (string) ( $data['category'] ?? '' );
	$cat_map  = array(
		'announcements' => nsw_theme_t( 'common.announcements', 'Announcements' ),
		'updates'       => nsw_theme_t( 'common.updates', 'Updates' ),
		'events'        => nsw_theme_t( 'eventsPage.title', 'Events' ),
	);
	$category = $cat_map[ $cat_slug ] ?? ucfirst( $cat_slug );
	if ( ! empty( $data['image'] ) ) {
		$thumb_url = nsw_theme_asset_url( (string) $data['image'] );
	}
} else {
	return;
}

$card_classes = 'news-card' . ( $thumb_url ? ' has-thumb' : '' );
?>
<a class="<?php echo esc_attr( $card_classes ); ?>" href="<?php echo esc_url( $url ); ?>">
	<div class="news-card__banner">
		<?php if ( $thumb_url ) : ?>
			<img class="news-card__img" src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy" decoding="async" />
		<?php else : ?>
			<span class="news-card__placeholder-label"><?php echo esc_html( $category ); ?></span>
		<?php endif; ?>
	</div>
	<div class="news-card__body">
		<div class="news-card__meta">
			<?php if ( $category ) : ?>
				<span class="badge"><?php echo esc_html( $category ); ?></span>
			<?php endif; ?>
			<?php if ( $date ) : ?>
				<span><?php echo esc_html( $date ); ?></span>
			<?php endif; ?>
		</div>
		<h3 class="news-card__title line-clamp-2"><?php echo esc_html( $title ); ?></h3>
		<?php if ( $excerpt ) : ?>
			<p class="news-card__excerpt line-clamp-3"><?php echo esc_html( $excerpt ); ?></p>
		<?php endif; ?>
	</div>
</a>
