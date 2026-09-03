<?php
/**
 * Headless content ingest: a single "upsert" REST endpoint that lets a trusted
 * integration push News / Pages / Documents / Events into the site by a stable
 * external id, without knowing WordPress post IDs.
 *
 * POST /wp-json/nsw-theme/v1/content
 *
 * The endpoint is identity-keyed on the `_nsw_api_external_id` post meta: the
 * caller owns an `external_id` string per (type, language) and the endpoint
 * decides create-vs-update from it. Everything else is a consequence of the
 * SAME capabilities the wp-admin UI enforces — this route grants no new powers:
 *
 *   - CREATE: publishes if the user may `publish_posts`, else saves `pending`
 *     for admin review. For News this runs the theme's agency stamping
 *     (save_post_post in inc/agency-news.php).
 *   - UPDATE of a draft/pending/future item: in place, if the user may edit it.
 *   - UPDATE of a PUBLISHED item: a full editor (`edit_post`) writes it live; a
 *     non-full editor who may `copy_post` (PublishPress Revisions' agency-scoped
 *     gate) submits a pending-revision into the approval queue instead. Repeat
 *     submissions by the same user fold into their existing pending revision.
 *
 * Translation grouping is handled with Polylang's proven programmatic path
 * (pll_set_post_language + pll_save_post_translations): pushing the same
 * external_id under a different language auto-links the sq/en pair.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** REST namespace/route and the identity meta key. */
const NSW_REST_NAMESPACE   = 'nsw-theme/v1';
const NSW_REST_ROUTE       = '/content';
const NSW_API_EXTERNAL_ID  = '_nsw_api_external_id';

/** Post types this endpoint accepts. */
function nsw_rest_supported_types(): array {
	return array( 'post', 'page', NSW_THEME_CPT_DOCUMENT, NSW_THEME_CPT_EVENT, NSW_THEME_CPT_SERVICE );
}

/* --------------------------------------------------------------------------
 * 1. Identity meta: `_nsw_api_external_id` on every supported type.
 *
 * Kept OUT of show_in_rest deliberately — it is the endpoint's private
 * matching key, not a field callers hand-edit through core wp/v2. auth is
 * gated at the same edit_posts level the theme uses elsewhere.
 * -------------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		foreach ( nsw_rest_supported_types() as $type ) {
			register_post_meta(
				$type,
				NSW_API_EXTERNAL_ID,
				array(
					'show_in_rest'      => false,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}
);

/* --------------------------------------------------------------------------
 * 2. Exclude Revisionary revision rows from our lookup queries.
 *
 * A pending revision shares its parent's post_type and sits in post_status
 * 'pending', so a naive WP_Query for (type + external_id + pending) would match
 * it and corrupt the upsert. Revision rows carry a '*-revision' post_mime_type;
 * we filter them out for any query flagged with 'nsw_exclude_revisions'.
 * -------------------------------------------------------------------------- */

add_filter(
	'posts_where',
	function ( $where, $query ) {
		if ( $query->get( 'nsw_exclude_revisions' ) ) {
			global $wpdb;
			$where .= " AND {$wpdb->posts}.post_mime_type NOT LIKE '%-revision'";
		}
		return $where;
	},
	10,
	2
);

/* --------------------------------------------------------------------------
 * 3. Route registration.
 * -------------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			NSW_REST_NAMESPACE,
			NSW_REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'nsw_rest_content_upsert',
				'permission_callback' => 'nsw_rest_content_permission',
				'args'                => array(
					'type'        => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => nsw_rest_supported_types(),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
					'external_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'lang'        => array(
						'type'              => 'string',
						'enum'              => nsw_rest_supported_langs(),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
					'title'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
					'excerpt'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
					'meta'        => array(
						'type' => 'object',
					),
				),
			)
		);
	}
);

/* --------------------------------------------------------------------------
 * 4. Permission: logged in AND able to edit posts of the requested type.
 *    Per-item authority (publish / edit_post / copy_post) is enforced later.
 * -------------------------------------------------------------------------- */

