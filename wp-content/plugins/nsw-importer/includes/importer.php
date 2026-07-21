<?php
/**
 * NSW Importer — idempotent seeding of the Albanian NSW content into WordPress.
 *
 * Reads the bundled JSON in /data and creates:
 *   - CPT content: agencies, partners (bilingual single posts, language-neutral)
 *     and documents, events, faq, news (one post per locale, Polylang-linked)
 *   - Pages (sq + en) wired to the theme's page templates, plus a front page
 *   - Primary + footer menus per language (editable in Appearance → Menus)
 *   - Polylang languages (sq default, en) and its translatable-type config
 *
 * Every record carries a `_nsw_theme_import_source` meta so re-running updates
 * in place instead of duplicating. Nothing here runs automatically — it is
 * triggered from Tools → NSW Setup.
 *
 * @package NSW_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NSW_IMPORTER_SOURCE_META = '_nsw_theme_import_source';
// News is core Posts (always translatable in Polylang); only these CPTs need
// to be registered as translatable.
const NSW_IMPORTER_TRANSLATED_CPTS = array( 'nsw_event', 'nsw_document', 'nsw_faq' );

/**
 * Logical page key → localized slug, mirroring the theme's nsw_theme_path_slugs().
 */
function nsw_importer_slugs(): array {
	return array(
		'about'        => array( 'sq' => 'rreth-nsw', 'en' => 'about' ),
		'how-it-works' => array( 'sq' => 'si-funksionon', 'en' => 'how-it-works' ),
		'agencies'     => array( 'sq' => 'agjencite', 'en' => 'agencies' ),
		'partners'     => array( 'sq' => 'partneret', 'en' => 'partners' ),
		'faq'          => array( 'sq' => 'pyetjet-e-shpeshta', 'en' => 'faq' ),
		'documents'    => array( 'sq' => 'dokumenta', 'en' => 'documents' ),
		'events'       => array( 'sq' => 'ngjarje', 'en' => 'events' ),
		'news'         => array( 'sq' => 'lajme', 'en' => 'news' ),
		'contact'      => array( 'sq' => 'kontakt', 'en' => 'contact' ),
		'support'      => array( 'sq' => 'suporti', 'en' => 'support' ),
	);
}

function nsw_importer_read_json( string $rel ) {
	$path = NSW_IMPORTER_DATA . ltrim( $rel, '/' );
	if ( ! is_readable( $path ) ) {
		return null;
	}
	return json_decode( (string) file_get_contents( $path ), true );
}

function nsw_importer_find_by_source( string $source, string $post_type ): int {
	$q = new WP_Query(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => NSW_IMPORTER_SOURCE_META, 'value' => $source ),
			),
		)
	);
	return $q->posts ? (int) $q->posts[0] : 0;
}

/**
 * Copy a local image file into the media library and return the attachment ID.
 * Idempotent: a prior import of the same file is reused. Handles SVG (skips the
 * intermediate-size generation core refuses for vector files).
 */
function nsw_importer_attach_image( string $abs, int $parent, string $title ): int {
	if ( '' === $abs || ! is_readable( $abs ) ) {
		return 0;
	}
	$basename = basename( $abs );
	$prior    = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_nsw_theme_import_img',
			'meta_value'     => $basename,
		)
	);
	if ( $prior ) {
		return (int) $prior[0];
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return 0;
	}
	$target = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $basename );
	if ( ! @copy( $abs, $target ) ) {
		return 0;
	}

	$ext   = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
	$mimes = array(
		'svg'  => 'image/svg+xml',
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);
	$mime = $mimes[ $ext ] ?? 'application/octet-stream';

	$attach_id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'post_content'   => '',
		),
		$target,
		$parent,
		true
	);
	if ( is_wp_error( $attach_id ) ) {
		return 0;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	if ( 'image/svg+xml' === $mime ) {
		wp_update_attachment_metadata( $attach_id, array( 'file' => _wp_relative_upload_path( $target ) ) );
	} else {
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $target ) );
	}
	update_post_meta( $attach_id, '_nsw_theme_import_img', $basename );
	return (int) $attach_id;
}

/** Resolve a JSON asset path like "/agencies/x.svg" to the theme image file. */
function nsw_importer_theme_image( string $asset_path ): string {
	$asset_path = ltrim( trim( $asset_path ), '/' );
	if ( '' === $asset_path ) {
		return '';
	}
	return get_theme_root() . '/nsw-theme/assets/images/' . $asset_path;
}

