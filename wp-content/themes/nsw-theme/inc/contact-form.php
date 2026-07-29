<?php
/**
 * Contact form — REST endpoint + MantisBT integration + customer confirmation email.
 *
 *   1. Validate required fields (fullName, email, category, subject, message).
 *   2. Create a MantisBT ticket via REST (see inc/contact-mantis.php).
 *   3. Send a customer-facing confirmation email referencing NSWAL-<id>.
 *
 * Protections: wp_rest nonce, honeypot (`website`), per-IP 30s rate limit, length caps.
 *
 * Secrets (wp-config.php): NSW_THEME_MANTIS_URL, NSW_THEME_MANTIS_TOKEN,
 * NSW_THEME_MANTIS_PROJECT_ID, and (optional) NSW_THEME_SMTP_*.
 *
 * @package NSW_Theme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ---- REST route ---------------------------------------------------------- */
add_action( 'rest_api_init', function () {
    register_rest_route( 'nsw-theme/v1', '/contact', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'nsw_theme_handle_contact',
        'permission_callback' => '__return_true',
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
    ) );
} );

/* ---- Handler ------------------------------------------------------------- */
function nsw_theme_handle_contact( WP_REST_Request $request ) {

    $nonce = $request->get_header( 'X-WP-Nonce' );
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        return new WP_Error( 'nsw_theme_bad_nonce', __( 'Security check failed.', 'nsw-theme' ), array( 'status' => 403 ) );
    }

    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) { $params = $request->get_params(); }

    if ( ! empty( $params['website'] ) ) { // honeypot: accept-but-discard
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
    $key = 'nsw_theme_contact_' . md5( $ip );
    if ( get_transient( $key ) ) {
        return new WP_Error( 'nsw_theme_rate_limited', __( 'Please wait a moment before sending again.', 'nsw-theme' ), array( 'status' => 429 ) );
    }
    set_transient( $key, 1, 30 );

    $data = array(
        'fullName'     => mb_substr( sanitize_text_field( (string) ( $params['fullName'] ?? '' ) ), 0, 120 ),
        'email'        => sanitize_email( mb_substr( (string) ( $params['email'] ?? '' ), 0, 200 ) ),
        'organization' => mb_substr( sanitize_text_field( (string) ( $params['organization'] ?? '' ) ), 0, 150 ),
        'category'     => mb_substr( sanitize_text_field( (string) ( $params['category'] ?? '' ) ), 0, 60 ),
        'agency'       => mb_substr( sanitize_text_field( (string) ( $params['agency'] ?? '' ) ), 0, 60 ),
        'subject'      => mb_substr( sanitize_text_field( (string) ( $params['subject'] ?? '' ) ), 0, 200 ),
        'message'      => mb_substr( sanitize_textarea_field( (string) ( $params['message'] ?? '' ) ), 0, 5000 ),
    );

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

    if ( nsw_theme_contact_mantis_configured() ) {
        $issue = nsw_theme_contact_create_mantis_issue( $data );
        if ( is_wp_error( $issue ) ) { return $issue; }
        $ref = $issue['id'] ? 'NSWAL-' . $issue['id'] : '';

        if ( $ref ) {
            try { nsw_theme_contact_send_confirmation_email( $data, $ref ); }
            catch ( \Throwable $e ) { error_log( 'NSW Theme contact: confirmation email failed (non-fatal): ' . $e->getMessage() ); }
        }
        return new WP_REST_Response( array( 'success' => true, 'ticket' => $ref, 'message' => __( 'Message sent.', 'nsw-theme' ) ), 200 );
    }

    // Fallback: email the admin when Mantis isn't configured (demo/staging).
    if ( ! nsw_theme_contact_send_admin_notification( $data ) ) {
        return new WP_Error( 'nsw_theme_mail_failed', __( 'Could not send the message. Please try again later.', 'nsw-theme' ), array( 'status' => 500 ) );
    }
    return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Message sent.', 'nsw-theme' ) ), 200 );
}

/* ---- Customer confirmation email ---------------------------------------- */
function nsw_theme_contact_send_confirmation_email( array $data, string $ref ): bool {
    $site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
    $from      = nsw_theme_contact_config( 'contact_from' );
    $reply_to  = nsw_theme_contact_config( 'contact_reply_to' );
    if ( '' === $from )     { $from = nsw_theme_contact_config( 'smtp_user' ); }
    if ( '' === $from )     { $from = (string) get_option( 'admin_email' ); }
    if ( '' === $reply_to ) { $reply_to = $from; }

    $subject = '[' . $ref . '] ' . __( "We've received your message", 'nsw-theme' );
    $name    = esc_html( $data['fullName'] );
    $ref_h   = esc_html( $ref );
    $msg_sub = esc_html( $data['subject'] );
    $msg     = esc_html( $data['message'] );

    $greeting = sprintf( __( 'Hi %s,', 'nsw-theme' ), $name );
    $thanks   = sprintf(
        __( 'Thank you for contacting <strong>%1$s</strong>. Your message has been received and assigned reference <strong>%2$s</strong>.', 'nsw-theme' ),
        esc_html( $site_name ), $ref_h
    );

    $body  = '<div style="font-family:system-ui,-apple-system,sans-serif;color:#222;line-height:1.5;max-width:600px">';
    $body .= '<p>' . $greeting . '</p>';
    $body .= '<p>' . $thanks . '</p>';
    $body .= '<p>' . esc_html__( 'We aim to respond within 36 hours.', 'nsw-theme' ) . '</p>';
    $body .= '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0">';
    $body .= '<p style="color:#666;font-size:13px;margin:0 0 8px"><strong>' . esc_html__( 'Your message:', 'nsw-theme' ) . '</strong></p>';
    $body .= '<p style="color:#666;font-size:13px;margin:0 0 4px"><em>' . $msg_sub . '</em></p>';
    $body .= '<p style="color:#666;font-size:13px;white-space:pre-wrap;margin:0">' . $msg . '</p></div>';

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: "' . str_replace( '"', '', $site_name ) . '" <' . $from . '>',
        'Reply-To: ' . $reply_to,
    );
    return (bool) wp_mail( $data['email'], $subject, $body, $headers );
}

