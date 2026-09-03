<?php
/**
 * Content accessors.
 *
 * Editorial content (agencies, events, documents, partners, FAQ, news) is read
 * from the database via the CPTs. UI microcopy is read from the theme's
 * languages/content-{locale}.php catalog. There is no JSON content layer.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a per-locale value from a record. Handles records keyed by locale
 * slug, e.g. [ 'sq' => '…', 'en' => '…' ], falling back in order: the requested
 * locale → the site's default locale → English → whichever locale exists.
 */
function nsw_theme_localized( $field, ?string $locale = null ) {
	$locale = $locale ?: nsw_theme_current_locale();

	if ( ! is_array( $field ) ) {
		return $field;
	}

	foreach ( array( $locale, nsw_theme_default_locale(), 'en' ) as $try ) {
		if ( isset( $field[ $try ] ) && '' !== $field[ $try ] ) {
			return $field[ $try ];
		}
	}
	$first = reset( $field );
	return false === $first ? '' : $first;
}

/**
 * Read the agencies list.
 *
 * Reads every published `nsw_agency` post from the database and maps each to
 * the `{id, abbreviation, image, name, description, documents, color, website}`
 * shape the public Agencies page expects. Managed in wp-admin.
 */
function nsw_theme_get_agencies(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$agencies = array();
	if ( post_type_exists( 'nsw_agency' ) ) {
		$posts = get_posts(
			array(
				'post_type'      => 'nsw_agency',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$agencies[] = nsw_theme_agency_post_to_array( $post );
		}
	}

	$cache = $agencies;
	return $cache;
}

/**
 * Build the array for one agency post. Each post is single-language now
 * (Polylang scopes the query to the active language), so name / abbreviation /
 * description / documents are plain values in the post's own language. Falls
 * back to the post title when the name meta is empty.
 */
function nsw_theme_agency_post_to_array( WP_Post $post ): array {
	$post_id = $post->ID;
	$id      = (string) get_post_meta( $post_id, '_nsw_theme_agency_id', true );

	/* Documents are stored one item per line. The card renders each as a pill.
	   Split on newlines, semicolons, and pipes so an admin typing
	   `Customs declarations; Certificates of origin` gets two pills. Commas are
	   NOT delimiters (document names legitimately contain them). */
	$raw_docs  = (string) get_post_meta( $post_id, '_nsw_theme_agency_docs', true );
	$documents = $raw_docs
		? array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n;|]+/', $raw_docs ) ) ) )
		: array();

	$image_url = '';
	if ( has_post_thumbnail( $post_id ) ) {
		$image_url = (string) get_the_post_thumbnail_url( $post_id, 'full' );
	}

	$name = (string) get_post_meta( $post_id, '_nsw_theme_agency_name', true );

	return array(
		'id'           => $id ?: $post->post_name,
		'abbreviation' => (string) get_post_meta( $post_id, '_nsw_theme_agency_abbr', true ),
		'image'        => $image_url,
		'name'         => '' !== $name ? $name : $post->post_title,
		'description'  => (string) get_post_meta( $post_id, '_nsw_theme_agency_desc', true ),
		'documents'    => $documents,
		'color'        => (string) get_post_meta( $post_id, '_nsw_theme_agency_color',   true ),
		'website'      => (string) get_post_meta( $post_id, '_nsw_theme_agency_website', true ),
	);
}

/**
 * Read the partners list from the database (nsw_partner CPT). One post per
 * language (Polylang scopes the query); logo is the featured image. Returns the
 * shape the Partners page + homepage strip expect: {id, type, name, description,
 * color, website, logo, logoBg}.
 */
