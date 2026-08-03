<?php
/**
 * Single Trade Service guide (public CPT). Mirrors the single-news layout:
 * a gradient hero with a back-link, operation chips and the responsible-agency
 * badge, then the authored guide body (the_content — steps / documents / fees)
 * with a related-services sidebar. Rendered by the nsw-theme/single-service
 * block, which sets up postdata for the queried service.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();

$types_csv   = (string) get_post_meta( $post_id, '_nsw_theme_service_type', true );
$types       = array_values( array_filter( array_map( 'trim', explode( ',', $types_csv ) ) ) );
$type_labels = array(
	'import'  => nsw_theme_t( 'servicesPage.types.import', 'Import' ),
	'export'  => nsw_theme_t( 'servicesPage.types.export', 'Export' ),
	'transit' => nsw_theme_t( 'servicesPage.types.transit', 'Transit' ),
);

$agency_id    = (string) get_post_meta( $post_id, '_nsw_service_agency', true );
$agency_names = nsw_agency_choices();
$agency_name  = ( '' !== $agency_id ) ? ( $agency_names[ $agency_id ] ?? '' ) : '';

// Related services: same responsible agency (Polylang scopes to the active
// language on its own). Only queried when this service has an agency.
$related = null;
if ( '' !== $agency_id ) {
	$related = new WP_Query(
		array(
			'post_type'      => NSW_THEME_CPT_SERVICE,
			'post_status'    => 'publish',
			'posts_per_page' => 3,
			'post__not_in'   => array( $post_id ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => '_nsw_service_agency',
					'value' => $agency_id,
				),
			),
		)
	);
}
?>
<article <?php post_class( 'article' ); ?>>

	<header class="article__hero">
		<div class="container article__hero-inner">
			<a class="article__back" href="<?php echo esc_url( nsw_theme_path_url( 'services' ) ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
				<?php echo esc_html( nsw_theme_t( 'servicesPage.backToServices', 'Back to Services' ) ); ?>
			</a>

			<?php if ( ! empty( $types ) ) : ?>
				<div class="article__hero-chips">
					<?php foreach ( $types as $t ) : ?>
						<span class="badge"><?php echo esc_html( $type_labels[ $t ] ?? ucfirst( $t ) ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h1 class="article__title"><?php the_title(); ?></h1>

			<?php if ( '' !== $agency_name ) : ?>
				<ul class="article__meta">
					<li>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="21" x2="21" y2="21"/><line x1="5" y1="21" x2="5" y2="10"/><line x1="19" y1="21" x2="19" y2="10"/><polygon points="12 3 20 8 4 8"/></svg>
						<span><?php echo esc_html( nsw_theme_t( 'servicesPage.responsibleAgency', 'Responsible agency' ) ); ?>: <strong><?php echo esc_html( $agency_name ); ?></strong></span>
					</li>
				</ul>
			<?php endif; ?>
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
				</div>

				<?php if ( $related && $related->have_posts() ) : ?>
					<aside class="article__sidebar" aria-label="<?php echo esc_attr( nsw_theme_t( 'servicesPage.relatedTitle', 'Related services' ) ); ?>">
						<h2 class="article__sidebar-title"><?php echo esc_html( nsw_theme_t( 'servicesPage.relatedTitle', 'Related services' ) ); ?></h2>
						<div class="article__related">
							<?php while ( $related->have_posts() ) :
								$related->the_post();
								$rel_types_csv = (string) get_post_meta( get_the_ID(), '_nsw_theme_service_type', true );
								$rel_types     = array_values( array_filter( array_map( 'trim', explode( ',', $rel_types_csv ) ) ) );
								?>
								<a class="article__related-card" href="<?php the_permalink(); ?>">
									<?php if ( ! empty( $rel_types ) ) : ?>
										<span class="badge article__related-badge"><?php echo esc_html( $type_labels[ $rel_types[0] ] ?? ucfirst( $rel_types[0] ) ); ?></span>
									<?php endif; ?>
									<h3 class="article__related-title"><?php the_title(); ?></h3>
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