function nsw_rest_content_permission( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'nsw_rest_not_logged_in',
			__( 'You must be authenticated to use this endpoint.', 'nsw-theme' ),
			array( 'status' => 401 )
		);
	}
	$type = (string) $request['type'];
	if ( ! in_array( $type, nsw_rest_supported_types(), true ) ) {
		// Let argument validation surface the 400 for a bad type.
		return true;
	}
	$pto = get_post_type_object( $type );
	if ( ! $pto || ! current_user_can( $pto->cap->edit_posts ) ) {
		return new WP_Error(
			'nsw_rest_forbidden',
			__( 'You are not allowed to create or edit this content type.', 'nsw-theme' ),
			array( 'status' => 403 )
		);
	}
	return true;
}

/* --------------------------------------------------------------------------
 * 5. Helpers.
 * -------------------------------------------------------------------------- */

/**
 * The languages the endpoint accepts — every language configured in Polylang.
 * Evaluated at rest_api_init (when Polylang is loaded), so adding a language in
 * wp-admin widens the endpoint with no code change. Falls back to the historic
 * sq/en pair only when Polylang is inactive.
 *
 * @return string[]
 */
function nsw_rest_supported_langs(): array {
	$langs = function_exists( 'nsw_theme_locales' ) ? nsw_theme_locales() : array();
	return ! empty( $langs ) ? $langs : array( 'sq', 'en' );
}

/**
 * The effective language for a request: explicit `lang`, else the site default.
 */
function nsw_rest_resolve_lang( WP_REST_Request $request ): string {
	$lang = (string) ( $request['lang'] ?? '' );
	if ( '' !== $lang ) {
		return $lang;
	}
	return nsw_theme_default_locale();
}

/**
 * Find a non-revision post of $type in $lang whose external id matches.
 * Returns the post ID or 0.
 */
function nsw_rest_find_post( string $type, string $external_id, string $lang ): int {
	$args = array(
		'post_type'              => $type,
		'post_status'            => array( 'publish', 'future', 'draft', 'pending' ),
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'suppress_filters'       => false,
		'nsw_exclude_revisions'  => true,
		'meta_query'             => array(
			array(
				'key'   => NSW_API_EXTERNAL_ID,
				'value' => $external_id,
			),
		),
	);
	// Constrain by Polylang language when available.
	if ( function_exists( 'pll_languages_list' ) ) {
		$args['lang'] = $lang;
	}
	$found = get_posts( $args );
	return $found ? (int) $found[0] : 0;
}

/**
 * Allowed passthrough meta keys for a type: registered for that subtype AND
 * exposed via show_in_rest, excluding our private identity key.
 */
function nsw_rest_allowed_meta_keys( string $type ): array {
	$keys       = array();
	$registered = get_registered_meta_keys( 'post', $type );
	foreach ( $registered as $key => $meta_args ) {
		if ( ! empty( $meta_args['show_in_rest'] ) && NSW_API_EXTERNAL_ID !== $key ) {
			$keys[] = $key;
		}
	}
	return $keys;
}

/**
 * Validate a caller-supplied meta object against the allowlist. Returns a clean
 * [ key => value ] map, or a WP_Error listing the allowed keys on any unknown key.
 */
function nsw_rest_validate_meta( $meta, string $type ) {
	if ( empty( $meta ) ) {
		return array();
	}
	if ( ! is_array( $meta ) ) {
		return new WP_Error(
			'nsw_rest_bad_meta',
			__( 'The `meta` field must be an object.', 'nsw-theme' ),
			array( 'status' => 400 )
		);
	}
	$allowed = nsw_rest_allowed_meta_keys( $type );
	$unknown = array_diff( array_keys( $meta ), $allowed );
	if ( $unknown ) {
		return new WP_Error(
			'nsw_rest_unknown_meta',
			sprintf(
				/* translators: 1: comma-separated unknown keys, 2: comma-separated allowed keys. */
				__( 'Unknown meta key(s): %1$s. Allowed for this type: %2$s.', 'nsw-theme' ),
				implode( ', ', $unknown ),
				$allowed ? implode( ', ', $allowed ) : __( '(none)', 'nsw-theme' )
			),
			array( 'status' => 400 )
		);
	}
	return $meta;
}

/**
 * Persist validated passthrough meta. Values are sanitized by the registered
 * sanitize callbacks through update_post_meta -> sanitize_meta.
 */
function nsw_rest_apply_meta( int $post_id, array $meta ): void {
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
}

/**
 * Link translations for a freshly created post: pull in any counterpart posts
 * sharing this external id in other languages and save the merged group.
 */
