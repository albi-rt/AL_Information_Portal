<?php
/**
 * Contact form — REST endpoint + Jira integration + customer confirmation
 * email. Mirrors the Macedonian theme's `inc/contact-form.php` 1:1 with
 * Albanian-flavored prefixes and agency labels.
 *
 *   1. Validate required fields (fullName, email, category, subject, message).
 *   2. Create a Jira issue via the standard Atlassian REST API (Basic Auth
 *      with apiEmail:apiToken). Description body is Atlassian Document
 *      Format (ADF) with a deterministic `From: Name <email>` first line
 *      so a `/jira-reply` webhook can extract the customer email when an
 *      agent comments.
 *   3. Send a customer-facing confirmation email with `[NSW-{key}]` in the
 *      subject so customer replies thread back into the same ticket via
 *      email channel.
 *
 * Additional WP-side protections:
 *   - `wp_rest` nonce check
 *   - Honeypot field (`website`) — bots fill it, humans don't
 *   - Per-IP rate limit (30s transient)
 *   - Per-field length caps to prevent DoS via huge payloads
 *
 * Configuration — secrets MUST go in wp-config.php (never in the DB):
 *
 *   define( 'NSW_THEME_JIRA_SITE',      'company.atlassian.net' );
 *   define( 'NSW_THEME_JIRA_API_EMAIL', 'info@nsw.al' );
 *   define( 'NSW_THEME_JIRA_API_TOKEN', '<atlassian API token>' );
 *
 *   define( 'NSW_THEME_SMTP_HOST',      'mail.example.com' );
 *   define( 'NSW_THEME_SMTP_PORT',       465 );  // optional, defaults to 465
 *   define( 'NSW_THEME_SMTP_USER',      'info@nsw.al' );
 *   define( 'NSW_THEME_SMTP_PASS',      '<smtp password>' );
 *
 * Non-secret configuration lives under Settings → General:
 *   - Project key (default "NSW")
 *   - Work type (default "Question")
 *   - Custom field IDs for Category / Organization / Agency
 *   - Confirmation email From + Reply-To addresses
 *   - Admin recipient (used as fallback when Jira isn't configured)
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
 * 1) Configuration resolver — secrets from constants, the rest from options.
 * ========================================================================= */

/**
 * Read a config value. Constants in wp-config.php win over wp_options so
 * secrets (API tokens, SMTP passwords) never have to land in the DB.
 *
 * @param string $key e.g. "jira_site", "smtp_host", "jira_project_key".
 */
function nsw_theme_contact_config( string $key, string $default = '' ): string {
	$const = 'NSW_THEME_' . strtoupper( $key );
	if ( defined( $const ) ) {
		return (string) constant( $const );
	}
	return (string) get_option( 'nsw_theme_' . strtolower( $key ), $default );
}

/**
 * Is the Jira integration fully configured? If any of the three required
 * secrets is missing, the endpoint silently falls back to the email-only
 * path so the form keeps working on demo / staging setups.
 */
function nsw_theme_contact_jira_configured(): bool {
	return '' !== nsw_theme_contact_config( 'jira_site' )
		&& '' !== nsw_theme_contact_config( 'jira_api_email' )
		&& '' !== nsw_theme_contact_config( 'jira_api_token' );
}

/* =========================================================================
 * 2) Label maps — convert the form's lowercase keys to the option labels
 *    that Jira's "Category" / "Agency" custom fields are configured with.
 *    Match the form's `contactPage.form.{category,agency}Options` in
 *    content-en.json so the Jira dropdown values are predictable.
 * ========================================================================= */

function nsw_theme_contact_category_labels(): array {
	return apply_filters(
		'nsw_theme_contact_category_labels',
		array(
			'general'      => 'General inquiry',
			'lpco'         => 'LPCO process',
			'registration' => 'Registration',
			'payment'      => 'Payment',
			'technical'    => 'Technical issue',
			'feedback'     => 'Feedback',
			'other'        => 'Other',
		)
	);
}

