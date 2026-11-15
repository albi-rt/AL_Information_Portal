<?php
/**
 * Unit tests for inc/blocks.php: the theme's own page blocks.
 *
 * These blocks build the header logo, the menus and the language switcher, the
 * footer pieces, the home page sections, the news list, single news articles and
 * service guides, the not-found page and the "Service guide" starter layout. Each
 * test renders a block with given settings and checks the markup it gives back.
 * WordPress is not running: its functions are simulated. Where the real output of a
 * section template matters, the theme's own template file is rendered.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 1.4, 2.1.1, 2.2.1 to 2.2.3 and 2.3. Where today's code differs from the
 * TSD, the test expects the TSD and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class BlocksTest extends TestCase {

	/** @var array<string, mixed> Query variables the blocks hand to their templates. */
	private array $query_vars = array();

	/** @var array<int, array<string, mixed>> Template parts loaded, with the state at that moment. */
	private array $parts = array();

	/** @var array<int, string> Order of selected WordPress calls. */
	private array $calls = array();

	/** @var array<string, array{0: bool, 1: mixed}> Globals saved before the test. */
	private array $saved_globals = array();

	protected function setUp(): void {
		parent::setUp();

		foreach ( array( 'post', 'wp_query' ) as $name ) {
			$this->saved_globals[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}

		// Links are escaped as WordPress does it: characters that cannot appear in an
		// address (quotes, spaces, angle brackets) are removed.
		Functions\when( 'esc_url' )->alias( array( self::class, 'esc_url_like_wordpress' ) );

		Functions\when( 'set_query_var' )->alias(
			function ( $key, $value ) {
				$this->query_vars[ $key ] = $value;
			}
		);
		Functions\when( 'get_query_var' )->alias(
			fn( $key, $default = '' ) => $this->query_vars[ $key ] ?? $default
		);

		// The language and the page addresses come from other theme files that need
		// the language plugin; they are fixed here so each test sets only what it checks.
		Functions\when( 'nsw_theme_current_locale' )->justReturn( 'sq' );
		Functions\when( 'nsw_theme_home_url' )->justReturn( 'https://nsw.test/' );
		Functions\when( 'nsw_theme_path_url' )->alias( static fn( $key ) => 'https://nsw.test/' . $key . '/' );

		// Another test file may already have created stand-ins for these language plugin
		// functions in this PHP process; make them behave as "no translation, no language".
		if ( function_exists( 'pll_translate_string' ) ) {
			Functions\when( 'pll_translate_string' )->returnArg( 1 );
		}
		if ( function_exists( 'pll_current_language' ) ) {
			Functions\when( 'pll_current_language' )->justReturn( false );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved_globals as $name => list( $had, $value ) ) {
			if ( $had ) {
				$GLOBALS[ $name ] = $value;
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		parent::tearDown();
	}

	/* ------------------------------------------------------------------ helpers */

	/** A small copy of the WordPress esc_url() character filter. */
	public static function esc_url_like_wordpress( $url ): string {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$url = (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );
		if ( false === strpos( $url, ':' ) && ! in_array( $url[0] ?? '', array( '/', '#', '?' ), true ) ) {
			$url = 'http://' . $url;
		}
		return str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url );
	}

	/** Template parts print a short marker and are recorded with the state at that moment. */
	private function spy_template_parts(): void {
		Functions\when( 'get_template_part' )->alias(
			function ( $slug, $name = null, $args = array() ) {
				$this->parts[] = array(
					'slug' => $slug,
					'name' => $name,
					'args' => $args,
					'vars' => $this->query_vars,
					'post' => $GLOBALS['post'] ?? null,
				);
				$this->calls[] = 'part';
				echo '[part ' . $slug . ( null !== $name ? '-' . $name : '' ) . ']';
			}
		);
	}

	/** Template parts are rendered from the theme's own template files. */
	private function render_real_template_parts(): void {
		Functions\when( 'get_template_part' )->alias(
			function ( $slug, $name = null, $args = array() ) {
				$this->parts[] = array( 'slug' => $slug, 'name' => $name );
				require NSW_TEST_THEME_DIR . $slug . ( null !== $name ? '-' . $name : '' ) . '.php';
			}
		);
	}

	/** The theme's own callbacks on a hook that come from inc/blocks.php, in file order. */
	private function blocks_callbacks( string $hook ): array {
		$found = array();
		foreach ( nsw_test_hook_registrations( $hook ) as $reg ) {
			if ( ! $reg['callback'] instanceof \Closure ) {
				continue;
			}
			$ref = new \ReflectionFunction( $reg['callback'] );
			if ( str_ends_with( str_replace( '\\', '/', (string) $ref->getFileName() ), 'inc/blocks.php' ) ) {
				$found[ $ref->getStartLine() ] = $reg['callback'];
			}
		}
		ksort( $found );
		return array_values( $found );
	}

	/** Runs the block registration and returns the arguments of each registered block. */
	private function register_blocks(): array {
		$registered = array();
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'register_block_type' )->alias(
			function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
			}
		);
		$this->blocks_callbacks( 'init' )[0]();
		return $registered;
	}

	/** Runs the starter layout registration and returns what was registered. */
	private function register_pattern(): array {
		$out = array( 'pattern' => array(), 'category' => array() );
		Functions\when( 'register_block_pattern_category' )->alias(
			function ( $name, $props ) use ( &$out ) {
				$out['category'][ $name ] = $props;
			}
		);
		Functions\when( 'register_block_pattern' )->alias(
			function ( $name, $props ) use ( &$out ) {
				$out['pattern'][ $name ] = $props;
			}
		);
		$this->blocks_callbacks( 'init' )[1]();
		return $out;
	}

	/** A main query holding the given posts, as WordPress keeps it in $wp_query. */
	private function main_query( array $posts ): object {
		return new class( $posts ) {
			private int $index = -1;

			public function __construct( private array $posts ) {}

			public function have_posts(): bool {
				return $this->index + 1 < count( $this->posts );
			}

			public function the_post(): void {
				++$this->index;
				$GLOBALS['post'] = $this->posts[ $this->index ];
			}
		};
	}

	private function news_item( int $id, string $title ): \WP_Post {
		return new \WP_Post( array( 'ID' => $id, 'post_title' => $title, 'post_type' => 'post' ) );
	}

	/** Defines the WordPress OBJECT constant the menu lookup passes on. */
	private function define_object_constant(): void {
		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}
	}

	/** Navigation menus found by their address, as WordPress stores them. */
	private function navigation_menus( array $ids_by_slug ): void {
		Functions\when( 'get_page_by_path' )->alias(
			static function ( $slug, $output = 'OBJECT', $type = 'page' ) use ( $ids_by_slug ) {
				if ( 'wp_navigation' !== $type || ! isset( $ids_by_slug[ $slug ] ) ) {
					return null;
				}
				return new \WP_Post( array( 'ID' => $ids_by_slug[ $slug ], 'post_type' => 'wp_navigation' ) );
			}
		);
		Functions\when( 'do_blocks' )->alias( static fn( $markup ) => '<nav>' . $markup . '</nav>' );
	}

	private function two_languages(): array {
		return array(
			'sq' => array( 'slug' => 'sq', 'url' => 'https://nsw.test/', 'current_lang' => true ),
			'en' => array( 'slug' => 'en', 'url' => 'https://nsw.test/en/', 'current_lang' => false ),
		);
	}

	/* ------------------------------------------------------- template capturing */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A section template is collected and handed back instead of being printed straight away')]
	public function test_capture_part_returns_markup(): void {
		$seen = array();
		Functions\when( 'get_template_part' )->alias(
			static function ( $slug, $name = null, $args = array() ) use ( &$seen ) {
				$seen = array( $slug, $name, $args );
				echo '<section>Agencies</section>';
			}
		);

		ob_start();
		$html    = nsw_theme_capture_part( 'template-parts/cards/agency', 'card', array( 'agency' => 'gdc' ) );
		$printed = ob_get_clean();

		$this->assertSame( '<section>Agencies</section>', $html );
		$this->assertSame( '', $printed );
		$this->assertSame( array( 'template-parts/cards/agency', 'card', array( 'agency' => 'gdc' ) ), $seen );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A section template that prints nothing gives back an empty result')]
	public function test_capture_part_empty(): void {
		Functions\when( 'get_template_part' )->justReturn( null );
		$this->assertSame( '', nsw_theme_capture_part( 'template-parts/sections/stats' ) );
	}

	/* ---------------------------------------------------------- editable fields */

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images". */
	#[TestDox('Eight page sections offer fields that an editor can change')]
	public function test_editable_sections_listed(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->assertSame(
			array(
				'nsw-theme/hero-home',
				'nsw-theme/stats',
				'nsw-theme/what-is-nsw',
				'nsw-theme/agencies-preview',
				'nsw-theme/news-latest',
				'nsw-theme/partners',
				'nsw-theme/cta',
				'nsw-theme/partners-page',
			),
			array_keys( nsw_theme_block_fields() )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Every editable field has its own name, a label and a known kind of input')]
	public function test_fields_are_well_formed(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		foreach ( nsw_theme_block_fields() as $block => $fields ) {
			$names = array_column( $fields, 'attr' );
			$this->assertSame( count( $names ), count( array_unique( $names ) ), "Field names repeat in $block" );
			foreach ( $fields as $field ) {
				$this->assertNotSame( '', (string) $field['attr'], "A field of $block has no name" );
				$this->assertNotSame( '', (string) $field['label'], "{$field['attr']} of $block has no label" );
				$this->assertContains( $field['type'], array( 'text', 'textarea', 'url', 'media' ), "{$field['attr']} of $block" );
			}
		}
	}

	/** Source: TSD 2.3.1, "statistics in the NSW Stats block"; TSD 2.2.3, "Statistics entered by administrators". */
	#[TestDox('The statistics section offers four figures, each with a value and a label, for an administrator to fill in')]
	public function test_statistics_fields(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$fields = nsw_theme_block_fields()['nsw-theme/stats'];

		$this->assertSame( array( 'v1', 'l1', 'v2', 'l2', 'v3', 'l3', 'v4', 'l4' ), array_column( $fields, 'attr' ) );
		$this->assertSame( array_fill( 0, 8, 'text' ), array_column( $fields, 'type' ) );
		$this->assertSame(
			array( 'Stat 1 value', 'Stat 1 label', 'Stat 2 value', 'Stat 2 label', 'Stat 3 value', 'Stat 3 label', 'Stat 4 value', 'Stat 4 label' ),
			array_column( $fields, 'label' )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The standard links of the sections lead to the About, Contact, How it works, Agencies and News pages')]
	public function test_default_links(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$all     = nsw_theme_block_fields();
		$default = static fn( string $block, string $attr ) => array_column( $all[ $block ], 'default', 'attr' )[ $attr ];

		$this->assertSame( 'https://nsw.test/about/', $default( 'nsw-theme/hero-home', 'cta1Url' ) );
		$this->assertSame( 'https://nsw.test/contact/', $default( 'nsw-theme/hero-home', 'cta2Url' ) );
		$this->assertSame( 'https://nsw.test/how-it-works/', $default( 'nsw-theme/what-is-nsw', 'btnUrl' ) );
		$this->assertSame( 'https://nsw.test/agencies/', $default( 'nsw-theme/agencies-preview', 'seeAllUrl' ) );
		$this->assertSame( 'https://nsw.test/news/', $default( 'nsw-theme/news-latest', 'seeAllUrl' ) );
		$this->assertSame( 'https://nsw.test/contact/', $default( 'nsw-theme/cta', 'buttonUrl' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The private sector actors of the Partners page are offered one per line')]
	public function test_private_sector_actors_one_per_line(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'nsw_theme_get_content' )->justReturn(
			array( 'partnersPage' => array( 'privateSectorActors' => array( 'Importers', 'Exporters', 'Customs brokers' ) ) )
		);
		$fields = array_column( nsw_theme_block_fields()['nsw-theme/partners-page'], 'default', 'attr' );
		$this->assertSame( "Importers\nExporters\nCustoms brokers", $fields['privateActors'] );
	}

	/* ------------------------------------------------------- block attributes */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A block without editable fields gets no editable settings')]
	public function test_attributes_of_block_without_fields(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->assertSame( array(), nsw_theme_block_attributes( 'nsw-theme/brand-logo' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Text fields of a section are stored empty until an editor fills them in')]
	public function test_attributes_text_fields_start_empty(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$attributes = nsw_theme_block_attributes( 'nsw-theme/stats' );
		$this->assertSame( array( 'v1', 'l1', 'v2', 'l2', 'v3', 'l3', 'v4', 'l4' ), array_keys( $attributes ) );
		foreach ( $attributes as $name => $schema ) {
			$this->assertSame( array( 'type' => 'string', 'default' => '' ), $schema, $name );
		}
	}

	/** Source: TSD 2.3.3, nsw_theme_hero_video_id, "Home video and poster". */
	#[TestDox('The home banner background starts as the video kept in the media library')]
	public function test_attributes_background_from_media_library(): void {
		Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => 'nsw_theme_hero_video_id' === $name ? '77' : $default );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://nsw.test/wp-content/uploads/2026/11/home.webm' );
		Functions\when( 'get_post_mime_type' )->justReturn( 'video/webm' );

		$attributes = nsw_theme_block_attributes( 'nsw-theme/hero-home' );

		$this->assertSame( array( 'type' => 'string', 'default' => 'https://nsw.test/wp-content/uploads/2026/11/home.webm' ), $attributes['bgMedia'] );
		$this->assertSame( array( 'type' => 'number', 'default' => 77 ), $attributes['bgMediaId'] );
		$this->assertSame( array( 'type' => 'string', 'default' => 'video/webm' ), $attributes['bgMediaMime'] );
		$this->assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['title'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without a video in the media library, the home banner background starts as the video shipped with the theme')]
	public function test_attributes_background_from_theme(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$attributes = nsw_theme_block_attributes( 'nsw-theme/hero-home' );
		$this->assertSame( NSW_THEME_URI . 'assets/video/hero.mp4', $attributes['bgMedia']['default'] );
		$this->assertSame( 0, $attributes['bgMediaId']['default'] );
		$this->assertSame( 'video/mp4', $attributes['bgMediaMime']['default'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An image field is stored as an address and a media library number, both empty at first')]
	public function test_attributes_image_field(): void {
		Functions\when( 'nsw_theme_block_fields' )->justReturn(
			array( 'example/block' => array( array( 'attr' => 'photo', 'label' => 'Photo', 'type' => 'image' ) ) )
		);
		$this->assertSame(
			array(
				'photo'   => array( 'type' => 'string', 'default' => '' ),
				'photoId' => array( 'type' => 'number', 'default' => 0 ),
			),
			nsw_theme_block_attributes( 'example/block' )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A background field without a standard value starts empty')]
	public function test_attributes_media_field_without_defaults(): void {
		Functions\when( 'nsw_theme_block_fields' )->justReturn(
			array( 'example/block' => array( array( 'attr' => 'bg', 'label' => 'Background', 'type' => 'media' ) ) )
		);
		$this->assertSame(
			array(
				'bg'     => array( 'type' => 'string', 'default' => '' ),
				'bgId'   => array( 'type' => 'number', 'default' => 0 ),
				'bgMime' => array( 'type' => 'string', 'default' => '' ),
			),
			nsw_theme_block_attributes( 'example/block' )
		);
	}

	/* ---------------------------------------------------------- field values */

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('A value entered by an editor is used, without the spaces around it')]
	public function test_field_uses_editor_value(): void {
		$this->assertSame( 'Trade made simple', nsw_theme_field( array( 'title' => "  Trade made simple \n" ), 'title', 'Standard title' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A missing, empty or blank value falls back to the standard text')]
	public function test_field_falls_back(): void {
		$this->assertSame( 'Standard title', nsw_theme_field( array(), 'title', 'Standard title' ) );
		$this->assertSame( 'Standard title', nsw_theme_field( array( 'title' => '' ), 'title', 'Standard title' ) );
		$this->assertSame( 'Standard title', nsw_theme_field( array( 'title' => "  \t " ), 'title', 'Standard title' ) );
		$this->assertSame( '', nsw_theme_field( array( 'title' => '' ), 'title' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A value of 0 entered by an editor in the home banner or the call to action is kept and not replaced by the standard text')]
	public function test_field_zero_is_kept(): void {
		$this->assertSame( '0', nsw_theme_field( array( 'title' => '0' ), 'title', 'Standard title' ) );
		$this->assertSame( '0', nsw_theme_field( array( 'title' => 0 ), 'title', 'Standard title' ) );
		$this->assertSame( '42', nsw_theme_field( array( 'title' => 42 ), 'title', 'Standard title' ) );

		$this->render_real_template_parts();
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->assertStringContainsString( '<h1 class="hero--large__title">0</h1>', nsw_theme_block_hero_home( array( 'title' => '0' ) ) );
		$this->assertStringContainsString( '<h2 class="cta__title" data-reveal>0</h2>', nsw_theme_block_cta( array( 'title' => '0' ) ) );
	}

	/* -------------------------------------------------------------------- logos */

	/** Source: TSD 2.2.3, "Albanian wordmark on Albanian pages, English on English pages". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('English pages show the English logo in the header and its white version in the footer')]
	public function test_logos_on_english_pages(): void {
		Functions\when( 'pll_current_language' )->justReturn( 'en' );
		Functions\when( 'get_bloginfo' )->justReturn( 'NSW Albania' );

		$this->assertSame( 'nsw-logo.svg', nsw_theme_brand_logo_file( false ) );
		$this->assertSame( 'nsw-logo-en-light.svg', nsw_theme_brand_logo_file( true ) );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/images/logos/nsw-logo.svg' );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/images/logos/nsw-logo-en-light.svg' );
		$this->assertStringContainsString( 'logos/nsw-logo.svg"', nsw_theme_block_brand_logo() );
		$this->assertStringContainsString( 'logos/nsw-logo-en-light.svg"', nsw_theme_block_footer_logo() );
	}

	/** Source: TSD 2.2.3, "Albanian wordmark on Albanian pages, English on English pages". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Albanian pages show the Albanian logo in the header and its white version in the footer')]
	public function test_logos_on_albanian_pages(): void {
		Functions\when( 'pll_current_language' )->justReturn( 'sq' );
		Functions\when( 'get_bloginfo' )->justReturn( 'NSW Albania' );

		$this->assertSame( 'nsw-logo-alb.svg', nsw_theme_brand_logo_file( false ) );
		$this->assertSame( 'nsw-logo-alb-light.svg', nsw_theme_brand_logo_file( true ) );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/images/logos/nsw-logo-alb.svg' );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/images/logos/nsw-logo-alb-light.svg' );
		$this->assertStringContainsString( 'logos/nsw-logo-alb.svg"', nsw_theme_block_brand_logo() );
		$this->assertStringContainsString( 'logos/nsw-logo-alb-light.svg"', nsw_theme_block_footer_logo() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the page language is not known, the Albanian logo is shown')]
	public function test_logos_without_known_language(): void {
		$this->assertSame( 'nsw-logo-alb.svg', nsw_theme_brand_logo_file() );
		$this->assertSame( 'nsw-logo-alb-light.svg', nsw_theme_brand_logo_file( true ) );
	}

	/** Source: TSD 2.2.3, "Header: NSW logo". */
	#[TestDox('The header logo links to the home page and carries the site name as its text alternative')]
	public function test_header_logo_markup(): void {
		Functions\expect( 'get_bloginfo' )->once()->with( 'name' )->andReturn( 'NSW "Albania" & Partners' );

		$this->assertSame(
			'<a class="custom-logo-link site-header__logo" href="https://nsw.test/" rel="home">'
			. '<img class="custom-logo" src="' . NSW_THEME_URI . 'assets/images/logos/nsw-logo-alb.svg" alt="NSW &quot;Albania&quot; &amp; Partners" width="185" /></a>',
			nsw_theme_block_brand_logo()
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The footer logo links to the home page and carries a translated description for screen readers')]
	public function test_footer_logo_markup(): void {
		Functions\when( 'esc_attr__' )->alias(
			static fn( $text, $domain = 'default' ) => 'nsw-theme' === $domain && 'NSW Albania — National Single Window' === $text
				? 'NSW Shqipëri, Dritarja Unike Kombëtare'
				: $text
		);

		$this->assertSame(
			'<a class="site-footer__logo" href="https://nsw.test/">'
			. '<img src="' . NSW_THEME_URI . 'assets/images/logos/nsw-logo-alb-light.svg" alt="NSW Shqipëri, Dritarja Unike Kombëtare" width="185" height="60" /></a>',
			nsw_theme_block_footer_logo()
		);
	}

	/* -------------------------------------------------------------- footer text */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A footer text piece with no text chosen shows nothing')]
	public function test_footer_text_needs_a_key(): void {
		$this->assertSame( '', nsw_theme_block_footer_text() );
		$this->assertSame( '', nsw_theme_block_footer_text( array( 'tkey' => '', 'tag' => 'h3' ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A footer text is shown as a paragraph with its style class')]
	public function test_footer_text_paragraph(): void {
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => 'footer.email' === $key ? 'info@example.com' : $key );
		$this->assertSame(
			'<p class="site-footer__email">info@example.com</p>',
			nsw_theme_block_footer_text( array( 'tkey' => 'footer.email', 'cls' => 'site-footer__email' ) )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A footer text without a style class gets no class attribute')]
	public function test_footer_text_without_class(): void {
		Functions\when( 'nsw_theme_t' )->justReturn( 'Quick Links' );
		$this->assertSame( '<p>Quick Links</p>', nsw_theme_block_footer_text( array( 'tkey' => 'footer.quickLinks' ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A footer text can be shown as a heading or as a plain text element, whatever letter case its setting uses')]
	public function test_footer_text_allowed_elements(): void {
		Functions\when( 'nsw_theme_t' )->justReturn( 'Resources' );
		foreach ( array( 'h2', 'h3', 'h4', 'span', 'div' ) as $tag ) {
			$this->assertSame( "<$tag>Resources</$tag>", nsw_theme_block_footer_text( array( 'tkey' => 'footer.resources', 'tag' => $tag ) ) );
		}
		$this->assertSame( '<h3>Resources</h3>', nsw_theme_block_footer_text( array( 'tkey' => 'footer.resources', 'tag' => 'H3' ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Any other element name for a footer text falls back to a paragraph')]
	public function test_footer_text_other_elements(): void {
		Functions\when( 'nsw_theme_t' )->justReturn( 'Resources' );
		foreach ( array( 'script', 'a', 'h1', 'h5', '' ) as $tag ) {
			$this->assertSame( '<p>Resources</p>', nsw_theme_block_footer_text( array( 'tkey' => 'footer.resources', 'tag' => $tag ) ), "tag '$tag'" );
		}
	}

	/** Source: TSD 2.2.3, Footer: "copyright". */
	#[TestDox('The footer copyright line shows the current year')]
	public function test_footer_copyright_year(): void {
		Functions\when( 'nsw_theme_t' )->justReturn( '© {year} Example Single Window. All rights reserved.' );
		Functions\expect( 'date_i18n' )->once()->with( 'Y' )->andReturn( '2026' );

		$this->assertSame(
			'<p class="site-footer__copyright">© 2026 Example Single Window. All rights reserved.</p>',
			nsw_theme_block_footer_text( array( 'tkey' => 'footer.copyright', 'cls' => 'site-footer__copyright', 'year' => true ) )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without the year option, a footer text is shown exactly as written')]
	public function test_footer_text_without_year_option(): void {
		Functions\when( 'nsw_theme_t' )->justReturn( 'Since {year}' );
		Functions\expect( 'date_i18n' )->never();
		$this->assertSame( '<p>Since {year}</p>', nsw_theme_block_footer_text( array( 'tkey' => 'footer.since', 'year' => false ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Footer texts and style classes cannot add markup to the page')]
	public function test_footer_text_is_escaped(): void {
		Functions\when( 'nsw_theme_t' )->justReturn( '<script>alert(1)</script> & more' );
		$this->assertSame(
			'<p class="x&quot; onclick=&quot;alert(1)">&lt;script&gt;alert(1)&lt;/script&gt; &amp; more</p>',
			nsw_theme_block_footer_text( array( 'tkey' => 'footer.description', 'cls' => 'x" onclick="alert(1)' ) )
		);
	}

	/** Source: TSD 2.2.3, Footer: "quick links, resources, contact details" and "copyright, funding notice". */
	#[TestDox('The footer shows the site description, the Quick Links, Resources and Contact Info headings, the copyright with the current year and the funding notice')]
	public function test_footer_texts_as_configured_in_the_footer(): void {
		Functions\when( 'date_i18n' )->justReturn( '2026' );
		$footer = (string) file_get_contents( NSW_TEST_THEME_DIR . 'parts/footer.html' );
		preg_match_all( '#<!-- wp:nsw-theme/footer-text (\{.*?\}) /-->#', $footer, $matches );

		$html = '';
		foreach ( $matches[1] as $json ) {
			$html .= nsw_theme_block_footer_text( json_decode( $json, true ) ) . "\n";
		}

		// Each required piece must be there; more pieces may be added to the footer.
		$expected = array(
			'<p class="site-footer__description">Albania&#039;s National Single Window — The electronic platform for facilitating cross-border trade.</p>',
			'<h3 class="site-footer__heading">Quick Links</h3>',
			'<h3 class="site-footer__heading">Resources</h3>',
			'<h3 class="site-footer__heading">Contact Info</h3>',
			'<p class="site-footer__copyright">© 2026 National Single Window — Albania. All rights reserved.</p>',
			'<p class="site-footer__funding">Funded by the World Bank (IBRD) in support of trade facilitation in Albania.</p>',
		);
		foreach ( $expected as $piece ) {
			$this->assertStringContainsString( $piece . "\n", $html );
		}
		$this->assertStringNotContainsString( '{year}', $html );
	}

	/** Source: TSD 2.2.3, Footer: "contact details". */
	#[TestDox('The footer contact column shows the address, the email address and the opening hours as plain text')]
	public function test_footer_contact(): void {
		$texts = array(
			'footer.address' => 'Rruga "Example" 1, Tirana',
			'footer.email'   => 'info@example.com',
			'footer.hours'   => 'Mon <b>to</b> Fri, 08:00 & later',
		);
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => $texts[ $key ] ?? $fallback );

		$html = nsw_theme_block_footer_contact();

		$this->assertStringStartsWith( '<ul class="site-footer__contact">', trim( $html ) );
		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( '<span>Rruga &quot;Example&quot; 1, Tirana</span>', $html );
		$this->assertStringContainsString( '<span>info@example.com</span>', $html );
		$this->assertStringContainsString( '<span>Mon &lt;b&gt;to&lt;/b&gt; Fri, 08:00 &amp; later</span>', $html );
	}

	/* -------------------------------------------------------------------- menus */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A menu block without a chosen menu shows nothing')]
	public function test_nav_without_menu(): void {
		Functions\expect( 'get_page_by_path' )->never();
		$this->assertSame( '', nsw_theme_block_nav() );
		$this->assertSame( '', nsw_theme_block_nav( array( 'menu' => '' ) ) );
	}

	/** Source: TSD 2.2.3, Footer: "quick links, resources". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A footer menu block shows the chosen menu, without the mobile menu button')]
	public function test_nav_shows_chosen_menu(): void {
		$this->define_object_constant();
		$this->navigation_menus( array( 'nsw-footer-quick' => 31 ) );

		$this->assertSame(
			'<nav><!-- wp:navigation {"ref":31,"overlayMenu":"never"} /--></nav>',
			nsw_theme_block_nav( array( 'menu' => 'nsw-footer-quick' ) )
		);
	}

	/** Source: TSD 2.2.2, "Slugs and menus per language". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('English pages show the English version of a menu')]
	public function test_menu_in_english(): void {
		$this->define_object_constant();
		Functions\when( 'nsw_theme_current_locale' )->justReturn( 'en' );
		$this->navigation_menus( array( 'nsw-footer-quick' => 31, 'nsw-footer-quick-en' => 41 ) );

		$this->assertStringContainsString( '"ref":41,', nsw_theme_render_block_nav( 'nsw-footer-quick' ) );
	}

	/** Source: TSD 2.2.2, "Slugs and menus per language". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Albanian pages show the Albanian version of a menu')]
	public function test_menu_in_albanian(): void {
		$this->define_object_constant();
		$this->navigation_menus( array( 'nsw-footer-quick' => 31, 'nsw-footer-quick-en' => 41 ) );

		$this->assertStringContainsString( '"ref":31,', nsw_theme_render_block_nav( 'nsw-footer-quick' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('When the English version of a menu has not been created yet, English pages fall back to the Albanian menu')]
	public function test_menu_english_falls_back_to_albanian(): void {
		$this->define_object_constant();
		Functions\when( 'nsw_theme_current_locale' )->justReturn( 'en' );
		$this->navigation_menus( array( 'nsw-footer-resources' => 32 ) );

		$this->assertStringContainsString( '"ref":32,', nsw_theme_render_block_nav( 'nsw-footer-resources' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A menu that does not exist shows nothing')]
	public function test_missing_menu_shows_nothing(): void {
		$this->define_object_constant();
		$this->navigation_menus( array() );
		Functions\expect( 'do_blocks' )->never();

		$this->assertSame( '', nsw_theme_render_block_nav( 'nsw-footer-quick' ) );
	}

	/** Source: TSD 2.2.3, "main menu" and "Responsive, mobile menu". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The main menu in the header turns into a mobile menu button on small screens')]
	public function test_primary_menu_has_mobile_button(): void {
		$this->define_object_constant();
		$this->navigation_menus( array( 'nsw-primary' => 7 ) );

		$this->assertSame(
			'<nav><!-- wp:navigation {"ref":7,"overlayMenu":"mobile"} /--></nav>',
			nsw_theme_block_primary_nav()
		);
	}

	/* ---------------------------------------------------------- language switcher */

	/** Source: TSD 2.2.2, "switcher (SQ, EN) on every page". */
	#[TestDox('The language switcher shows SQ and EN, separated by a bar, and marks the current language')]
	public function test_language_switcher(): void {
		Functions\when( 'pll_the_languages' )->justReturn( $this->two_languages() );

		$this->assertSame(
			'<div class="lang-switcher">'
			. '<a class="lang-switcher__link is-active" href="https://nsw.test/" lang="sq" aria-current="true">SQ</a>'
			. '<span class="lang-switcher__sep" aria-hidden="true">|</span>'
			. '<a class="lang-switcher__link" href="https://nsw.test/en/" lang="en">EN</a>'
			. '</div>',
			nsw_theme_block_language_switcher()
		);
	}

	/** Source: TSD 2.2.2, "switcher (SQ, EN) on every page". */
	#[TestDox('The language switcher offers both languages, also on a page that has no translation yet')]
	public function test_language_switcher_lists_every_language(): void {
		Functions\expect( 'pll_the_languages' )
			->once()
			->with( array( 'raw' => 1, 'hide_if_no_translation' => 0, 'hide_current' => 0 ) )
			->andReturn( $this->two_languages() );

		$html = nsw_theme_block_language_switcher();

		$this->assertStringContainsString( '>SQ</a>', $html );
		$this->assertStringContainsString( '>EN</a>', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The language switcher shows nothing when no languages are set up')]
	public function test_language_switcher_without_languages(): void {
		Functions\when( 'pll_the_languages' )->justReturn( array() );
		$this->assertSame( '', nsw_theme_block_language_switcher() );

		Functions\when( 'pll_the_languages' )->justReturn( '' );
		$this->assertSame( '', nsw_theme_block_language_switcher() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A language without an address points to the top of the same page')]
	public function test_language_switcher_entry_without_address(): void {
		Functions\when( 'pll_the_languages' )->justReturn( array( array( 'slug' => 'en' ) ) );
		$this->assertSame(
			'<div class="lang-switcher"><a class="lang-switcher__link" href="#" lang="en">EN</a></div>',
			nsw_theme_block_language_switcher()
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Language addresses and codes cannot break the markup of the switcher')]
	public function test_language_switcher_is_escaped(): void {
		Functions\when( 'pll_the_languages' )->justReturn(
			array( array( 'slug' => 'en"><b', 'url' => 'https://nsw.test/en/"><script>alert(1)</script>' ) )
		);
		$html = nsw_theme_block_language_switcher();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b', $html );
		$this->assertStringContainsString( 'href="https://nsw.test/en/scriptalert(1)/script"', $html );
		$this->assertStringContainsString( 'lang="en&quot;&gt;&lt;b"', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, no language switcher is shown')]
	public function test_language_switcher_without_language_plugin(): void {
		$this->assertFalse( function_exists( 'pll_the_languages' ) );
		$this->assertSame( '', nsw_theme_block_language_switcher() );
	}

	/* --------------------------------------------------------- interior banner */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('On the news page the banner shows the title and summary of the page chosen for news')]
	public function test_page_banner_on_news_page(): void {
		$this->render_real_template_parts();
		Functions\when( 'is_home' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => 'page_for_posts' === $name ? '10' : $default );
		Functions\when( 'get_the_title' )->alias( static fn( $post = 0 ) => 10 === $post ? 'News' : 'First news item' );
		Functions\when( 'has_excerpt' )->alias( static fn( $post = 0 ) => 10 === $post );
		Functions\when( 'get_the_excerpt' )->alias( static fn( $post = null ) => 10 === $post ? 'Updates from the NSW team' : '' );

		$html = nsw_theme_block_page_hero();

		$this->assertStringContainsString( '<section class="hero hero--compact">', $html );
		$this->assertStringContainsString( '<h1 class="hero--compact__title">News</h1>', $html );
		$this->assertStringContainsString( '<p class="hero--compact__subtitle">Updates from the NSW team</p>', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('On the news page without a summary the banner shows only the title')]
	public function test_page_banner_on_news_page_without_summary(): void {
		$this->render_real_template_parts();
		Functions\when( 'is_home' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( '10' );
		Functions\when( 'get_the_title' )->justReturn( 'News' );
		Functions\when( 'has_excerpt' )->justReturn( false );
		Functions\expect( 'get_the_excerpt' )->never();

		$html = nsw_theme_block_page_hero();

		$this->assertStringContainsString( '<h1 class="hero--compact__title">News</h1>', $html );
		$this->assertStringNotContainsString( 'hero--compact__subtitle', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When no page is chosen for news, the news page banner uses the current title without a summary')]
	public function test_page_banner_without_news_page(): void {
		$this->render_real_template_parts();
		Functions\when( 'is_home' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( '0' );
		Functions\expect( 'get_the_title' )->once()->withNoArgs()->andReturn( 'Latest' );
		Functions\expect( 'has_excerpt' )->never();

		$html = nsw_theme_block_page_hero();

		$this->assertStringContainsString( '<h1 class="hero--compact__title">Latest</h1>', $html );
		$this->assertStringNotContainsString( 'hero--compact__subtitle', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('On an ordinary page the banner shows the page title and its summary')]
	public function test_page_banner_on_page(): void {
		$this->render_real_template_parts();
		$page = new \WP_Post( array( 'ID' => 22, 'post_title' => 'About NSW', 'post_type' => 'page' ) );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( $page );
		Functions\when( 'get_the_title' )->alias( static fn( $post = 0 ) => $post instanceof \WP_Post ? $post->post_title : 'Other' );
		Functions\when( 'has_excerpt' )->justReturn( true );
		Functions\when( 'get_the_excerpt' )->alias( static fn( $post = null ) => $post instanceof \WP_Post ? 'Who we are' : '' );

		$html = nsw_theme_block_page_hero();

		$this->assertStringContainsString( '<h1 class="hero--compact__title">About NSW</h1>', $html );
		$this->assertStringContainsString( '<p class="hero--compact__subtitle">Who we are</p>', $html );
		$this->assertSame( array( array( 'slug' => 'template-parts/sections/hero', 'name' => 'compact' ) ), $this->parts );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A page title with markup characters is shown as plain text in the banner')]
	public function test_page_banner_title_is_escaped(): void {
		$this->render_real_template_parts();
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Post( array( 'ID' => 23 ) ) );
		Functions\when( 'get_the_title' )->justReturn( 'Fees & <em>charges</em>' );
		Functions\when( 'has_excerpt' )->justReturn( false );

		$this->assertStringContainsString(
			'<h1 class="hero--compact__title">Fees &amp; &lt;em&gt;charges&lt;/em&gt;</h1>',
			nsw_theme_block_page_hero()
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the page is not a single item, the banner uses the current title without a summary')]
	public function test_page_banner_without_single_item(): void {
		$this->render_real_template_parts();
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( null );
		Functions\expect( 'get_the_title' )->once()->withNoArgs()->andReturn( 'Documents' );
		Functions\expect( 'has_excerpt' )->never();

		$html = nsw_theme_block_page_hero();

		$this->assertStringContainsString( '<h1 class="hero--compact__title">Documents</h1>', $html );
		$this->assertStringNotContainsString( 'hero--compact__subtitle', $html );
	}

	/* ---------------------------------------------------------- home page banner */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without editor changes, the home banner shows the standard title and text and two buttons to About and Contact')]
	public function test_home_banner_defaults(): void {
		$this->render_real_template_parts();
		Functions\when( 'get_option' )->justReturn( 0 );

		$html = nsw_theme_block_hero_home();

		$this->assertStringContainsString( '<h1 class="hero--large__title">Transforming Albanian Trade</h1>', $html );
		$this->assertStringContainsString(
			'<p class="hero--large__subtitle">The National Single Window — the single electronic entry point for all cross-border trade regulatory requirements in Albania.</p>',
			$html
		);
		$this->assertStringContainsString(
			'<div class="hero--large__actions"><a class="btn btn--lg" href="https://nsw.test/about/">Learn More</a>'
			. '<a class="btn btn--lg btn--secondary" href="https://nsw.test/contact/">Contact Us</a></div>',
			$html
		);
		$this->assertStringContainsString( '<source src="' . NSW_THEME_URI . 'assets/video/hero.mp4" type="video/mp4" />', $html );
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('Texts and links entered by an editor replace the standard ones in the home banner')]
	public function test_home_banner_editor_values(): void {
		$this->render_real_template_parts();
		Functions\when( 'get_option' )->justReturn( 0 );

		$html = nsw_theme_block_hero_home(
			array(
				'title'    => ' One window for trade ',
				'subtitle' => 'Submit once, for every agency.',
				'cta1Text' => 'Start here',
				'cta1Url'  => 'https://nsw.test/services/',
				'cta2Text' => 'Ask us',
				'cta2Url'  => 'https://nsw.test/support/',
			)
		);

		$this->assertStringContainsString( '<h1 class="hero--large__title">One window for trade</h1>', $html );
		$this->assertStringContainsString( '<p class="hero--large__subtitle">Submit once, for every agency.</p>', $html );
		$this->assertStringContainsString( '<a class="btn btn--lg" href="https://nsw.test/services/">Start here</a>', $html );
		$this->assertStringContainsString( '<a class="btn btn--lg btn--secondary" href="https://nsw.test/support/">Ask us</a>', $html );
		$this->assertStringNotContainsString( 'Transforming Albanian Trade', $html );
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('An image chosen as the home banner background is shown as an image instead of the video')]
	public function test_home_banner_image_background(): void {
		$this->render_real_template_parts();
		Functions\when( 'get_option' )->justReturn( 0 );

		$html = nsw_theme_block_hero_home(
			array( 'bgMedia' => 'https://nsw.test/wp-content/uploads/2026/11/port.jpg', 'bgMediaMime' => 'image/jpeg' )
		);

		$this->assertStringContainsString( '<img class="hero--large__media" src="https://nsw.test/wp-content/uploads/2026/11/port.jpg" alt="" aria-hidden="true" />', $html );
		$this->assertStringNotContainsString( '<video', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Button labels, links and texts entered by an editor cannot break the markup of the home banner')]
	public function test_home_banner_is_escaped(): void {
		$this->render_real_template_parts();
		Functions\when( 'get_option' )->justReturn( 0 );

		$html = nsw_theme_block_hero_home(
			array(
				'title'    => 'Trade <em>now</em>',
				'cta1Text' => '<img src=x onerror=alert(1)>',
				'cta1Url'  => 'https://example.com/apply" onmouseover="alert(1)',
			)
		);

		$this->assertStringContainsString( '<h1 class="hero--large__title">Trade &lt;em&gt;now&lt;/em&gt;</h1>', $html );
		$this->assertStringContainsString( '>&lt;img src=x onerror=alert(1)&gt;</a>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'href="https://example.com/applyonmouseover=alert(1)"', $html );
		$this->assertStringNotContainsString( '" onmouseover', $html );
	}

	/** Source: TSD 2.2.3, "the muted home page video". */
	#[TestDox('The home page video plays without sound')]
	public function test_home_video_is_muted(): void {
		$this->render_real_template_parts();
		Functions\when( 'get_option' )->justReturn( 0 );

		$html = nsw_theme_block_hero_home();

		$this->assertSame( 1, preg_match( '#<video\b([^>]*)>#', $html, $video ) );
		$this->assertMatchesRegularExpression( '#\smuted\s#', $video[1] );
	}

	/** Source: TSD 2.2.3, "the muted home page video has a pause button". */
	#[Group('tsd-difference')]
	#[TestDox('The home page video comes with a button to pause it')]
	public function test_home_video_has_pause_button(): void {
		$this->render_real_template_parts();
		Functions\when( 'get_option' )->justReturn( 0 );

		$html = nsw_theme_block_hero_home();

		$this->assertStringContainsString( '<video', $html );
		$this->assertMatchesRegularExpression( '/<button\b/i', $html );
	}

	/* --------------------------------------------------------------- statistics */

	/** Source: TSD 2.3.1, "statistics in the NSW Stats block"; TSD 2.2.3, "Statistics entered by administrators". */
	#[TestDox('Figures and labels entered by an administrator appear in the statistics section of the home page')]
	public function test_statistics_entered_by_administrator(): void {
		$this->render_real_template_parts();

		$html = nsw_theme_block_stats(
			array(
				'v1' => ' 25 ',
				'l1' => 'Connected agencies',
				'v2' => '80%',
				'l2' => 'Less paper',
				'v3' => 'n/a',
				'l3' => 'Open data',
				'v4' => '200+',
				'l4' => 'Online forms',
			)
		);

		$this->assertSame( '25', $this->query_vars['nsw_theme_stats_v1'] );
		$this->assertStringContainsString( 'data-stat-target="25" data-stat-suffix=""', $html );
		$this->assertStringContainsString( '<div class="stats__label">Connected agencies</div>', $html );
		$this->assertStringContainsString( 'data-stat-target="80" data-stat-suffix="%"', $html );
		$this->assertStringContainsString( '<div class="stats__label">Less paper</div>', $html );
		$this->assertStringContainsString( '<div class="stats__value">n/a</div>', $html );
		$this->assertStringContainsString( '<div class="stats__label">Open data</div>', $html );
		$this->assertStringContainsString( 'data-stat-target="200" data-stat-suffix="+"', $html );
		$this->assertStringContainsString( '<div class="stats__label">Online forms</div>', $html );
	}

	/** Source: TSD 2.2.3, "Statistics entered by administrators". */
	#[TestDox('A statistic left blank or filled only with spaces counts as not entered')]
	public function test_statistics_blank_input(): void {
		$this->spy_template_parts();

		$html = nsw_theme_block_stats( array( 'v1' => '', 'l1' => "   \t", 'v2' => ' 9 ' ) );

		$this->assertSame( '[part template-parts/sections/stats]', $html );
		$vars = $this->parts[0]['vars'];
		$this->assertSame( '9', $vars['nsw_theme_stats_v2'] );
		foreach ( array( 'v1', 'l1', 'l2', 'v3', 'l3', 'v4', 'l4' ) as $key ) {
			$this->assertSame( '', $vars[ 'nsw_theme_stats_' . $key ], $key );
		}
	}

	/* ------------------------------------------------- other home page sections */

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('Texts entered by an editor in the What is NSW section are passed on without surrounding spaces, and fields left blank are passed on blank')]
	public function test_what_is_nsw_section(): void {
		$this->spy_template_parts();

		$html = nsw_theme_block_what_is_nsw( array( 'title' => '  What is the NSW? ', 'f2Title' => 'Open data', 'btnUrl' => 'https://nsw.test/how-it-works/' ) );

		$this->assertSame( '[part template-parts/sections/what-is-nsw]', $html );
		$vars = $this->parts[0]['vars'];
		$this->assertSame( 'What is the NSW?', $vars['nsw_theme_wisnsw_title'] );
		$this->assertSame( 'Open data', $vars['nsw_theme_wisnsw_f2Title'] );
		$this->assertSame( 'https://nsw.test/how-it-works/', $vars['nsw_theme_wisnsw_btnUrl'] );
		foreach ( array( 'desc1', 'desc2', 'f1Title', 'f1Desc', 'f2Desc', 'f3Title', 'f3Desc', 'btnText' ) as $key ) {
			$this->assertSame( '', $vars[ 'nsw_theme_wisnsw_' . $key ], $key );
		}
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('The texts of the Latest News section are passed on without surrounding spaces, and empty fields stay empty')]
	public function test_latest_news_section(): void {
		$this->spy_template_parts();

		$html = nsw_theme_block_news_latest( array( 'title' => ' Latest from NSW ', 'seeAllUrl' => 'https://nsw.test/news/' ) );

		$this->assertSame( '[part template-parts/sections/news-latest]', $html );
		$this->assertSame(
			array(
				'nsw_theme_news_title'      => 'Latest from NSW',
				'nsw_theme_news_subtitle'   => '',
				'nsw_theme_news_seeAllText' => '',
				'nsw_theme_news_seeAllUrl'  => 'https://nsw.test/news/',
			),
			$this->parts[0]['vars']
		);
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('The title and subtitle of the Partners section are passed on without surrounding spaces')]
	public function test_partners_section(): void {
		$this->spy_template_parts();

		$html = nsw_theme_block_partners( array( 'title' => 'Our partners ', 'subtitle' => '' ) );

		$this->assertSame( '[part template-parts/sections/partners]', $html );
		$this->assertSame(
			array( 'nsw_theme_partners_title' => 'Our partners', 'nsw_theme_partners_subtitle' => '' ),
			$this->parts[0]['vars']
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without editor changes, the agencies section shows its standard title and text, up to eight agencies and a button to all agencies')]
	public function test_agencies_section_defaults(): void {
		$this->spy_template_parts();

		$html = nsw_theme_block_agencies_preview();

		$this->assertStringContainsString( '<h2 class="section-heading__title">Participating Agencies</h2>', $html );
		$this->assertStringContainsString( '<p class="section-heading__subtitle">14 cross-border regulatory agencies connected to NSW</p>', $html );
		$this->assertMatchesRegularExpression( '#<a class="btn btn--outline" href="https://nsw.test/agencies/">\s*View all agencies#', $html );
		$this->assertSame( 'template-parts/sections/agencies-grid', $this->parts[0]['slug'] );
		$this->assertSame( array(), $this->parts[0]['vars']['nsw_theme_agencies_list'] );
		$this->assertSame( 8, $this->parts[0]['vars']['nsw_theme_agencies_limit'] );
		$this->assertFalse( $this->parts[0]['vars']['nsw_theme_agencies_show_documents'] );
		$this->assertLessThan( strpos( $html, 'section-actions' ), strpos( $html, '[part template-parts/sections/agencies-grid]' ) );
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('Texts and the link entered by an editor replace the standard ones in the agencies section, as plain text')]
	public function test_agencies_section_editor_values(): void {
		$this->spy_template_parts();

		$html = nsw_theme_block_agencies_preview(
			array(
				'title'      => 'Agencies & <b>bodies</b>',
				'subtitle'   => 'All connected agencies',
				'seeAllText' => 'See them all',
				'seeAllUrl'  => 'https://nsw.test/agencies/?type="all"',
			)
		);

		$this->assertStringContainsString( '<h2 class="section-heading__title">Agencies &amp; &lt;b&gt;bodies&lt;/b&gt;</h2>', $html );
		$this->assertStringContainsString( '<p class="section-heading__subtitle">All connected agencies</p>', $html );
		$this->assertMatchesRegularExpression( '#href="https://nsw.test/agencies/\?type=all">\s*See them all#', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without editor changes, the call to action shows its standard question and a button to the Contact page')]
	public function test_call_to_action_defaults(): void {
		$this->render_real_template_parts();

		$html = nsw_theme_block_cta();

		$this->assertStringContainsString( '<h2 class="cta__title" data-reveal>Have questions about NSW?</h2>', $html );
		$this->assertStringContainsString( 'href="https://nsw.test/contact/">Contact Us</a>', $html );
		foreach ( array( 'title', 'description', 'button', 'href' ) as $key ) {
			$this->assertSame( '', $this->query_vars[ 'nsw_theme_cta_' . $key ], $key );
		}
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('Texts and the link entered by an editor replace the standard ones in the call to action')]
	public function test_call_to_action_editor_values(): void {
		$this->render_real_template_parts();

		$html = nsw_theme_block_cta(
			array(
				'title'       => ' Need help? ',
				'description' => 'Write to the Help Desk.',
				'buttonText'  => 'Open a ticket',
				'buttonUrl'   => 'https://nsw.test/support/',
			)
		);

		$this->assertStringContainsString( '<h2 class="cta__title" data-reveal>Need help?</h2>', $html );
		$this->assertStringContainsString( '>Write to the Help Desk.</p>', $html );
		$this->assertStringContainsString( 'href="https://nsw.test/support/">Open a ticket</a>', $html );
	}

	/* ---------------------------------------------------------------- news list */

	/** Source: TSD 2.2.3, Screens: "News (list, article)". */
	#[TestDox('The news list shows one card per news item in a grid, followed by previous and next page links')]
	public function test_news_list_with_items(): void {
		$this->spy_template_parts();
		$first  = $this->news_item( 101, 'New LPCO module' );
		$second = $this->news_item( 102, 'Training for traders' );
		$GLOBALS['wp_query'] = $this->main_query( array( $first, $second ) );
		Functions\when( 'nsw_theme_current_locale' )->justReturn( 'en' );
		Functions\when( 'get_post' )->alias( static fn() => $GLOBALS['post'] );
		Functions\expect( 'wp_reset_postdata' )->once()->andReturnUsing(
			function () {
				$this->calls[] = 'reset';
			}
		);
		$pagination = array();
		Functions\when( 'the_posts_pagination' )->alias(
			static function ( $args ) use ( &$pagination ) {
				$pagination = $args;
				echo '[pages]';
			}
		);

		$html = nsw_theme_block_news_list();

		$this->assertSame(
			'<section class="section"><div class="container"><div class="grid grid--lg-3">'
			. '[part template-parts/cards/news-card][part template-parts/cards/news-card]'
			. '</div>[pages]</div></section>',
			$html
		);
		$this->assertSame( $first, $this->parts[0]['vars']['nsw_theme_card_post'] );
		$this->assertSame( $second, $this->parts[1]['vars']['nsw_theme_card_post'] );
		$this->assertSame( 'en', $this->parts[1]['vars']['nsw_theme_card_locale'] );
		$this->assertSame( array(), $this->parts[1]['vars']['nsw_theme_card_data'] );
		$this->assertSame( array( 'part', 'part', 'reset' ), $this->calls );
		$this->assertSame( array( 'mid_size' => 1, 'prev_text' => 'Previous', 'next_text' => 'Next' ), $pagination );
	}

	/* ----------------------------------------------- single article and guide */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The article block shows nothing when the page is not a single item')]
	public function test_single_article_without_item(): void {
		Functions\when( 'get_queried_object' )->justReturn( (object) array( 'term_id' => 5, 'taxonomy' => 'category' ) );
		Functions\expect( 'setup_postdata' )->never();
		Functions\expect( 'get_template_part' )->never();

		$this->assertSame( '', nsw_theme_block_single_post() );
	}

	/** Source: TSD 2.2.3, Screens: "News (list, article)". */
	#[TestDox('The article block shows the article layout for the requested news item')]
	public function test_single_article(): void {
		$this->spy_template_parts();
		$article = $this->news_item( 205, 'Customs extends opening hours' );
		Functions\when( 'get_queried_object' )->justReturn( $article );
		Functions\expect( 'setup_postdata' )->once()->with( \Mockery::on( static fn( $p ) => $p === $article ) )->andReturnUsing(
			function () {
				$this->calls[] = 'setup';
				return true;
			}
		);
		Functions\expect( 'wp_reset_postdata' )->once()->andReturnUsing(
			function () {
				$this->calls[] = 'reset';
			}
		);

		$html = nsw_theme_block_single_post();

		$this->assertSame( '[part template-parts/single-news]', $html );
		$this->assertSame( $article, $this->parts[0]['post'] );
		$this->assertSame( array( 'setup', 'part', 'reset' ), $this->calls );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The service guide block shows nothing when the page is not a single item')]
	public function test_single_service_without_item(): void {
		Functions\when( 'get_queried_object' )->justReturn( null );
		Functions\expect( 'setup_postdata' )->never();
		Functions\expect( 'get_template_part' )->never();

		$this->assertSame( '', nsw_theme_block_single_service() );
	}

	/** Source: TSD 2.2.3, Screens: "one page per service guide". */
	#[TestDox('The service guide block shows the guide layout for the requested service guide')]
	public function test_single_service(): void {
		$this->spy_template_parts();
		$guide = new \WP_Post( array( 'ID' => 310, 'post_title' => 'Import of plants', 'post_type' => 'nsw_service' ) );
		Functions\when( 'get_queried_object' )->justReturn( $guide );
		Functions\expect( 'setup_postdata' )->once()->with( \Mockery::on( static fn( $p ) => $p === $guide ) )->andReturnUsing(
			function () {
				$this->calls[] = 'setup';
				return true;
			}
		);
		Functions\expect( 'wp_reset_postdata' )->once()->andReturnUsing(
			function () {
				$this->calls[] = 'reset';
			}
		);

		$html = nsw_theme_block_single_service();

		$this->assertSame( '[part template-parts/single-service]', $html );
		$this->assertSame( $guide, $this->parts[0]['post'] );
		$this->assertSame( array( 'setup', 'part', 'reset' ), $this->calls );
	}

	/* ------------------------------------------------------------------- 404 */

	/** Source: TSD 2.2.3, Screens: "404". */
	#[TestDox('The not-found page shows 404, a title, an explanation and a button back to the home page')]
	public function test_not_found_page(): void {
		$html = nsw_theme_block_not_found();

		$this->assertMatchesRegularExpression( '#>404</div>#', $html );
		$this->assertStringContainsString( '<h1 style="margin-top:1rem">Page Not Found</h1>', $html );
		$this->assertStringContainsString( '>The page you are looking for does not exist or has been moved.</p>', $html );
		$this->assertStringContainsString( '<a class="btn btn--lg" href="https://nsw.test/">Back to Homepage</a>', $html );
	}

	/** Source: TSD 2.2.3, "Albanian (default) and English for all public pages, menus and interface texts". */
	#[TestDox('The not-found page shows its texts in the language of the page')]
	public function test_not_found_page_translated(): void {
		$texts = array(
			'notFound.title'       => 'Faqja nuk u gjet',
			'notFound.description' => 'Faqja që kërkoni nuk ekziston.',
			'notFound.backHome'    => 'Kthehu në faqen kryesore',
		);
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => $texts[ $key ] ?? $fallback );

		$html = nsw_theme_block_not_found();

		$this->assertStringContainsString( '>Faqja nuk u gjet</h1>', $html );
		$this->assertStringContainsString( '>Faqja që kërkoni nuk ekziston.</p>', $html );
		$this->assertStringContainsString( '>Kthehu në faqen kryesore</a>', $html );
		$this->assertStringNotContainsString( 'Page Not Found', $html );
	}

	/* ------------------------------------------------------------ registration */

	/** Source: TSD 2.2.1, "Theme templates, blocks and patterns". */
	#[TestDox('The theme blocks appear in their own NSW Theme group in the block inserter, before the other groups')]
	public function test_block_inserter_group(): void {
		$add_group = $this->blocks_callbacks( 'block_categories_all' )[0];
		$this->assertSame(
			array(
				array( 'slug' => 'nsw-theme', 'title' => 'NSW Theme' ),
				array( 'slug' => 'text', 'title' => 'Text' ),
			),
			$add_group( array( array( 'slug' => 'text', 'title' => 'Text' ) ) )
		);
	}

	/** Source: TSD 2.2.1, "Theme templates, blocks and patterns". */
	#[TestDox('The theme registers all of its blocks in its own group')]
	public function test_registers_all_blocks(): void {
		$registered = $this->register_blocks();

		$this->assertSame(
			array(
				'nsw-theme/brand-logo',
				'nsw-theme/footer-logo',
				'nsw-theme/footer-contact',
				'nsw-theme/page-hero',
				'nsw-theme/primary-nav',
				'nsw-theme/language-switcher',
				'nsw-theme/news-list',
				'nsw-theme/single-post',
				'nsw-theme/single-service',
				'nsw-theme/not-found',
				'nsw-theme/hero-home',
				'nsw-theme/stats',
				'nsw-theme/what-is-nsw',
				'nsw-theme/agencies-preview',
				'nsw-theme/news-latest',
				'nsw-theme/partners',
				'nsw-theme/cta',
				'nsw-theme/footer-text',
				'nsw-theme/nav',
			),
			array_keys( $registered )
		);
		foreach ( $registered as $name => $args ) {
			$this->assertSame( 3, $args['api_version'], $name );
			$this->assertSame( 'nsw-theme', $args['category'], $name );
			$this->assertNotSame( '', $args['title'], $name );
		}
		$this->assertSame( 'NSW Stats', $registered['nsw-theme/stats']['title'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('No theme block can be edited as raw HTML or saved as a reusable block')]
	public function test_blocks_not_html_editable_or_reusable(): void {
		foreach ( $this->register_blocks() as $name => $args ) {
			$this->assertFalse( $args['supports']['html'], $name );
			$this->assertFalse( $args['supports']['reusable'], $name );
		}
	}

	/** Source: TSD 2.1.1, "Custom blocks let editors change texts and images without code changes". */
	#[TestDox('Editable home page sections offer spacing controls and their editable fields')]
	public function test_sections_have_spacing_and_fields(): void {
		$registered = $this->register_blocks();

		foreach ( array( 'hero-home', 'stats', 'what-is-nsw', 'agencies-preview', 'news-latest', 'partners', 'cta' ) as $short ) {
			$args = $registered[ 'nsw-theme/' . $short ];
			$this->assertSame( array( 'margin' => array( 'top', 'bottom' ), 'padding' => true ), $args['supports']['spacing'], $short );
			$this->assertSame( nsw_theme_block_attributes( 'nsw-theme/' . $short ), $args['attributes'], $short );
			$this->assertInstanceOf( \Closure::class, $args['render_callback'], $short );
		}
		$this->assertArrayHasKey( 'bgMediaMime', $registered['nsw-theme/hero-home']['attributes'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Header, footer and page blocks without editable fields have no spacing controls or settings')]
	public function test_fixed_blocks_have_no_settings(): void {
		$registered = $this->register_blocks();
		$fixed      = array(
			'brand-logo', 'footer-logo', 'footer-contact', 'page-hero', 'primary-nav',
			'language-switcher', 'news-list', 'single-post', 'single-service', 'not-found',
		);

		foreach ( $fixed as $short ) {
			$args = $registered[ 'nsw-theme/' . $short ];
			$this->assertArrayNotHasKey( 'spacing', $args['supports'], $short );
			$this->assertArrayNotHasKey( 'attributes', $args, $short );
			$this->assertSame( 'nsw_theme_block_' . str_replace( '-', '_', $short ), $args['render_callback'], $short );
		}
	}

	/** Source: TSD 2.2.3, Footer: "copyright, funding notice". */
	#[TestDox('A footer text can be set to a chosen text, element, style and current year, and a footer menu to a chosen menu')]
	public function test_footer_helper_block_settings(): void {
		$registered = $this->register_blocks();

		$this->assertSame( 'nsw_theme_block_footer_text', $registered['nsw-theme/footer-text']['render_callback'] );
		$this->assertSame(
			array(
				'tkey' => array( 'type' => 'string', 'default' => '' ),
				'tag'  => array( 'type' => 'string', 'default' => 'p' ),
				'cls'  => array( 'type' => 'string', 'default' => '' ),
				'year' => array( 'type' => 'boolean', 'default' => false ),
			),
			$registered['nsw-theme/footer-text']['attributes']
		);
		$this->assertSame( 'nsw_theme_block_nav', $registered['nsw-theme/nav']['render_callback'] );
		$this->assertSame( array( 'menu' => array( 'type' => 'string', 'default' => '' ) ), $registered['nsw-theme/nav']['attributes'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An editable section is wrapped so that the spacing chosen by the editor is applied on the page')]
	public function test_section_is_wrapped(): void {
		$render = $this->register_blocks()['nsw-theme/cta']['render_callback'];
		Functions\expect( 'nsw_theme_block_cta' )->once()->with( array( 'title' => 'Need help?' ) )->andReturn( '<section class="cta">Need help?</section>' );
		Functions\when( 'get_block_wrapper_attributes' )->justReturn( 'class="wp-block-nsw-theme-cta" style="margin-top:2rem"' );

		$this->assertSame(
			'<div class="wp-block-nsw-theme-cta" style="margin-top:2rem"><section class="cta">Need help?</section></div>',
			$render( array( 'title' => 'Need help?' ), '', null )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An editable section with nothing to show is not wrapped')]
	public function test_empty_section_is_not_wrapped(): void {
		$render = $this->register_blocks()['nsw-theme/partners']['render_callback'];
		Functions\when( 'nsw_theme_block_partners' )->justReturn( "  \n " );
		Functions\expect( 'get_block_wrapper_attributes' )->never();

		$this->assertSame( "  \n ", $render( array(), '', null ) );
	}

	/* --------------------------------------------------- service guide layout */

	/** Source: TSD 1.4, Service guide: "steps, required documents, fees and charges, processing time, contact and responsible agency" (the agency is a separate field, TSD 2.3.1). */
	#[TestDox('The service guide starter layout has an introduction followed by Steps, Required documents, Fees, Processing time and Contact')]
	public function test_service_guide_layout_sections(): void {
		$content = $this->register_pattern()['pattern']['nsw-theme/service-guide']['content'];

		preg_match_all( '#<h2 class="wp-block-heading">(.*?)</h2>#', $content, $headings );
		$this->assertSame( array( 'Steps', 'Required documents', 'Fees', 'Processing time', 'Contact' ), $headings[1] );
		$this->assertStringStartsWith( "<!-- wp:paragraph -->\n<p>[Replace: one short paragraph", $content );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $content );
		$this->assertSame( 5, substr_count( $content, '<li>[Replace: step ' ) );
		$this->assertStringContainsString( '<!-- wp:table -->', $content );
	}

	/** Source: TSD 2.2.1, "Theme templates, blocks and patterns". */
	#[TestDox('The service guide starter layout is offered only when writing a service guide, in the NSW Theme group')]
	public function test_service_guide_layout_scope(): void {
		$registered = $this->register_pattern();
		$pattern    = $registered['pattern']['nsw-theme/service-guide'];

		$this->assertSame( array( 'nsw-theme' => array( 'label' => 'NSW Theme' ) ), $registered['category'] );
		$this->assertSame( 'Service guide', $pattern['title'] );
		$this->assertSame( array( 'nsw-theme' ), $pattern['categories'] );
		$this->assertSame( array( 'nsw_service' ), $pattern['postTypes'] );
		$this->assertSame( array( 'core/post-content' ), $pattern['blockTypes'] );
	}

	/** Source: TSD 2.2.2, "Interface texts: English source in the theme, translated in Polylang". */
	#[TestDox('The headings of the service guide starter layout are in the language of the editor, as plain text')]
	public function test_service_guide_layout_language(): void {
		$texts = array(
			'servicesPage.stepsTitle'      => 'Hapat',
			'servicesPage.documentsTitle'  => 'Dokumentet e kërkuara',
			'servicesPage.feesTitle'       => 'Tarifat & pagesat',
			'servicesPage.processingTitle' => 'Koha e përpunimit',
			'servicesPage.contactTitle'    => 'Kontakti',
		);
		Functions\when( 'nsw_theme_t' )->alias( static fn( $key, $fallback = '' ) => $texts[ $key ] ?? $fallback );

		$content = $this->register_pattern()['pattern']['nsw-theme/service-guide']['content'];

		preg_match_all( '#<h2 class="wp-block-heading">(.*?)</h2>#', $content, $headings );
		$this->assertSame(
			array( 'Hapat', 'Dokumentet e kërkuara', 'Tarifat &amp; pagesat', 'Koha e përpunimit', 'Kontakti' ),
			$headings[1]
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without block pattern support in WordPress, no starter layout is registered')]
	public function test_service_guide_layout_without_pattern_support(): void {
		$this->assertFalse( function_exists( 'register_block_pattern' ) );
		Functions\expect( 'register_block_pattern_category' )->never();

		$this->assertNull( $this->blocks_callbacks( 'init' )[1]() );
	}
}