function nsw_theme_get_partners(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$partners = array();
	if ( post_type_exists( 'nsw_partner' ) ) {
		$posts = get_posts(
			array(
				'post_type'      => 'nsw_partner',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$partners[] = nsw_theme_partner_post_to_array( $post );
		}
	}

	$cache = $partners;
	return $cache;
}

function nsw_theme_partner_post_to_array( WP_Post $post ): array {
	$pid  = $post->ID;
	$logo = has_post_thumbnail( $pid ) ? (string) get_the_post_thumbnail_url( $pid, 'full' ) : '';
	$name = (string) get_post_meta( $pid, '_nsw_theme_partner_name', true );

	return array(
		'id'          => (string) get_post_meta( $pid, '_nsw_theme_partner_id', true ) ?: $post->post_name,
		'type'        => (string) get_post_meta( $pid, '_nsw_theme_partner_type', true ),
		'name'        => '' !== $name ? $name : $post->post_title,
		'description' => (string) get_post_meta( $pid, '_nsw_theme_partner_desc', true ),
		'color'       => (string) get_post_meta( $pid, '_nsw_theme_partner_color', true ),
		'website'     => (string) get_post_meta( $pid, '_nsw_theme_partner_website', true ),
		'logo'        => $logo,
		'logoBg'      => '1' === (string) get_post_meta( $pid, '_nsw_theme_partner_logo_bg', true ),
	);
}

/**
 * Read the documents list — same JSON+CPT merge pattern as agencies. CPT
 * entries override JSON by id (so the dashboard can correct/extend a JSON
 * seed without breaking everything else), and CPT-only entries append.
 * Per-locale CPT entries are filtered to the active locale up front so they
 * sit naturally next to bilingual JSON entries on the public page.
 */
function nsw_theme_get_documents(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$documents = array();
	if ( post_type_exists( 'nsw_document' ) ) {
		/* Polylang scopes the query to the active language on its own, so we
		   simply map every returned post. */
		$posts = get_posts(
			array(
				'post_type'      => 'nsw_document',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$documents[] = nsw_theme_document_post_to_array( $post );
		}
	}

	$cache = $documents;
	return $cache;
}

/**
 * Build the legacy-JSON-shaped array for one document CPT post. Locale-tagged
 * fields are stored on the post's own locale meta — translations live as
 * separate CPT entries (mirrors how news/events are modeled).
 */
function nsw_theme_document_post_to_array( WP_Post $post ): array {
	$post_id  = $post->ID;
	$locale   = nsw_theme_post_locale( $post_id );
	$file_id  = (int) get_post_meta( $post_id, '_nsw_theme_doc_file_id', true );
	$external = (string) get_post_meta( $post_id, '_nsw_theme_doc_external_url', true );
	$url      = $file_id ? (string) wp_get_attachment_url( $file_id ) : $external;
	$terms    = wp_get_object_terms( $post_id, 'nsw_document_category', array( 'fields' => 'slugs' ) );
	$category = ( is_array( $terms ) && ! empty( $terms ) ) ? (string) $terms[0] : '';

	return array(
		'id'          => $post->post_name ?: ( 'cpt-' . $post_id ),
		'category'    => $category,
		'title'       => array( $locale => $post->post_title ),
		'description' => array( $locale => (string) $post->post_excerpt ),
		'url'         => $url,
		'fileType'    => (string) get_post_meta( $post_id, '_nsw_theme_doc_file_type', true ),
		'size'        => (string) get_post_meta( $post_id, '_nsw_theme_doc_size', true ),
		'language'    => $locale,
	);
}

/**
 * Read the events list from the database. Each event is one post per locale,
 * tagged with its locale and filtered to the active one. `isPast` is computed
 * from the event date so the public Upcoming/Past split works automatically.
 */
function nsw_theme_get_events(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$events = array();
	if ( post_type_exists( 'nsw_event' ) ) {
		/* Polylang scopes the query to the active language on its own. */
		$posts = get_posts(
			array(
				'post_type'      => 'nsw_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_nsw_theme_event_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$events[] = nsw_theme_event_post_to_array( $post );
		}
	}

	$cache = $events;
	return $cache;
}

/**
 * Build the legacy-JSON-shaped array for one event CPT post.
 */
function nsw_theme_event_post_to_array( WP_Post $post ): array {
	$post_id = $post->ID;
	$locale  = nsw_theme_post_locale( $post_id );
	$image   = has_post_thumbnail( $post_id ) ? (string) get_the_post_thumbnail_url( $post_id, 'full' ) : '';
	$date    = (string) get_post_meta( $post_id, '_nsw_theme_event_date', true );
	$today   = current_time( 'Y-m-d' );

	return array(
		'id'          => $post->post_name ?: ( 'cpt-' . $post_id ),
		'title'       => array( $locale => $post->post_title ),
		'date'        => $date,
		'time'        => (string) get_post_meta( $post_id, '_nsw_theme_event_time', true ),
		'location'    => array( $locale => (string) get_post_meta( $post_id, '_nsw_theme_event_location', true ) ),
		'type'        => (string) get_post_meta( $post_id, '_nsw_theme_event_type', true ),
		'isPast'      => $date && $date < $today,
		'description' => array( $locale => (string) $post->post_excerpt ),
		'image'       => $image,
	);
}

/**
 * Read the FAQ list for a locale from the database (nsw_faq CPT). Each item is
 * one post per locale: question = title, answer = content, category =
 * nsw_faq_category term. Returns {id, category, question, answer} rows.
 */
function nsw_theme_get_faq( ?string $locale = null ): array {
	$key = $locale ?: nsw_theme_current_locale();
	static $cache = array();
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$items = array();
	if ( post_type_exists( 'nsw_faq' ) ) {
		$posts = get_posts(
			array(
				'post_type'      => 'nsw_faq',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$terms = wp_get_object_terms( $post->ID, 'nsw_faq_category', array( 'fields' => 'slugs' ) );
			$items[] = array(
				'id'       => $post->post_name ?: ( 'cpt-' . $post->ID ),
				'category' => ( is_array( $terms ) && ! empty( $terms ) ) ? (string) $terms[0] : '',
				'question' => $post->post_title,
				'answer'   => trim( wp_strip_all_tags( $post->post_content ) ),
			);
		}
	}

	$cache[ $key ] = $items;
	return $cache[ $key ];
}

/**
 * UI microcopy map used by nsw_theme_t() for hero/section copy and a few option
 * lists.
 *
 * English is the single source, in languages/content-en.php. Translations are
 * owned by Polylang: for a non-English locale we return the English tree with
 * every string leaf run through pll_translate_string() for that exact locale
 * (so it's correct on the front end AND in editor previews, regardless of
 * Polylang's ambient current language). No per-language catalog file.
 */
function nsw_theme_get_content( ?string $locale = null ): array {
	$locale = $locale ?: nsw_theme_current_locale();
	static $source = null;
	static $cache  = array();

	if ( null === $source ) {
		$file   = NSW_THEME_DIR . 'languages/content-en.php';
		$source = is_readable( $file ) ? (array) require $file : array();
	}

	if ( 'en' === $locale || ! function_exists( 'pll_translate_string' ) ) {
		return $source;
	}
	if ( ! isset( $cache[ $locale ] ) ) {
		$cache[ $locale ] = nsw_theme_translate_content_tree( $source, $locale );
	}
	return $cache[ $locale ];
}

/**
 * Deep-map a content tree, translating each non-empty string leaf to $locale
 * through Polylang. Arrays are recursed; non-strings pass through unchanged.
 */
function nsw_theme_translate_content_tree( array $node, string $locale ): array {
	$out = array();
	foreach ( $node as $k => $v ) {
		if ( is_array( $v ) ) {
			$out[ $k ] = nsw_theme_translate_content_tree( $v, $locale );
		} elseif ( is_string( $v ) && '' !== $v ) {
			$out[ $k ] = (string) pll_translate_string( $v, $locale );
		} else {
			$out[ $k ] = $v;
		}
	}
	return $out;
}

/**
 * Translate a path that the Next.js public/ folder used (e.g. "/agencies/dpd-logo.svg")
 * to a theme asset URL. Idempotent for paths that already point at the theme.
 */
function nsw_theme_asset_url( string $path ): string {
	$path = trim( $path );
	if ( '' === $path ) {
		return '';
	}
	if ( preg_match( '#^(https?:)?//#', $path ) ) {
		return $path;
	}
	$path = '/' . ltrim( $path, '/' );

	$prefixes = array(
		'/agencies/' => 'assets/images/agencies/',
		'/partners/' => 'assets/images/partners/',
		'/images/'   => 'assets/images/',
	);
	foreach ( $prefixes as $needle => $folder ) {
		if ( 0 === strpos( $path, $needle ) ) {
			return NSW_THEME_URI . $folder . ltrim( substr( $path, strlen( $needle ) ), '/' );
		}
	}

	if ( 0 === strpos( $path, '/assets/' ) ) {
		return NSW_THEME_URI . ltrim( $path, '/' );
	}

	return NSW_THEME_URI . ltrim( $path, '/' );
}
