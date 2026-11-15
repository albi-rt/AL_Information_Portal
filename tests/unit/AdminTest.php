<?php
/**
 * Unit tests for inc/admin.php: changes to the WordPress administration screens.
 *
 * It registers the contact form recipient setting and prints a recipient field in
 * Settings, General; adds Date, Location and Type columns to the list of events (the
 * list can be sorted by event date) and File type and Size columns to the list of
 * documents; and has dashboard changes meant for Agency Officers.
 *
 * The recipient field printed here is a second field for the same setting: the contact
 * form file already offers one in the NSW Contact Form section. Only the stored setting
 * and the safe display of the value are checked here; the duplicate field is reported
 * as a suspected fault, not tested as correct. The Agency Officer dashboard changes never
 * run today (the check they depend on does not exist in the theme), so only the
 * administrator's dashboard is checked.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.3.3 (data dictionary) and 2.5.2 (escaping output).
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class AdminTest extends TestCase {

	/** Calls made to WordPress during a test, by function name. */
	private array $calls = array();

	/** True when a hook registration was made by the given theme file. */
	private static function from_file( array $reg, string $file ): bool {
		return $reg['callback'] instanceof \Closure
			&& str_ends_with( str_replace( '\\', '/', (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName() ), '/inc/' . $file );
	}

	/** The callback number $index that inc/admin.php registered on a hook. */
	private function admin_callback( string $hook, int $index = 0 ): callable {
		return nsw_test_hook_callback( $hook, $index, static fn( array $reg ): bool => self::from_file( $reg, 'admin.php' ) );
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

	/** Simulates the saved options: the site administrator's email and, if given, a saved recipient. */
	private function options( ?string $recipient ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $recipient ) {
				if ( 'admin_email' === $name ) {
					return 'admin@example.com';
				}
				if ( 'nsw_theme_contact_email' === $name && null !== $recipient ) {
					return $recipient;
				}
				return $default;
			}
		);
	}

	/** Runs the settings set-up of inc/admin.php and returns the HTML of the recipient field. */
	private function recipient_field_html( ?string $recipient ): string {
		$this->options( $recipient );
		$this->record( 'register_setting', 'add_settings_field' );
		$this->admin_callback( 'admin_init' )();

		ob_start();
		call_user_func( $this->calls['add_settings_field'][0][2] );
		return (string) ob_get_clean();
	}

	/** Simulates saved post meta: post id => meta key => value. */
	private function post_meta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key = '', $single = false ) => $meta[ $post_id ][ $key ] ?? ''
		);
	}

	/** Prints one cell of a list table and returns it. */
	private function cell( string $hook, string $column, int $post_id ): string {
		ob_start();
		$this->admin_callback( $hook )( $column, $post_id );
		return (string) ob_get_clean();
	}

	/** A list query in wp-admin, as WordPress passes it to the theme before it runs. */
	private function list_query( bool $main, $orderby ): object {
		return new class( $main, $orderby ) {
			public array $vars = array();
			public function __construct( private bool $main, $orderby ) {
				$this->vars['orderby'] = $orderby;
			}
			public function is_main_query(): bool {
				return $this->main;
			}
			public function get( $key ) {
				return $this->vars[ $key ] ?? '';
			}
			public function set( $key, $value ): void {
				$this->vars[ $key ] = $value;
			}
		};
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

	/* ------------------------------------------------- contact form recipient */

	/** Source: TSD 2.3.3, nsw_theme_contact_email, "Fallback recipient (default: site admin email)". */
	#[TestDox('The contact form recipient is kept as a cleaned email address and starts as the email of the site administrator')]
	public function test_recipient_setting_is_registered(): void {
		$this->options( null );
		$this->record( 'register_setting', 'add_settings_field' );

		$this->admin_callback( 'admin_init' )();

		$this->assertSame(
			array(
				array(
					'general',
					'nsw_theme_contact_email',
					array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => 'admin@example.com' ),
				),
			),
			$this->calls['register_setting']
		);
	}

	/** Source: TSD 2.5.2, "The theme sanitises input and escapes output". */
	#[TestDox('A saved recipient address with quotes or angle brackets is shown in the settings as plain text and cannot change the page')]
	public function test_recipient_field_escapes_the_value(): void {
		$html = $this->recipient_field_html( 'x"><script>alert(1)</script>@example.com' );

		$this->assertStringContainsString( 'value="x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;@example.com"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/* ------------------------------------------------------------ event list */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The list of events in the administration area shows Date, Location and Type columns right after the title')]
	public function test_event_columns_after_title(): void {
		$columns = $this->admin_callback( 'manage_nsw_event_posts_columns' )(
			array( 'cb' => '<input type="checkbox" />', 'title' => 'Title', 'author' => 'Author', 'date' => 'Date' )
		);

		$this->assertSame(
			array(
				'cb'                 => '<input type="checkbox" />',
				'title'              => 'Title',
				'nsw_event_date'     => 'Date',
				'nsw_event_location' => 'Location',
				'nsw_event_type'     => 'Type',
				'author'             => 'Author',
				'date'               => 'Date',
			),
			$columns
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A list of events without a title column gets no extra columns')]
	public function test_event_columns_need_a_title_column(): void {
		$columns = array( 'cb' => '<input type="checkbox" />', 'date' => 'Date' );
		$this->assertSame( $columns, $this->admin_callback( 'manage_nsw_event_posts_columns' )( $columns ) );
		$this->assertSame( array(), $this->admin_callback( 'manage_nsw_event_posts_columns' )( array() ) );
	}

	/** Source: TSD 2.3.3, event date (YYYY-MM-DD), location and type. */
	#[TestDox('Each event column shows the date, location or type saved for that event')]
	public function test_event_cells_show_saved_values(): void {
		$this->post_meta(
			array(
				7 => array(
					'_nsw_theme_event_date'     => '2026-12-01',
					'_nsw_theme_event_location' => 'Tirana',
					'_nsw_theme_event_type'     => 'workshop',
				),
			)
		);

		$this->assertSame( '2026-12-01', $this->cell( 'manage_nsw_event_posts_custom_column', 'nsw_event_date', 7 ) );
		$this->assertSame( 'Tirana', $this->cell( 'manage_nsw_event_posts_custom_column', 'nsw_event_location', 7 ) );
		$this->assertSame( 'workshop', $this->cell( 'manage_nsw_event_posts_custom_column', 'nsw_event_type', 7 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An event without a saved date, location or type shows empty cells')]
	public function test_event_cells_empty_without_values(): void {
		$this->post_meta( array() );

		foreach ( array( 'nsw_event_date', 'nsw_event_location', 'nsw_event_type' ) as $column ) {
			$this->assertSame( '', $this->cell( 'manage_nsw_event_posts_custom_column', $column, 8 ) );
		}
	}

	/** Source: TSD 2.5.2, "The theme sanitises input and escapes output". */
	#[TestDox('Event values with HTML in them are shown as plain text')]
	public function test_event_cells_escape_values(): void {
		$this->post_meta( array( 7 => array( '_nsw_theme_event_location' => '<b>Durrës</b> & "Port"' ) ) );

		$this->assertSame(
			'&lt;b&gt;Durrës&lt;/b&gt; &amp; &quot;Port&quot;',
			$this->cell( 'manage_nsw_event_posts_custom_column', 'nsw_event_location', 7 )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The theme writes nothing in the other columns of the list of events')]
	public function test_event_other_columns_untouched(): void {
		Functions\expect( 'get_post_meta' )->never();

		$this->assertSame( '', $this->cell( 'manage_nsw_event_posts_custom_column', 'author', 7 ) );
		$this->assertSame( '', $this->cell( 'manage_nsw_event_posts_custom_column', 'nsw_doc_type', 7 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The list of events can be sorted by event date, and the other sortable columns stay sortable')]
	public function test_event_date_column_is_sortable(): void {
		$this->assertSame(
			array( 'title' => 'title', 'date' => array( 'date', true ), 'nsw_event_date' => 'nsw_event_date' ),
			$this->admin_callback( 'manage_edit-nsw_event_sortable_columns' )( array( 'title' => 'title', 'date' => array( 'date', true ) ) )
		);
	}

	/** Source: TSD 2.3.3, event date stored as YYYY-MM-DD, so text order is date order. */
	#[TestDox('Sorting the list of events by event date orders the events by their saved date')]
	public function test_sorting_by_event_date(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$query = $this->list_query( true, 'nsw_event_date' );

		$this->admin_callback( 'pre_get_posts' )( $query );

		$this->assertSame( array( 'orderby' => 'meta_value', 'meta_key' => '_nsw_theme_event_date' ), $query->vars );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Sorting by another column, or not sorting, leaves the list order to WordPress')]
	public function test_other_sorting_unchanged(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		foreach ( array( 'title', '', 'date' ) as $orderby ) {
			$query = $this->list_query( true, $orderby );
			$this->admin_callback( 'pre_get_posts' )( $query );
			$this->assertSame( array( 'orderby' => $orderby ), $query->vars );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Lists on the public website and secondary lists in the administration area are never re-sorted by event date')]
	public function test_public_and_secondary_lists_unchanged(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$public = $this->list_query( true, 'nsw_event_date' );
		$this->admin_callback( 'pre_get_posts' )( $public );
		$this->assertSame( array( 'orderby' => 'nsw_event_date' ), $public->vars );

		Functions\when( 'is_admin' )->justReturn( true );
		$secondary = $this->list_query( false, 'nsw_event_date' );
		$this->admin_callback( 'pre_get_posts' )( $secondary );
		$this->assertSame( array( 'orderby' => 'nsw_event_date' ), $secondary->vars );
	}

	/* --------------------------------------------------------- document list */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The list of documents in the administration area shows File type and Size columns right after the title')]
	public function test_document_columns_after_title(): void {
		$columns = $this->admin_callback( 'manage_nsw_document_posts_columns' )(
			array( 'cb' => '<input type="checkbox" />', 'title' => 'Title', 'date' => 'Date' )
		);

		$this->assertSame(
			array(
				'cb'           => '<input type="checkbox" />',
				'title'        => 'Title',
				'nsw_doc_type' => 'File type',
				'nsw_doc_size' => 'Size',
				'date'         => 'Date',
			),
			$columns
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A list of documents without a title column gets no extra columns')]
	public function test_document_columns_need_a_title_column(): void {
		$columns = array( 'cb' => '<input type="checkbox" />', 'date' => 'Date' );
		$this->assertSame( $columns, $this->admin_callback( 'manage_nsw_document_posts_columns' )( $columns ) );
	}

	/** Source: TSD 2.3.3, document "type and size labels". */
	#[TestDox('Each document column shows the file type or size saved for that document')]
	public function test_document_cells_show_saved_values(): void {
		$this->post_meta( array( 9 => array( '_nsw_theme_doc_file_type' => 'PDF', '_nsw_theme_doc_size' => '1.2 MB' ) ) );

		$this->assertSame( 'PDF', $this->cell( 'manage_nsw_document_posts_custom_column', 'nsw_doc_type', 9 ) );
		$this->assertSame( '1.2 MB', $this->cell( 'manage_nsw_document_posts_custom_column', 'nsw_doc_size', 9 ) );
		$this->assertSame( '', $this->cell( 'manage_nsw_document_posts_custom_column', 'nsw_doc_size', 10 ) );
	}

	/** Source: TSD 2.5.2, "The theme sanitises input and escapes output". */
	#[TestDox('Document values with HTML in them are shown as plain text')]
	public function test_document_cells_escape_values(): void {
		$this->post_meta( array( 9 => array( '_nsw_theme_doc_file_type' => '<img src=x onerror=alert(1)>' ) ) );

		$this->assertSame( '&lt;img src=x onerror=alert(1)&gt;', $this->cell( 'manage_nsw_document_posts_custom_column', 'nsw_doc_type', 9 ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The theme writes nothing in the other columns of the list of documents')]
	public function test_document_other_columns_untouched(): void {
		Functions\expect( 'get_post_meta' )->never();

		$this->assertSame( '', $this->cell( 'manage_nsw_document_posts_custom_column', 'title', 9 ) );
		$this->assertSame( '', $this->cell( 'manage_nsw_document_posts_custom_column', 'nsw_event_date', 9 ) );
	}

	/* -------------------------------------------------------------- dashboard */

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The dashboard of an administrator keeps the standard WordPress panels and gets no extra panel')]
	public function test_administrator_dashboard_unchanged(): void {
		Functions\when( 'wp_get_current_user' )->justReturn(
			new \WP_User( array( 'ID' => 1, 'display_name' => 'Ana Admin', 'roles' => array( 'administrator' ) ) )
		);
		Functions\expect( 'remove_meta_box' )->never();
		Functions\expect( 'wp_add_dashboard_widget' )->never();

		$this->admin_callback( 'wp_dashboard_setup', 0 )();
		$this->admin_callback( 'wp_dashboard_setup', 1 )();

		$this->assertCount( 2, array_filter( nsw_test_hook_registrations( 'wp_dashboard_setup' ), static fn( array $reg ): bool => self::from_file( $reg, 'admin.php' ) ) );
	}

	/* ----------------------------------------------------------- direct access */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Opening the file with the administration changes directly, outside WordPress, runs nothing and shows nothing')]
	public function test_direct_access_does_nothing(): void {
		[ $code, $output ] = self::run_outside_wordpress( 'inc/admin.php' );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $output );
	}
}
