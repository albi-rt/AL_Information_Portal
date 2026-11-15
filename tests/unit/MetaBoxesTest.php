<?php
/**
 * Unit tests for inc/meta-boxes.php: the extra boxes on the wp-admin edit screens.
 *
 * The file adds the boxes where content users enter the details of events, service
 * guides, documents, agencies and partners (and the Description box of documents and
 * events), shows the saved values in them, and saves what is entered. A save is
 * refused without the box's security token, during an automatic draft save, and for a
 * user who may not edit the item.
 *
 * WordPress is not running. Its functions are replaced in each test: saved values go
 * to a small in-memory list, and the cleaning functions (removing slashes, tags and
 * line breaks) are replaced by short stand-ins that behave like WordPress for the
 * inputs used here.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.1 (main functions), 2.2.1 (components), 2.2.2 (workflow), 2.3.3 (data
 * dictionary) and 2.5.2 (access control), and from the Software Development Package
 * (SDP) 3.1. Where today's code differs from the TSD, the test expects the TSD and is
 * in the group "tsd-difference". Those tests look for the field on the whole edit
 * screen of the content type (every box this file adds there) and send the whole form
 * back, so they do not depend on which box the missing field is later put in.
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class MetaBoxesTest extends TestCase {

	/** The item being edited in every test. */
	private const POST_ID = 42;

	/** @var array<string, mixed> meta key => value saved by the code under test */
	private array $saved = array();

	/** @var array<int, string> meta keys removed by the code under test */
	private array $deleted = array();

	/** Whether the signed-in user may edit the item. */
	private bool $may_edit = true;

	/** @var array<string, mixed> */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->post_backup = $_POST;
		$_POST             = array();
		$this->saved       = array();
		$this->deleted     = array();
		$this->may_edit    = true;
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		parent::tearDown();
	}

	/* ------------------------------------------------------------------ helpers */

	/** An item of the given type, as WordPress passes it to the boxes and the save steps. */
	private function post( string $type, array $fields = array() ): \WP_Post {
		return new \WP_Post( array_merge( array( 'ID' => self::POST_ID, 'post_type' => $type ), $fields ) );
	}

	/**
	 * Replaces the WordPress functions a save step uses. The security token of a box is
	 * valid only for that box ("nonce-for-<action>"); the user may edit item 42 unless
	 * $this->may_edit is false; saved and removed values are recorded.
	 */
	private function stub_save_functions(): void {
		Functions\when( 'wp_unslash' )->alias(
			static fn( $value ) => is_array( $value ) ? array_map( 'stripslashes', $value ) : stripslashes( (string) $value )
		);
		// Like WordPress: tags removed, line breaks, tabs and repeated spaces made one space, trimmed.
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ) => trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) )
		);
		// Like WordPress: tags removed, line breaks kept, trimmed.
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn( $value ) => trim( strip_tags( (string) $value ) )
		);
		// Like WordPress: lower case, only a-z, 0-9, dash and underscore kept.
		Functions\when( 'sanitize_key' )->alias(
			static fn( $value ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn( $nonce, $action ) => ( 'nonce-for-' . $action ) === $nonce ? 1 : false
		);
		Functions\when( 'current_user_can' )->alias(
			fn( $capability, ...$args ) => $this->may_edit && 'edit_post' === $capability && self::POST_ID === ( $args[0] ?? null )
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->assertSame( self::POST_ID, $post_id );
				$this->saved[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				$this->assertSame( self::POST_ID, $post_id );
				$this->deleted[] = $key;
				return true;
			}
		);
	}

	/** The form data WordPress receives when a box is submitted with its valid security token. */
	private function submit( string $action, array $fields ): void {
		$_POST = array_merge( array( $action . '_nonce' => 'nonce-for-' . $action ), $fields );
	}

	/**
	 * Replaces the WordPress functions a box uses to show itself. Saved values come from
	 * $meta (meta key => value); a missing key reads as an empty string, as in WordPress.
	 */
	private function stub_render_functions( array $meta = array() ): void {
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) use ( $meta ) {
				$this->assertSame( self::POST_ID, $post_id );
				return $meta[ $key ] ?? '';
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '">';
			}
		);
		// Like WordPress: prints selected='selected' / checked='checked' when the two values match.
		foreach ( array( 'selected', 'checked' ) as $helper ) {
			Functions\when( $helper )->alias(
				static function ( $value, $current = true, $display = true ) use ( $helper ) {
					$result = ( (string) $value === (string) $current ) ? " $helper='$helper'" : '';
					if ( $display ) {
						echo $result;
					}
					return $result;
				}
			);
		}
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );
		Functions\when( 'wp_basename' )->alias( static fn( $path ) => basename( (string) $path ) );
	}

	/** Labels of the three operation types, as the theme's text catalogue gives them. */
	private function stub_operation_type_labels(): void {
		Functions\when( 'nsw_theme_t' )->alias(
			static fn( $key ) => array(
				'servicesPage.types.import'  => 'Import',
				'servicesPage.types.export'  => 'Export',
				'servicesPage.types.transit' => 'Transit',
			)[ $key ] ?? $key
		);
	}

	/** Captures what a box prints. */
	private function render( callable $box, \WP_Post $post ): string {
		ob_start();
		$box( $post );
		return (string) ob_get_clean();
	}

	/**
	 * The attributes of every input field in the HTML, entities decoded.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function inputs( string $html ): array {
		preg_match_all( '/<input\b([^>]*)>/i', $html, $tags );
		$inputs = array();
		foreach ( $tags[1] as $attributes ) {
			preg_match_all( '/([a-z_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attributes, $pairs, PREG_SET_ORDER );
			$row = array();
			foreach ( $pairs as $pair ) {
				$row[ strtolower( $pair[1] ) ] = html_entity_decode( $pair[3] ?? $pair[2], ENT_QUOTES, 'UTF-8' );
			}
			$inputs[] = $row;
		}
		return $inputs;
	}

	/** @return array<string, string> attributes of the input field with this name */
	private function input( string $html, string $name ): array {
		foreach ( $this->inputs( $html ) as $row ) {
			if ( ( $row['name'] ?? null ) === $name ) {
				return $row;
			}
		}
		$this->fail( "No input field named $name in the box." );
	}

	/**
	 * The name of the first input field that shows this value. The field type is not
	 * checked: the TSD gives the stored format (for example YYYY-MM-DD), not the kind of field.
	 */
	private function name_of_field_showing( string $html, string $value ): ?string {
		foreach ( $this->inputs( $html ) as $row ) {
			if ( ( $row['value'] ?? null ) === $value && isset( $row['name'] ) ) {
				return $row['name'];
			}
		}
		return null;
	}

	/** The text inside the textarea with this name, entities decoded. */
	private function textarea( string $html, string $name ): string {
		$this->assertMatchesRegularExpression( '/<textarea\b[^>]*name="' . preg_quote( $name, '/' ) . '"[^>]*>/', $html );
		preg_match( '/<textarea\b[^>]*name="' . preg_quote( $name, '/' ) . '"[^>]*>(.*?)<\/textarea>/s', $html, $m );
		return html_entity_decode( $m[1] ?? '', ENT_QUOTES, 'UTF-8' );
	}

	/** @return array<int, string> the values of the options in the select list with this name */
	private function option_values( string $html, string $name ): array {
		preg_match( '/<select\b[^>]*name="' . preg_quote( $name, '/' ) . '"[^>]*>(.*?)<\/select>/s', $html, $m );
		$this->assertNotEmpty( $m, "No select list named $name in the box." );
		preg_match_all( '/<option value="([^"]*)"/', $m[1], $values );
		return $values[1];
	}

	/** @return array<int, string> the values of the options marked as selected */
	private function selected_options( string $html ): array {
		preg_match_all( '/<option value="([^"]*)"\s*selected=\'selected\'/', $html, $values );
		return $values[1];
	}

	/** The add_meta_boxes steps registered by inc/meta-boxes.php (not by other theme files). */
	private function own_add_meta_boxes_steps(): array {
		return array_values(
			array_filter(
				nsw_test_hook_registrations( 'add_meta_boxes' ),
				static fn( $reg ) => $reg['callback'] instanceof \Closure
					&& str_ends_with( (string) ( new \ReflectionFunction( $reg['callback'] ) )->getFileName(), '/inc/meta-boxes.php' )
			)
		);
	}

	/**
	 * Runs the given add_meta_boxes steps and returns the boxes they add and remove.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<int, string>>}
	 */
	private function run_steps( array $steps ): array {
		$added   = array();
		$removed = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id, $title, $callback, $screen, $context = 'advanced', $priority = 'default' ) use ( &$added ) {
				$added[] = compact( 'id', 'title', 'callback', 'screen', 'context', 'priority' );
			}
		);
		Functions\when( 'remove_meta_box' )->alias(
			static function ( $id, $screen, $context ) use ( &$removed ) {
				$removed[] = array( $id, $screen, $context );
			}
		);
		foreach ( $steps as $reg ) {
			( $reg['callback'] )();
		}
		return array( $added, $removed );
	}

	/** The boxes added for one content type, keyed by box id. */
	private function boxes_for( string $type ): array {
		[ $added ] = $this->run_steps( $this->own_add_meta_boxes_steps() );
		$boxes     = array();
		foreach ( $added as $box ) {
			if ( $box['screen'] === $type ) {
				$boxes[ $box['id'] ] = $box;
			}
		}
		return $boxes;
	}

	/**
	 * Everything this file's boxes show on the edit screen of one content type, whichever
	 * box a field is in. The render stand-ins must be in place.
	 */
	private function render_edit_screen( string $type, \WP_Post $post ): string {
		$html = '';
		foreach ( $this->boxes_for( $type ) as $box ) {
			$html .= $this->render( $box['callback'], $post );
		}
		return $html;
	}

	/**
	 * The form data a content user sends from an edit screen: every input field with the
	 * value it shows (ticked boxes only), with $changes (field name => new value) on top.
	 */
	private function form_data( string $html, array $changes ): array {
		$data = array();
		foreach ( $this->inputs( $html ) as $row ) {
			if ( ! isset( $row['name'] ) ) {
				continue;
			}
			if ( in_array( strtolower( $row['type'] ?? 'text' ), array( 'checkbox', 'radio' ), true ) && ! isset( $row['checked'] ) ) {
				continue;
			}
			if ( str_ends_with( $row['name'], '[]' ) ) {
				$data[ substr( $row['name'], 0, -2 ) ][] = $row['value'] ?? '';
			} else {
				$data[ $row['name'] ] = $row['value'] ?? '';
			}
		}
		foreach ( $changes as $name => $value ) {
			if ( str_ends_with( $name, '[]' ) ) {
				$data[ substr( $name, 0, -2 ) ] = (array) $value;
			} else {
				$data[ $name ] = $value;
			}
		}
		return $data;
	}

	/** Runs every save step the theme registered for this content type, as WordPress does on save. */
	private function save_edit_screen( string $type, \WP_Post $post ): void {
		$regs = nsw_test_hook_registrations( 'save_post_' . $type );
		$this->assertNotEmpty( $regs, "No save step registered for $type." );
		foreach ( $regs as $reg ) {
			( $reg['callback'] )( ...array_slice( array( self::POST_ID, $post, true ), 0, $reg['accepted_args'] ) );
		}
	}

	/**
	 * One valid submission per content type: the save step, the box's security token
	 * action, one field and the value it is saved as.
	 */
	public static function content_types(): array {
		return array(
			'event'         => array( 'an event', 'nsw_theme_save_event_meta', 'nsw_theme_event_meta', 'nsw_event', array( 'nsw_theme_event_location' => 'Example Hall, Tirana' ), '_nsw_theme_event_location', 'Example Hall, Tirana' ),
			'service guide' => array( 'a service guide', 'nsw_theme_save_service_meta', 'nsw_theme_service_meta', 'nsw_service', array( 'nsw_theme_service_type' => array( 'export' ) ), '_nsw_theme_service_type', 'export' ),
			'document'      => array( 'a document', 'nsw_theme_save_document_meta', 'nsw_theme_document_meta', 'nsw_document', array( 'nsw_theme_doc_file_type' => 'PDF' ), '_nsw_theme_doc_file_type', 'PDF' ),
			'agency'        => array( 'an agency', 'nsw_theme_save_agency_meta', 'nsw_theme_agency_meta', 'nsw_agency', array( 'nsw_theme_agency_name' => 'Example Agency' ), '_nsw_theme_agency_name', 'Example Agency' ),
			'partner'       => array( 'a partner', 'nsw_theme_save_partner_meta', 'nsw_theme_partner_meta', 'nsw_partner', array( 'nsw_theme_partner_name' => 'Example Partner' ), '_nsw_theme_partner_name', 'Example Partner' ),
		);
	}

	/* ------------------------------------------------------- boxes on the screens */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Documents and events get a Description box in the main column, near the top')]
	public function test_description_box_on_documents_and_events(): void {
		foreach ( array( 'nsw_document', 'nsw_event' ) as $type ) {
			$box = $this->boxes_for( $type )['nsw_theme_description'] ?? null;
			$this->assertNotNull( $box, "No Description box on $type." );
			$this->assertSame( 'Description', $box['title'] );
			$this->assertSame( 'nsw_theme_render_description_meta_box', $box['callback'] );
			$this->assertSame( 'normal', $box['context'] );
			$this->assertSame( 'high', $box['priority'] );
		}
	}

	/** Source: TSD 2.3.3, event date, time, location, type. */
	#[TestDox('Events get an Event details box at the side of the edit screen')]
	public function test_event_details_box(): void {
		$box = $this->boxes_for( 'nsw_event' )['nsw_theme_event_details'] ?? null;
		$this->assertNotNull( $box );
		$this->assertSame( 'Event details', $box['title'] );
		$this->assertSame( 'nsw_theme_render_event_meta_box', $box['callback'] );
		$this->assertSame( 'side', $box['context'] );
	}

	/** Source: TSD 2.3.3, operation types: import, export, transit. */
	#[TestDox('Service guides get a Service details box at the side of the edit screen')]
	public function test_service_details_box(): void {
		$box = $this->boxes_for( 'nsw_service' )['nsw_theme_service_details'] ?? null;
		$this->assertNotNull( $box );
		$this->assertSame( 'Service details', $box['title'] );
		$this->assertSame( 'nsw_theme_render_service_meta_box', $box['callback'] );
		$this->assertSame( 'side', $box['context'] );
	}

	/** Source: TSD 2.3.3, document media item, type and size labels. */
	#[TestDox('Documents get a Document file box at the side of the edit screen')]
	public function test_document_file_box(): void {
		$box = $this->boxes_for( 'nsw_document' )['nsw_theme_document_file'] ?? null;
		$this->assertNotNull( $box );
		$this->assertSame( 'Document file', $box['title'] );
		$this->assertSame( 'nsw_theme_render_document_meta_box', $box['callback'] );
		$this->assertSame( 'side', $box['context'] );
	}

	/** Source: TSD 2.3.3, agency id, texts, colour, website. */
	#[TestDox('Agencies and partners each get their own details box in the main column')]
	public function test_agency_and_partner_boxes(): void {
		$agency  = $this->boxes_for( 'nsw_agency' )['nsw_theme_agency_details'] ?? null;
		$partner = $this->boxes_for( 'nsw_partner' )['nsw_theme_partner_details'] ?? null;
		$this->assertNotNull( $agency );
		$this->assertNotNull( $partner );
		$this->assertSame( array( 'Agency details', 'nsw_theme_render_agency_meta_box', 'normal' ), array( $agency['title'], $agency['callback'], $agency['context'] ) );
		$this->assertSame( array( 'Partner details', 'nsw_theme_render_partner_meta_box', 'normal' ), array( $partner['title'], $partner['callback'], $partner['context'] ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Each details box appears only on the content types it is meant for, and pages and news items get none')]
	public function test_no_box_on_other_types(): void {
		[ $added ] = $this->run_steps( $this->own_add_meta_boxes_steps() );
		$screens   = array();
		foreach ( $added as $box ) {
			$screens[ $box['id'] ][] = $box['screen'];
		}
		$this->assertEqualsCanonicalizing( array( 'nsw_document', 'nsw_event' ), $screens['nsw_theme_description'] ?? array() );
		$this->assertSame( array( 'nsw_event' ), $screens['nsw_theme_event_details'] ?? array() );
		$this->assertSame( array( 'nsw_service' ), $screens['nsw_theme_service_details'] ?? array() );
		$this->assertSame( array( 'nsw_document' ), $screens['nsw_theme_document_file'] ?? array() );
		$this->assertSame( array( 'nsw_agency' ), $screens['nsw_theme_agency_details'] ?? array() );
		$this->assertSame( array( 'nsw_partner' ), $screens['nsw_theme_partner_details'] ?? array() );
		$this->assertSame( array(), $this->boxes_for( 'page' ) );
		$this->assertSame( array(), $this->boxes_for( 'post' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The standard Custom Fields box is removed from agencies, partners, documents and events, and the standard Excerpt box from documents and events')]
	public function test_default_boxes_removed(): void {
		[ , $removed ] = $this->run_steps( $this->own_add_meta_boxes_steps() );
		$this->assertSame(
			array(
				array( 'postcustom', 'nsw_agency', 'normal' ),
				array( 'postcustom', 'nsw_partner', 'normal' ),
				array( 'postcustom', 'nsw_document', 'normal' ),
				array( 'postcustom', 'nsw_event', 'normal' ),
				array( 'postexcerpt', 'nsw_document', 'normal' ),
				array( 'postexcerpt', 'nsw_event', 'normal' ),
			),
			$removed
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Saving an event, service guide, document, agency or partner runs the save step of its own box')]
	public function test_save_steps_registered(): void {
		$expected = array(
			'nsw_event'    => 'nsw_theme_save_event_meta',
			'nsw_service'  => 'nsw_theme_save_service_meta',
			'nsw_document' => 'nsw_theme_save_document_meta',
			'nsw_agency'   => 'nsw_theme_save_agency_meta',
			'nsw_partner'  => 'nsw_theme_save_partner_meta',
		);
		foreach ( $expected as $type => $save ) {
			$regs = array_values( array_filter( nsw_test_hook_registrations( 'save_post_' . $type ), static fn( $reg ) => $save === $reg['callback'] ) );
			$this->assertCount( 1, $regs, "Save step for $type not registered once." );
			$this->assertSame( 2, $regs[0]['accepted_args'] );
		}
	}

	/* --------------------------------------------------- refused saves (all boxes) */

	/** Source: SDP 3.1, nonces and capability checks use WordPress APIs. */
	#[DataProvider('content_types')]
	#[TestDox('The details of $label are saved when the security token is valid and the user may edit the item')]
	public function test_valid_save_is_accepted( string $label, string $save, string $action, string $type, array $fields, string $key, $value ): void {
		$this->stub_save_functions();
		$this->submit( $action, $fields );
		$save( self::POST_ID, $this->post( $type ) );
		$this->assertSame( $value, $this->saved[ $key ] ?? null );
	}

	/** Source: SDP 3.1, nonces and capability checks use WordPress APIs. */
	#[DataProvider('content_types')]
	#[TestDox('Nothing is saved for $label when the security token is missing')]
	public function test_missing_token_is_refused( string $label, string $save, string $action, string $type, array $fields, string $key, $value ): void {
		$this->stub_save_functions();
		$_POST = $fields;
		$save( self::POST_ID, $this->post( $type ) );
		$this->assertSame( array(), $this->saved );
		$this->assertSame( array(), $this->deleted );
	}

	/** Source: SDP 3.1, nonces and capability checks use WordPress APIs. */
	#[DataProvider('content_types')]
	#[TestDox('Nothing is saved for $label when the security token is not valid for its box')]
	public function test_wrong_token_is_refused( string $label, string $save, string $action, string $type, array $fields, string $key, $value ): void {
		$this->stub_save_functions();
		$_POST = array_merge( array( $action . '_nonce' => 'nonce-for-another-form' ), $fields );
		$save( self::POST_ID, $this->post( $type ) );
		$this->assertSame( array(), $this->saved );
		$this->assertSame( array(), $this->deleted );
	}

	/** Source: TSD 2.5.2, WordPress checks capabilities server-side on every wp-admin action. */
	#[DataProvider('content_types')]
	#[TestDox('Nothing is saved for $label by a user who may not edit the item')]
	public function test_user_without_edit_right_is_refused( string $label, string $save, string $action, string $type, array $fields, string $key, $value ): void {
		$this->stub_save_functions();
		$this->may_edit = false;
		$this->submit( $action, $fields );
		$save( self::POST_ID, $this->post( $type ) );
		$this->assertSame( array(), $this->saved );
		$this->assertSame( array(), $this->deleted );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Nothing in the details boxes is saved during an automatic draft save')]
	public function test_autosave_saves_nothing(): void {
		define( 'DOING_AUTOSAVE', true );
		$this->stub_save_functions();
		foreach ( self::content_types() as [ $label, $save, $action, $type, $fields ] ) {
			$this->submit( $action, $fields );
			$save( self::POST_ID, $this->post( $type ) );
		}
		$this->assertSame( array(), $this->saved );
		$this->assertSame( array(), $this->deleted );
	}

	/* --------------------------------------------------------------- description */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The Description box shows the saved description in the field WordPress stores as the excerpt')]
	public function test_description_box_shows_excerpt(): void {
		$html = $this->render( 'nsw_theme_render_description_meta_box', $this->post( 'nsw_document', array( 'post_excerpt' => 'Rules for exporters & agents' ) ) );
		$this->assertSame( 'Rules for exporters & agents', $this->textarea( $html, 'excerpt' ) );
		$this->assertStringContainsString( 'Shown on the public listing.', $html );
	}

	/** Source: TSD 2.5.2, the theme sanitises input and escapes output. */
	#[TestDox('Markup typed into a description is shown as text in the Description box, not run as HTML')]
	public function test_description_box_escapes_markup(): void {
		$html = $this->render( 'nsw_theme_render_description_meta_box', $this->post( 'nsw_event', array( 'post_excerpt' => '</textarea><script>alert(1)</script>' ) ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;&lt;script&gt;', $html );
	}

	/* --------------------------------------------------------------------- events */

	/** Source: TSD 2.3.3, event date (YYYY-MM-DD), time (HH:MM), location, type. */
	#[TestDox('The Event details box shows the saved date, time, location and type')]
	public function test_event_box_shows_saved_values(): void {
		$this->stub_render_functions(
			array(
				'_nsw_theme_event_date'     => '2026-12-03',
				'_nsw_theme_event_time'     => '09:30',
				'_nsw_theme_event_location' => 'Example Hall, Tirana',
				'_nsw_theme_event_type'     => 'training',
			)
		);
		$html = $this->render( 'nsw_theme_render_event_meta_box', $this->post( 'nsw_event' ) );

		$date = $this->input( $html, 'nsw_theme_event_date' );
		$time = $this->input( $html, 'nsw_theme_event_time' );
		$this->assertSame( array( 'date', '2026-12-03' ), array( $date['type'], $date['value'] ) );
		$this->assertSame( array( 'time', '09:30' ), array( $time['type'], $time['value'] ) );
		$this->assertSame( 'Example Hall, Tirana', $this->input( $html, 'nsw_theme_event_location' )['value'] );
		$this->assertSame( array( 'training' ), $this->selected_options( $html ) );
	}

	/** Source: TSD 2.3.3, type (workshop, meeting, conference, training). */
	#[TestDox('The event type list offers Workshop, Meeting, Conference and Training, with no type chosen for a new event')]
	public function test_event_type_options(): void {
		$this->stub_render_functions();
		$html = $this->render( 'nsw_theme_render_event_meta_box', $this->post( 'nsw_event' ) );
		$this->assertSame( array( '', 'workshop', 'meeting', 'conference', 'training' ), $this->option_values( $html, 'nsw_theme_event_type' ) );
		$this->assertSame( array( '' ), $this->selected_options( $html ) );
		$this->assertSame( '', $this->input( $html, 'nsw_theme_event_date' )['value'] );
	}

	/** Source: SDP 3.1, nonces use WordPress APIs. */
	#[TestDox('The Event details box carries the security token that its save step checks')]
	public function test_event_box_token(): void {
		$this->stub_render_functions();
		$html = $this->render( 'nsw_theme_render_event_meta_box', $this->post( 'nsw_event' ) );
		$this->assertSame( 'nonce-for-nsw_theme_event_meta', $this->input( $html, 'nsw_theme_event_meta_nonce' )['value'] );
	}

	/** Source: TSD 2.5.2, the theme escapes output. */
	#[TestDox('Quotes and markup in a saved event location are shown as text in the box')]
	public function test_event_box_escapes_location(): void {
		$this->stub_render_functions( array( '_nsw_theme_event_location' => '"><script>alert(1)</script>' ) );
		$html = $this->render( 'nsw_theme_render_event_meta_box', $this->post( 'nsw_event' ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( '"><script>alert(1)</script>', $this->input( $html, 'nsw_theme_event_location' )['value'] );
	}

	/** Source: TSD 2.3.3, event date, time, location, type. */
	#[TestDox('An event is saved with its date, time, location and type')]
	public function test_event_save_all_fields(): void {
		$this->stub_save_functions();
		$this->submit(
			'nsw_theme_event_meta',
			array(
				'nsw_theme_event_date'     => '2026-12-03',
				'nsw_theme_event_time'     => '09:30',
				'nsw_theme_event_location' => 'Example Hall, Tirana',
				'nsw_theme_event_type'     => 'workshop',
			)
		);
		nsw_theme_save_event_meta( self::POST_ID, $this->post( 'nsw_event' ) );
		$this->assertSame(
			array(
				'_nsw_theme_event_date'     => '2026-12-03',
				'_nsw_theme_event_time'     => '09:30',
				'_nsw_theme_event_location' => 'Example Hall, Tirana',
				'_nsw_theme_event_type'     => 'workshop',
			),
			$this->saved
		);
		$this->assertSame( array(), $this->deleted );
	}

	/** Source: TSD 2.5.2, the theme sanitises input. */
	#[TestDox('Tags, line breaks and added slashes are removed from an event location before it is saved')]
	public function test_event_location_cleaned(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_event_meta', array( 'nsw_theme_event_location' => "<b>Founders\\' Hall</b>\n   Tirana " ) );
		nsw_theme_save_event_meta( self::POST_ID, $this->post( 'nsw_event' ) );
		$this->assertSame( "Founders' Hall Tirana", $this->saved['_nsw_theme_event_location'] );
	}

	/** Source: TSD 2.3.3, the event fields are optional. */
	#[TestDox('Event fields left empty, blank or missing are removed from the event')]
	public function test_event_blank_fields_removed(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_event_meta', array( 'nsw_theme_event_date' => '', 'nsw_theme_event_time' => '   ', 'nsw_theme_event_type' => '' ) );
		nsw_theme_save_event_meta( self::POST_ID, $this->post( 'nsw_event' ) );
		$this->assertSame( array(), $this->saved );
		$this->assertSame(
			array( '_nsw_theme_event_date', '_nsw_theme_event_time', '_nsw_theme_event_location', '_nsw_theme_event_type' ),
			$this->deleted
		);
	}

	/* ------------------------------------------------------------- service guides */

	/** Source: TSD 2.3.3, operation types: import, export, transit. */
	#[TestDox('The Service details box offers Import, Export and Transit and ticks the saved ones')]
	public function test_service_box_ticks_saved_types(): void {
		$this->stub_render_functions( array( '_nsw_theme_service_type' => 'import, transit' ) );
		$this->stub_operation_type_labels();
		$html = $this->render( 'nsw_theme_render_service_meta_box', $this->post( 'nsw_service' ) );

		$boxes = array();
		foreach ( $this->inputs( $html ) as $row ) {
			if ( 'nsw_theme_service_type[]' === ( $row['name'] ?? '' ) ) {
				$boxes[ $row['value'] ] = isset( $row['checked'] );
			}
		}
		$this->assertSame( array( 'import' => true, 'export' => false, 'transit' => true ), $boxes );
		$this->assertMatchesRegularExpression( '/value="import"[^>]*>\s*Import\s*<\/label>/', $html );
		$this->assertMatchesRegularExpression( '/value="export"[^>]*>\s*Export\s*<\/label>/', $html );
		$this->assertMatchesRegularExpression( '/value="transit"[^>]*>\s*Transit\s*<\/label>/', $html );
		$this->assertSame( 'nonce-for-nsw_theme_service_meta', $this->input( $html, 'nsw_theme_service_meta_nonce' )['value'] );
	}

	/** Source: TSD 2.3.3, operation types are optional. */
	#[TestDox('A service guide without operation types shows no ticked box')]
	public function test_service_box_nothing_ticked(): void {
		$this->stub_render_functions();
		$this->stub_operation_type_labels();
		$html = $this->render( 'nsw_theme_render_service_meta_box', $this->post( 'nsw_service' ) );
		$this->assertStringNotContainsString( "checked='checked'", $html );
		$this->assertCount( 3, array_filter( $this->inputs( $html ), static fn( $row ) => 'checkbox' === ( $row['type'] ?? '' ) ) );
	}

	/** Source: TSD 2.3.3, operation types, comma-separated. */
	#[TestDox('The ticked operation types are saved as one comma-separated value in a fixed order')]
	public function test_service_types_saved_in_order(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_service_meta', array( 'nsw_theme_service_type' => array( 'transit', 'import' ) ) );
		nsw_theme_save_service_meta( self::POST_ID, $this->post( 'nsw_service' ) );
		$this->assertSame( array( '_nsw_theme_service_type' => 'import,transit' ), $this->saved );
	}

	/** Source: TSD 2.3.3, operation types: import, export, transit. */
	#[TestDox('An operation type other than import, export or transit is not saved, and a repeated type is saved once')]
	public function test_service_unknown_type_dropped(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_service_meta', array( 'nsw_theme_service_type' => array( 'export', 'air-freight', 'export' ) ) );
		nsw_theme_save_service_meta( self::POST_ID, $this->post( 'nsw_service' ) );
		$this->assertSame( array( '_nsw_theme_service_type' => 'export' ), $this->saved );
	}

	/** Source: TSD 2.3.3, operation types are optional. */
	#[TestDox('A service guide with no valid operation type ticked has its operation types removed')]
	public function test_service_no_type_removes_value(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_service_meta', array( 'nsw_theme_service_type' => array( 'air-freight' ) ) );
		nsw_theme_save_service_meta( self::POST_ID, $this->post( 'nsw_service' ) );
		$this->submit( 'nsw_theme_service_meta', array() );
		nsw_theme_save_service_meta( self::POST_ID, $this->post( 'nsw_service' ) );
		$this->assertSame( array(), $this->saved );
		// Once for each of the two saves. Other optional fields of the guide may be removed too.
		$this->assertCount( 2, array_keys( $this->deleted, '_nsw_theme_service_type', true ) );
	}

	/* ------------------------------------------------------------------ documents */

	/** Source: TSD 2.3.3, document media item; type and size labels. */
	#[TestDox('The Document file box shows the attached file name, its type label and size, and a Remove button')]
	public function test_document_box_with_file(): void {
		$this->stub_render_functions(
			array(
				'_nsw_theme_doc_file_id'   => '77',
				'_nsw_theme_doc_file_type' => 'PDF',
				'_nsw_theme_doc_size'      => '1.2 MB',
			)
		);
		Functions\expect( 'wp_get_attachment_url' )->once()->with( 77 )->andReturn( 'https://nsw.test/wp-content/uploads/2026/11/export-guide.pdf' );
		Functions\expect( 'wp_enqueue_media' )->once();
		$html = $this->render( 'nsw_theme_render_document_meta_box', $this->post( 'nsw_document' ) );

		$file = $this->input( $html, 'nsw_theme_doc_file_id' );
		$this->assertSame( array( 'hidden', '77' ), array( $file['type'], $file['value'] ) );
		$this->assertMatchesRegularExpression( '/id="nsw_theme_doc_file_preview"[^>]*>\s*export-guide\.pdf\s*<\/span>/', $html );
		$this->assertSame( 'PDF', $this->input( $html, 'nsw_theme_doc_file_type' )['value'] );
		$this->assertSame( '1.2 MB', $this->input( $html, 'nsw_theme_doc_size' )['value'] );
		$this->assertMatchesRegularExpression( '/id="nsw_theme_doc_file_clear" style="margin-left:8px;">Remove</', $html );
		$this->assertSame( 'nonce-for-nsw_theme_document_meta', $this->input( $html, 'nsw_theme_document_meta_nonce' )['value'] );
	}

	/** Source: TSD 2.3.3, the document file is optional. */
	#[TestDox('A document without a file shows "No file selected." and hides the Remove button')]
	public function test_document_box_without_file(): void {
		$this->stub_render_functions();
		Functions\expect( 'wp_get_attachment_url' )->never();
		Functions\expect( 'wp_enqueue_media' )->once();
		$html = $this->render( 'nsw_theme_render_document_meta_box', $this->post( 'nsw_document' ) );

		$this->assertSame( '0', $this->input( $html, 'nsw_theme_doc_file_id' )['value'] );
		$this->assertMatchesRegularExpression( '/id="nsw_theme_doc_file_preview"[^>]*>\s*No file selected\.\s*<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/id="nsw_theme_doc_file_clear" style="margin-left:8px;display:none;">Remove</', $html );
		$this->assertStringContainsString( 'label.textContent = "No file selected.";', $html );
	}

	/** Source: TSD 2.3.3, document media item (integer); type and size labels. */
	#[TestDox('A document is saved with its file number, file type label and size label')]
	public function test_document_save_all_fields(): void {
		$this->stub_save_functions();
		$this->submit(
			'nsw_theme_document_meta',
			array( 'nsw_theme_doc_file_id' => '77', 'nsw_theme_doc_file_type' => 'PDF', 'nsw_theme_doc_size' => '1.2 MB' )
		);
		nsw_theme_save_document_meta( self::POST_ID, $this->post( 'nsw_document' ) );
		$this->assertSame(
			array( '_nsw_theme_doc_file_id' => 77, '_nsw_theme_doc_file_type' => 'PDF', '_nsw_theme_doc_size' => '1.2 MB' ),
			$this->saved
		);
	}

	/** Source: TSD 2.3.3, document media item (integer), optional. */
	#[TestDox('A removed, zero or non-numeric file number removes the file from the document')]
	public function test_document_without_valid_file_number(): void {
		$this->stub_save_functions();
		foreach ( array( '', '0', 'abc' ) as $file_id ) {
			$this->submit( 'nsw_theme_document_meta', array( 'nsw_theme_doc_file_id' => $file_id ) );
			nsw_theme_save_document_meta( self::POST_ID, $this->post( 'nsw_document' ) );
		}
		$this->assertArrayNotHasKey( '_nsw_theme_doc_file_id', $this->saved );
		$this->assertCount( 3, array_keys( $this->deleted, '_nsw_theme_doc_file_id', true ) );
	}

	/** Source: TSD 2.3.3, type and size labels are optional. */
	#[TestDox('Empty file type and size labels are removed from the document')]
	public function test_document_blank_labels_removed(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_document_meta', array( 'nsw_theme_doc_file_id' => '77', 'nsw_theme_doc_file_type' => ' ' ) );
		nsw_theme_save_document_meta( self::POST_ID, $this->post( 'nsw_document' ) );
		$this->assertSame( array( '_nsw_theme_doc_file_id' => 77 ), $this->saved );
		// Other optional fields of the document may be removed too; the file is kept.
		$this->assertContains( '_nsw_theme_doc_file_type', $this->deleted );
		$this->assertContains( '_nsw_theme_doc_size', $this->deleted );
		$this->assertNotContains( '_nsw_theme_doc_file_id', $this->deleted );
	}

	/** Source: TSD 2.3.3, "Document media item, or external URL"; TSD 2.2.1, content types provide the wp-admin edit screens. */
	#[Group('tsd-difference')]
	#[TestDox('A document has a field for an external address that shows the saved address')]
	public function test_document_box_shows_external_url(): void {
		$this->stub_render_functions( array( '_nsw_theme_doc_external_url' => 'https://docs.example.com/export-guide.pdf' ) );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		$html = $this->render_edit_screen( 'nsw_document', $this->post( 'nsw_document' ) );
		$this->assertNotNull(
			$this->name_of_field_showing( $html, 'https://docs.example.com/export-guide.pdf' ),
			'The document edit screen has no field showing the external address.'
		);
	}

	/** Source: TSD 2.3.3, "Document media item, or external URL"; TSD 2.2.1, content types provide the wp-admin edit screens. */
	#[Group('tsd-difference')]
	#[TestDox('An external address entered for a document is saved')]
	public function test_document_external_url_saved(): void {
		$this->stub_render_functions( array( '_nsw_theme_doc_external_url' => 'https://docs.example.com/export-guide.pdf' ) );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		$post  = $this->post( 'nsw_document' );
		$html  = $this->render_edit_screen( 'nsw_document', $post );
		$field = $this->name_of_field_showing( $html, 'https://docs.example.com/export-guide.pdf' );
		$this->assertNotNull( $field, 'The document edit screen has no field for the external address.' );

		$this->stub_save_functions();
		$_POST = $this->form_data( $html, array( $field => 'https://docs.example.com/new-guide.pdf' ) );
		$this->save_edit_screen( 'nsw_document', $post );
		$this->assertSame( 'https://docs.example.com/new-guide.pdf', $this->saved['_nsw_theme_doc_external_url'] ?? null );
	}

	/** Source: TSD 2.2.2, "Service guides (procedures, fees) and documents have an optional “Visible until” date"; TSD 2.1, wp-admin: "“Visible until” date". */
	#[Group('tsd-difference')]
	#[TestDox('A document has a Visible until field that shows the saved date')]
	public function test_document_box_shows_visible_until(): void {
		$this->stub_render_functions( array( '_nsw_theme_visible_until' => '2026-12-31' ) );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		$html = $this->render_edit_screen( 'nsw_document', $this->post( 'nsw_document' ) );
		$this->assertMatchesRegularExpression( '/visible until/i', $html, 'The document edit screen has no Visible until label.' );
		$this->assertNotNull( $this->name_of_field_showing( $html, '2026-12-31' ), 'The document edit screen has no field showing the saved Visible until date.' );
	}

	/** Source: TSD 2.3.3, "_nsw_theme_visible_until YYYY-MM-DD ... Last visible day of a document or service guide". */
	#[Group('tsd-difference')]
	#[TestDox('A Visible until date entered for a document is saved')]
	public function test_document_visible_until_saved(): void {
		$this->stub_render_functions( array( '_nsw_theme_visible_until' => '2026-12-31' ) );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		$post  = $this->post( 'nsw_document' );
		$html  = $this->render_edit_screen( 'nsw_document', $post );
		$field = $this->name_of_field_showing( $html, '2026-12-31' );
		$this->assertNotNull( $field, 'The document edit screen has no Visible until field.' );

		$this->stub_save_functions();
		$_POST = $this->form_data( $html, array( $field => '2027-01-15' ) );
		$this->save_edit_screen( 'nsw_document', $post );
		$this->assertSame( '2027-01-15', $this->saved['_nsw_theme_visible_until'] ?? null );
	}

	/** Source: TSD 2.2.2, "Service guides (procedures, fees) and documents have an optional “Visible until” date"; TSD 2.1, wp-admin: "“Visible until” date". */
	#[Group('tsd-difference')]
	#[TestDox('A service guide has a Visible until field that shows the saved date')]
	public function test_service_box_shows_visible_until(): void {
		$this->stub_render_functions( array( '_nsw_theme_visible_until' => '2026-12-31' ) );
		$this->stub_operation_type_labels();
		$html = $this->render_edit_screen( 'nsw_service', $this->post( 'nsw_service' ) );
		$this->assertMatchesRegularExpression( '/visible until/i', $html, 'The service guide edit screen has no Visible until label.' );
		$this->assertNotNull( $this->name_of_field_showing( $html, '2026-12-31' ), 'The service guide edit screen has no field showing the saved Visible until date.' );
	}

	/** Source: TSD 2.3.3, "_nsw_theme_visible_until YYYY-MM-DD ... Last visible day of a document or service guide". */
	#[Group('tsd-difference')]
	#[TestDox('A Visible until date entered for a service guide is saved')]
	public function test_service_visible_until_saved(): void {
		$this->stub_render_functions( array( '_nsw_theme_visible_until' => '2026-12-31' ) );
		$this->stub_operation_type_labels();
		$post  = $this->post( 'nsw_service' );
		$html  = $this->render_edit_screen( 'nsw_service', $post );
		$field = $this->name_of_field_showing( $html, '2026-12-31' );
		$this->assertNotNull( $field, 'The service guide edit screen has no Visible until field.' );

		$this->stub_save_functions();
		$_POST = $this->form_data( $html, array( $field => '2027-01-15' ) );
		$this->save_edit_screen( 'nsw_service', $post );
		$this->assertSame( '2027-01-15', $this->saved['_nsw_theme_visible_until'] ?? null );
	}

	/* ------------------------------------------------------------------- agencies */

	/** Source: TSD 2.3.3, agency id, texts, colour, website. */
	#[TestDox('The Agency details box shows the saved id, name, abbreviation, description, documents, colour and website')]
	public function test_agency_box_shows_saved_values(): void {
		$this->stub_render_functions(
			array(
				'_nsw_theme_agency_id'      => 'exa',
				'_nsw_theme_agency_abbr'    => 'EXA',
				'_nsw_theme_agency_name'    => 'Example "Trade" Agency',
				'_nsw_theme_agency_desc'    => "Checks goods.\nIssues permits.",
				'_nsw_theme_agency_docs'    => "Import permit\nHealth certificate",
				'_nsw_theme_agency_color'   => '#123456',
				'_nsw_theme_agency_website' => 'https://agency.example.com',
			)
		);
		$html = $this->render( 'nsw_theme_render_agency_meta_box', $this->post( 'nsw_agency' ) );

		$this->assertSame( 'exa', $this->input( $html, 'nsw_theme_agency_id' )['value'] );
		$this->assertSame( 'EXA', $this->input( $html, 'nsw_theme_agency_abbr' )['value'] );
		$this->assertSame( 'Example "Trade" Agency', $this->input( $html, 'nsw_theme_agency_name' )['value'] );
		$this->assertSame( "Checks goods.\nIssues permits.", $this->textarea( $html, 'nsw_theme_agency_desc' ) );
		$this->assertSame( "Import permit\nHealth certificate", $this->textarea( $html, 'nsw_theme_agency_docs' ) );
		$this->assertSame( '#123456', $this->input( $html, 'nsw_theme_agency_color' )['value'] );
		$website = $this->input( $html, 'nsw_theme_agency_website' );
		$this->assertSame( array( 'url', 'https://agency.example.com' ), array( $website['type'], $website['value'] ) );
		$this->assertStringContainsString( 'value="Example &quot;Trade&quot; Agency"', $html );
		$this->assertSame( 'nonce-for-nsw_theme_agency_meta', $this->input( $html, 'nsw_theme_agency_meta_nonce' )['value'] );
	}

	/** Source: TSD 2.3.3, agency id, texts, colour, website. */
	#[TestDox('An agency is saved with its id, abbreviation, name, colour, description, documents and website')]
	public function test_agency_save_all_fields(): void {
		$this->stub_save_functions();
		$this->submit(
			'nsw_theme_agency_meta',
			array(
				'nsw_theme_agency_id'      => 'exa',
				'nsw_theme_agency_abbr'    => 'EXA',
				'nsw_theme_agency_name'    => 'Example Agency',
				'nsw_theme_agency_color'   => '#123456',
				'nsw_theme_agency_desc'    => "Checks goods.\nIssues permits.",
				'nsw_theme_agency_docs'    => "Import permit\nHealth certificate",
				'nsw_theme_agency_website' => 'https://agency.example.com',
			)
		);
		nsw_theme_save_agency_meta( self::POST_ID, $this->post( 'nsw_agency' ) );
		$this->assertSame(
			array(
				'_nsw_theme_agency_id'      => 'exa',
				'_nsw_theme_agency_abbr'    => 'EXA',
				'_nsw_theme_agency_name'    => 'Example Agency',
				'_nsw_theme_agency_color'   => '#123456',
				'_nsw_theme_agency_desc'    => "Checks goods.\nIssues permits.",
				'_nsw_theme_agency_docs'    => "Import permit\nHealth certificate",
				'_nsw_theme_agency_website' => 'https://agency.example.com',
			),
			$this->saved
		);
		$this->assertSame( array(), $this->deleted );
	}

	/** Source: TSD 2.5.2, the theme sanitises input. */
	#[TestDox('An agency name is saved on one line and its description and documents list keep their line breaks, with tags removed from all three')]
	public function test_agency_text_cleaning(): void {
		$this->stub_save_functions();
		$this->submit(
			'nsw_theme_agency_meta',
			array(
				'nsw_theme_agency_name' => "Example\n<i>Agency</i>",
				'nsw_theme_agency_desc' => "<b>Checks</b> goods.\nIssues permits.",
				'nsw_theme_agency_docs' => "Import permit\n<em>Health</em> certificate",
			)
		);
		nsw_theme_save_agency_meta( self::POST_ID, $this->post( 'nsw_agency' ) );
		$this->assertSame( 'Example Agency', $this->saved['_nsw_theme_agency_name'] );
		$this->assertSame( "Checks goods.\nIssues permits.", $this->saved['_nsw_theme_agency_desc'] );
		$this->assertSame( "Import permit\nHealth certificate", $this->saved['_nsw_theme_agency_docs'] );
	}

	/** Source: TSD 2.5.2, the theme sanitises input. */
	#[TestDox('An agency website goes through the WordPress address check, and an address it refuses is not saved')]
	public function test_agency_website_refused_address(): void {
		$this->stub_save_functions();
		$checked = array();
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) use ( &$checked ) {
				$checked[] = $url;
				return ''; // The address check refuses it.
			}
		);
		$this->submit( 'nsw_theme_agency_meta', array( 'nsw_theme_agency_website' => 'javascript:alert(1)' ) );
		nsw_theme_save_agency_meta( self::POST_ID, $this->post( 'nsw_agency' ) );
		$this->assertArrayNotHasKey( '_nsw_theme_agency_website', $this->saved );
		$this->assertContains( '_nsw_theme_agency_website', $this->deleted );
		$this->assertSame( array( 'javascript:alert(1)' ), $checked );
	}

	/** Source: TSD 2.3.3, agency fields are optional. */
	#[TestDox('Agency fields left empty or missing are removed from the agency')]
	public function test_agency_blank_fields_removed(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_agency_meta', array( 'nsw_theme_agency_name' => '', 'nsw_theme_agency_desc' => "  \n " ) );
		nsw_theme_save_agency_meta( self::POST_ID, $this->post( 'nsw_agency' ) );
		$this->assertSame( array(), $this->saved );
		$this->assertSame(
			array(
				'_nsw_theme_agency_id',
				'_nsw_theme_agency_abbr',
				'_nsw_theme_agency_name',
				'_nsw_theme_agency_color',
				'_nsw_theme_agency_desc',
				'_nsw_theme_agency_docs',
				'_nsw_theme_agency_website',
			),
			$this->deleted
		);
	}

	/* ------------------------------------------------------------------- partners */

	/** Source: TSD 2.3.3, partner id, type, texts, colour, website, logo flag. */
	#[TestDox('The Partner details box shows the saved id, type, name, description, colour, website and logo background choice')]
	public function test_partner_box_shows_saved_values(): void {
		$this->stub_render_functions(
			array(
				'_nsw_theme_partner_id'      => 'example-fund',
				'_nsw_theme_partner_type'    => 'government',
				'_nsw_theme_partner_name'    => 'Example Fund',
				'_nsw_theme_partner_desc'    => "Supports trade.\nFunds training.",
				'_nsw_theme_partner_color'   => '#1a2b3c',
				'_nsw_theme_partner_website' => 'https://partner.example.org',
				'_nsw_theme_partner_logo_bg' => '1',
			)
		);
		$html = $this->render( 'nsw_theme_render_partner_meta_box', $this->post( 'nsw_partner' ) );

		$this->assertSame( 'example-fund', $this->input( $html, 'nsw_theme_partner_id' )['value'] );
		$this->assertSame( array( 'government' ), $this->selected_options( $html ) );
		$this->assertSame( 'Example Fund', $this->input( $html, 'nsw_theme_partner_name' )['value'] );
		$this->assertSame( "Supports trade.\nFunds training.", $this->textarea( $html, 'nsw_theme_partner_desc' ) );
		$this->assertSame( '#1a2b3c', $this->input( $html, 'nsw_theme_partner_color' )['value'] );
		$this->assertSame( 'https://partner.example.org', $this->input( $html, 'nsw_theme_partner_website' )['value'] );
		$this->assertArrayHasKey( 'checked', $this->input( $html, 'nsw_theme_partner_logo_bg' ) );
		$this->assertSame( 'nonce-for-nsw_theme_partner_meta', $this->input( $html, 'nsw_theme_partner_meta_nonce' )['value'] );
	}

	/** Source: TSD 2.3.3, partner type (international or government). */
	#[TestDox('The partner type list offers International and Government, and a new partner has the logo background unticked')]
	public function test_partner_type_options(): void {
		$this->stub_render_functions();
		$html = $this->render( 'nsw_theme_render_partner_meta_box', $this->post( 'nsw_partner' ) );
		$this->assertSame( array( 'international', 'government' ), $this->option_values( $html, 'nsw_theme_partner_type' ) );
		$this->assertArrayNotHasKey( 'checked', $this->input( $html, 'nsw_theme_partner_logo_bg' ) );
	}

	/** Source: TSD 2.3.3, partner id, type, texts, colour, website, logo flag. */
	#[TestDox('A partner is saved with its id, name, colour, type, description, website and logo background choice')]
	public function test_partner_save_all_fields(): void {
		$this->stub_save_functions();
		$this->submit(
			'nsw_theme_partner_meta',
			array(
				'nsw_theme_partner_id'      => 'example-fund',
				'nsw_theme_partner_name'    => 'Example Fund',
				'nsw_theme_partner_color'   => '#1a2b3c',
				'nsw_theme_partner_type'    => 'government',
				'nsw_theme_partner_desc'    => "Supports trade.\nFunds training.",
				'nsw_theme_partner_website' => 'https://partner.example.org',
				'nsw_theme_partner_logo_bg' => '1',
			)
		);
		nsw_theme_save_partner_meta( self::POST_ID, $this->post( 'nsw_partner' ) );
		$this->assertSame(
			array(
				'_nsw_theme_partner_id'      => 'example-fund',
				'_nsw_theme_partner_name'    => 'Example Fund',
				'_nsw_theme_partner_color'   => '#1a2b3c',
				'_nsw_theme_partner_type'    => 'government',
				'_nsw_theme_partner_desc'    => "Supports trade.\nFunds training.",
				'_nsw_theme_partner_website' => 'https://partner.example.org',
				'_nsw_theme_partner_logo_bg' => '1',
			),
			$this->saved
		);
		$this->assertSame( array(), $this->deleted );
	}

	/** Source: TSD 2.3.3, partner type (international or government). */
	#[TestDox('A partner type written in capitals is saved in lower case')]
	public function test_partner_type_lower_case(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_partner_meta', array( 'nsw_theme_partner_type' => 'GOVERNMENT' ) );
		nsw_theme_save_partner_meta( self::POST_ID, $this->post( 'nsw_partner' ) );
		$this->assertSame( 'government', $this->saved['_nsw_theme_partner_type'] );
	}

	/** Source: TSD 2.3.3, partner type (international or government); the fallback is theme behaviour. */
	#[TestDox('A partner type other than international or government, or no type at all, is saved as international')]
	public function test_partner_unknown_type_becomes_international(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_partner_meta', array( 'nsw_theme_partner_type' => 'ngo' ) );
		nsw_theme_save_partner_meta( self::POST_ID, $this->post( 'nsw_partner' ) );
		$this->assertSame( 'international', $this->saved['_nsw_theme_partner_type'] );

		$this->saved = array();
		$this->submit( 'nsw_theme_partner_meta', array() );
		nsw_theme_save_partner_meta( self::POST_ID, $this->post( 'nsw_partner' ) );
		$this->assertSame( 'international', $this->saved['_nsw_theme_partner_type'] );
	}

	/** Source: TSD 2.3.3, partner fields and logo flag are optional. */
	#[TestDox('Partner fields left empty and an unticked logo background are removed from the partner')]
	public function test_partner_blank_fields_removed(): void {
		$this->stub_save_functions();
		$this->submit( 'nsw_theme_partner_meta', array( 'nsw_theme_partner_name' => '  ', 'nsw_theme_partner_logo_bg' => '' ) );
		nsw_theme_save_partner_meta( self::POST_ID, $this->post( 'nsw_partner' ) );
		$this->assertSame( array( '_nsw_theme_partner_type' => 'international' ), $this->saved );
		$this->assertSame(
			array(
				'_nsw_theme_partner_id',
				'_nsw_theme_partner_name',
				'_nsw_theme_partner_color',
				'_nsw_theme_partner_desc',
				'_nsw_theme_partner_website',
				'_nsw_theme_partner_logo_bg',
			),
			$this->deleted
		);
	}

	/** Source: TSD 2.5.2, the theme sanitises input. */
	#[TestDox('A partner website goes through the WordPress address check, and an address it refuses is not saved')]
	public function test_partner_website_refused_address(): void {
		$this->stub_save_functions();
		$checked = array();
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) use ( &$checked ) {
				$checked[] = $url;
				return ''; // The address check refuses it.
			}
		);
		$this->submit( 'nsw_theme_partner_meta', array( 'nsw_theme_partner_website' => 'javascript:alert(1)' ) );
		nsw_theme_save_partner_meta( self::POST_ID, $this->post( 'nsw_partner' ) );
		$this->assertArrayNotHasKey( '_nsw_theme_partner_website', $this->saved );
		$this->assertContains( '_nsw_theme_partner_website', $this->deleted );
		$this->assertSame( array( 'javascript:alert(1)' ), $checked );
	}
}