function nsw_theme_contact_agency_labels(): array {
	return apply_filters(
		'nsw_theme_contact_agency_labels',
		array(
			'general' => 'General',
			'customs' => 'Customs',
			'gdc'     => 'GDC (General Directorate of Customs)',
			'nfa'     => 'NFA (National Food Authority)',
			'navpp'   => 'NAVPP (Veterinary & Plant Protection)',
			'mhsp'    => 'MHSP (Ministry of Health)',
			'nadmd'   => 'NADMD (Drugs & Medical Devices)',
			'nanr'    => 'NANR (Natural Resources)',
			'stii'    => 'STII (Technical Inspectorate)',
			'moe'     => 'MoE (Ministry of Environment)',
			'seca'    => 'SECA (State Export Control Authority)',
			'sess'    => 'SESS (Seeds & Seedlings)',
			'iph'     => 'IPH (Institute of Public Health)',
			'nich'    => 'NICH (Cultural Heritage)',
			'mie'     => 'MIE (Ministry of Infrastructure & Energy)',
			'nea'     => 'NEA (National Environment Agency)',
			'other'   => 'Other',
		)
	);
}

/* =========================================================================
 * 3) REST route registration
 * ========================================================================= */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'nsw-theme/v1',
			'/contact',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'nsw_theme_handle_contact',
				'permission_callback' => '__return_true', // Anyone — nonce checked inside.
				'args'                => array(
					'fullName'     => array( 'type' => 'string', 'required' => true ),
					'email'        => array( 'type' => 'string', 'required' => true, 'format' => 'email' ),
					'organization' => array( 'type' => 'string' ),
					'category'     => array( 'type' => 'string', 'required' => true ),
					'agency'       => array( 'type' => 'string' ),
					'subject'      => array( 'type' => 'string', 'required' => true ),
					'message'      => array( 'type' => 'string', 'required' => true ),
					'website'      => array( 'type' => 'string' ), // Honeypot.
				),
			)
		);
	}
);

/* =========================================================================
 * 4) Main handler — security gates, then Jira (or email fallback).
 * ========================================================================= */

function nsw_theme_handle_contact( WP_REST_Request $request ) {

	/* --- Security: nonce, honeypot, rate limit -------------------------- */

	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error( 'nsw_theme_bad_nonce', __( 'Security check failed.', 'nsw-theme' ), array( 'status' => 403 ) );
	}

	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = $request->get_params();
	}

	/* Honeypot — bots fill it, humans don't see it. Accept-but-discard so
	   the bot doesn't learn its trap was detected. */
	if ( ! empty( $params['website'] ) ) {
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'nsw_theme_contact_' . md5( $ip );
	if ( get_transient( $key ) ) {
		return new WP_Error( 'nsw_theme_rate_limited', __( 'Please wait a moment before sending again.', 'nsw-theme' ), array( 'status' => 429 ) );
	}
	set_transient( $key, 1, 30 );

	/* --- Sanitize + cap field lengths ----------------------------------- */

	$data = array(
		'fullName'     => mb_substr( sanitize_text_field( (string) ( $params['fullName']     ?? '' ) ), 0, 120 ),
		'email'        => sanitize_email(             mb_substr( (string) ( $params['email']        ?? '' ), 0, 200 ) ),
		'organization' => mb_substr( sanitize_text_field( (string) ( $params['organization'] ?? '' ) ), 0, 150 ),
		'category'     => mb_substr( sanitize_text_field( (string) ( $params['category']     ?? '' ) ), 0, 60 ),
		'agency'       => mb_substr( sanitize_text_field( (string) ( $params['agency']       ?? '' ) ), 0, 60 ),
		'subject'      => mb_substr( sanitize_text_field( (string) ( $params['subject']      ?? '' ) ), 0, 200 ),
		'message'      => mb_substr( sanitize_textarea_field( (string) ( $params['message']  ?? '' ) ), 0, 5000 ),
	);

	/* --- Validate ------------------------------------------------------- */

	foreach ( array( 'fullName', 'email', 'category', 'subject', 'message' ) as $required ) {
		if ( '' === trim( (string) $data[ $required ] ) ) {
			return new WP_Error( 'nsw_theme_missing', __( 'Missing required fields.', 'nsw-theme' ), array( 'status' => 400 ) );
		}
	}
	if ( ! is_email( $data['email'] ) ) {
		return new WP_Error( 'nsw_theme_bad_email', __( 'Please enter a valid email.', 'nsw-theme' ), array( 'status' => 422 ) );
	}
	if ( strlen( $data['subject'] ) < 3 ) {
		return new WP_Error( 'nsw_theme_short_subject', __( 'Subject is too short.', 'nsw-theme' ), array( 'status' => 400 ) );
	}
	if ( strlen( $data['message'] ) < 10 ) {
		return new WP_Error( 'nsw_theme_short_message', __( 'Message is too short.', 'nsw-theme' ), array( 'status' => 400 ) );
	}

	/* --- Jira path (preferred), email-only fallback otherwise ----------- */

	if ( nsw_theme_contact_jira_configured() ) {
		$jira = nsw_theme_contact_create_jira_issue( $data );
		if ( is_wp_error( $jira ) ) {
			return $jira;
		}
		$issue_key = (string) $jira['key'];

		/* Non-fatal — if the confirmation email fails, the ticket still exists. */
		if ( $issue_key ) {
			try {
				nsw_theme_contact_send_confirmation_email( $data, $issue_key );
			} catch ( \Throwable $e ) {
				error_log( 'NSW Theme contact: confirmation email failed (non-fatal): ' . $e->getMessage() );
			}
		}

		return new WP_REST_Response( array( 'success' => true, 'issueKey' => $issue_key, 'message' => __( 'Message sent.', 'nsw-theme' ) ), 200 );
	}

	/* Email-only fallback — keeps the form usable on demo/staging where Jira
	   hasn't been wired up yet. Sends a single notification to the admin
	   recipient configured under Settings → General. */
	$sent = nsw_theme_contact_send_admin_notification( $data );
	if ( ! $sent ) {
		return new WP_Error( 'nsw_theme_mail_failed', __( 'Could not send the message. Please try again later.', 'nsw-theme' ), array( 'status' => 500 ) );
	}
	return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Message sent.', 'nsw-theme' ) ), 200 );
}

