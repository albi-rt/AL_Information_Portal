<?php
/**
 * Agencies grid section. Used on homepage (compact, limit 8) and the Agencies page (full).
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agencies = (array) get_query_var( 'nsw_theme_agencies_list' );
if ( empty( $agencies ) ) {
	$agencies = nsw_theme_get_agencies();
}
$limit          = (int) get_query_var( 'nsw_theme_agencies_limit' );
$show_documents = (bool) get_query_var( 'nsw_theme_agencies_show_documents' );
$locale         = nsw_theme_current_locale();

if ( $limit > 0 ) {
	$agencies = array_slice( $agencies, 0, $limit );
}
?>
<div class="grid grid--lg-4">
	<?php
	$i = 0;
	foreach ( $agencies as $agency ) :
		$i++;
		set_query_var( 'nsw_theme_card_agency', $agency );
		set_query_var( 'nsw_theme_card_locale', $locale );
		set_query_var( 'nsw_theme_card_show_documents', $show_documents );
		?>
		<div data-reveal data-reveal-delay="<?php echo (int) min( $i, 3 ); ?>">
			<?php get_template_part( 'template-parts/cards/agency', 'card' ); ?>
		</div>
	<?php endforeach; ?>
</div>
