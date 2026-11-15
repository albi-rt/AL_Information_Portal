<?php
/**
 * Unit tests for inc/post-types.php: the content types of the Information Portal.
 *
 * The file tells WordPress which kinds of content the portal has besides news items
 * and pages (events, documents, agencies, partners, FAQ items and service guides),
 * which of them have a public web address, which fields each one has, which
 * permissions are needed to edit them, which ones are kept as revisions, which fields
 * are shared between the Albanian and English versions, and when the web address
 * rules are refreshed. It also registers the FAQ category list.
 *
 * WordPress is not running: the registrations the file makes when WordPress starts
 * are captured and checked. Expected behaviour comes from the Information Portal
 * Technical Specification (TSD), sections 2.2, 2.3 and 2.5, and the Software
 * Development Package (SDP), section 5.6. Where today's code differs from the TSD,
 * the test expects the TSD and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

final class PostTypesTest extends TestCase {

	/** The six content types the theme adds. */
	private const TYPES = array( 'nsw_event', 'nsw_document', 'nsw_agency', 'nsw_partner', 'nsw_faq', 'nsw_service' );

	/** @var array<string, array<string, mixed>> content type => arguments given to WordPress */
	private array $post_types = array();

	/** @var array<string, array{object_type: mixed, args: array<string, mixed>}> */
	private array $taxonomies = array();

	/** @var array<string, array<string, array<string, mixed>>> content type => field key => arguments */
	private array $meta = array();

	/** @var array<int, array{0: string, 1: string}> content type and feature added later */
	private array $added_supports = array();

	/**
	 * The callbacks a theme file (by default inc/post-types.php) registered on a hook,
	 * in the order it added them.
	 *
	 * @return array<int, array{callback: callable, priority: int, accepted_args: int}>
	 */
	private function registrations( string $hook, string $file = 'post-types.php' ): array {
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

	/** Runs what the file does when WordPress starts, and captures every registration. */
	private function run_init(): void {
		Functions\when( 'register_post_type' )->alias(
			function ( $name, $args = array() ) {
				$this->post_types[ $name ] = $args;
				return new \stdClass();
			}
		);
		Functions\when( 'register_taxonomy' )->alias(
			function ( $name, $object_type, $args = array() ) {
				$this->taxonomies[ $name ] = array( 'object_type' => $object_type, 'args' => $args );
				return true;
			}
		);
		Functions\when( 'register_post_meta' )->alias(
			function ( $post_type, $key, $args ) {
				$this->meta[ $post_type ][ $key ] = $args;
				return true;
			}
		);
		Functions\when( 'add_post_type_support' )->alias(
			function ( $post_type, $feature ) {
				foreach ( (array) $feature as $one ) {
					$this->added_supports[] = array( $post_type, $one );
				}
			}
		);
		// The version-keyed refresh is tested on its own below; here it has nothing to do.
		Functions\when( 'get_option' )->justReturn( NSW_THEME_VERSION );

		foreach ( $this->registrations( 'init' ) as $reg ) {
			( $reg['callback'] )();
		}
	}

	/**
	 * The permission names WordPress derives from a content type's settings, the way
	 * WordPress does it: the capability type in its singular and plural form, then any
	 * names given one by one, and the right to create defaults to the right to edit.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, string>
	 */
	private function capabilities_of( array $args ): array {
		$type = $args['capability_type'] ?? 'post';
		if ( is_array( $type ) ) {
			$singular = $type[0];
			$plural   = $type[1] ?? $type[0] . 's';
		} else {
			$singular = $type;
			$plural   = $type . 's';
		}
		$caps = array_merge(
			array(
				'edit_post'              => 'edit_' . $singular,
				'delete_post'            => 'delete_' . $singular,
				'edit_posts'             => 'edit_' . $plural,
				'edit_others_posts'      => 'edit_others_' . $plural,
				'publish_posts'          => 'publish_' . $plural,
				'edit_published_posts'   => 'edit_published_' . $plural,
				'delete_published_posts' => 'delete_published_' . $plural,
			),
			(array) ( $args['capabilities'] ?? array() )
		);
		if ( ! isset( $caps['create_posts'] ) ) {
			$caps['create_posts'] = $caps['edit_posts'];
		}
		return $caps;
	}

	/**
	 * Asks every registered field whether the current user may change it through the
	 * website API, with the arguments WordPress passes, and records which right the
	 * field asked about.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} answers per field, rights asked
	 */
	private function ask_every_field( bool $user_has_the_right ): array {
		$asked = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) use ( &$asked, $user_has_the_right ) {
				$asked[] = (string) $capability;
				return $user_has_the_right;
			}
		);
		$answers = array();
		foreach ( $this->meta as $type => $fields ) {
			foreach ( $fields as $key => $args ) {
				// WordPress passes: allowed so far, field key, item id, user id, capability, primitive capabilities.
				$answers[ "$type $key" ] = ( $args['auth_callback'] )( false, $key, 42, 7, 'edit_post_meta', array( 'edit_posts' ) );
			}
		}
		return array( $answers, $asked );
	}

	/** @return array<int, string> */
	private function supports( string $type ): array {
		return $this->post_types[ $type ]['supports'];
	}

	/* ------------------------------------------------------------- content types */

	/** Source: TSD 2.3.3, post_type "nsw_event, nsw_document, nsw_agency, nsw_partner, nsw_faq, nsw_service". */
	#[TestDox('The portal has six content types of its own: events, documents, agencies, partners, FAQ items and service guides')]
	public function test_six_content_types_are_registered(): void {
		$this->run_init();
		$this->assertEqualsCanonicalizing( self::TYPES, array_keys( $this->post_types ) );
	}

	/** Source: TSD 2.3.1, news item "wp_posts (post, page)". */
	#[TestDox('News items are standard WordPress posts and not a content type of their own')]
	public function test_news_items_are_standard_posts(): void {
		$this->run_init();
		$this->assertSame( 'post', NSW_THEME_CPT_NEWS );
		$this->assertArrayNotHasKey( 'post', $this->post_types );
		$this->assertArrayNotHasKey( 'nsw_news', $this->post_types );
	}

	/** Source: TSD 2.3.3, "nsw_service (only one with a public URL)"; TSD 2.3.1, event, document "no own URL". */
	#[TestDox('Service guides are the only content type with a public web address of their own')]
	public function test_only_service_guides_have_a_public_address(): void {
		$this->run_init();

		$service = $this->post_types['nsw_service'];
		$this->assertTrue( $service['public'] );
		$this->assertTrue( $service['publicly_queryable'] );
		$this->assertNotFalse( $service['rewrite'] );

		foreach ( array_diff( self::TYPES, array( 'nsw_service' ) ) as $type ) {
			$args = $this->post_types[ $type ];
			$this->assertFalse( $args['public'], "$type must not be public" );
			$this->assertFalse( $args['publicly_queryable'], "$type must not be viewable on its own" );
			$this->assertFalse( $args['rewrite'], "$type must have no web address rule" );
			$this->assertFalse( $args['has_archive'], "$type must have no listing address of its own" );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('On the Albanian site, service guide addresses start with /sherbime/, and the list of services is an ordinary page')]
	public function test_service_guide_address(): void {
		$this->run_init();
		$service = $this->post_types['nsw_service'];
		$this->assertSame( array( 'slug' => 'sherbime', 'with_front' => false ), $service['rewrite'] );
		$this->assertFalse( $service['has_archive'] );
	}

	/** Source: TSD 2.3.3, post_type "String, max 20". */
	#[TestDox('The internal name of every content type fits in the 20 characters the database allows')]
	public function test_content_type_names_fit_the_database(): void {
		$this->run_init();
		$this->assertCount( 6, $this->post_types );
		foreach ( array_keys( $this->post_types ) as $type ) {
			$this->assertGreaterThan( 0, strlen( $type ) );
			$this->assertLessThanOrEqual( 20, strlen( $type ), "$type is longer than 20 characters" );
		}
	}

	/** Source: TSD 2.2, content types "Provides: wp-admin edit screens; REST routes". */
	#[TestDox('Every content type can be edited in the administration screens and is available through the website API')]
	public function test_every_type_has_admin_screens_and_api(): void {
		$this->run_init();
		foreach ( self::TYPES as $type ) {
			$args = $this->post_types[ $type ];
			$this->assertTrue( $args['show_ui'], "$type needs edit screens" );
			$this->assertTrue( $args['show_in_menu'], "$type needs a menu entry" );
			$this->assertTrue( $args['show_in_rest'], "$type needs the website API" );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('In the administration menu the content types appear in the order events, documents, agencies, partners, FAQ, services')]
	public function test_admin_menu_order(): void {
		$this->run_init();
		$positions = array_map( static fn( array $args ): int => $args['menu_position'], $this->post_types );
		asort( $positions );
		$this->assertSame(
			array( 'nsw_event', 'nsw_document', 'nsw_agency', 'nsw_partner', 'nsw_faq', 'nsw_service' ),
			array_keys( $positions )
		);
		$this->assertSame( 6, $positions['nsw_event'] );
		$this->assertSame( 11, $positions['nsw_service'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Each content type has its own name in the administration screens')]
	public function test_content_type_names(): void {
		$this->run_init();
		$expected = array(
			'nsw_event'    => array( 'Events', 'Event', 'Events' ),
			'nsw_document' => array( 'Documents', 'Document', 'Documents' ),
			'nsw_agency'   => array( 'Agencies', 'Agency', 'Agencies' ),
			'nsw_partner'  => array( 'Partners', 'Partner', 'Partners' ),
			'nsw_faq'      => array( 'FAQ', 'FAQ item', 'FAQ' ),
			'nsw_service'  => array( 'Services', 'Service', 'Services' ),
		);
		foreach ( $expected as $type => list( $name, $singular, $menu ) ) {
			$labels = $this->post_types[ $type ]['labels'];
			$this->assertSame( $name, $labels['name'] );
			$this->assertSame( $singular, $labels['singular_name'] );
			$this->assertSame( $menu, $labels['menu_name'] );
			$this->assertSame( 'Add New ' . $singular, $labels['add_new_item'] );
			$this->assertSame( 'Edit ' . $singular, $labels['edit_item'] );
		}
		$this->assertSame( 'No services found.', $this->post_types['nsw_service']['labels']['not_found'] );
		$this->assertSame( 'No events found.', $this->post_types['nsw_event']['labels']['not_found'] );
	}

	/** Source: TSD 2.2, multilingual text "Interface texts: English source in the theme". */
	#[TestDox('The names of the content types are offered for translation')]
	public function test_content_type_names_are_translatable(): void {
		Functions\when( '__' )->alias( static fn( $text, $domain = 'default' ) => "[$domain] $text" );
		$this->run_init();
		foreach ( self::TYPES as $type ) {
			foreach ( $this->post_types[ $type ]['labels'] as $key => $label ) {
				$this->assertStringStartsWith( '[nsw-theme] ', $label, "$type label $key" );
			}
		}
		$this->assertStringStartsWith( '[nsw-theme] ', $this->taxonomies['nsw_faq_category']['args']['labels']['name'] );
	}

	/* -------------------------------------------------------- fields and revisions */

	/** Source: TSD 2.5.4, revisions keep "title and excerpt (events, documents)". */
	#[TestDox('Events and documents have a title and a short description but no main text')]
	public function test_events_and_documents_have_title_and_excerpt(): void {
		$this->run_init();
		foreach ( array( 'nsw_event', 'nsw_document' ) as $type ) {
			$this->assertContains( 'title', $this->supports( $type ) );
			$this->assertContains( 'excerpt', $this->supports( $type ) );
			$this->assertNotContains( 'editor', $this->supports( $type ) );
		}
	}

	/** Source: TSD 2.5.4, revisions keep "title, content and excerpt (... service guides)". */
	#[TestDox('Service guides have a title, a main text and a short description')]
	public function test_service_guides_have_title_content_and_excerpt(): void {
		$this->run_init();
		foreach ( array( 'title', 'editor', 'excerpt' ) as $feature ) {
			$this->assertContains( $feature, $this->supports( 'nsw_service' ) );
		}
	}

	/** Source: TSD 2.5.4, "Revisions (all kept)" for events, documents and service guides. */
	#[TestDox('Changes to events, documents and service guides are kept as revisions')]
	public function test_revisions_kept_for_events_documents_services(): void {
		$this->run_init();
		foreach ( array( 'nsw_event', 'nsw_document', 'nsw_service' ) as $type ) {
			$this->assertContains( 'revisions', $this->supports( $type ), "$type must keep revisions" );
		}
	}

	/** Source: TSD 2.5.4, "Not recorded: agencies, partners, FAQ items". */
	#[TestDox('Changes to agencies, partners and FAQ items are not kept as revisions')]
	public function test_no_revisions_for_agencies_partners_faq(): void {
		$this->run_init();
		foreach ( array( 'nsw_agency', 'nsw_partner', 'nsw_faq' ) as $type ) {
			$this->assertNotContains( 'revisions', $this->supports( $type ), "$type must not keep revisions" );
		}
	}

	/** Source: TSD 2.3.1, agency, partner "id, name, colour, website, logo". */
	#[TestDox('Agencies and partners have a logo image')]
	public function test_agencies_and_partners_have_a_logo(): void {
		$this->run_init();
		$this->assertContains( 'thumbnail', $this->supports( 'nsw_agency' ) );
		$this->assertContains( 'thumbnail', $this->supports( 'nsw_partner' ) );
	}

	/** Source: TSD 2.3.1, FAQ item "question, answer, FAQ category". */
	#[TestDox('An FAQ item has the question as its title, the answer as its main text, and an FAQ category')]
	public function test_faq_item_question_and_answer(): void {
		$this->run_init();
		$this->assertContains( 'title', $this->supports( 'nsw_faq' ) );
		$this->assertContains( 'editor', $this->supports( 'nsw_faq' ) );
		$this->assertSame( array( 'nsw_faq_category' ), $this->post_types['nsw_faq']['taxonomies'] );
	}

	/** Source: TSD 2.2, content types "with their fields", "REST routes". */
	#[TestDox('Content types with extra fields make those fields available through the website API')]
	public function test_types_with_fields_expose_them(): void {
		$this->run_init();
		foreach ( array_keys( $this->meta ) as $type ) {
			$this->assertContains( 'custom-fields', $this->supports( $type ), "$type has fields but does not expose them" );
		}
		$this->assertEqualsCanonicalizing(
			array( 'nsw_event', 'nsw_document', 'nsw_agency', 'nsw_partner', 'nsw_service' ),
			array_keys( $this->meta )
		);
	}

	/** Source: TSD 2.3.3, taxonomy "nsw_document_category", "nsw_faq_category", "nsw_event_type". */
	#[TestDox('Events, documents and FAQ items are each linked to their own category list')]
	public function test_types_are_linked_to_their_category_lists(): void {
		$this->run_init();
		$this->assertSame( array( 'nsw_event_type' ), $this->post_types['nsw_event']['taxonomies'] );
		$this->assertSame( array( 'nsw_document_category' ), $this->post_types['nsw_document']['taxonomies'] );
		$this->assertSame( array( 'nsw_faq_category' ), $this->post_types['nsw_faq']['taxonomies'] );
	}

	/**
	 * Source: TSD 2.2.3, "Content is managed in wp-admin"; TSD 2.5.4, revisions keep
	 * "title, content and excerpt (news items, pages, service guides)".
	 */
	#[Group('tsd-difference')]
	#[TestDox('Pages have a short description field, so that it can be edited and kept in revisions')]
	public function test_pages_have_an_excerpt(): void {
		$this->run_init();
		$this->assertContains( array( 'page', 'excerpt' ), $this->added_supports, 'Pages have no short description field' );
	}

	/* ---------------------------------------------------------------- permissions */

	/** Source: TSD 2.5.2, Agency Officer "Not granted: ... publish_nsw_services, edit_published_nsw_services, delete_published_nsw_services". */
	#[TestDox('Service guides have permissions of their own, separate from news items, so publishing them can be withheld')]
	public function test_service_guide_permissions(): void {
		$this->run_init();
		$caps = $this->capabilities_of( $this->post_types['nsw_service'] );
		$this->assertSame( 'edit_nsw_services', $caps['edit_posts'] );
		$this->assertSame( 'edit_nsw_services', $caps['create_posts'] );
		$this->assertSame( 'publish_nsw_services', $caps['publish_posts'] );
		$this->assertSame( 'edit_published_nsw_services', $caps['edit_published_posts'] );
		$this->assertSame( 'delete_published_nsw_services', $caps['delete_published_posts'] );
	}

	/** Source: TSD 2.5.2, "Agency Officers have no access to pages, events, documents ...". */
	#[TestDox('Events and documents need permissions of their own, separate from those for news items')]
	public function test_event_and_document_permissions(): void {
		$this->run_init();
		$event    = $this->capabilities_of( $this->post_types['nsw_event'] );
		$document = $this->capabilities_of( $this->post_types['nsw_document'] );
		$this->assertSame( 'edit_nsw_event_posts', $event['edit_posts'] );
		$this->assertSame( 'edit_nsw_event_posts', $event['create_posts'] );
		$this->assertSame( 'edit_others_nsw_event_posts', $event['edit_others_posts'] );
		$this->assertSame( 'publish_nsw_event_posts', $event['publish_posts'] );
		$this->assertSame( 'edit_nsw_document_posts', $document['edit_posts'] );
		$this->assertSame( 'edit_nsw_document_posts', $document['create_posts'] );
		$this->assertSame( 'edit_others_nsw_document_posts', $document['edit_others_posts'] );
		$this->assertSame( 'publish_nsw_document_posts', $document['publish_posts'] );
	}

	/** Source: TSD 2.2, roles and agency scoping "Uses: map_meta_cap". */
	#[TestDox('Permissions are checked for each individual item of every content type')]
	public function test_permissions_checked_per_item(): void {
		$this->run_init();
		foreach ( self::TYPES as $type ) {
			$this->assertTrue( $this->post_types[ $type ]['map_meta_cap'], "$type must check permissions per item" );
		}
	}

	/** Source: TSD 2.5.2, "Agency Officers have no access to ... agencies, partners or FAQ items". */
	#[Group('tsd-difference')]
	#[TestDox('Agencies need a permission of their own, so an Agency Officer who edits news items cannot create or edit them')]
	public function test_agencies_need_own_permission(): void {
		$this->run_init();
		$caps = $this->capabilities_of( $this->post_types['nsw_agency'] );
		$this->assertNotSame( 'edit_posts', $caps['create_posts'], 'Agencies are created with the news item permission' );
		$this->assertNotSame( 'edit_posts', $caps['edit_posts'], 'Agencies are edited with the news item permission' );
		$this->assertNotSame( 'edit_others_posts', $caps['edit_others_posts'], 'Other users\' agencies are edited with the news item permission' );
	}

	/** Source: TSD 2.5.2, "Agency Officers have no access to ... agencies, partners or FAQ items". */
	#[Group('tsd-difference')]
	#[TestDox('Partners need a permission of their own, so an Agency Officer who edits news items cannot create or edit them')]
	public function test_partners_need_own_permission(): void {
		$this->run_init();
		$caps = $this->capabilities_of( $this->post_types['nsw_partner'] );
		$this->assertNotSame( 'edit_posts', $caps['create_posts'], 'Partners are created with the news item permission' );
		$this->assertNotSame( 'edit_posts', $caps['edit_posts'], 'Partners are edited with the news item permission' );
		$this->assertNotSame( 'edit_others_posts', $caps['edit_others_posts'], 'Other users\' partners are edited with the news item permission' );
	}

	/** Source: TSD 2.5.2, "Agency Officers have no access to ... agencies, partners or FAQ items". */
	#[Group('tsd-difference')]
	#[TestDox('FAQ items need a permission of their own, so an Agency Officer who edits news items cannot create or edit them')]
	public function test_faq_items_need_own_permission(): void {
		$this->run_init();
		$caps = $this->capabilities_of( $this->post_types['nsw_faq'] );
		$this->assertNotSame( 'edit_posts', $caps['create_posts'], 'FAQ items are created with the news item permission' );
		$this->assertNotSame( 'edit_posts', $caps['edit_posts'], 'FAQ items are edited with the news item permission' );
		$this->assertNotSame( 'edit_others_posts', $caps['edit_others_posts'], 'Other users\' FAQ items are edited with the news item permission' );
	}

	/* ------------------------------------------------------------ FAQ categories */

	/** Source: TSD 2.3.3, taxonomy "nsw_faq_category"; TSD 2.2, "wp-admin edit screens; REST routes". */
	#[TestDox('FAQ categories belong to FAQ items and can be managed in the administration screens and through the website API')]
	public function test_faq_categories_registered(): void {
		$this->run_init();
		$faq = $this->taxonomies['nsw_faq_category'];
		$this->assertSame( 'nsw_faq', $faq['object_type'] );
		$this->assertTrue( $faq['args']['show_ui'] );
		$this->assertTrue( $faq['args']['show_in_rest'] );
		$this->assertSame( 'FAQ categories', $faq['args']['labels']['name'] );
		$this->assertSame( 'FAQ category', $faq['args']['labels']['singular_name'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('FAQ categories have no public web address, can be nested and show as a column in the FAQ list')]
	public function test_faq_categories_not_public(): void {
		$this->run_init();
		$args = $this->taxonomies['nsw_faq_category']['args'];
		$this->assertFalse( $args['public'] );
		$this->assertFalse( $args['rewrite'] );
		$this->assertTrue( $args['hierarchical'] );
		$this->assertTrue( $args['show_admin_column'] );
	}

	/* --------------------------------------------------------------------- fields */

	/** Source: TSD 2.3.3, "_nsw_theme_event_date, _nsw_theme_event_time, _nsw_theme_event_location, _nsw_theme_event_type". */
	#[TestDox('An event has a date, a time, a location and a type')]
	public function test_event_fields(): void {
		$this->run_init();
		$this->assertEqualsCanonicalizing(
			array( '_nsw_theme_event_date', '_nsw_theme_event_time', '_nsw_theme_event_location', '_nsw_theme_event_type' ),
			array_keys( $this->meta['nsw_event'] )
		);
		foreach ( $this->meta['nsw_event'] as $key => $args ) {
			$this->assertSame( 'string', $args['type'], $key );
			$this->assertTrue( $args['single'], $key );
			$this->assertTrue( $args['show_in_rest'], $key );
		}
	}

	/** Source: TSD 2.3.3, "_nsw_theme_doc_file_id, _nsw_theme_doc_external_url, _nsw_theme_doc_file_type, _nsw_theme_doc_size", "Integer; String". */
	#[TestDox('A document has a file, an external address, a file type and a size, and the file is recorded by its number in the media library')]
	public function test_document_fields(): void {
		$this->run_init();
		$fields = $this->meta['nsw_document'];
		$this->assertArrayHasKey( '_nsw_theme_doc_file_id', $fields );
		$this->assertSame( 'integer', $fields['_nsw_theme_doc_file_id']['type'] );
		foreach ( array( '_nsw_theme_doc_external_url', '_nsw_theme_doc_file_type', '_nsw_theme_doc_size' ) as $key ) {
			$this->assertArrayHasKey( $key, $fields );
			$this->assertSame( 'string', $fields[ $key ]['type'], $key );
		}
		foreach ( $fields as $key => $args ) {
			$this->assertTrue( $args['single'], $key );
			$this->assertTrue( $args['show_in_rest'], $key );
		}
	}

	/** Source: TSD 2.3.3, "_nsw_theme_agency_id, _nsw_theme_agency_name, _nsw_theme_agency_abbr, ...". */
	#[TestDox('An agency has an identifier, an abbreviation, a name, a description, a list of documents, a colour and a website')]
	public function test_agency_fields(): void {
		$this->run_init();
		$this->assertEqualsCanonicalizing(
			array(
				'_nsw_theme_agency_id',
				'_nsw_theme_agency_abbr',
				'_nsw_theme_agency_name',
				'_nsw_theme_agency_desc',
				'_nsw_theme_agency_docs',
				'_nsw_theme_agency_color',
				'_nsw_theme_agency_website',
			),
			array_keys( $this->meta['nsw_agency'] )
		);
		foreach ( $this->meta['nsw_agency'] as $key => $args ) {
			$this->assertSame( 'string', $args['type'], $key );
			$this->assertTrue( $args['single'], $key );
			$this->assertTrue( $args['show_in_rest'], $key );
		}
	}

	/** Source: TSD 2.3.3, "_nsw_theme_partner_id, _nsw_theme_partner_type, _nsw_theme_partner_name, ...". */
	#[TestDox('A partner has an identifier, a type, a name, a description, a colour, a website and a logo background setting')]
	public function test_partner_fields(): void {
		$this->run_init();
		$this->assertEqualsCanonicalizing(
			array(
				'_nsw_theme_partner_id',
				'_nsw_theme_partner_type',
				'_nsw_theme_partner_name',
				'_nsw_theme_partner_desc',
				'_nsw_theme_partner_color',
				'_nsw_theme_partner_website',
				'_nsw_theme_partner_logo_bg',
			),
			array_keys( $this->meta['nsw_partner'] )
		);
		foreach ( $this->meta['nsw_partner'] as $key => $args ) {
			$this->assertSame( 'string', $args['type'], $key );
			$this->assertTrue( $args['single'], $key );
			$this->assertTrue( $args['show_in_rest'], $key );
		}
	}

	/** Source: TSD 2.3.3, "_nsw_theme_service_type", "Operation types: import, export, transit". */
	#[TestDox('A service guide has an operation types field that only keeps import, export and transit')]
	public function test_service_operation_types_field(): void {
		$this->run_init();
		$field = $this->meta['nsw_service']['_nsw_theme_service_type'];
		$this->assertSame( 'string', $field['type'] );
		$this->assertTrue( $field['single'] );
		$this->assertTrue( $field['show_in_rest'] );
		$this->assertIsCallable( $field['sanitize_callback'] );
		$this->assertSame( 'import,transit', call_user_func( $field['sanitize_callback'], 'import, shipping, transit' ) );
	}

	/** Source: TSD 2.3.3, "_nsw_theme_visible_until", "Last visible day of a document or service guide". */
	#[Group('tsd-difference')]
	#[TestDox('Documents and service guides have a "Visible until" date field')]
	public function test_visible_until_field(): void {
		$this->run_init();
		foreach ( array( 'nsw_document', 'nsw_service' ) as $type ) {
			$this->assertArrayHasKey( '_nsw_theme_visible_until', $this->meta[ $type ] ?? array(), "$type has no Visible until field" );
			$field = $this->meta[ $type ]['_nsw_theme_visible_until'];
			$this->assertSame( 'string', $field['type'] );
			$this->assertTrue( $field['single'] );
			$this->assertTrue( $field['show_in_rest'] );
		}
	}

	/** Source: TSD 2.2, content types "REST routes (writing needs edit rights)". */
	#[TestDox('A user without edit rights cannot change any of these fields through the website API')]
	public function test_fields_refused_without_edit_rights(): void {
		$this->run_init();
		list( $answers, $asked ) = $this->ask_every_field( false );
		$this->assertCount( 23, $answers );
		foreach ( $answers as $field => $answer ) {
			$this->assertFalse( $answer, $field );
		}
		$this->assertCount( 23, $asked, 'Every field must check the user\'s rights' );
		foreach ( $asked as $capability ) {
			$this->assertStringStartsWith( 'edit_', $capability, 'The right checked must be an edit right' );
		}
	}

	/** Source: TSD 2.2, content types "REST routes (writing needs edit rights)". */
	#[TestDox('A user with edit rights can change these fields through the website API')]
	public function test_fields_allowed_with_edit_rights(): void {
		$this->run_init();
		list( $answers ) = $this->ask_every_field( true );
		$this->assertCount( 23, $answers );
		foreach ( $answers as $field => $answer ) {
			$this->assertTrue( $answer, $field );
		}
	}

	/** Source: TSD 2.5.4, "Not revisioned: post meta (event dates, document files, agency)". */
	#[TestDox('Changes to the extra fields of an item, such as event dates and document files, are not kept in revisions')]
	public function test_fields_are_not_revisioned(): void {
		$this->run_init();
		$checked = 0;
		foreach ( $this->meta as $type => $fields ) {
			foreach ( $fields as $key => $args ) {
				$this->assertFalse( (bool) ( $args['revisions_enabled'] ?? false ), "$type $key is kept in revisions" );
				++$checked;
			}
		}
		$this->assertSame( 23, $checked );
	}

	/** Source: TSD 2.5.2, "The theme sanitises input". */
	#[TestDox('One-line text fields are cleaned of markup when they are saved')]
	public function test_one_line_fields_are_cleaned(): void {
		$this->run_init();
		$one_line = array(
			'nsw_event'    => array( '_nsw_theme_event_date', '_nsw_theme_event_time', '_nsw_theme_event_location', '_nsw_theme_event_type' ),
			'nsw_document' => array( '_nsw_theme_doc_external_url', '_nsw_theme_doc_file_type', '_nsw_theme_doc_size' ),
			'nsw_agency'   => array( '_nsw_theme_agency_id', '_nsw_theme_agency_abbr', '_nsw_theme_agency_name', '_nsw_theme_agency_color', '_nsw_theme_agency_website' ),
			'nsw_partner'  => array( '_nsw_theme_partner_id', '_nsw_theme_partner_type', '_nsw_theme_partner_name', '_nsw_theme_partner_color', '_nsw_theme_partner_website', '_nsw_theme_partner_logo_bg' ),
		);
		foreach ( $one_line as $type => $keys ) {
			foreach ( $keys as $key ) {
				$this->assertSame( 'sanitize_text_field', $this->meta[ $type ][ $key ]['sanitize_callback'], "$type $key" );
			}
		}
	}

	/* ------------------------------------------------------------ operation types */

	/** Source: TSD 2.3.3, "Operation types: import, export, transit". */
	#[TestDox('Valid operation types are kept in the order they were given')]
	public function test_operation_types_kept_in_order(): void {
		$this->assertSame( 'import,export,transit', nsw_theme_sanitize_service_type( 'import,export,transit' ) );
		$this->assertSame( 'transit,import', nsw_theme_sanitize_service_type( 'transit,import' ) );
		$this->assertSame( 'export', nsw_theme_sanitize_service_type( 'export' ) );
	}

	/** Source: TSD 2.3.3, _nsw_theme_service_type "String (comma-separated)". */
	#[TestDox('Spaces around operation types are removed')]
	public function test_operation_types_trimmed(): void {
		$this->assertSame( 'import,export', nsw_theme_sanitize_service_type( '  import , export  ' ) );
	}

	/** Source: TSD 2.3.3, "Operation types: import, export, transit". */
	#[TestDox('Unknown operation types are dropped')]
	public function test_unknown_operation_types_dropped(): void {
		$this->assertSame( 'import,export', nsw_theme_sanitize_service_type( 'import,shipping,export' ) );
		$this->assertSame( '', nsw_theme_sanitize_service_type( 'shipping' ) );
		$this->assertSame( '', nsw_theme_sanitize_service_type( 'imports,import-export,<b>transit</b>' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An operation type given twice is kept once')]
	public function test_repeated_operation_type_kept_once(): void {
		$this->assertSame( 'import,export', nsw_theme_sanitize_service_type( 'import,import,export,import' ) );
	}

	/** Source: TSD 2.3.3, _nsw_theme_service_type "Optional". */
	#[TestDox('An empty value means that no operation type is chosen')]
	public function test_empty_operation_types(): void {
		$this->assertSame( '', nsw_theme_sanitize_service_type( '' ) );
		$this->assertSame( '', nsw_theme_sanitize_service_type( null ) );
		$this->assertSame( '', nsw_theme_sanitize_service_type( ',,' ) );
		$this->assertSame( 'import,transit', nsw_theme_sanitize_service_type( 'import,,transit' ) );
	}

	/* ------------------------------------------------------------------ languages */

	/** Source: TSD 2.2, multilingual text "Each translation is a separate linked item". */
	#[TestDox('Agencies, partners and service guides can always have an Albanian and an English version')]
	public function test_agencies_partners_services_translatable(): void {
		$filter = $this->registrations( 'pll_get_post_types' )[0]['callback'];
		foreach ( array( true, false ) as $is_settings ) {
			$types = $filter( array(), $is_settings );
			$this->assertSame( 'nsw_agency', $types['nsw_agency'] ?? null );
			$this->assertSame( 'nsw_partner', $types['nsw_partner'] ?? null );
			$this->assertSame( 'nsw_service', $types['nsw_service'] ?? null );
		}
	}

	/** Source: TSD 2.3.1, "Translations" for news items, pages, events, documents and FAQ items. */
	#[TestDox('Content types already set as translatable stay translatable')]
	public function test_existing_translatable_types_kept(): void {
		$filter = $this->registrations( 'pll_get_post_types' )[0]['callback'];
		$types  = $filter( array( 'post' => 'post', 'page' => 'page', 'nsw_event' => 'nsw_event' ), false );
		$this->assertSame( 'post', $types['post'] );
		$this->assertSame( 'page', $types['page'] );
		$this->assertSame( 'nsw_event', $types['nsw_event'] );
		$this->assertCount( 6, $types );
	}

	/** Source: TSD 2.2, "language-independent fields (identifier, partner type, colour, website, logo, operation types, responsible agency) are copied". */
	#[TestDox('Fields that are the same in both languages are copied between the Albanian and English versions')]
	public function test_language_independent_fields_copied(): void {
		$filter = $this->registrations( 'pll_copy_post_metas' )[0]['callback'];
		$copied = $filter( array() );
		foreach (
			array(
				'_thumbnail_id',
				'_nsw_theme_agency_id',
				'_nsw_theme_agency_color',
				'_nsw_theme_agency_website',
				'_nsw_theme_partner_id',
				'_nsw_theme_partner_type',
				'_nsw_theme_partner_color',
				'_nsw_theme_partner_website',
				'_nsw_theme_partner_logo_bg',
				'_nsw_theme_service_type',
				'_nsw_service_agency',
			) as $key
		) {
			$this->assertContains( $key, $copied );
		}
	}

	/** Source: TSD 2.2, only "language-independent fields ... are copied between versions". */
	#[TestDox('Names, abbreviations, descriptions and document lists are not copied, because they differ per language')]
	public function test_language_specific_fields_not_copied(): void {
		$filter = $this->registrations( 'pll_copy_post_metas' )[0]['callback'];
		$copied = $filter( array() );
		foreach (
			array(
				'_nsw_theme_agency_name',
				'_nsw_theme_agency_abbr',
				'_nsw_theme_agency_desc',
				'_nsw_theme_agency_docs',
				'_nsw_theme_partner_name',
				'_nsw_theme_partner_desc',
			) as $key
		) {
			$this->assertNotContains( $key, $copied );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Fields already marked for copying between languages stay marked, and the list also works when nothing was marked before')]
	public function test_existing_copied_fields_kept(): void {
		$filter = $this->registrations( 'pll_copy_post_metas' )[0]['callback'];
		$copied = $filter( array( '_example_shared_field' ) );
		$this->assertSame( '_example_shared_field', $copied[0] );
		$this->assertCount( 12, $copied );
		$this->assertCount( 11, $filter( null ) );
	}

	/* ------------------------------------------------------- web address rules */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Activating the theme refreshes the web address rules')]
	public function test_activation_refreshes_address_rules(): void {
		Functions\expect( 'flush_rewrite_rules' )->once()->withNoArgs();
		$callbacks = $this->registrations( 'after_switch_theme' );
		$this->assertCount( 1, $callbacks );
		( $callbacks[0]['callback'] )();
	}

	/**
	 * Runs the version check and returns what it did.
	 *
	 * @param mixed $recorded the theme version stored in the database (false when none)
	 * @return array{flushes: array<int, array<int, mixed>>, saved: array<int, array<int, mixed>>}
	 */
	private function run_version_check( $recorded ): array {
		$done = array( 'flushes' => array(), 'saved' => array() );
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'nsw_theme_rewrite_version' === $name ? $recorded : $default
		);
		Functions\when( 'flush_rewrite_rules' )->alias(
			static function ( ...$args ) use ( &$done ) {
				$done['flushes'][] = $args;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( ...$args ) use ( &$done ) {
				$done['saved'][] = $args;
				return true;
			}
		);
		$check = $this->registrations( 'init' );
		$check = array_values( array_filter( $check, static fn( array $reg ): bool => 99 === $reg['priority'] ) );
		$this->assertCount( 1, $check );
		( $check[0]['callback'] )();
		return $done;
	}

	/** Source: SDP 5.6, theme upgrade "with a raised theme version (refreshes assets and rewrite rules)". */
	#[TestDox('After a release with a new theme version, the web address rules are refreshed once and the version is recorded')]
	public function test_new_version_refreshes_rules_once(): void {
		$earlier = '0.0.1';
		$this->assertTrue( version_compare( $earlier, NSW_THEME_VERSION, '<' ) );
		$done = $this->run_version_check( $earlier );
		$this->assertSame( array( array( false ) ), $done['flushes'] );
		$this->assertSame( array( array( 'nsw_theme_rewrite_version', NSW_THEME_VERSION, true ) ), $done['saved'] );
	}

	/** Source: TSD 2.3.3, nsw_theme_rewrite_version "permalink version" (optional). */
	#[TestDox('When no version has been recorded yet, the web address rules are refreshed and the version is recorded')]
	public function test_no_recorded_version_refreshes_rules(): void {
		foreach ( array( false, '' ) as $nothing_recorded ) {
			$done = $this->run_version_check( $nothing_recorded );
			$this->assertCount( 1, $done['flushes'] );
			$this->assertSame( array( array( 'nsw_theme_rewrite_version', NSW_THEME_VERSION, true ) ), $done['saved'] );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('After going back to an earlier release, the web address rules are refreshed for that release')]
	public function test_rollback_refreshes_rules(): void {
		$later = '99.0.0';
		$this->assertTrue( version_compare( $later, NSW_THEME_VERSION, '>' ) );
		$done = $this->run_version_check( $later );
		$this->assertCount( 1, $done['flushes'] );
		$this->assertSame( array( array( 'nsw_theme_rewrite_version', NSW_THEME_VERSION, true ) ), $done['saved'] );
	}

	/** Source: SDP 5.6, the refresh comes with "a raised theme version". */
	#[TestDox('When the recorded version is the current one, the web address rules are not refreshed again')]
	public function test_same_version_does_nothing(): void {
		$done = $this->run_version_check( NSW_THEME_VERSION );
		$this->assertSame( array(), $done['flushes'] );
		$this->assertSame( array(), $done['saved'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The web address rules are refreshed only after all content types and category lists have been registered')]
	public function test_refresh_runs_after_registration(): void {
		$own = array_map( static fn( array $reg ): int => $reg['priority'], $this->registrations( 'init' ) );
		$this->assertCount( 4, $own );
		$this->assertSame( array( 99 ), array_values( array_filter( $own, static fn( int $p ): bool => $p >= 99 ) ), 'Exactly one refresh step, at the end' );

		// Everything else these two files register when WordPress starts runs before it.
		$taxonomies = array_map( static fn( array $reg ): int => $reg['priority'], $this->registrations( 'init', 'taxonomies.php' ) );
		$this->assertNotEmpty( $taxonomies );
		$registration = array_merge( array_values( array_filter( $own, static fn( int $p ): bool => $p < 99 ) ), $taxonomies );
		$this->assertCount( 4, $registration );
		foreach ( $registration as $priority ) {
			$this->assertLessThan( 99, $priority );
		}
	}
}
