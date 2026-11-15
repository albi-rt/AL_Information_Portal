<?php
/**
 * Unit tests for inc/i18n.php: how the website chooses between Albanian and English,
 * and how interface texts are translated.
 *
 * The language plugin (Polylang) is never loaded: its functions are simulated in each
 * test. Expected behaviour comes from the Information Portal Technical Specification
 * (TSD), sections 2.2.1 (Languages component), 2.2.2 (Multilingual text) and 2.2.3
 * (Languages; contact form and FAQ page messages), and from the Software Development Package (SDP),
 * section 5.5 (theme texts in the group "NSW Theme"). Where today's code differs from
 * the TSD, the test expects the TSD and is in the group "tsd-difference".
 *
 * Tests that need the language plugin to be absent run in a separate PHP process: once
 * a test has simulated a plugin function, that function exists until the process ends.
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class I18nTest extends TestCase {

	private bool $had_preview_locale = false;
	private $saved_preview_locale = null;
	private bool $had_accept_language = false;
	private $saved_accept_language = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_preview_locale    = array_key_exists( 'nsw_theme_preview_locale', $GLOBALS );
		$this->saved_preview_locale  = $GLOBALS['nsw_theme_preview_locale'] ?? null;
		$this->had_accept_language   = array_key_exists( 'HTTP_ACCEPT_LANGUAGE', $_SERVER );
		$this->saved_accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		unset( $GLOBALS['nsw_theme_preview_locale'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['nsw_theme_preview_locale'], $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		if ( $this->had_preview_locale ) {
			$GLOBALS['nsw_theme_preview_locale'] = $this->saved_preview_locale;
		}
		if ( $this->had_accept_language ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $this->saved_accept_language;
		}
		parent::tearDown();
	}

	/** The callback that inc/i18n.php registered on a hook. */
	private function i18n_callback( string $hook ): callable {
		return nsw_test_hook_callback(
			$hook,
			0,
			static fn( array $reg ): bool => $reg['callback'] instanceof \Closure
				&& str_ends_with( (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName(), '/inc/i18n.php' )
		);
	}

	/** Simulates the language plugin on a page in the given language ('sq', 'en', or false for none). */
	private function plugin_page_language( $slug ): void {
		Functions\when( 'pll_current_language' )->alias( static fn( $field = 'slug' ) => 'slug' === $field ? $slug : false );
	}

	/** The theme's English catalogue, read from the file the theme ships. */
	private static function english_catalogue(): array {
		return require NSW_TEST_THEME_DIR . 'languages/content-en.php';
	}

	/** A small invented catalogue in the same shape as the theme's English catalogue. */
	private static function sample_catalogue(): array {
		return array(
			'common'      => array( 'learnMore' => 'Learn More' ),
			'contactPage' => array( 'form' => array( 'submit' => 'Send Message' ) ),
		);
	}

	/**
	 * Runs the start-up registration of interface texts with the language plugin present.
	 * Returns what was registered, by name: value, group and multi-line flag.
	 *
	 * @param array<int, mixed> $asked_locales filled with the languages the catalogue was asked for
	 */
	private function run_string_registration( array $catalogue, array &$asked_locales = array() ): array {
		$registered = array();
		Functions\when( 'nsw_theme_get_content' )->alias(
			static function ( $locale = null ) use ( $catalogue, &$asked_locales ) {
				$asked_locales[] = $locale;
				return $catalogue;
			}
		);
		Functions\when( 'pll_register_string' )->alias(
			static function ( $name, $string, $group = 'Polylang', $multiline = false ) use ( &$registered ) {
				$registered[ $name ] = array( 'value' => $string, 'group' => $group, 'multiline' => $multiline );
			}
		);
		$this->i18n_callback( 'init' )();
		return $registered;
	}

	/** An editor preview request for a post, as the block editor sends it. */
	private function preview_request( int $post_id, string $route = '/wp/v2/block-renderer/nsw-theme/hero' ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', $route );
		if ( $post_id > 0 ) {
			$request->set_param( 'post_id', $post_id );
		}
		return $request;
	}

	/* ------------------------------------------------------------ language plugin */

	/** Source: TSD 2.2.1, Languages component "Polylang; inc/i18n.php". */
	#[TestDox('The website notices when the language plugin is active')]
	public function test_plugin_detected(): void {
		$this->plugin_page_language( 'sq' );
		$this->assertTrue( nsw_theme_has_polylang() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The website notices when the language plugin is not installed')]
	public function test_plugin_absent(): void {
		$this->assertFalse( nsw_theme_has_polylang() );
	}

	/* ------------------------------------------------------------ page language */

	/** Source: TSD 2.2.2, "Albanian (default, https://nsw.al/) and English (/en/)". */
	#[TestDox('A page the language plugin marks as English is shown in English')]
	public function test_english_page(): void {
		$this->plugin_page_language( 'en' );
		$this->assertSame( 'en', nsw_theme_current_locale() );
	}

	/** Source: TSD 2.2.2, "Albanian (default, https://nsw.al/) and English (/en/)". */
	#[TestDox('A page the language plugin marks as Albanian is shown in Albanian')]
	public function test_albanian_page(): void {
		$this->plugin_page_language( 'sq' );
		$this->assertSame( 'sq', nsw_theme_current_locale() );
	}

	/** Source: TSD 2.2.1, "Albanian (default) and English versions". */
	#[TestDox('Any language other than English is treated as Albanian, the default language')]
	public function test_other_language_is_albanian(): void {
		$this->plugin_page_language( 'de' );
		$this->assertSame( 'sq', nsw_theme_current_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the language plugin gives no language, the WordPress language setting decides')]
	public function test_no_plugin_language_uses_wordpress_setting(): void {
		$this->plugin_page_language( false );

		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		$this->assertSame( 'en', nsw_theme_current_locale() );

		Functions\when( 'determine_locale' )->justReturn( 'en_GB' );
		$this->assertSame( 'en', nsw_theme_current_locale() );

		Functions\when( 'determine_locale' )->justReturn( 'sq_AL' );
		$this->assertSame( 'sq', nsw_theme_current_locale() );
	}

	/** Source: TSD 2.2.2, "Albanian (default, https://nsw.al/)". */
	#[TestDox('Without the language plugin, an empty or unknown language setting gives Albanian')]
	public function test_without_plugin_unknown_setting_is_albanian(): void {
		Functions\when( 'nsw_theme_has_polylang' )->justReturn( false );

		Functions\when( 'determine_locale' )->justReturn( '' );
		$this->assertSame( 'sq', nsw_theme_current_locale() );

		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		$this->assertSame( 'sq', nsw_theme_current_locale() );
	}

	/** Source: TSD 2.2.2, "Language from the URL, not the browser". */
	#[TestDox('The language set in the visitor\'s browser does not change the language of the page')]
	public function test_browser_language_ignored(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		$this->plugin_page_language( 'sq' );
		$this->assertSame( 'sq', nsw_theme_current_locale() );

		$this->plugin_page_language( false );
		Functions\when( 'determine_locale' )->justReturn( 'sq_AL' );
		$this->assertSame( 'sq', nsw_theme_current_locale() );
	}

	/* ----------------------------------------------------------- editor preview */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An editor preview set to English is shown in English even when the page language is Albanian')]
	public function test_preview_language_wins(): void {
		$this->plugin_page_language( 'sq' );
		nsw_theme_set_preview_locale( 'en' );
		$this->assertSame( 'en', nsw_theme_current_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Only English or Albanian can be set as the language of an editor preview')]
	public function test_preview_language_values(): void {
		nsw_theme_set_preview_locale( 'sq' );
		$this->assertSame( 'sq', nsw_theme_get_preview_locale() );

		nsw_theme_set_preview_locale( 'fr' );
		$this->assertNull( nsw_theme_get_preview_locale() );

		nsw_theme_set_preview_locale( 'en' );
		nsw_theme_set_preview_locale( null );
		$this->assertNull( nsw_theme_get_preview_locale() );
	}

	/* ------------------------------------------------------------ post language */

	/** Source: TSD 2.2.2, "Each translation is a separate linked item". */
	#[TestDox('The language of a news item or page is the one the language plugin records for it')]
	public function test_post_language_from_plugin(): void {
		$asked = array();
		Functions\when( 'pll_get_post_language' )->alias(
			static function ( $post_id, $field = 'slug' ) use ( &$asked ) {
				$asked[] = array( $post_id, $field );
				return 41 === $post_id ? 'en' : 'sq';
			}
		);
		$this->assertSame( 'en', nsw_theme_post_locale( 41 ) );
		$this->assertSame( 'sq', nsw_theme_post_locale( 42 ) );
		$this->assertSame( array( array( 41, 'slug' ), array( 42, 'slug' ) ), $asked );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An item without a recorded language takes the language of the current page')]
	public function test_post_without_language_takes_page_language(): void {
		$this->plugin_page_language( 'en' );

		Functions\when( 'pll_get_post_language' )->justReturn( false );
		$this->assertSame( 'en', nsw_theme_post_locale( 43 ) );

		Functions\when( 'pll_get_post_language' )->justReturn( 'de' );
		$this->assertSame( 'en', nsw_theme_post_locale( 43 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, an item takes the language of the current page')]
	public function test_post_language_without_plugin(): void {
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		$this->assertSame( 'en', nsw_theme_post_locale( 44 ) );
	}

	/* --------------------------------------------------------- catalogue lookup */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A text is found by its name, even when it sits several levels deep in a list of texts')]
	public function test_dotted_lookup_found(): void {
		$data = array(
			'hero' => array( 'title' => 'Welcome', 'links' => array( 'First', 'Second' ) ),
		);
		$this->assertSame( 'Welcome', nsw_theme_dot_get( $data, 'hero.title' ) );
		$this->assertSame( 'Second', nsw_theme_dot_get( $data, 'hero.links.1' ) );
		$this->assertSame( array( 'First', 'Second' ), nsw_theme_dot_get( $data, 'hero.links' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Looking up a text name that does not exist gives the fallback value')]
	public function test_dotted_lookup_missing(): void {
		$data = array( 'hero' => array( 'title' => 'Welcome' ) );
		$this->assertSame( 'none', nsw_theme_dot_get( $data, 'hero.subtitle', 'none' ) );
		$this->assertSame( 'none', nsw_theme_dot_get( $data, 'hero.title.extra', 'none' ) );
		$this->assertNull( nsw_theme_dot_get( $data, 'footer.title' ) );
		$this->assertSame( 'none', nsw_theme_dot_get( array(), 'hero', 'none' ) );
	}

	/* --------------------------------------------------------- text translation */

	/** Source: TSD 2.2.2, "Interface texts: English source in the theme, translated in Polylang". */
	#[TestDox('An interface text is taken in English from the theme and translated into the language of the page')]
	public function test_text_translated_from_english_source(): void {
		$this->plugin_page_language( 'sq' );
		$asked_catalogue = array();
		Functions\when( 'nsw_theme_get_content' )->alias(
			static function ( $locale = null ) use ( &$asked_catalogue ) {
				$asked_catalogue[] = $locale;
				return self::sample_catalogue();
			}
		);
		$asked = array();
		Functions\when( 'pll_translate_string' )->alias(
			static function ( $text, $lang ) use ( &$asked ) {
				$asked[] = array( $text, $lang );
				return 'Learn More' === $text && 'sq' === $lang ? 'Mëso më shumë' : $text;
			}
		);

		$this->assertSame( 'Mëso më shumë', nsw_theme_t( 'common.learnMore' ) );
		$this->assertSame( array( 'en' ), $asked_catalogue );
		$this->assertSame( array( array( 'Learn More', 'sq' ) ), $asked );
	}

	/** Source: TSD 2.2.2, "Interface texts: English source in the theme, translated in Polylang". */
	#[TestDox('On an English page the English interface text is asked for in English')]
	public function test_text_on_english_page(): void {
		$this->plugin_page_language( 'en' );
		Functions\when( 'nsw_theme_get_content' )->justReturn( self::sample_catalogue() );
		Functions\expect( 'pll_translate_string' )->once()->with( 'Send Message', 'en' )->andReturn( 'Send Message' );

		$this->assertSame( 'Send Message', nsw_theme_t( 'contactPage.form.submit' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A text name missing from the theme\'s English texts uses the English text given with it')]
	public function test_missing_name_uses_given_english_text(): void {
		$this->plugin_page_language( 'sq' );
		Functions\when( 'nsw_theme_get_content' )->justReturn( self::sample_catalogue() );
		// Such a text is not offered for translation, so the plugin gives it back unchanged.
		Functions\expect( 'pll_translate_string' )->once()->with( 'Welcome to NSW', 'sq' )->andReturn( 'Welcome to NSW' );

		$this->assertSame( 'Welcome to NSW', nsw_theme_t( 'hero.welcome', 'Welcome to NSW' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A text name missing from the theme\'s English texts, with no English text given, is shown as the name itself')]
	public function test_missing_name_without_text_shows_name(): void {
		$this->plugin_page_language( 'sq' );
		Functions\when( 'nsw_theme_get_content' )->justReturn( self::sample_catalogue() );
		Functions\when( 'pll_translate_string' )->returnArg( 1 );

		$this->assertSame( 'hero.welcome', nsw_theme_t( 'hero.welcome' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A name that points to a group of texts, not to one text, uses the English text given with it')]
	public function test_name_of_group_uses_given_text(): void {
		$this->plugin_page_language( 'en' );
		Functions\when( 'nsw_theme_get_content' )->justReturn( self::sample_catalogue() );
		Functions\when( 'pll_translate_string' )->returnArg( 1 );

		$this->assertSame( 'Contact form', nsw_theme_t( 'contactPage.form', 'Contact form' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A plain sentence is translated as it is, without being looked up in the theme\'s English texts')]
	public function test_plain_text_translated_directly(): void {
		$this->plugin_page_language( 'sq' );
		Functions\expect( 'nsw_theme_get_content' )->never();
		Functions\expect( 'pll_translate_string' )->once()->with( 'Search', 'sq' )->andReturn( 'Kërko' );

		$this->assertSame( 'Kërko', nsw_theme_t( 'Search' ) );
	}

	/** Source: theme behaviour, not specified in the TSD (the TSD always has Polylang installed). */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, interface texts are shown in English')]
	public function test_text_without_plugin(): void {
		Functions\when( 'determine_locale' )->justReturn( 'sq_AL' );
		Functions\when( 'nsw_theme_get_content' )->justReturn( self::sample_catalogue() );

		$this->assertSame( 'Learn More', nsw_theme_t( 'common.learnMore' ) );
		$this->assertSame( 'Search', nsw_theme_t( 'Search' ) );
	}

	/* ------------------------------------------------------ catalogue flattening */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Texts grouped under headings are listed under a name made of the heading and the text name')]
	public function test_flatten_nested(): void {
		$this->assertSame(
			array(
				'common.learnMore'          => 'Learn More',
				'contactPage.form.submit'   => 'Send Message',
				'contactPage.form.errors.0' => 'Name is required',
			),
			nsw_theme_flatten_strings(
				array(
					'common'      => array( 'learnMore' => 'Learn More' ),
					'contactPage' => array( 'form' => array( 'submit' => 'Send Message', 'errors' => array( 'Name is required' ) ) ),
				)
			)
		);
		$this->assertSame( array( 'nav.home' => 'Home' ), nsw_theme_flatten_strings( array( 'home' => 'Home' ), 'nav' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Empty texts and values that are not texts are left out of the list')]
	public function test_flatten_skips_empty_and_non_text(): void {
		$this->assertSame(
			array( 'a.kept' => 'Kept' ),
			nsw_theme_flatten_strings(
				array( 'a' => array( 'kept' => 'Kept', 'empty' => '', 'number' => 3, 'flag' => true, 'nothing' => null, 'group' => array() ) )
			)
		);
	}

	/* ------------------------------------------------ registration for translation */

	/** Source: TSD 2.2.2, interface texts "translated in Polylang"; SDP 5.5, "theme texts (group NSW Theme)". */
	#[TestDox('Every English text of the theme is offered for translation in the group "NSW Theme"')]
	public function test_every_catalogue_text_registered(): void {
		$catalogue = self::english_catalogue();
		$expected  = 0;
		array_walk_recursive(
			$catalogue,
			static function ( $value ) use ( &$expected ) {
				if ( is_string( $value ) && '' !== $value ) {
					++$expected;
				}
			}
		);

		$asked      = array();
		$registered = $this->run_string_registration( $catalogue, $asked );

		$this->assertSame( array( 'en' ), $asked );
		$this->assertGreaterThan( 100, $expected );
		$this->assertCount( $expected, $registered );
		$this->assertSame( array( 'NSW Theme' ), array_values( array_unique( array_column( $registered, 'group' ) ) ) );
		$this->assertSame( 'Send Message', $registered['contactPage.form.submit']['value'] );
		$this->assertSame( 'Import', $registered['servicesPage.types.import']['value'] );
		$this->assertSame( 'Page Not Found', $registered['notFound.title']['value'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Texts longer than 80 characters are offered for translation in a multi-line box')]
	public function test_long_texts_registered_as_multiline(): void {
		$registered = $this->run_string_registration(
			array(
				'short'    => 'Learn More',
				'exactly'  => str_repeat( 'a', 80 ),
				'longer'   => str_repeat( 'b', 81 ),
			)
		);
		$this->assertFalse( $registered['short']['multiline'] );
		$this->assertFalse( $registered['exactly']['multiline'] );
		$this->assertTrue( $registered['longer']['multiline'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, no texts are offered for translation and the site still starts')]
	public function test_registration_without_plugin(): void {
		Functions\expect( 'nsw_theme_get_content' )->never();
		$this->i18n_callback( 'init' )();
		$this->assertFalse( function_exists( 'pll_register_string' ) );
	}

	/**
	 * Source: TSD 2.2.2, interface texts "translated in Polylang"; TSD 2.2.3, Success: "Message sent".
	 *
	 * The TSD success text is "Message sent"; a closing full stop is accepted. The longer text
	 * the browser shows today is a separate difference, checked with the browser texts.
	 */
	#[Group('tsd-difference')]
	#[TestDox('The contact form confirmation "Message sent" is offered for translation into Albanian')]
	public function test_contact_success_message_registered(): void {
		$values = array_column( $this->run_string_registration( self::english_catalogue() ), 'value' );
		$this->assertNotEmpty(
			preg_grep( '/^Message sent\.?$/', $values ),
			'No text "Message sent" is offered for translation'
		);
	}

	/** Source: TSD 2.2.3, Albanian and English "for all public pages, menus and interface texts". */
	#[Group('tsd-difference')]
	#[TestDox('The messages the contact form shows while sending and when something is wrong are offered for translation into Albanian')]
	public function test_contact_form_messages_registered(): void {
		$values   = array_column( $this->run_string_registration( self::english_catalogue() ), 'value' );
		$messages = array(
			// Shown by the browser while sending and on a failed or incomplete form.
			'Submitting…',
			'Something went wrong. Please try again.',
			'Please fill out all required fields.',
			// Sent by the website and shown on the form.
			'Security check failed.',
			'Please wait a moment before sending again.',
			'Missing required fields.',
			'Please enter a valid email.',
			'Subject is too short.',
			'Message is too short.',
			'Could not send the message. Please try again later.',
			'Failed to create support request.',
		);
		$this->assertSame( array(), array_values( array_diff( $messages, $values ) ), 'Contact form messages that cannot be translated' );
	}

	/**
	 * Source: TSD 2.2.3, Albanian and English "for all public pages, menus and interface texts".
	 *
	 * The FAQ page shows this text when a search or category filter finds nothing. The theme
	 * gives it with a built-in English text under a name that is missing from its English texts,
	 * so it is never offered for translation and stays in English on Albanian pages.
	 */
	#[Group('tsd-difference')]
	#[TestDox('The message the FAQ page shows when a search finds nothing is offered for translation into Albanian')]
	public function test_faq_no_results_message_registered(): void {
		$values = array_column( $this->run_string_registration( self::english_catalogue() ), 'value' );
		$this->assertContains( 'No results. Try a different search term or category.', $values );
	}

	/* --------------------------------------------------- gettext bridge to plugin */

	/** Source: TSD 2.2.2, "Interface texts: English source in the theme, translated in Polylang". */
	#[TestDox('On an Albanian page, a theme text is shown with its Albanian translation')]
	public function test_theme_text_translated_on_albanian_page(): void {
		$this->plugin_page_language( 'sq' );
		Functions\expect( 'pll_translate_string' )->once()->with( 'Search', 'sq' )->andReturn( 'Kërko' );

		$this->assertSame( 'Kërko', $this->i18n_callback( 'gettext' )( 'Search', 'Search', 'nsw-theme' ) );
	}

	/** Source: TSD 2.2.2, "English source in the theme". */
	#[TestDox('On an English page, theme texts are shown in English unchanged')]
	public function test_theme_text_unchanged_on_english_page(): void {
		$this->plugin_page_language( 'en' );
		Functions\expect( 'pll_translate_string' )->never();

		$this->assertSame( 'Search', $this->i18n_callback( 'gettext' )( 'Search', 'Search', 'nsw-theme' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Texts of WordPress itself and of plugins are left as they are')]
	public function test_other_texts_untouched(): void {
		$this->plugin_page_language( 'sq' );
		Functions\expect( 'pll_translate_string' )->never();

		$this->assertSame( 'Kërko', $this->i18n_callback( 'gettext' )( 'Kërko', 'Search', 'default' ) );
		$this->assertSame( 'Members', $this->i18n_callback( 'gettext' )( 'Members', 'Members', 'members' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the translation is empty, the text WordPress already had is kept')]
	public function test_empty_translation_keeps_text(): void {
		$this->plugin_page_language( 'sq' );
		Functions\when( 'pll_translate_string' )->justReturn( '' );

		$this->assertSame( 'Search', $this->i18n_callback( 'gettext' )( 'Search', 'Search', 'nsw-theme' ) );
	}

	/** Source: theme behaviour, not specified in the TSD (the TSD always has Polylang installed). */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, theme texts are shown as WordPress gives them')]
	public function test_theme_text_without_plugin(): void {
		$this->assertSame( 'Search', $this->i18n_callback( 'gettext' )( 'Search', 'Search', 'nsw-theme' ) );
	}

	/* ---------------------------------------------------- editor preview requests */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The editor preview of an English item is shown in English')]
	public function test_preview_of_english_item(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\expect( 'pll_get_post_language' )->once()->with( 15, 'slug' )->andReturn( 'en' );
		$response = array( 'untouched' => true );

		$result = $this->i18n_callback( 'rest_request_before_callbacks' )( $response, array(), $this->preview_request( 15 ) );

		$this->assertSame( $response, $result );
		$this->assertSame( 'en', nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The editor preview of an Albanian item is shown in Albanian')]
	public function test_preview_of_albanian_item(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'pll_get_post_language' )->justReturn( 'sq' );

		$this->i18n_callback( 'rest_request_before_callbacks' )( null, array(), $this->preview_request( 16 ) );

		$this->assertSame( 'sq', nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The editor preview of an item without a language keeps the normal language')]
	public function test_preview_of_item_without_language(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'pll_get_post_language' )->justReturn( false );

		$this->i18n_callback( 'rest_request_before_callbacks' )( null, array(), $this->preview_request( 17 ) );

		$this->assertNull( nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Requests other than editor previews do not change the language')]
	public function test_other_requests_do_not_change_language(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\expect( 'pll_get_post_language' )->never();

		$this->i18n_callback( 'rest_request_before_callbacks' )( null, array(), $this->preview_request( 15, '/wp/v2/posts/15' ) );

		$this->assertNull( nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An editor preview without an item number does not change the language')]
	public function test_preview_without_item_number(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\expect( 'pll_get_post_language' )->never();

		$this->i18n_callback( 'rest_request_before_callbacks' )( null, array(), $this->preview_request( 0 ) );
		$request = $this->preview_request( 0 );
		$request->set_param( 'post_id', 'abc' );
		$this->i18n_callback( 'rest_request_before_callbacks' )( null, array(), $request );

		$this->assertNull( nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An editor preview request that has already been refused is passed on unchanged')]
	public function test_failed_request_passed_on(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\expect( 'pll_get_post_language' )->never();
		$error = new \WP_Error( 'rest_forbidden', 'Sorry.' );

		$result = $this->i18n_callback( 'rest_request_before_callbacks' )( $error, array(), $this->preview_request( 15 ) );

		$this->assertSame( $error, $result );
		$this->assertNull( nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A request of an unexpected kind is passed on unchanged and does not change the language')]
	public function test_unexpected_request_passed_on(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\expect( 'pll_get_post_language' )->never();

		$result = $this->i18n_callback( 'rest_request_before_callbacks' )( 'response', array(), array( 'post_id' => 15 ) );

		$this->assertSame( 'response', $result );
		$this->assertNull( nsw_theme_get_preview_locale() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, editor previews keep the normal language')]
	public function test_preview_without_plugin(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->i18n_callback( 'rest_request_before_callbacks' )( null, array(), $this->preview_request( 15 ) );

		$this->assertNull( $result );
		$this->assertNull( nsw_theme_get_preview_locale() );
	}
}
