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
 * Create the Services list ("wizard") page in every Polylang language and link
 * them as translations of one another. The page body is a single
 * nsw-theme/services block, and the page uses the "page-plain" template — like
 * every other data page (FAQ, Documents, Events, Agencies) — because the default
 * page.html already renders nsw-theme/page-hero and the services block prepends
 * its own data hero, so the default template would show the hero twice.
 *
 * Nothing here is language-specific: the language list comes from Polylang, the
 * slug from nsw_theme_path_slugs()['services'] (English slug as the fallback for
 * a language with no entry), and the title/subtitle from the English source
 * strings run through Polylang for that language. A language whose slug would
 * collide with one already created is skipped, so two pages never fight over the
 * same permalink. Idempotent: an existing page at the slug is reused, never
 * duplicated (and self-healed onto the page-plain template if it lacks it), and
 * the translation links are re-saved harmlessly.
 */
function nsw_theme_migrate_create_services_page(): void {
	$default = nsw_theme_default_locale();
	$langs   = nsw_theme_locales();
	if ( empty( $langs ) ) {
		$langs = array( $default );
	}
	// Default language first, so which language wins a shared slug is
	// deterministic rather than dependent on Polylang's term ordering.
	$langs = array_values( array_unique( array_merge( array( $default ), $langs ) ) );

	$ids   = array();
	$taken = array();
	$prev  = nsw_theme_get_preview_locale();

	foreach ( $langs as $lang ) {
		$slug = (string) nsw_theme_path_slug( 'services', $lang );
		if ( '' === $slug || isset( $taken[ $slug ] ) ) {
			// Another language already claimed this slug (it has no localized
			// entry of its own and fell back to English) — skip it rather than
			// create a second page on the same path.
			continue;
		}
		$taken[ $slug ] = true;

		// Title/subtitle in this language: English source, translated by Polylang.
		nsw_theme_set_preview_locale( $lang );
		$title    = nsw_theme_t( 'nav.services', 'Services' );
		$subtitle = nsw_theme_t( 'servicesPage.subtitle', 'Find the steps, documents and fees for any import, export or transit operation.' );

		$existing = get_page_by_path( $slug );
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
					'post_name'     => $slug,
					'post_title'    => $title,
					'post_excerpt'  => $subtitle,
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

	nsw_theme_set_preview_locale( $prev );

	if ( count( $ids ) > 1 && function_exists( 'pll_save_post_translations' ) ) {
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
