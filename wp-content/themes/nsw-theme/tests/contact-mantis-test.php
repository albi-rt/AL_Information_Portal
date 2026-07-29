<?php
/** Plain-PHP unit tests for the MantisBT contact client. Run: php tests/contact-mantis-test.php */
require __DIR__ . '/wp-shims.php';
define( 'NSW_THEME_MANTIS_URL',        'http://localhost:8090/' );
define( 'NSW_THEME_MANTIS_TOKEN',      'tok-123' );
define( 'NSW_THEME_MANTIS_PROJECT_ID', '1' );
require __DIR__ . '/../inc/contact-mantis.php';

$fails = 0;
function check( $label, $cond ) {
    global $fails;
    if ( $cond ) { echo "  ok  - $label\n"; }
    else { echo "  FAIL- $label\n"; $fails++; }
}

$data = array(
    'fullName'     => 'Jane Doe',
    'email'        => 'jane@example.com',
    'organization' => 'ACME sh.p.k.',
    'category'     => 'technical',
    'agency'       => 'customs',
    'subject'      => 'Cannot upload LPCO',
    'message'      => "Line one.\nLine two.",
);

// map_category
check( 'map known category', nsw_theme_contact_map_category( 'technical' ) === 'Technical issue' );
check( 'map unknown category falls back', nsw_theme_contact_map_category( 'zzz' ) === 'General inquiry' );

// build_description
$desc = nsw_theme_contact_build_description( $data );
check( 'description first line is From', strpos( $desc, "From: Jane Doe <jane@example.com>" ) === 0 );
check( 'description has Category label', strpos( $desc, "Category: Technical issue" ) !== false );
check( 'description has Organization', strpos( $desc, "Organization: ACME sh.p.k." ) !== false );
check( 'description contains message body', strpos( $desc, "Line one.\nLine two." ) !== false );

// build_custom_fields
$cf = nsw_theme_contact_build_custom_fields( $data );
$byName = array();
foreach ( $cf as $row ) { $byName[ $row['field']['name'] ] = $row['value']; }
check( 'cf Customer Name',  ( $byName['Customer Name']  ?? '' ) === 'Jane Doe' );
check( 'cf Customer Email', ( $byName['Customer Email'] ?? '' ) === 'jane@example.com' );
check( 'cf Organization',   ( $byName['Organization']   ?? '' ) === 'ACME sh.p.k.' );
check( 'cf Source Channel', ( $byName['Source Channel'] ?? '' ) === 'Portal' );

// build_issue_payload
$p = nsw_theme_contact_build_issue_payload( $data );
check( 'payload summary',      $p['summary'] === 'Cannot upload LPCO' );
check( 'payload project id',   $p['project']['id'] === 1 );
check( 'payload category name', $p['category']['name'] === 'Technical issue' );
check( 'payload has custom_fields array', is_array( $p['custom_fields'] ) && count( $p['custom_fields'] ) >= 4 );

// ---- transport: success ----
$GLOBALS['__wp_remote_post_return'] = array(
    'response' => array( 'code' => 201 ),
    'body'     => json_encode( array( 'issue' => array( 'id' => 42 ) ) ),
);
$res = nsw_theme_contact_create_mantis_issue( $data );
check( 'create returns id on 201', is_array( $res ) && ( $res['id'] ?? 0 ) === 42 );
check( 'POST hit issues endpoint', strpos( $GLOBALS['__wp_remote_post_args']['url'], '/api/rest/issues' ) !== false );
check( 'Authorization header is raw token',
    ( $GLOBALS['__wp_remote_post_args']['args']['headers']['Authorization'] ?? '' ) === 'tok-123' );

// ---- transport: HTTP error ----
$GLOBALS['__wp_remote_post_return'] = array(
    'response' => array( 'code' => 400 ),
    'body'     => '{"message":"bad"}',
);
$res = nsw_theme_contact_create_mantis_issue( $data );
check( 'create returns WP_Error on 400', is_wp_error( $res ) );

// ---- transport: connection error ----
$GLOBALS['__wp_remote_post_return'] = new WP_Error( 'http_request_failed', 'down' );
$res = nsw_theme_contact_create_mantis_issue( $data );
check( 'create returns WP_Error on transport error', is_wp_error( $res ) );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
