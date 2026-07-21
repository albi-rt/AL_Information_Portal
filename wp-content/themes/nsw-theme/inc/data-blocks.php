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
 * Localized compact page hero (title + subtitle from the string table), matching
 * what the old page-templates rendered. The data pages' own titles aren't
 * localized, so the hero text comes from the Polylang strings — not page-hero.
 */
function nsw_theme_data_hero( string $title_key, string $title_default, string $sub_key, string $sub_default ): string {
	ob_start();
	nsw_theme_render_hero(
		array(
			'variant'  => 'compact',
			'title'    => nsw_theme_t( $title_key, $title_default ),
			'subtitle' => nsw_theme_t( $sub_key, $sub_default ),
		)
	);
	return (string) ob_get_clean();
}

/* ---- Render callbacks (localized hero + the section body) ---- */

function nsw_theme_block_agencies(): string {
	$hero = nsw_theme_data_hero( 'agenciesPage.title', 'Participating Agencies', 'agenciesPage.subtitle', '14 Cross-Border Regulatory Agencies (CBRA) connected to the NSW' );
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

function nsw_theme_block_partners_page(): string {
	return nsw_theme_data_hero( 'partnersPage.title', 'Partners & Stakeholders', 'partnersPage.subtitle', 'International and domestic collaboration for the success of NSW' )
		. nsw_theme_capture_part( 'template-parts/sections/partners-page' );
}

function nsw_theme_block_faq(): string {
	return nsw_theme_data_hero( 'faqPage.title', 'Frequently Asked Questions', 'faqPage.subtitle', 'Find answers to your questions about NSW' )
		. nsw_theme_capture_part( 'template-parts/sections/faq' );
}

function nsw_theme_block_documents(): string {
	return nsw_theme_data_hero( 'documentsPage.title', 'Documents & Guides', 'documentsPage.subtitle', 'Resources and materials for NSW users' )
		. nsw_theme_capture_part( 'template-parts/sections/documents' );
}

function nsw_theme_block_events(): string {
	return nsw_theme_data_hero( 'eventsPage.title', 'Events', 'eventsPage.subtitle', 'NSW activities and events' )
		. nsw_theme_capture_part( 'template-parts/sections/events' );
}

function nsw_theme_block_contact_form(): string {
	return nsw_theme_data_hero( 'contactPage.title', 'Contact Us', 'contactPage.subtitle', "We're here to help with any NSW question" )
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
			'nsw-theme/contact-form'  => array( 'nsw_theme_block_contact_form', 'NSW Contact Form' ),
		);
		foreach ( $blocks as $name => $def ) {
			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'title'           => $def[1],
					'category'        => 'nsw-theme',
					'render_callback' => $def[0],
					'supports'        => array( 'html' => false, 'reusable' => false ),
				)
			);
		}
	}
);
