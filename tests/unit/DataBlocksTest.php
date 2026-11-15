<?php
/**
 * Unit tests for inc/data-blocks.php: the NSW blocks that build the listing pages.
 *
 * Each block shows the page hero (the page title and summary) followed by the
 * section of its page: Agencies, Partners, FAQ, Documents, Events, Trade Services or
 * Contact Form. The section templates themselves are not run: a stand-in prints a
 * marker with the name of the template, so the tests see which section a block
 * shows and in which order.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.1.1 (custom blocks), 2.2.3 (screens) and 2.3.1 (listing pages built from
 * NSW blocks). Where the TSD says nothing, the test follows what the theme intends.
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class DataBlocksTest extends TestCase {

	/** The marker printed in place of the compact page hero. */
	private const HERO = '[template-parts/sections/hero-compact]';

	/** The seven blocks of the listing pages: name => [render function, title]. */
	private const BLOCKS = array(
		'nsw-theme/agencies'      => array( 'nsw_theme_block_agencies', 'NSW Agencies (full list)' ),
		'nsw-theme/partners-page' => array( 'nsw_theme_block_partners_page', 'NSW Partners (full page)' ),
		'nsw-theme/faq'           => array( 'nsw_theme_block_faq', 'NSW FAQ' ),
		'nsw-theme/documents'     => array( 'nsw_theme_block_documents', 'NSW Documents' ),
		'nsw-theme/events'        => array( 'nsw_theme_block_events', 'NSW Events' ),
		'nsw-theme/services'      => array( 'nsw_theme_block_services', 'NSW Trade Services' ),
		'nsw-theme/contact-form'  => array( 'nsw_theme_block_contact_form', 'NSW Contact Form' ),
	);

	/** @var array<string, mixed> Page variables set by the code, as they are now. */
	private array $query_vars = array();

	/** @var array<string, array<string, mixed>> Page variables as they were when each template was shown. */
	private array $vars_at_render = array();

	private bool $had_preview_locale = false;

	/** @var mixed */
	private $preview_locale_before = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_preview_locale    = array_key_exists( 'nsw_theme_preview_locale', $GLOBALS );
		$this->preview_locale_before = $GLOBALS['nsw_theme_preview_locale'] ?? null;
	}

	protected function tearDown(): void {
		if ( $this->had_preview_locale ) {
			$GLOBALS['nsw_theme_preview_locale'] = $this->preview_locale_before;
		} else {
			unset( $GLOBALS['nsw_theme_preview_locale'] );
		}
		parent::tearDown();
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * The page being viewed, plus stand-ins for the page variables and the templates.
	 * A template prints "[slug-name]" and the page variables are kept as they were
	 * at that moment.
	 *
	 * @param mixed $queried What WordPress gives as the current page.
	 */
	private function given_page( $queried ): void {
		$this->query_vars     = array();
		$this->vars_at_render = array();
		Functions\when( 'get_queried_object' )->justReturn( $queried );
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post instanceof \WP_Post ? $post->post_title : '' );
		Functions\when( 'set_query_var' )->alias(
			function ( $key, $value ) {
				$this->query_vars[ $key ] = $value;
			}
		);
		Functions\when( 'get_template_part' )->alias(
			function ( $slug, $name = null, $args = array() ) {
				$part                          = $slug . ( null !== $name ? '-' . $name : '' );
				$this->vars_at_render[ $part ] = $this->query_vars;
				echo '[' . $part . ']';
			}
		);
	}

	/** An invented listing page with a title and a summary. */
	private function listing_page( string $title = 'Participating Agencies', string $summary = 'The agencies that take part in the NSW.' ): \WP_Post {
		return new \WP_Post(
			array( 'ID' => 70, 'post_type' => 'page', 'post_title' => $title, 'post_excerpt' => $summary )
		);
	}

	/** The block registration callback that inc/data-blocks.php added to "init". */
	private function block_registration(): callable {
		return nsw_test_hook_callback(
			'init',
			0,
			static fn( array $reg ) => $reg['callback'] instanceof \Closure
				&& str_ends_with( (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName(), '/inc/data-blocks.php' )
		);
	}

	/**
	 * Runs the registration and returns what was registered: name => settings. The
	 * editable-field schema belongs to inc/blocks.php, so a stand-in returns a marker.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function registered_blocks(): array {
		$registered = array();
		Functions\when( 'register_block_type' )->alias(
			static function ( $name, $args = array() ) use ( &$registered ) {
				$registered[ $name ] = $args;
				return true;
			}
		);
		Functions\when( 'nsw_theme_block_attributes' )->alias(
			static fn( string $block ) => array( 'fieldsOf' => array( 'type' => 'string', 'default' => $block ) )
		);
		( $this->block_registration() )();
		return $registered;
	}

	/* ------------------------------------------------------------------ page hero */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The banner at the top of the page shows the page title as heading and the page summary as subtitle')]
	public function test_hero_uses_page_title_and_summary(): void {
		$this->given_page( $this->listing_page( 'Participating Agencies', 'The agencies that take part in the NSW.' ) );

		$html = nsw_theme_data_hero();

		$this->assertSame( self::HERO, $html );
		$vars = $this->vars_at_render['template-parts/sections/hero-compact'];
		$this->assertSame( 'compact', $vars['nsw_theme_hero_variant'] );
		$this->assertSame( 'Participating Agencies', $vars['nsw_theme_hero_title'] );
		$this->assertSame( 'The agencies that take part in the NSW.', $vars['nsw_theme_hero_subtitle'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A page without a summary gets a page banner without a subtitle')]
	public function test_hero_without_summary(): void {
		$this->given_page( $this->listing_page( 'Events', '' ) );

		$this->assertSame( self::HERO, nsw_theme_data_hero() );
		$vars = $this->vars_at_render['template-parts/sections/hero-compact'];
		$this->assertSame( 'Events', $vars['nsw_theme_hero_title'] );
		$this->assertSame( '', $vars['nsw_theme_hero_subtitle'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the current view is not a page, the page banner has no title and no subtitle')]
	public function test_hero_outside_a_page(): void {
		$this->given_page( null );
		Functions\expect( 'get_the_title' )->never();

		$this->assertSame( self::HERO, nsw_theme_data_hero() );
		$vars = $this->vars_at_render['template-parts/sections/hero-compact'];
		$this->assertSame( '', $vars['nsw_theme_hero_title'] );
		$this->assertSame( '', $vars['nsw_theme_hero_subtitle'] );
	}

	/* ------------------------------------------------------------- block output */

	/** Source: TSD 2.2.3, public screens include "Agencies". */
	#[TestDox('The Agencies block shows the page banner followed by the full list of agencies with their documents')]
	public function test_agencies_block(): void {
		$this->given_page( $this->listing_page() );

		$html = nsw_theme_block_agencies();

		$this->assertMatchesRegularExpression(
			'#^' . preg_quote( self::HERO, '#' ) . '\s*<section class="section">\s*<div class="container">\s*\[template-parts/sections/agencies-grid\]\s*</div>\s*</section>\s*$#',
			$html
		);
		$vars = $this->vars_at_render['template-parts/sections/agencies-grid'];
		$this->assertSame( array(), $vars['nsw_theme_agencies_list'], 'No preset list: all agencies are read.' );
		$this->assertSame( 0, $vars['nsw_theme_agencies_limit'], 'No limit on the number of agencies.' );
		$this->assertTrue( $vars['nsw_theme_agencies_show_documents'] );
	}

	/** Source: TSD 2.2.3, public screens include "Partners". */
	#[TestDox('The Partners block shows the page banner followed by the partners page')]
	public function test_partners_block_output(): void {
		$this->given_page( $this->listing_page( 'Partners', 'Who supports the NSW.' ) );
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => $fallback );
		nsw_theme_set_preview_locale( 'en' );

		$this->assertSame( self::HERO . '[template-parts/sections/partners-page]', nsw_theme_block_partners_page( array() ) );
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The Private Sector texts of the Partners block are the ones the editor filled in')]
	public function test_partners_block_uses_editor_texts(): void {
		nsw_theme_set_preview_locale( 'en' );
		$this->given_page( $this->listing_page( 'Partners' ) );

		nsw_theme_block_partners_page(
			array(
				'privateTitle'    => '  Business community  ',
				'privateDesc'     => 'Traders and customs brokers use the NSW every day.',
				'privateActors'   => "Importers\nExporters\nFreight forwarders",
				'privateBenefits' => 'Fewer visits to offices.',
			)
		);

		$vars = $this->vars_at_render['template-parts/sections/partners-page'];
		$this->assertSame( 'Business community', $vars['nsw_pp_private_title'] );
		$this->assertSame( 'Traders and customs brokers use the NSW every day.', $vars['nsw_pp_private_desc'] );
		$this->assertSame( "Importers\nExporters\nFreight forwarders", $vars['nsw_pp_private_actors'] );
		$this->assertSame( 'Fewer visits to offices.', $vars['nsw_pp_private_benefits'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A Private Sector title left empty or filled only with spaces shows the standard title "Private Sector"')]
	public function test_partners_block_blank_title_uses_standard_text(): void {
		nsw_theme_set_preview_locale( 'en' );
		$this->given_page( $this->listing_page( 'Partners' ) );

		nsw_theme_block_partners_page( array( 'privateTitle' => "   \n " ) );
		$this->assertSame( 'Private Sector', $this->vars_at_render['template-parts/sections/partners-page']['nsw_pp_private_title'] );

		nsw_theme_block_partners_page( array() );
		$this->assertSame( 'Private Sector', $this->vars_at_render['template-parts/sections/partners-page']['nsw_pp_private_title'] );
	}

	/** Source: TSD 2.2.3, "FAQ (search, category filter)". */
	#[TestDox('The FAQ block shows the page banner followed by the questions and answers')]
	public function test_faq_block(): void {
		$this->given_page( $this->listing_page( 'Frequently asked questions' ) );

		$this->assertSame( self::HERO . '[template-parts/sections/faq]', nsw_theme_block_faq() );
		$this->assertSame( 'Frequently asked questions', $this->vars_at_render['template-parts/sections/hero-compact']['nsw_theme_hero_title'] );
	}

	/** Source: TSD 2.2.3, public screens include "Documents". */
	#[TestDox('The Documents block shows the page banner followed by the documents list')]
	public function test_documents_block(): void {
		$this->given_page( $this->listing_page( 'Documents' ) );

		$this->assertSame( self::HERO . '[template-parts/sections/documents]', nsw_theme_block_documents() );
	}

	/** Source: TSD 2.2.3, public screens include "Events". */
	#[TestDox('The Events block shows the page banner followed by the events list')]
	public function test_events_block(): void {
		$this->given_page( $this->listing_page( 'Events' ) );

		$this->assertSame( self::HERO . '[template-parts/sections/events]', nsw_theme_block_events() );
	}

	/** Source: TSD 2.2.3, public screens include "Trade Services". */
	#[TestDox('The Trade Services block shows the page banner followed by the service guides')]
	public function test_services_block(): void {
		$this->given_page( $this->listing_page( 'Trade Services' ) );

		$this->assertSame( self::HERO . '[template-parts/sections/services]', nsw_theme_block_services() );
	}

	/** Source: TSD 2.2.3, public screens include "Contact (form, map)". */
	#[TestDox('The Contact Form block shows the page banner followed by the contact form')]
	public function test_contact_form_block(): void {
		$this->given_page( $this->listing_page( 'Contact' ) );

		$this->assertSame( self::HERO . '[template-parts/sections/contact-form]', nsw_theme_block_contact_form() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A block returns its page content instead of printing it')]
	public function test_blocks_print_nothing(): void {
		$this->given_page( $this->listing_page() );
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => $fallback );
		nsw_theme_set_preview_locale( 'en' );

		ob_start();
		$returned = array(
			nsw_theme_block_agencies(),
			nsw_theme_block_partners_page( array() ),
			nsw_theme_block_faq(),
			nsw_theme_block_documents(),
			nsw_theme_block_events(),
			nsw_theme_block_services(),
			nsw_theme_block_contact_form(),
		);
		$printed = ob_get_clean();

		$this->assertSame( '', $printed );
		foreach ( $returned as $html ) {
			$this->assertStringStartsWith( self::HERO, $html );
		}
	}

	/* -------------------------------------------------------------- registration */

	/** Source: TSD 2.3.1, pages include "the listing pages (NSW blocks)". */
	#[TestDox('Seven NSW blocks are available to editors for the listing pages, each showing its own page section')]
	public function test_seven_listing_blocks_registered(): void {
		$registered = $this->registered_blocks();

		$this->assertSame( array_keys( self::BLOCKS ), array_keys( $registered ) );
		foreach ( self::BLOCKS as $name => [ $render, $title ] ) {
			$this->assertSame( $title, $registered[ $name ]['title'], $name );
			$this->assertSame( $render, $registered[ $name ]['render_callback'], $name );
			$this->assertTrue( function_exists( $render ), "$name: the render function exists." );
			$this->assertSame( 'nsw-theme', $registered[ $name ]['category'], $name );
			$this->assertSame( 3, $registered[ $name ]['api_version'], $name );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Editors cannot switch the listing blocks to HTML editing or save them as reusable blocks')]
	public function test_listing_blocks_not_html_or_reusable(): void {
		foreach ( $this->registered_blocks() as $name => $args ) {
			$this->assertSame( array( 'html' => false, 'reusable' => false ), $args['supports'], $name );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Only the Partners block carries fields that editors can fill in')]
	public function test_only_partners_block_has_editable_fields(): void {
		$registered = $this->registered_blocks();

		$this->assertSame(
			array( 'fieldsOf' => array( 'type' => 'string', 'default' => 'nsw-theme/partners-page' ) ),
			$registered['nsw-theme/partners-page']['attributes']
		);
		foreach ( $registered as $name => $args ) {
			if ( 'nsw-theme/partners-page' !== $name ) {
				$this->assertArrayNotHasKey( 'attributes', $args, $name );
			}
		}
	}
}