/** Ensure a term exists in a taxonomy and return its slug. */
function nsw_importer_ensure_term( string $slug, string $taxonomy ): string {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return '';
	}
	if ( ! term_exists( $slug, $taxonomy ) ) {
		wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), $taxonomy, array( 'slug' => $slug ) );
	}
	return $slug;
}

/* ------------------------------------------------------------------ *
 *  CPT importers
 * ------------------------------------------------------------------ */

function nsw_importer_import_agencies(): array {
	$log  = array();
	$rows = nsw_importer_read_json( 'agencies.json' );
	if ( ! is_array( $rows ) ) {
		return array( '[agency] no data' );
	}
	foreach ( array_values( $rows ) as $i => $a ) {
		$id = (string) ( $a['id'] ?? '' );
		if ( '' === $id ) {
			continue;
		}
		$src   = 'agency:' . $id;
		$title = (string) ( $a['name']['en'] ?? $a['name']['sq'] ?? $id );
		$exist = nsw_importer_find_by_source( $src, 'nsw_agency' );
		$arr   = array(
			'post_type'   => 'nsw_agency',
			'post_status' => 'publish',
			'post_title'  => $title,
			'menu_order'  => $i,
		);
		if ( $exist ) {
			$arr['ID'] = $exist;
			$pid       = wp_update_post( $arr, true );
		} else {
			$pid = wp_insert_post( $arr, true );
		}
		if ( is_wp_error( $pid ) ) {
			$log[] = "[agency] $id ERROR " . $pid->get_error_message();
			continue;
		}
		$meta = array(
			NSW_IMPORTER_SOURCE_META       => $src,
			'_nsw_theme_agency_id'         => $id,
			'_nsw_theme_agency_abbr_sq'    => (string) ( $a['abbreviation']['sq'] ?? '' ),
			'_nsw_theme_agency_abbr_en'    => (string) ( $a['abbreviation']['en'] ?? '' ),
			'_nsw_theme_agency_name_sq'    => (string) ( $a['name']['sq'] ?? '' ),
			'_nsw_theme_agency_name_en'    => (string) ( $a['name']['en'] ?? '' ),
			'_nsw_theme_agency_desc_sq'    => (string) ( $a['description']['sq'] ?? '' ),
			'_nsw_theme_agency_desc_en'    => (string) ( $a['description']['en'] ?? '' ),
			'_nsw_theme_agency_docs_sq'    => implode( "\n", (array) ( $a['documents']['sq'] ?? array() ) ),
			'_nsw_theme_agency_docs_en'    => implode( "\n", (array) ( $a['documents']['en'] ?? array() ) ),
			'_nsw_theme_agency_color'      => (string) ( $a['color'] ?? '' ),
			'_nsw_theme_agency_website'    => (string) ( $a['website'] ?? '' ),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $pid, $k, $v );
		}
		if ( ! empty( $a['image'] ) ) {
			$att = nsw_importer_attach_image( nsw_importer_theme_image( (string) $a['image'] ), $pid, $title );
			if ( $att ) {
				set_post_thumbnail( $pid, $att );
			}
		}
		$log[] = "[agency] $id " . ( $exist ? 'updated' : 'created' ) . " (#$pid)";
	}
	return $log;
}

function nsw_importer_import_partners(): array {
	$log  = array();
	$rows = nsw_importer_read_json( 'partners.json' );
	if ( ! is_array( $rows ) ) {
		return array( '[partner] no data' );
	}
	foreach ( array_values( $rows ) as $i => $p ) {
		$id = (string) ( $p['id'] ?? '' );
		if ( '' === $id ) {
			continue;
		}
		$src   = 'partner:' . $id;
		$title = (string) ( $p['name']['en'] ?? $p['name']['sq'] ?? $id );
		$exist = nsw_importer_find_by_source( $src, 'nsw_partner' );
		$arr   = array(
			'post_type'   => 'nsw_partner',
			'post_status' => 'publish',
			'post_title'  => $title,
			'menu_order'  => $i,
		);
		if ( $exist ) {
			$arr['ID'] = $exist;
			$pid       = wp_update_post( $arr, true );
		} else {
			$pid = wp_insert_post( $arr, true );
		}
		if ( is_wp_error( $pid ) ) {
			$log[] = "[partner] $id ERROR " . $pid->get_error_message();
			continue;
		}
		$meta = array(
			NSW_IMPORTER_SOURCE_META    => $src,
			'_nsw_theme_partner_id'      => $id,
			'_nsw_theme_partner_type'    => (string) ( $p['type'] ?? '' ),
			'_nsw_theme_partner_name_sq' => (string) ( $p['name']['sq'] ?? '' ),
			'_nsw_theme_partner_name_en' => (string) ( $p['name']['en'] ?? '' ),
			'_nsw_theme_partner_desc_sq' => (string) ( $p['description']['sq'] ?? '' ),
			'_nsw_theme_partner_desc_en' => (string) ( $p['description']['en'] ?? '' ),
			'_nsw_theme_partner_color'   => (string) ( $p['color'] ?? '' ),
			'_nsw_theme_partner_website' => (string) ( $p['website'] ?? '' ),
			'_nsw_theme_partner_logo_bg' => ! empty( $p['logoBg'] ) ? '1' : '',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $pid, $k, $v );
		}
		if ( ! empty( $p['logo'] ) ) {
			$att = nsw_importer_attach_image( nsw_importer_theme_image( (string) $p['logo'] ), $pid, $title );
			if ( $att ) {
				set_post_thumbnail( $pid, $att );
			}
		}
		$log[] = "[partner] $id " . ( $exist ? 'updated' : 'created' ) . " (#$pid)";
	}
	return $log;
}

