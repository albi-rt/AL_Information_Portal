<?php
/**
 * Unit tests for inc/enqueue.php: the stylesheet and scripts the theme adds to pages.
 *
 * On public pages it loads the theme stylesheet and script and hands the script what it
 * needs for the contact form: the address of the contact service, the security code to
 * send with a message, the page language and the texts the form shows. In the block
 * editor it loads the preview script and hands it the editable fields of each NSW block,
 * with the field hints in the language of the page being edited.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.2.3 (form messages) and 2.4.2 (contact interface), and the Software
 * Development Package (SDP), sections 2.3 (versioned file addresses) and 2.5 (no
 * third-party script library). Where today's code differs, the test expects the
 * document and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class EnqueueTest extends TestCase {

	/** Calls made to WordPress during a test, by function name. */
	private array $calls = array();

	/** The preview language in force when the block field list was built. */
	private ?string $locale_while_building_fields = null;

	private bool $had_get_post = false;
	private $saved_get_post = null;
	private bool $had_preview_locale = false;
	private $saved_preview_locale = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_get_post         = array_key_exists( 'post', $_GET );
		$this->saved_get_post       = $_GET['post'] ?? null;
		$this->had_preview_locale   = array_key_exists( 'nsw_theme_preview_locale', $GLOBALS );
		$this->saved_preview_locale = $GLOBALS['nsw_theme_preview_locale'] ?? null;
		unset( $_GET['post'], $GLOBALS['nsw_theme_preview_locale'] );
	}

	protected function tearDown(): void {
		unset( $_GET['post'], $GLOBALS['nsw_theme_preview_locale'] );
		if ( $this->had_get_post ) {
			$_GET['post'] = $this->saved_get_post;
		}
		if ( $this->had_preview_locale ) {
			$GLOBALS['nsw_theme_preview_locale'] = $this->saved_preview_locale;
		}
		parent::tearDown();
	}

	/** The callback that inc/enqueue.php registered on a hook. */
	private function enqueue_callback( string $hook ): callable {
		return nsw_test_hook_callback(
			$hook,
			0,
			static fn( array $reg ): bool => $reg['callback'] instanceof \Closure
				&& str_ends_with( str_replace( '\\', '/', (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName() ), '/inc/enqueue.php' )
		);
	}

	/** Records every call to the given WordPress functions in $this->calls. */
	private function record( string ...$functions ): void {
		foreach ( $functions as $fn ) {
			Functions\when( $fn )->alias(
				function ( ...$args ) use ( $fn ) {
					$this->calls[ $fn ][] = $args;
					return true;
				}
			);
		}
	}

	/** Runs the public page set-up for a page in the given language and returns the data handed to the script. */
	private function run_public_page( string $locale = 'sq' ): array {
		$this->record( 'wp_enqueue_style', 'wp_enqueue_script', 'wp_localize_script' );
		Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://nsw.test/wp-json/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action = -1 ) => 'wp_rest' === $action ? '0a1b2c3d4e' : 'wrong-action' );
		Functions\when( 'nsw_theme_current_locale' )->justReturn( $locale );

		$this->enqueue_callback( 'wp_enqueue_scripts' )();

		$this->assertCount( 1, $this->calls['wp_localize_script'] );
		[ $handle, $name, $data ] = $this->calls['wp_localize_script'][0];
		$this->assertSame( 'nsw-theme-main', $handle );
		$this->assertSame( 'NSW_THEME', $name );
		return $data;
	}

	/** Two NSW blocks with editable fields, as the block field list describes them. */
	private static function block_fields(): array {
		return array(
			'nsw-theme/hero-home' => array(
				array( 'attr' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => 'Transforming Albanian Trade' ),
				array( 'attr' => 'cta1Url', 'label' => 'Button 1 link', 'type' => 'url', 'default' => '' ),
			),
			'nsw-theme/cta'       => array(
				array( 'attr' => 'buttonText', 'label' => 'Button label', 'type' => 'text', 'default' => 'Contact Us' ),
			),
		);
	}

	/** Simulates the language plugin: the edited page's language, the default language and translations. */
	private function language_plugin( $page_language, string $default_language = 'sq' ): void {
		Functions\when( 'pll_get_post_language' )->alias( static fn( $id, $field = 'slug' ) => 12 === $id ? $page_language : false );
		Functions\when( 'pll_default_language' )->justReturn( $default_language );
		Functions\when( 'pll_translate_string' )->alias( static fn( $text, $lang ) => "[$lang] $text" );
		Functions\when( 'get_the_ID' )->justReturn( false );
	}

	/**
	 * Runs the block editor set-up and returns the block configuration handed to the editor.
	 *
	 * @return array<string, array{title: string, fields: array}>
	 */
	private function run_block_editor( ?array $fields = null ): array {
		$this->record( 'wp_enqueue_script', 'wp_add_inline_script' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		$fields = $fields ?? self::block_fields();
		Functions\when( 'nsw_theme_block_fields' )->alias(
			function () use ( $fields ) {
				$this->locale_while_building_fields = nsw_theme_get_preview_locale();
				return $fields;
			}
		);

		$this->enqueue_callback( 'enqueue_block_editor_assets' )();

		$this->assertCount( 1, $this->calls['wp_add_inline_script'] );
		[ $handle, $script, $position ] = $this->calls['wp_add_inline_script'][0];
		$this->assertSame( 'nsw-theme-editor-preview', $handle );
		$this->assertSame( 'before', $position );
		$this->assertMatchesRegularExpression( '/^window\.NSW_BLOCKS = \{.*\};$/s', $script );
		return json_decode( substr( $script, strlen( 'window.NSW_BLOCKS = ' ), -1 ), true );
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

	/* ------------------------------------------------------------- public pages */

	/** Source: SDP 2.3, "Theme CSS, JavaScript and favicon URLs carry NSW_THEME_VERSION (e.g. main.css?ver=0.11.0)". */
	#[TestDox('Every public page loads the theme stylesheet and script, with the theme version in their addresses')]
	public function test_public_page_assets_are_versioned(): void {
		$this->run_public_page();

		$this->assertSame(
			array( array( 'nsw-theme-main', 'https://nsw.test/wp-content/themes/nsw-theme/assets/css/main.css', array(), NSW_THEME_VERSION ) ),
			$this->calls['wp_enqueue_style']
		);
		$this->assertSame( 'https://nsw.test/wp-content/themes/nsw-theme/assets/js/main.js', $this->calls['wp_enqueue_script'][0][1] );
		$this->assertSame( NSW_THEME_VERSION, $this->calls['wp_enqueue_script'][0][3] );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/css/main.css' );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/js/main.js' );
	}

	/** Source: SDP 2.5, "The theme scripts (assets/js/main.js, ...) use no third-party JavaScript library". */
	#[TestDox('The theme script needs no other script library and is loaded at the end of the page')]
	public function test_public_script_has_no_library_and_loads_last(): void {
		$this->run_public_page();

		$this->assertCount( 1, $this->calls['wp_enqueue_script'] );
		[ $handle, , $dependencies, , $in_footer ] = $this->calls['wp_enqueue_script'][0];
		$this->assertSame( 'nsw-theme-main', $handle );
		$this->assertSame( array(), $dependencies );
		$this->assertTrue( $in_footer );
	}

	/** Source: TSD 2.4.2, "POST /wp-json/nsw-theme/v1/contact (..., REST nonce in X-WP-Nonce)". */
	#[TestDox('Every public page gives the browser the address of the contact service and the security code to send with a message')]
	public function test_contact_address_and_security_code(): void {
		$data = $this->run_public_page();

		// The browser script adds "contact" to this address.
		$this->assertSame( 'https://nsw.test/wp-json/nsw-theme/v1/contact', $data['restUrl'] . 'contact' );
		// The contact service accepts only a code made for the "wp_rest" action.
		$this->assertSame( '0a1b2c3d4e', $data['nonce'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The browser is told whether the page is in Albanian or in English')]
	public function test_page_language_handed_to_browser(): void {
		$this->assertSame( 'sq', $this->run_public_page( 'sq' )['locale'] );
		$this->calls = array();
		$this->assertSame( 'en', $this->run_public_page( 'en' )['locale'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The browser receives the texts to show while a message is sending, after an error and when required fields are empty')]
	public function test_form_texts_handed_to_browser(): void {
		$texts = $this->run_public_page()['i18n'];

		$this->assertSame( array( 'submitting', 'sent', 'error', 'required' ), array_keys( $texts ) );
		$this->assertSame( 'Submitting…', $texts['submitting'] );
		$this->assertSame( 'Something went wrong. Please try again.', $texts['error'] );
		$this->assertSame( 'Please fill out all required fields.', $texts['required'] );
	}

	/** Source: TSD 2.2.3, input validation and messages, Success: “Message sent”. */
	#[TestDox('After a message is sent, the visitor sees the text "Message sent"')]
	public function test_success_text_is_message_sent(): void {
		$texts = $this->run_public_page()['i18n'];
		$this->assertSame( 'Message sent', rtrim( $texts['sent'], '.' ) );
	}

	/* ------------------------------------------------------------- block editor */

	/** Source: SDP 2.3 (versioned JavaScript URLs) and SDP 2.5, "the editor script uses the block editor packages that come with WordPress". */
	#[TestDox('The block editor loads the preview script with the theme version, using only the block editor parts that come with WordPress')]
	public function test_editor_script(): void {
		$this->language_plugin( false );
		$this->run_block_editor();

		$this->assertSame(
			array(
				array(
					'nsw-theme-editor-preview',
					'https://nsw.test/wp-content/themes/nsw-theme/assets/js/editor-preview.js',
					array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-dom-ready' ),
					NSW_THEME_VERSION,
					true,
				),
			),
			$this->calls['wp_enqueue_script']
		);
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/js/editor-preview.js' );
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('The block editor receives, for each NSW block, the fields an editor can change, with their labels and in their order')]
	public function test_editor_receives_block_fields_and_names(): void {
		$this->language_plugin( 'en' );
		$_GET['post'] = '12';

		$config = $this->run_block_editor();

		$this->assertSame( array( 'nsw-theme/hero-home', 'nsw-theme/cta' ), array_keys( $config ) );
		$this->assertSame( 'NSW Home Hero', $config['nsw-theme/hero-home']['title'] );
		$this->assertSame( 'NSW Call to Action', $config['nsw-theme/cta']['title'] );
		$this->assertSame( array( 'title', 'cta1Url' ), array_column( $config['nsw-theme/hero-home']['fields'], 'attr' ) );
		$this->assertSame( 'Button label', $config['nsw-theme/cta']['fields'][0]['label'] );
	}

	/**
	 * Source: theme behaviour, not specified in the TSD.
	 *
	 * The editor names are compared with the names the website itself gives the blocks when
	 * it registers them. The full partners page block is left out on purpose: its editor
	 * name is missing today, which is reported as a suspected fault, not tested as correct.
	 */
	#[TestDox('The editable home page sections have the same names in the block editor as the names the website gives them')]
	public function test_editor_block_names_match_website_names(): void {
		$sections = array(
			'nsw-theme/hero-home',
			'nsw-theme/stats',
			'nsw-theme/what-is-nsw',
			'nsw-theme/agencies-preview',
			'nsw-theme/news-latest',
			'nsw-theme/partners',
			'nsw-theme/cta',
		);
		$this->language_plugin( false );
		$config = $this->run_block_editor( array_fill_keys( $sections, array() ) );

		// The website's own block registration, run with the same block field list.
		$registered = array();
		Functions\when( 'register_block_type' )->alias(
			static function ( $name, $args = array() ) use ( &$registered ) {
				$registered[ $name ] = $args['title'] ?? '';
				return true;
			}
		);
		Functions\when( 'nsw_theme_block_attributes' )->justReturn( array() );
		nsw_test_hook_callback(
			'init',
			0,
			static fn( array $reg ): bool => $reg['callback'] instanceof \Closure
				&& str_ends_with( str_replace( '\\', '/', (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName() ), '/inc/blocks.php' )
		)();

		$this->assertSame( $sections, array_keys( $config ) );
		foreach ( $sections as $name ) {
			$this->assertNotSame( '', $registered[ $name ] ?? '', "$name is not registered by the website." );
			$this->assertSame( $registered[ $name ], $config[ $name ]['title'], "$name has another name in the editor." );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When an English page is edited, the field hints in the block editor are in English')]
	public function test_hints_follow_english_page(): void {
		$this->language_plugin( 'en' );
		$_GET['post'] = '12';

		$config = $this->run_block_editor();

		$this->assertSame( '[en] Transforming Albanian Trade', $config['nsw-theme/hero-home']['fields'][0]['default'] );
		$this->assertSame( '[en] Contact Us', $config['nsw-theme/cta']['fields'][0]['default'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When an Albanian page is edited, the field hints in the block editor are in Albanian, even if English is the default language')]
	public function test_hints_follow_albanian_page(): void {
		$this->language_plugin( 'sq', 'en' );
		$_GET['post'] = '12';

		$config = $this->run_block_editor();

		$this->assertSame( '[sq] Transforming Albanian Trade', $config['nsw-theme/hero-home']['fields'][0]['default'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When no single page is being edited, the field hints follow the default language of the site')]
	public function test_hints_follow_default_language_without_page(): void {
		$this->language_plugin( 'en', 'sq' );
		$this->assertSame( '[sq] Contact Us', $this->run_block_editor()['nsw-theme/cta']['fields'][0]['default'] );

		$this->calls = array();
		$this->language_plugin( 'sq', 'en' );
		$this->assertSame( '[en] Contact Us', $this->run_block_editor()['nsw-theme/cta']['fields'][0]['default'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the page being edited is found from the editor itself, its language is used')]
	public function test_page_found_from_the_editor(): void {
		$this->language_plugin( 'en', 'sq' );
		Functions\when( 'get_the_ID' )->justReturn( 12 );

		$this->assertSame( '[en] Contact Us', $this->run_block_editor()['nsw-theme/cta']['fields'][0]['default'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An invalid page number in the editor address is ignored and the default language is used')]
	public function test_invalid_page_number_uses_default_language(): void {
		$this->language_plugin( 'en', 'sq' );
		$_GET['post'] = 'not-a-number';

		$this->assertSame( '[sq] Contact Us', $this->run_block_editor()['nsw-theme/cta']['fields'][0]['default'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A page that has no language yet uses the default language of the site')]
	public function test_page_without_language_uses_default_language(): void {
		$this->language_plugin( false, 'en' );
		$_GET['post'] = '12';

		$this->assertSame( '[en] Contact Us', $this->run_block_editor()['nsw-theme/cta']['fields'][0]['default'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The field list is built from the English source texts, and the language setting used for previews is put back afterwards')]
	public function test_fields_built_in_english_and_preview_language_restored(): void {
		$this->language_plugin( 'sq' );
		$_GET['post'] = '12';
		nsw_theme_set_preview_locale( 'sq' );

		$this->run_block_editor();

		$this->assertSame( 'en', $this->locale_while_building_fields );
		$this->assertSame( 'sq', nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Empty hints and hints that are not text reach the editor unchanged, without asking for a translation')]
	public function test_empty_and_non_text_hints_unchanged(): void {
		Functions\when( 'pll_get_post_language' )->justReturn( 'sq' );
		Functions\when( 'pll_default_language' )->justReturn( 'sq' );
		Functions\expect( 'pll_translate_string' )->once()->with( 'Contact Us', 'sq' )->andReturn( 'Na kontaktoni' );
		$_GET['post'] = '12';
		$fields = array(
			'nsw-theme/cta' => array(
				array( 'attr' => 'buttonText', 'type' => 'text', 'default' => 'Contact Us' ),
				array( 'attr' => 'buttonUrl', 'type' => 'url', 'default' => '' ),
				array( 'attr' => 'bgMediaId', 'type' => 'number', 'default' => 42 ),
				array( 'attr' => 'note', 'type' => 'text' ),
			),
		);

		$out = $this->run_block_editor( $fields )['nsw-theme/cta']['fields'];

		$this->assertSame( 'Na kontaktoni', $out[0]['default'] );
		$this->assertSame( '', $out[1]['default'] );
		$this->assertSame( 42, $out[2]['default'] );
		$this->assertArrayNotHasKey( 'default', $out[3] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, the block editor receives the English source hints unchanged')]
	public function test_without_language_plugin_hints_unchanged(): void {
		$this->assertFalse( function_exists( 'pll_translate_string' ) );

		$config = $this->run_block_editor();

		$this->assertSame( 'Transforming Albanian Trade', $config['nsw-theme/hero-home']['fields'][0]['default'] );
		$this->assertSame( 'Contact Us', $config['nsw-theme/cta']['fields'][0]['default'] );
		$this->assertSame( 'en', $this->locale_while_building_fields );
		$this->assertNull( nsw_theme_get_preview_locale() );
	}

	/* ----------------------------------------------------------- direct access */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Opening the file that adds the stylesheet and scripts directly, outside WordPress, runs nothing and shows nothing')]
	public function test_direct_access_does_nothing(): void {
		[ $code, $output ] = self::run_outside_wordpress( 'inc/enqueue.php' );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $output );
	}
}