/* =========================================================================
 * 5) Jira issue creation
 * ========================================================================= */

/**
 * POST a new issue to Atlassian Jira via the standard REST API.
 *
 * @param array{fullName:string,email:string,organization:string,category:string,agency:string,subject:string,message:string} $data
 * @return array{key:string}|WP_Error
 */
function nsw_theme_contact_create_jira_issue( array $data ) {

	$site         = nsw_theme_contact_config( 'jira_site' );
	$api_email    = nsw_theme_contact_config( 'jira_api_email' );
	$api_token    = nsw_theme_contact_config( 'jira_api_token' );
	$project_key  = nsw_theme_contact_config( 'jira_project_key', 'NSW' );
	$work_type    = nsw_theme_contact_config( 'jira_work_type', 'Question' );
	$field_cat    = nsw_theme_contact_config( 'jira_field_category' );
	$field_org    = nsw_theme_contact_config( 'jira_field_organization' );
	$field_agency = nsw_theme_contact_config( 'jira_field_agency' );

	$categories = nsw_theme_contact_category_labels();
	$agencies   = nsw_theme_contact_agency_labels();

	/* We deliberately do NOT set `reporter` to the customer — that would
	   create a JSM customer record and trigger a "verify your email"
	   invitation. The API user is the reporter; the customer's name + email
	   live in the description and are extracted by the email-reply webhook
	   when an agent posts a public comment. */
	$fields = array(
		'project'     => array( 'key' => $project_key ),
		'issuetype'   => array( 'name' => $work_type ),
		'summary'     => $data['subject'],
		'description' => nsw_theme_contact_build_adf( $data ),
		/* Labels can't contain `@`, so replace it with `__at__` — the
		   webhook converts back when parsing. */
		'labels'      => array(
			'web-contact',
			'from:' . str_replace( '@', '__at__', $data['email'] ),
		),
	);

	if ( '' !== $field_cat && isset( $categories[ $data['category'] ] ) ) {
		$fields[ $field_cat ] = array( 'value' => $categories[ $data['category'] ] );
	}
	if ( '' !== $field_org && '' !== $data['organization'] ) {
		$fields[ $field_org ] = $data['organization'];
	}
	if ( '' !== $field_agency && '' !== $data['agency'] && isset( $agencies[ $data['agency'] ] ) ) {
		$fields[ $field_agency ] = array( 'value' => $agencies[ $data['agency'] ] );
	}

	$response = wp_remote_post(
		'https://' . $site . '/rest/api/3/issue',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_email . ':' . $api_token ),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'fields' => $fields ) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'NSW Theme contact: Jira request transport error: ' . $response->get_error_message() );
		return new WP_Error( 'nsw_theme_jira_failed', __( 'Failed to create support request.', 'nsw-theme' ), array( 'status' => 502 ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );

	if ( $code < 200 || $code >= 300 ) {
		error_log( 'NSW Theme contact: Jira create issue error: ' . $code . ' — ' . $body );
		return new WP_Error( 'nsw_theme_jira_failed', __( 'Failed to create support request.', 'nsw-theme' ), array( 'status' => 502 ) );
	}

	$decoded = json_decode( $body, true );
	$key     = is_array( $decoded ) && isset( $decoded['key'] ) ? (string) $decoded['key'] : '';

	return array( 'key' => $key );
}

