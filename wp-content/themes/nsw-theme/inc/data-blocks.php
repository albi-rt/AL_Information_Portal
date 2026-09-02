<?php
/**
 * Data-page blocks.
 *
 * Dynamic (server-rendered) blocks for the data-driven pages — Agencies,
 * Partners, FAQ, Documents, Events, Contact. Each reuses the existing data
 * helpers + section markup (template-parts/sections/*), so the data layer is
 * unchanged; the page just renders via page.html (header + page-hero +
 * post-content + footer) with the block placed in the page content.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact page hero for the data pages. Title + subtitle come from the queried
 * page itself — post title (H1) and post excerpt (subtitle) — so they are edited
 * in the page editor and localized per Polylang page. This is the single, shared
 * source for every page's H1 (the page-hero block resolves titles the same way).
 */
function nsw_theme_data_hero(): string {
	$qo       = get_queried_object();
	$title    = ( $qo instanceof WP_Post ) ? get_the_title( $qo ) : '';
	$subtitle = ( $qo instanceof WP_Post ) ? (string) $qo->post_excerpt : '';
	ob_start();
	nsw_theme_render_hero(
		array(
			'variant'  => 'compact',
			'title'    => $title,
			'subtitle' => $subtitle,
		)
	);
	return (string) ob_get_clean();
}

/* ---- Render callbacks (localized hero + the section body) ---- */

function nsw_theme_block_agencies(): string {
	$hero = nsw_theme_data_hero();
	ob_start();
	?>
	<section class="section">
		<div class="container">
			<?php
			set_query_var( 'nsw_theme_agencies_list', array() );
			set_query_var( 'nsw_theme_agencies_limit', 0 );
			set_query_var( 'nsw_theme_agencies_show_documents', true );
			get_template_part( 'template-parts/sections/agencies-grid' );
			?>
		</div>
	</section>
	<?php
	return $hero . (string) ob_get_clean();
}

function nsw_theme_block_partners_page( $attributes = array() ): string {
	// The "Private Sector" copy is editable via block fields (see
	// nsw_theme_block_fields()); pass it to the section, falling back to the
	// localized string while a page hasn't set explicit values.
	set_query_var( 'nsw_pp_private_title',    nsw_theme_field( $attributes, 'privateTitle', nsw_theme_t( 'partnersPage.privateSectorTitle', 'Private Sector' ) ) );
	set_query_var( 'nsw_pp_private_desc',     nsw_theme_field( $attributes, 'privateDesc', nsw_theme_t( 'partnersPage.privateSectorDesc', '' ) ) );
	set_query_var( 'nsw_pp_private_actors',   nsw_theme_field( $attributes, 'privateActors', implode( "\n", (array) nsw_theme_dot_get( nsw_theme_get_content(), 'partnersPage.privateSectorActors', array() ) ) ) );
	set_query_var( 'nsw_pp_private_benefits', nsw_theme_field( $attributes, 'privateBenefits', nsw_theme_t( 'partnersPage.privateSectorBenefits', '' ) ) );
	return nsw_theme_data_hero()
		. nsw_theme_capture_part( 'template-parts/sections/partners-page' );
}

function nsw_theme_block_faq(): string {
	return nsw_theme_data_hero()
		. nsw_theme_capture_part( 'template-parts/sections/faq' );
}

function nsw_theme_block_documents(): string {
	return nsw_theme_data_hero()
		. nsw_theme_capture_part( 'template-parts/sections/documents' );
}

function nsw_theme_block_events(): string {
	return nsw_theme_data_hero()
		. nsw_theme_capture_part( 'template-parts/sections/events' );
}

function nsw_theme_block_services(): string {
	return nsw_theme_data_hero()
		. nsw_theme_capture_part( 'template-parts/sections/services' );
}

function nsw_theme_block_contact_form(): string {
	return nsw_theme_data_hero()
		. nsw_theme_capture_part( 'template-parts/sections/contact-form' );
}

/* ---- Registration ---- */

add_action(
	'init',
	function () {
		$blocks = array(
			'nsw-theme/agencies'      => array( 'nsw_theme_block_agencies', 'NSW Agencies (full list)' ),
			'nsw-theme/partners-page' => array( 'nsw_theme_block_partners_page', 'NSW Partners (full page)' ),
			'nsw-theme/faq'           => array( 'nsw_theme_block_faq', 'NSW FAQ' ),
			'nsw-theme/documents'     => array( 'nsw_theme_block_documents', 'NSW Documents' ),
			'nsw-theme/events'        => array( 'nsw_theme_block_events', 'NSW Events' ),
			'nsw-theme/services'      => array( 'nsw_theme_block_services', 'NSW Trade Services' ),
			'nsw-theme/contact-form'  => array( 'nsw_theme_block_contact_form', 'NSW Contact Form' ),
		);
		foreach ( $blocks as $name => $def ) {
			$args = array(
				'api_version'     => 3,
				'title'           => $def[1],
				'category'        => 'nsw-theme',
				'render_callback' => $def[0],
				'supports'        => array( 'html' => false, 'reusable' => false ),
			);
			// partners-page carries editable "Private Sector" fields.
			if ( 'nsw-theme/partners-page' === $name ) {
				$args['attributes'] = nsw_theme_block_attributes( $name );
			}
			register_block_type( $name, $args );
		}
	}
);
