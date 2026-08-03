<?php
/**
 * Services wizard page body — rendered by the nsw-theme/services dynamic block.
 *
 * A deterministic "wizard" (no chatbot): an operation-type pill row (All +
 * import/export/transit) AND an agency select, filtering a grid of published
 * Trade Services. Each card carries its facets as data-attributes; the actual
 * show/hide runs client-side in initServicesWizard() (assets/js/main.js),
 * mirroring the FAQ filter. Polylang auto-scopes the query to the active
 * language, so a page only lists its own language's services.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$services_query = new WP_Query(
	array(
		'post_type'      => NSW_THEME_CPT_SERVICE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	)
);

$agency_names = nsw_agency_choices();

// Build the card list and, along the way, collect only the agencies that
// actually have at least one published service — the select lists just those
// (nicer UX than every agency, most of which would filter to nothing).
$cards                  = array();
$agencies_with_services = array();
foreach ( $services_query->posts as $service ) {
	$types_csv = (string) get_post_meta( $service->ID, '_nsw_theme_service_type', true );
	$types     = array_values( array_filter( array_map( 'trim', explode( ',', $types_csv ) ) ) );
	$agency_id = (string) get_post_meta( $service->ID, '_nsw_service_agency', true );
	if ( '' !== $agency_id && isset( $agency_names[ $agency_id ] ) ) {
		$agencies_with_services[ $agency_id ] = $agency_names[ $agency_id ];
	}
	$cards[] = array(
		'post'      => $service,
		'types'     => $types,
		'agency_id' => $agency_id,
	);
}
asort( $agencies_with_services );

$type_labels = array(
	'import'  => nsw_theme_t( 'servicesPage.types.import', 'Import' ),
	'export'  => nsw_theme_t( 'servicesPage.types.export', 'Export' ),
	'transit' => nsw_theme_t( 'servicesPage.types.transit', 'Transit' ),
);

// Leading "All agencies" (empty value = no agency filter), then each agency.
$agency_options = array( '' => nsw_theme_t( 'servicesPage.allAgencies', 'All agencies' ) );
foreach ( $agencies_with_services as $id => $name ) {
	$agency_options[ $id ] = $name;
}

// Genuinely empty catalog (e.g. prod day one): no wizard controls — there is
// nothing to filter — just a server-rendered, always-visible message. The
// data-services-wizard container is deliberately absent so initServicesWizard()
// skips the page entirely. Distinct from servicesPage.noResults, which is the
// JS-filter "your selection matched nothing" state below.
if ( empty( $cards ) ) :
	?>
	<section class="section">
		<div class="container services-wizard">
			<div class="faq-empty" role="status">
				<?php echo esc_html( nsw_theme_t( 'servicesPage.noServices', 'Service guides will be published here soon.' ) ); ?>
			</div>
		</div>
	</section>
	<?php
	wp_reset_postdata();
	return;
endif;
?>
<section class="section">
	<div class="container services-wizard" data-services-wizard>

		<div class="services-wizard__controls" data-reveal>
			<div class="services-wizard__control">
				<span class="services-wizard__label" id="service-type-label"><?php echo esc_html( nsw_theme_t( 'servicesPage.filterByType', 'Filter by operation' ) ); ?></span>
				<div class="pill-row" role="group" aria-labelledby="service-type-label">
					<button type="button" class="pill is-active" data-service-pill="all" aria-pressed="true"><?php echo esc_html( nsw_theme_t( 'servicesPage.allTypes', 'All operations' ) ); ?></button>
					<?php foreach ( $type_labels as $slug => $label ) : ?>
						<button type="button" class="pill" data-service-pill="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="services-wizard__control services-wizard__control--agency">
				<label class="services-wizard__label" for="service-agency"><?php echo esc_html( nsw_theme_t( 'servicesPage.filterByAgency', 'Filter by agency' ) ); ?></label>
				<?php
				nsw_theme_render_select(
					array(
						'id'          => 'service-agency',
						'name'        => 'service_agency',
						'options'     => $agency_options,
						'placeholder' => nsw_theme_t( 'servicesPage.allAgencies', 'All agencies' ),
					)
				);
				?>
			</div>
		</div>

		<div data-services-empty class="faq-empty" hidden role="status">
			<?php echo esc_html( nsw_theme_t( 'servicesPage.noResults', 'No services match your selection.' ) ); ?>
		</div>

		<div class="grid grid--lg-3 services-wizard__grid" data-services-grid>
			<?php foreach ( $cards as $card ) :
				$service   = $card['post'];
				$types     = $card['types'];
				$agency_id = $card['agency_id'];
				$agency    = ( '' !== $agency_id ) ? ( $agency_names[ $agency_id ] ?? '' ) : '';
				$excerpt   = get_the_excerpt( $service );
				?>
				<a
					class="card service-card"
					href="<?php echo esc_url( (string) get_permalink( $service ) ); ?>"
					data-service-item
					data-service-types="<?php echo esc_attr( implode( ' ', $types ) ); ?>"
					data-service-agency="<?php echo esc_attr( $agency_id ); ?>"
				>
					<?php if ( ! empty( $types ) ) : ?>
						<div class="service-card__types">
							<?php foreach ( $types as $t ) : ?>
								<span class="badge badge--outline service-card__type"><?php echo esc_html( $type_labels[ $t ] ?? ucfirst( $t ) ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<h3 class="service-card__title"><?php echo esc_html( get_the_title( $service ) ); ?></h3>

					<?php if ( '' !== $excerpt ) : ?>
						<p class="service-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
					<?php endif; ?>

					<?php if ( '' !== $agency ) : ?>
						<span class="service-card__agency">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="21" x2="21" y2="21"/><line x1="5" y1="21" x2="5" y2="10"/><line x1="19" y1="21" x2="19" y2="10"/><polygon points="12 3 20 8 4 8"/></svg>
							<?php echo esc_html( $agency ); ?>
						</span>
					<?php endif; ?>

					<span class="service-card__cta">
						<?php echo esc_html( nsw_theme_t( 'servicesPage.readGuide', 'Read the guide' ) ); ?>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
					</span>
				</a>
			<?php endforeach; ?>
		</div>

	</div>
</section>
<?php
wp_reset_postdata();