/* =========================================================================
 * 6) Atlassian Document Format (ADF) body builder
 *
 *    The first paragraph always reads `From: Name <email>` so the
 *    `/jira-reply` webhook can extract the customer email when an agent
 *    posts a public comment.
 * ========================================================================= */

function nsw_theme_contact_build_adf( array $data ): array {

	$categories = nsw_theme_contact_category_labels();
	$agencies   = nsw_theme_contact_agency_labels();

	$meta = array(
		array( 'From', $data['fullName'] . ' <' . $data['email'] . '>' ),
	);
	if ( '' !== $data['organization'] ) {
		$meta[] = array( 'Organization', $data['organization'] );
	}
	$meta[] = array( 'Category', $categories[ $data['category'] ] ?? $data['category'] );
	if ( '' !== $data['agency'] ) {
		$meta[] = array( 'Agency', $agencies[ $data['agency'] ] ?? $data['agency'] );
	}

	$paragraphs = array();
	foreach ( $meta as $row ) {
		list( $label, $value ) = $row;
		$paragraphs[]          = array(
			'type'    => 'paragraph',
			'content' => array(
				array( 'type' => 'text', 'text' => $label . ': ', 'marks' => array( array( 'type' => 'strong' ) ) ),
				array( 'type' => 'text', 'text' => (string) $value ),
			),
		);
	}

	foreach ( preg_split( '/\n+/', $data['message'] ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		$paragraphs[] = array(
			'type'    => 'paragraph',
			'content' => array( array( 'type' => 'text', 'text' => $line ) ),
		);
	}

	return array(
		'type'    => 'doc',
		'version' => 1,
		'content' => $paragraphs,
	);
}

/* =========================================================================
 * 7) Customer confirmation email
 *
 *    Sent via `wp_mail()` so it inherits whatever SMTP wiring is in place
 *    (either our `phpmailer_init` hook below when NSW_THEME_SMTP_* constants
 *    are defined, or a 3rd-party WP Mail SMTP plugin).
 *
 *    Subject embeds `[NSW-X]` so when the customer replies, the email
 *    channel forwarder threads the reply back into the same ticket.
 * ========================================================================= */

function nsw_theme_contact_send_confirmation_email( array $data, string $issue_key ): bool {

	$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$from      = nsw_theme_contact_config( 'contact_from' );
	$reply_to  = nsw_theme_contact_config( 'contact_reply_to' );

	/* Fallback chain: contact_from → SMTP user → admin email. */
	if ( '' === $from ) {
		$from = nsw_theme_contact_config( 'smtp_user' );
	}
	if ( '' === $from ) {
		$from = (string) get_option( 'admin_email' );
	}
	if ( '' === $reply_to ) {
		$reply_to = $from;
	}

	$subject = '[' . $issue_key . '] ' . __( "We've received your message", 'nsw-theme' );

	$name    = esc_html( $data['fullName'] );
	$key     = esc_html( $issue_key );
	$msg_sub = esc_html( $data['subject'] );
	$msg     = esc_html( $data['message'] );

	$greeting = sprintf(
		/* translators: %s: customer's full name. */
		__( 'Hi %s,', 'nsw-theme' ),
		$name
	);
	$thanks = sprintf(
		/* translators: 1: site name (HTML-escaped); 2: issue key like NSW-123. */
		__( 'Thank you for contacting <strong>%1$s</strong>. Your message has been received and assigned reference <strong>%2$s</strong>.', 'nsw-theme' ),
		esc_html( $site_name ),
		$key
	);
	$reply_hint = sprintf(
		/* translators: %s: bracketed issue key, e.g. [NSW-123] */
		__( 'To add anything to your request, simply reply to this email and keep <strong>%s</strong> in the subject line.', 'nsw-theme' ),
		'[' . $key . ']'
	);

	$body  = '<div style="font-family:system-ui,-apple-system,sans-serif;color:#222;line-height:1.5;max-width:600px">';
	$body .= '<p>' . $greeting . '</p>';
	$body .= '<p>' . $thanks . '</p>';
	$body .= '<p>' . esc_html__( 'We aim to respond within 36 hours.', 'nsw-theme' ) . '</p>';
	$body .= '<p>' . $reply_hint . '</p>';
	$body .= '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0">';
	$body .= '<p style="color:#666;font-size:13px;margin:0 0 8px"><strong>' . esc_html__( 'Your message:', 'nsw-theme' ) . '</strong></p>';
	$body .= '<p style="color:#666;font-size:13px;margin:0 0 4px"><em>' . $msg_sub . '</em></p>';
	$body .= '<p style="color:#666;font-size:13px;white-space:pre-wrap;margin:0">' . $msg . '</p>';
	$body .= '</div>';

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: "' . str_replace( '"', '', $site_name ) . '" <' . $from . '>',
		'Reply-To: ' . $reply_to,
	);

	return (bool) wp_mail( $data['email'], $subject, $body, $headers );
}

