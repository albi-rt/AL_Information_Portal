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
		// 'backfill-something-2026-08' => 'nsw_theme_migrate_backfill_something',
	);
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
