<?php
/**
 * Event card. Mirrors EventCard.tsx: date badge on the left (month uppercase + day),
 * card body on the right with badge, title, description, time + location row.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$event   = (array) get_query_var( 'nsw_theme_card_event' );
$locale  = (string) get_query_var( 'nsw_theme_card_locale' );
$is_past = (bool) get_query_var( 'nsw_theme_card_is_past' );
if ( '' === $locale ) {
	$locale = nsw_theme_current_locale();
}
if ( empty( $event ) ) {
	return;
}

$title       = nsw_theme_localized( $event['title'] ?? '', $locale );
$location    = nsw_theme_localized( $event['location'] ?? '', $locale );
$description = nsw_theme_localized( $event['description'] ?? '', $locale );
$type        = (string) ( $event['type'] ?? '' );
$date_raw    = (string) ( $event['date'] ?? '' );
$time        = (string) ( $event['time'] ?? '' );
$ts          = $date_raw ? strtotime( $date_raw ) : 0;
$month       = $ts ? strtoupper( date_i18n( 'M', $ts ) ) : '';
$day         = $ts ? date_i18n( 'j', $ts ) : '';
$image       = (string) ( $event['image'] ?? '' );
if ( $image && ! preg_match( '#^https?://#', $image ) ) {
	$image = nsw_theme_asset_url( $image );
}

$type_labels = array(
	'workshop'   => nsw_theme_t( 'eventsPage.workshop', 'Workshop' ),
	'conference' => nsw_theme_t( 'eventsPage.conference', 'Conference' ),
	'training'   => nsw_theme_t( 'eventsPage.training', 'Training' ),
);
$type_label = $type_labels[ $type ] ?? ucfirst( $type );
?>
<article class="event-card<?php echo $is_past ? ' event-card--past' : ''; ?><?php echo $image ? ' event-card--has-image' : ''; ?>">
	<?php if ( $image ) : ?>
		<div class="event-card__media">
			<img class="event-card__img" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async" />
		</div>
	<?php endif; ?>
	<div class="event-card__date">
		<span class="event-card__month"><?php echo esc_html( $month ); ?></span>
		<span class="event-card__day"><?php echo esc_html( $day ); ?></span>
	</div>
	<div class="event-card__body">
		<?php if ( $type ) : ?>
			<span class="badge<?php echo $is_past ? '' : ' badge--primary'; ?> event-card__type"><?php echo esc_html( $type_label ); ?></span>
		<?php endif; ?>
		<h3 class="event-card__title"><?php echo esc_html( (string) $title ); ?></h3>
		<?php if ( $description ) : ?>
			<p class="event-card__description line-clamp-2"><?php echo esc_html( (string) $description ); ?></p>
		<?php endif; ?>
		<div class="event-card__meta">
			<?php if ( $time ) : ?>
				<span>
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
					<?php echo esc_html( $time ); ?>
				</span>
			<?php endif; ?>
			<?php if ( $location ) : ?>
				<span>
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1118 0z"/><circle cx="12" cy="10" r="3"/></svg>
					<?php echo esc_html( (string) $location ); ?>
				</span>
			<?php endif; ?>
		</div>
	</div>
</article>