/* =========================================================================
 * 8) Email-only fallback (Jira not configured)
 *
 *    Identical to the previous, simpler behaviour — kept so the form is
 *    still functional on demo / staging without any Jira credentials.
 * ========================================================================= */

function nsw_theme_contact_send_admin_notification( array $data ): bool {

	$to      = (string) get_option( 'nsw_theme_contact_email', get_option( 'admin_email' ) );
	if ( '' === $to ) {
		$to = (string) get_option( 'admin_email' );
	}
	$site    = (string) get_bloginfo( 'name' );
	$subject = sprintf( '[%s] %s', $site, $data['subject'] );

	/* Strip CRLF from name to prevent header injection via Reply-To. */
	$reply_name = trim( str_replace( array( "\r", "\n" ), '', $data['fullName'] ) );
	$headers    = array(
		'Content-Type: text/html; charset=UTF-8',
		'Reply-To: "' . addslashes( $reply_name ) . '" <' . $data['email'] . '>',
	);

	$rows = array(
		__( 'Name',         'nsw-theme' ) => $data['fullName'],
		__( 'Email',        'nsw-theme' ) => $data['email'],
		__( 'Organization', 'nsw-theme' ) => $data['organization'],
		__( 'Category',     'nsw-theme' ) => $data['category'],
		__( 'Agency',       'nsw-theme' ) => $data['agency'],
		__( 'Subject',      'nsw-theme' ) => $data['subject'],
	);

	$body  = '<h2>' . esc_html__( 'New contact submission', 'nsw-theme' ) . '</h2>';
	$body .= '<table style="border-collapse:collapse;width:100%;max-width:600px">';
	foreach ( $rows as $label => $value ) {
		if ( '' === $value ) {
			continue;
		}
		$body .= sprintf(
			'<tr><td style="padding:8px 12px;font-weight:bold;border-bottom:1px solid #eee">%s</td><td style="padding:8px 12px;border-bottom:1px solid #eee">%s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}
	$body .= sprintf(
		'<tr><td style="padding:8px 12px;font-weight:bold;vertical-align:top">%s</td><td style="padding:8px 12px;white-space:pre-wrap">%s</td></tr>',
		esc_html__( 'Message', 'nsw-theme' ),
		nl2br( esc_html( $data['message'] ) )
	);
	$body .= '</table>';

	return (bool) wp_mail( $to, $subject, $body, $headers );
}

/* =========================================================================
 * 9) SMTP via PHPMailer
 *
 *    When NSW_THEME_SMTP_HOST + NSW_THEME_SMTP_USER + NSW_THEME_SMTP_PASS are
 *    defined in wp-config.php, all `wp_mail()` calls route through the
 *    configured SMTP server.
 * ========================================================================= */

add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		$host = nsw_theme_contact_config( 'smtp_host' );
		$user = nsw_theme_contact_config( 'smtp_user' );
		$pass = nsw_theme_contact_config( 'smtp_pass' );
		if ( '' === $host || '' === $user || '' === $pass ) {
			return;
		}
		$port = (int) ( nsw_theme_contact_config( 'smtp_port', '465' ) ?: 465 );

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->Port       = $port;
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = $user;
		$phpmailer->Password   = $pass;
		$phpmailer->SMTPSecure = ( 465 === $port ) ? 'ssl' : 'tls';
		/* Loose TLS verification — many gov SMTP gateways still use
		   self-signed or older certs. */
		$phpmailer->SMTPOptions = array(
			'ssl' => array(
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			),
		);
	}
);

