<?php
/**
 * Bootstrap for the Information Portal unit tests.
 *
 * Loads the test tools, makes WordPress function stand-ins possible (Brain Monkey),
 * defines minimal stand-ins for the WordPress classes the theme uses, records the
 * hooks the theme registers at load time, and then loads the theme through its own
 * functions.php so every file under inc/ is loaded exactly once.
 *
 * Nothing here talks to a database, a web server or the network.
 */

declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Patchwork must be active before any theme file is loaded, so that Brain Monkey can
// replace the WordPress functions the theme calls.
require_once dirname( __DIR__, 2 ) . '/vendor/brain/monkey/inc/patchwork-loader.php';

define( 'NSW_TEST_THEME_DIR', dirname( __DIR__, 2 ) . '/wp-content/themes/nsw-theme/' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
// functions.php only defines these when they are missing, so it does not need
// get_template_directory() during the tests.
define( 'NSW_THEME_DIR', NSW_TEST_THEME_DIR );
define( 'NSW_THEME_URI', 'https://nsw.test/wp-content/themes/nsw-theme/' );

require __DIR__ . '/support/hook-recorder.php';
require __DIR__ . '/support/wp-classes.php';
require __DIR__ . '/support/TestCase.php';

/**
 * Loads the theme through its own functions.php, exactly as WordPress does, once per
 * PHP process. TestCase::setUp() calls it, so the loading runs inside the first test
 * and its lines (hook registrations at the top of each file) count in the coverage.
 */
function nsw_test_load_theme(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;
	require NSW_TEST_THEME_DIR . 'functions.php';
}
