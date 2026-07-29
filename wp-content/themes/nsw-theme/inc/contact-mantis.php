<?php
/**
 * MantisBT contact client — config resolver, label maps, pure payload builders,
 * and the REST transport. No hooks registered at load, so this file is
 * unit-testable under plain PHP (see tests/contact-mantis-test.php).
 *
 * Secrets (wp-config.php constants):
 *   NSW_THEME_MANTIS_URL         base URL, trailing slash (REST at {URL}api/rest/)
 *   NSW_THEME_MANTIS_TOKEN       API token for the nswal_web service account
 *   NSW_THEME_MANTIS_PROJECT_ID  Mantis project id (default "1")
 *
 * @package NSW_Theme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Constants (wp-config.php) win over wp_options so secrets never land in the DB. */
function nsw_theme_contact_config( string $key, string $default = '' ): string {
    $const = 'NSW_THEME_' . strtoupper( $key );
    if ( defined( $const ) ) { return (string) constant( $const ); }
    return (string) get_option( 'nsw_theme_' . strtolower( $key ), $default );
}

/** True only when URL + token + project id are all set. */
function nsw_theme_contact_mantis_configured(): bool {
    return '' !== nsw_theme_contact_config( 'mantis_url' )
        && '' !== nsw_theme_contact_config( 'mantis_token' )
        && '' !== nsw_theme_contact_config( 'mantis_project_id', '1' );
}

/** Form category key => Mantis category name (must match sql/nswal_setup.sql). */
function nsw_theme_contact_category_labels(): array {
    return apply_filters( 'nsw_theme_contact_category_labels', array(
        'general'      => 'General inquiry',
        'lpco'         => 'LPCO process',
        'registration' => 'Registration',
        'payment'      => 'Payment',
        'technical'    => 'Technical issue',
        'feedback'     => 'Feedback',
        'other'        => 'Other',
    ) );
}

/** Agency slug => human label (best-effort; dynamic CPT slugs fall through to raw value). */
function nsw_theme_contact_agency_labels(): array {
    return apply_filters( 'nsw_theme_contact_agency_labels', array(
        'general' => 'General',
        'customs' => 'Customs',
        'other'   => 'Other',
    ) );
}

/** Map a form category key to a Mantis category name; fallback to 'General inquiry'. */
function nsw_theme_contact_map_category( string $key ): string {
    $labels = nsw_theme_contact_category_labels();
    return $labels[ $key ] ?? 'General inquiry';
}

/** Plain-text description: `From:` first line (agent-facing), meta lines, then the message. */
function nsw_theme_contact_build_description( array $data ): string {
    $lines   = array();
    $lines[] = 'From: ' . $data['fullName'] . ' <' . $data['email'] . '>';
    if ( '' !== ( $data['organization'] ?? '' ) ) {
        $lines[] = 'Organization: ' . $data['organization'];
    }
    $lines[] = 'Category: ' . nsw_theme_contact_map_category( (string) ( $data['category'] ?? '' ) );
    if ( '' !== ( $data['agency'] ?? '' ) ) {
        $agencies = nsw_theme_contact_agency_labels();
        $lines[]  = 'Agency: ' . ( $agencies[ $data['agency'] ] ?? $data['agency'] );
    }
    $lines[] = '';
    $lines[] = (string) ( $data['message'] ?? '' );
    return implode( "\n", $lines );
}

/** Build the REST custom_fields array (referenced by name). Blank optional fields are skipped. */
function nsw_theme_contact_build_custom_fields( array $data ): array {
    $fields   = array();
    $agencies = nsw_theme_contact_agency_labels();
    $add = function ( $name, $value ) use ( &$fields ) {
        if ( '' !== (string) $value ) {
            $fields[] = array( 'field' => array( 'name' => $name ), 'value' => (string) $value );
        }
    };
    $add( 'Customer Name',  $data['fullName'] ?? '' );
    $add( 'Customer Email', $data['email'] ?? '' );
    $add( 'Organization',   $data['organization'] ?? '' );
    if ( '' !== ( $data['agency'] ?? '' ) ) {
        $add( 'Relevant Agency', $agencies[ $data['agency'] ] ?? $data['agency'] );
    }
    $add( 'Source Channel', 'Portal' );
    return $fields;
}

/** Assemble the full MantisBT issue payload. */
function nsw_theme_contact_build_issue_payload( array $data ): array {
    return array(
        'summary'       => (string) ( $data['subject'] ?? '' ),
        'description'   => nsw_theme_contact_build_description( $data ),
        'project'       => array( 'id' => (int) nsw_theme_contact_config( 'mantis_project_id', '1' ) ),
        'category'      => array( 'name' => nsw_theme_contact_map_category( (string) ( $data['category'] ?? '' ) ) ),
        'custom_fields' => nsw_theme_contact_build_custom_fields( $data ),
    );
}

/**
 * Create a MantisBT issue via REST. Returns array{id:int} or WP_Error.
 *
 * @param array $data Validated form data.
 * @return array{id:int}|WP_Error
 */
function nsw_theme_contact_create_mantis_issue( array $data ) {
    $base  = rtrim( nsw_theme_contact_config( 'mantis_url' ), '/' );
    $token = nsw_theme_contact_config( 'mantis_token' );

    $response = wp_remote_post(
        $base . '/api/rest/issues',
        array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => $token,           // raw token, no Bearer prefix
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( nsw_theme_contact_build_issue_payload( $data ) ),
        )
    );

    if ( is_wp_error( $response ) ) {
        error_log( 'NSW Theme contact: Mantis transport error: ' . $response->get_error_message() );
        return new WP_Error( 'nsw_theme_mantis_failed', __( 'Failed to create support request.', 'nsw-theme' ), array( 'status' => 502 ) );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );

    if ( $code < 200 || $code >= 300 ) {
        error_log( 'NSW Theme contact: Mantis create issue error: ' . $code . ' — ' . $body );
        return new WP_Error( 'nsw_theme_mantis_failed', __( 'Failed to create support request.', 'nsw-theme' ), array( 'status' => 502 ) );
    }

    $decoded = json_decode( $body, true );
    $id      = ( is_array( $decoded ) && isset( $decoded['issue']['id'] ) ) ? (int) $decoded['issue']['id'] : 0;
    return array( 'id' => $id );
}