/* =========================================================================
 * 10) Admin settings — Settings → General. Secrets are deliberately NOT
 *     editable here; they must live in wp-config.php as constants.
 * ========================================================================= */

add_action(
	'admin_init',
	function () {

		$strings = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'show_in_rest' => false, 'default' => '' );
		$emails  = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email',       'show_in_rest' => false, 'default' => '' );

		register_setting( 'general', 'nsw_theme_contact_email',           $emails );
		register_setting( 'general', 'nsw_theme_jira_project_key',        $strings );
		register_setting( 'general', 'nsw_theme_jira_work_type',          $strings );
		register_setting( 'general', 'nsw_theme_jira_field_category',     $strings );
		register_setting( 'general', 'nsw_theme_jira_field_organization', $strings );
		register_setting( 'general', 'nsw_theme_jira_field_agency',       $strings );
		register_setting( 'general', 'nsw_theme_contact_from',            $emails );
		register_setting( 'general', 'nsw_theme_contact_reply_to',        $emails );

		add_settings_section(
			'nsw_theme_contact_section',
			__( 'NSW Contact Form', 'nsw-theme' ),
			function () {
				echo '<p>' . esc_html__( 'Settings for the contact form REST endpoint. Secrets (Jira API token, SMTP password) must be defined as constants in wp-config.php — see inc/contact-form.php for the full list.', 'nsw-theme' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Status:', 'nsw-theme' ) . '</strong> ';
				if ( nsw_theme_contact_jira_configured() ) {
					echo '<span style="color:#2e7d32">' . esc_html__( 'Jira integration is configured — submissions create tickets.', 'nsw-theme' ) . '</span>';
				} else {
					echo '<span style="color:#ef6c00">' . esc_html__( 'Jira NOT configured — submissions fall back to admin email.', 'nsw-theme' ) . '</span>';
				}
				echo '</p>';
			},
			'general'
		);

		$text_field = function ( string $name, string $label, string $description = '', string $placeholder = '' ) {
			add_settings_field(
				$name,
				$label,
				function () use ( $name, $description, $placeholder ) {
					printf(
						'<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s" placeholder="%3$s">',
						esc_attr( $name ),
						esc_attr( (string) get_option( $name, '' ) ),
						esc_attr( $placeholder )
					);
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
				},
				'general',
				'nsw_theme_contact_section'
			);
		};

		$text_field( 'nsw_theme_contact_email',           __( 'Admin recipient', 'nsw-theme' ), __( 'Fallback email when Jira isn\'t configured. Defaults to the site admin email.', 'nsw-theme' ), (string) get_option( 'admin_email' ) );
		$text_field( 'nsw_theme_jira_project_key',        __( 'Jira project key', 'nsw-theme' ), __( 'e.g. NSW.', 'nsw-theme' ), 'NSW' );
		$text_field( 'nsw_theme_jira_work_type',          __( 'Jira issue type', 'nsw-theme' ), __( 'e.g. Question, Task, Service Request.', 'nsw-theme' ), 'Question' );
		$text_field( 'nsw_theme_jira_field_category',     __( 'Jira "Category" custom field ID', 'nsw-theme' ), __( 'e.g. customfield_12345. Leave blank to skip mapping.', 'nsw-theme' ) );
		$text_field( 'nsw_theme_jira_field_organization', __( 'Jira "Organization" custom field ID', 'nsw-theme' ), __( 'e.g. customfield_12346. Leave blank to skip.', 'nsw-theme' ) );
		$text_field( 'nsw_theme_jira_field_agency',       __( 'Jira "Agency" custom field ID', 'nsw-theme' ), __( 'e.g. customfield_12347. Leave blank to skip.', 'nsw-theme' ) );
		$text_field( 'nsw_theme_contact_from',            __( 'Confirmation email From', 'nsw-theme' ), __( 'Address shown as the sender on the customer confirmation email. Defaults to SMTP_USER, then site admin email.', 'nsw-theme' ) );
		$text_field( 'nsw_theme_contact_reply_to',        __( 'Confirmation email Reply-To', 'nsw-theme' ), __( 'Where customer replies are delivered. Defaults to From.', 'nsw-theme' ) );
	}
);
