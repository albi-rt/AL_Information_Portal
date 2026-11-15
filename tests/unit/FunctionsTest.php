<?php
/**
 * Unit tests for functions.php: the file WordPress runs first when it starts the theme.
 *
 * It sets the theme version and the theme's folder and web address, then loads every
 * part of the theme from the inc folder in a fixed order. The tests check the version,
 * that it matches the version shown in WordPress, that every part is loaded, that the
 * parts which need the content type names load after them, and that the file does
 * nothing when it is opened directly instead of through WordPress.
 *
 * Expected behaviour comes from the Information Portal Software Development Package
 * (SDP), section 2.3 (versioning). Where today's code differs, the test expects the
 * document and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

final class FunctionsTest extends TestCase {

	/** The theme files loaded in this PHP process, as paths inside the theme folder, in load order. */
	private function loaded_theme_files(): array {
		$theme = str_replace( '\\', '/', (string) realpath( NSW_TEST_THEME_DIR ) ) . '/';
		$files = array();
		foreach ( get_included_files() as $path ) {
			$path = str_replace( '\\', '/', (string) realpath( $path ) );
			if ( str_starts_with( $path, $theme ) ) {
				$files[] = substr( $path, strlen( $theme ) );
			}
		}
		return $files;
	}

	/** Runs one theme file on its own with the PHP command line, outside WordPress. */
	private static function run_outside_wordpress( string $relative_path ): array {
		$output = array();
		$code   = -1;
		exec(
			escapeshellarg( PHP_BINARY ) . ' -n -d display_errors=1 -d error_reporting=-1 '
				. escapeshellarg( NSW_TEST_THEME_DIR . $relative_path ) . ' 2>&1',
			$output,
			$code
		);
		return array( $code, implode( "\n", $output ) );
	}

	/** The Version line of the style.css header, which WordPress shows under Appearance, Themes. */
	private static function stylesheet_version(): string {
		$header = (string) file_get_contents( NSW_TEST_THEME_DIR . 'style.css', false, null, 0, 8192 );
		return preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $header, $m ) ? trim( $m[1] ) : '';
	}

	/* ------------------------------------------------------------------ version */

	/** Source: SDP 2.3, version scheme "vMAJOR.MINOR.PATCH". */
	#[TestDox('The theme version is made of three numbers: major, minor and patch')]
	public function test_version_is_major_minor_patch(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', NSW_THEME_VERSION );
	}

	/**
	 * Source: SDP 2.3, "Before tagging, the style.css header and NSW_THEME_VERSION are set to
	 * the tag version"; wp-admin "shows the style.css version". SDP 2.3 itself notes that the
	 * header "still says 0.10.0" today.
	 */
	#[Group('tsd-difference')]
	#[TestDox('The theme version shown in WordPress is the same as the version in the addresses of the theme files')]
	public function test_stylesheet_version_matches_theme_version(): void {
		$this->assertSame( NSW_THEME_VERSION, self::stylesheet_version() );
	}

	/* ------------------------------------------------------- folder and address */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A theme folder and web address set before the theme starts are kept as they are')]
	public function test_folder_and_address_set_beforehand_are_kept(): void {
		$this->assertSame( NSW_TEST_THEME_DIR, NSW_THEME_DIR );
		$this->assertSame( 'https://nsw.test/wp-content/themes/nsw-theme/', NSW_THEME_URI );
	}

	/* ------------------------------------------------------------ loading order */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Every part of the theme in the inc folder is loaded when the theme starts')]
	public function test_every_inc_file_is_loaded(): void {
		$loaded = $this->loaded_theme_files();
		$parts  = array_map(
			static fn( string $path ): string => 'inc/' . basename( $path ),
			glob( NSW_TEST_THEME_DIR . 'inc/*.php' )
		);

		$start = array_search( 'functions.php', $loaded, true );

		$this->assertNotEmpty( $parts );
		$this->assertIsInt( $start );
		foreach ( $parts as $part ) {
			$this->assertContains( $part, $loaded, "$part is not loaded." );
			$this->assertGreaterThan( $start, array_search( $part, $loaded, true ), "$part is loaded before functions.php." );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The content types are set up before the parts of the theme that use their names when they load')]
	public function test_content_types_load_before_their_users(): void {
		$loaded     = $this->loaded_theme_files();
		$post_types = array_search( 'inc/post-types.php', $loaded, true );

		$this->assertIsInt( $post_types );
		$this->assertGreaterThan( $post_types, array_search( 'inc/meta-boxes.php', $loaded, true ) );
		$this->assertGreaterThan( $post_types, array_search( 'inc/admin.php', $loaded, true ) );
	}

	/* ----------------------------------------------------------- direct access */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Opening the theme start-up file directly, outside WordPress, runs nothing and shows nothing')]
	public function test_direct_access_does_nothing(): void {
		[ $code, $output ] = self::run_outside_wordpress( 'functions.php' );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $output );
	}
}
