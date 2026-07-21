<?php
/**
 * Taxonomies and seed terms for the News / Event / Document CPTs.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		// News uses the core `category` taxonomy (native Posts) — nothing to
		// register here. Default news categories are seeded below.

		register_taxonomy(
			'nsw_event_type',
			'nsw_event',
			array(
				'labels'            => array(
					'name'          => __( 'Event Types', 'nsw-theme' ),
					'singular_name' => __( 'Event Type',  'nsw-theme' ),
					'menu_name'     => __( 'Types',       'nsw-theme' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'event-type', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'nsw_document_category',
			'nsw_document',
			array(
				'labels'            => array(
					'name'          => __( 'Document Categories', 'nsw-theme' ),
					'singular_name' => __( 'Document Category',   'nsw-theme' ),
					'menu_name'     => __( 'Categories',          'nsw-theme' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'document-category', 'with_front' => false ),
			)
		);

		$defaults = array(
			'announcements' => __( 'Announcements', 'nsw-theme' ),
			'updates'       => __( 'Updates',       'nsw-theme' ),
			'events'        => __( 'Events',        'nsw-theme' ),
			'partners'      => __( 'Partners',      'nsw-theme' ),
		);
		foreach ( $defaults as $slug => $name ) {
			if ( ! term_exists( $slug, 'category' ) ) {
				wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
			}
		}
	}
);

/**
 * Seed the default document categories on theme activation (matches the existing site).
 */
add_action(
	'after_switch_theme',
	function () {
		$defaults = array(
			'legal'     => __( 'Legal',     'nsw-theme' ),
			'guides'    => __( 'Guides',    'nsw-theme' ),
			'technical' => __( 'Technical', 'nsw-theme' ),
			'templates' => __( 'Templates', 'nsw-theme' ),
		);
		foreach ( $defaults as $slug => $name ) {
			if ( ! term_exists( $slug, 'nsw_document_category' ) ) {
				wp_insert_term( $name, 'nsw_document_category', array( 'slug' => $slug ) );
			}
		}
	}
);
