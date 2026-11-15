<?php
/**
 * Unit tests for inc/contact-mantis.php: the website's client for the Help Desk.
 *
 * It turns a contact form request into a Help Desk ticket (MantisBT REST API). The
 * Help Desk itself is never called: every reply is simulated.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * section 2.4 (Help Desk interface) and 2.5 (secrets). Where today's code differs from
 * the TSD, the test expects the TSD and is in the group "tsd-difference".
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class ContactMantisTest extends TestCase {

	/** A valid contact form request, as the form handler passes it on. */
	private function request( array $overrides = array() ): array {
		return array_merge(
			array(
				'fullName'     => 'Jane Doe',
				'email'        => 'jane@example.com',
				'organization' => 'ACME sh.p.k.',
				'category'     => 'technical',
				'agency'       => 'customs',
				'subject'      => 'Cannot upload LPCO',
				'message'      => "Line one.\nLine two.",
			),
			$overrides
		);
	}

	/** @return array<string, string> custom field name => value */
	private function fields_by_name( array $custom_fields ): array {
		$by_name = array();
		foreach ( $custom_fields as $row ) {
			$by_name[ $row['field']['name'] ] = $row['value'];
		}
		return $by_name;
	}

	/** Defines the three wp-config.php constants, as on a configured server. */
	private function define_help_desk_constants(): void {
		define( 'NSW_THEME_MANTIS_URL', 'https://support.nsw.test/' );
		define( 'NSW_THEME_MANTIS_TOKEN', 'test-token-0123456789abcdef01234' );
		define( 'NSW_THEME_MANTIS_PROJECT_ID', '1' );
	}

	/* ---------------------------------------------------------------- categories */

	#[TestDox('The form offers the seven Help Desk categories')]
	public function test_seven_categories(): void {
		$this->assertSame(
			array( 'General inquiry', 'LPCO process', 'Registration', 'Payment', 'Technical issue', 'Feedback', 'Other' ),
			array_values( nsw_theme_contact_category_labels() )
		);
	}

	#[TestDox('A known category is mapped to its Help Desk category name')]
	public function test_known_category_is_mapped(): void {
		$this->assertSame( 'Technical issue', nsw_theme_contact_map_category( 'technical' ) );
		$this->assertSame( 'LPCO process', nsw_theme_contact_map_category( 'lpco' ) );
	}

	#[TestDox('An unknown or empty category falls back to General inquiry')]
	public function test_unknown_category_falls_back(): void {
		$this->assertSame( 'General inquiry', nsw_theme_contact_map_category( 'zzz' ) );
		$this->assertSame( 'General inquiry', nsw_theme_contact_map_category( '' ) );
	}

	/* --------------------------------------------------------------- description */

	#[TestDox('The ticket description starts with the sender, then organisation, category, agency and the message')]
	public function test_description_layout(): void {
		$this->assertSame(
			"From: Jane Doe <jane@example.com>\nOrganization: ACME sh.p.k.\nCategory: Technical issue\nAgency: Customs\n\nLine one.\nLine two.",
			nsw_theme_contact_build_description( $this->request() )
		);
	}

	#[TestDox('The description leaves out the organisation and agency lines when they are blank')]
	public function test_description_without_optional_lines(): void {
		$description = nsw_theme_contact_build_description( $this->request( array( 'organization' => '', 'agency' => '' ) ) );
		$this->assertStringNotContainsString( 'Organization:', $description );
		$this->assertStringNotContainsString( 'Agency:', $description );
	}

	#[TestDox('An agency without a known label is written as given')]
	public function test_description_unknown_agency_written_as_given(): void {
		$description = nsw_theme_contact_build_description( $this->request( array( 'agency' => 'nfa' ) ) );
		$this->assertStringContainsString( 'Agency: nfa', $description );
	}

	/** Source: TSD 2.4, Help Desk interface (description keeps the full subject if cut). */
	#[TestDox('A subject longer than 128 characters is kept in full in the description')]
	public function test_description_keeps_full_long_subject(): void {
		$subject     = str_repeat( 'Long subject ', 12 ); // 156 characters.
		$description = nsw_theme_contact_build_description( $this->request( array( 'subject' => $subject ) ) );
		$this->assertStringContainsString( $subject, $description );
	}

	/* ------------------------------------------------------------- custom fields */

	#[TestDox('The ticket carries the sender name, email, organisation and agency as custom fields')]
	public function test_custom_fields_values(): void {
		$fields = $this->fields_by_name( nsw_theme_contact_build_custom_fields( $this->request() ) );
		$this->assertSame( 'Jane Doe', $fields['Customer Name'] );
		$this->assertSame( 'jane@example.com', $fields['Customer Email'] );
		$this->assertSame( 'ACME sh.p.k.', $fields['Organization'] );
		$this->assertSame( 'Customs', $fields['Relevant Agency'] );
	}

	#[TestDox('Blank optional fields are not sent')]
	public function test_custom_fields_skip_blank_optional(): void {
		$fields = $this->fields_by_name(
			nsw_theme_contact_build_custom_fields( $this->request( array( 'organization' => '', 'agency' => '' ) ) )
		);
		$this->assertArrayNotHasKey( 'Organization', $fields );
		$this->assertArrayNotHasKey( 'Relevant Agency', $fields );
	}

	/** Source: TSD 2.4, Help Desk interface (Source Channel "Information Portal"). */
	#[TestDox('The Source Channel of a website request is "Information Portal"')]
	public function test_source_channel_is_information_portal(): void {
		$fields = $this->fields_by_name( nsw_theme_contact_build_custom_fields( $this->request() ) );
		$this->assertSame( 'Information Portal', $fields['Source Channel'] );
	}

	/* ------------------------------------------------------------------- payload */

	#[TestDox('The ticket goes to project 1 with the mapped category and the subject as summary')]
	public function test_payload(): void {
		Functions\when( 'get_option' )->returnArg( 2 );
		$payload = nsw_theme_contact_build_issue_payload( $this->request() );
		$this->assertSame( 'Cannot upload LPCO', $payload['summary'] );
		$this->assertSame( array( 'id' => 1 ), $payload['project'] );
		$this->assertSame( array( 'name' => 'Technical issue' ), $payload['category'] );
		$this->assertCount( 5, $payload['custom_fields'] );
	}

	/** Source: TSD 2.4, Help Desk interface (subject cut to 128 characters). */
	#[TestDox('A subject longer than 128 characters is cut to 128 characters in the summary')]
	public function test_summary_cut_to_128_characters(): void {
		Functions\when( 'get_option' )->returnArg( 2 );
		$subject = str_repeat( 'x', 200 );
		$payload = nsw_theme_contact_build_issue_payload( $this->request( array( 'subject' => $subject ) ) );
		$this->assertLessThanOrEqual( 128, mb_strlen( $payload['summary'] ) );
	}

	#[TestDox('A subject of exactly 128 characters is sent unchanged')]
	public function test_summary_of_128_characters_unchanged(): void {
		Functions\when( 'get_option' )->returnArg( 2 );
		$subject = str_repeat( 'y', 128 );
		$payload = nsw_theme_contact_build_issue_payload( $this->request( array( 'subject' => $subject ) ) );
		$this->assertSame( $subject, $payload['summary'] );
	}

	/** Source: TSD 2.4, Help Desk interface (subject cut to 128 characters). */
	#[TestDox('The summary cut counts letters, not bytes, so Albanian letters such as ë are not cut early')]
	public function test_summary_cut_counts_characters(): void {
		Functions\when( 'get_option' )->returnArg( 2 );
		$payload = nsw_theme_contact_build_issue_payload( $this->request( array( 'subject' => str_repeat( 'ë', 200 ) ) ) );
		$this->assertSame( str_repeat( 'ë', 128 ), $payload['summary'] );
	}

	/** Source: TSD 2.4, Help Desk interface (description: ... full subject if cut ...). */
	#[TestDox('The description repeats the full subject, after the agency and before the message, only when the summary had to cut it')]
	public function test_description_full_subject_only_when_cut(): void {
		$long = str_repeat( 'z', 129 );
		$this->assertSame(
			"From: Jane Doe <jane@example.com>\nOrganization: ACME sh.p.k.\nCategory: Technical issue\nAgency: Customs\nSubject: $long\n\nLine one.\nLine two.",
			nsw_theme_contact_build_description( $this->request( array( 'subject' => $long ) ) )
		);
		$this->assertStringNotContainsString(
			'Subject:',
			nsw_theme_contact_build_description( $this->request( array( 'subject' => str_repeat( 'z', 128 ) ) ) )
		);
	}

	/** Source: TSD 2.4, Help Desk interface (optional attachment, base64 in the files element). */
	#[TestDox('An attachment goes in the files element with its name and its content in base64; without one there is no files element')]
	public function test_payload_files_element(): void {
		Functions\when( 'get_option' )->returnArg( 2 );
		$content = "%PDF-1.4\nInvented test file content.";

		$with    = nsw_theme_contact_build_issue_payload( $this->request(), array( array( 'name' => 'evidence.pdf', 'content' => $content ) ) );
		$without = nsw_theme_contact_build_issue_payload( $this->request() );

		$this->assertSame( array( array( 'name' => 'evidence.pdf', 'content' => base64_encode( $content ) ) ), $with['files'] );
		$this->assertArrayNotHasKey( 'files', $without );
	}

	/* ------------------------------------------------------------- configuration */

	/** Source: TSD 2.5.3, secrets (... SMTP credentials) only in wp-config.php, not in the database. */
	#[TestDox('The mail server password is never read from the database by the settings reader')]
	public function test_smtp_password_setting_not_read_from_database(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'nsw_theme_smtp_pass' === $name ? 'password-in-the-database' : $default
		);
		$this->assertSame( '', nsw_theme_contact_config( 'smtp_pass' ) );
	}

	/** Source: TSD 2.3.3, nsw_theme_contact_from and nsw_theme_contact_reply_to are wp_options rows. */
	#[TestDox('Settings that are not secrets, such as the confirmation From address, are still read from the database')]
	public function test_non_secret_settings_read_from_database(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'nsw_theme_contact_from' === $name ? 'noreply@example.com' : $default
		);
		$this->assertSame( 'noreply@example.com', nsw_theme_contact_config( 'contact_from' ) );
	}

	#[TestDox('The Help Desk counts as not configured when no settings are present')]
	public function test_not_configured_without_settings(): void {
		Functions\when( 'get_option' )->returnArg( 2 );
		$this->assertFalse( nsw_theme_contact_mantis_configured() );
	}

	/** Source: TSD 2.4 and 2.5 (Help Desk token only in wp-config.php, not in the database). */
	#[TestDox('The Help Desk token is never read from the database, only from wp-config.php')]
	public function test_token_not_read_from_database(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'nsw_theme_mantis_token' === $name ? 'token-in-the-database' : $default
		);
		$this->assertSame( '', nsw_theme_contact_config( 'mantis_token' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Settings in wp-config.php win over the database and make the Help Desk configured')]
	public function test_wp_config_constants_win(): void {
		$this->define_help_desk_constants();
		Functions\when( 'get_option' )->justReturn( 'from-the-database' );
		$this->assertSame( 'https://support.nsw.test/', nsw_theme_contact_config( 'mantis_url' ) );
		$this->assertSame( 'test-token-0123456789abcdef01234', nsw_theme_contact_config( 'mantis_token' ) );
		$this->assertTrue( nsw_theme_contact_mantis_configured() );
	}

	/* --------------------------------------------------------- sending a ticket */

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A created ticket returns its number, sent to the issues endpoint with the raw token')]
	public function test_create_ticket_success(): void {
		$this->define_help_desk_constants();
		$sent = array();
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$sent ) {
				$sent = array( 'url' => $url, 'args' => $args );
				return array( 'response' => array( 'code' => 201 ), 'body' => '{"issue":{"id":42}}' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

		$result = nsw_theme_contact_create_mantis_issue( $this->request() );

		$this->assertSame( array( 'id' => 42 ), $result );
		$this->assertSame( 'https://support.nsw.test/api/rest/issues', $sent['url'] );
		$this->assertSame( 'test-token-0123456789abcdef01234', $sent['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $sent['args']['headers']['Content-Type'] );
		$this->assertSame( 15, $sent['args']['timeout'] );
		$body = json_decode( $sent['args']['body'], true );
		$this->assertSame( 'Cannot upload LPCO', $body['summary'] );
		$this->assertSame( 1, $body['project']['id'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A refusal from the Help Desk is logged and reported as a failed request (status 502)')]
	public function test_create_ticket_http_error(): void {
		$this->define_help_desk_constants();
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"bad"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"message":"bad"}' );
		Functions\expect( 'error_log' )->once()->with( \Mockery::pattern( '/Mantis create issue error: 400/' ) );

		$result = nsw_theme_contact_create_mantis_issue( $this->request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nsw_theme_mantis_failed', $result->get_error_code() );
		$this->assertSame( array( 'status' => 502 ), $result->get_error_data() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A Help Desk that cannot be reached is logged and reported as a failed request (status 502)')]
	public function test_create_ticket_transport_error(): void {
		$this->define_help_desk_constants();
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'down' ) );
		Functions\expect( 'error_log' )->once()->with( \Mockery::pattern( '/transport error: down/' ) );

		$result = nsw_theme_contact_create_mantis_issue( $this->request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'status' => 502 ), $result->get_error_data() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A success reply without a ticket number returns number 0')]
	public function test_create_ticket_reply_without_id(): void {
		$this->define_help_desk_constants();
		Functions\when( 'wp_remote_post' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 201 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'not json' );

		$this->assertSame( array( 'id' => 0 ), nsw_theme_contact_create_mantis_issue( $this->request() ) );
	}
}
