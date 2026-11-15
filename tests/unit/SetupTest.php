<?php
/**
 * Unit tests for inc/setup.php: the theme set-up.
 *
 * It adds the NSW favicon to every page, switches on the WordPress features the theme
 * uses (page titles, featured images, site logo, editor styles and so on), loads the
 * public stylesheet into the block editor, defines the two image sizes the theme cuts,
 * and marks every page with the theme name and the page language. The file also covers
 * what the theme puts in the head of every page, so the "no generator tag" rule of the
 * production hardening is checked here.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * section 2.5.3 (production hardening), and the Software Development Package (SDP),
 * section 2.3 (versioned file addresses). Where today's code differs, the test expects
 * the document and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

final class SetupTest extends TestCase {

	/** Calls made to WordPress during a test, by function name. */
	private array $calls = array();

	/** The callback that inc/setup.php registered on a hook. */
	private function setup_callback( string $hook ): callable {
		return nsw_test_hook_callback(
			$hook,
			0,
			static fn( array $reg ): bool => $reg['callback'] instanceof \Closure
				&& str_ends_with( str_replace( '\\', '/', (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName() ), '/inc/setup.php' )
		);
	}

	/** Runs the theme set-up (after_setup_theme) and records what it asked WordPress to do. */
	private function run_theme_setup(): void {
		foreach ( array( 'load_theme_textdomain', 'add_theme_support', 'add_editor_style', 'add_image_size' ) as $fn ) {
			Functions\when( $fn )->alias(
				function ( ...$args ) use ( $fn ) {
					$this->calls[ $fn ][] = $args;
					return true;
				}
			);
		}
		$this->setup_callback( 'after_setup_theme' )();
	}

	/** @return array<string, mixed> feature => its settings (true when it has none) */
	private function theme_supports(): array {
		$supports = array();
		foreach ( $this->calls['add_theme_support'] ?? array() as $args ) {
			$supports[ $args[0] ] = $args[1] ?? true;
		}
		return $supports;
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

	/* ------------------------------------------------------------------ favicon */

	/** Source: SDP 2.3, "Theme CSS, JavaScript and favicon URLs carry NSW_THEME_VERSION". */
	#[TestDox('Every page links the NSW favicon, with the theme version in its address')]
	public function test_favicon_link_carries_the_version(): void {
		Functions\when( 'has_site_icon' )->justReturn( false );

		ob_start();
		$this->setup_callback( 'wp_head' )();
		$html = ob_get_clean();

		$this->assertSame(
			'<link rel="icon" type="image/svg+xml" href="https://nsw.test/wp-content/themes/nsw-theme/assets/images/logos/favicon.svg?v='
				. NSW_THEME_VERSION . '" />' . "\n",
			$html
		);
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/images/logos/favicon.svg' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When a site icon is set in WordPress, the theme adds no favicon of its own')]
	public function test_no_favicon_when_site_icon_is_set(): void {
		Functions\when( 'has_site_icon' )->justReturn( true );

		ob_start();
		$this->setup_callback( 'wp_head' )();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Source: TSD 2.5.3, production hardening, "no X-Powered-By or generator tag"; TSD 2.1.2,
	 * "Only the theme is custom code", so the theme is where the tag can be removed.
	 *
	 * The test applies the theme's filters on the generator text to the page head tag and to
	 * the feed tag. A fix that empties that text passes; a fix that only takes the tag out of
	 * the page head would still leave it in the feed.
	 */
	#[Group('tsd-difference')]
	#[TestDox('The pages and the news feed do not show which WordPress version the site runs')]
	public function test_generator_tag_is_removed(): void {
		$tags = array(
			'xhtml' => '<meta name="generator" content="WordPress 7.1.4" />',
			'rss2'  => '<generator>https://wordpress.org/?v=7.1.4</generator>',
		);
		foreach ( $tags as $type => $tag ) {
			foreach ( nsw_test_hook_registrations( 'the_generator' ) as $reg ) {
				$tag = call_user_func_array( $reg['callback'], array_slice( array( $tag, $type ), 0, max( 1, $reg['accepted_args'] ) ) );
			}
			$this->assertSame( '', (string) $tag, "The $type generator tag is still output." );
		}
	}

	/* ------------------------------------------------------------- theme set-up */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The theme switches on page titles, featured images, feed links, responsive embeds, editor styles, block styles and wide alignment')]
	public function test_theme_features_switched_on(): void {
		$this->run_theme_setup();
		$supports = $this->theme_supports();

		foreach ( array( 'title-tag', 'post-thumbnails', 'automatic-feed-links', 'responsive-embeds', 'editor-styles', 'wp-block-styles', 'align-wide' ) as $feature ) {
			$this->assertArrayHasKey( $feature, $supports, "$feature is not switched on." );
			$this->assertTrue( $supports[ $feature ] );
		}
		$this->assertCount( 9, $supports );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Search forms, comments, galleries, captions, styles and scripts are written as modern HTML')]
	public function test_html5_markup(): void {
		$this->run_theme_setup();
		$this->assertSame(
			array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ),
			$this->theme_supports()['html5']
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A site logo can be set in WordPress, sized 185 by 60 pixels, with flexible width and height')]
	public function test_site_logo_support(): void {
		$this->run_theme_setup();
		$this->assertSame(
			array( 'height' => 60, 'width' => 185, 'flex-height' => true, 'flex-width' => true ),
			$this->theme_supports()['custom-logo']
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The block editor uses the same stylesheet as the public pages, plus a small editor-only stylesheet')]
	public function test_editor_uses_the_public_stylesheet(): void {
		$this->run_theme_setup();

		$this->assertSame( array( array( array( 'assets/css/main.css', 'assets/css/editor.css' ) ) ), $this->calls['add_editor_style'] );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/css/main.css' );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/css/editor.css' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Uploaded images are also cut to a card size of 800 by 540 pixels and a banner size of 1920 by 1080 pixels')]
	public function test_image_sizes(): void {
		$this->run_theme_setup();
		$this->assertSame(
			array(
				array( 'nsw-theme-card', 800, 540, true ),
				array( 'nsw-theme-hero', 1920, 1080, true ),
			),
			$this->calls['add_image_size']
		);
	}

	/* --------------------------------------------------------------- page class */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An Albanian page is marked with the theme name and the Albanian language, keeping the classes it already has')]
	public function test_body_class_albanian_page(): void {
		Functions\when( 'nsw_theme_current_locale' )->justReturn( 'sq' );
		$this->assertSame(
			array( 'home', 'page', 'nsw-theme', 'lang-sq' ),
			$this->setup_callback( 'body_class' )( array( 'home', 'page' ) )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An English page is marked with the English language')]
	public function test_body_class_english_page(): void {
		Functions\when( 'nsw_theme_current_locale' )->justReturn( 'en' );
		$this->assertSame( array( 'nsw-theme', 'lang-en' ), $this->setup_callback( 'body_class' )( array() ) );
	}

	/* ----------------------------------------------------------- direct access */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Opening the theme set-up file directly, outside WordPress, runs nothing and shows nothing')]
	public function test_direct_access_does_nothing(): void {
		[ $code, $output ] = self::run_outside_wordpress( 'inc/setup.php' );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $output );
	}
}
