<?php
/**
 * Unit tests for inc/template-tags.php: the helpers the page templates use to build
 * links in the visitor's language, menu labels, the page hero, news and event cards,
 * and the drop-down list of the contact form.
 *
 * The language plugin (Polylang) and WordPress are never loaded: their functions are
 * simulated in each test. Expected behaviour comes from the Information Portal
 * Technical Specification (TSD), sections 2.2.2 (Multilingual text) and 2.2.3 (User
 * Interface Design), and from the Software Development Package (SDP), sections 3.1
 * (escaping with WordPress functions) and 5.4 (the page addresses checked after an
 * installation). Where the TSD and SDP say nothing, the test describes what the theme
 * clearly intends.
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\TestDox;

final class TemplateTagsTest extends TestCase {

	private bool $had_preview_locale = false;
	private $saved_preview_locale = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_preview_locale   = array_key_exists( 'nsw_theme_preview_locale', $GLOBALS );
		$this->saved_preview_locale = $GLOBALS['nsw_theme_preview_locale'] ?? null;
		unset( $GLOBALS['nsw_theme_preview_locale'] );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://nsw.test/' . ltrim( (string) $path, '/' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['nsw_theme_preview_locale'] );
		if ( $this->had_preview_locale ) {
			$GLOBALS['nsw_theme_preview_locale'] = $this->saved_preview_locale;
		}
		parent::tearDown();
	}

	/** Simulates the language plugin on a page in the given language. */
	private function with_plugin_on_page( string $slug ): void {
		Functions\when( 'pll_current_language' )->alias( static fn( $field = 'slug' ) => 'slug' === $field ? $slug : false );
	}

	/** Simulates a site without the language plugin, in the given WordPress language. */
	private function without_plugin( string $wordpress_locale ): void {
		Functions\when( 'nsw_theme_has_polylang' )->justReturn( false );
		Functions\when( 'determine_locale' )->justReturn( $wordpress_locale );
	}

	/**
	 * Records set_query_var() and get_template_part() calls.
	 *
	 * @param array<string, mixed>          $vars  filled with query variable name => value
	 * @param array<int, array<int, mixed>> $parts filled with [slug, name] of each part loaded
	 */
	private function record_template_calls( array &$vars, array &$parts ): void {
		Functions\when( 'set_query_var' )->alias(
			static function ( $name, $value ) use ( &$vars ) {
				$vars[ $name ] = $value;
			}
		);
		Functions\when( 'get_template_part' )->alias(
			static function ( $slug, $name = null ) use ( &$parts ) {
				$parts[] = array( $slug, $name );
			}
		);
	}

	/** Renders the drop-down and returns it as a searchable HTML document. */
	private function render_select_dom( array $args ): \DOMXPath {
		ob_start();
		nsw_theme_render_select( $args );
		$html = (string) ob_get_clean();
		$this->assertNotSame( '', $html );

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new \DOMXPath( $doc );
	}

	/** The contact form category drop-down, with invented values. */
	private static function category_select( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 'contact-category',
				'name'        => 'category',
				'placeholder' => 'Select a category',
				'options'     => array(
					'general' => 'General Inquiry',
					'lpco'    => 'LPCO Process',
					'payment' => 'Payment',
				),
				'required'    => true,
			),
			$overrides
		);
	}

	/* ------------------------------------------------------------------ home link */

	/** Source: TSD 2.2.2, "English (/en/)". */
	#[TestDox('On an English page the home link goes to the English home page')]
	public function test_home_link_english(): void {
		$this->with_plugin_on_page( 'en' );
		Functions\when( 'pll_home_url' )->justReturn( 'https://nsw.test/en/' );

		$this->assertSame( 'https://nsw.test/en/', nsw_theme_home_url() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the language plugin gives no home address, the home link goes to the site home page')]
	public function test_home_link_plugin_without_address(): void {
		$this->with_plugin_on_page( 'sq' );
		Functions\when( 'pll_home_url' )->justReturn( '' );

		$this->assertSame( 'https://nsw.test/', nsw_theme_home_url() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without the language plugin, the home link goes to the site home page')]
	public function test_home_link_without_plugin(): void {
		$this->without_plugin( 'sq_AL' );
		Functions\expect( 'pll_home_url' )->never();

		$this->assertSame( 'https://nsw.test/', nsw_theme_home_url() );
	}

	/* -------------------------------------------------------- section addresses */

	/** Source: SDP 5.4, step 10, Albanian addresses "/agjencite/, /partneret/, /dokumenta/ ...". */
	#[TestDox('The Albanian addresses of the main sections are the ones checked after an installation')]
	public function test_albanian_section_addresses(): void {
		$slugs = nsw_theme_path_slugs();
		$this->assertSame( 'agjencite', $slugs['agencies']['sq'] );
		$this->assertSame( 'partneret', $slugs['partners']['sq'] );
		$this->assertSame( 'dokumenta', $slugs['documents']['sq'] );
		$this->assertSame( 'ngjarje', $slugs['events']['sq'] );
		$this->assertSame( 'pyetjet-e-shpeshta', $slugs['faq']['sq'] );
		$this->assertSame( 'lajme', $slugs['news']['sq'] );
		$this->assertSame( 'sherbime', $slugs['services']['sq'] );
		$this->assertSame( 'kontakt', $slugs['contact']['sq'] );
	}

	/** Source: SDP 5.4, step 9, "Services page in each language (/sherbime/, /en/services/)". */
	#[TestDox('The Services section is at "sherbime" in Albanian and "services" in English')]
	public function test_services_addresses(): void {
		$this->assertSame( array( 'sq' => 'sherbime', 'en' => 'services' ), nsw_theme_path_slugs()['services'] );
	}

	/** Source: TSD 2.2.2, "Slugs and menus per language". */
	#[TestDox('Every section has an address in Albanian and in English, and the home page has none')]
	public function test_every_section_has_both_addresses(): void {
		$slugs = nsw_theme_path_slugs();
		$this->assertSame(
			array( 'home', 'about', 'how-it-works', 'agencies', 'partners', 'faq', 'documents', 'services', 'news', 'events', 'contact', 'support' ),
			array_keys( $slugs )
		);
		foreach ( $slugs as $key => $by_language ) {
			$this->assertSame( array( 'sq', 'en' ), array_keys( $by_language ), "Section $key" );
			if ( 'home' !== $key ) {
				$this->assertNotSame( '', $by_language['sq'], "Section $key in Albanian" );
				$this->assertNotSame( '', $by_language['en'], "Section $key in English" );
			}
		}
		$this->assertSame( array( 'sq' => '', 'en' => '' ), $slugs['home'] );
	}

	/** Source: TSD 2.2.2, "Slugs and menus per language". */
	#[TestDox('On an Albanian page a section link goes to the Albanian page of that section')]
	public function test_section_link_albanian_page(): void {
		$this->with_plugin_on_page( 'sq' );
		$page = new \WP_Post( array( 'ID' => 12, 'post_name' => 'agjencite', 'post_type' => 'page' ) );
		Functions\expect( 'get_page_by_path' )->once()->with( 'agjencite' )->andReturn( $page );
		Functions\expect( 'get_permalink' )->once()->with( $page )->andReturn( 'https://nsw.test/agjencite/' );

		$this->assertSame( 'https://nsw.test/agjencite/', nsw_theme_path_url( 'agencies' ) );
	}

	/** Source: TSD 2.2.2, "Slugs and menus per language". */
	#[TestDox('On an English page a section link goes to the English page of that section')]
	public function test_section_link_english_page(): void {
		$this->with_plugin_on_page( 'en' );
		$page = new \WP_Post( array( 'ID' => 22, 'post_name' => 'agencies', 'post_type' => 'page' ) );
		Functions\expect( 'get_page_by_path' )->once()->with( 'agencies' )->andReturn( $page );
		Functions\when( 'get_permalink' )->justReturn( 'https://nsw.test/en/agencies/' );

		$this->assertSame( 'https://nsw.test/en/agencies/', nsw_theme_path_url( 'agencies' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the section page does not exist, the link is built from the section address')]
	public function test_section_link_page_missing(): void {
		$this->with_plugin_on_page( 'sq' );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\expect( 'get_permalink' )->never();

		$this->assertSame( 'https://nsw.test/pyetjet-e-shpeshta/', nsw_theme_path_url( 'faq' ) );
	}

	/**
	 * Source: theme behaviour, not specified in the TSD.
	 *
	 * Checked on an Albanian page only: on an English page the same fallback builds a link
	 * without the /en/ part of the address (reported as a suspected fault, not tested).
	 */
	#[TestDox('When the section page has no address of its own, the link is built from the section address')]
	public function test_section_link_without_permalink(): void {
		$this->with_plugin_on_page( 'sq' );
		Functions\when( 'get_page_by_path' )->justReturn( new \WP_Post( array( 'ID' => 30 ) ) );
		Functions\when( 'get_permalink' )->justReturn( false );

		$this->assertSame( 'https://nsw.test/kontakt/', nsw_theme_path_url( 'contact' ) );
	}

	/** Source: theme behaviour, not specified in the TSD (the TSD always has Polylang installed). */
	#[TestDox('Without the language plugin, a section link uses the address in the site language')]
	public function test_section_link_without_plugin(): void {
		Functions\expect( 'get_page_by_path' )->never();

		$this->without_plugin( 'en_US' );
		$this->assertSame( 'https://nsw.test/about/', nsw_theme_path_url( 'about' ) );

		$this->without_plugin( 'sq_AL' );
		$this->assertSame( 'https://nsw.test/rreth-nsw/', nsw_theme_path_url( 'about' ) );
		$this->assertSame( 'https://nsw.test/si-funksionon/', nsw_theme_path_url( 'how-it-works' ) );
	}

	/**
	 * Source: theme behaviour, not specified in the TSD.
	 *
	 * Checked on an Albanian page only: on an English page the link has no /en/ part either
	 * (reported as a suspected fault, not tested).
	 */
	#[TestDox('A section the theme does not know is linked by the name given')]
	public function test_unknown_section_link(): void {
		$this->with_plugin_on_page( 'sq' );
		Functions\expect( 'get_page_by_path' )->never();

		$this->assertSame( 'https://nsw.test/statistika', nsw_theme_path_url( 'statistika' ) );
		$this->assertSame( 'https://nsw.test/raporti-vjetor', nsw_theme_path_url( '/raporti-vjetor' ) );
	}

	/* --------------------------------------------------------------- menu labels */

	/** Source: TSD 2.2.2, "Interface texts: English source in the theme, translated in Polylang". */
	#[TestDox('A menu label is shown in the language of the page when it has a translation')]
	public function test_menu_label_translated(): void {
		$asked = array();
		Functions\when( 'nsw_theme_t' )->alias(
			static function ( $key, $fallback = '' ) use ( &$asked ) {
				$asked[] = array( $key, $fallback );
				return 'nav.services' === $key ? 'Shërbimet' : $fallback;
			}
		);

		$this->assertSame( 'Shërbimet', nsw_theme_nav_label( 'services' ) );
		$this->assertSame( array( array( 'nav.services', 'Services' ) ), $asked );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without a translation, every main menu entry has its English label')]
	public function test_menu_labels_in_english(): void {
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => $fallback );
		$expected = array(
			'home'              => 'Home',
			'about'             => 'About NSW',
			'howItWorks'        => 'How It Works',
			'agencies'          => 'Agencies',
			'partners'          => 'Partners',
			'faq'               => 'FAQ',
			'documents'         => 'Documents',
			'services'          => 'Services',
			'news'              => 'News',
			'events'            => 'Events',
			'contact'           => 'Contact',
			'support'           => 'Support',
			'aboutDropdown'     => 'About Us',
			'resourcesDropdown' => 'Resources',
		);
		foreach ( $expected as $key => $label ) {
			$this->assertSame( $label, nsw_theme_nav_label( $key ), "Menu entry $key" );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A menu entry the theme does not know is labelled with its own name')]
	public function test_unknown_menu_label(): void {
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => $fallback );
		$this->assertSame( 'statistics', nsw_theme_nav_label( 'statistics' ) );
	}

	/* ---------------------------------------------------------------------- hero */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A page hero without a chosen style is shown as the compact hero, with empty optional parts')]
	public function test_hero_defaults(): void {
		$vars  = array();
		$parts = array();
		$this->record_template_calls( $vars, $parts );

		nsw_theme_render_hero( array( 'title' => 'About NSW' ) );

		$this->assertSame(
			array(
				'nsw_theme_hero_variant'  => 'compact',
				'nsw_theme_hero_title'    => 'About NSW',
				'nsw_theme_hero_subtitle' => '',
				'nsw_theme_hero_children' => '',
				'nsw_theme_hero_bg_url'   => '',
				'nsw_theme_hero_bg_mime'  => '',
			),
			$vars
		);
		$this->assertSame( array( array( 'template-parts/sections/hero', 'compact' ) ), $parts );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The large page hero is shown with its title, subtitle and background media')]
	public function test_hero_large(): void {
		$vars  = array();
		$parts = array();
		$this->record_template_calls( $vars, $parts );

		nsw_theme_render_hero(
			array(
				'variant'  => 'large',
				'title'    => 'National Single Window',
				'subtitle' => 'Trade made simple',
				'children' => '<a href="/en/about/">Learn More</a>',
				'bg_url'   => 'https://nsw.test/wp-content/uploads/hero.mp4',
				'bg_mime'  => 'video/mp4',
			)
		);

		$this->assertSame( 'large', $vars['nsw_theme_hero_variant'] );
		$this->assertSame( 'National Single Window', $vars['nsw_theme_hero_title'] );
		$this->assertSame( 'Trade made simple', $vars['nsw_theme_hero_subtitle'] );
		$this->assertSame( '<a href="/en/about/">Learn More</a>', $vars['nsw_theme_hero_children'] );
		$this->assertSame( 'https://nsw.test/wp-content/uploads/hero.mp4', $vars['nsw_theme_hero_bg_url'] );
		$this->assertSame( 'video/mp4', $vars['nsw_theme_hero_bg_mime'] );
		$this->assertSame( array( array( 'template-parts/sections/hero', 'large' ) ), $parts );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A page hero with an unknown style is shown as the compact hero')]
	public function test_hero_unknown_style(): void {
		$vars  = array();
		$parts = array();
		$this->record_template_calls( $vars, $parts );

		nsw_theme_render_hero( array( 'variant' => 'huge', 'title' => 'News' ) );

		$this->assertSame( array( array( 'template-parts/sections/hero', 'compact' ) ), $parts );
	}

	/* --------------------------------------------------------------------- cards */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A news card is shown for the given news item in the given language')]
	public function test_news_card(): void {
		$vars  = array();
		$parts = array();
		$this->record_template_calls( $vars, $parts );
		$post = new \WP_Post( array( 'ID' => 55, 'post_title' => 'New customs procedure' ) );

		nsw_theme_render_news_card( $post, 'en' );

		$this->assertSame( $post, $vars['nsw_theme_card_post'] );
		$this->assertSame( 'en', $vars['nsw_theme_card_locale'] );
		$this->assertSame( array( array( 'template-parts/cards/news', 'card' ) ), $parts );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An event card is shown for the given event in the given language')]
	public function test_event_card(): void {
		$vars  = array();
		$parts = array();
		$this->record_template_calls( $vars, $parts );
		$event = array( 'id' => 'workshop-1', 'title' => 'Trader workshop', 'date' => '2026-12-01' );

		nsw_theme_render_event_card( $event, 'sq' );

		$this->assertSame( $event, $vars['nsw_theme_card_event'] );
		$this->assertSame( 'sq', $vars['nsw_theme_card_locale'] );
		$this->assertSame( array( array( 'template-parts/cards/event', 'card' ) ), $parts );
	}

	/* ----------------------------------------------------------------- drop-down */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The drop-down lists each choice with its value and label, in the given order')]
	public function test_select_lists_choices(): void {
		$xpath   = $this->render_select_dom( self::category_select() );
		$options = $xpath->query( '//ul/li' );

		$this->assertSame( 3, $options->length );
		$found = array();
		foreach ( $options as $option ) {
			$found[ $option->getAttribute( 'data-value' ) ] = $option->textContent;
		}
		$this->assertSame( array( 'general' => 'General Inquiry', 'lpco' => 'LPCO Process', 'payment' => 'Payment' ), $found );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The drop-down shows its prompt and sends no value until a choice is made')]
	public function test_select_starts_empty(): void {
		$xpath = $this->render_select_dom( self::category_select() );

		$button = $xpath->query( '//button' )->item( 0 );
		$this->assertSame( 'contact-category', $button->getAttribute( 'id' ) );
		$this->assertSame( 'button', $button->getAttribute( 'type' ) );
		$this->assertSame( 'Select a category', trim( $xpath->query( '//button/span' )->item( 0 )->textContent ) );

		$input = $xpath->query( '//input' )->item( 0 );
		$this->assertSame( 'hidden', $input->getAttribute( 'type' ) );
		$this->assertSame( 'category', $input->getAttribute( 'name' ) );
		$this->assertSame( '', $input->getAttribute( 'value' ) );
		$this->assertTrue( $xpath->query( '//ul' )->item( 0 )->hasAttribute( 'hidden' ) );
	}

	/** Source: TSD 2.2.3, contact form "category (one of seven)" is required. */
	#[TestDox('A required drop-down is marked as required, an optional one is not')]
	public function test_select_required_mark(): void {
		$required = $this->render_select_dom( self::category_select() )->query( '//input' )->item( 0 );
		$this->assertSame( 'true', $required->getAttribute( 'data-required' ) );

		$optional = $this->render_select_dom( self::category_select( array( 'name' => 'agency', 'required' => false ) ) )->query( '//input' )->item( 0 );
		$this->assertFalse( $optional->hasAttribute( 'data-required' ) );
	}

	/** Source: TSD 2.2.3, "Recognised web accessibility standards (e.g. WCAG) where applicable". */
	#[TestDox('The drop-down tells screen readers it is a list of choices, with a name, and that it is closed')]
	public function test_select_screen_reader_roles(): void {
		$xpath = $this->render_select_dom( self::category_select() );

		$button = $xpath->query( '//button' )->item( 0 );
		$this->assertSame( 'listbox', $button->getAttribute( 'aria-haspopup' ) );
		$this->assertSame( 'false', $button->getAttribute( 'aria-expanded' ) );

		$list = $xpath->query( '//ul' )->item( 0 );
		$this->assertSame( 'listbox', $list->getAttribute( 'role' ) );
		$this->assertSame( 'Select a category', $list->getAttribute( 'aria-label' ) );

		foreach ( $xpath->query( '//ul/li' ) as $option ) {
			$this->assertSame( 'option', $option->getAttribute( 'role' ) );
			$this->assertSame( 'false', $option->getAttribute( 'aria-selected' ) );
		}
		$this->assertSame( 'true', $xpath->query( '//*[local-name()="svg"]' )->item( 0 )->getAttribute( 'aria-hidden' ) );
	}

	/** Source: SDP 3.1, "Escaping, sanitising, nonces and capability checks use WordPress APIs". */
	#[TestDox('Text and values in the drop-down are escaped, so they cannot add code to the page')]
	public function test_select_escapes_text(): void {
		ob_start();
		nsw_theme_render_select(
			self::category_select(
				array(
					'placeholder' => 'Pick "one" <now>',
					'options'     => array( 'a"onclick="x' => '<script>alert(1)</script>' ),
				)
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'data-value="a&quot;onclick=&quot;x"', $html );
		$this->assertStringContainsString( 'data-placeholder="Pick &quot;one&quot; &lt;now&gt;"', $html );
		$this->assertStringNotContainsString( '<now>', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A drop-down without an identifier or a field name is not shown at all')]
	public function test_select_needs_id_and_name(): void {
		$this->expectOutputString( '' );
		nsw_theme_render_select( self::category_select( array( 'id' => '' ) ) );
		nsw_theme_render_select( self::category_select( array( 'name' => '' ) ) );
		nsw_theme_render_select( array() );
	}
}
