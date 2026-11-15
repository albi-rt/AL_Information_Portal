<?php
/**
 * Unit tests for inc/agency-news.php: agency scoping for Agency Officers.
 *
 * Each user can be given one agency on their profile, by an administrator. News items
 * and service guides carry an agency. An Agency Officer only sees and changes the items
 * of their own agency in wp-admin, the items they save take their agency, and
 * administrators are warned when the officer role could skip approval. WordPress is not
 * running: users, items, forms and saved values are simulated in each test.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 1.4 (definitions), 2.2.1 (roles and agency scoping), 2.2.2 (workflow),
 * 2.2.3 (languages), 2.3.1 (data model), 2.3.3 (data dictionary) and 2.5.2
 * (authorisation). Where today's code differs from the TSD, the test expects the TSD
 * and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class AgencyNewsTest extends TestCase {

	private const CUSTOMS = 'customs';
	private const FOOD    = 'food-safety';

	/** Invented agencies, as the agency list gives them: stable id => name. */
	private const AGENCIES = array(
		'customs'     => 'Example Customs Office',
		'food-safety' => 'Example Food Safety Agency',
	);

	/** The agency field of each kind of item (TSD 2.3.3). */
	private const META_KEYS = array(
		'post'        => '_nsw_news_agency',
		'nsw_service' => '_nsw_service_agency',
	);

	/** The six rights an Agency Officer must not have (TSD 2.5.2). */
	private const APPROVAL_RIGHTS = array(
		'publish_posts',
		'edit_published_posts',
		'delete_published_posts',
		'publish_nsw_services',
		'edit_published_nsw_services',
		'delete_published_nsw_services',
	);

	/** @var array<string, mixed> */
	private array $saved_post = array();

	private bool $had_pagenow = false;

	/** @var mixed */
	private $saved_pagenow = null;

	/** @var array<int, array<int, mixed>> agency values written: [action, id, key, value?] */
	private array $writes = array();

	/** @var array<int, array{0: string, 1: string, 2: string}> items whose agency can change: [kind, status, agency] */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		$this->saved_post    = $_POST;
		$this->had_pagenow   = array_key_exists( 'pagenow', $GLOBALS );
		$this->saved_pagenow = $GLOBALS['pagenow'] ?? null;
		$this->writes        = array();
		$this->store         = array();
	}

	protected function tearDown(): void {
		$_POST = $this->saved_post;
		if ( $this->had_pagenow ) {
			$GLOBALS['pagenow'] = $this->saved_pagenow;
		} else {
			unset( $GLOBALS['pagenow'] );
		}
		parent::tearDown();
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * The registrations inc/agency-news.php made on a hook. Other theme files use some
	 * of the same hooks, so registrations are picked by the file that defines them.
	 *
	 * @return array<int, array{callback: callable, priority: int, accepted_args: int}>
	 */
	private function theme_registrations( string $hook ): array {
		return array_values(
			array_filter(
				nsw_test_hook_registrations( $hook ),
				static function ( array $registration ): bool {
					$callback = $registration['callback'];
					if ( ! ( $callback instanceof \Closure ) && ! ( is_string( $callback ) && function_exists( $callback ) ) ) {
						return false;
					}
					$file = ( new \ReflectionFunction( $callback ) )->getFileName();
					return is_string( $file ) && str_ends_with( str_replace( '\\', '/', $file ), 'inc/agency-news.php' );
				}
			)
		);
	}

	private function theme_callback( string $hook ): callable {
		$registrations = $this->theme_registrations( $hook );
		$this->assertCount( 1, $registrations, "One agency callback is expected on the hook $hook." );
		return $registrations[0]['callback'];
	}

	/**
	 * Simulates the signed-in user. "agency_officer" is an Agency Officer and
	 * "administrator" has manage_options. $agency is the agency on their profile.
	 */
	private function sign_in( string $role, string $agency = '', int $id = 7 ): \WP_User {
		$user = new \WP_User(
			array(
				'ID'         => $id,
				'user_login' => $role . '.one',
				'user_email' => $role . '.one@example.com',
				'roles'      => array( $role ),
			)
		);
		// The theme looks the role name up once per PHP process and keeps it. It is
		// fixed here so these tests do not depend on the order the test files run in.
		Functions\when( 'nsw_agency_officer_role' )->justReturn( 'agency_officer' );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );
		Functions\when( 'get_current_user_id' )->justReturn( $id );
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $value ) => ( 'id' === $field && (int) $value === $id ) ? $user : false
		);
		Functions\when( 'user_can' )->alias(
			static fn( $who, $cap ) => 'manage_options' === $cap && in_array( 'administrator', (array) $who->roles, true )
		);
		Functions\when( 'get_user_meta' )->alias(
			static fn( $user_id, $key = '', $single = false ) => ( (int) $user_id === $id && '_nsw_agency' === $key ) ? $agency : ''
		);
		return $user;
	}

	/**
	 * Simulated items: id => [kind, status, agency]. Their agency is read from the
	 * field named in TSD 2.3.3 for their kind.
	 */
	private function given_items(): void {
		$items = array(
			101 => array( 'post', 'publish', self::CUSTOMS ),
			102 => array( 'post', 'publish', self::FOOD ),
			103 => array( 'post', 'draft', '' ),
			104 => array( 'post', 'auto-draft', '' ),
			201 => array( 'nsw_service', 'publish', self::CUSTOMS ),
			202 => array( 'nsw_service', 'pending', self::FOOD ),
			301 => array( 'page', 'publish', self::CUSTOMS ),
		);
		$keys  = self::META_KEYS;
		Functions\when( 'get_post_type' )->alias( static fn( $id ) => $items[ (int) $id ][0] ?? false );
		Functions\when( 'get_post_status' )->alias( static fn( $id ) => $items[ (int) $id ][1] ?? false );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key = '', $single = false ) use ( $items, $keys ) {
				$item = $items[ (int) $id ] ?? null;
				return ( null !== $item && ( $keys[ $item[0] ] ?? null ) === $key ) ? $item[2] : '';
			}
		);
	}

	/** Asks the agency check whether a user may do $cap on an item. */
	private function check( string $cap, int $item_id, int $user_id = 7, array $caps = array( 'edit_posts' ) ): array {
		return ( $this->theme_callback( 'map_meta_cap' ) )( $caps, $cap, $user_id, array( $item_id ) );
	}

	/** Records every agency value saved on items. */
	private function record_item_writes(): void {
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->writes[] = array( 'set', (int) $id, $key, $value );
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				$this->writes[] = array( 'delete', (int) $id, $key );
				return true;
			}
		);
	}

	/** Records every agency value saved on user profiles. */
	private function record_user_writes(): void {
		Functions\when( 'update_user_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->writes[] = array( 'set', (int) $id, $key, $value );
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $id, $key ) {
				$this->writes[] = array( 'delete', (int) $id, $key );
				return true;
			}
		);
	}

	/**
	 * Simulates form handling: a form's security token is "token-<action>" and is only
	 * accepted for that action; input cleaning removes tags and outer spaces.
	 */
	private function given_forms(): void {
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action = -1, $name = '_wpnonce' ) {
				echo '<input type="hidden" name="' . $name . '" value="token-' . $action . '" />';
				return '';
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn( $nonce, $action = -1 ) => ( 'token-' . $action ) === $nonce ? 1 : false
		);
		Functions\when( 'wp_unslash' )->alias( static fn( $value ) => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ) => trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) )
		);
		Functions\when( 'selected' )->alias(
			static function ( $selected, $current = true, $display = true ) {
				$result = (string) $selected === (string) $current ? " selected='selected'" : '';
				if ( $display ) {
					echo $result;
				}
				return $result;
			}
		);
	}

	/** @return array<string, array{label: string, selected: bool}> option value => label and state */
	private function options_of( string $html, string $select_name ): array {
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$options = array();
		foreach ( ( new \DOMXPath( $doc ) )->query( '//select[@name="' . $select_name . '"]/option' ) as $option ) {
			$options[ $option->getAttribute( 'value' ) ] = array(
				'label'    => trim( $option->textContent ),
				'selected' => $option->hasAttribute( 'selected' ),
			);
		}
		return $options;
	}

	/** The value of a hidden form field, or null when the field is missing. */
	private function hidden_value( string $html, string $name ): ?string {
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$nodes = ( new \DOMXPath( $doc ) )->query( '//input[@type="hidden"][@name="' . $name . '"]' );
		return $nodes->length ? $nodes->item( 0 )->getAttribute( 'value' ) : null;
	}

	/** The agency field registrations made on "init": kind => field => settings. */
	private function registered_agency_fields(): array {
		$registered = array();
		Functions\when( 'register_post_meta' )->alias(
			static function ( $type, $key, $args ) use ( &$registered ) {
				$registered[ $type ][ $key ] = $args;
				return true;
			}
		);
		( $this->theme_callback( 'init' ) )();
		return $registered;
	}

	/**
	 * Whether the REST API would accept a new value for a registered field, following
	 * WordPress: the field must be shown in the REST API and its auth_callback (for a
	 * protected field without one: refuse) must allow the change.
	 */
	private function rest_accepts_change( array $settings, string $key, int $item_id ): bool {
		if ( empty( $settings['show_in_rest'] ) ) {
			return false;
		}
		$auth = $settings['auth_callback'] ?? static fn() => false;
		return (bool) $auth( false, $key, $item_id, 7, 'edit_post_meta', array( 'edit_post_meta' ) );
	}

	/** A list query in wp-admin, as WordPress passes it before reading the list. */
	private function list_query( array $vars = array(), bool $main = true ): object {
		return new class( $vars, $main ) {
			/** @var array<string, mixed> */
			public array $vars;
			private bool $main;

			public function __construct( array $vars, bool $main ) {
				$this->vars = $vars;
				$this->main = $main;
			}

			public function is_main_query(): bool {
				return $this->main;
			}

			public function get( $key, $default = '' ) {
				return $this->vars[ $key ] ?? $default;
			}

			public function set( $key, $value ): void {
				$this->vars[ $key ] = $value;
			}
		};
	}

	/** The Agency Officer role, holding the given rights. */
	private function officer_role_with( array $rights ): object {
		return new class( $rights ) {
			/** @var array<int, string> */
			private array $rights;

			public function __construct( array $rights ) {
				$this->rights = $rights;
			}

			public function has_cap( $cap ): bool {
				return in_array( $cap, $this->rights, true );
			}
		};
	}

	private function approval_warning(): string {
		ob_start();
		( $this->theme_callback( 'admin_notices' ) )();
		return (string) ob_get_clean();
	}

	private function column( string $type, string $column, int $item_id ): string {
		ob_start();
		( $this->theme_callback( 'manage_' . $type . '_posts_custom_column' ) )( $column, $item_id );
		return (string) ob_get_clean();
	}

	/** Agency items in two languages, with Polylang active; no lang asked = current language, or all. */
	private function given_agencies_in_two_languages( $current_language ): void {
		$albanian = array(
			new \WP_Post( array( 'ID' => 11, 'post_type' => 'nsw_agency', 'post_name' => 'zyra-doganore', 'post_title' => 'Zyra Doganore Shembull' ) ),
			new \WP_Post( array( 'ID' => 12, 'post_type' => 'nsw_agency', 'post_name' => 'agjencia-ushqimit', 'post_title' => 'Agjencia Shembull e Ushqimit' ) ),
		);
		$english  = array(
			new \WP_Post( array( 'ID' => 21, 'post_type' => 'nsw_agency', 'post_name' => 'customs-office', 'post_title' => 'Example Customs Office' ) ),
			new \WP_Post( array( 'ID' => 22, 'post_type' => 'nsw_agency', 'post_name' => 'food-agency', 'post_title' => 'Example Food Safety Agency' ) ),
		);
		$meta = array(
			11 => array( '_nsw_theme_agency_id' => self::CUSTOMS, '_nsw_theme_agency_name' => 'Zyra Doganore Shembull' ),
			12 => array( '_nsw_theme_agency_id' => self::FOOD, '_nsw_theme_agency_name' => 'Agjencia Shembull e Ushqimit' ),
			21 => array( '_nsw_theme_agency_id' => self::CUSTOMS, '_nsw_theme_agency_name' => 'Example Customs Office' ),
			22 => array( '_nsw_theme_agency_id' => self::FOOD, '_nsw_theme_agency_name' => 'Example Food Safety Agency' ),
		);
		Functions\when( 'pll_default_language' )->justReturn( 'sq' );
		Functions\when( 'pll_current_language' )->justReturn( $current_language );
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( $albanian, $english, $current_language ) {
				$lang = $args['lang'] ?? ( $current_language ?: '' );
				if ( 'sq' === $lang ) {
					return $albanian;
				}
				if ( 'en' === $lang ) {
					return $english;
				}
				return array_merge( $albanian, $english );
			}
		);
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key = '', $single = false ) => $meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post->post_title );
	}

	/* ------------------------------------------------------- the officer role */

	/** Source: TSD 1.4, "WordPress role (agency_officer)". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The Agency Officer role is found under its standard name')]
	public function test_role_found_under_standard_name(): void {
		Functions\when( 'get_role' )->alias( static fn( $name ) => 'agency_officer' === $name ? new \stdClass() : null );
		$this->assertSame( 'agency_officer', nsw_agency_officer_role() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The Agency Officer role is also found when its name is written with a hyphen')]
	public function test_role_found_with_hyphen(): void {
		Functions\when( 'get_role' )->alias( static fn( $name ) => 'agency-officer' === $name ? new \stdClass() : null );
		$this->assertSame( 'agency-officer', nsw_agency_officer_role() );
	}

	/** Source: TSD 1.4, "WordPress role (agency_officer)". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('When no Agency Officer role is set up yet, the standard role name is used')]
	public function test_role_falls_back_to_standard_name(): void {
		Functions\when( 'get_role' )->justReturn( null );
		$this->assertSame( 'agency_officer', nsw_agency_officer_role() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The Agency Officer role name is looked up only once per page load')]
	public function test_role_name_looked_up_once(): void {
		$lookups = 0;
		Functions\when( 'get_role' )->alias(
			static function ( $name ) use ( &$lookups ) {
				++$lookups;
				return 'agency_officer' === $name ? new \stdClass() : null;
			}
		);
		$first        = nsw_agency_officer_role();
		$after_first  = $lookups;
		$second       = nsw_agency_officer_role();

		$this->assertSame( $first, $second );
		$this->assertSame( $after_first, $lookups );
	}

	/* ------------------------------------------------------- kinds of content */

	/** Source: TSD 2.3.3, "_nsw_service_agency, _nsw_news_agency ... Agency of the item". */
	#[TestDox('News items and service guides are the two kinds of content that carry an agency, each in its own field')]
	public function test_scoped_kinds_and_fields(): void {
		$types = nsw_agency_scoped_types();
		$this->assertSame( array( 'post', 'nsw_service' ), array_keys( $types ) );
		$this->assertSame( '_nsw_news_agency', $types['post']['meta'] );
		$this->assertSame( '_nsw_service_agency', $types['nsw_service']['meta'] );
	}

	/** Source: TSD 2.5.2, "Not granted: publish_posts, edit_published_posts, delete_published_posts, ...". */
	#[TestDox('The approval check watches exactly the six rights the Agency Officer role must not have')]
	public function test_watched_approval_rights(): void {
		$watched = array();
		foreach ( nsw_agency_scoped_types() as $conf ) {
			$watched = array_merge( $watched, $conf['bypass'] );
		}
		sort( $watched );
		$expected = self::APPROVAL_RIGHTS;
		sort( $expected );
		$this->assertSame( $expected, $watched );
	}

	/** Source: TSD 2.3.3, "_nsw_service_agency, _nsw_news_agency String (agency id)". */
	#[TestDox('The agency of a news item or service guide is stored as one cleaned text value')]
	public function test_agency_fields_registered(): void {
		$registered = $this->registered_agency_fields();

		foreach ( self::META_KEYS as $type => $key ) {
			$this->assertArrayHasKey( $key, $registered[ $type ] ?? array(), "Agency field of $type" );
			$this->assertTrue( $registered[ $type ][ $key ]['single'] );
			$this->assertSame( 'string', $registered[ $type ][ $key ]['type'] );
			$this->assertSame( 'sanitize_text_field', $registered[ $type ][ $key ]['sanitize_callback'] );
		}
	}

	/** Source: TSD 2.2.1, "No REST API can set or change the agency." */
	#[TestDox('A user without editing rights cannot change the agency of an item through the REST API')]
	public function test_rest_refuses_user_without_editing_rights(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$registered = $this->registered_agency_fields();

		$this->assertFalse( $this->rest_accepts_change( $registered['post']['_nsw_news_agency'], '_nsw_news_agency', 101 ) );
		$this->assertFalse( $this->rest_accepts_change( $registered['nsw_service']['_nsw_service_agency'], '_nsw_service_agency', 201 ) );
	}

	/** Source: TSD 2.2.1, "No REST API can set or change the agency" (TSD 2.5.2: "no agency can be set or changed through the REST or content API"). */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('An Agency Officer cannot move a news item or service guide to another agency through the REST API')]
	public function test_rest_refuses_agency_change_by_officer(): void {
		define( 'REST_REQUEST', true );
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();
		// An Agency Officer may write news items and service guides, but is no administrator.
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => ! in_array( $cap, array( 'manage_options', 'edit_users' ), true ) );
		$registered = $this->registered_agency_fields();

		$this->assertFalse(
			$this->rest_accepts_change( $registered['post']['_nsw_news_agency'], '_nsw_news_agency', 101 ),
			'The REST API accepts a new agency for a news item from an Agency Officer.'
		);
		$this->assertFalse(
			$this->rest_accepts_change( $registered['nsw_service']['_nsw_service_agency'], '_nsw_service_agency', 201 ),
			'The REST API accepts a new agency for a service guide from an Agency Officer.'
		);
	}

	/** Source: TSD 2.2.1, "No REST API can set or change the agency." */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Not even an administrator can set the agency of an item through the REST API')]
	public function test_rest_refuses_agency_change_by_administrator(): void {
		define( 'REST_REQUEST', true );
		$this->sign_in( 'administrator' );
		$this->given_items();
		Functions\when( 'current_user_can' )->justReturn( true );
		$registered = $this->registered_agency_fields();

		$this->assertFalse(
			$this->rest_accepts_change( $registered['post']['_nsw_news_agency'], '_nsw_news_agency', 103 ),
			'The REST API accepts an agency for a news item.'
		);
		$this->assertFalse(
			$this->rest_accepts_change( $registered['nsw_service']['_nsw_service_agency'], '_nsw_service_agency', 202 ),
			'The REST API accepts an agency for a service guide.'
		);
	}

	/* --------------------------------------------------------- a user's agency */

	/** Source: TSD 2.3.3, "_nsw_agency String (agency id) ... User's agency". */
	#[TestDox('The agency of a user is read from their profile')]
	public function test_user_agency_from_profile(): void {
		Functions\expect( 'get_user_meta' )->once()->with( 12, '_nsw_agency', true )->andReturn( self::FOOD );
		$this->assertSame( self::FOOD, nsw_user_agency( 12 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without a user given, the agency of the signed-in user is read')]
	public function test_user_agency_of_signed_in_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\expect( 'get_user_meta' )->once()->with( 7, '_nsw_agency', true )->andReturn( self::CUSTOMS );
		$this->assertSame( self::CUSTOMS, nsw_user_agency() );
	}

	/** Source: TSD 2.3.3, "_nsw_agency ... Optional". */
	#[TestDox('A user without an assigned agency has no agency')]
	public function test_user_without_agency(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$this->assertSame( '', nsw_user_agency( 12 ) );
		Functions\when( 'get_user_meta' )->justReturn( false ); // Unknown user.
		$this->assertSame( '', nsw_user_agency( 999 ) );
	}

	/* -------------------------------------------------- who is an Agency Officer */

	/** Source: TSD 1.4, "Agency Officer: WordPress role (agency_officer) for a user of one agency". */
	#[TestDox('A user with the Agency Officer role is treated as an Agency Officer')]
	public function test_officer_role_counts(): void {
		$officer = $this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->assertTrue( nsw_is_agency_officer( $officer ) );
		$this->assertTrue( nsw_is_agency_officer() ); // The signed-in user.
	}

	/** Source: TSD 2.5.2, Administrator "no agency limit". */
	#[TestDox('An administrator is never limited to one agency, even with the Agency Officer role as well')]
	public function test_administrator_never_officer(): void {
		$this->sign_in( 'agency_officer' );
		$admin = new \WP_User( array( 'ID' => 3, 'roles' => array( 'administrator', 'agency_officer' ) ) );
		$this->assertFalse( nsw_is_agency_officer( $admin ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A user with another role is not limited to one agency')]
	public function test_other_role_not_officer(): void {
		$editor = $this->sign_in( 'editor' );
		$this->assertFalse( nsw_is_agency_officer( $editor ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A visitor who is not signed in is not treated as an Agency Officer')]
	public function test_visitor_not_officer(): void {
		Functions\when( 'nsw_agency_officer_role' )->justReturn( 'agency_officer' );
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( array( 'ID' => 0, 'roles' => array( 'agency_officer' ) ) ) );
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertFalse( nsw_is_agency_officer() );
	}

	/* ---------------------------------------------------------- the agency list */

	/** Source: TSD 2.3.1, "Each agency has a stable agency id, used by users, news items and service guides". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The agency list gives each published agency by its stable id and name, in alphabetical order of name')]
	public function test_agency_list_ids_and_names_sorted(): void {
		$asked = array();
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$asked ) {
				$asked = $args;
				return array(
					new \WP_Post( array( 'ID' => 31, 'post_type' => 'nsw_agency', 'post_name' => 'zeta', 'post_title' => 'A title' ) ),
					new \WP_Post( array( 'ID' => 32, 'post_type' => 'nsw_agency', 'post_name' => 'alpha', 'post_title' => 'B title' ) ),
				);
			}
		);
		$meta = array(
			31 => array( '_nsw_theme_agency_id' => 'zeta-board', '_nsw_theme_agency_name' => 'Zeta Example Board' ),
			32 => array( '_nsw_theme_agency_id' => 'alpha-authority', '_nsw_theme_agency_name' => 'Alpha Example Authority' ),
		);
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key = '', $single = false ) => $meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post->post_title );

		$this->assertSame(
			array( 'alpha-authority' => 'Alpha Example Authority', 'zeta-board' => 'Zeta Example Board' ),
			nsw_agency_choices()
		);
		$this->assertSame( 'nsw_agency', $asked['post_type'] );
		$this->assertSame( 'publish', $asked['post_status'] );
		$this->assertSame( -1, $asked['posts_per_page'] );
		$this->assertArrayNotHasKey( 'lang', $asked ); // No language plugin here.
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('An agency without a stable id or name is listed by its web address name and its title')]
	public function test_agency_list_fallbacks(): void {
		Functions\when( 'get_posts' )->justReturn(
			array( new \WP_Post( array( 'ID' => 33, 'post_type' => 'nsw_agency', 'post_name' => 'example-board', 'post_title' => 'Example Board' ) ) )
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post->post_title );

		$this->assertSame( array( 'example-board' => 'Example Board' ), nsw_agency_choices() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('In the administration area each agency is listed once, under its name in the default language')]
	public function test_agency_list_once_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->given_agencies_in_two_languages( false ); // wp-admin showing all languages.

		$this->assertSame(
			array( self::FOOD => 'Agjencia Shembull e Ushqimit', self::CUSTOMS => 'Zyra Doganore Shembull' ),
			nsw_agency_choices()
		);
	}

	/** Source: TSD 2.2.3, "Albanian (default) and English for all public pages". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('On an English page the agencies are listed under their English names')]
	public function test_agency_list_in_english_on_english_page(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->given_agencies_in_two_languages( 'en' );

		$this->assertSame(
			array( self::CUSTOMS => 'Example Customs Office', self::FOOD => 'Example Food Safety Agency' ),
			nsw_agency_choices()
		);
	}

	/** Source: TSD 2.2.3, "Albanian (default) and English for all public pages". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('On an Albanian page the agencies are listed under their Albanian names')]
	public function test_agency_list_in_albanian_on_albanian_page(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->given_agencies_in_two_languages( 'sq' );

		$this->assertSame(
			array( self::FOOD => 'Agjencia Shembull e Ushqimit', self::CUSTOMS => 'Zyra Doganore Shembull' ),
			nsw_agency_choices()
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('On an English page an agency without an English version is still listed, under its Albanian name')]
	public function test_agency_list_english_page_keeps_agency_without_english_version(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->given_agencies_in_two_languages( 'en' );
		// Only the customs office has an English version.
		$albanian = array(
			new \WP_Post( array( 'ID' => 11, 'post_type' => 'nsw_agency', 'post_name' => 'zyra-doganore', 'post_title' => 'Zyra Doganore Shembull' ) ),
			new \WP_Post( array( 'ID' => 12, 'post_type' => 'nsw_agency', 'post_name' => 'agjencia-ushqimit', 'post_title' => 'Agjencia Shembull e Ushqimit' ) ),
		);
		$english  = array(
			new \WP_Post( array( 'ID' => 21, 'post_type' => 'nsw_agency', 'post_name' => 'customs-office', 'post_title' => 'Example Customs Office' ) ),
		);
		Functions\when( 'get_posts' )->alias(
			static fn( $args ) => 'en' === ( $args['lang'] ?? '' ) ? $english : $albanian
		);

		$this->assertSame(
			array( self::FOOD => 'Agjencia Shembull e Ushqimit', self::CUSTOMS => 'Example Customs Office' ),
			nsw_agency_choices()
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The agency list is read only once per page load')]
	public function test_agency_list_read_once(): void {
		$reads = 0;
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$reads ) {
				++$reads;
				return array( new \WP_Post( array( 'ID' => 34, 'post_name' => 'example-office', 'post_title' => 'Example Office' ) ) );
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post->post_title );

		$first  = nsw_agency_choices();
		$second = nsw_agency_choices();

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $reads );
	}

	/* ------------------------------------------------ the profile agency field */

	/** Source: TSD 2.2.1, "One agency per user, set by administrators". */
	#[TestDox('The assigned agency field is on the profile, edit user and add user screens, and is saved from each')]
	public function test_profile_field_on_user_screens(): void {
		foreach ( array( 'show_user_profile', 'edit_user_profile', 'user_new_form' ) as $screen ) {
			$this->assertContains( 'nsw_render_user_agency_field', array_column( nsw_test_hook_registrations( $screen ), 'callback' ), $screen );
		}
		foreach ( array( 'personal_options_update', 'edit_user_profile_update', 'user_register' ) as $save ) {
			$this->assertContains( 'nsw_save_user_agency', array_column( nsw_test_hook_registrations( $save ), 'callback' ), $save );
		}
	}

	/** Source: TSD 2.2.1, "One agency per user, set by administrators". */
	#[TestDox('A user who may not manage users does not see the assigned agency field')]
	public function test_profile_field_hidden_without_user_management(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->expectOutputString( '' );
		nsw_render_user_agency_field( new \WP_User( array( 'ID' => 7, 'roles' => array( 'agency_officer' ) ) ) );
	}

	/** Source: TSD 2.2.1, "One agency per user, set by administrators". */
	#[TestDox('An administrator sees every agency in the field, with the user\'s current agency chosen')]
	public function test_profile_field_lists_agencies_with_current_chosen(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_users' === $cap );
		Functions\when( 'nsw_agency_choices' )->justReturn( self::AGENCIES );
		Functions\when( 'get_user_meta' )->alias( static fn( $id, $key = '', $single = false ) => ( 12 === (int) $id && '_nsw_agency' === $key ) ? self::FOOD : '' );
		$this->given_forms();

		ob_start();
		nsw_render_user_agency_field( new \WP_User( array( 'ID' => 12, 'roles' => array( 'agency_officer' ) ) ) );
		$html = (string) ob_get_clean();

		$options = $this->options_of( $html, 'nsw_agency' );
		$this->assertSame( array( '', self::CUSTOMS, self::FOOD ), array_keys( $options ) );
		$this->assertSame( 'Example Customs Office', $options[ self::CUSTOMS ]['label'] );
		$this->assertSame( 'Example Food Safety Agency', $options[ self::FOOD ]['label'] );
		$this->assertTrue( $options[ self::FOOD ]['selected'] );
		$this->assertFalse( $options[ self::CUSTOMS ]['selected'] );
		$this->assertFalse( $options['']['selected'] );
		$this->assertSame( 'token-nsw_user_agency', $this->hidden_value( $html, 'nsw_user_agency_nonce' ) );
	}

	/** Source: TSD 2.2.1, "One agency per user, set by administrators". */
	#[TestDox('On the add user screen the field is shown with no agency chosen')]
	public function test_profile_field_on_add_user_screen(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_users' === $cap );
		Functions\when( 'nsw_agency_choices' )->justReturn( self::AGENCIES );
		Functions\expect( 'get_user_meta' )->never();
		$this->given_forms();

		ob_start();
		nsw_render_user_agency_field( 'add-new-user' );
		$html = (string) ob_get_clean();

		$options = $this->options_of( $html, 'nsw_agency' );
		$this->assertCount( 3, $options );
		$this->assertSame( array(), array_keys( array_filter( $options, static fn( $o ) => $o['selected'] ) ) );
		$this->assertSame( 'token-nsw_user_agency', $this->hidden_value( $html, 'nsw_user_agency_nonce' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The field is not shown when an existing user is added to a site of a network')]
	public function test_profile_field_not_on_add_existing_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->expectOutputString( '' );
		nsw_render_user_agency_field( 'add-existing-user' );
	}

	/* ------------------------------------------- saving the agency of a user */

	/** Source: TSD 2.2.1, "One agency per user, set by administrators". */
	#[TestDox('An administrator can assign an agency to a user')]
	public function test_admin_assigns_agency(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_users' === $cap );
		$this->given_forms();
		$this->record_user_writes();
		$_POST['nsw_user_agency_nonce'] = 'token-nsw_user_agency';
		$_POST['nsw_agency']            = self::CUSTOMS;

		nsw_save_user_agency( 12 );

		$this->assertSame( array( array( 'set', 12, '_nsw_agency', self::CUSTOMS ) ), $this->writes );
	}

	/** Source: TSD 2.3.3, "_nsw_agency ... Optional". */
	#[TestDox('An administrator can remove the agency from a user by choosing none')]
	public function test_admin_removes_agency(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_users' === $cap );
		$this->given_forms();
		$this->record_user_writes();
		$_POST['nsw_user_agency_nonce'] = 'token-nsw_user_agency';
		$_POST['nsw_agency']            = '';

		nsw_save_user_agency( 12 );

		$this->assertSame( array( array( 'delete', 12, '_nsw_agency' ) ), $this->writes );
	}

	/** Source: TSD 2.5.2, "The theme sanitises input and escapes output." */
	#[TestDox('The agency sent in the user form is cleaned of markup and spaces before it is saved')]
	public function test_user_agency_cleaned(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_users' === $cap );
		$this->given_forms();
		$this->record_user_writes();
		$_POST['nsw_user_agency_nonce'] = 'token-nsw_user_agency';
		$_POST['nsw_agency']            = '  <b>customs</b> ';

		nsw_save_user_agency( 12 );

		$this->assertSame( array( array( 'set', 12, '_nsw_agency', self::CUSTOMS ) ), $this->writes );
	}

	/** Source: TSD 2.2.1, "One agency per user, set by administrators". */
	#[TestDox('An Agency Officer cannot change their own agency, even by sending a made-up form')]
	public function test_officer_cannot_change_own_agency(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->given_forms();
		$this->record_user_writes();
		$_POST['nsw_user_agency_nonce'] = 'token-nsw_user_agency';
		$_POST['nsw_agency']            = self::FOOD;

		nsw_save_user_agency( 7 );

		$this->assertSame( array(), $this->writes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A change of a user\'s agency without the form\'s security token is ignored')]
	public function test_user_agency_without_token_ignored(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->given_forms();
		$this->record_user_writes();
		$_POST['nsw_agency'] = self::FOOD;
		unset( $_POST['nsw_user_agency_nonce'] );

		nsw_save_user_agency( 12 );

		$this->assertSame( array(), $this->writes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A change of a user\'s agency with a wrong or expired security token is ignored')]
	public function test_user_agency_with_wrong_token_ignored(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->given_forms();
		$this->record_user_writes();
		$_POST['nsw_agency']            = self::FOOD;
		$_POST['nsw_user_agency_nonce'] = 'token-nsw_agency_box'; // A token of another form.

		nsw_save_user_agency( 12 );

		$this->assertSame( array(), $this->writes );
	}

	/* ------------------------------------------------------- the Agency box */

	/** Source: TSD 2.5.2, "Their items take the profile's agency". */
	#[TestDox('An Agency Officer is not offered a choice of agency when editing a news item or service guide')]
	public function test_no_agency_box_for_officer(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$boxes = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( ...$args ) use ( &$boxes ) {
				$boxes[] = $args;
			}
		);

		( $this->theme_callback( 'add_meta_boxes_post' ) )();
		( $this->theme_callback( 'add_meta_boxes_nsw_service' ) )();

		$this->assertSame( array(), $boxes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An administrator gets an Agency box beside news items and service guides')]
	public function test_agency_box_for_administrator(): void {
		$this->sign_in( 'administrator' );
		$boxes = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( ...$args ) use ( &$boxes ) {
				$boxes[] = $args;
			}
		);

		( $this->theme_callback( 'add_meta_boxes_post' ) )();
		( $this->theme_callback( 'add_meta_boxes_nsw_service' ) )();

		$this->assertSame(
			array(
				array( 'nsw_agency_box', 'Agency', 'nsw_render_agency_box', 'post', 'side', 'default' ),
				array( 'nsw_agency_box', 'Agency', 'nsw_render_agency_box', 'nsw_service', 'side', 'default' ),
			),
			$boxes
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The Agency box lists every agency and shows the item\'s current agency as chosen')]
	public function test_agency_box_shows_current_agency(): void {
		$this->given_items();
		$this->given_forms();
		Functions\when( 'nsw_agency_choices' )->justReturn( self::AGENCIES );

		ob_start();
		nsw_render_agency_box( new \WP_Post( array( 'ID' => 202, 'post_type' => 'nsw_service' ) ) );
		$html = (string) ob_get_clean();

		$options = $this->options_of( $html, 'nsw_agency_box' );
		$this->assertSame( array( '', self::CUSTOMS, self::FOOD ), array_keys( $options ) );
		$this->assertSame( 'Example Food Safety Agency', $options[ self::FOOD ]['label'] );
		$this->assertTrue( $options[ self::FOOD ]['selected'] );
		$this->assertFalse( $options[ self::CUSTOMS ]['selected'] );
		$this->assertSame( 'token-nsw_agency_box', $this->hidden_value( $html, 'nsw_agency_box_nonce' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The Agency box of an item without an agency has no agency chosen')]
	public function test_agency_box_without_agency(): void {
		$this->given_items();
		$this->given_forms();
		Functions\when( 'nsw_agency_choices' )->justReturn( self::AGENCIES );

		ob_start();
		nsw_render_agency_box( new \WP_Post( array( 'ID' => 103, 'post_type' => 'post' ) ) );
		$html = (string) ob_get_clean();

		$options = $this->options_of( $html, 'nsw_agency_box' );
		$this->assertCount( 3, $options );
		$this->assertSame( array(), array_keys( array_filter( $options, static fn( $o ) => $o['selected'] ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The Agency box shows nothing on content that does not carry an agency')]
	public function test_agency_box_empty_for_other_content(): void {
		Functions\expect( 'wp_nonce_field' )->never();
		$this->expectOutputString( '' );
		nsw_render_agency_box( new \WP_Post( array( 'ID' => 301, 'post_type' => 'page' ) ) );
	}

	/* -------------------------------------------- tagging an item on save */

	/** Source: TSD 2.5.2, "Their items take the profile's agency". */
	#[TestDox('A news item saved by an Agency Officer is tagged with the officer\'s agency')]
	public function test_officer_news_item_tagged(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->record_item_writes();

		( $this->theme_callback( 'save_post' ) )( 103, new \WP_Post( array( 'ID' => 103, 'post_type' => 'post' ) ) );

		$this->assertSame( array( array( 'set', 103, '_nsw_news_agency', self::CUSTOMS ) ), $this->writes );
	}

	/** Source: TSD 2.5.2, "Their items take the profile's agency". */
	#[TestDox('A service guide saved by an Agency Officer is tagged with the officer\'s agency')]
	public function test_officer_service_guide_tagged(): void {
		$this->sign_in( 'agency_officer', self::FOOD );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->record_item_writes();

		( $this->theme_callback( 'save_post' ) )( 202, new \WP_Post( array( 'ID' => 202, 'post_type' => 'nsw_service' ) ) );

		$this->assertSame( array( array( 'set', 202, '_nsw_service_agency', self::FOOD ) ), $this->writes );
	}

	/** Source: TSD 2.5.2, "Their items take the profile's agency". */
	#[TestDox('An Agency Officer cannot give an item another agency by sending a made-up Agency box value')]
	public function test_officer_cannot_choose_other_agency(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_forms();
		$this->record_item_writes();
		$_POST['nsw_agency_box_nonce'] = 'token-nsw_agency_box';
		$_POST['nsw_agency_box']       = self::FOOD;

		( $this->theme_callback( 'save_post' ) )( 101, new \WP_Post( array( 'ID' => 101, 'post_type' => 'post' ) ) );

		$this->assertSame( array( array( 'set', 101, '_nsw_news_agency', self::CUSTOMS ) ), $this->writes );
	}

	/** Source: TSD 2.3.3, "an Agency Officer without one edits nothing". */
	#[TestDox('An item saved by an Agency Officer without an assigned agency is not tagged')]
	public function test_officer_without_agency_does_not_tag(): void {
		$this->sign_in( 'agency_officer', '' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->record_item_writes();

		( $this->theme_callback( 'save_post' ) )( 103, new \WP_Post( array( 'ID' => 103, 'post_type' => 'post' ) ) );

		$this->assertSame( array(), $this->writes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An administrator can set the agency of an item in the Agency box')]
	public function test_admin_sets_item_agency(): void {
		$this->sign_in( 'administrator' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_forms();
		$this->record_item_writes();
		$_POST['nsw_agency_box_nonce'] = 'token-nsw_agency_box';
		$_POST['nsw_agency_box']       = self::FOOD;

		( $this->theme_callback( 'save_post' ) )( 201, new \WP_Post( array( 'ID' => 201, 'post_type' => 'nsw_service' ) ) );

		$this->assertSame( array( array( 'set', 201, '_nsw_service_agency', self::FOOD ) ), $this->writes );
	}

	/** Source: TSD 2.3.3, "_nsw_service_agency, _nsw_news_agency ... Optional". */
	#[TestDox('An administrator can clear the agency of an item by choosing none')]
	public function test_admin_clears_item_agency(): void {
		$this->sign_in( 'administrator' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_forms();
		$this->record_item_writes();
		$_POST['nsw_agency_box_nonce'] = 'token-nsw_agency_box';
		$_POST['nsw_agency_box']       = '';

		( $this->theme_callback( 'save_post' ) )( 101, new \WP_Post( array( 'ID' => 101, 'post_type' => 'post' ) ) );

		$this->assertSame( array( array( 'delete', 101, '_nsw_news_agency' ) ), $this->writes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Saving an item without the Agency box leaves its agency unchanged')]
	public function test_save_without_box_keeps_agency(): void {
		$this->sign_in( 'administrator' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_forms();
		$this->record_item_writes();
		unset( $_POST['nsw_agency_box_nonce'], $_POST['nsw_agency_box'] );

		( $this->theme_callback( 'save_post' ) )( 101, new \WP_Post( array( 'ID' => 101, 'post_type' => 'post' ) ) );

		$this->assertSame( array(), $this->writes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An agency choice sent with a wrong or expired security token is ignored')]
	public function test_item_agency_with_wrong_token_ignored(): void {
		$this->sign_in( 'administrator' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_forms();
		$this->record_item_writes();
		$_POST['nsw_agency_box_nonce'] = 'token-nsw_user_agency'; // A token of another form.
		$_POST['nsw_agency_box']       = self::FOOD;

		( $this->theme_callback( 'save_post' ) )( 101, new \WP_Post( array( 'ID' => 101, 'post_type' => 'post' ) ) );

		$this->assertSame( array(), $this->writes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Saving content that does not carry an agency never gives it one')]
	public function test_other_content_never_tagged(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_forms();
		$this->record_item_writes();
		$_POST['nsw_agency_box_nonce'] = 'token-nsw_agency_box';
		$_POST['nsw_agency_box']       = self::FOOD;

		( $this->theme_callback( 'save_post' ) )( 301, new \WP_Post( array( 'ID' => 301, 'post_type' => 'page' ) ) );
		$this->sign_in( 'administrator' );
		( $this->theme_callback( 'save_post' ) )( 301, new \WP_Post( array( 'ID' => 301, 'post_type' => 'page' ) ) );

		$this->assertSame( array(), $this->writes );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Saving a revision copy of an item does not change any agency')]
	public function test_revision_not_tagged(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'wp_is_post_revision' )->alias( static fn( $id ) => 501 === (int) $id ? 101 : false );
		$this->record_item_writes();

		( $this->theme_callback( 'save_post' ) )( 501, new \WP_Post( array( 'ID' => 501, 'post_type' => 'post' ) ) );

		$this->assertSame( array(), $this->writes );
	}

	/**
	 * Simulated items whose agency can change during the test: id => [kind, status,
	 * agency]. Saved agency values are written back, so a later check sees them.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string}> $items
	 */
	private function given_changing_items( array $items ): void {
		$this->store = $items;
		$keys        = self::META_KEYS;
		Functions\when( 'get_post_type' )->alias( fn( $id ) => $this->store[ (int) $id ][0] ?? false );
		Functions\when( 'get_post_status' )->alias( fn( $id ) => $this->store[ (int) $id ][1] ?? false );
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key = '', $single = false ) use ( $keys ) {
				$item = $this->store[ (int) $id ] ?? null;
				return ( null !== $item && ( $keys[ $item[0] ] ?? null ) === $key ) ? $item[2] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) use ( $keys ) {
				if ( isset( $this->store[ (int) $id ] ) && $keys[ $this->store[ (int) $id ][0] ] === $key ) {
					$this->store[ (int) $id ][2] = (string) $value;
				}
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) use ( $keys ) {
				if ( isset( $this->store[ (int) $id ] ) && $keys[ $this->store[ (int) $id ][0] ] === $key ) {
					$this->store[ (int) $id ][2] = '';
				}
				return true;
			}
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('An automatic background save does not apply an agency sent in the Agency box')]
	public function test_autosave_ignores_agency_box(): void {
		define( 'DOING_AUTOSAVE', true );
		$this->sign_in( 'administrator' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_forms();
		$this->given_changing_items( array( 101 => array( 'post', 'publish', self::CUSTOMS ) ) );
		$_POST['nsw_agency_box_nonce'] = 'token-nsw_agency_box';
		$_POST['nsw_agency_box']       = self::FOOD;

		( $this->theme_callback( 'save_post' ) )( 101, new \WP_Post( array( 'ID' => 101, 'post_type' => 'post' ) ) );

		$this->assertSame( self::CUSTOMS, $this->store[101][2] );
	}

	/**
	 * Source: TSD 2.5.2, "Their items take the profile's agency"; TSD 2.2.2, "Agency
	 * Officers create and edit the news items and service guides of their own agency".
	 * WordPress stores a new item as a placeholder when the editor opens (an ordinary
	 * save, in both editors), and the first automatic save turns it into a draft.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A new item takes the Agency Officer\'s agency as soon as it is opened and keeps it through automatic background saves, so the officer can go on editing it')]
	public function test_new_item_keeps_officer_agency_through_autosave(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->given_changing_items(
			array(
				104 => array( 'post', 'auto-draft', '' ),
				204 => array( 'nsw_service', 'auto-draft', '' ),
			)
		);
		$save = $this->theme_callback( 'save_post' );

		// Opening the editor for a new news item and a new service guide stores the placeholders.
		$save( 104, new \WP_Post( array( 'ID' => 104, 'post_type' => 'post', 'post_status' => 'auto-draft' ) ) );
		$save( 204, new \WP_Post( array( 'ID' => 204, 'post_type' => 'nsw_service', 'post_status' => 'auto-draft' ) ) );

		// The first automatic save turns each placeholder into a draft.
		define( 'DOING_AUTOSAVE', true );
		$this->store[104][1] = 'draft';
		$this->store[204][1] = 'draft';
		$save( 104, new \WP_Post( array( 'ID' => 104, 'post_type' => 'post', 'post_status' => 'draft' ) ) );
		$save( 204, new \WP_Post( array( 'ID' => 204, 'post_type' => 'nsw_service', 'post_status' => 'draft' ) ) );

		$this->assertSame( self::CUSTOMS, $this->store[104][2] );
		$this->assertSame( self::CUSTOMS, $this->store[204][2] );
		$this->assertSame( array( 'edit_posts' ), $this->check( 'edit_post', 104 ) );
		$this->assertSame( array( 'edit_posts' ), $this->check( 'edit_post', 204 ) );
	}

	/* -------------------------------------------- the lists in wp-admin */

	/** Source: TSD 2.2.1, "Agency Officers see and change only their agency's items". */
	#[TestDox('An Agency Officer sees only the news items of their own agency in the news list')]
	public function test_officer_news_list_limited(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = 'edit.php';
		$query              = $this->list_query();

		( $this->theme_callback( 'pre_get_posts' ) )( $query );

		$this->assertContains(
			array( 'key' => '_nsw_news_agency', 'value' => self::CUSTOMS, 'compare' => '=' ),
			$query->vars['meta_query'] ?? array()
		);
	}

	/** Source: TSD 2.2.1, "Agency Officers see and change only their agency's items". */
	#[TestDox('An Agency Officer sees only the service guides of their own agency in the service guide list')]
	public function test_officer_service_list_limited(): void {
		$this->sign_in( 'agency_officer', self::FOOD );
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = 'edit.php';
		$query              = $this->list_query( array( 'post_type' => 'nsw_service' ) );

		( $this->theme_callback( 'pre_get_posts' ) )( $query );

		$this->assertContains(
			array( 'key' => '_nsw_service_agency', 'value' => self::FOOD, 'compare' => '=' ),
			$query->vars['meta_query'] ?? array()
		);
	}

	/** Source: TSD 2.5.2, "Agency Officers see and change only their agency's items ... (none without an assigned agency)". */
	#[TestDox('An Agency Officer without an assigned agency sees no items in the lists, not even untagged ones')]
	public function test_officer_without_agency_sees_nothing(): void {
		$this->sign_in( 'agency_officer', '' );
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = 'edit.php';
		$query              = $this->list_query( array( 'post_type' => 'post' ) );

		( $this->theme_callback( 'pre_get_posts' ) )( $query );

		$clauses = array_values(
			array_filter(
				(array) ( $query->vars['meta_query'] ?? array() ),
				static fn( $clause ) => is_array( $clause ) && '_nsw_news_agency' === ( $clause['key'] ?? null )
			)
		);
		$this->assertCount( 1, $clauses );
		$this->assertSame( '=', $clauses[0]['compare'] );
		// The value matches no agency and no untagged item.
		$this->assertNotSame( '', $clauses[0]['value'] );
		$this->assertArrayNotHasKey( $clauses[0]['value'], self::AGENCIES );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The agency limit is added to any other filter already set on the list')]
	public function test_existing_list_filters_kept(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = 'edit.php';
		$existing           = array( 'key' => '_nsw_theme_service_type', 'value' => 'import', 'compare' => 'LIKE' );
		$query              = $this->list_query( array( 'post_type' => 'nsw_service', 'meta_query' => array( $existing ) ) );

		( $this->theme_callback( 'pre_get_posts' ) )( $query );

		$this->assertContains( $existing, $query->vars['meta_query'] );
		$this->assertContains(
			array( 'key' => '_nsw_service_agency', 'value' => self::CUSTOMS, 'compare' => '=' ),
			$query->vars['meta_query']
		);
	}

	/** Source: TSD 2.5.2, Administrator "no agency limit". */
	#[TestDox('An administrator sees the items of every agency in the lists')]
	public function test_admin_list_not_limited(): void {
		$this->sign_in( 'administrator' );
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = 'edit.php';
		$query              = $this->list_query( array( 'post_type' => 'post' ) );

		( $this->theme_callback( 'pre_get_posts' ) )( $query );

		$this->assertSame( array( 'post_type' => 'post' ), $query->vars );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The public website shows the news items of every agency, also to a signed-in Agency Officer')]
	public function test_public_lists_not_limited(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'is_admin' )->justReturn( false );
		$GLOBALS['pagenow'] = 'index.php';
		$public             = $this->list_query( array( 'post_type' => 'post' ) );

		( $this->theme_callback( 'pre_get_posts' ) )( $public );

		$this->assertSame( array( 'post_type' => 'post' ), $public->vars );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The media library is not limited by agency')]
	public function test_media_library_not_limited(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = 'upload.php';
		$media              = $this->list_query( array( 'post_type' => 'attachment' ) );

		( $this->theme_callback( 'pre_get_posts' ) )( $media );

		$this->assertSame( array( 'post_type' => 'attachment' ), $media->vars );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Lists of content that does not carry an agency are not limited')]
	public function test_other_content_lists_not_limited(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = 'edit.php';
		$query              = $this->list_query( array( 'post_type' => 'page' ) );

		( $this->theme_callback( 'pre_get_posts' ) )( $query );

		$this->assertSame( array( 'post_type' => 'page' ), $query->vars );
	}

	/* ------------------------------------ editing another agency's items */

	/** Source: TSD 2.2.2, "Agency Officers create and edit the news items and service guides of their own agency". */
	#[TestDox('An Agency Officer can edit, delete, publish and revise their own agency\'s items, within the rights of their role')]
	public function test_officer_keeps_rights_on_own_items(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();

		foreach ( array( 'edit_post', 'delete_post', 'publish_post', 'copy_post' ) as $cap ) {
			$this->assertSame( array( 'edit_published_posts' ), $this->check( $cap, 101, 7, array( 'edit_published_posts' ) ), "$cap on own news item" );
			$this->assertSame( array( 'edit_nsw_services' ), $this->check( $cap, 201, 7, array( 'edit_nsw_services' ) ), "$cap on own service guide" );
		}
	}

	/** Source: TSD 2.5.2, "Agency Officers see and change only their agency's items, also by direct URL". */
	#[TestDox('An Agency Officer cannot edit, delete, publish or revise another agency\'s news item, even by direct link')]
	public function test_officer_blocked_on_other_agency_news(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();

		foreach ( array( 'edit_post', 'delete_post', 'publish_post', 'copy_post' ) as $cap ) {
			$this->assertSame( array( 'do_not_allow' ), $this->check( $cap, 102 ), $cap );
		}
	}

	/** Source: TSD 2.5.2, "Agency Officers see and change only their agency's items, also by direct URL". */
	#[TestDox('An Agency Officer cannot edit, delete, publish or revise another agency\'s service guide')]
	public function test_officer_blocked_on_other_agency_service(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();

		foreach ( array( 'edit_post', 'delete_post', 'publish_post', 'copy_post' ) as $cap ) {
			$this->assertSame( array( 'do_not_allow' ), $this->check( $cap, 202 ), $cap );
		}
	}

	/** Source: TSD 2.5.2, "Agency Officers see and change only their agency's items". */
	#[TestDox('An Agency Officer cannot change an item that has no agency')]
	public function test_officer_blocked_on_untagged_item(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();

		$this->assertSame( array( 'do_not_allow' ), $this->check( 'edit_post', 103 ) );
	}

	/** Source: TSD 2.3.3, "an Agency Officer without one edits nothing". */
	#[TestDox('An Agency Officer without an assigned agency cannot change any item')]
	public function test_officer_without_agency_changes_nothing(): void {
		$this->sign_in( 'agency_officer', '' );
		$this->given_items();

		foreach ( array( 101, 102, 103, 201, 202 ) as $item ) {
			$this->assertSame( array( 'do_not_allow' ), $this->check( 'edit_post', $item ), "item $item" );
		}
	}

	/** Source: TSD 2.2.2, "Agency Officers create and edit the news items and service guides of their own agency". */
	#[TestDox('An Agency Officer can start a new item before it has an agency')]
	public function test_officer_can_start_new_item(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();

		$this->assertSame( array( 'edit_posts' ), $this->check( 'edit_post', 104 ) );
	}

	/** Source: TSD 2.3.3, "an Agency Officer without one edits nothing" (TSD 2.5.2: "none without an assigned agency"). */
	#[TestDox('An Agency Officer without an assigned agency cannot write a new item either')]
	public function test_officer_without_agency_cannot_start_new_item(): void {
		$this->sign_in( 'agency_officer', '' );
		$this->given_items();

		$this->assertSame(
			array( 'do_not_allow' ),
			$this->check( 'edit_post', 104 ),
			'An Agency Officer without an agency can write and submit a new item.'
		);
	}

	/**
	 * Source: TSD 2.3.3, "an Agency Officer without one edits nothing" (TSD 2.5.2: "none
	 * without an assigned agency"). WordPress checks this right before any new item of
	 * the kind is written: in wp-admin, through the REST API and through the content API.
	 */
	#[TestDox('An Agency Officer without an assigned agency has no right to create news items or service guides')]
	public function test_officer_without_agency_has_no_create_right(): void {
		$this->sign_in( 'agency_officer', '' );
		$callback = $this->theme_callback( 'map_meta_cap' );

		$this->assertSame( array( 'do_not_allow' ), $callback( array( 'edit_posts' ), 'edit_posts', 7, array() ), 'news items' );
		$this->assertSame( array( 'do_not_allow' ), $callback( array( 'edit_nsw_services' ), 'edit_nsw_services', 7, array() ), 'service guides' );
		$this->assertSame( array( 'upload_files' ), $callback( array( 'upload_files' ), 'upload_files', 7, array() ), 'other rights' );
	}

	/** Source: TSD 2.2.2, "Agency Officers create and edit the news items and service guides of their own agency"; TSD 2.5.2, Administrator "no agency limit". */
	#[TestDox('An Agency Officer with an assigned agency, and an administrator without one, keep the right to create news items and service guides')]
	public function test_create_right_kept_with_agency_and_for_administrator(): void {
		foreach ( array( array( 'agency_officer', self::CUSTOMS ), array( 'administrator', '' ) ) as [ $role, $agency ] ) {
			$this->sign_in( $role, $agency );
			$callback = $this->theme_callback( 'map_meta_cap' );

			$this->assertSame( array( 'edit_posts' ), $callback( array( 'edit_posts' ), 'edit_posts', 7, array() ), "$role: news items" );
			$this->assertSame( array( 'edit_nsw_services' ), $callback( array( 'edit_nsw_services' ), 'edit_nsw_services', 7, array() ), "$role: service guides" );
		}
	}

	/** Source: TSD 2.5.2, Administrator "no agency limit". */
	#[TestDox('An administrator can change the items of any agency')]
	public function test_admin_not_limited_by_agency(): void {
		$this->sign_in( 'administrator', '' );
		$this->given_items();

		foreach ( array( 101, 102, 103, 202 ) as $item ) {
			$this->assertSame( array( 'edit_others_posts' ), $this->check( 'edit_post', $item, 7, array( 'edit_others_posts' ) ), "item $item" );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Only editing, deleting, publishing and revising an item are checked against the agency')]
	public function test_other_rights_not_checked(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();

		$this->assertSame( array( 'edit_posts' ), $this->check( 'edit_posts', 102, 7, array( 'edit_posts' ) ) );
		$this->assertSame( array( 'read' ), $this->check( 'read_post', 102, 7, array( 'read' ) ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Rights of an unknown user, rights without a particular item and rights on other content are left to WordPress')]
	public function test_check_left_to_wordpress(): void {
		$this->sign_in( 'agency_officer', self::CUSTOMS );
		$this->given_items();
		$callback = $this->theme_callback( 'map_meta_cap' );

		$this->assertSame( array( 'edit_posts' ), $callback( array( 'edit_posts' ), 'edit_post', 999, array( 102 ) ), 'unknown user' );
		$this->assertSame( array( 'edit_posts' ), $callback( array( 'edit_posts' ), 'edit_post', 7, array() ), 'no item' );
		$this->assertSame( array( 'edit_pages' ), $callback( array( 'edit_pages' ), 'edit_post', 7, array( 301 ) ), 'a page' );
		$this->assertSame( array( 'edit_posts' ), $callback( array( 'edit_posts' ), 'edit_post', 7, array( 999 ) ), 'missing item' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The agency check runs after the revisions plugin has set the rights, so its refusal stands')]
	public function test_check_runs_after_revisions_plugin(): void {
		$registrations = $this->theme_registrations( 'map_meta_cap' );
		$this->assertCount( 1, $registrations );
		$this->assertGreaterThan( 5, $registrations[0]['priority'] );
		$this->assertSame( 4, $registrations[0]['accepted_args'] );
	}

	/* ----------------------------------------------- the approval warning */

	/** Source: TSD 2.5.2, "If the role gains one of them, wp-admin warns administrators." */
	#[TestDox('Administrators are warned, with the rights named, when the Agency Officer role could skip approval')]
	public function test_warning_names_rights(): void {
		Functions\when( 'nsw_agency_officer_role' )->justReturn( 'agency_officer' );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );
		$role = $this->officer_role_with( array( 'edit_posts', 'publish_posts', 'edit_published_nsw_services' ) );
		Functions\when( 'get_role' )->alias( static fn( $name ) => 'agency_officer' === $name ? $role : null );

		$html = $this->approval_warning();

		$this->assertStringContainsString( 'class="notice notice-warning"', $html );
		$this->assertStringContainsString( 'bypassing admin approval', $html );
		$this->assertStringContainsString( '<code>publish_posts</code>, <code>edit_published_nsw_services</code>', $html );
		$this->assertStringNotContainsString( '<code>edit_posts</code>', $html );
		$this->assertStringContainsString( 'Members → Roles', $html );
	}

	/** Source: TSD 2.5.2, "Without the six rights ... If the role gains one of them, wp-admin warns administrators." */
	#[TestDox('Each of the six rights that skip approval triggers the warning on its own')]
	public function test_each_right_triggers_warning(): void {
		Functions\when( 'nsw_agency_officer_role' )->justReturn( 'agency_officer' );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );

		foreach ( self::APPROVAL_RIGHTS as $right ) {
			$role = $this->officer_role_with( array( $right ) );
			Functions\when( 'get_role' )->justReturn( $role );
			$html = $this->approval_warning();
			$this->assertStringContainsString( 'notice-warning', $html, $right );
			$this->assertStringContainsString( '<code>' . $right . '</code>', $html, $right );
		}
	}

	/** Source: TSD 2.5.2, "If the role gains one of them, wp-admin warns administrators." */
	#[TestDox('No approval warning is shown when the Agency Officer role cannot skip approval')]
	public function test_no_warning_when_role_is_safe(): void {
		Functions\when( 'nsw_agency_officer_role' )->justReturn( 'agency_officer' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( $this->officer_role_with( array( 'read', 'edit_posts', 'edit_nsw_services' ) ) );

		$this->assertSame( '', $this->approval_warning() );
	}

	/** Source: TSD 2.5.2, "wp-admin warns administrators". */
	#[TestDox('Only administrators see the approval warning')]
	public function test_warning_only_for_administrators(): void {
		Functions\when( 'nsw_agency_officer_role' )->justReturn( 'agency_officer' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_role' )->justReturn( $this->officer_role_with( self::APPROVAL_RIGHTS ) );

		$this->assertSame( '', $this->approval_warning() );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('No approval warning is shown when the Agency Officer role does not exist')]
	public function test_no_warning_without_role(): void {
		Functions\when( 'nsw_agency_officer_role' )->justReturn( 'agency_officer' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );

		$this->assertSame( '', $this->approval_warning() );
	}

	/* -------------------------------------------------- the Agency column */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The news list and the service guide list have an Agency column after their other columns')]
	public function test_agency_column_added(): void {
		foreach ( array_keys( self::META_KEYS ) as $type ) {
			$columns = ( $this->theme_callback( 'manage_' . $type . '_posts_columns' ) )( array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' ) );
			$this->assertSame( array( 'cb' => '', 'title' => 'Title', 'date' => 'Date', 'nsw_agency' => 'Agency' ), $columns, $type );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The Agency column shows the name of the item\'s agency')]
	public function test_agency_column_shows_name(): void {
		$this->given_items();
		Functions\when( 'nsw_agency_choices' )->justReturn( self::AGENCIES );

		$this->assertSame( 'Example Customs Office', $this->column( 'post', 'nsw_agency', 101 ) );
		$this->assertSame( 'Example Food Safety Agency', $this->column( 'nsw_service', 'nsw_agency', 202 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An item without an agency shows a dash in the Agency column')]
	public function test_agency_column_dash_without_agency(): void {
		$this->given_items();
		Functions\when( 'nsw_agency_choices' )->justReturn( self::AGENCIES );

		$this->assertSame( "\u{2014}", $this->column( 'post', 'nsw_agency', 103 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An agency that is no longer in the agency list is shown by its id')]
	public function test_agency_column_unknown_agency(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'retired-agency' );
		Functions\when( 'nsw_agency_choices' )->justReturn( self::AGENCIES );

		$this->assertSame( 'retired-agency', $this->column( 'nsw_service', 'nsw_agency', 203 ) );
	}

	/** Source: TSD 2.5.2, "The theme sanitises input and escapes output." */
	#[TestDox('Special characters in an agency name are shown as text, not run as page code')]
	public function test_agency_column_escapes_name(): void {
		$this->given_items();
		Functions\when( 'nsw_agency_choices' )->justReturn( array( self::CUSTOMS => 'Example <script>x</script> & Co' ) );

		$this->assertSame( 'Example &lt;script&gt;x&lt;/script&gt; &amp; Co', $this->column( 'post', 'nsw_agency', 101 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The other columns of the lists are left alone')]
	public function test_other_columns_left_alone(): void {
		Functions\expect( 'get_post_meta' )->never();

		$this->assertSame( '', $this->column( 'post', 'title', 101 ) );
		$this->assertSame( '', $this->column( 'nsw_service', 'date', 201 ) );
	}
}
