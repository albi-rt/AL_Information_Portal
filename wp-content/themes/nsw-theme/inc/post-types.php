<?php
/**
 * Custom post types: nsw_event, nsw_document, nsw_agency, nsw_partner, nsw_faq,
 * nsw_service. News is core Posts.
 *
 * All CPTs are managed in native wp-admin. News and Services drive their own
 * public single URLs; Events, Documents and Agencies have no public permalink of
 * their own and are rendered by page templates that query them.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NSW_THEME_CPT_NEWS     = 'post'; // News is native WordPress Posts.
const NSW_THEME_CPT_EVENT    = 'nsw_event';
const NSW_THEME_CPT_DOCUMENT = 'nsw_document';
const NSW_THEME_CPT_AGENCY   = 'nsw_agency';
const NSW_THEME_CPT_PARTNER  = 'nsw_partner';
const NSW_THEME_CPT_FAQ      = 'nsw_faq';
const NSW_THEME_CPT_SERVICE  = 'nsw_service';

add_action(
	'init',
	function () {
		/* News is native WordPress Posts — no custom post type. The news listing
		   is the posts page (Settings → Reading → Posts page = "News"), rendered
		   by home.php; single articles render via single.php. Editors manage them
		   under the standard Posts menu with core Categories, so the full plugin
		   ecosystem (SEO, related posts, editorial calendars) applies. */

		/* Events + Documents: archive + single URLs are intentionally disabled
		   here because the public Albanian site renders /events/ and
		   /documents/ via page-template files that query these CPTs. Keeping
		   `has_archive`/`rewrite` would steal those URLs from the existing
		   pages. They are edited in wp-admin and queried via WP_Query — they
		   just don't expose public permalinks of their own. */

		register_post_type(
			NSW_THEME_CPT_EVENT,
			array(
				'labels'             => array(
					'name'          => __( 'Events',           'nsw-theme' ),
					'singular_name' => __( 'Event',            'nsw-theme' ),
					'menu_name'     => __( 'Events',           'nsw-theme' ),
					'add_new_item'  => __( 'Add New Event',    'nsw-theme' ),
					'edit_item'     => __( 'Edit Event',       'nsw-theme' ),
					'view_item'     => __( 'View Event',       'nsw-theme' ),
					'all_items'     => __( 'All Events',       'nsw-theme' ),
					'search_items'  => __( 'Search Events',    'nsw-theme' ),
					'not_found'     => __( 'No events found.', 'nsw-theme' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_admin_bar'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-calendar-alt',
				'menu_position'      => 6,
				// No 'editor': events have no body content (description = excerpt).
				// 'custom-fields' exposes the registered meta to the REST API.
				'supports'           => array( 'title', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
				'capability_type'    => array( 'nsw_event', 'nsw_event_posts' ),
				'map_meta_cap'       => true,
				'taxonomies'         => array( 'nsw_event_type' ),
			)
		);

		register_post_type(
			NSW_THEME_CPT_DOCUMENT,
			array(
				'labels'             => array(
					'name'          => __( 'Documents',           'nsw-theme' ),
					'singular_name' => __( 'Document',            'nsw-theme' ),
					'menu_name'     => __( 'Documents',           'nsw-theme' ),
					'add_new_item'  => __( 'Add New Document',    'nsw-theme' ),
					'edit_item'     => __( 'Edit Document',       'nsw-theme' ),
					'view_item'     => __( 'View Document',       'nsw-theme' ),
					'all_items'     => __( 'All Documents',       'nsw-theme' ),
					'search_items'  => __( 'Search Documents',    'nsw-theme' ),
					'not_found'     => __( 'No documents found.', 'nsw-theme' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_admin_bar'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-media-document',
				'menu_position'      => 7,
				// No 'editor': documents have no body content (description = excerpt).
				// 'custom-fields' exposes the registered meta to the REST API.
				'supports'           => array( 'title', 'excerpt', 'author', 'revisions', 'custom-fields' ),
				'capability_type'    => array( 'nsw_document', 'nsw_document_posts' ),
				'map_meta_cap'       => true,
				'taxonomies'         => array( 'nsw_document_category' ),
			)
		);

		/* Agencies — managed in native wp-admin (Agencies menu). They have no
		   public permalink of their own; the /agjensite/ page template queries
		   them and renders the cards. Editable fields live in the "Agency
		   details" meta box (see inc/meta-boxes.php); the logo is the featured
		   image. */
		register_post_type(
			NSW_THEME_CPT_AGENCY,
			array(
				'labels'             => array(
					'name'          => __( 'Agencies',           'nsw-theme' ),
					'singular_name' => __( 'Agency',             'nsw-theme' ),
					'menu_name'     => __( 'Agencies',           'nsw-theme' ),
					'add_new_item'  => __( 'Add New Agency',     'nsw-theme' ),
					'edit_item'     => __( 'Edit Agency',        'nsw-theme' ),
					'view_item'     => __( 'View Agency',        'nsw-theme' ),
					'all_items'     => __( 'All Agencies',       'nsw-theme' ),
					'search_items'  => __( 'Search Agencies',    'nsw-theme' ),
					'not_found'     => __( 'No agencies found.', 'nsw-theme' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_admin_bar'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-bank',
				'menu_position'      => 8,
				// 'custom-fields' exposes the registered meta to the REST API.
				'supports'           => array( 'title', 'thumbnail', 'author', 'custom-fields' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		/* Partners — bilingual, managed in wp-admin. Rendered by the Partners
		   page template and the homepage partners strip; no public permalink.
		   Logo = featured image. */
		register_post_type(
			NSW_THEME_CPT_PARTNER,
			array(
				'labels'             => array(
					'name'          => __( 'Partners',           'nsw-theme' ),
					'singular_name' => __( 'Partner',            'nsw-theme' ),
					'menu_name'     => __( 'Partners',           'nsw-theme' ),
					'add_new_item'  => __( 'Add New Partner',    'nsw-theme' ),
					'edit_item'     => __( 'Edit Partner',       'nsw-theme' ),
					'all_items'     => __( 'All Partners',       'nsw-theme' ),
					'not_found'     => __( 'No partners found.', 'nsw-theme' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_admin_bar'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-groups',
				'menu_position'      => 9,
				// 'custom-fields' exposes the registered meta to the REST API.
				'supports'           => array( 'title', 'thumbnail', 'author', 'custom-fields' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		/* FAQ — one post per locale (question = title, answer = content),
		   grouped by nsw_faq_category on the public FAQ page. */
		register_post_type(
			NSW_THEME_CPT_FAQ,
			array(
				'labels'             => array(
					'name'          => __( 'FAQ',                 'nsw-theme' ),
					'singular_name' => __( 'FAQ item',           'nsw-theme' ),
					'menu_name'     => __( 'FAQ',                 'nsw-theme' ),
					'add_new_item'  => __( 'Add New FAQ item',   'nsw-theme' ),
					'edit_item'     => __( 'Edit FAQ item',      'nsw-theme' ),
					'all_items'     => __( 'All FAQ items',      'nsw-theme' ),
					'not_found'     => __( 'No FAQ items found.', 'nsw-theme' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_admin_bar'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-editor-help',
				'menu_position'      => 10,
				'supports'           => array( 'title', 'editor', 'author' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'taxonomies'         => array( 'nsw_faq_category' ),
			)
		);

		/* Services — the ONLY public CPT. Each service is a real page with its
		   own permalink under /sherbime/, authored as blocks (steps / documents
		   / fees) in post_content; that body content is what PublishPress
		   Revisions tracks for approval. The Services list page (wizard) lives
		   at the shared /sherbime/ base and queries these. Dedicated
		   capability_type so agency officers get a scoped, approval-gated cap
		   set (granted per environment in Members). */
		register_post_type(
			NSW_THEME_CPT_SERVICE,
			array(
				'labels'             => array(
					'name'          => __( 'Services',           'nsw-theme' ),
					'singular_name' => __( 'Service',            'nsw-theme' ),
					'menu_name'     => __( 'Services',           'nsw-theme' ),
					'add_new_item'  => __( 'Add New Service',    'nsw-theme' ),
					'edit_item'     => __( 'Edit Service',       'nsw-theme' ),
					'view_item'     => __( 'View Service',       'nsw-theme' ),
					'all_items'     => __( 'All Services',       'nsw-theme' ),
					'search_items'  => __( 'Search Services',    'nsw-theme' ),
					'not_found'     => __( 'No services found.', 'nsw-theme' ),
				),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_admin_bar'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => array( 'slug' => 'sherbime', 'with_front' => false ),
				'menu_icon'          => 'dashicons-clipboard',
				'menu_position'      => 11,
				// Keeps 'editor': the guide body (steps/docs/fees) is authored as
				// blocks in post_content — that's what Revisions approval tracks.
				// 'custom-fields' exposes the registered meta to the REST API.
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
				'capability_type'    => array( 'nsw_service', 'nsw_services' ),
				'map_meta_cap'       => true,
			)
		);
	}
);

/**
 * FAQ category taxonomy — a small fixed set (general, registration, lpco,
 * payments, technical, agencies). Non-public; used to group the FAQ page.
 */
add_action(
	'init',
	function () {
		register_taxonomy(
			'nsw_faq_category',
			NSW_THEME_CPT_FAQ,
			array(
				'labels'            => array(
					'name'          => __( 'FAQ categories', 'nsw-theme' ),
					'singular_name' => __( 'FAQ category',   'nsw-theme' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);
	}
);

/**
 * Sanitize the service operation-type meta: a comma-separated list restricted to
 * the whitelist (import, export, transit). Splits on commas, keeps only allowed
 * values, dedupes, and rejoins. An empty string is valid (no operations chosen).
 */
function nsw_theme_sanitize_service_type( $value ): string {
	$allowed  = array( 'import', 'export', 'transit' );
	$parts    = array_map( 'trim', explode( ',', (string) $value ) );
	$filtered = array_values( array_unique( array_intersect( $parts, $allowed ) ) );
	return implode( ',', $filtered );
}

/**
 * Register custom meta for REST exposure (so the block editor and theme can read it cleanly).
 */
add_action(
	'init',
	function () {

		$register = function ( string $post_type, string $key, string $type = 'string' ) {
			register_post_meta(
				$post_type,
				$key,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => $type,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
					'sanitize_callback' => 'string' === $type ? 'sanitize_text_field' : null,
				)
			);
		};

		// Event meta.
		$register( NSW_THEME_CPT_EVENT, '_nsw_theme_event_date',     'string' );
		$register( NSW_THEME_CPT_EVENT, '_nsw_theme_event_time',     'string' );
		$register( NSW_THEME_CPT_EVENT, '_nsw_theme_event_location', 'string' );
		$register( NSW_THEME_CPT_EVENT, '_nsw_theme_event_type',     'string' );

		// Document meta.
		$register( NSW_THEME_CPT_DOCUMENT, '_nsw_theme_doc_file_id',      'integer' );
		$register( NSW_THEME_CPT_DOCUMENT, '_nsw_theme_doc_external_url', 'string' );
		$register( NSW_THEME_CPT_DOCUMENT, '_nsw_theme_doc_file_type',    'string' );
		$register( NSW_THEME_CPT_DOCUMENT, '_nsw_theme_doc_size',         'string' );

		// Agency meta. Language now lives in Polylang (one post per language);
		// name / abbreviation / description / documents are single-language and
		// belong to the post's own language. id / colour / website are shared
		// across translations (see the pll_copy_post_metas filter below).
		$register( NSW_THEME_CPT_AGENCY, '_nsw_theme_agency_id',      'string' );
		$register( NSW_THEME_CPT_AGENCY, '_nsw_theme_agency_abbr',    'string' );
		$register( NSW_THEME_CPT_AGENCY, '_nsw_theme_agency_name',    'string' );
		$register( NSW_THEME_CPT_AGENCY, '_nsw_theme_agency_desc',    'string' );
		$register( NSW_THEME_CPT_AGENCY, '_nsw_theme_agency_docs',    'string' );
		$register( NSW_THEME_CPT_AGENCY, '_nsw_theme_agency_color',   'string' );
		$register( NSW_THEME_CPT_AGENCY, '_nsw_theme_agency_website', 'string' );

		// Partner meta. name / description are single-language; type / colour /
		// website / logo-bg are shared across translations.
		$register( NSW_THEME_CPT_PARTNER, '_nsw_theme_partner_id',      'string' );
		$register( NSW_THEME_CPT_PARTNER, '_nsw_theme_partner_type',    'string' );
		$register( NSW_THEME_CPT_PARTNER, '_nsw_theme_partner_name',    'string' );
		$register( NSW_THEME_CPT_PARTNER, '_nsw_theme_partner_desc',    'string' );
		$register( NSW_THEME_CPT_PARTNER, '_nsw_theme_partner_color',   'string' );
		$register( NSW_THEME_CPT_PARTNER, '_nsw_theme_partner_website', 'string' );
		$register( NSW_THEME_CPT_PARTNER, '_nsw_theme_partner_logo_bg', 'string' );

		// Service meta. Operation type is a comma-separated multi-value whitelist
		// (import/export/transit), so it is registered here directly rather than via
		// the $register helper — it needs the custom CSV sanitizer, not
		// sanitize_text_field. The agency link meta (_nsw_service_agency) is
		// registered alongside News in inc/agency-news.php.
		register_post_meta(
			NSW_THEME_CPT_SERVICE,
			'_nsw_theme_service_type',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => 'nsw_theme_sanitize_service_type',
			)
		);
	}
);

/**
 * Make Agencies and Partners translatable by Polylang (one post per language,
 * linked as translations). News/Events/Documents/FAQ are configured in
 * Polylang's own settings; these two are theme-forced so the theme's bilingual
 * data model stays self-contained and survives DB resets. Polylang shows them
 * as checked-and-locked under Languages → Settings.
 */
add_filter(
	'pll_get_post_types',
	function ( $types, $is_settings ) {
		$types[ NSW_THEME_CPT_AGENCY ]  = NSW_THEME_CPT_AGENCY;
		$types[ NSW_THEME_CPT_PARTNER ] = NSW_THEME_CPT_PARTNER;
		$types[ NSW_THEME_CPT_SERVICE ] = NSW_THEME_CPT_SERVICE;
		return $types;
	},
	10,
	2
);

/**
 * Meta that is identical across an agency's / partner's / service's translations.
 * Polylang copies these when a new translation is first created and keeps them in
 * sync on save, so brand colour / website / logo / type live in one place. For
 * services, the operation type and the responsible agency are the same fact in
 * both languages, so they are shared too. The per-language copy (name,
 * abbreviation, description, documents) is intentionally NOT listed here.
 */
add_filter(
	'pll_copy_post_metas',
	function ( $metas ) {
		return array_merge(
			(array) $metas,
			array(
				'_thumbnail_id', // logo (shared attachment)
				'_nsw_theme_agency_id',
				'_nsw_theme_agency_color',
				'_nsw_theme_agency_website',
				'_nsw_theme_partner_id',
				'_nsw_theme_partner_type',
				'_nsw_theme_partner_color',
				'_nsw_theme_partner_website',
				'_nsw_theme_partner_logo_bg',
				'_nsw_theme_service_type',
				'_nsw_service_agency',
			)
		);
	}
);

/**
 * Flush rewrite rules on theme activation so the CPT slugs work immediately.
 */
add_action(
	'after_switch_theme',
	function () {
		flush_rewrite_rules();
	}
);

/**
 * Deploy-safe, version-keyed rewrite flush.
 *
 * WHY: production deploys are `git pull`, which swap the theme files WITHOUT
 * firing `after_switch_theme` — so a newly added CPT rewrite (the public
 * /sherbime/<slug> service singles) would 404 until an admin manually re-saved
 * Settings → Permalinks. This flushes exactly once per theme version: the first
 * `init` after a deploy that bumped NSW_THEME_VERSION, at priority 99 so every
 * post type and rewrite is already registered. The recorded version keeps it
 * from re-flushing on subsequent requests.
 */
add_action(
	'init',
	function () {
		if ( get_option( 'nsw_theme_rewrite_version' ) === NSW_THEME_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'nsw_theme_rewrite_version', NSW_THEME_VERSION, true );
	},
	99
);
