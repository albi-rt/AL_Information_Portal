<?php
/**
 * Events page body — rendered by the nsw-theme/events dynamic block.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$locale = nsw_theme_current_locale();
$events = nsw_theme_get_events();

$upcoming = array_values( array_filter( $events, function ( $e ) { return empty( $e['isPast'] ); } ) );
$past     = array_values( array_filter( $events, function ( $e ) { return ! empty( $e['isPast'] ); } ) );

usort(
	$upcoming,
	function ( $a, $b ) {
		$ad = isset( $a['date'] ) ? strtotime( (string) $a['date'] ) : 0;
		$bd = isset( $b['date'] ) ? strtotime( (string) $b['date'] ) : 0;
		return $ad <=> $bd;
	}
);
usort(
	$past,
	function ( $a, $b ) {
		$ad = isset( $a['date'] ) ? strtotime( (string) $a['date'] ) : 0;
		$bd = isset( $b['date'] ) ? strtotime( (string) $b['date'] ) : 0;
		return $bd <=> $ad;
	}
);

$render_events = static function ( array $list, bool $is_past ) use ( $locale ) {
	echo '<div style="display:flex; flex-direction:column; gap:1rem">';
	$i = 0;
	foreach ( $list as $event ) {
		$i++;
		set_query_var( 'nsw_theme_card_event', $event );
		set_query_var( 'nsw_theme_card_locale', $locale );
		set_query_var( 'nsw_theme_card_is_past', $is_past );
		echo '<div data-reveal data-reveal-delay="' . (int) min( $i, 3 ) . '">';
		get_template_part( 'template-parts/cards/event', 'card' );
		echo '</div>';
	}
	echo '</div>';
};
?>
<?php if ( ! empty( $upcoming ) ) : ?>
	<section class="section">
		<div class="container">
			<h2 class="section-heading__title" style="text-align:left; margin-bottom:2rem" data-reveal><?php echo esc_html( nsw_theme_t( 'eventsPage.upcomingTitle', 'Upcoming Events' ) ); ?></h2>
			<?php $render_events( $upcoming, false ); ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( ! empty( $past ) ) : ?>
	<section class="section section--muted">
		<div class="container">
			<h2 class="section-heading__title" style="text-align:left; margin-bottom:2rem" data-reveal><?php echo esc_html( nsw_theme_t( 'eventsPage.pastTitle', 'Past Events' ) ); ?></h2>
			<?php $render_events( $past, true ); ?>
		</div>
	</section>
<?php endif; ?>