function nsw_importer_import_documents(): array {
	$log  = array();
	$rows = nsw_importer_read_json( 'documents.json' );
	if ( ! is_array( $rows ) ) {
		return array( '[document] no data' );
	}
	$pairs = array();
	foreach ( $rows as $d ) {
		$docid = (string) ( $d['id'] ?? '' );
		if ( '' === $docid ) {
			continue;
		}
		foreach ( array( 'sq', 'en' ) as $loc ) {
			$src   = "document:$loc:$docid";
			$title = (string) ( $d['title'][ $loc ] ?? $d['title']['en'] ?? $docid );
			$desc  = (string) ( $d['description'][ $loc ] ?? $d['description']['en'] ?? '' );
			$exist = nsw_importer_find_by_source( $src, 'nsw_document' );
			$arr   = array(
				'post_type'    => 'nsw_document',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => $desc,
			);
			if ( $exist ) {
				$arr['ID'] = $exist;
				$pid       = wp_update_post( $arr, true );
			} else {
				$pid = wp_insert_post( $arr, true );
			}
			if ( is_wp_error( $pid ) ) {
				$log[] = "[document] $docid/$loc ERROR " . $pid->get_error_message();
				continue;
			}
			update_post_meta( $pid, NSW_IMPORTER_SOURCE_META, $src );
			update_post_meta( $pid, '_nsw_theme_locale', $loc );
			update_post_meta( $pid, '_nsw_theme_doc_file_type', (string) ( $d['fileType'] ?? '' ) );
			update_post_meta( $pid, '_nsw_theme_doc_size', (string) ( $d['size'] ?? '' ) );
			if ( ! empty( $d['url'] ) ) {
				update_post_meta( $pid, '_nsw_theme_doc_external_url', (string) $d['url'] );
			}
			if ( ! empty( $d['category'] ) ) {
				$slug = nsw_importer_ensure_term( (string) $d['category'], 'nsw_document_category' );
				wp_set_object_terms( $pid, $slug, 'nsw_document_category', false );
			}
			$pairs[ $docid ][ $loc ] = $pid;
		}
	}
	$log[] = sprintf( '[document] imported %d records (sq+en)', count( $pairs ) );
	$log   = array_merge( $log, nsw_importer_link_pairs( $pairs ) );
	return $log;
}

function nsw_importer_import_events(): array {
	$log   = array();
	$pairs = array();
	foreach ( array( 'sq', 'en' ) as $loc ) {
		$rows = nsw_importer_read_json( "events/$loc.json" );
		if ( ! is_array( $rows ) ) {
			continue;
		}
		foreach ( $rows as $e ) {
			$eid = (string) ( $e['id'] ?? '' );
			if ( '' === $eid ) {
				continue;
			}
			$src   = "event:$loc:$eid";
			$exist = nsw_importer_find_by_source( $src, 'nsw_event' );
			$arr   = array(
				'post_type'    => 'nsw_event',
				'post_status'  => 'publish',
				'post_title'   => (string) ( $e['title'] ?? $eid ),
				'post_excerpt' => (string) ( $e['description'] ?? '' ),
			);
			if ( $exist ) {
				$arr['ID'] = $exist;
				$pid       = wp_update_post( $arr, true );
			} else {
				$pid = wp_insert_post( $arr, true );
			}
			if ( is_wp_error( $pid ) ) {
				$log[] = "[event] $eid/$loc ERROR " . $pid->get_error_message();
				continue;
			}
			update_post_meta( $pid, NSW_IMPORTER_SOURCE_META, $src );
			update_post_meta( $pid, '_nsw_theme_locale', $loc );
			update_post_meta( $pid, '_nsw_theme_event_date', (string) ( $e['date'] ?? '' ) );
			update_post_meta( $pid, '_nsw_theme_event_time', (string) ( $e['time'] ?? '' ) );
			update_post_meta( $pid, '_nsw_theme_event_location', (string) ( $e['location'] ?? '' ) );
			update_post_meta( $pid, '_nsw_theme_event_type', (string) ( $e['type'] ?? '' ) );
			if ( ! empty( $e['type'] ) ) {
				$slug = nsw_importer_ensure_term( (string) $e['type'], 'nsw_event_type' );
				wp_set_object_terms( $pid, $slug, 'nsw_event_type', false );
			}
			$pairs[ $eid ][ $loc ] = $pid;
		}
	}
	$log[] = sprintf( '[event] imported %d records (sq+en)', count( $pairs ) );
	$log   = array_merge( $log, nsw_importer_link_pairs( $pairs ) );
	return $log;
}

