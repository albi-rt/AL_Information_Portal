<?php
/**
 * Run-once, idempotent data migrations.
 *
 * Definition changes (post types, taxonomies, meta registration, capabilities,
 * hooks, block behavior) are pure code and need NO migration — they take effect
 * the moment the theme deploys. This runner is only for reshaping EXISTING data
 * (backfills, one-time conversions), so such changes ride along with a git
 * deploy and self-apply on production without any DB export/import.
 *
 * How to add a migration:
 *   1. Add an entry to nsw_theme_migrations() keyed by a unique, stable slug.
 *   2. Make the callback IDEMPOTENT (safe to run twice).
 *   3. Test it on local.
 *   4. On deploy it runs once per site — recorded in the
 *      'nsw_theme_migrations_done' option — on the next admin page load.
 *   5. Once it has run everywhere, you may remove the entry; the recorded slug
 *      keeps it from re-running even if the code returns later.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The migration registry: [ 'slug' => callable ]. Runs in array order.
 * Empty by default — add an entry here only when EXISTING data must be reshaped.
 *
 * @return array<string, callable|string>
 */
function nsw_theme_migrations(): array {
	return array(
		'create-services-page-2026-08' => 'nsw_theme_migrate_create_services_page',
	);
}

/**
 * Create the Services list ("wizard") page in each language and link the pair as
 * Polylang translations. The page body is a single nsw-theme/services block, and
 * the page uses the "page-plain" template — like every other data page (FAQ,
 * Documents, Events, Agencies) — because the default page.html already renders
 * nsw-theme/page-hero and the services block prepends its own data hero, so the
 * default template would show the hero twice. Titles and subtitles are hardcoded
 * per language (the block itself is language-agnostic; it queries the active
 * language's services). Idempotent: an existing page at the slug is reused, never
 * duplicated (and self-healed onto the page-plain template if it lacks it), and
 * the translation link is re-saved harmlessly.
 */
function nsw_theme_migrate_create_services_page(): void {
	$pages = array(
		'sq' => array(
			'slug'     => 'sherbime',
			'title'    => 'Shërbimet',
			'subtitle' => 'Gjeni hapat, dokumentet dhe tarifat për çdo operacion importi, eksporti ose tranziti.',
		),
		'en' => array(
			'slug'     => 'services',
			'title'    => 'Services',
			'subtitle' => 'Find the steps, documents and fees for any import, export or transit operation.',
		),
	);

	$ids = array();
	foreach ( $pages as $lang => $def ) {
		$existing = get_page_by_path( $def['slug'] );
		if ( $existing instanceof WP_Post ) {
			$page_id = (int) $existing->ID;
			// Self-heal: an already-existing page must also use the plain
			// template, or the hero renders twice (page-hero + the block's own).
			if ( 'page-plain' !== get_post_meta( $page_id, '_wp_page_template', true ) ) {
				update_post_meta( $page_id, '_wp_page_template', 'page-plain' );
			}
		} else {
			$page_id = wp_insert_post(
				array(
					'post_type'     => 'page',
					'post_name'     => $def['slug'],
					'post_title'    => $def['title'],
					'post_excerpt'  => $def['subtitle'],
					'post_status'   => 'publish',
					'post_content'  => '<!-- wp:nsw-theme/services /-->',
					'page_template' => 'page-plain',
				),
				true
			);
			if ( is_wp_error( $page_id ) || ! $page_id ) {
				continue;
			}
			$page_id = (int) $page_id;
		}
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $page_id, $lang );
		}
		$ids[ $lang ] = $page_id;
	}

	if ( count( $ids ) === count( $pages ) && function_exists( 'pll_save_post_translations' ) ) {
		pll_save_post_translations( $ids );
	}
}

/**
 * Run any pending migrations, once per site. Fires on admin page loads by an
 * administrator; each migration slug is recorded so it never runs twice, and a
 * short transient lock prevents two concurrent admins from double-running.
 */
add_action(
	'admin_init',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$migrations = nsw_theme_migrations();
		if ( empty( $migrations ) ) {
			return;
		}
		if ( get_transient( 'nsw_theme_migrating' ) ) {
			return;
		}
		set_transient( 'nsw_theme_migrating', 1, 2 * MINUTE_IN_SECONDS );

		$done    = (array) get_option( 'nsw_theme_migrations_done', array() );
		$changed = false;
		foreach ( $migrations as $slug => $callback ) {
			if ( in_array( $slug, $done, true ) || ! is_callable( $callback ) ) {
				continue;
			}
			try {
				call_user_func( $callback );
				$done[]  = $slug;
				$changed = true;
			} catch ( \Throwable $e ) {
				// Leave it unrecorded so it retries on the next load.
				error_log( '[nsw-theme] migration "' . $slug . '" failed: ' . $e->getMessage() );
			}
		}
		if ( $changed ) {
			update_option( 'nsw_theme_migrations_done', array_values( array_unique( $done ) ), false );
		}
		delete_transient( 'nsw_theme_migrating' );
	}
);
