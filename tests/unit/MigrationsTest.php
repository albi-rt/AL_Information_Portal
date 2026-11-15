<?php
/**
 * Unit tests for inc/migrations.php: the one-off data updates that a new release makes
 * to the existing content, and the update that creates the Services page in Albanian
 * and English.
 *
 * Each update runs once, on the first administration page load by an administrator
 * after a release, under a short lock. A completed update is recorded so it never runs
 * again; a failed one is logged and tried again on the next load. WordPress, its
 * database and the language plugin (Polylang) are never used: their functions are
 * simulated in each test.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.2.1 (Data migrations component), 2.3.2 (run once, under a 2-minute lock,
 * safe to run twice), 2.3.3 (the stored record and the lock), 2.6.1 (run on the next
 * administrator load) and 2.6.2 (failed data migrations are logged), and from the
 * Software Development Package (SDP), section 5.4, step 9 (the Services page in each
 * language). Where today's code differs from the TSD, the test expects the TSD and is
 * in the group "tsd-difference".
 *
 * Tests that run updates need the WordPress time constant MINUTE_IN_SECONDS, so they
 * run in a separate PHP process where it can be defined.
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class MigrationsTest extends TestCase {

	private const SERVICES_UPDATE = 'create-services-page-2026-08';

	/** @var array<string, mixed> simulated stored settings */
	private array $options = array();
	/** @var array<string, mixed> autoload flag passed with each saved setting */
	private array $autoload = array();
	/** @var array<string, array{value: mixed, expiration: int}> simulated transients */
	private array $transients = array();
	/** @var array<int, string> what happened, in order */
	private array $events = array();
	/** @var array<int, array{args: array, wp_error: bool}> pages created */
	private array $inserted = array();
	/** @var array<int, array{0: int, 1: string, 2: mixed}> page settings changed */
	private array $meta_updates = array();
	/** @var array<int, string> language recorded per page */
	private array $languages = array();
	/** @var array<int, array<string, int>> translation links saved */
	private array $linked = array();

	/** The update runner that inc/migrations.php registered on the administration start. */
	private function runner(): callable {
		return nsw_test_hook_callback(
			'admin_init',
			0,
			static fn( array $reg ): bool => $reg['callback'] instanceof \Closure
				&& str_ends_with( (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName(), '/inc/migrations.php' )
		);
	}

	private function define_time_constants(): void {
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
	}

	/** Simulates the settings and transients tables. */
	private function simulate_database(): void {
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				$this->events[]          = 'record';
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( $name ) => $this->transients[ $name ]['value'] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $expiration = 0 ) {
				$this->transients[ $name ] = array( 'value' => $value, 'expiration' => $expiration );
				$this->events[]            = "lock $name $expiration";
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				$this->events[] = "unlock $name";
				return true;
			}
		);
	}

	private function signed_in_as_administrator( bool $administrator = true ): void {
		Functions\when( 'current_user_can' )->alias( static fn( $capability ) => $administrator && 'manage_options' === $capability );
	}

	/** Replaces the release's list of updates. */
	private function with_updates( array $updates ): void {
		Functions\when( 'nsw_theme_migrations' )->justReturn( $updates );
	}

	/** An update that only notes that it ran. */
	private function update_named( string $slug ): \Closure {
		return function () use ( $slug ) {
			$this->events[] = "run $slug";
		};
	}

	/**
	 * Simulates the site's pages for the Services page update.
	 *
	 * @param array<string, \WP_Post> $existing       page address => page that already exists
	 * @param array<int, mixed>       $insert_results result of each page creation, in order
	 * @param array<int, string>      $templates      page number => page template already set
	 */
	private function simulate_pages( array $existing, array $insert_results, array $templates = array() ): void {
		// A page that was created exists at its address from then on.
		Functions\when( 'get_page_by_path' )->alias(
			function ( $slug ) use ( &$existing ) {
				return $existing[ $slug ] ?? null;
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			function ( $args, $wp_error = false ) use ( &$insert_results, &$existing ) {
				$this->inserted[] = array( 'args' => $args, 'wp_error' => $wp_error );
				$result           = array_shift( $insert_results );
				if ( is_int( $result ) && $result > 0 ) {
					$existing[ $args['post_name'] ] = new \WP_Post( array( 'ID' => $result, 'post_name' => $args['post_name'], 'post_type' => 'page' ) );
				}
				return $result;
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key = '', $single = false ) => '_wp_page_template' === $key ? ( $templates[ $id ] ?? '' ) : ''
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta_updates[] = array( $id, $key, $value );
				return true;
			}
		);
	}

	/** Simulates the language plugin's functions for page language and translations. */
	private function simulate_language_plugin(): void {
		Functions\when( 'pll_set_post_language' )->alias(
			function ( $id, $lang ) {
				$this->languages[ $id ] = $lang;
			}
		);
		Functions\when( 'pll_save_post_translations' )->alias(
			function ( $ids ) {
				$this->linked[] = $ids;
				return $ids;
			}
		);
	}

	/** The fields sent to create the page at the given position (0 = first page created). */
	private function created( int $index ): array {
		return $this->inserted[ $index ]['args'];
	}

	/* ---------------------------------------------------------- list of updates */

	/** Source: SDP 5.4, step 9, data migrations create the "Services page in each language". */
	#[TestDox('This release\'s data updates include creating the Services page')]
	public function test_release_updates(): void {
		$this->assertSame( array( self::SERVICES_UPDATE => 'nsw_theme_migrate_create_services_page' ), nsw_theme_migrations() );
		$this->assertTrue( is_callable( nsw_theme_migrations()[ self::SERVICES_UPDATE ] ) );
	}

	/* ------------------------------------------------------- Services page update */

	/** Source: SDP 5.4, step 9, "Services page in each language (/sherbime/, /en/services/)"; TSD 2.2.1, "versions linked as translations". */
	#[TestDox('The Services page is created in Albanian at "sherbime" and in English at "services", and the two are linked as translations')]
	public function test_services_pages_created(): void {
		$this->simulate_pages( array(), array( 101, 102 ) );
		$this->simulate_language_plugin();

		nsw_theme_migrate_create_services_page();

		$this->assertCount( 2, $this->inserted );
		$this->assertSame( 'sherbime', $this->created( 0 )['post_name'] );
		$this->assertSame( 'Shërbimet', $this->created( 0 )['post_title'] );
		$this->assertSame( 'services', $this->created( 1 )['post_name'] );
		$this->assertSame( 'Services', $this->created( 1 )['post_title'] );
		$this->assertSame( array( 101 => 'sq', 102 => 'en' ), $this->languages );
		$this->assertSame( array( array( 'sq' => 101, 'en' => 102 ) ), $this->linked );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Each new Services page is a published page that shows the list of services, with no second page hero')]
	public function test_services_page_content(): void {
		$this->simulate_pages( array(), array( 101, 102 ) );
		$this->simulate_language_plugin();

		nsw_theme_migrate_create_services_page();

		foreach ( array( 0, 1 ) as $index ) {
			$page = $this->created( $index );
			$this->assertSame( 'page', $page['post_type'] );
			$this->assertSame( 'publish', $page['post_status'] );
			$this->assertSame( '<!-- wp:nsw-theme/services /-->', $page['post_content'] );
			$this->assertSame( 'page-plain', $page['page_template'] );
			$this->assertNotSame( '', $page['post_excerpt'] );
			$this->assertTrue( $this->inserted[ $index ]['wp_error'] );
		}
		$this->assertSame( 'Find the steps, documents and fees for any import, export or transit operation.', $this->created( 1 )['post_excerpt'] );
	}

	/** Source: TSD 2.3.2, "Migrations run once ... and are safe to run twice". */
	#[TestDox('Running the Services page update again reuses the existing pages and creates no copies')]
	public function test_services_update_safe_to_repeat(): void {
		$this->simulate_pages(
			array(
				'sherbime' => new \WP_Post( array( 'ID' => 7, 'post_name' => 'sherbime', 'post_type' => 'page' ) ),
				'services' => new \WP_Post( array( 'ID' => 8, 'post_name' => 'services', 'post_type' => 'page' ) ),
			),
			array(),
			array( 7 => 'page-plain', 8 => 'page-plain' )
		);
		$this->simulate_language_plugin();

		nsw_theme_migrate_create_services_page();

		$this->assertSame( array(), $this->inserted );
		$this->assertSame( array(), $this->meta_updates );
		$this->assertSame( array( 7 => 'sq', 8 => 'en' ), $this->languages );
		$this->assertSame( array( array( 'sq' => 7, 'en' => 8 ) ), $this->linked );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An existing Services page without the plain page layout is switched to it')]
	public function test_existing_page_gets_plain_layout(): void {
		$this->simulate_pages(
			array(
				'sherbime' => new \WP_Post( array( 'ID' => 7 ) ),
				'services' => new \WP_Post( array( 'ID' => 8 ) ),
			),
			array(),
			array( 7 => '', 8 => 'page-plain' )
		);
		$this->simulate_language_plugin();

		nsw_theme_migrate_create_services_page();

		$this->assertSame( array( array( 7, '_wp_page_template', 'page-plain' ) ), $this->meta_updates );
	}

	/**
	 * Source: theme behaviour, not specified in the TSD.
	 *
	 * Only the outcome on the pages is checked. Whether the update then carries on silently
	 * (today) or reports the failure (what the TSD asks, see the "tsd-difference" test below)
	 * is left open here, so an error raised by the update is allowed.
	 */
	#[TestDox('If the English Services page cannot be created, the Albanian page keeps its language and no translation link is made')]
	public function test_one_page_fails(): void {
		$this->simulate_pages( array(), array( 101, new \WP_Error( 'db_insert_error', 'Could not insert post into the database.' ) ) );
		$this->simulate_language_plugin();

		try {
			nsw_theme_migrate_create_services_page();
		} catch ( \Exception $e ) {
			// Reporting the failure with an error is allowed.
		}

		$this->assertSame( array( 'sherbime', 'services' ), array_column( array_column( $this->inserted, 'args' ), 'post_name' ) );
		$this->assertSame( array( 101 => 'sq' ), $this->languages );
		$this->assertSame( array(), $this->linked );
	}

	/** Source: theme behaviour, not specified in the TSD. Same open point as the test above. */
	#[TestDox('A page creation that gives back no page number is not treated as a created page')]
	public function test_page_without_number(): void {
		$this->simulate_pages( array(), array( 0, 102 ) );
		$this->simulate_language_plugin();

		try {
			nsw_theme_migrate_create_services_page();
		} catch ( \Exception $e ) {
			// Reporting the failure with an error is allowed.
		}

		$this->assertArrayNotHasKey( 0, $this->languages );
		$this->assertSame( array(), $this->linked );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the language plugin, the Services pages are still created')]
	public function test_services_pages_without_plugin(): void {
		$this->simulate_pages( array(), array( 101, 102 ) );

		nsw_theme_migrate_create_services_page();

		$this->assertSame( array( 'sherbime', 'services' ), array_column( array_column( $this->inserted, 'args' ), 'post_name' ) );
		$this->assertFalse( function_exists( 'pll_set_post_language' ) );
	}

	/* ------------------------------------------------------------- update runner */

	/** Source: TSD 2.2.1, data migrations on the "First administrator wp-admin page load after a release". */
	#[TestDox('A user who is not an administrator does not start the data updates')]
	public function test_non_administrator_runs_nothing(): void {
		$this->simulate_database();
		$this->signed_in_as_administrator( false );
		$this->with_updates( array( 'example-update' => $this->update_named( 'example-update' ) ) );

		$this->runner()();

		$this->assertSame( array(), $this->events );
		$this->assertSame( array(), $this->options );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When a release has no data updates, nothing is locked or recorded')]
	public function test_no_updates(): void {
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$this->with_updates( array() );

		$this->runner()();

		$this->assertSame( array(), $this->events );
		$this->assertSame( array(), $this->options );
	}

	/** Source: TSD 2.3.2, "Migrations run once, under a 2-minute lock". */
	#[TestDox('While another administrator\'s page load holds the lock, no data update runs')]
	public function test_lock_held_runs_nothing(): void {
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$this->with_updates( array( 'example-update' => $this->update_named( 'example-update' ) ) );
		$this->transients['nsw_theme_migrating'] = array( 'value' => 1, 'expiration' => 120 );

		$this->runner()();

		$this->assertSame( array(), $this->events );
		$this->assertSame( array(), $this->options );
		$this->assertArrayHasKey( 'nsw_theme_migrating', $this->transients );
	}

	/** Source: TSD 2.2.1, "Run-once data updates per release ... recorded in the database". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A new data update runs on the administrator\'s page load and is recorded next to the earlier ones')]
	public function test_pending_update_runs_and_is_recorded(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$this->options['nsw_theme_migrations_done'] = array( 'older-update' );
		$this->with_updates(
			array(
				'older-update' => $this->update_named( 'older-update' ),
				'new-update'   => $this->update_named( 'new-update' ),
			)
		);

		$this->runner()();

		$this->assertContains( 'run new-update', $this->events );
		$this->assertNotContains( 'run older-update', $this->events );
		$this->assertSame( array( 'older-update', 'new-update' ), $this->options['nsw_theme_migrations_done'] );
		$this->assertFalse( $this->autoload['nsw_theme_migrations_done'] );
	}

	/** Source: TSD 2.3.2, "Migrations run once". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A data update that has already run is not run again')]
	public function test_done_update_not_run_again(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$this->options['nsw_theme_migrations_done'] = array( 'example-update' );
		$this->with_updates( array( 'example-update' => $this->update_named( 'example-update' ) ) );

		$this->runner()();
		$this->runner()();

		$this->assertNotContains( 'run example-update', $this->events );
		$this->assertNotContains( 'record', $this->events );
		$this->assertSame( array( 'example-update' ), $this->options['nsw_theme_migrations_done'] );
	}

	/** Source: TSD 2.2.1, "2-minute lock"; TSD 2.3.3, transient nsw_theme_migrating, "2 min". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('While the data updates run, a two-minute lock is held, and it is released afterwards')]
	public function test_lock_held_for_two_minutes_and_released(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$this->with_updates( array( 'example-update' => $this->update_named( 'example-update' ) ) );

		$this->runner()();

		$this->assertSame(
			array( 'lock nsw_theme_migrating 120', 'run example-update', 'record', 'unlock nsw_theme_migrating' ),
			$this->events
		);
		$this->assertSame( array(), $this->transients );
	}

	/** Source: TSD 2.2.1, "failures logged and retried"; TSD 2.6.2, the theme logs "failed data migrations". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A data update that fails is logged and not recorded, and the next update still runs')]
	public function test_failure_logged_and_not_recorded(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$logged = array();
		Functions\when( 'error_log' )->alias(
			static function ( $message ) use ( &$logged ) {
				$logged[] = $message;
				return true;
			}
		);
		$this->with_updates(
			array(
				'broken-update' => static function () {
					throw new \RuntimeException( 'disk full' );
				},
				'faulty-update' => static function () {
					throw new \Error( 'unexpected value' );
				},
				'next-update'   => $this->update_named( 'next-update' ),
			)
		);

		$this->runner()();

		$this->assertSame(
			array(
				'[nsw-theme] migration "broken-update" failed: disk full',
				'[nsw-theme] migration "faulty-update" failed: unexpected value',
			),
			$logged
		);
		$this->assertContains( 'run next-update', $this->events );
		$this->assertSame( array( 'next-update' ), $this->options['nsw_theme_migrations_done'] );
		$this->assertSame( array(), $this->transients );
	}

	/** Source: TSD 2.2.1, "failures logged and retried". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A data update that failed is tried again on the next administrator page load')]
	public function test_failure_retried_on_next_load(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		Functions\when( 'error_log' )->justReturn( true );
		$attempts = 0;
		$this->with_updates(
			array(
				'flaky-update' => function () use ( &$attempts ) {
					if ( 1 === ++$attempts ) {
						throw new \RuntimeException( 'database busy' );
					}
					$this->events[] = 'run flaky-update';
				},
			)
		);

		$this->runner()();
		$this->assertArrayNotHasKey( 'nsw_theme_migrations_done', $this->options );

		$this->runner()();
		$this->assertSame( 2, $attempts );
		$this->assertSame( array( 'flaky-update' ), $this->options['nsw_theme_migrations_done'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A listed data update whose code is not available is not recorded as done')]
	public function test_unavailable_update_not_recorded(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$this->with_updates(
			array(
				'unavailable-update' => 'nsw_theme_migrate_example_that_does_not_exist',
				'next-update'        => $this->update_named( 'next-update' ),
			)
		);

		$this->runner()();

		$this->assertSame( array( 'next-update' ), $this->options['nsw_theme_migrations_done'] );
	}

	/** Source: TSD 2.6.1, "Data migrations run once on the next administrator wp-admin load"; SDP 5.4, step 9. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('On the first administrator page load after the release, the Services pages are created and the update is recorded')]
	public function test_release_update_runs_on_first_load(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		$this->simulate_pages( array(), array( 101, 102 ) );
		$this->simulate_language_plugin();

		$this->runner()();

		$this->assertSame( array( 'sherbime', 'services' ), array_column( array_column( $this->inserted, 'args' ), 'post_name' ) );
		$this->assertSame( array( array( 'sq' => 101, 'en' => 102 ) ), $this->linked );
		$this->assertSame( array( self::SERVICES_UPDATE ), $this->options['nsw_theme_migrations_done'] );
		$this->assertSame( array(), $this->transients );
	}

	/** Source: TSD 2.2.1, data migrations "failures logged and retried"; TSD 2.6.2, the theme logs "failed data migrations". */
	#[Group('tsd-difference')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('If a Services page cannot be created, the failure is logged and the page is created on the next administrator page load')]
	public function test_services_page_failure_logged_and_retried(): void {
		$this->define_time_constants();
		$this->simulate_database();
		$this->signed_in_as_administrator();
		// First load: the Albanian page is created, the English one fails. Second load: it works.
		$this->simulate_pages( array(), array( 101, new \WP_Error( 'db_insert_error', 'Could not insert post into the database.' ), 102 ) );
		$this->simulate_language_plugin();
		$logged = array();
		Functions\when( 'error_log' )->alias(
			static function ( $message ) use ( &$logged ) {
				$logged[] = $message;
				return true;
			}
		);

		$this->runner()();

		$this->assertNotContains(
			self::SERVICES_UPDATE,
			(array) ( $this->options['nsw_theme_migrations_done'] ?? array() ),
			'The update must stay pending so that it is tried again'
		);
		$this->assertNotEmpty( $logged, 'The failure must be logged' );

		$this->runner()();

		$this->assertSame( array( 101 => 'sq', 102 => 'en' ), $this->languages, 'The English page must be created on the next load' );
		$this->assertSame( array( 'sq' => 101, 'en' => 102 ), end( $this->linked ) );
		$this->assertSame( array( self::SERVICES_UPDATE ), $this->options['nsw_theme_migrations_done'] );
	}
}