function nsw_importer_import_faq(): array {
	$log   = array();
	$pairs = array();
	foreach ( array( 'sq', 'en' ) as $loc ) {
		$rows = nsw_importer_read_json( "faq/$loc.json" );
		if ( ! is_array( $rows ) ) {
			continue;
		}
		foreach ( array_values( $rows ) as $i => $f ) {
			$fid = (string) ( $f['id'] ?? '' );
			if ( '' === $fid ) {
				continue;
			}
			$src   = "faq:$loc:$fid";
			$exist = nsw_importer_find_by_source( $src, 'nsw_faq' );
			$arr   = array(
				'post_type'    => 'nsw_faq',
				'post_status'  => 'publish',
				'post_title'   => (string) ( $f['question'] ?? $fid ),
				'post_content' => (string) ( $f['answer'] ?? '' ),
				'menu_order'   => $i,
			);
			if ( $exist ) {
				$arr['ID'] = $exist;
				$pid       = wp_update_post( $arr, true );
			} else {
				$pid = wp_insert_post( $arr, true );
			}
			if ( is_wp_error( $pid ) ) {
				$log[] = "[faq] $fid/$loc ERROR " . $pid->get_error_message();
				continue;
			}
			update_post_meta( $pid, NSW_IMPORTER_SOURCE_META, $src );
			update_post_meta( $pid, '_nsw_theme_locale', $loc );
			if ( ! empty( $f['category'] ) ) {
				$slug = nsw_importer_ensure_term( (string) $f['category'], 'nsw_faq_category' );
				wp_set_object_terms( $pid, $slug, 'nsw_faq_category', false );
			}
			$pairs[ $fid ][ $loc ] = $pid;
		}
	}
	$log[] = sprintf( '[faq] imported %d records (sq+en)', count( $pairs ) );
	$log   = array_merge( $log, nsw_importer_link_pairs( $pairs ) );
	return $log;
}

function nsw_importer_import_news(): array {
	$log   = array();
	$pairs = array();
	foreach ( array( 'sq', 'en' ) as $loc ) {
		$dir = NSW_IMPORTER_DATA . "news/$loc";
		foreach ( glob( $dir . '/*.json' ) ?: array() as $file ) {
			$d = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $d ) || empty( $d['slug'] ) ) {
				continue;
			}
			$slug = sanitize_title( (string) $d['slug'] );
			$src  = "news:$loc:$slug";

			$content = '';
			if ( isset( $d['content'] ) && is_array( $d['content'] ) ) {
				foreach ( $d['content'] as $para ) {
					$content .= '<p>' . wp_kses_post( (string) $para ) . "</p>\n";
				}
			} elseif ( isset( $d['content'] ) ) {
				$content = wp_kses_post( (string) $d['content'] );
			}

			$arr = array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => (string) ( $d['title'] ?? $slug ),
				'post_name'    => $slug . ( 'en' === $loc ? '-en' : '' ),
				'post_excerpt' => (string) ( $d['excerpt'] ?? '' ),
				'post_content' => $content,
			);
			if ( ! empty( $d['date'] ) ) {
				$ts = strtotime( (string) $d['date'] );
				if ( $ts ) {
					$arr['post_date']     = gmdate( 'Y-m-d H:i:s', $ts );
					$arr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
				}
			}
			$exist = nsw_importer_find_by_source( $src, 'post' );
			if ( $exist ) {
				$arr['ID'] = $exist;
				$pid       = wp_update_post( $arr, true );
			} else {
				$pid = wp_insert_post( $arr, true );
			}
			if ( is_wp_error( $pid ) ) {
				$log[] = "[news] $slug/$loc ERROR " . $pid->get_error_message();
				continue;
			}
			update_post_meta( $pid, NSW_IMPORTER_SOURCE_META, $src );
			update_post_meta( $pid, '_nsw_theme_locale', $loc );
			if ( ! empty( $d['author'] ) ) {
				update_post_meta( $pid, '_nsw_theme_author_text', (string) $d['author'] );
			}
			if ( ! empty( $d['category'] ) ) {
				$scat = nsw_importer_ensure_term( (string) $d['category'], 'category' );
				wp_set_object_terms( $pid, $scat, 'category', false );
			}
			$pairs[ $slug ][ $loc ] = $pid;
		}
	}
	$log[] = sprintf( '[news] imported %d articles (sq+en)', count( $pairs ) );
	$log   = array_merge( $log, nsw_importer_link_pairs( $pairs ) );
	return $log;
}