function nsw_rest_link_translations( int $post_id, string $type, string $external_id, string $lang ): void {
	if ( ! function_exists( 'pll_save_post_translations' ) || ! function_exists( 'pll_languages_list' ) ) {
		return;
	}
	$translations = array( $lang => $post_id );
	foreach ( pll_languages_list() as $other ) {
		if ( $other === $lang ) {
			continue;
		}
		$counterpart = nsw_rest_find_post( $type, $external_id, $other );
		if ( $counterpart ) {
			$translations = array_merge( pll_get_post_translations( $counterpart ), $translations );
			$translations[ $other ] = $counterpart;
		}
	}
	$translations[ $lang ] = $post_id;
	if ( count( $translations ) > 1 ) {
		pll_save_post_translations( $translations );
	}
}

/** Uniform success payload. */
function nsw_rest_response( string $result, int $post_id, string $status, string $lang, string $external_id, $revision_id = null, ?int $http = null ): WP_REST_Response {
	$body = array(
		'result'      => $result,
		'post_id'     => $post_id,
		'status'      => $status,
		'lang'        => $lang,
		'external_id' => $external_id,
		'link'        => get_permalink( $post_id ) ?: '',
	);
	if ( null !== $revision_id ) {
		$body['revision_id'] = (int) $revision_id;
	}
	return new WP_REST_Response( $body, $http ?? ( 'created_published' === $result || 'created_pending' === $result ? 201 : 200 ) );
}

/* --------------------------------------------------------------------------
 * 6. The upsert handler.
 * -------------------------------------------------------------------------- */

