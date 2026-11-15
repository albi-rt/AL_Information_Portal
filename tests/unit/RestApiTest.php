<?php
/**
 * Unit tests for inc/rest-api.php: the content API that other NSW systems use to
 * create or update news items, pages, documents, events and service guides.
 *
 * They cover the address and the fields of the API, who may use it, how an item is
 * found again by its external id and language, which extra fields (meta) may be
 * written, how new items are created (published or pending), how existing items are
 * updated or sent for approval as a pending revision, how translations are linked,
 * and what the caller gets back. One test covers who may hold an Application
 * Password, the sign-in method of the content API.
 *
 * No WordPress, database, Polylang or PublishPress Revisions is used: a small fake
 * site in this file stands in for them, and every database answer is simulated.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.2.2 (workflow), 2.3.3 (data dictionary), 2.4.2 and 2.4.3 (content API)
 * and 2.5.1 and 2.5.2 (sign-in and rights). Where today's code differs from the TSD,
 * the test expects the TSD and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class RestApiTest extends TestCase {

	/**
	 * Plural used in the capability names of each content type, as the theme registers
	 * them (capability_type in inc/post-types.php): for example edit_nsw_document_posts.
	 */
	private const PLURALS = array(
		'post'         => 'posts',
		'page'         => 'pages',
		'nsw_document' => 'nsw_document_posts',
		'nsw_event'    => 'nsw_event_posts',
		'nsw_service'  => 'nsw_services',
		'nsw_agency'   => 'posts',
	);

	/* ------------------------------------------------------------ the fake site */

	/** @var array<int, array<string, mixed>> Items on the fake site, by id. */
	private array $posts = array();
	private int $next_id = 101;
	/** @var array<int, string> Rights of the signed-in caller. */
	private array $caps = array();
	/** @var array<int, array{0: string, 1: array}> Every rights check made. */
	private array $cap_checks = array();
	private bool $logged_in = true;
	private bool $write_error = false;
	private bool $no_permalink = false;
	/** @var array<string, array<string, array>> Registered meta by content type. */
	private array $registered_meta = array();
	/** @var array<int, array> */
	private array $routes = array();
	/** @var array<int, array> Every item lookup made. */
	private array $lookups = array();
	/** @var array<int, array> Data passed to the "create item" call, after unslashing. */
	private array $inserted = array();
	/** @var array<int, array> Data passed to the "update item" call, after unslashing. */
	private array $updated = array();
	/** @var array<int, array{0: int, 1: string, 2: mixed}> Every meta write. */
	private array $meta_writes = array();
	/** @var array<int, array{0: int, 1: string}> Languages set on items. */
	private array $languages_set = array();
	/** @var array<int, array<string, int>> Translation groups saved. */
	private array $translations_saved = array();
	/** @var array<int, array<string, int>> Existing translation groups, by item id. */
	private array $groups = array();

	/** @var object|null Fake database object for the revision lookup. */
	private ?object $wpdb = null;
	private bool $had_wpdb = false;
	private mixed $saved_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb   = array_key_exists( 'wpdb', $GLOBALS );
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->stub_wordpress();
		if ( ! str_ends_with( $this->name(), '_without_language_plugin' ) ) {
			$this->stub_polylang();
		}
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->saved_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		unset( $GLOBALS['nsw_test_rvy_calls'], $GLOBALS['nsw_test_rvy_result'] );
		parent::tearDown();
	}

	/** Replaces the WordPress functions the content API calls with the fake site. */
	private function stub_wordpress(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'is_user_logged_in' )->alias( fn() => $this->logged_in );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->alias(
			function ( $cap, ...$args ) {
				$this->cap_checks[] = array( $cap, $args );
				return in_array( $cap, $this->caps, true );
			}
		);
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $type ) {
				if ( ! isset( self::PLURALS[ $type ] ) ) {
					return null;
				}
				$plural = self::PLURALS[ $type ];
				return (object) array(
					'name' => $type,
					'cap'  => (object) array(
						'edit_posts'    => 'edit_' . $plural,
						'publish_posts' => 'publish_' . $plural,
					),
				);
			}
		);
		Functions\when( 'get_registered_meta_keys' )->alias(
			fn( $object_type, $subtype = '' ) => 'post' === $object_type ? ( $this->registered_meta[ $subtype ] ?? array() ) : array()
		);
		Functions\when( 'register_post_meta' )->alias(
			function ( $type, $key, $args ) {
				$this->registered_meta[ $type ][ $key ] = $args;
				return true;
			}
		);
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->routes[] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
				return true;
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( array $args ) {
				$this->lookups[] = $args;
				foreach ( $this->posts as $id => $post ) {
					if ( $post['type'] !== $args['post_type']
						|| ! in_array( $post['status'], (array) $args['post_status'], true )
						|| ( $post['meta']['_nsw_api_external_id'] ?? null ) !== $args['meta_query'][0]['value']
						|| ( isset( $args['lang'] ) && $post['lang'] !== $args['lang'] ) ) {
						continue;
					}
					return array( $id );
				}
				return array();
			}
		);
		Functions\when( 'get_post_status' )->alias( fn( $id ) => $this->posts[ $id ]['status'] ?? false );
		Functions\when( 'get_post' )->alias(
			fn( $id ) => isset( $this->posts[ $id ] ) ? new \WP_Post(
				array(
					'ID'           => $id,
					'post_type'    => $this->posts[ $id ]['type'],
					'post_status'  => $this->posts[ $id ]['status'],
					'post_title'   => $this->posts[ $id ]['title'],
					'post_content' => $this->posts[ $id ]['content'],
					'post_excerpt' => $this->posts[ $id ]['excerpt'],
				)
			) : null
		);
		Functions\when( 'get_permalink' )->alias( fn( $id ) => $this->no_permalink ? false : 'https://nsw.test/?p=' . $id );
		// WordPress expects slashed data in its write functions and unslashes it.
		Functions\when( 'wp_slash' )->alias( fn( $value ) => $this->map_strings( $value, 'addslashes' ) );
		Functions\when( 'wp_insert_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$postarr          = $this->map_strings( $postarr, 'stripslashes' );
				$this->inserted[] = $postarr;
				if ( $this->write_error ) {
					return $this->wp_error( 'db_insert_error', 'Could not insert post into the database.' );
				}
				$id                 = $this->next_id++;
				$this->posts[ $id ] = array(
					'type'    => $postarr['post_type'],
					'status'  => $postarr['post_status'],
					'lang'    => null,
					'title'   => $postarr['post_title'],
					'content' => $postarr['post_content'],
					'excerpt' => $postarr['post_excerpt'],
					'meta'    => array(),
				);
				return $id;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$postarr         = $this->map_strings( $postarr, 'stripslashes' );
				$this->updated[] = $postarr;
				if ( $this->write_error ) {
					return $this->wp_error( 'db_update_error', 'Could not update post in the database.' );
				}
				return $postarr['ID'];
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta_writes[] = array( $id, $key, $value );
				if ( isset( $this->posts[ $id ] ) ) {
					$this->posts[ $id ]['meta'][ $key ] = $value;
				}
				return true;
			}
		);
	}

	/** Polylang with Albanian (default) and English, as on the site. */
	private function stub_polylang(): void {
		Functions\when( 'pll_default_language' )->justReturn( 'sq' );
		Functions\when( 'pll_languages_list' )->justReturn( array( 'sq', 'en' ) );
		Functions\when( 'pll_set_post_language' )->alias(
			function ( $id, $lang ) {
				$this->languages_set[] = array( $id, $lang );
				if ( isset( $this->posts[ $id ] ) ) {
					$this->posts[ $id ]['lang'] = $lang;
				}
			}
		);
		Functions\when( 'pll_get_post_translations' )->alias(
			fn( $id ) => $this->groups[ $id ] ?? array( $this->posts[ $id ]['lang'] => $id )
		);
		Functions\when( 'pll_save_post_translations' )->alias(
			function ( $group ) {
				$this->translations_saved[] = $group;
				return $group;
			}
		);
	}

	/** A WordPress error that also offers add_data(), as the real WP_Error does. */
	private function wp_error( string $code, string $message ): \WP_Error {
		return new class( $code, $message ) extends \WP_Error {
			public function add_data( $data, $code = '' ): void {
				$this->error_data[ '' === $code ? $this->get_error_code() : $code ] = $data;
			}
		};
	}

	private function map_strings( mixed $value, callable $fn ): mixed {
		if ( is_array( $value ) ) {
			return array_map( fn( $item ) => $this->map_strings( $item, $fn ), $value );
		}
		return is_string( $value ) ? $fn( $value ) : $value;
	}

	/** A content API request with the given body fields. */
	private function request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/nsw-theme/v1/content' );
		$request->set_body_params( $params );
		return $request;
	}

	/** Puts an item on the fake site. */
	private function add_post( int $id, string $type, string $status, string $external_id, ?string $lang = 'sq' ): void {
		$this->posts[ $id ] = array(
			'type'    => $type,
			'status'  => $status,
			'lang'    => $lang,
			'title'   => 'Live title',
			'content' => '<p>Live content</p>',
			'excerpt' => 'Live summary',
			'meta'    => array( '_nsw_api_external_id' => $external_id ),
		);
	}

	/** Lets the given meta keys through the REST API for a content type. */
	private function allow_meta( string $type, array $keys ): void {
		foreach ( $keys as $key ) {
			$this->registered_meta[ $type ][ $key ] = array( 'show_in_rest' => true, 'single' => true, 'type' => 'string' );
		}
	}

	/** The callback this file registered on a hook (other theme files use the same hooks). */
	private function rest_api_callback( string $hook ): callable {
		return nsw_test_hook_callback(
			$hook,
			0,
			static fn( array $reg ) => str_ends_with(
				(string) ( new \ReflectionFunction( \Closure::fromCallable( $reg['callback'] ) ) )->getFileName(),
				'/inc/rest-api.php'
			)
		);
	}

	/**
	 * Fills the fake meta registry exactly as the theme does on the site: runs every
	 * 'init' callback of the theme that registers post meta.
	 */
	private function use_theme_meta_registry(): void {
		$this->registered_meta = array();
		foreach ( nsw_test_hook_registrations( 'init' ) as $reg ) {
			$fn     = new \ReflectionFunction( \Closure::fromCallable( $reg['callback'] ) );
			$lines  = file( (string) $fn->getFileName() );
			$source = implode( '', array_slice( $lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1 ) );
			if ( str_contains( $source, 'register_post_meta(' ) ) {
				( $reg['callback'] )();
			}
		}
	}

	/** Runs a value through every theme callback on a filter, in priority order. */
	private function apply_theme_filter( string $hook, mixed $value, mixed ...$args ): mixed {
		$regs = nsw_test_hook_registrations( $hook );
		usort( $regs, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		foreach ( $regs as $reg ) {
			$value = ( $reg['callback'] )( ...array_slice( array( $value, ...$args ), 0, max( 1, $reg['accepted_args'] ) ) );
		}
		return $value;
	}

	/** A fake database that answers the "open pending revision" lookup. */
	private function fake_wpdb( ?int $open_revision ): object {
		$this->wpdb = new class( $open_revision ) {
			public string $posts = 'wp_posts';
			/** @var array<int, array{0: string, 1: array}> */
			public array $prepared = array();

			public function __construct( private ?int $answer ) {}

			public function prepare( string $query, ...$args ): string {
				$this->prepared[] = array( $query, $args );
				return $query;
			}

			public function get_var( string $query ) {
				return null === $this->answer ? null : (string) $this->answer;
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;
		return $this->wpdb;
	}

	/** Makes the PublishPress Revisions plugin look installed (separate process only). */
	private function install_revisions_plugin( mixed $create_result ): void {
		define( 'REVISIONARY_FILE', __DIR__ . '/fixtures/rest-api/revisionary.php' );
		Functions\when( 'rvy_create_revision' )->justReturn( 0 );
		$GLOBALS['nsw_test_rvy_calls']  = array();
		$GLOBALS['nsw_test_rvy_result'] = $create_result;
	}

	private function assert_error( mixed $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array( 'status' => $status ), $result->get_error_data() );
	}

	private function assert_nothing_written(): void {
		$this->assertSame( array(), $this->inserted, 'No item may be created.' );
		$this->assertSame( array(), $this->updated, 'No item may be changed.' );
		$this->assertSame( array(), $this->meta_writes, 'No field may be saved.' );
	}

	/* ---------------------------------------------------------- content types */

	/** Source: TSD 2.4.2, "type (post, page, nsw_document, nsw_event, nsw_service)". */
	#[TestDox('The content API accepts news items, pages, documents, events and service guides only')]
	public function test_supported_types(): void {
		$this->assertSame( array( 'post', 'page', 'nsw_document', 'nsw_event', 'nsw_service' ), nsw_rest_supported_types() );
	}

	/** Source: TSD 2.3.3, "Content API key (with type and language); not readable through the REST API". */
	#[TestDox('Every content type the API accepts stores an external id that is hidden from the public REST API')]
	public function test_external_id_registered_hidden(): void {
		$this->rest_api_callback( 'init' )();

		$this->assertSame( nsw_rest_supported_types(), array_keys( $this->registered_meta ) );
		foreach ( $this->registered_meta as $type => $keys ) {
			$args = $keys['_nsw_api_external_id'];
			$this->assertFalse( $args['show_in_rest'], "Hidden for $type" );
			$this->assertTrue( $args['single'] );
			$this->assertSame( 'string', $args['type'] );
			$this->assertSame( 'sanitize_text_field', $args['sanitize_callback'] );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Only users with editing rights may write the external id')]
	public function test_external_id_write_needs_editing_rights(): void {
		$this->rest_api_callback( 'init' )();
		$auth = $this->registered_meta['post']['_nsw_api_external_id']['auth_callback'];

		$this->caps = array( 'edit_posts' );
		$this->assertTrue( $auth() );
		$this->caps = array();
		$this->assertFalse( $auth() );
	}

	/* ------------------------------------------------------------------ route */

	/** Source: TSD 2.4.2, "POST https://nsw.al/wp-json/nsw-theme/v1/content". */
	#[TestDox('The content API is offered at one address that accepts POST requests')]
	public function test_route_address(): void {
		$this->rest_api_callback( 'rest_api_init' )();

		$this->assertCount( 1, $this->routes );
		$this->assertSame( 'nsw-theme/v1', $this->routes[0]['namespace'] );
		$this->assertSame( '/content', $this->routes[0]['route'] );
		$this->assertSame( 'POST', $this->routes[0]['args']['methods'] );
		$this->assertSame( 'nsw_rest_content_upsert', $this->routes[0]['args']['callback'] );
		$this->assertSame( 'nsw_rest_content_permission', $this->routes[0]['args']['permission_callback'] );
	}

	/** Source: TSD 2.4.2, "Required: type ..., external_id; title on create". */
	#[TestDox('The content type and the external id must always be sent, the title only for a new item')]
	public function test_route_required_fields(): void {
		$this->rest_api_callback( 'rest_api_init' )();
		$args = $this->routes[0]['args']['args'];

		$this->assertTrue( $args['type']['required'] );
		$this->assertTrue( $args['external_id']['required'] );
		// The title is checked by the handler on create only; the optional fields are never required.
		foreach ( array( 'title', 'lang', 'content', 'excerpt', 'meta' ) as $optional ) {
			$this->assertFalse( $args[ $optional ]['required'] ?? false, "$optional is not required" );
		}
	}

	/** Source: TSD 2.4.3, "400 invalid input (unknown type or language". */
	#[TestDox('Only the five content types and the languages Albanian and English are accepted as input')]
	public function test_route_type_and_language_lists(): void {
		$this->rest_api_callback( 'rest_api_init' )();
		$args = $this->routes[0]['args']['args'];

		$this->assertSame( nsw_rest_supported_types(), $args['type']['enum'] );
		$this->assertSame( 'rest_validate_request_arg', $args['type']['validate_callback'] );
		$this->assertSame( array( 'sq', 'en' ), $args['lang']['enum'] );
		$this->assertSame( 'rest_validate_request_arg', $args['lang']['validate_callback'] );
	}

	/** Source: TSD 2.4.2, "content and excerpt (filtered HTML)"; 2.5.2, "The theme sanitises input". */
	#[TestDox('The title is cleaned to plain text and the content and summary to filtered HTML')]
	public function test_route_text_cleaning(): void {
		$this->rest_api_callback( 'rest_api_init' )();
		$args = $this->routes[0]['args']['args'];

		$this->assertSame( 'sanitize_text_field', $args['title']['sanitize_callback'] );
		$this->assertSame( 'wp_kses_post', $args['content']['sanitize_callback'] );
		$this->assertSame( 'wp_kses_post', $args['excerpt']['sanitize_callback'] );
		$this->assertSame( 'sanitize_text_field', $args['external_id']['sanitize_callback'] );
	}

	/** Source: TSD 2.4.2, "Not supported: publish date, categories, featured image, file upload". */
	#[TestDox('The content API takes no publish date, categories, featured image or file, and takes extra fields only as an object')]
	public function test_route_fields_list(): void {
		$this->rest_api_callback( 'rest_api_init' )();
		$args = $this->routes[0]['args']['args'];

		$this->assertSame( array( 'type', 'external_id', 'lang', 'title', 'content', 'excerpt', 'meta' ), array_keys( $args ) );
		$this->assertSame( 'object', $args['meta']['type'] );
	}

	/* ------------------------------------------------------------- permission */

	/** Source: TSD 2.4.3, "401 not signed in". */
	#[TestDox('A caller who is not signed in is refused (status 401)')]
	public function test_permission_not_signed_in(): void {
		$this->logged_in = false;
		$this->assert_error( nsw_rest_content_permission( $this->request( array( 'type' => 'post' ) ) ), 'nsw_rest_not_logged_in', 401 );
	}

	/** Source: TSD 2.4.3, "403 no right for the type or item". */
	#[TestDox('A signed-in caller without editing rights for the content type is refused (status 403)')]
	public function test_permission_no_right_for_type(): void {
		$this->caps = array( 'edit_posts' ); // News rights only.
		$result     = nsw_rest_content_permission( $this->request( array( 'type' => 'nsw_document' ) ) );

		$this->assert_error( $result, 'nsw_rest_forbidden', 403 );
		$this->assertSame( 'edit_nsw_document_posts', $this->cap_checks[0][0] );
	}

	/** Source: TSD 2.4.2, "role limited to its content types"; 2.5.2. */
	#[TestDox('A signed-in caller with editing rights for the content type is let through')]
	public function test_permission_with_right_for_type(): void {
		$this->caps = array( 'edit_nsw_document_posts' );
		$this->assertTrue( nsw_rest_content_permission( $this->request( array( 'type' => 'nsw_document' ) ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A content type that is not set up on the site is refused (status 403)')]
	public function test_permission_type_not_set_up(): void {
		Functions\when( 'get_post_type_object' )->justReturn( null );
		$this->caps = array( 'edit_nsw_event_posts' );
		$this->assert_error( nsw_rest_content_permission( $this->request( array( 'type' => 'nsw_event' ) ) ), 'nsw_rest_forbidden', 403 );
	}

	/** Source: TSD 2.4.3, "400 invalid input (unknown type". */
	#[TestDox('The rights check lets an unknown content type through to the input check')]
	public function test_permission_unknown_type_left_to_input_check(): void {
		$this->assertTrue( nsw_rest_content_permission( $this->request( array( 'type' => 'nsw_agency' ) ) ) );
		$this->assertTrue( nsw_rest_content_permission( $this->request( array( 'type' => 'not-a-type' ) ) ) );
		$this->assertTrue( nsw_rest_content_permission( $this->request( array() ) ) );
		$this->assertSame( array(), $this->cap_checks );
	}

	/* --------------------------------------------------------------- language */

	/** Source: TSD 2.4.2, "lang (sq, en; default sq)". */
	#[TestDox('The language sent by the caller is used')]
	public function test_language_from_request(): void {
		$this->assertSame( 'en', nsw_rest_resolve_lang( $this->request( array( 'lang' => 'en' ) ) ) );
	}

	/** Source: TSD 2.4.2, "lang (sq, en; default sq)". */
	#[TestDox('Without a language the site default, Albanian, is used')]
	public function test_language_default_albanian(): void {
		$this->assertSame( 'sq', nsw_rest_resolve_lang( $this->request( array() ) ) );
		$this->assertSame( 'sq', nsw_rest_resolve_lang( $this->request( array( 'lang' => '' ) ) ) );
	}

	/** Source: TSD 2.4.2, "lang (sq, en; default sq)". */
	#[TestDox('Without a language and without a site default language, Albanian is used')]
	public function test_language_no_site_default(): void {
		Functions\when( 'pll_default_language' )->justReturn( false );
		$this->assertSame( 'sq', nsw_rest_resolve_lang( $this->request( array() ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, items are found and created in Albanian without translation links')]
	public function test_create_without_language_plugin(): void {
		$this->caps = array( 'edit_posts', 'publish_posts' );

		$this->assertSame( 'sq', nsw_rest_resolve_lang( $this->request( array() ) ) );
		$response = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-9', 'title' => 'Hello' ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'sq', $response->get_data()['lang'] );
		$this->assertArrayNotHasKey( 'lang', $this->lookups[0] );
		$this->assertCount( 1, $this->lookups, 'No search for translations.' );
		$this->assertSame( array( array( 101, '_nsw_api_external_id', 'news-9' ) ), $this->meta_writes );
	}

	/* ----------------------------------------------------------------- lookup */

	/** Source: TSD 2.4.2, "keyed on type, external_id and language". */
	#[TestDox('An item is found by content type, external id and language among published, scheduled, draft and pending items')]
	public function test_find_post_by_key(): void {
		$this->add_post( 40, 'nsw_event', 'future', 'event-1', 'en' );

		$this->assertSame( 40, nsw_rest_find_post( 'nsw_event', 'event-1', 'en' ) );
		$args = $this->lookups[0];
		$this->assertSame( 'nsw_event', $args['post_type'] );
		$this->assertSame( array( 'publish', 'future', 'draft', 'pending' ), $args['post_status'] );
		$this->assertSame( array( array( 'key' => '_nsw_api_external_id', 'value' => 'event-1' ) ), $args['meta_query'] );
		$this->assertSame( 'en', $args['lang'] );
		$this->assertSame( 1, $args['posts_per_page'] );
		$this->assertSame( 'ids', $args['fields'] );
	}

	/** Source: TSD 2.4.2, "keyed on type, external_id and language". */
	#[TestDox('The same external id in another language or another content type is a different item')]
	public function test_find_post_other_language_or_type(): void {
		$this->add_post( 40, 'nsw_event', 'publish', 'event-1', 'sq' );

		$this->assertSame( 0, nsw_rest_find_post( 'nsw_event', 'event-1', 'en' ) );
		$this->assertSame( 0, nsw_rest_find_post( 'nsw_document', 'event-1', 'sq' ) );
		$this->assertSame( 0, nsw_rest_find_post( 'nsw_event', 'event-2', 'sq' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The item search asks for pending revisions to be left out')]
	public function test_find_post_excludes_revisions(): void {
		nsw_rest_find_post( 'post', 'news-1', 'sq' );

		$this->assertTrue( $this->lookups[0]['nsw_exclude_revisions'] );
		$this->assertFalse( $this->lookups[0]['suppress_filters'], 'The search must run the filter that leaves revisions out.' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Pending revisions are left out only of the content API item searches')]
	public function test_revision_rows_filtered_out_of_api_searches(): void {
		$this->fake_wpdb( null );
		$filter = $this->rest_api_callback( 'posts_where' );
		$query  = static fn( array $vars ) => new class( $vars ) {
			public function __construct( private array $vars ) {}
			public function get( $key ) {
				return $this->vars[ $key ] ?? '';
			}
		};

		$this->assertSame(
			" AND 1=1 AND wp_posts.post_mime_type NOT LIKE '%-revision'",
			$filter( ' AND 1=1', $query( array( 'nsw_exclude_revisions' => true ) ) )
		);
		$this->assertSame( ' AND 1=1', $filter( ' AND 1=1', $query( array() ) ) );
	}

	/* ------------------------------------------------------------ extra fields */

	/** Source: TSD 2.4.2, "Optional: ... meta". */
	#[TestDox('Sending no extra fields means nothing extra to save')]
	public function test_meta_empty(): void {
		$this->assertSame( array(), nsw_rest_validate_meta( null, 'nsw_event' ) );
		$this->assertSame( array(), nsw_rest_validate_meta( array(), 'nsw_event' ) );
		$this->assertSame( array(), nsw_rest_validate_meta( '', 'nsw_event' ) );
	}

	/** Source: TSD 2.4.3, "400 invalid input (... invalid or unknown meta)". */
	#[TestDox('Extra fields that are not sent as an object are refused (status 400)')]
	public function test_meta_not_an_object(): void {
		$this->assert_error( nsw_rest_validate_meta( 'event_date=2026-12-01', 'nsw_event' ), 'nsw_rest_bad_meta', 400 );
	}

	/** Source: TSD 2.4.2, "other keys ... are rejected (HTTP 400)". */
	#[TestDox('An extra field not allowed for the content type is refused (status 400) with the list of allowed fields')]
	public function test_meta_unknown_key(): void {
		$this->allow_meta( 'nsw_event', array( '_nsw_theme_event_date', '_nsw_theme_event_time' ) );

		$result = nsw_rest_validate_meta( array( '_nsw_theme_event_date' => '2026-12-01', 'colour' => 'red' ), 'nsw_event' );

		$this->assert_error( $result, 'nsw_rest_unknown_meta', 400 );
		$this->assertSame(
			'Unknown meta key(s): colour. Allowed for this type: _nsw_theme_event_date, _nsw_theme_event_time.',
			$result->get_error_message()
		);
	}

	/** Source: TSD 2.4.2, "other keys ... are rejected (HTTP 400)". */
	#[TestDox('When a content type allows no extra fields, the refusal says that none are allowed')]
	public function test_meta_unknown_key_none_allowed(): void {
		$result = nsw_rest_validate_meta( array( 'colour' => 'red' ), 'page' );

		$this->assert_error( $result, 'nsw_rest_unknown_meta', 400 );
		$this->assertStringEndsWith( 'Allowed for this type: (none).', $result->get_error_message() );
	}

	/** Source: TSD 2.4.2, "meta accepts only the event, document, operation type and Visible until keys". */
	#[TestDox('Allowed extra fields are passed on unchanged')]
	public function test_meta_allowed_passes(): void {
		$this->allow_meta( 'nsw_event', array( '_nsw_theme_event_date', '_nsw_theme_event_location' ) );
		$meta = array( '_nsw_theme_event_date' => '2026-12-01', '_nsw_theme_event_location' => 'Tirana' );

		$this->assertSame( $meta, nsw_rest_validate_meta( $meta, 'nsw_event' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Fields hidden from the REST API are never allowed as extra fields')]
	public function test_meta_hidden_fields_not_allowed(): void {
		$this->allow_meta( 'nsw_event', array( '_nsw_theme_event_date' ) );
		$this->registered_meta['nsw_event']['_internal_note'] = array( 'show_in_rest' => false );

		$this->assertSame( array( '_nsw_theme_event_date' ), nsw_rest_allowed_meta_keys( 'nsw_event' ) );
	}

	/** Source: TSD 2.3.3, "Content API key ... not readable through the REST API"; 2.4.2. */
	#[TestDox('The external id itself cannot be changed as an extra field')]
	public function test_meta_external_id_never_allowed(): void {
		$this->allow_meta( 'nsw_event', array( '_nsw_api_external_id', '_nsw_theme_event_date' ) );

		$this->assertSame( array( '_nsw_theme_event_date' ), nsw_rest_allowed_meta_keys( 'nsw_event' ) );
		$this->assert_error(
			nsw_rest_validate_meta( array( '_nsw_api_external_id' => 'other-id' ), 'nsw_event' ),
			'nsw_rest_unknown_meta',
			400
		);
	}

	/* ----------------------------------- extra fields of each type on the site */

	/** Source: TSD 2.4.2, "meta accepts only the event ... keys of the type"; 2.3.3. */
	#[TestDox('Events accept exactly their date, time, location and type as extra fields')]
	public function test_site_meta_events(): void {
		$this->use_theme_meta_registry();
		$this->assertEqualsCanonicalizing(
			array( '_nsw_theme_event_date', '_nsw_theme_event_time', '_nsw_theme_event_location', '_nsw_theme_event_type' ),
			nsw_rest_allowed_meta_keys( 'nsw_event' )
		);
	}

	/** Source: TSD 2.4.2, "meta accepts only the event, document, operation type and Visible until keys". */
	#[TestDox('Pages accept no extra fields')]
	public function test_site_meta_pages(): void {
		$this->use_theme_meta_registry();
		$this->assertSame( array(), nsw_rest_allowed_meta_keys( 'page' ) );
	}

	/** Source: TSD 2.4.2, "meta accepts only the ... document ... and Visible until keys of the type"; 2.3.3. */
	#[Group('tsd-difference')]
	#[TestDox('Documents accept exactly their file, external address, file type, size and "Visible until" date as extra fields')]
	public function test_site_meta_documents(): void {
		$this->use_theme_meta_registry();
		$this->assertEqualsCanonicalizing(
			array(
				'_nsw_theme_doc_file_id',
				'_nsw_theme_doc_external_url',
				'_nsw_theme_doc_file_type',
				'_nsw_theme_doc_size',
				'_nsw_theme_visible_until',
			),
			nsw_rest_allowed_meta_keys( 'nsw_document' )
		);
	}

	/** Source: TSD 2.4.2, "operation type and Visible until keys ... other keys, including agency keys, are rejected". */
	#[Group('tsd-difference')]
	#[TestDox('Service guides accept exactly their operation types and "Visible until" date as extra fields, and no agency')]
	public function test_site_meta_service_guides(): void {
		$this->use_theme_meta_registry();
		$this->assertEqualsCanonicalizing(
			array( '_nsw_theme_service_type', '_nsw_theme_visible_until' ),
			nsw_rest_allowed_meta_keys( 'nsw_service' )
		);
	}

	/** Source: TSD 2.4.2, "other keys, including agency keys, are rejected (HTTP 400)". */
	#[Group('tsd-difference')]
	#[TestDox('News items accept no extra fields, the agency included')]
	public function test_site_meta_news(): void {
		$this->use_theme_meta_registry();
		$this->assertSame( array(), nsw_rest_allowed_meta_keys( 'post' ) );
	}

	/** Source: TSD 2.4.2, "other keys, including agency keys, are rejected (HTTP 400)". */
	#[Group('tsd-difference')]
	#[TestDox('An agency sent with a new news item is refused as invalid input (status 400) and nothing is saved')]
	public function test_site_agency_refused_on_new_news_item(): void {
		$this->use_theme_meta_registry();
		$this->caps = array( 'edit_posts' );

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'Hello', 'meta' => array( '_nsw_news_agency' => 'nfa' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'The agency was accepted.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.5.2, "no agency can be set or changed through the REST or content API"; 2.4.2. */
	#[Group('tsd-difference')]
	#[TestDox('The agency of an existing news item cannot be changed through the content API (status 400)')]
	public function test_site_agency_change_refused_on_news_item(): void {
		$this->use_theme_meta_registry();
		$this->add_post( 30, 'post', 'pending', 'news-1' );
		$this->posts[30]['meta']['_nsw_news_agency'] = 'gdc';
		$this->caps = array( 'edit_posts', 'edit_post' );

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'meta' => array( '_nsw_news_agency' => 'nfa' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'The agency was accepted.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertSame( 'gdc', $this->posts[30]['meta']['_nsw_news_agency'] );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.2, "other keys, including agency keys, are rejected (HTTP 400)". */
	#[Group('tsd-difference')]
	#[TestDox('An agency sent with a new service guide is refused as invalid input (status 400) and nothing is saved')]
	public function test_site_agency_refused_on_new_service_guide(): void {
		$this->use_theme_meta_registry();
		$this->caps = array( 'edit_nsw_services' );

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'nsw_service', 'external_id' => 'svc-1', 'title' => 'Import licence', 'meta' => array( '_nsw_service_agency' => 'nfa' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'The agency was accepted.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.2, "meta accepts only the ... document ... and Visible until keys of the type". */
	#[Group('tsd-difference')]
	#[TestDox('A "Visible until" date sent with a new document is saved on it')]
	public function test_site_visible_until_saved_on_new_document(): void {
		$this->use_theme_meta_registry();
		$this->caps = array( 'edit_nsw_document_posts' );

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'nsw_document', 'external_id' => 'doc-1', 'title' => 'Tariff guide', 'meta' => array( '_nsw_theme_visible_until' => '2026-12-31' ) ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 201, $result->get_status() );
		$this->assertContains( array( 101, '_nsw_theme_visible_until', '2026-12-31' ), $this->meta_writes );
	}

	/** Source: TSD 2.4.2, "meta accepts only the ... operation type and Visible until keys of the type"; 2.3.3. */
	#[Group('tsd-difference')]
	#[TestDox('A "Visible until" date sent with a new service guide is saved on it')]
	public function test_site_visible_until_saved_on_new_service_guide(): void {
		$this->use_theme_meta_registry();
		$this->caps = array( 'edit_nsw_services' );

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'nsw_service', 'external_id' => 'svc-2', 'title' => 'Export permit', 'meta' => array( '_nsw_theme_visible_until' => '2027-01-31' ) ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 201, $result->get_status() );
		$this->assertContains( array( 101, '_nsw_theme_visible_until', '2027-01-31' ), $this->meta_writes );
	}

	/* -------------------------------------------------------- input refusals */

	/** Source: TSD 2.4.3, "400 invalid input (... missing external_id"; "nothing is written if validation fails". */
	#[TestDox('An empty or blank external id is refused (status 400) and nothing is looked up or saved')]
	public function test_blank_external_id(): void {
		$this->caps = array( 'edit_posts', 'publish_posts' );
		foreach ( array( '', '   ' ) as $external_id ) {
			$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => $external_id, 'title' => 'Hello' ) ) );
			$this->assert_error( $result, 'nsw_rest_missing_external_id', 400 );
		}
		$this->assertSame( array(), $this->lookups );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.3, "nothing is written if validation fails"; TSD 2.3.2, "validates the whole request before writing". */
	#[TestDox('A request with an extra field that is not allowed is refused (status 400) before anything is looked up or saved')]
	public function test_unknown_meta_refused_before_lookup(): void {
		$this->caps = array( 'edit_nsw_event_posts', 'publish_nsw_event_posts' );

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'nsw_event', 'external_id' => 'event-1', 'title' => 'Workshop', 'meta' => array( 'colour' => 'red' ) ) )
		);

		$this->assert_error( $result, 'nsw_rest_unknown_meta', 400 );
		$this->assertSame( array(), $this->lookups );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.3, "400 invalid input (... missing external_id or title". */
	#[TestDox('A new item without a title is refused (status 400) and nothing is saved')]
	public function test_new_item_without_title(): void {
		$this->caps = array( 'edit_posts', 'publish_posts' );

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'content' => '<p>Body</p>' ) ) );

		$this->assert_error( $result, 'nsw_rest_missing_title', 400 );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.2, "title on create". */
	#[TestDox('A new item with a blank title is refused (status 400) and nothing is saved')]
	public function test_new_item_with_blank_title(): void {
		$this->caps = array( 'edit_posts', 'publish_posts' );

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => "  \t " ) ) );

		$this->assert_error( $result, 'nsw_rest_missing_title', 400 );
		$this->assert_nothing_written();
	}

	/* ------------------------------------------------------------- new items */

	/** Source: TSD 2.4.2, "New item: published if the caller has the publish right"; "HTTP 201 create". */
	#[TestDox('A caller with the publish right creates the item published (status 201)')]
	public function test_create_published(): void {
		$this->caps = array( 'edit_posts', 'publish_posts' );

		$response = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'lang' => 'en', 'title' => 'New portal', 'content' => '<p>Body</p>', 'excerpt' => 'Short' ) )
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			array(
				'result'      => 'created_published',
				'post_id'     => 101,
				'status'      => 'publish',
				'lang'        => 'en',
				'external_id' => 'news-1',
				'link'        => 'https://nsw.test/?p=101',
			),
			$response->get_data()
		);
		$this->assertSame(
			array( 'post_type' => 'post', 'post_title' => 'New portal', 'post_content' => '<p>Body</p>', 'post_excerpt' => 'Short', 'post_status' => 'publish' ),
			$this->inserted[0]
		);
		$this->assertSame( array( array( 101, '_nsw_api_external_id', 'news-1' ) ), $this->meta_writes );
		$this->assertSame( array( array( 101, 'en' ) ), $this->languages_set );
	}

	/** Source: TSD 2.2.2, "the content API creates items as pending"; 2.4.2, "else pending". */
	#[TestDox('A caller without the publish right creates the item as pending for approval (status 201)')]
	public function test_create_pending(): void {
		$this->caps = array( 'edit_nsw_document_posts' );

		$response = nsw_rest_content_upsert( $this->request( array( 'type' => 'nsw_document', 'external_id' => 'doc-1', 'title' => 'Tariff guide' ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'created_pending', $response->get_data()['result'] );
		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertSame( 'pending', $this->inserted[0]['post_status'] );
		$this->assertSame( 'sq', $response->get_data()['lang'] );
		$this->assertContains( 'publish_nsw_document_posts', array_column( $this->cap_checks, 0 ) );
	}

	/** Source: TSD 2.4.2, "Not supported: publish date, categories, featured image"; 2.5.2, no right to publish. */
	#[TestDox('A caller without the publish right cannot publish by sending a status, date, categories or image')]
	public function test_create_ignores_unsupported_fields(): void {
		$this->caps = array( 'edit_posts' );

		$response = nsw_rest_content_upsert(
			$this->request(
				array(
					'type'           => 'post',
					'external_id'    => 'news-2',
					'title'          => 'Hello',
					'status'         => 'publish',
					'post_status'    => 'publish',
					'date'           => '2026-01-01T10:00:00',
					'categories'     => array( 3 ),
					'featured_media' => 55,
				)
			)
		);

		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertSame(
			array( 'post_type' => 'post', 'post_title' => 'Hello', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'pending' ),
			$this->inserted[0]
		);
	}

	/** Source: TSD 2.4.2, "Optional: ... meta". */
	#[TestDox('Allowed extra fields are saved on the new item')]
	public function test_create_saves_meta(): void {
		$this->allow_meta( 'nsw_event', array( '_nsw_theme_event_date', '_nsw_theme_event_location' ) );
		$this->caps = array( 'edit_nsw_event_posts' );

		nsw_rest_content_upsert(
			$this->request(
				array(
					'type'        => 'nsw_event',
					'external_id' => 'event-1',
					'title'       => 'Workshop',
					'meta'        => array( '_nsw_theme_event_date' => '2026-12-01', '_nsw_theme_event_location' => 'Tirana' ),
				)
			)
		);

		$this->assertSame(
			array(
				array( 101, '_nsw_api_external_id', 'event-1' ),
				array( 101, '_nsw_theme_event_date', '2026-12-01' ),
				array( 101, '_nsw_theme_event_location', 'Tirana' ),
			),
			$this->meta_writes
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Text with quotes and backslashes is saved exactly as sent')]
	public function test_create_keeps_quotes_and_backslashes(): void {
		$this->caps = array( 'edit_posts' );
		$title      = 'Customs "green" lane \ notes';
		$content    = '<p>It\'s a \\"test\\" path C:\\temp</p>';

		nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-3', 'title' => $title, 'content' => $content ) ) );

		$this->assertSame( $title, $this->inserted[0]['post_title'] );
		$this->assertSame( $content, $this->inserted[0]['post_content'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An external id sent with spaces around it is saved and answered without them')]
	public function test_create_trims_external_id(): void {
		$this->caps = array( 'edit_posts' );

		$response = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => '  news-4 ', 'title' => 'Hello' ) ) );

		$this->assertSame( 'news-4', $response->get_data()['external_id'] );
		$this->assertSame( 'news-4', $this->lookups[0]['meta_query'][0]['value'] );
		$this->assertSame( array( 101, '_nsw_api_external_id', 'news-4' ), $this->meta_writes[0] );
	}

	/** Source: TSD 2.4.2, "linked as a translation if the external_id exists in another language". */
	#[TestDox('A new English item is linked as the translation of the Albanian item with the same external id')]
	public function test_create_links_translation(): void {
		$this->add_post( 10, 'post', 'publish', 'news-7', 'sq' );
		$this->caps = array( 'edit_posts', 'publish_posts' );

		$response = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-7', 'lang' => 'en', 'title' => 'Hello' ) ) );

		$this->assertSame( 'created_published', $response->get_data()['result'] );
		$this->assertSame( 101, $response->get_data()['post_id'], 'A new item, not the Albanian one.' );
		$this->assertCount( 1, $this->translations_saved );
		$this->assertEquals( array( 'sq' => 10, 'en' => 101 ), $this->translations_saved[0] );
	}

	/** Source: TSD 2.4.2, "linked as a translation if the external_id exists in another language". */
	#[TestDox('A new item without a counterpart in the other language is not linked')]
	public function test_create_without_counterpart_not_linked(): void {
		$this->add_post( 10, 'nsw_event', 'publish', 'another-event', 'en' );
		$this->caps = array( 'edit_posts', 'publish_posts' );

		nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-8', 'title' => 'Hello' ) ) );

		$this->assertSame( array(), $this->translations_saved );
		$this->assertSame( 'en', $this->lookups[1]['lang'], 'The other language was searched.' );
	}

	/** Source: TSD 2.4.3, "500 write failed". */
	#[TestDox('A new item that cannot be saved is reported as a write failure (status 500) and nothing else is saved')]
	public function test_create_write_failure(): void {
		$this->caps        = array( 'edit_posts' );
		$this->write_error = true;

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'Hello' ) ) );

		$this->assert_error( $result, 'db_insert_error', 500 );
		$this->assertSame( array(), $this->meta_writes );
		$this->assertSame( array(), $this->languages_set );
	}

	/* --------------------------------------------- existing items, not live */

	/** Source: TSD 2.4.2, "Existing item: updated if the caller may edit it"; "200 update". */
	#[TestDox('A pending item is updated in place by a caller who may edit it (status 200)')]
	public function test_update_pending_item(): void {
		$this->add_post( 30, 'post', 'pending', 'news-1' );
		$this->caps = array( 'edit_posts', 'edit_post' );

		$response = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'New title', 'content' => '<p>New</p>', 'excerpt' => 'New summary' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'result'      => 'updated_pending',
				'post_id'     => 30,
				'status'      => 'pending',
				'lang'        => 'sq',
				'external_id' => 'news-1',
				'link'        => 'https://nsw.test/?p=30',
			),
			$response->get_data()
		);
		$this->assertSame(
			array( array( 'ID' => 30, 'post_title' => 'New title', 'post_content' => '<p>New</p>', 'post_excerpt' => 'New summary' ) ),
			$this->updated
		);
		$this->assertSame( array(), $this->inserted );
		$this->assertContains( array( 'edit_post', array( 30 ) ), $this->cap_checks );
	}

	/** Source: TSD 2.4.2, "Existing item: updated if the caller may edit it". */
	#[TestDox('A scheduled item is updated in place and reported as updated (status 200)')]
	public function test_update_scheduled_item(): void {
		$this->add_post( 31, 'nsw_event', 'future', 'event-1' );
		$this->caps = array( 'edit_nsw_event_posts', 'edit_post' );

		$response = nsw_rest_content_upsert( $this->request( array( 'type' => 'nsw_event', 'external_id' => 'event-1', 'title' => 'Moved' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'updated', $response->get_data()['result'] );
		$this->assertSame( 'future', $response->get_data()['status'] );
		$this->assertSame( array( array( 'ID' => 31, 'post_title' => 'Moved' ) ), $this->updated );
	}

	/** Source: TSD 2.4.2, "Existing item: updated if the caller may edit it". */
	#[TestDox('A draft item is updated in place and stays a draft (status 200)')]
	public function test_update_draft_item(): void {
		$this->add_post( 32, 'page', 'draft', 'page-1' );
		$this->caps = array( 'edit_pages', 'edit_post' );

		$response = nsw_rest_content_upsert( $this->request( array( 'type' => 'page', 'external_id' => 'page-1', 'content' => '<p>Draft text</p>' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'draft', $response->get_data()['status'] );
		$this->assertSame( array( array( 'ID' => 32, 'post_content' => '<p>Draft text</p>' ) ), $this->updated );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An update changes only the fields sent, keeps the current title when a blank one is sent and clears the content when empty content is sent')]
	public function test_update_only_sent_fields(): void {
		$this->add_post( 30, 'post', 'pending', 'news-1' );
		$this->caps = array( 'edit_posts', 'edit_post' );

		nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => '   ', 'content' => '' ) ) );

		$this->assertSame( array( array( 'ID' => 30, 'post_content' => '' ) ), $this->updated );
	}

	/** Source: TSD 2.4.3, "403 no right for the type or item"; "nothing is written". */
	#[TestDox('A caller who may not edit the pending item is refused (status 403) and nothing is saved')]
	public function test_update_pending_item_without_right(): void {
		$this->allow_meta( 'post', array( '_nsw_theme_note' ) );
		$this->add_post( 30, 'post', 'pending', 'news-1' );
		$this->caps = array( 'edit_posts' );

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'Mine now', 'meta' => array( '_nsw_theme_note' => 'x' ) ) )
		);

		$this->assert_error( $result, 'nsw_rest_cannot_edit', 403 );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.3, "a repeated call (same type, external_id, language) updates the same item"; "Content API callers repeat the call". */
	#[TestDox('Repeating a call with the same content type, external id and language updates the item made by the first call')]
	public function test_repeated_call_updates_same_item(): void {
		$this->caps = array( 'edit_nsw_services', 'edit_post' );
		$call       = array( 'type' => 'nsw_service', 'external_id' => 'svc-3', 'lang' => 'en', 'title' => 'Transit guarantee' );

		$first  = nsw_rest_content_upsert( $this->request( $call ) );
		$second = nsw_rest_content_upsert( $this->request( array( 'title' => 'Transit guarantee, revised' ) + $call ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 'created_pending', $first->get_data()['result'] );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 'updated_pending', $second->get_data()['result'] );
		$this->assertSame( $first->get_data()['post_id'], $second->get_data()['post_id'] );
		$this->assertCount( 1, $this->inserted, 'Only the first call creates an item.' );
		$this->assertSame( array( array( 'ID' => 101, 'post_title' => 'Transit guarantee, revised' ) ), $this->updated );
	}

	/** Source: TSD 2.4.2, "no right to publish or edit published items"; "Not supported: publish date". */
	#[TestDox('A caller without the publish right cannot publish a pending item by sending a status or date with an update')]
	public function test_update_ignores_status_and_date(): void {
		$this->add_post( 30, 'post', 'pending', 'news-1' );
		$this->caps = array( 'edit_posts', 'edit_post' );

		$response = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'Now live?', 'status' => 'publish', 'post_status' => 'publish', 'date' => '2026-01-01T10:00:00' ) )
		);

		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertSame( array( array( 'ID' => 30, 'post_title' => 'Now live?' ) ), $this->updated, 'No status or date is passed on.' );
	}

	/** Source: TSD 2.4.2, "Optional: ... meta". */
	#[TestDox('Allowed extra fields sent with an update are saved on the item')]
	public function test_update_saves_meta(): void {
		$this->allow_meta( 'nsw_event', array( '_nsw_theme_event_time' ) );
		$this->add_post( 31, 'nsw_event', 'pending', 'event-1' );
		$this->caps = array( 'edit_nsw_event_posts', 'edit_post' );

		nsw_rest_content_upsert(
			$this->request( array( 'type' => 'nsw_event', 'external_id' => 'event-1', 'meta' => array( '_nsw_theme_event_time' => '10:30' ) ) )
		);

		$this->assertSame( array( array( 31, '_nsw_theme_event_time', '10:30' ) ), $this->meta_writes );
		$this->assertSame(
			array(),
			array_filter( $this->updated, static fn( array $update ) => array( 'ID' => 31 ) !== $update ),
			'The title, content and summary are not changed.'
		);
	}

	/** Source: TSD 2.4.3, "500 write failed". */
	#[TestDox('A pending item that cannot be saved is reported as a write failure (status 500) and its fields are not saved')]
	public function test_update_pending_item_write_failure(): void {
		$this->allow_meta( 'nsw_event', array( '_nsw_theme_event_time' ) );
		$this->add_post( 31, 'nsw_event', 'pending', 'event-1' );
		$this->caps        = array( 'edit_nsw_event_posts', 'edit_post' );
		$this->write_error = true;

		$result = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'nsw_event', 'external_id' => 'event-1', 'title' => 'X', 'meta' => array( '_nsw_theme_event_time' => '10:30' ) ) )
		);

		$this->assert_error( $result, 'db_update_error', 500 );
		$this->assertSame( array(), $this->meta_writes );
	}

	/* -------------------------------------------------- published items, live */

	/** Source: TSD 2.4.2, "Existing item: updated if the caller may edit it"; "200 update". */
	#[TestDox('A published item is updated live by a caller with the right to edit it (status 200)')]
	public function test_update_published_item_by_editor(): void {
		$this->allow_meta( 'nsw_document', array( '_nsw_theme_doc_size' ) );
		$this->add_post( 33, 'nsw_document', 'publish', 'doc-1', 'en' );
		$this->caps = array( 'edit_nsw_document_posts', 'edit_post' );

		$response = nsw_rest_content_upsert(
			$this->request(
				array(
					'type'        => 'nsw_document',
					'external_id' => 'doc-1',
					'lang'        => 'en',
					'title'       => '',
					'content'     => '<p>Updated</p>',
					'excerpt'     => 'New summary',
					'meta'        => array( '_nsw_theme_doc_size' => '2 MB' ),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'updated', $response->get_data()['result'] );
		$this->assertSame( 'publish', $response->get_data()['status'] );
		$this->assertSame( array( array( 'ID' => 33, 'post_content' => '<p>Updated</p>', 'post_excerpt' => 'New summary' ) ), $this->updated );
		$this->assertSame( array( array( 33, '_nsw_theme_doc_size', '2 MB' ) ), $this->meta_writes );
		$this->assertArrayNotHasKey( 'revision_id', $response->get_data() );
	}

	/** Source: TSD 2.4.3, "500 write failed". */
	#[TestDox('A published item that cannot be saved is reported as a write failure (status 500)')]
	public function test_update_published_item_write_failure(): void {
		$this->add_post( 33, 'nsw_document', 'publish', 'doc-1' );
		$this->caps        = array( 'edit_nsw_document_posts', 'edit_post' );
		$this->write_error = true;

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'nsw_document', 'external_id' => 'doc-1', 'title' => 'New' ) ) );

		$this->assert_error( $result, 'db_update_error', 500 );
	}

	/* ------------------------------------------ published items, by revision */

	/** Source: TSD 2.4.3, "403 no right for the type or item". */
	#[TestDox('A caller who may neither edit nor revise the published item is refused (status 403) and nothing is saved')]
	public function test_published_item_without_revise_right(): void {
		$this->add_post( 20, 'post', 'publish', 'news-1' );
		$this->caps = array( 'edit_posts' );

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'New title' ) ) );

		$this->assert_error( $result, 'nsw_rest_cannot_revise', 403 );
		$this->assertContains( array( 'copy_post', array( 20 ) ), $this->cap_checks );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.3, "501 revision workflow not available". */
	#[TestDox('Without the revision plugin, a change to a published item is refused as not available (status 501)')]
	public function test_published_item_without_revision_plugin(): void {
		$this->add_post( 20, 'post', 'publish', 'news-1' );
		$this->caps = array( 'edit_posts', 'copy_post' );

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'New title' ) ) );

		$this->assert_error( $result, 'nsw_rest_revisions_unavailable', 501 );
		$this->assert_nothing_written();
	}

	/** Source: TSD 2.4.2, "for a published item, a pending revision is created"; 2.2.2, "changes to published items as pending revisions". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A change to a published item by a caller without the right to edit it is sent for approval as a pending revision')]
	public function test_published_item_revision_submitted(): void {
		$this->install_revisions_plugin( 901 );
		$this->allow_meta( 'nsw_event', array( '_nsw_theme_event_location' ) );
		$wpdb = $this->fake_wpdb( null );
		$this->add_post( 20, 'nsw_event', 'publish', 'event-1' );
		$this->caps = array( 'edit_nsw_event_posts', 'copy_post' );

		$response = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'nsw_event', 'external_id' => 'event-1', 'title' => 'New title', 'meta' => array( '_nsw_theme_event_location' => 'Durres' ) ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'result'      => 'revision_submitted',
				'post_id'     => 20,
				'status'      => 'pending-revision',
				'lang'        => 'sq',
				'external_id' => 'event-1',
				'link'        => 'https://nsw.test/?p=20',
				'revision_id' => 901,
			),
			$response->get_data()
		);
		$this->assertSame(
			array(
				array(
					'post_id'         => 20,
					'revision_status' => 'pending-revision',
					'args'            => array( 'suppress_redirect' => true ),
					'data'            => array( 'post_title' => 'New title', 'post_content' => '<p>Live content</p>', 'post_excerpt' => 'Live summary' ),
				),
			),
			$GLOBALS['nsw_test_rvy_calls']
		);
		$this->assertSame( array(), $this->updated, 'The live item is not changed.' );
		$this->assertSame( array( array( 901, '_nsw_theme_event_location', 'Durres' ) ), $this->meta_writes );
		$this->assertSame( array( 'nsw_event', 20, 7 ), $wpdb->prepared[0][1] );
	}

	/** Source: TSD 2.4.3, "a repeated call ... updates ... the caller's open pending revision". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A repeated change by the same caller updates their open pending revision and makes no new one')]
	public function test_published_item_revision_updated(): void {
		$this->install_revisions_plugin( 999 );
		$wpdb = $this->fake_wpdb( 555 );
		$this->add_post( 20, 'post', 'publish', 'news-1', 'en' );
		$this->caps = array( 'edit_posts', 'copy_post' );

		$response = nsw_rest_content_upsert(
			$this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'lang' => 'en', 'title' => 'Second try', 'content' => '<p>Second</p>', 'excerpt' => 'Second summary' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'revision_updated', $response->get_data()['result'] );
		$this->assertSame( 20, $response->get_data()['post_id'] );
		$this->assertSame( 555, $response->get_data()['revision_id'] );
		$this->assertSame( 'pending-revision', $response->get_data()['status'] );
		$this->assertSame(
			array( array( 'ID' => 555, 'post_title' => 'Second try', 'post_content' => '<p>Second</p>', 'post_excerpt' => 'Second summary' ) ),
			$this->updated
		);
		$this->assertSame( array(), $GLOBALS['nsw_test_rvy_calls'], 'No second revision.' );
		$this->assertStringContainsString( "post_mime_type = 'pending-revision'", $wpdb->prepared[0][0] );
		$this->assertSame( array( 'post', 20, 7 ), $wpdb->prepared[0][1], 'Same content type, same item, same caller.' );
	}

	/** Source: TSD 2.4.3, "500 write failed". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('An open pending revision that cannot be saved is reported as a write failure (status 500)')]
	public function test_published_item_revision_update_failure(): void {
		$this->install_revisions_plugin( 999 );
		$this->fake_wpdb( 555 );
		$this->add_post( 20, 'post', 'publish', 'news-1' );
		$this->caps        = array( 'edit_posts', 'copy_post' );
		$this->write_error = true;

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'Second try' ) ) );

		$this->assert_error( $result, 'db_update_error', 500 );
		$this->assertSame( array(), $this->meta_writes );
	}

	/** Source: TSD 2.4.3, "500 write failed". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A revision the revision plugin refuses with an error is reported as a write failure (status 500)')]
	public function test_published_item_revision_plugin_error(): void {
		$this->install_revisions_plugin( $this->wp_error( 'rvy_error', 'Revision not created.' ) );
		$this->fake_wpdb( null );
		$this->add_post( 20, 'post', 'publish', 'news-1' );
		$this->caps = array( 'edit_posts', 'copy_post' );

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'New title' ) ) );

		$this->assert_error( $result, 'rvy_error', 500 );
		$this->assertSame( array(), $this->meta_writes );
	}

	/** Source: TSD 2.4.3, "500 write failed". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A revision the revision plugin does not create is reported as a write failure (status 500)')]
	public function test_published_item_revision_not_created(): void {
		$this->install_revisions_plugin( 0 );
		$this->fake_wpdb( null );
		$this->add_post( 20, 'post', 'publish', 'news-1' );
		$this->caps = array( 'edit_posts', 'copy_post' );

		$result = nsw_rest_content_upsert( $this->request( array( 'type' => 'post', 'external_id' => 'news-1', 'title' => 'New title' ) ) );

		$this->assert_error( $result, 'nsw_rest_revision_failed', 500 );
		$this->assertSame( array(), $this->meta_writes );
	}

	/* --------------------------------------------------------------- answers */

	/** Source: TSD 2.4.2, "HTTP 201 create, 200 update". */
	#[TestDox('Created items are answered with status 201 and every other result with status 200')]
	public function test_answer_status_by_result(): void {
		$expected = array(
			'created_published'  => 201,
			'created_pending'    => 201,
			'updated'            => 200,
			'updated_pending'    => 200,
			'revision_submitted' => 200,
			'revision_updated'   => 200,
		);
		foreach ( $expected as $result => $status ) {
			$this->assertSame( $status, nsw_rest_response( $result, 5, 'publish', 'sq', 'x-1' )->get_status(), $result );
		}
	}

	/** Source: TSD 2.4.2, "revision_id (if a revision is made)". */
	#[TestDox('The revision number is included in the answer only when a revision is made')]
	public function test_answer_revision_number(): void {
		$this->assertArrayNotHasKey( 'revision_id', nsw_rest_response( 'updated', 5, 'publish', 'sq', 'x-1' )->get_data() );
		$this->assertSame( 77, nsw_rest_response( 'revision_submitted', 5, 'pending-revision', 'sq', 'x-1', '77' )->get_data()['revision_id'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An item without a public address is answered with an empty link')]
	public function test_answer_without_public_address(): void {
		$this->no_permalink = true;
		$this->assertSame( '', nsw_rest_response( 'created_pending', 5, 'pending', 'sq', 'x-1' )->get_data()['link'] );
	}

	/* ------------------------------------------------- Application Passwords */

	/** Source: TSD 2.5.1, Application Passwords for content API accounts, "only these accounts can have one". */
	#[Group('tsd-difference')]
	#[TestDox('An administrator account cannot have an Application Password')]
	public function test_administrator_cannot_have_application_password(): void {
		Functions\when( 'user_can' )->alias( static fn( $user, $cap ) => $user instanceof \WP_User && $user->has_cap( $cap ) );
		$administrator = new \WP_User(
			array(
				'ID'         => 1,
				'user_login' => 'site-admin',
				'user_email' => 'admin@example.com',
				'roles'      => array( 'administrator' ),
				'allcaps'    => array( 'manage_options' => true, 'publish_posts' => true, 'edit_published_posts' => true, 'edit_posts' => true ),
			)
		);

		$this->assertFalse( $this->apply_theme_filter( 'wp_is_application_passwords_available_for_user', true, $administrator ) );
	}
}