/* ------------------------------------------------------------------ *
 *  Pages + front page
 * ------------------------------------------------------------------ */

function nsw_importer_import_pages(): array {
	$log   = array();
	$slugs = nsw_importer_slugs();
	$pages = array(
		'about'        => array( 'title' => 'About NSW', 'template' => 'page-templates/page-about.php' ),
		'how-it-works' => array( 'title' => 'How It Works', 'template' => 'page-templates/page-how-it-works.php' ),
		'agencies'     => array( 'title' => 'Agencies', 'template' => 'page-templates/page-agencies.php' ),
		'partners'     => array( 'title' => 'Partners', 'template' => 'page-templates/page-partners.php' ),
		'faq'          => array( 'title' => 'FAQ', 'template' => 'page-templates/page-faq.php' ),
		'documents'    => array( 'title' => 'Documents', 'template' => 'page-templates/page-documents.php' ),
		'events'       => array( 'title' => 'Events', 'template' => 'page-templates/page-events.php' ),
		'contact'      => array( 'title' => 'Contact', 'template' => 'page-templates/page-contact.php' ),
		'support'      => array( 'title' => 'Support', 'template' => 'page-templates/page-support.php' ),
	);

	foreach ( $pages as $key => $info ) {
		$pair = array();
		foreach ( array( 'sq', 'en' ) as $loc ) {
			$slug  = $slugs[ $key ][ $loc ] ?? $slugs[ $key ]['en'];
			$src   = "page:$loc:$key";
			$exist = nsw_importer_find_by_source( $src, 'page' );
			$arr   = array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $info['title'],
				'post_name'   => $slug,
			);
			if ( $exist ) {
				$arr['ID'] = $exist;
				$pid       = wp_update_post( $arr, true );
			} else {
				$pid = wp_insert_post( $arr, true );
			}
			if ( is_wp_error( $pid ) ) {
				$log[] = "[page] $key/$loc ERROR " . $pid->get_error_message();
				continue;
			}
			update_post_meta( $pid, '_wp_page_template', $info['template'] );
			update_post_meta( $pid, NSW_IMPORTER_SOURCE_META, $src );
			update_post_meta( $pid, '_nsw_theme_locale', $loc );
			$pair[ $loc ] = $pid;
		}
		$log[] = "[page] $key (sq#{$pair['sq']} / en#{$pair['en']})";
		$log   = array_merge( $log, nsw_importer_link_pairs( array( $key => $pair ) ) );
	}

	// Front page (sq + en, linked). sq is the site front page; Polylang serves
	// the en translation on the English homepage.
	$home = array();
	foreach ( array( 'sq', 'en' ) as $loc ) {
		$src   = "page:$loc:home";
		$exist = nsw_importer_find_by_source( $src, 'page' );
		$arr   = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Home',
			'post_name'   => 'sq' === $loc ? 'home' : 'home-en',
		);
		if ( $exist ) {
			$arr['ID'] = $exist;
			$pid       = wp_update_post( $arr, true );
		} else {
			$pid = wp_insert_post( $arr, true );
		}
		if ( is_wp_error( $pid ) ) {
			$log[] = "[home] $loc ERROR " . $pid->get_error_message();
			continue;
		}
		update_post_meta( $pid, NSW_IMPORTER_SOURCE_META, $src );
		update_post_meta( $pid, '_nsw_theme_locale', $loc );
		$home[ $loc ] = $pid;
	}
	if ( ! empty( $home['sq'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $home['sq'] );
		$log[] = "[home] front page set to sq#{$home['sq']}";
	}
	$log = array_merge( $log, nsw_importer_link_pairs( array( 'home' => $home ) ) );

	// News page — assigned as the Posts page (Settings → Reading), so /lajme/
	// and /en/news/ list the core Posts, rendered by the theme's home.php.
	$news = array();
	foreach ( array( 'sq', 'en' ) as $loc ) {
		$slug  = $slugs['news'][ $loc ] ?? 'news';
		$src   = "page:$loc:news";
		$exist = nsw_importer_find_by_source( $src, 'page' );
		$arr   = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'News',
			'post_name'   => $slug,
		);
		if ( $exist ) {
			$arr['ID'] = $exist;
			$pid       = wp_update_post( $arr, true );
		} else {
			$pid = wp_insert_post( $arr, true );
		}
		if ( is_wp_error( $pid ) ) {
			$log[] = "[news-page] $loc ERROR " . $pid->get_error_message();
			continue;
		}
		update_post_meta( $pid, NSW_IMPORTER_SOURCE_META, $src );
		update_post_meta( $pid, '_nsw_theme_locale', $loc );
		$news[ $loc ] = $pid;
	}
	if ( ! empty( $news['sq'] ) ) {
		update_option( 'page_for_posts', (int) $news['sq'] );
		$log[] = "[news-page] posts page set to sq#{$news['sq']}";
	}
	$log = array_merge( $log, nsw_importer_link_pairs( array( 'news' => $news ) ) );

	return $log;
}