/* ---- Admin fallback notification (Mantis not configured) ----------------- */
function nsw_theme_contact_send_admin_notification( array $data ): bool {
    $to = (string) get_option( 'nsw_theme_contact_email', get_option( 'admin_email' ) );
    if ( '' === $to ) { $to = (string) get_option( 'admin_email' ); }
    $site    = (string) get_bloginfo( 'name' );
    $subject = sprintf( '[%s] %s', $site, $data['subject'] );

    $reply_name = trim( str_replace( array( "\r", "\n" ), '', $data['fullName'] ) );
    $headers    = array(
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: "' . addslashes( $reply_name ) . '" <' . $data['email'] . '>',
    );
    $rows = array(
        __( 'Name', 'nsw-theme' )         => $data['fullName'],
        __( 'Email', 'nsw-theme' )        => $data['email'],
        __( 'Organization', 'nsw-theme' ) => $data['organization'],
        __( 'Category', 'nsw-theme' )     => $data['category'],
        __( 'Agency', 'nsw-theme' )       => $data['agency'],
        __( 'Subject', 'nsw-theme' )      => $data['subject'],
    );
    $body = '<h2>' . esc_html__( 'New contact submission', 'nsw-theme' ) . '</h2><table style="border-collapse:collapse;width:100%;max-width:600px">';
    foreach ( $rows as $label => $value ) {
        if ( '' === $value ) { continue; }
        $body .= sprintf( '<tr><td style="padding:8px 12px;font-weight:bold;border-bottom:1px solid #eee">%s</td><td style="padding:8px 12px;border-bottom:1px solid #eee">%s</td></tr>', esc_html( $label ), esc_html( $value ) );
    }
    $body .= sprintf( '<tr><td style="padding:8px 12px;font-weight:bold;vertical-align:top">%s</td><td style="padding:8px 12px;white-space:pre-wrap">%s</td></tr>', esc_html__( 'Message', 'nsw-theme' ), nl2br( esc_html( $data['message'] ) ) );
    $body .= '</table>';
    return (bool) wp_mail( $to, $subject, $body, $headers );
}

/* ---- SMTP via PHPMailer (when NSW_THEME_SMTP_* are defined) --------------- */
add_action( 'phpmailer_init', function ( $phpmailer ) {
    $host = nsw_theme_contact_config( 'smtp_host' );
    $user = nsw_theme_contact_config( 'smtp_user' );
    $pass = nsw_theme_contact_config( 'smtp_pass' );
    if ( '' === $host || '' === $user || '' === $pass ) { return; }
    $port = (int) ( nsw_theme_contact_config( 'smtp_port', '465' ) ?: 465 );
    $phpmailer->isSMTP();
    $phpmailer->Host       = $host;
    $phpmailer->Port       = $port;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = $user;
    $phpmailer->Password   = $pass;
    $phpmailer->SMTPSecure = ( 465 === $port ) ? 'ssl' : 'tls';
    $phpmailer->SMTPOptions = array( 'ssl' => array( 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true ) );
} );

/* ---- Settings → General -------------------------------------------------- */
add_action( 'admin_init', function () {
    $emails = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'show_in_rest' => false, 'default' => '' );
    register_setting( 'general', 'nsw_theme_contact_email',    $emails );
    register_setting( 'general', 'nsw_theme_contact_from',     $emails );
    register_setting( 'general', 'nsw_theme_contact_reply_to', $emails );

    add_settings_section( 'nsw_theme_contact_section', __( 'NSW Contact Form', 'nsw-theme' ), function () {
        echo '<p>' . esc_html__( 'Contact form → MantisBT. Secrets (Mantis URL, API token, project id, SMTP password) are defined as constants in wp-config.php — see inc/contact-form.php.', 'nsw-theme' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Status:', 'nsw-theme' ) . '</strong> ';
        if ( nsw_theme_contact_mantis_configured() ) {
            echo '<span style="color:#2e7d32">' . esc_html__( 'MantisBT is configured — submissions create tickets.', 'nsw-theme' ) . '</span>';
        } else {
            echo '<span style="color:#ef6c00">' . esc_html__( 'MantisBT NOT configured — submissions fall back to admin email.', 'nsw-theme' ) . '</span>';
        }
        echo '</p>';
    }, 'general' );

    $text_field = function ( string $name, string $label, string $description = '' ) {
        add_settings_field( $name, $label, function () use ( $name, $description ) {
            printf( '<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( (string) get_option( $name, '' ) ) );
            if ( $description ) { echo '<p class="description">' . esc_html( $description ) . '</p>'; }
        }, 'general', 'nsw_theme_contact_section' );
    };
    $text_field( 'nsw_theme_contact_email',    __( 'Admin recipient', 'nsw-theme' ), __( 'Fallback email when Mantis isn\'t configured. Defaults to the site admin email.', 'nsw-theme' ) );
    $text_field( 'nsw_theme_contact_from',     __( 'Confirmation email From', 'nsw-theme' ), __( 'Sender of the customer confirmation. Defaults to SMTP user, then admin email.', 'nsw-theme' ) );
    $text_field( 'nsw_theme_contact_reply_to', __( 'Confirmation email Reply-To', 'nsw-theme' ), __( 'Where customer replies go. Defaults to From.', 'nsw-theme' ) );
} );
