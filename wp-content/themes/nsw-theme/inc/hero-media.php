<?php
/**
 * Hero background media.
 *
 * The theme ships a default hero video + poster as theme assets. To make the
 * hero background editable from the Media Library (a single "Background media"
 * field that accepts an image OR a video), we import those two assets into the
 * Media Library once, so they appear as the current selection and can be
 * swapped. The attachment IDs are stored in options.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy a theme file into the Media Library and return the new attachment ID.
 */
function nsw_theme_sideload_file( string $path, string $filename, string $mime ): int {
	if ( ! is_readable( $path ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload = wp_upload_bits( $filename, null, file_get_contents( $path ) );
	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}
	$attachment = array(
		'post_mime_type' => $mime,
		'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$id = wp_insert_attachment( $attachment, $upload['file'] );
	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	return (int) $id;
}

/**
 * Import the hero video + poster into the Media Library once (idempotent).
 */
function nsw_theme_import_hero_media(): void {
	if ( get_option( 'nsw_theme_hero_media_imported' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$assets = array(
		'nsw_theme_hero_video_id'  => array( NSW_THEME_DIR . 'assets/video/hero.mp4', 'nsw-hero-background.mp4', 'video/mp4' ),
		'nsw_theme_hero_poster_id' => array( NSW_THEME_DIR . 'assets/images/hero/hero-bg.jpg', 'nsw-hero-poster.jpg', 'image/jpeg' ),
	);
	foreach ( $assets as $option => $info ) {
		$existing = (int) get_option( $option );
		if ( $existing && wp_get_attachment_url( $existing ) ) {
			continue;
		}
		$id = nsw_theme_sideload_file( $info[0], $info[1], $info[2] );
		if ( $id ) {
			update_option( $option, $id );
		}
	}
	update_option( 'nsw_theme_hero_media_imported', 1 );
}
add_action( 'admin_init', 'nsw_theme_import_hero_media' );

/**
 * The default hero background media: the imported video if available, else the
 * theme's shipped video asset. Returns url + attachment id + mime.
 *
 * @return array{url:string,id:int,mime:string}
 */
function nsw_theme_hero_media_default(): array {
	$vid = (int) get_option( 'nsw_theme_hero_video_id' );
	$url = $vid ? wp_get_attachment_url( $vid ) : '';
	if ( $vid && $url ) {
		return array(
			'url'  => $url,
			'id'   => $vid,
			'mime' => get_post_mime_type( $vid ) ?: 'video/mp4',
		);
	}
	// Theme asset fallback (not a Media Library item).
	return array(
		'url'  => NSW_THEME_URI . 'assets/video/hero.mp4',
		'id'   => 0,
		'mime' => 'video/mp4',
	);
}

/**
 * The hero poster image URL (imported poster if available, else theme asset).
 */
function nsw_theme_hero_poster_url(): string {
	$pid = (int) get_option( 'nsw_theme_hero_poster_id' );
	$url = $pid ? wp_get_attachment_url( $pid ) : '';
	return $url ?: NSW_THEME_URI . 'assets/images/hero/hero-bg.jpg';
}