/* ------------------------------------------------------------------ *
 *  Menus (per language)
 * ------------------------------------------------------------------ */

/**
 * Build the primary + footer menus for one language and return their term IDs
 * keyed by theme location.
 */
function nsw_importer_build_menus( string $loc ): array {
	$slugs = nsw_importer_slugs();

	$page_id = function ( string $key ) use ( $loc ): int {
		return nsw_importer_find_by_source( "page:$loc:$key", 'page' );
	};
	$label = function ( string $key ) use ( $loc ): string {
		$en = array(
			'home' => 'Home', 'about' => 'About NSW', 'how-it-works' => 'How It Works',
			'agencies' => 'Agencies', 'partners' => 'Partners', 'faq' => 'FAQ',
			'documents' => 'Documents', 'events' => 'Events', 'news' => 'News', 'contact' => 'Contact',
			'aboutDropdown' => 'About', 'resourcesDropdown' => 'Resources',
		);
		$sq = array(
			'home' => 'Kreu', 'about' => 'Rreth NSW', 'how-it-works' => 'Si Funksionon',
			'agencies' => 'Agjencitë', 'partners' => 'Partnerët', 'faq' => 'Pyetjet e Shpeshta',
			'documents' => 'Dokumenta', 'events' => 'Ngjarje', 'news' => 'Lajme', 'contact' => 'Kontakt',
			'aboutDropdown' => 'Rreth Nesh', 'resourcesDropdown' => 'Burime',
		);
		return ( 'sq' === $loc ? $sq : $en )[ $key ] ?? $key;
	};

	$locations = array();

	// ---- Primary (with two dropdowns) ----
	$primary = nsw_importer_reset_menu( "Primary ($loc)" );
	$home_item = wp_update_nav_menu_item( $primary, 0, array(
		'menu-item-title'     => $label( 'home' ),
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $page_id( 'home' ),
		'menu-item-type'      => 'post_type',
		'menu-item-status'    => 'publish',
	) );

	$about_parent = wp_update_nav_menu_item( $primary, 0, array(
		'menu-item-title'  => $label( 'aboutDropdown' ),
		'menu-item-url'    => '#',
		'menu-item-type'   => 'custom',
		'menu-item-status' => 'publish',
	) );
	foreach ( array( 'about', 'how-it-works', 'agencies', 'partners' ) as $child ) {
		wp_update_nav_menu_item( $primary, 0, array(
			'menu-item-title'     => $label( $child ),
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page_id( $child ),
			'menu-item-type'      => 'post_type',
			'menu-item-parent-id' => $about_parent,
			'menu-item-status'    => 'publish',
		) );
	}

	$res_parent = wp_update_nav_menu_item( $primary, 0, array(
		'menu-item-title'  => $label( 'resourcesDropdown' ),
		'menu-item-url'    => '#',
		'menu-item-type'   => 'custom',
		'menu-item-status' => 'publish',
	) );
	foreach ( array( 'faq', 'documents', 'news', 'events' ) as $child ) {
		wp_update_nav_menu_item( $primary, 0, array(
			'menu-item-title'     => $label( $child ),
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page_id( $child ),
			'menu-item-type'      => 'post_type',
			'menu-item-parent-id' => $res_parent,
			'menu-item-status'    => 'publish',
		) );
	}

	wp_update_nav_menu_item( $primary, 0, array(
		'menu-item-title'     => $label( 'contact' ),
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $page_id( 'contact' ),
		'menu-item-type'      => 'post_type',
		'menu-item-status'    => 'publish',
	) );
	$locations['primary'] = $primary;

	// ---- Footer: quick links ----
	$fq = nsw_importer_reset_menu( "Footer Quick ($loc)" );
	foreach ( array( 'home', 'about', 'how-it-works', 'contact' ) as $key ) {
		wp_update_nav_menu_item( $fq, 0, array(
			'menu-item-title'     => $label( $key ),
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page_id( $key ),
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		) );
	}
	$locations['footer-quick'] = $fq;

	// ---- Footer: resources ----
	$fr = nsw_importer_reset_menu( "Footer Resources ($loc)" );
	foreach ( array( 'faq', 'documents', 'news', 'events' ) as $key ) {
		wp_update_nav_menu_item( $fr, 0, array(
			'menu-item-title'     => $label( $key ),
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page_id( $key ),
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		) );
	}
	$locations['footer-resources'] = $fr;

	return $locations;
}

