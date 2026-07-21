<?php
/**
 * Plugin Name:       NSW Importer
 * Description:       One-time importer that seeds the Albanian National Single Window content (agencies, partners, documents, events, FAQ, news), pages, menus and Polylang languages into WordPress. Run once from Tools → NSW Setup, then deactivate.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            NSW Albania
 * License:           GPL-2.0-or-later
 * Text Domain:       nsw-importer
 *
 * @package NSW_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NSW_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'NSW_IMPORTER_DATA', NSW_IMPORTER_DIR . 'data/' );

require_once NSW_IMPORTER_DIR . 'includes/importer.php';

/**
 * Register the Tools → NSW Setup page.
 */
add_action(
	'admin_menu',
	function () {
		add_management_page(
			__( 'NSW Setup', 'nsw-importer' ),
			__( 'NSW Setup', 'nsw-importer' ),
			'manage_options',
			'nsw-setup',
			'nsw_importer_render_admin_page'
		);
	}
);

/**
 * Handle the run-import form submission before the page renders.
 */
function nsw_importer_maybe_run(): ?array {
	if ( empty( $_POST['nsw_importer_run'] ) ) {
		return null;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'nsw-importer' ) );
	}
	check_admin_referer( 'nsw_importer_run' );

	$clean = ! empty( $_POST['nsw_importer_clean'] );

	// Imports can create dozens of posts + sideload images; give it room.
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
	@set_time_limit( 300 );

	return nsw_importer_run( array( 'clean' => $clean ) );
}

/**
 * Render the admin page and (if submitted) the import log.
 */
function nsw_importer_render_admin_page(): void {
	$log         = nsw_importer_maybe_run();
	$polylang_on = function_exists( 'pll_languages_list' );
	$theme_ok    = 'nsw-theme' === get_option( 'stylesheet' ) || 'nsw-theme' === get_option( 'template' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'NSW Setup', 'nsw-importer' ); ?></h1>
		<p><?php esc_html_e( 'One-time importer for the Albanian National Single Window content. Running it is idempotent — records are matched by an import key and updated in place, so re-running never duplicates.', 'nsw-importer' ); ?></p>

		<h2><?php esc_html_e( 'Pre-flight', 'nsw-importer' ); ?></h2>
		<ul style="list-style:disc;margin-left:20px">
			<li>
				<?php echo $theme_ok
					? '✅ ' . esc_html__( 'NSW Theme is active.', 'nsw-importer' )
					: '⚠️ ' . esc_html__( 'NSW Theme is not active — activate it under Appearance → Themes first.', 'nsw-importer' ); ?>
			</li>
			<li>
				<?php echo $polylang_on
					? '✅ ' . esc_html__( 'Polylang is active (languages + translations will be configured).', 'nsw-importer' )
					: '⚠️ ' . esc_html__( 'Polylang is not active — content imports, but bilingual linking is skipped. Install Polylang first for the full setup.', 'nsw-importer' ); ?>
			</li>
		</ul>

		<form method="post">
			<?php wp_nonce_field( 'nsw_importer_run' ); ?>
			<p>
				<label>
					<input type="checkbox" name="nsw_importer_clean" value="1" />
					<?php esc_html_e( 'Clean first — delete previously imported posts/pages before importing.', 'nsw-importer' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" name="nsw_importer_run" value="1" class="button button-primary">
					<?php esc_html_e( 'Run import', 'nsw-importer' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>" class="button" style="margin-left:8px">
					<?php esc_html_e( 'Flush permalinks', 'nsw-importer' ); ?>
				</a>
			</p>
		</form>

		<?php if ( is_array( $log ) ) : ?>
			<h2><?php esc_html_e( 'Import log', 'nsw-importer' ); ?></h2>
			<textarea readonly rows="24" style="width:100%;font-family:monospace;font-size:12px"><?php
				echo esc_textarea( implode( "\n", $log ) );
			?></textarea>
			<p><em><?php esc_html_e( 'Done. Visit the site — then deactivate this plugin (it is only needed for the initial seed).', 'nsw-importer' ); ?></em></p>
		<?php endif; ?>
	</div>
	<?php
}
