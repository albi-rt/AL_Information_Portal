<?php
/**
 * Agency card. Mirrors the Next.js AgencyGrid layout: logo on top (uncontained),
 * then name, then description, then optional document badges.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agency = (array) get_query_var( 'nsw_theme_card_agency' );
$locale = (string) get_query_var( 'nsw_theme_card_locale' );
if ( '' === $locale ) {
	$locale = nsw_theme_current_locale();
}
$show_documents = (bool) get_query_var( 'nsw_theme_card_show_documents' );
if ( empty( $agency ) ) {
	return;
}
$name      = nsw_theme_localized( $agency['name'] ?? '', $locale );
$desc      = nsw_theme_localized( $agency['description'] ?? '', $locale );
$image     = isset( $agency['image'] ) ? nsw_theme_asset_url( (string) $agency['image'] ) : '';
$website   = $agency['website'] ?? '';
$documents = (array) ( $agency['documents'] ?? array() );
$tag       = $website ? 'a' : 'div';
?>
<<?php echo $tag; ?> class="agency-card"<?php if ( 'a' === $tag ) : ?> href="<?php echo esc_url( (string) $website ); ?>" target="_blank" rel="noopener"<?php endif; ?>>
	<?php if ( $image ) : ?>
		<div class="agency-card__logo">
			<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( (string) $name ); ?>" />
		</div>
	<?php endif; ?>
	<h3 class="agency-card__name"><?php echo esc_html( (string) $name ); ?></h3>
	<p class="agency-card__description"><?php echo esc_html( (string) $desc ); ?></p>
	<?php if ( $show_documents && is_array( $documents ) && ! empty( $documents ) ) : ?>
		<div class="agency-card__docs">
			<?php foreach ( $documents as $doc ) : ?>
				<span class="badge"><?php echo esc_html( (string) $doc ); ?></span>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</<?php echo $tag; ?>>