/** Find-or-create a menu by name and strip its existing items (idempotent rebuild). */
function nsw_importer_reset_menu( string $name ): int {
	$menu = wp_get_nav_menu_object( $name );
	if ( ! $menu ) {
		$id = wp_create_nav_menu( $name );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}
	foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: array() as $item ) {
		wp_delete_post( $item->ID, true );
	}
	return (int) $menu->term_id;
}

function nsw_importer_import_menus(): array {
	$log       = array();
	$theme     = get_option( 'stylesheet' );
	$has_pll   = function_exists( 'pll_languages_list' );
	$sq_locs   = nsw_importer_build_menus( 'sq' );
	$log[]     = '[menu] built sq menus: ' . implode( ', ', array_keys( $sq_locs ) );

	// Default (non-Polylang) assignment → sq menus.
	$mods = get_theme_mod( 'nav_menu_locations', array() );
	foreach ( $sq_locs as $location => $menu_id ) {
		$mods[ $location ] = $menu_id;
	}
	set_theme_mod( 'nav_menu_locations', $mods );

	if ( $has_pll ) {
		$en_locs = nsw_importer_build_menus( 'en' );
		$log[]   = '[menu] built en menus: ' . implode( ', ', array_keys( $en_locs ) );
		$opts    = get_option( 'polylang' );
		if ( is_array( $opts ) ) {
			foreach ( $sq_locs as $location => $menu_id ) {
				$opts['nav_menus'][ $theme ][ $location ]['sq'] = $menu_id;
				$opts['nav_menus'][ $theme ][ $location ]['en'] = $en_locs[ $location ] ?? $menu_id;
			}
			update_option( 'polylang', $opts );
			$log[] = '[menu] assigned per-language menu locations in Polylang';
		}
	} else {
		$log[] = '[menu] Polylang inactive — assigned sq menus to locations';
	}

	return $log;
}

/* ------------------------------------------------------------------ *
 *  Polylang: languages, links, string seeding
 * ------------------------------------------------------------------ */

/** Assign languages + link a translation pair. $pairs = [ key => [sq=>id, en=>id] ]. */
function nsw_importer_link_pairs( array $pairs ): array {
	$log = array();
	if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		return $log;
	}
	foreach ( $pairs as $key => $p ) {
		if ( empty( $p['sq'] ) || empty( $p['en'] ) ) {
			continue;
		}
		pll_set_post_language( (int) $p['sq'], 'sq' );
		pll_set_post_language( (int) $p['en'], 'en' );
		pll_save_post_translations( array( 'sq' => (int) $p['sq'], 'en' => (int) $p['en'] ) );
	}
	return $log;
}

/**
 * Ensure the sq (default) + en languages exist and the translatable post-type
 * config is correct (agencies/partners stay language-neutral).
 */
