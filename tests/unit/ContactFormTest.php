<?php
/**
 * Unit tests for inc/contact-form.php: the website contact form.
 *
 * Covers the web address that receives a contact form message, the checks made on
 * it (security token, spam trap, consent, required fields, lengths, attachment, one
 * message per visitor every 30 seconds), the hand-over to the Help Desk, the
 * confirmation email to the sender, the email to the site administrator when the
 * Help Desk is not set up, the mail server set-up and the settings screen.
 *
 * Nothing is sent: the Help Desk, the mail function and WordPress are simulated. The
 * WordPress cleaning functions are replaced with simple stand-ins that, like
 * WordPress, remove HTML tags and remove line breaks from single-line text.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.2.3 (input validation), 2.3.3 (settings), 2.4.2 and 2.4.3 (interfaces,
 * errors), 2.5 (security) and 2.6.2 (logging). Where today's code differs from the
 * TSD, the test expects the TSD and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class ContactFormTest extends TestCase {

	private const VISITOR_IP = '203.0.113.10';

	/** @var array<string, mixed> $_SERVER as it was before the test. */
	private array $server_backup = array();

	/** @var array<string, mixed> $_FILES as it was before the test. */
	private array $files_backup = array();

	/** @var array<string, array{value: mixed, expiration: int}> Simulated transients. */
	private array $transients = array();

	/** @var array<int, array{to: mixed, subject: string, message: string, headers: mixed}> Emails handed to wp_mail. */
	private array $mails = array();

	/** @var bool|\Throwable What wp_mail returns, or throws. */
	private $mail_result = true;

	/** @var array<int, string> Lines written to the PHP error log. */
	private array $logs = array();

	/** @var array<int, array<string, string>> Data passed to the Help Desk client. */
	private array $tickets = array();

	/** @var array<int, array{url: string, body: array<string, mixed>}> Simulated Help Desk calls. */
	private array $help_desk_calls = array();

	/** @var array<string, mixed> Simulated WordPress options. */
	private array $options = array();

	/** @var array<string, string> Simulated contact settings (wp-config.php constants). */
	private array $config = array();

	private string $site_name = 'Example Portal';

	/** @var array<int, string> Temporary upload files to delete. */
	private array $temp_files = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_backup        = $_SERVER;
		$this->files_backup         = $_FILES;
		$_SERVER['REMOTE_ADDR']     = self::VISITOR_IP;
		$this->options              = array( 'admin_email' => 'admin@example.com' );
		Functions\when( 'error_log' )->alias(
			function ( $message ) {
				$this->logs[] = (string) $message;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_FILES  = $this->files_backup;
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	/* ------------------------------------------------------------------ helpers */

	/** A valid message as the browser sends it (consent box ticked, spam trap empty). */
	private function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'fullName'     => 'Jane Doe',
				'email'        => 'jane@example.com',
				'organization' => 'Example Trading sh.p.k.',
				'category'     => 'technical',
				'agency'       => 'customs',
				'subject'      => 'Cannot upload a document',
				'message'      => "Line one.\nLine two.",
				'privacy'      => 'on',
				'website'      => '',
			),
			$overrides
		);
	}

	private function request( array $fields, ?string $nonce = 'valid-nonce', bool $as_form = false, array $files = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/nsw-theme/v1/contact' );
		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		if ( $as_form ) {
			$request->set_body_params( $fields );
		} else {
			$request->set_json_params( $fields );
		}
		if ( $files ) {
			$request->set_file_params( $files );
		}
		return $request;
	}

	/**
	 * A valid message posted as a web form with an uploaded file. As on a real site, the
	 * file is in both the request and PHP's list of uploaded files.
	 */
	private function request_with_attachment( array $files ): \WP_REST_Request {
		$_FILES = $files;
		$this->php_received( $files );
		return $this->request( $this->fields(), 'valid-nonce', true, $files );
	}

	/**
	 * PHP's own check that a file arrived with the request (is_uploaded_file) only knows
	 * files PHP received over HTTP. The test files are made with tempnam, so this tells the
	 * check which of them count as uploaded: exactly the ones in $files.
	 */
	private function php_received( array $files ): void {
		$uploaded = array_column( $files, 'tmp_name' );
		Functions\when( 'is_uploaded_file' )->alias( static fn( $path ) => in_array( $path, $uploaded, true ) );
	}

	/** Replaces the WordPress functions the form uses with simple stand-ins. */
	private function stub_wordpress(): void {
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn( $nonce, $action = -1 ) => ( 'valid-nonce' === $nonce && 'wp_rest' === $action ) ? 1 : false
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => isset( $this->transients[ $key ] ) ? $this->transients[ $key ]['value'] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration = 0 ) {
				$this->transients[ $key ] = array( 'value' => $value, 'expiration' => (int) $expiration );
				return true;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $text ) => trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) )
		);
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'sanitize_email' )->alias( static fn( $email ) => trim( (string) $email ) );
		Functions\when( 'sanitize_file_name' )->alias(
			static fn( $name ) => (string) preg_replace( '/[^A-Za-z0-9._-]+/', '', str_replace( ' ', '-', (string) $name ) )
		);
		Functions\when( 'is_email' )->alias(
			static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false
		);
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'get_bloginfo' )->alias( fn( $show = '' ) => 'name' === $show ? $this->site_name : '' );
		Functions\when( 'wp_specialchars_decode' )->alias(
			static fn( $text, $quote_style = ENT_NOQUOTES ) => htmlspecialchars_decode( (string) $text, ENT_QUOTES )
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message, $headers = '' ) {
				$this->mails[] = array( 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers );
				if ( $this->mail_result instanceof \Throwable ) {
					throw $this->mail_result;
				}
				return $this->mail_result;
			}
		);
		$this->stub_config();
	}

	/** The contact settings come from $this->config, as if defined in wp-config.php. */
	private function stub_config(): void {
		Functions\when( 'nsw_theme_contact_config' )->alias(
			fn( $key, $default = '' ) => array_key_exists( $key, $this->config ) ? (string) $this->config[ $key ] : (string) $default
		);
	}

	/** The Help Desk is set up and answers with $reply (a ticket number or an error). */
	private function help_desk_configured( $reply = array( 'id' => 42 ) ): void {
		Functions\when( 'nsw_theme_contact_mantis_configured' )->justReturn( true );
		Functions\when( 'nsw_theme_contact_create_mantis_issue' )->alias(
			function ( array $data ) use ( $reply ) {
				$this->tickets[] = $data;
				return $reply;
			}
		);
	}

	private function help_desk_not_configured(): void {
		Functions\when( 'nsw_theme_contact_mantis_configured' )->justReturn( false );
		Functions\when( 'nsw_theme_contact_create_mantis_issue' )->alias(
			function ( array $data ) {
				$this->tickets[] = $data;
				return array( 'id' => 1 );
			}
		);
	}

	/** The real Help Desk client runs; only its network call is simulated (ticket 42). */
	private function help_desk_over_the_network(): void {
		Functions\when( 'nsw_theme_contact_mantis_configured' )->justReturn( true );
		$this->config['mantis_url']        = 'https://support.example.com/';
		$this->config['mantis_token']      = 'invented-test-token';
		$this->config['mantis_project_id'] = '1';
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->help_desk_calls[] = array( 'url' => (string) $url, 'body' => (array) json_decode( (string) $args['body'], true ) );
				return array( 'response' => array( 'code' => 201 ), 'body' => '{"issue":{"id":42}}' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );
	}

	private function assertRefused( $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array( 'status' => $status ), $result->get_error_data() );
	}

	private function assertAccepted( $result ): void {
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );
	}

	/** The rate-limit marker name for a visitor address. */
	private static function marker( string $ip ): string {
		return 'nsw_theme_contact_' . md5( $ip );
	}

	/** An email address of exactly 200 characters (with $extra letters added in front). */
	private static function long_email( int $extra = 0 ): string {
		return str_repeat( 'x', $extra ) . 'jane@' . str_repeat( 'a', 61 ) . '.' . str_repeat( 'b', 60 ) . '.' . str_repeat( 'c', 60 ) . '.example.com';
	}

	/**
	 * First bytes of each file type, so a content check sees the right type. The two Word
	 * formats are containers, so they also hold the part names every Word document has: a
	 * doc the WordDocument stream (OLE2 directory, UTF-16LE), a docx the [Content_Types].xml
	 * and word/document.xml entries (ZIP local file headers).
	 */
	private const MAGIC = array(
		'pdf'  => "%PDF-1.4\n",
		'doc'  => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\x3E\0\x03\0\xFE\xFF\x09\0"
			. "R\0o\0o\0t\0 \0E\0n\0t\0r\0y\0\0\0W\0o\0r\0d\0D\0o\0c\0u\0m\0e\0n\0t\0\0\0",
		'docx' => "PK\x03\x04\x14\0\x06\0\x08\0\0\0\x21\0\0\0\0\0\0\0\0\0\0\0\0\0\x13\0\0\0[Content_Types].xml"
			. "PK\x03\x04\x14\0\x06\0\x08\0\0\0\x21\0\0\0\0\0\0\0\0\0\0\0\0\0\x11\0\0\0word/document.xml",
		'jpg'  => "\xFF\xD8\xFF\xE0",
		'jpeg' => "\xFF\xD8\xFF\xE0",
		'png'  => "\x89PNG\r\n\x1a\n",
		'exe'  => 'MZ',
		'zip'  => "PK\x03\x04",
		'html' => '<!doctype html>',
	);

	private const MIME = array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'exe'  => 'application/x-msdownload',
		'zip'  => 'application/zip',
		'html' => 'text/html',
	);

	/**
	 * A file uploaded with the form, as PHP describes it, stored in a temporary file of
	 * exactly $size bytes.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	private function upload( string $name, int $size, string $content = '' ): array {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $content ) {
			$content = self::MAGIC[ $ext ] ?? '';
		}
		$path               = (string) tempnam( sys_get_temp_dir(), 'nsw-upload-' );
		$this->temp_files[] = $path;
		$handle             = fopen( $path, 'wb' );
		fwrite( $handle, $content );
		if ( $size > strlen( $content ) ) {
			ftruncate( $handle, $size );
		}
		fclose( $handle );
		return array(
			'name'     => $name,
			'type'     => self::MIME[ $ext ] ?? 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => $size,
		);
	}

	/** The closure this file registered on a hook. */
	private function hook( string $hook_name ): callable {
		return nsw_test_hook_callback(
			$hook_name,
			0,
			static function ( array $registration ): bool {
				$callback = $registration['callback'];
				return $callback instanceof \Closure
					&& str_ends_with( (string) ( new \ReflectionFunction( $callback ) )->getFileName(), 'inc/contact-form.php' );
			}
		);
	}

	/** A stand-in for the PHPMailer object WordPress passes to the mail set-up. */
	private function mailer(): object {
		return new class() {
			public bool $smtp = false;
			public $Host = null;
			public $Port = null;
			public $SMTPAuth = null;
			public $Username = null;
			public $Password = null;
			public $SMTPSecure = null;
			public $SMTPOptions = null;

			public function isSMTP(): void {
				$this->smtp = true;
			}
		};
	}

	/** Runs the settings set-up and returns what it registered. */
	private function run_settings_setup(): array {
		$found = array( 'settings' => array(), 'sections' => array(), 'fields' => array() );
		Functions\when( 'register_setting' )->alias(
			function ( $group, $name, $args = array() ) use ( &$found ) {
				$found['settings'][ $name ] = array( 'group' => $group ) + (array) $args;
			}
		);
		Functions\when( 'add_settings_section' )->alias(
			function ( $id, $title, $callback, $page ) use ( &$found ) {
				$found['sections'][ $id ] = array( 'title' => $title, 'callback' => $callback, 'page' => $page );
			}
		);
		Functions\when( 'add_settings_field' )->alias(
			function ( $id, $title, $callback, $page, $section = 'default' ) use ( &$found ) {
				$found['fields'][ $id ] = array( 'title' => $title, 'callback' => $callback, 'page' => $page, 'section' => $section );
			}
		);
		( $this->hook( 'admin_init' ) )();
		return $found;
	}

	private function render( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/* -------------------------------------------------------------- web address */

	/** Source: TSD 2.4.2, Browser: POST /wp-json/nsw-theme/v1/contact; TSD 2.5.2, visitors use the contact form without an account. */
	#[TestDox('The contact form is received at a public web address that accepts posted messages')]
	public function test_route_is_public_post_endpoint(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$routes ) {
				$routes[] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
				return true;
			}
		);

		( $this->hook( 'rest_api_init' ) )();

		$this->assertCount( 1, $routes );
		$this->assertSame( 'nsw-theme/v1', $routes[0]['namespace'] );
		$this->assertSame( '/contact', $routes[0]['route'] );
		$this->assertSame( 'POST', $routes[0]['args']['methods'] );
		$this->assertSame( 'nsw_theme_handle_contact', $routes[0]['args']['callback'] );
		$this->assertSame( '__return_true', $routes[0]['args']['permission_callback'] );
	}

	/** Source: TSD 2.2.3, name, email, category, subject, message ... required; email format checked. */
	#[TestDox('The web address requires the name, email, category, subject and message, and checks the email format')]
	public function test_route_declares_required_fields(): void {
		$args = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $options ) use ( &$args ) {
				$args = $options['args'];
				return true;
			}
		);

		( $this->hook( 'rest_api_init' ) )();

		foreach ( array( 'fullName', 'email', 'category', 'subject', 'message' ) as $field ) {
			$this->assertTrue( $args[ $field ]['required'] ?? false, "$field must be required" );
		}
		$this->assertSame( 'email', $args['email']['format'] );
		$this->assertFalse( $args['organization']['required'] ?? false );
		$this->assertFalse( $args['agency']['required'] ?? false );
	}

	/* ----------------------------------------------------------- security token */

	/** Source: TSD 2.4.3, 403 security check failed; TSD 2.4.2, REST nonce in X-WP-Nonce. */
	#[TestDox('A message sent without the security token is refused (status 403) and nothing is sent')]
	public function test_missing_nonce_refused(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields(), null ) );

		$this->assertRefused( $result, 'nsw_theme_bad_nonce', 403 );
		$this->assertSame( 'Security check failed.', $result->get_error_message() );
		$this->assertSame( array(), $this->tickets );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->transients );
	}

	/** Source: TSD 2.4.3, 403 security check failed (also a cached page with an expired nonce). */
	#[TestDox('A message with an expired or wrong security token is refused (status 403) and nothing is sent')]
	public function test_wrong_nonce_refused(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields(), 'expired-nonce' ) );

		$this->assertRefused( $result, 'nsw_theme_bad_nonce', 403 );
		$this->assertSame( array(), $this->tickets );
		$this->assertSame( array(), $this->mails );
	}

	/* ---------------------------------------------------------------- spam trap */

	/** Source: TSD 2.2.3, Spam honeypot. */
	#[TestDox('A message with the hidden spam trap filled in is answered as sent but is not passed on')]
	public function test_honeypot_discards_message(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields( array( 'website' => 'https://spam.example.com' ) ) ) );

		$this->assertAccepted( $result );
		$this->assertSame( array(), $this->tickets );
		$this->assertSame( array(), $this->mails );
	}

	/* ------------------------------------------------------------------ consent */

	public static function missing_consent(): array {
		return array(
			'left out'   => array( 'left out', null ),
			'sent blank' => array( 'sent blank', '' ),
		);
	}

	/** Source: TSD 2.2.3, privacy consent required ... Consent ... checked on the server; TSD 2.4.3, 400 missing ... fields. */
	#[Group('tsd-difference')]
	#[DataProvider('missing_consent')]
	#[TestDox('A message with the privacy consent $label is refused (status 400) and no ticket is created')]
	public function test_missing_consent_refused( string $label, ?string $value ): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$fields = $this->fields();
		if ( null === $value ) {
			unset( $fields['privacy'] );
		} else {
			$fields['privacy'] = $value;
		}

		$result = nsw_theme_handle_contact( $this->request( $fields ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A message without consent was accepted.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertSame( array(), $this->tickets );
	}

	/* ---------------------------------------------------------- required fields */

	public static function required_fields(): array {
		return array(
			'name'     => array( 'name', 'fullName' ),
			'email'    => array( 'email address', 'email' ),
			'category' => array( 'category', 'category' ),
			'subject'  => array( 'subject', 'subject' ),
			'message'  => array( 'message text', 'message' ),
		);
	}

	/** Source: TSD 2.2.3, name, email, category, subject, message ... required; TSD 2.4.3, 400 missing. */
	#[DataProvider('required_fields')]
	#[TestDox('A message with an empty $label is refused as incomplete (status 400)')]
	public function test_empty_required_field_refused( string $label, string $field ): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields( array( $field => '' ) ) ) );

		$this->assertRefused( $result, 'nsw_theme_missing', 400 );
		$this->assertSame( 'Missing required fields.', $result->get_error_message() );
		$this->assertSame( array(), $this->tickets );
	}

	/** Source: TSD 2.2.3, required fields; TSD 2.4.3, 400 missing. */
	#[TestDox('A required field that holds only spaces or only HTML tags counts as empty')]
	public function test_blank_after_cleaning_counts_as_missing(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$spaces = nsw_theme_handle_contact( $this->request( $this->fields( array( 'subject' => '     ' ) ) ) );
		$this->transients = array();
		$tags = nsw_theme_handle_contact( $this->request( $this->fields( array( 'fullName' => '<b></b>' ) ) ) );

		$this->assertRefused( $spaces, 'nsw_theme_missing', 400 );
		$this->assertRefused( $tags, 'nsw_theme_missing', 400 );
		$this->assertSame( array(), $this->tickets );
	}

	/** Source: TSD 2.2.3, name, email, category (one of seven), subject, message and privacy consent required (organisation and agency are not in the list). */
	#[TestDox('A message without organisation or agency is accepted')]
	public function test_optional_fields_may_be_left_out(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$fields = $this->fields();
		unset( $fields['organization'], $fields['agency'] );

		$result = nsw_theme_handle_contact( $this->request( $fields ) );

		$this->assertAccepted( $result );
		$this->assertSame( '', $this->tickets[0]['organization'] );
		$this->assertSame( '', $this->tickets[0]['agency'] );
	}

	/** Source: TSD 2.4.2, unknown values fall back to General inquiry. */
	#[TestDox('A message with a category the form does not offer is still accepted and passed on')]
	public function test_unknown_category_accepted(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields( array( 'category' => 'not-a-category' ) ) ) );

		$this->assertAccepted( $result );
		$this->assertSame( 'not-a-category', $this->tickets[0]['category'] );
	}

	/* -------------------------------------------------------------------- email */

	/** Source: TSD 2.4.3, 422 email not valid after cleaning. */
	#[TestDox('A message with an email address that is not valid is refused (status 422)')]
	public function test_invalid_email_refused(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields( array( 'email' => 'jane.example.com' ) ) ) );

		$this->assertRefused( $result, 'nsw_theme_bad_email', 422 );
		$this->assertSame( 'Please enter a valid email.', $result->get_error_message() );
		$this->assertSame( array(), $this->tickets );
	}

	/* ----------------------------------------------------------- short texts */

	/** Source: TSD 2.4.3, 400 ... too short fields; the minimum of 3 characters is theme behaviour, not specified in the TSD. */
	#[TestDox('A subject of two characters is refused as too short (status 400), three characters are accepted')]
	public function test_subject_minimum_length(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$short = nsw_theme_handle_contact( $this->request( $this->fields( array( 'subject' => 'ab' ) ) ) );
		$this->transients = array();
		$ok = nsw_theme_handle_contact( $this->request( $this->fields( array( 'subject' => 'abc' ) ) ) );

		$this->assertRefused( $short, 'nsw_theme_short_subject', 400 );
		$this->assertSame( 'Subject is too short.', $short->get_error_message() );
		$this->assertAccepted( $ok );
		$this->assertCount( 1, $this->tickets );
	}

	/** Source: TSD 2.4.3, 400 ... too short fields; the minimum of 10 characters is theme behaviour, not specified in the TSD. */
	#[TestDox('A message text of nine characters is refused as too short (status 400), ten characters are accepted')]
	public function test_message_minimum_length(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$short = nsw_theme_handle_contact( $this->request( $this->fields( array( 'message' => 'Too short' ) ) ) );
		$this->transients = array();
		$ok = nsw_theme_handle_contact( $this->request( $this->fields( array( 'message' => 'Ten chars!' ) ) ) );

		$this->assertRefused( $short, 'nsw_theme_short_message', 400 );
		$this->assertSame( 'Message is too short.', $short->get_error_message() );
		$this->assertAccepted( $ok );
		$this->assertCount( 1, $this->tickets );
	}

	/* --------------------------------------------------------- maximum lengths */

	/** Source: TSD 2.2.3, Maximum lengths: name 120, email 200, organisation 150, subject 200, message 5,000. */
	#[TestDox('Texts of exactly the maximum length are passed on unchanged')]
	public function test_texts_at_maximum_length_unchanged(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$fields = $this->fields(
			array(
				'fullName'     => str_repeat( 'N', 120 ),
				'email'        => self::long_email(),
				'organization' => str_repeat( 'O', 150 ),
				'subject'      => str_repeat( 'S', 200 ),
				'message'      => str_repeat( 'M', 5000 ),
			)
		);
		$this->assertSame( 200, strlen( $fields['email'] ) );

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $fields ) ) );

		foreach ( array( 'fullName', 'email', 'organization', 'subject', 'message' ) as $key ) {
			$this->assertSame( $fields[ $key ], $this->tickets[0][ $key ], "$key changed" );
		}
	}

	/** Source: TSD 2.2.3, Maximum lengths ...; longer text is cut. */
	#[TestDox('Texts one character over the maximum length are cut to the maximum')]
	public function test_texts_over_maximum_length_cut(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$fields = $this->fields(
			array(
				'fullName'     => str_repeat( 'N', 121 ),
				'email'        => self::long_email( 1 ),
				'organization' => str_repeat( 'O', 151 ),
				'subject'      => str_repeat( 'S', 201 ),
				'message'      => str_repeat( 'M', 5001 ),
			)
		);

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $fields ) ) );

		$ticket = $this->tickets[0];
		$this->assertSame( str_repeat( 'N', 120 ), $ticket['fullName'] );
		$this->assertSame( substr( $fields['email'], 0, 200 ), $ticket['email'] );
		$this->assertSame( str_repeat( 'O', 150 ), $ticket['organization'] );
		$this->assertSame( str_repeat( 'S', 200 ), $ticket['subject'] );
		$this->assertSame( str_repeat( 'M', 5000 ), $ticket['message'] );
	}

	/** Source: TSD 2.4.3, Subject up to 200, message up to 5,000 characters; TSD 2.2.3, name 120. */
	#[TestDox('The maximum lengths count letters, not bytes, so Albanian letters such as ë and ç are not cut early')]
	public function test_lengths_count_characters_not_bytes(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$fields = $this->fields(
			array(
				'fullName' => str_repeat( 'ç', 121 ),
				'subject'  => str_repeat( 'ë', 200 ),
				'message'  => str_repeat( 'ë', 5001 ),
			)
		);

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $fields ) ) );

		$ticket = $this->tickets[0];
		$this->assertSame( str_repeat( 'ç', 120 ), $ticket['fullName'] );
		$this->assertSame( str_repeat( 'ë', 200 ), $ticket['subject'] );
		$this->assertSame( 5000, mb_strlen( $ticket['message'] ) );
	}

	/* ----------------------------------------------------------------- cleaning */

	/** Source: TSD 2.2.3, HTML tags stripped; line breaks removed from single-line fields. */
	#[TestDox('HTML tags are removed from the name, organisation, category, agency, subject and message, and line breaks from all of them except the message')]
	public function test_tags_and_line_breaks_cleaned(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$fields = $this->fields(
			array(
				'fullName'     => "<b>Jane</b>\nDoe",
				'organization' => "Example\r\nTrading",
				'category'     => "<b>technical</b>\n",
				'agency'       => "<i>customs</i>",
				'subject'      => "Cannot\r\nupload <em>file</em>",
				'message'      => "<b>Line one.</b>\nLine two.",
			)
		);

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $fields ) ) );

		$ticket = $this->tickets[0];
		$this->assertSame( 'Jane Doe', $ticket['fullName'] );
		$this->assertSame( 'Example Trading', $ticket['organization'] );
		$this->assertSame( 'technical', $ticket['category'] );
		$this->assertSame( 'customs', $ticket['agency'] );
		$this->assertSame( 'Cannot upload file', $ticket['subject'] );
		$this->assertSame( "Line one.\nLine two.", $ticket['message'] );
	}

	/** Source: TSD 2.4.2, multipart/form-data. */
	#[TestDox('A message posted in the standard web form format is handled the same way as one sent by the contact page')]
	public function test_form_encoded_request_accepted(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields(), 'valid-nonce', true ) );

		$this->assertAccepted( $result );
		$this->assertSame( 'Jane Doe', $this->tickets[0]['fullName'] );
		$this->assertSame( 'Cannot upload a document', $this->tickets[0]['subject'] );
	}

	/* --------------------------------------------------------------- rate limit */

	/** Source: TSD 2.3.3, nsw_theme_contact_<MD5 of visitor IP>, Transient: 30 s; TSD 2.3.4, only a 30-second rate-limit marker (hashed IP). */
	#[TestDox('An accepted message leaves only a 30-second marker named after a hash of the visitor address')]
	public function test_rate_limit_marker(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $this->fields() ) ) );

		$this->assertSame( array( self::marker( self::VISITOR_IP ) ), array_keys( $this->transients ) );
		$marker = $this->transients[ self::marker( self::VISITOR_IP ) ];
		$this->assertSame( 30, $marker['expiration'] );
		$stored = (string) json_encode( $marker ) . array_key_first( $this->transients );
		$this->assertStringNotContainsString( self::VISITOR_IP, $stored );
		$this->assertStringNotContainsString( 'jane@example.com', $stored );
		$this->assertStringNotContainsString( 'Jane Doe', $stored );
	}

	/** Source: TSD 2.2.3, One submission per IP every 30 seconds; TSD 2.4.3, 429 too many submissions. */
	#[TestDox('A second message from the same address within 30 seconds is refused (status 429)')]
	public function test_second_message_within_30_seconds_refused(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$first  = nsw_theme_handle_contact( $this->request( $this->fields() ) );
		$second = nsw_theme_handle_contact( $this->request( $this->fields( array( 'subject' => 'Second try' ) ) ) );

		$this->assertAccepted( $first );
		$this->assertRefused( $second, 'nsw_theme_rate_limited', 429 );
		$this->assertSame( 'Please wait a moment before sending again.', $second->get_error_message() );
		$this->assertCount( 1, $this->tickets );
	}

	/** Source: TSD 2.2.3, One submission per IP every 30 seconds. */
	#[TestDox('A message from another address is not held back by the first visitor\'s limit')]
	public function test_other_address_not_limited(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$first                  = nsw_theme_handle_contact( $this->request( $this->fields() ) );
		$_SERVER['REMOTE_ADDR'] = '2001:db8::7';
		$second                 = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertAccepted( $first );
		$this->assertAccepted( $second );
		$this->assertCount( 2, $this->tickets );
		$this->assertArrayHasKey( self::marker( '2001:db8::7' ), $this->transients );
	}

	/** Source: TSD 2.4.2, The web server must pass the real visitor IP to WordPress. */
	#[TestDox('A forwarded-address header sent by the visitor cannot be used to get around the limit')]
	public function test_forwarded_header_does_not_bypass_limit(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$first_request = $this->request( $this->fields() );
		$first_request->set_header( 'X-Forwarded-For', '198.51.100.1' );
		$first = nsw_theme_handle_contact( $first_request );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
		$second_request = $this->request( $this->fields() );
		$second_request->set_header( 'X-Forwarded-For', '198.51.100.2' );
		$second = nsw_theme_handle_contact( $second_request );

		$this->assertAccepted( $first );
		$this->assertRefused( $second, 'nsw_theme_rate_limited', 429 );
		$this->assertSame( array( self::marker( self::VISITOR_IP ) ), array_keys( $this->transients ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A visitor whose address is not known is still limited to one message every 30 seconds')]
	public function test_unknown_address_still_limited(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		unset( $_SERVER['REMOTE_ADDR'] );

		$first  = nsw_theme_handle_contact( $this->request( $this->fields() ) );
		$second = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertAccepted( $first );
		$this->assertRefused( $second, 'nsw_theme_rate_limited', 429 );
		$this->assertArrayHasKey( self::marker( 'unknown' ), $this->transients );
	}

	/* ------------------------------------------------------- Help Desk ticket */

	/** Source: TSD 2.2.3, Success: “Message sent”; TSD 2.4.2, confirmation with NSWAL-<id> after ticket creation. */
	#[TestDox('An accepted message creates a ticket, answers "Message sent" and emails the sender once')]
	public function test_accepted_message_creates_ticket(): void {
		$this->stub_wordpress();
		$this->help_desk_configured( array( 'id' => 42 ) );

		$result = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertAccepted( $result );
		$this->assertMatchesRegularExpression( '/^Message sent\.?$/', $result->get_data()['message'] );
		$this->assertSame( 'NSWAL-42', $result->get_data()['ticket'] );
		$this->assertSame(
			array(
				'fullName'     => 'Jane Doe',
				'email'        => 'jane@example.com',
				'organization' => 'Example Trading sh.p.k.',
				'category'     => 'technical',
				'agency'       => 'customs',
				'subject'      => 'Cannot upload a document',
				'message'      => "Line one.\nLine two.",
			),
			$this->tickets[0]
		);
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'jane@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'NSWAL-42', $this->mails[0]['subject'] );
	}

	/** Source: TSD 2.6.2, The theme logs failed Help Desk requests ... and failed emails. */
	#[TestDox('An accepted message writes nothing to the error log')]
	public function test_accepted_message_logs_nothing(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $this->fields() ) ) );

		$this->assertSame( array(), $this->logs );
	}

	/** Source: TSD 2.4.3, 502 Help Desk unavailable; TSD 2.6.1, the request is not recorded. */
	#[TestDox('When the Help Desk cannot create the ticket, its error (status 502) is returned and no email is sent')]
	public function test_help_desk_failure_returned(): void {
		$this->stub_wordpress();
		$error = new \WP_Error( 'nsw_theme_mantis_failed', 'Failed to create support request.', array( 'status' => 502 ) );
		$this->help_desk_configured( $error );

		$result = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertSame( $error, $result );
		$this->assertSame( array(), $this->mails );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the Help Desk gives no ticket number, the message counts as sent but no confirmation email is sent')]
	public function test_no_ticket_number_no_confirmation(): void {
		$this->stub_wordpress();
		$this->help_desk_configured( array( 'id' => 0 ) );

		$result = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertAccepted( $result );
		$this->assertSame( '', $result->get_data()['ticket'] );
		$this->assertSame( array(), $this->mails );
	}

	/** Source: TSD 2.4.2, Caller confirmation ... failure logged, request succeeds; TSD 2.6.2, errors are not shown to visitors. */
	#[TestDox('When the confirmation email breaks, the problem is logged, hidden from the visitor and the message still counts as sent')]
	public function test_confirmation_exception_logged_request_succeeds(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$this->mail_result = new \RuntimeException( 'SMTP connect() failed' );

		$result = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertAccepted( $result );
		$this->assertSame( 'NSWAL-42', $result->get_data()['ticket'] );
		$this->assertCount( 1, $this->logs );
		$this->assertStringContainsString( 'confirmation email failed', $this->logs[0] );
		$this->assertStringContainsString( 'SMTP connect() failed', $this->logs[0] );
		$this->assertStringNotContainsString( 'SMTP', (string) json_encode( $result->get_data() ) );
	}

	/** Source: TSD 2.4.2, Caller confirmation ... failure logged, request succeeds. */
	#[TestDox('When the mail server does not accept the confirmation email, the message still counts as sent')]
	public function test_confirmation_not_accepted_request_succeeds(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$this->mail_result = false;

		$result = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertAccepted( $result );
		$this->assertSame( 'NSWAL-42', $result->get_data()['ticket'] );
		$this->assertCount( 1, $this->mails );
	}

	/** Source: TSD 2.4.2, Caller confirmation ... failure logged; TSD 2.6.2, The theme logs ... failed emails. */
	#[Group('tsd-difference')]
	#[TestDox('When the mail server does not accept the confirmation email, the failure is logged')]
	public function test_confirmation_not_accepted_is_logged(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$this->mail_result = false;

		nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertCount( 1, $this->logs, 'The failed confirmation email was not logged.' );
	}

	/* -------------------------------------------------- Help Desk not set up */

	/** Source: TSD 2.4.2, Help Desk not configured: submission to the fallback recipient. */
	#[TestDox('Without Help Desk settings the message is emailed to the site administrator and counts as sent')]
	public function test_fallback_email_when_help_desk_not_configured(): void {
		$this->stub_wordpress();
		$this->help_desk_not_configured();
		$this->options['nsw_theme_contact_email'] = 'office@example.com';

		$result = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertAccepted( $result );
		$this->assertMatchesRegularExpression( '/^Message sent\.?$/', $result->get_data()['message'] );
		$this->assertSame( array(), $this->tickets );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'office@example.com', $this->mails[0]['to'] );
	}

	/** Source: TSD 2.4.2, Help Desk not configured ... failure returns HTTP 500. */
	#[TestDox('Without Help Desk settings, a failed email to the administrator is reported to the visitor (status 500)')]
	public function test_fallback_email_failure_returns_500(): void {
		$this->stub_wordpress();
		$this->help_desk_not_configured();
		$this->mail_result = false;

		$result = nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertRefused( $result, 'nsw_theme_mail_failed', 500 );
		$this->assertSame( 'Could not send the message. Please try again later.', $result->get_error_message() );
	}

	/** Source: TSD 2.6.2, The theme logs ... failed emails. */
	#[Group('tsd-difference')]
	#[TestDox('Without Help Desk settings, a failed email to the administrator is logged')]
	public function test_fallback_email_failure_is_logged(): void {
		$this->stub_wordpress();
		$this->help_desk_not_configured();
		$this->mail_result = false;

		nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertCount( 1, $this->logs, 'The failed email to the administrator was not logged.' );
	}

	/* --------------------------------------------------------------- attachment */

	public static function refused_types(): array {
		return array(
			'program'  => array( 'setup.exe' ),
			'archive'  => array( 'documents.zip' ),
			'web page' => array( 'page.html' ),
		);
	}

	/** Source: TSD 2.2.3, Optional attachment: one file (pdf, doc, docx, jpg, jpeg, png) ... attachment type ... checked on the server. */
	#[Group('tsd-difference')]
	#[DataProvider('refused_types')]
	#[TestDox('An attachment named $name is refused (status 400) and no ticket is created')]
	public function test_attachment_of_wrong_type_refused( string $name ): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$files = array( 'attachment' => $this->upload( $name, 2048 ) );

		$result = nsw_theme_handle_contact( $this->request_with_attachment( $files ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An attachment of a type not allowed was accepted.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertSame( array(), $this->tickets );
	}

	/** Source: TSD 2.2.3, Optional attachment ... up to 10 MB ... size ... checked on the server. */
	#[Group('tsd-difference')]
	#[TestDox('An attachment one byte larger than 10 MB is refused (status 400) and no ticket is created')]
	public function test_attachment_over_10_mb_refused(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$files = array( 'attachment' => $this->upload( 'scan.pdf', 10 * 1024 * 1024 + 1 ) );

		$result = nsw_theme_handle_contact( $this->request_with_attachment( $files ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An attachment over 10 MB was accepted.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertSame( array(), $this->tickets );
	}

	public static function allowed_types(): array {
		return array(
			'pdf'  => array( 'pdf' ),
			'doc'  => array( 'doc' ),
			'docx' => array( 'docx' ),
			'jpg'  => array( 'jpg' ),
			'jpeg' => array( 'jpeg' ),
			'png'  => array( 'png' ),
		);
	}

	/** Source: TSD 2.2.3, Optional attachment: one file (pdf, doc, docx, jpg, jpeg, png) ... forwarded; TSD 2.4.2, base64 in the files element. */
	#[Group('tsd-difference')]
	#[DataProvider('allowed_types')]
	#[TestDox('A $type attachment is accepted and forwarded to the Help Desk with the ticket')]
	public function test_allowed_attachment_forwarded( string $type ): void {
		$this->stub_wordpress();
		$this->help_desk_over_the_network();
		$content = self::MAGIC[ $type ] . 'Invented test file content.';
		$files   = array( 'attachment' => $this->upload( "evidence.$type", strlen( $content ), $content ) );

		$result = nsw_theme_handle_contact( $this->request_with_attachment( $files ) );

		$this->assertAccepted( $result );
		$this->assertCount( 1, $this->help_desk_calls );
		$body = $this->help_desk_calls[0]['body'];
		$this->assertArrayHasKey( 'files', $body, 'The attachment was not forwarded to the Help Desk.' );
		$this->assertCount( 1, $body['files'] );
		$this->assertSame( "evidence.$type", $body['files'][0]['name'] ?? null );
		$this->assertSame( base64_encode( $content ), $body['files'][0]['content'] ?? null );
	}

	/** Source: TSD 2.2.3, Optional attachment ... up to 10 MB, forwarded, not stored. */
	#[Group('tsd-difference')]
	#[TestDox('An attachment of exactly 10 MB is accepted and forwarded to the Help Desk with the ticket')]
	public function test_attachment_of_exactly_10_mb_forwarded(): void {
		$this->stub_wordpress();
		$this->help_desk_over_the_network();
		$size  = 10 * 1024 * 1024;
		$files = array( 'attachment' => $this->upload( 'scan.pdf', $size ) );

		$result = nsw_theme_handle_contact( $this->request_with_attachment( $files ) );

		$this->assertAccepted( $result );
		$this->assertCount( 1, $this->help_desk_calls );
		$body = $this->help_desk_calls[0]['body'];
		$this->assertArrayHasKey( 'files', $body, 'The attachment was not forwarded to the Help Desk.' );
		$this->assertCount( 1, $body['files'] );
		$this->assertSame(
			sha1_file( $files['attachment']['tmp_name'] ),
			sha1( (string) base64_decode( (string) ( $body['files'][0]['content'] ?? '' ), true ) )
		);
	}

	public static function content_not_matching_name(): array {
		return array(
			'web page named .pdf'     => array( 'scan.pdf', "<!doctype html><script>alert(1)</script>" ),
			'program named .docx'     => array( 'letter.docx', "MZ\x90\x00Invented test program." ),
			'PNG image named .jpg'    => array( 'photo.jpg', "\x89PNG\r\n\x1a\nInvented test image." ),
			'ZIP archive named .docx' => array( 'documents.docx', "PK\x03\x04\x14\0\0\0\x08\0\0\0\x21\0\0\0\0\0\0\0\0\0\0\0\0\0\x09\0\0\0setup.exeInvented test archive." ),
			'spreadsheet named .doc'  => array( 'table.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1R\0o\0o\0t\0 \0E\0n\0t\0r\0y\0\0\0W\0o\0r\0k\0b\0o\0o\0k\0\0\0Invented test spreadsheet." ),
		);
	}

	/** Source: TSD 2.2.3, attachment type ... checked on the server; TSD 2.5.2, the theme sanitises input. */
	#[DataProvider('content_not_matching_name')]
	#[TestDox('An attachment whose content is not what its name says ($name) is refused (status 400), whatever type the browser reports')]
	public function test_attachment_content_must_match_name( string $name, string $content ): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$files = array( 'attachment' => $this->upload( $name, strlen( $content ), $content ) );

		$result = nsw_theme_handle_contact( $this->request_with_attachment( $files ) );

		$this->assertRefused( $result, 'nsw_theme_attachment_type', 400 );
		$this->assertSame( 'The attachment must be a PDF, DOC, DOCX, JPG, JPEG or PNG file.', $result->get_error_message() );
		$this->assertSame( array(), $this->tickets );
	}

	/** Source: TSD 2.5.2, the theme sanitises input; the file must be one PHP received with this request. */
	#[TestDox('A file that did not arrive with the request is refused (status 400) and its content is not sent')]
	public function test_file_not_uploaded_with_request_refused(): void {
		$this->stub_wordpress();
		$this->help_desk_over_the_network();
		$files = array( 'attachment' => $this->upload( 'evidence.pdf', 64, "%PDF-1.4\nInvented test file content." ) );
		$this->php_received( array() );

		$result = nsw_theme_handle_contact( $this->request( $this->fields(), 'valid-nonce', true, $files ) );

		$this->assertRefused( $result, 'nsw_theme_attachment_failed', 400 );
		$this->assertSame( array(), $this->help_desk_calls );
	}

	/** Source: TSD 2.2.3, Optional attachment. */
	#[TestDox('A form sent with the file field left empty is accepted and the ticket carries no file')]
	public function test_empty_file_field_means_no_attachment(): void {
		$this->stub_wordpress();
		$this->help_desk_over_the_network();
		$files = array(
			'attachment' => array( 'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 ),
		);

		$result = nsw_theme_handle_contact( $this->request_with_attachment( $files ) );

		$this->assertAccepted( $result );
		$this->assertCount( 1, $this->help_desk_calls );
		$this->assertArrayNotHasKey( 'files', $this->help_desk_calls[0]['body'] );
	}

	/** Source: TSD 2.2.3, up to 10 MB ... size checked on the server; TSD 2.4.3, upload_max_filesize 10M. */
	#[TestDox('A file that PHP refused as larger than its upload limit is refused as too large (status 400)')]
	public function test_file_over_php_upload_limit_refused(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$files = array(
			'attachment' => array( 'name' => 'scan.pdf', 'type' => 'application/pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ),
		);

		$result = nsw_theme_handle_contact( $this->request_with_attachment( $files ) );

		$this->assertRefused( $result, 'nsw_theme_attachment_size', 400 );
		$this->assertSame( 'The attachment must not be larger than 10 MB.', $result->get_error_message() );
		$this->assertSame( array(), $this->tickets );
	}

	/** Source: TSD 2.2.3, forwarded, not stored; TSD 2.3, contact form submissions are not stored. */
	#[TestDox('An accepted attachment is not stored on the website: it stays in the temporary upload folder and is not added to the media library')]
	public function test_attachment_not_stored(): void {
		$this->stub_wordpress();
		$this->help_desk_over_the_network();
		Functions\expect( 'wp_handle_upload' )->never();
		Functions\expect( 'media_handle_upload' )->never();
		Functions\expect( 'wp_upload_bits' )->never();
		Functions\expect( 'wp_insert_attachment' )->never();
		$content = "%PDF-1.4\nInvented test file content.";
		$files   = array( 'attachment' => $this->upload( 'evidence.pdf', strlen( $content ), $content ) );

		$this->assertAccepted( nsw_theme_handle_contact( $this->request_with_attachment( $files ) ) );

		$this->assertFileExists( $files['attachment']['tmp_name'] );
		$this->assertSame( $content, file_get_contents( $files['attachment']['tmp_name'] ) );
	}

	/** Source: TSD 2.5.2, the theme sanitises input. */
	#[TestDox('The file name the browser sends is cleaned before it is passed on')]
	public function test_attachment_name_cleaned(): void {
		$this->stub_wordpress();
		$this->help_desk_over_the_network();
		$content = "%PDF-1.4\nInvented test file content.";
		$files   = array( 'attachment' => $this->upload( 'My scan <b>1</b>.pdf', strlen( $content ), $content ) );

		$this->assertAccepted( nsw_theme_handle_contact( $this->request_with_attachment( $files ) ) );

		$this->assertSame( 'My-scan-b1b.pdf', $this->help_desk_calls[0]['body']['files'][0]['name'] );
	}

	/** Source: TSD 2.4.2, Help Desk not configured: submission to the fallback recipient; TSD 2.2.3, attachment forwarded, not stored. */
	#[TestDox('Without Help Desk settings the attachment goes with the email to the administrator, under its own name')]
	public function test_fallback_email_carries_attachment(): void {
		$this->stub_wordpress();
		$this->help_desk_not_configured();
		$attached = null;
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message, $headers = '', $attachments = array() ) use ( &$attached ) {
				$attached = $attachments;
				return true;
			}
		);
		$content = "\x89PNG\r\n\x1a\nInvented test image.";
		$files   = array( 'attachment' => $this->upload( 'screen.png', strlen( $content ), $content ) );

		$this->assertAccepted( nsw_theme_handle_contact( $this->request_with_attachment( $files ) ) );

		$this->assertSame( array( 'screen.png' => $files['attachment']['tmp_name'] ), $attached );
	}

	/* ---------------------------------------------------------- consent values */

	public static function consent_not_given(): array {
		return array(
			'off'   => array( 'off' ),
			'0'     => array( '0' ),
			'false' => array( 'false' ),
			'no'    => array( 'no' ),
		);
	}

	/** Source: TSD 2.2.3, privacy consent required ... checked on the server. */
	#[DataProvider('consent_not_given')]
	#[TestDox('A message whose consent field says "$value" is refused (status 400) with the consent message')]
	public function test_consent_not_given_refused( string $value ): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$result = nsw_theme_handle_contact( $this->request( $this->fields( array( 'privacy' => $value ) ) ) );

		$this->assertRefused( $result, 'nsw_theme_no_consent', 400 );
		$this->assertSame( 'You must accept the privacy policy', $result->get_error_message() );
		$this->assertSame( array(), $this->tickets );
	}

	/** Source: TSD 2.4.2, multipart/form-data; a ticked checkbox sends "on". */
	#[TestDox('A message posted as a web form with the consent box ticked is accepted')]
	public function test_consent_from_web_form_accepted(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $this->fields(), 'valid-nonce', true ) ) );
	}

	/** Source: TSD 2.2.3, privacy consent required ... checked on the server. */
	#[TestDox('A JSON message whose consent field is true is accepted, and one whose consent field is false is refused (status 400)')]
	public function test_consent_as_json_true_or_false(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();

		$this->assertAccepted( nsw_theme_handle_contact( $this->request( $this->fields( array( 'privacy' => true ) ) ) ) );
		$this->transients = array();
		$this->assertRefused( nsw_theme_handle_contact( $this->request( $this->fields( array( 'privacy' => false ) ) ) ), 'nsw_theme_no_consent', 400 );
		$this->assertCount( 1, $this->tickets );
	}

	/** Source: TSD 2.2.3, privacy consent required ... checked on the server; TSD 2.4.2, multipart/form-data. */
	#[TestDox('The web address lets the consent field through to the form check, as text from the web form or as true or false from a JSON client')]
	public function test_route_lets_consent_through(): void {
		$args = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $options ) use ( &$args ) {
				$args = $options['args'];
				return true;
			}
		);

		( $this->hook( 'rest_api_init' ) )();

		$this->assertFalse( $args['privacy']['required'] ?? false );
		$this->assertContains( 'string', (array) $args['privacy']['type'] );
		$this->assertContains( 'boolean', (array) $args['privacy']['type'] );
	}

	/* ------------------------------------------------------------ log privacy */

	/** Source: TSD 2.6.2, failed emails are logged; TSD 2.5.3, contact submissions are not stored. */
	#[TestDox('The log line for a failed email names the ticket but not the sender')]
	public function test_failed_email_log_has_no_personal_data(): void {
		$this->stub_wordpress();
		$this->help_desk_configured();
		$this->mail_result = false;

		nsw_theme_handle_contact( $this->request( $this->fields() ) );

		$this->assertCount( 1, $this->logs );
		$this->assertStringContainsString( 'confirmation email failed', $this->logs[0] );
		$this->assertStringContainsString( 'NSWAL-42', $this->logs[0] );
		$this->assertStringNotContainsString( 'jane@example.com', $this->logs[0] );
		$this->assertStringNotContainsString( 'Jane Doe', $this->logs[0] );
	}

	/* ------------------------------------------------------ confirmation email */

	private function confirmation_data( array $overrides = array() ): array {
		$fields = $this->fields( $overrides );
		unset( $fields['privacy'], $fields['website'] );
		return $fields;
	}

	/** Source: TSD 2.4.2, Confirmation: caller name, NSWAL-<id>, subject, message. */
	#[TestDox('The confirmation email goes to the sender and shows the name, the reference, the subject and the message')]
	public function test_confirmation_content(): void {
		$this->stub_wordpress();

		$sent = nsw_theme_contact_send_confirmation_email( $this->confirmation_data(), 'NSWAL-42' );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$this->assertSame( 'jane@example.com', $mail['to'] );
		$this->assertSame( "[NSWAL-42] We've received your message", $mail['subject'] );
		$this->assertStringContainsString( 'Hi Jane Doe,', $mail['message'] );
		$this->assertStringContainsString( '<strong>NSWAL-42</strong>', $mail['message'] );
		$this->assertStringContainsString( '<strong>Example Portal</strong>', $mail['message'] );
		$this->assertStringContainsString( 'Cannot upload a document', $mail['message'] );
		$this->assertStringContainsString( "Line one.\nLine two.", $mail['message'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $mail['headers'] );
	}

	/** Source: TSD 2.5.2, The theme sanitises input and escapes output. */
	#[TestDox('HTML typed by the sender is shown as text in the confirmation email, never run')]
	public function test_confirmation_escapes_sender_text(): void {
		$this->stub_wordpress();

		nsw_theme_contact_send_confirmation_email(
			$this->confirmation_data(
				array(
					'fullName' => '<b>Jane</b>',
					'subject'  => '<img src=x onerror=alert(1)>',
					'message'  => '<script>alert("x")</script>',
				)
			),
			'NSWAL-42'
		);

		$body = $this->mails[0]['message'];
		$this->assertStringContainsString( 'Hi &lt;b&gt;Jane&lt;/b&gt;,', $body );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $body );
		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $body );
		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringNotContainsString( '<img', $body );
	}

	/** Source: TSD 2.4.2, Confirmation: caller name, NSWAL-<id>, subject, message; no response time; TSD 2.2.2, no response time. */
	#[Group('tsd-difference')]
	#[TestDox('The confirmation email promises no response time')]
	public function test_confirmation_has_no_response_time(): void {
		$this->stub_wordpress();

		nsw_theme_contact_send_confirmation_email( $this->confirmation_data(), 'NSWAL-42' );

		$body = $this->mails[0]['message'];
		$this->assertDoesNotMatchRegularExpression( '/respond within|\b\d+\s*(?:hours?|days?|working days)\b/i', $body );
	}

	/** Source: TSD 2.3.3, confirmation From ... and Reply-To. */
	#[TestDox('The confirmation email uses the From and Reply-To addresses set in the settings')]
	public function test_confirmation_from_and_reply_to_from_settings(): void {
		$this->stub_wordpress();
		$this->config = array(
			'contact_from'     => 'noreply@example.com',
			'contact_reply_to' => 'helpdesk@example.com',
			'smtp_user'        => 'mailer@example.com',
		);

		nsw_theme_contact_send_confirmation_email( $this->confirmation_data(), 'NSWAL-42' );

		$headers = $this->mails[0]['headers'];
		$this->assertContains( 'From: "Example Portal" <noreply@example.com>', $headers );
		$this->assertContains( 'Reply-To: helpdesk@example.com', $headers );
	}

	/** Source: TSD 2.3.3, confirmation From (default: SMTP user ...) and Reply-To (default: From). */
	#[TestDox('Without a From setting the confirmation comes from the mail server account, and replies go to that address')]
	public function test_confirmation_from_defaults_to_smtp_user(): void {
		$this->stub_wordpress();
		$this->config = array( 'smtp_user' => 'mailer@example.com' );

		nsw_theme_contact_send_confirmation_email( $this->confirmation_data(), 'NSWAL-42' );

		$headers = $this->mails[0]['headers'];
		$this->assertContains( 'From: "Example Portal" <mailer@example.com>', $headers );
		$this->assertContains( 'Reply-To: mailer@example.com', $headers );
	}

	/** Source: TSD 2.3.3, confirmation From (default: SMTP user, then admin email). */
	#[TestDox('Without a From setting or mail server account the confirmation comes from the site administrator address')]
	public function test_confirmation_from_falls_back_to_admin_email(): void {
		$this->stub_wordpress();

		nsw_theme_contact_send_confirmation_email( $this->confirmation_data(), 'NSWAL-42' );

		$headers = $this->mails[0]['headers'];
		$this->assertContains( 'From: "Example Portal" <admin@example.com>', $headers );
		$this->assertContains( 'Reply-To: admin@example.com', $headers );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Quotation marks in the site name are left out of the sender name of the confirmation')]
	public function test_confirmation_sender_name_without_quotes(): void {
		$this->stub_wordpress();
		$this->site_name = 'Example &quot;Portal&quot; &amp; Co';

		nsw_theme_contact_send_confirmation_email( $this->confirmation_data(), 'NSWAL-42' );

		$this->assertContains( 'From: "Example Portal & Co" <admin@example.com>', $this->mails[0]['headers'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Sending the confirmation reports whether the mail server accepted the email')]
	public function test_confirmation_reports_mail_result(): void {
		$this->stub_wordpress();
		$this->mail_result = false;

		$this->assertFalse( nsw_theme_contact_send_confirmation_email( $this->confirmation_data(), 'NSWAL-42' ) );
	}

	/* ------------------------------------------------- email to administrator */

	/** Source: TSD 2.4.2, Fallback: all form fields, Reply-To the caller; TSD 2.3.3, fallback recipient. */
	#[TestDox('The email to the administrator holds every form field and replies go to the sender')]
	public function test_admin_notification_content(): void {
		$this->stub_wordpress();
		$this->options['nsw_theme_contact_email'] = 'office@example.com';

		$sent = nsw_theme_contact_send_admin_notification( $this->confirmation_data() );

		$this->assertTrue( $sent );
		$mail = $this->mails[0];
		$this->assertSame( 'office@example.com', $mail['to'] );
		$this->assertSame( '[Example Portal] Cannot upload a document', $mail['subject'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $mail['headers'] );
		$reply_to = array_values( array_filter( $mail['headers'], static fn( $h ) => str_starts_with( $h, 'Reply-To:' ) ) );
		$this->assertCount( 1, $reply_to );
		$this->assertStringContainsString( 'Jane Doe', $reply_to[0] );
		$this->assertStringContainsString( '<jane@example.com>', $reply_to[0] );
		foreach ( array( 'Name', 'Jane Doe', 'Email', 'jane@example.com', 'Organization', 'Example Trading sh.p.k.', 'Category', 'technical', 'Agency', 'customs', 'Subject', 'Cannot upload a document', 'Message', 'Line one.' ) as $text ) {
			$this->assertStringContainsString( ">$text", $mail['message'] );
		}
	}

	/** Source: TSD 2.3.3, Fallback recipient (default: site admin email). */
	#[TestDox('Without a recipient setting, or with an empty one, the email goes to the site administrator address')]
	public function test_admin_notification_default_recipient(): void {
		$this->stub_wordpress();

		nsw_theme_contact_send_admin_notification( $this->confirmation_data() );
		$this->options['nsw_theme_contact_email'] = '';
		nsw_theme_contact_send_admin_notification( $this->confirmation_data() );

		$this->assertSame( 'admin@example.com', $this->mails[0]['to'] );
		$this->assertSame( 'admin@example.com', $this->mails[1]['to'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Optional fields left blank are left out of the email to the administrator')]
	public function test_admin_notification_skips_blank_fields(): void {
		$this->stub_wordpress();

		nsw_theme_contact_send_admin_notification( $this->confirmation_data( array( 'organization' => '', 'agency' => '' ) ) );

		$body = $this->mails[0]['message'];
		$this->assertStringNotContainsString( '>Organization<', $body );
		$this->assertStringNotContainsString( '>Agency<', $body );
		$this->assertStringContainsString( '>Name<', $body );
	}

	/** Source: TSD 2.5.2, The theme sanitises input and escapes output. */
	#[TestDox('In the email to the administrator, HTML typed by the sender is shown as text and line breaks are kept')]
	public function test_admin_notification_escapes_and_keeps_line_breaks(): void {
		$this->stub_wordpress();

		nsw_theme_contact_send_admin_notification(
			$this->confirmation_data(
				array(
					'organization' => '<b>Example</b>',
					'message'      => "<script>alert(1)</script>\nSecond line.",
				)
			)
		);

		$body = $this->mails[0]['message'];
		$this->assertStringContainsString( '&lt;b&gt;Example&lt;/b&gt;', $body );
		$this->assertStringContainsString( "&lt;script&gt;alert(1)&lt;/script&gt;<br />\nSecond line.", $body );
		$this->assertStringNotContainsString( '<script>', $body );
	}

	/** Source: TSD 2.2.3, line breaks removed from single-line fields. */
	#[TestDox('Line breaks in the sender name cannot add extra lines to the email to the administrator')]
	public function test_admin_notification_reply_to_without_line_breaks(): void {
		$this->stub_wordpress();

		nsw_theme_contact_send_admin_notification(
			$this->confirmation_data( array( 'fullName' => "Jane\r\nBcc: someone@example.com" ) )
		);

		foreach ( $this->mails[0]['headers'] as $header ) {
			$this->assertStringNotContainsString( "\r", $header );
			$this->assertStringNotContainsString( "\n", $header );
		}
		$this->assertCount( 2, $this->mails[0]['headers'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Sending the email to the administrator reports whether the mail server accepted it')]
	public function test_admin_notification_reports_mail_result(): void {
		$this->stub_wordpress();
		$this->mail_result = false;

		$this->assertFalse( nsw_theme_contact_send_admin_notification( $this->confirmation_data() ) );
	}

	/* ------------------------------------------------------------- mail server */

	/** Source: TSD 2.4.2, SMTP over SSL ... or PHP mail(). */
	#[TestDox('Without mail server settings, website email is sent by the web server\'s own mail function')]
	public function test_smtp_not_used_without_settings(): void {
		$this->stub_config();
		$mailer = $this->mailer();

		( $this->hook( 'phpmailer_init' ) )( $mailer );

		$this->assertFalse( $mailer->smtp );
		$this->assertNull( $mailer->Host );
		$this->assertNull( $mailer->Password );
	}

	/** Source: TSD 2.4.2, SMTP over SSL (port 465 by default) without certificate checks. */
	#[TestDox('With mail server settings, website email goes through that server over SSL on port 465, signed in, without certificate checks')]
	public function test_smtp_over_ssl_by_default(): void {
		$this->stub_config();
		$this->config = array(
			'smtp_host' => 'mail.example.com',
			'smtp_user' => 'portal@example.com',
			'smtp_pass' => 'invented-test-password',
		);
		$mailer = $this->mailer();

		( $this->hook( 'phpmailer_init' ) )( $mailer );

		$this->assertTrue( $mailer->smtp );
		$this->assertSame( 'mail.example.com', $mailer->Host );
		$this->assertSame( 465, $mailer->Port );
		$this->assertSame( 'ssl', $mailer->SMTPSecure );
		$this->assertTrue( $mailer->SMTPAuth );
		$this->assertSame( 'portal@example.com', $mailer->Username );
		$this->assertSame( 'invented-test-password', $mailer->Password );
		$this->assertSame(
			array( 'ssl' => array( 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true ) ),
			$mailer->SMTPOptions
		);
	}

	/** Source: TSD 2.4.2, STARTTLS on other ports, also without certificate checks. */
	#[TestDox('On a port other than 465, website email uses STARTTLS, also without certificate checks')]
	public function test_smtp_starttls_on_other_port(): void {
		$this->stub_config();
		$this->config = array(
			'smtp_host' => 'mail.example.com',
			'smtp_user' => 'portal@example.com',
			'smtp_pass' => 'invented-test-password',
			'smtp_port' => '587',
		);
		$mailer = $this->mailer();

		( $this->hook( 'phpmailer_init' ) )( $mailer );

		$this->assertSame( 587, $mailer->Port );
		$this->assertSame( 'tls', $mailer->SMTPSecure );
		$this->assertFalse( $mailer->SMTPOptions['ssl']['verify_peer'] );
		$this->assertFalse( $mailer->SMTPOptions['ssl']['verify_peer_name'] );
	}

	public static function incomplete_smtp_settings(): array {
		return array(
			'address'  => array( 'address', 'smtp_host' ),
			'account'  => array( 'account', 'smtp_user' ),
			'password' => array( 'password', 'smtp_pass' ),
		);
	}

	/** Source: SDP 5.5, without host, user and password, PHP mail(). */
	#[DataProvider('incomplete_smtp_settings')]
	#[TestDox('When the mail server $label is missing, website email is sent by the web server\'s own mail function')]
	public function test_smtp_not_used_when_a_setting_is_missing( string $label, string $missing ): void {
		$this->stub_config();
		$this->config = array(
			'smtp_host' => 'mail.example.com',
			'smtp_user' => 'portal@example.com',
			'smtp_pass' => 'invented-test-password',
		);
		unset( $this->config[ $missing ] );
		$mailer = $this->mailer();

		( $this->hook( 'phpmailer_init' ) )( $mailer );

		$this->assertFalse( $mailer->smtp );
		$this->assertNull( $mailer->Password );
	}

	/** Source: TSD 2.4.2, SMTP over SSL, port 465 by default. */
	#[TestDox('An empty mail server port falls back to port 465 over SSL')]
	public function test_smtp_empty_port_falls_back_to_465(): void {
		$this->stub_config();
		$this->config = array(
			'smtp_host' => 'mail.example.com',
			'smtp_user' => 'portal@example.com',
			'smtp_pass' => 'invented-test-password',
			'smtp_port' => '',
		);
		$mailer = $this->mailer();

		( $this->hook( 'phpmailer_init' ) )( $mailer );

		$this->assertSame( 465, $mailer->Port );
		$this->assertSame( 'ssl', $mailer->SMTPSecure );
	}

	/** Source: TSD 2.4.2, SMTP credentials are wp-config.php constants; SDP 5.5, NSW_THEME_SMTP_HOST, NSW_THEME_SMTP_USER, NSW_THEME_SMTP_PASS. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Mail server settings defined in the site configuration file switch website email to that server')]
	public function test_smtp_settings_from_wp_config(): void {
		define( 'NSW_THEME_SMTP_HOST', 'mail.example.com' );
		define( 'NSW_THEME_SMTP_USER', 'portal@example.com' );
		define( 'NSW_THEME_SMTP_PASS', 'invented-test-password' );
		Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => $default );
		$mailer = $this->mailer();

		( $this->hook( 'phpmailer_init' ) )( $mailer );

		$this->assertTrue( $mailer->smtp );
		$this->assertSame( 'mail.example.com', $mailer->Host );
		$this->assertSame( 'portal@example.com', $mailer->Username );
		$this->assertSame( 'invented-test-password', $mailer->Password );
		$this->assertSame( 465, $mailer->Port );
	}

	/** Source: TSD 2.5.3, Secrets (... SMTP credentials) are only in wp-config.php, not in the database. */
	#[Group('tsd-difference')]
	#[TestDox('The mail server password is never taken from the database')]
	public function test_smtp_password_not_read_from_database(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				$stored = array(
					'nsw_theme_smtp_host' => 'mail.example.com',
					'nsw_theme_smtp_user' => 'portal@example.com',
					'nsw_theme_smtp_pass' => 'password-in-the-database',
				);
				return $stored[ $name ] ?? $default;
			}
		);
		$mailer = $this->mailer();

		( $this->hook( 'phpmailer_init' ) )( $mailer );

		$this->assertNotSame( 'password-in-the-database', $mailer->Password, 'The SMTP password was read from the database.' );
	}

	/* ---------------------------------------------------------------- settings */

	/** Source: TSD 2.3.3, nsw_theme_contact_email, nsw_theme_contact_from, nsw_theme_contact_reply_to, String (email), Optional. */
	#[TestDox('The settings screen stores the administrator recipient, From and Reply-To as email addresses that the website never publishes')]
	public function test_settings_registered(): void {
		$found = $this->run_settings_setup();

		$this->assertSame(
			array( 'nsw_theme_contact_email', 'nsw_theme_contact_from', 'nsw_theme_contact_reply_to' ),
			array_keys( $found['settings'] )
		);
		foreach ( $found['settings'] as $name => $setting ) {
			$this->assertSame( 'general', $setting['group'], $name );
			$this->assertSame( 'string', $setting['type'], $name );
			$this->assertSame( 'sanitize_email', $setting['sanitize_callback'], $name );
			$this->assertFalse( $setting['show_in_rest'], $name );
			$this->assertSame( '', $setting['default'], $name );
		}
	}

	/** Source: SDP 5.5, Settings, General, NSW Contact Form; SDP 5.4, Settings, General: MantisBT configured. */
	#[TestDox('The General settings screen has a contact form section saying that messages create Help Desk tickets when it is set up')]
	public function test_settings_section_when_configured(): void {
		$found = $this->run_settings_setup();
		Functions\when( 'nsw_theme_contact_mantis_configured' )->justReturn( true );

		$section = $found['sections']['nsw_theme_contact_section'];
		$html    = $this->render( $section['callback'] );

		$this->assertSame( 'NSW Contact Form', $section['title'] );
		$this->assertSame( 'general', $section['page'] );
		$this->assertStringContainsString( 'Status:', $html );
		$this->assertStringContainsString( 'MantisBT is configured', $html );
		$this->assertStringNotContainsString( 'NOT configured', $html );
	}

	/** Source: TSD 2.2.1, Without Help Desk settings, emails the submission to the site administrator. */
	#[TestDox('The contact form section warns that messages go to the administrator when the Help Desk is not set up')]
	public function test_settings_section_when_not_configured(): void {
		$found = $this->run_settings_setup();
		Functions\when( 'nsw_theme_contact_mantis_configured' )->justReturn( false );

		$html = $this->render( $found['sections']['nsw_theme_contact_section']['callback'] );

		$this->assertStringContainsString( 'MantisBT NOT configured', $html );
		$this->assertStringContainsString( 'fall back to admin email', $html );
	}

	/** Source: TSD 2.3.3, Fallback recipient ...; confirmation From ... and Reply-To. */
	#[TestDox('The contact form section has three fields: administrator recipient, confirmation From and confirmation Reply-To')]
	public function test_settings_fields(): void {
		$found = $this->run_settings_setup();

		$this->assertSame(
			array(
				'nsw_theme_contact_email'    => 'Admin recipient',
				'nsw_theme_contact_from'     => 'Confirmation email From',
				'nsw_theme_contact_reply_to' => 'Confirmation email Reply-To',
			),
			array_map( static fn( $field ) => $field['title'], $found['fields'] )
		);
		foreach ( $found['fields'] as $id => $field ) {
			$this->assertSame( 'general', $field['page'], $id );
			$this->assertSame( 'nsw_theme_contact_section', $field['section'], $id );
		}
	}

	/** Source: TSD 2.5.2, The theme sanitises input and escapes output. */
	#[TestDox('A settings field shows the saved address safely, with its help text')]
	public function test_settings_field_renders_saved_value(): void {
		$found = $this->run_settings_setup();
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'nsw_theme_contact_from' === $name ? 'no"reply@example.com' : $default
		);

		$html = $this->render( $found['fields']['nsw_theme_contact_from']['callback'] );

		$this->assertStringContainsString( 'name="nsw_theme_contact_from"', $html );
		$this->assertStringContainsString( 'id="nsw_theme_contact_from"', $html );
		$this->assertStringContainsString( 'value="no&quot;reply@example.com"', $html );
		$this->assertStringContainsString( '<p class="description">Sender of the customer confirmation. Defaults to SMTP user, then admin email.</p>', $html );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A settings field with nothing saved shows an empty box')]
	public function test_settings_field_renders_empty(): void {
		$found = $this->run_settings_setup();
		Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => $default );

		$html = $this->render( $found['fields']['nsw_theme_contact_email']['callback'] );

		$this->assertStringContainsString( 'value=""', $html );
	}
}