function nsw_rest_content_upsert( WP_REST_Request $request ) {
	$type        = (string) $request['type'];
	$external_id = trim( (string) $request['external_id'] );
	$lang        = nsw_rest_resolve_lang( $request );
	$pto         = get_post_type_object( $type );

	if ( '' === $external_id ) {
		return new WP_Error(
			'nsw_rest_missing_external_id',
			__( 'A non-empty `external_id` is required.', 'nsw-theme' ),
			array( 'status' => 400 )
		);
	}

	// Validate passthrough meta up front so we never half-write a post.
	$meta = nsw_rest_validate_meta( $request['meta'], $type );
	if ( is_wp_error( $meta ) ) {
		return $meta;
	}

	$has_title   = null !== $request['title'];
	$has_content = null !== $request['content'];
	$has_excerpt = null !== $request['excerpt'];
	$title       = (string) $request['title'];
	$content     = (string) $request['content'];
	$excerpt     = (string) $request['excerpt'];

	$existing = nsw_rest_find_post( $type, $external_id, $lang );

	/* ---------- CREATE ---------- */
	if ( ! $existing ) {
		if ( ! $has_title || '' === trim( $title ) ) {
			return new WP_Error(
				'nsw_rest_missing_title',
				__( 'A `title` is required when creating new content.', 'nsw-theme' ),
				array( 'status' => 400 )
			);
		}
		$publish  = current_user_can( $pto->cap->publish_posts );
		$postarr  = array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $publish ? 'publish' : 'pending',
		);
		$new_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $new_id ) ) {
			$new_id->add_data( array( 'status' => 500 ) );
			return $new_id;
		}
		update_post_meta( $new_id, NSW_API_EXTERNAL_ID, $external_id );
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $new_id, $lang );
		}
		nsw_rest_apply_meta( $new_id, $meta );
		nsw_rest_link_translations( $new_id, $type, $external_id, $lang );

		$status = get_post_status( $new_id );
		return nsw_rest_response(
			$publish ? 'created_published' : 'created_pending',
			$new_id,
			$status,
			$lang,
			$external_id
		);
	}

	/* ---------- UPDATE of an existing item ---------- */
	$post_status = get_post_status( $existing );

	// Draft / pending / future: update in place if the user may edit it.
	if ( in_array( $post_status, array( 'draft', 'pending', 'future' ), true ) ) {
		if ( ! current_user_can( 'edit_post', $existing ) ) {
			return new WP_Error(
				'nsw_rest_cannot_edit',
				__( 'You are not allowed to edit this content.', 'nsw-theme' ),
				array( 'status' => 403 )
			);
		}
		$update = array( 'ID' => $existing );
		if ( $has_title && '' !== trim( $title ) ) {
			$update['post_title'] = $title;
		}
		if ( $has_content ) {
			$update['post_content'] = $content;
		}
		if ( $has_excerpt ) {
			$update['post_excerpt'] = $excerpt;
		}
		$res = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 500 ) );
			return $res;
		}
		nsw_rest_apply_meta( $existing, $meta );
		return nsw_rest_response(
			'future' === $post_status ? 'updated' : 'updated_pending',
			$existing,
			get_post_status( $existing ),
			$lang,
			$external_id
		);
	}

	/* ---------- UPDATE of a PUBLISHED item ---------- */

	// (a) Full editor for this post: write it live.
	if ( current_user_can( 'edit_post', $existing ) ) {
		$update = array( 'ID' => $existing );
		if ( $has_title && '' !== trim( $title ) ) {
			$update['post_title'] = $title;
		}
		if ( $has_content ) {
			$update['post_content'] = $content;
		}
		if ( $has_excerpt ) {
			$update['post_excerpt'] = $excerpt;
		}
		$res = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 500 ) );
			return $res;
		}
		nsw_rest_apply_meta( $existing, $meta );
		return nsw_rest_response( 'updated', $existing, get_post_status( $existing ), $lang, $external_id );
	}

	// (b) Not a full editor: may they submit a revision at all?
	if ( ! current_user_can( 'copy_post', $existing ) ) {
		return new WP_Error(
			'nsw_rest_cannot_revise',
			__( 'You are not allowed to revise this published content.', 'nsw-theme' ),
			array( 'status' => 403 )
		);
	}

	// Revisions plugin required for the pending-revision workflow. The
	// RevisionCreation class is loaded lazily below, so gate on the plugin's
	// always-present entry symbols rather than the not-yet-required class.
	if ( ! function_exists( 'rvy_create_revision' ) || ! defined( 'REVISIONARY_FILE' ) ) {
		return new WP_Error(
			'nsw_rest_revisions_unavailable',
			__( 'The revision workflow is unavailable on this site.', 'nsw-theme' ),
			array( 'status' => 501 )
		);
	}

	$parent      = get_post( $existing );
	$rev_title   = ( $has_title && '' !== trim( $title ) ) ? $title : $parent->post_title;
	$rev_content = $has_content ? $content : $parent->post_content;
	$rev_excerpt = $has_excerpt ? $excerpt : $parent->post_excerpt;
	$user_id     = get_current_user_id();

	// DEDUP: reuse this user's existing pending revision of this parent.
	global $wpdb;
	$existing_rev = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = %s
			   AND post_mime_type = 'pending-revision'
			   AND comment_count = %d
			   AND post_author = %d
			 ORDER BY ID DESC LIMIT 1",
			$type,
			$existing,
			$user_id
		)
	);

	if ( $existing_rev ) {
		$res = wp_update_post(
			wp_slash(
				array(
					'ID'           => $existing_rev,
					'post_title'   => $rev_title,
					'post_content' => $rev_content,
					'post_excerpt' => $rev_excerpt,
				)
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 500 ) );
			return $res;
		}
		nsw_rest_apply_meta( $existing_rev, $meta );
		return nsw_rest_response( 'revision_updated', $existing, 'pending-revision', $lang, $external_id, $existing_rev );
	}

	// Otherwise create a fresh pending revision, suppressing the plugin's redirect.
	require_once dirname( REVISIONARY_FILE ) . '/revision-creation_rvy.php';
	$creator     = new \PublishPress\Revisions\RevisionCreation();
	$revision_id = $creator->createRevision(
		$existing,
		'pending-revision',
		array( 'suppress_redirect' => true ),
		array(
			'post_title'   => $rev_title,
			'post_content' => $rev_content,
			'post_excerpt' => $rev_excerpt,
		)
	);
	if ( is_wp_error( $revision_id ) ) {
		$revision_id->add_data( array( 'status' => 500 ) );
		return $revision_id;
	}
	if ( ! $revision_id ) {
		return new WP_Error(
			'nsw_rest_revision_failed',
			__( 'The revision could not be created.', 'nsw-theme' ),
			array( 'status' => 500 )
		);
	}
	nsw_rest_apply_meta( (int) $revision_id, $meta );
	return nsw_rest_response( 'revision_submitted', $existing, 'pending-revision', $lang, $external_id, (int) $revision_id );
}