function nsw_importer_setup_polylang(): array {
	$log = array();
	if ( ! function_exists( 'PLL' ) || ! PLL() || ! isset( PLL()->model ) ) {
		return array( '[pll] Polylang not active — skipping language setup' );
	}
	$model     = PLL()->model;
	$existing  = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list() : array();
	$languages = array(
		array( 'name' => 'Shqip', 'slug' => 'sq', 'locale' => 'sq_AL', 'rtl' => 0, 'term_group' => 0, 'flag' => 'al' ),
		array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => 0, 'term_group' => 1, 'flag' => 'us' ),
	);
	foreach ( $languages as $lang ) {
		if ( in_array( $lang['slug'], $existing, true ) ) {
			$log[] = "[pll] language {$lang['slug']} already exists";
			continue;
		}
		$res = $model->add_language( $lang );
		$log[] = is_wp_error( $res )
			? "[pll] add {$lang['slug']} ERROR " . $res->get_error_message()
			: "[pll] added language {$lang['slug']}";
	}

	$opts = get_option( 'polylang' );
	if ( is_array( $opts ) ) {
		$opts['default_lang'] = 'sq';
		// Serve secondary-language front pages at the language-code URL (/en/)
		// instead of the page-name URL (/en/home-en/).
		$opts['redirect_lang'] = true;
		$opts['post_types']    = array_values( array_unique( array_merge( $opts['post_types'] ?? array(), NSW_IMPORTER_TRANSLATED_CPTS ) ) );
		// Keep taxonomies (incl. core `category`) language-neutral so news
		// categories are shared across sq/en and read by the theme via slug.
		$opts['taxonomies']    = array();
		update_option( 'polylang', $opts );
		// Reflect the change in the live Polylang instance so the end-of-run
		// cache rebuild computes home URLs with redirect_lang already true.
		try {
			if ( isset( PLL()->options ) ) {
				PLL()->options['redirect_lang'] = true;
			}
		} catch ( \Throwable $e ) {
			$log[] = '[pll] note: could not update live options in-place (' . $e->getMessage() . ')';
		}
		$log[] = '[pll] translatable CPTs: ' . implode( ', ', NSW_IMPORTER_TRANSLATED_CPTS ) . ' (agencies/partners neutral)';
	}
	if ( method_exists( $model, 'clean_languages_cache' ) ) {
		$model->clean_languages_cache();
	}
	return $log;
}

/**
 * Albanian UI-string translations are owned by Polylang (Languages → String
 * translations, group "NSW Theme"), not by a theme file. There is no file-based
 * seed anymore, so this step is a no-op kept for orchestrator compatibility.
 */
function nsw_importer_seed_strings(): array {
	return array( '[pll] string translations are managed in Polylang (Languages → String translations); no file seeding' );
}

/* ------------------------------------------------------------------ *
 *  Clean + orchestrator
 * ------------------------------------------------------------------ */

function nsw_importer_wipe(): int {
	$q = new WP_Query(
		array(
			'post_type'      => array( 'nsw_agency', 'nsw_partner', 'nsw_document', 'nsw_event', 'nsw_faq', 'post', 'page' ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => NSW_IMPORTER_SOURCE_META, 'compare' => 'EXISTS' ),
			),
		)
	);
	$n = 0;
	foreach ( $q->posts as $id ) {
		wp_delete_post( (int) $id, true );
		$n++;
	}
	return $n;
}

/**
 * Full import. Order matters: Polylang languages first (so posts can be
 * assigned a language as they are created), then content, pages, menus, strings.
 *
 * @param array{clean?: bool} $args
 * @return string[]
 */
function nsw_importer_run( array $args = array() ): array {
	$log = array( '[info] NSW import started' );

	if ( ! empty( $args['clean'] ) ) {
		$n     = nsw_importer_wipe();
		$log[] = "[clean] removed $n previously imported posts/pages";
	}

	$log = array_merge( $log, nsw_importer_setup_polylang() );
	$log = array_merge( $log, nsw_importer_import_agencies() );
	$log = array_merge( $log, nsw_importer_import_partners() );
	$log = array_merge( $log, nsw_importer_import_documents() );
	$log = array_merge( $log, nsw_importer_import_events() );
	$log = array_merge( $log, nsw_importer_import_faq() );
	$log = array_merge( $log, nsw_importer_import_news() );
	$log = array_merge( $log, nsw_importer_import_pages() );
	$log = array_merge( $log, nsw_importer_import_menus() );
	$log = array_merge( $log, nsw_importer_seed_strings() );

	// Recompute per-language home URLs now that the front-page translations are
	// linked and redirect_lang is set, then refresh rewrite rules.
	if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->model ) && method_exists( PLL()->model, 'clean_languages_cache' ) ) {
		PLL()->model->clean_languages_cache();
	}
	flush_rewrite_rules();
	$log[] = '[info] NSW import finished';
	return $log;
}
