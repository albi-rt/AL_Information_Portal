<?php
/**
 * Unit tests for inc/taxonomies.php: the category lists of events and documents, and
 * the categories the theme creates on its own.
 *
 * The file adds the event type list and the document category list, creates the
 * default news categories when they are missing, and creates the default document
 * categories when the theme is activated. The FAQ category list is registered in
 * inc/post-types.php; whether its categories are created is checked here, with the
 * other categories the theme creates.
 *
 * WordPress is not running: the registrations and the categories the file would
 * create are captured and checked. Expected behaviour comes from the Information
 * Portal Technical Specification (TSD), sections 2.2 and 2.3. Where today's code
 * differs from the TSD, the test expects the TSD and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

final class TaxonomiesTest extends TestCase {

	/** @var array<string, array{object_type: mixed, args: array<string, mixed>}> */
	private array $taxonomies = array();

	/** @var array<int, array{name: string, taxonomy: string, slug: string}> categories created */
	private array $inserted = array();

	/** @var array<int, array{0: string, 1: string}> slug and list asked about */
	private array $looked_up = array();

	/**
	 * The callbacks a theme file registered on a hook, in the order it added them.
	 *
	 * @return array<int, array{callback: callable, priority: int, accepted_args: int}>
	 */
	private function registrations( string $hook, string $file = 'taxonomies.php' ): array {
		return array_values(
			array_filter(
				nsw_test_hook_registrations( $hook ),
				static function ( array $reg ) use ( $file ): bool {
					if ( ! $reg['callback'] instanceof \Closure ) {
						return false;
					}
					$path = ( new \ReflectionFunction( $reg['callback'] ) )->getFileName();
					return str_ends_with( str_replace( '\\', '/', (string) $path ), '/inc/' . $file );
				}
			)
		);
	}

	/**
	 * Captures list registrations and category creation.
	 *
	 * @param array<int, string> $existing "list:slug" of categories that already exist
	 */
	private function capture( array $existing = array() ): void {
		Functions\when( 'register_taxonomy' )->alias(
			function ( $name, $object_type, $args = array() ) {
				$this->taxonomies[ $name ] = array( 'object_type' => $object_type, 'args' => $args );
				return true;
			}
		);
		Functions\when( 'term_exists' )->alias(
			function ( $term, $taxonomy = '' ) use ( $existing ) {
				$this->looked_up[] = array( $term, $taxonomy );
				return in_array( "$taxonomy:$term", $existing, true ) ? array( 'term_id' => '7', 'term_taxonomy_id' => '7' ) : null;
			}
		);
		Functions\when( 'wp_insert_term' )->alias(
			function ( $name, $taxonomy, $args = array() ) {
				$this->inserted[] = array( 'name' => $name, 'taxonomy' => $taxonomy, 'slug' => $args['slug'] ?? '' );
				return array( 'term_id' => 100 + count( $this->inserted ), 'term_taxonomy_id' => 100 + count( $this->inserted ) );
			}
		);
	}

	/** Runs what this file does when WordPress starts. */
	private function run_init( array $existing = array() ): void {
		$this->capture( $existing );
		foreach ( $this->registrations( 'init' ) as $reg ) {
			( $reg['callback'] )();
		}
	}

	/** Runs what this file does when the theme is activated. */
	private function run_activation( array $existing = array() ): void {
		$this->capture( $existing );
		foreach ( $this->registrations( 'after_switch_theme' ) as $reg ) {
			( $reg['callback'] )();
		}
	}

	/**
	 * Runs everything this file does, when WordPress starts and when the theme is
	 * activated. Used where the test checks which categories exist afterwards and not
	 * at which moment they were created.
	 */
	private function run_setup( array $existing = array() ): void {
		$this->capture( $existing );
		foreach ( array( 'init', 'after_switch_theme' ) as $hook ) {
			foreach ( $this->registrations( $hook ) as $reg ) {
				( $reg['callback'] )();
			}
		}
	}

	/** @return array<int, array{name: string, taxonomy: string, slug: string}> categories created in one list */
	private function created_rows( string $taxonomy ): array {
		return array_values( array_filter( $this->inserted, static fn( array $row ): bool => $taxonomy === $row['taxonomy'] ) );
	}

	/**
	 * Runs everything the content type files (this one and inc/post-types.php) do when
	 * WordPress starts and when the theme is activated, with no category existing yet.
	 */
	private function run_all_content_type_setup(): void {
		$this->capture();
		Functions\when( 'register_post_type' )->justReturn( new \stdClass() );
		Functions\when( 'register_post_meta' )->justReturn( true );
		Functions\when( 'add_post_type_support' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );
		foreach ( array( 'init', 'after_switch_theme' ) as $hook ) {
			foreach ( array( 'post-types.php', 'taxonomies.php' ) as $file ) {
				foreach ( $this->registrations( $hook, $file ) as $reg ) {
					( $reg['callback'] )();
				}
			}
		}
	}

	/** @return array<int, string> slugs created in one list */
	private function created_slugs( string $taxonomy ): array {
		return array_values(
			array_map(
				static fn( array $row ): string => $row['slug'],
				array_filter( $this->inserted, static fn( array $row ): bool => $taxonomy === $row['taxonomy'] )
			)
		);
	}

	/* --------------------------------------------------------------- the lists */

	/** Source: TSD 2.3.3, taxonomy "nsw_event_type"; TSD 2.2, content types "REST routes". */
	#[TestDox('Event types belong to events and are available through the website API')]
	public function test_event_types_registered(): void {
		$this->run_init( array( 'category:announcements', 'category:updates', 'category:events', 'category:partners' ) );
		$this->assertArrayHasKey( 'nsw_event_type', $this->taxonomies );
		$this->assertSame( 'nsw_event', $this->taxonomies['nsw_event_type']['object_type'] );
		$this->assertTrue( $this->taxonomies['nsw_event_type']['args']['show_in_rest'] );
	}

	/** Source: TSD 2.3.3, taxonomy "nsw_document_category"; TSD 2.2, content types "REST routes". */
	#[TestDox('Document categories belong to documents and are available through the website API')]
	public function test_document_categories_registered(): void {
		$this->run_init( array( 'category:announcements', 'category:updates', 'category:events', 'category:partners' ) );
		$this->assertArrayHasKey( 'nsw_document_category', $this->taxonomies );
		$this->assertSame( 'nsw_document', $this->taxonomies['nsw_document_category']['object_type'] );
		$this->assertTrue( $this->taxonomies['nsw_document_category']['args']['show_in_rest'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Event types and document categories can be nested and show as a column in the administration lists')]
	public function test_lists_nested_and_shown_as_column(): void {
		$this->run_init();
		foreach ( array( 'nsw_event_type', 'nsw_document_category' ) as $taxonomy ) {
			$this->assertTrue( $this->taxonomies[ $taxonomy ]['args']['hierarchical'], $taxonomy );
			$this->assertTrue( $this->taxonomies[ $taxonomy ]['args']['show_admin_column'], $taxonomy );
		}
		$this->assertCount( 2, $this->taxonomies );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Event types and document categories have their own names in the administration screens')]
	public function test_list_names(): void {
		$this->run_init();
		$this->assertSame(
			array( 'name' => 'Event Types', 'singular_name' => 'Event Type', 'menu_name' => 'Types' ),
			$this->taxonomies['nsw_event_type']['args']['labels']
		);
		$this->assertSame(
			array( 'name' => 'Document Categories', 'singular_name' => 'Document Category', 'menu_name' => 'Categories' ),
			$this->taxonomies['nsw_document_category']['args']['labels']
		);
	}

	/** Source: TSD 2.2, multilingual text "Interface texts: English source in the theme". */
	#[TestDox('The names of the lists and of the categories the theme creates are offered for translation')]
	public function test_names_are_translatable(): void {
		Functions\when( '__' )->alias( static fn( $text, $domain = 'default' ) => "[$domain] $text" );
		$this->run_setup();
		$this->assertCount( 2, $this->taxonomies );
		foreach ( $this->taxonomies as $taxonomy => $registration ) {
			foreach ( $registration['args']['labels'] as $key => $label ) {
				$this->assertStringStartsWith( '[nsw-theme] ', $label, "$taxonomy label $key" );
			}
		}
		$this->assertCount( 8, $this->inserted );
		foreach ( $this->inserted as $row ) {
			$this->assertStringStartsWith( '[nsw-theme] ', $row['name'], $row['slug'] );
		}
	}

	/** Source: TSD 2.3.3, taxonomy "String, max 32". */
	#[TestDox('The internal name of every category list fits in the 32 characters the database allows')]
	public function test_list_names_fit_the_database(): void {
		$this->run_all_content_type_setup();
		$this->assertEqualsCanonicalizing(
			array( 'nsw_event_type', 'nsw_document_category', 'nsw_faq_category' ),
			array_keys( $this->taxonomies )
		);
		foreach ( array_keys( $this->taxonomies ) as $taxonomy ) {
			$this->assertLessThanOrEqual( 32, strlen( $taxonomy ), "$taxonomy is longer than 32 characters" );
		}
	}

	/* ------------------------------------------------------- document categories */

	/** Source: TSD 2.3.3, "nsw_document_category (legal, guides, technical, templates)". */
	#[TestDox('When the theme is activated, the document categories Legal, Guides, Technical and Templates are created')]
	public function test_document_categories_created_on_activation(): void {
		$this->run_activation();
		$this->assertSame(
			array(
				array( 'name' => 'Legal', 'taxonomy' => 'nsw_document_category', 'slug' => 'legal' ),
				array( 'name' => 'Guides', 'taxonomy' => 'nsw_document_category', 'slug' => 'guides' ),
				array( 'name' => 'Technical', 'taxonomy' => 'nsw_document_category', 'slug' => 'technical' ),
				array( 'name' => 'Templates', 'taxonomy' => 'nsw_document_category', 'slug' => 'templates' ),
			),
			$this->inserted
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A document category that already exists is not created again')]
	public function test_existing_document_category_not_created_again(): void {
		$this->run_activation( array( 'nsw_document_category:guides' ) );
		$this->assertSame( array( 'legal', 'technical', 'templates' ), $this->created_slugs( 'nsw_document_category' ) );
		$this->assertContains( array( 'guides', 'nsw_document_category' ), $this->looked_up );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When all document categories already exist, activating the theme creates none')]
	public function test_no_document_category_created_when_all_exist(): void {
		$this->run_activation(
			array( 'nsw_document_category:legal', 'nsw_document_category:guides', 'nsw_document_category:technical', 'nsw_document_category:templates' )
		);
		$this->assertSame( array(), $this->inserted );
		$this->assertCount( 4, $this->looked_up );
	}

	/* ----------------------------------------------------------- news categories */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The news categories Announcements, Updates, Events and Partners are created when they are missing')]
	public function test_news_categories_created_when_missing(): void {
		$this->run_setup();
		$this->assertSame(
			array(
				array( 'name' => 'Announcements', 'taxonomy' => 'category', 'slug' => 'announcements' ),
				array( 'name' => 'Updates', 'taxonomy' => 'category', 'slug' => 'updates' ),
				array( 'name' => 'Events', 'taxonomy' => 'category', 'slug' => 'events' ),
				array( 'name' => 'Partners', 'taxonomy' => 'category', 'slug' => 'partners' ),
			),
			$this->created_rows( 'category' )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A news category that already exists is not created again')]
	public function test_existing_news_category_not_created_again(): void {
		$this->run_setup( array( 'category:announcements', 'category:events' ) );
		$this->assertSame( array( 'updates', 'partners' ), $this->created_slugs( 'category' ) );
		$this->assertContains( array( 'announcements', 'category' ), $this->looked_up );
		$this->assertContains( array( 'events', 'category' ), $this->looked_up );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When all news categories already exist, none is created')]
	public function test_no_news_category_created_when_all_exist(): void {
		$this->run_setup( array( 'category:announcements', 'category:updates', 'category:events', 'category:partners' ) );
		$this->assertSame( array(), $this->created_slugs( 'category' ) );
		$this->assertCount( 4, array_filter( $this->looked_up, static fn( array $q ): bool => 'category' === $q[1] ) );
	}

	/* ------------------------------------------ event types and FAQ categories */

	/** Source: TSD 2.3.3, "nsw_event_type (no terms)". */
	#[TestDox('The theme creates no event types of its own')]
	public function test_no_event_types_created(): void {
		$this->run_all_content_type_setup();
		$this->assertNotEmpty( $this->inserted );
		$this->assertSame( array(), $this->created_slugs( 'nsw_event_type' ) );
	}

	/** Source: TSD 2.3.3, "nsw_faq_category (general, registration, lpco, payments, technical, agencies)". */
	#[Group('tsd-difference')]
	#[TestDox('The six FAQ categories general, registration, LPCO, payments, technical and agencies are created when they are missing')]
	public function test_faq_categories_created(): void {
		$this->run_all_content_type_setup();
		$this->assertEqualsCanonicalizing(
			array( 'general', 'registration', 'lpco', 'payments', 'technical', 'agencies' ),
			$this->created_slugs( 'nsw_faq_category' ),
			'The FAQ categories are not created'
		);
	}
}
