<?php
/**
 * LIVE integration test: exercises the REAL contact client (inc/contact-mantis.php —
 * actual builders + transport) against a running MantisBT, using a wp_remote_post
 * shim that performs a genuine HTTP POST. Proves the WordPress-side payload is
 * accepted and parsed correctly end-to-end.
 *
 * Requires a live Mantis. Run:
 *   MANTIS_URL=http://localhost:8090/ MANTIS_TOKEN=<32-char token> \
 *   /opt/homebrew/opt/php/bin/php tests/contact-mantis-integration.php
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

define( 'NSW_THEME_MANTIS_URL',        getenv( 'MANTIS_URL' )   ?: 'http://localhost:8090/' );
define( 'NSW_THEME_MANTIS_TOKEN',      getenv( 'MANTIS_TOKEN' ) ?: 'nswaldevtoken0000000000000000000' );
define( 'NSW_THEME_MANTIS_PROJECT_ID', getenv( 'MANTIS_PROJECT_ID' ) ?: '1' );

/* --- Real HTTP shims (curl-backed), WP-compatible shapes --- */
function apply_filters( $tag, $value ) { return $value; }
function get_option( $name, $default = false ) { return $default; }
function __( $t, $d = 'default' ) { return $t; }
function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
class WP_Error {
    public $code, $message;
    public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->message = $m; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_post( $url, $args = array() ) {
    $ch = curl_init( $url );
    $headers = array();
    foreach ( ( $args['headers'] ?? array() ) as $k => $v ) { $headers[] = "$k: $v"; }
    curl_setopt_array( $ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $args['body'] ?? '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $args['timeout'] ?? 15,
    ) );
    $body = curl_exec( $ch );
    if ( false === $body ) { $e = curl_error( $ch ); curl_close( $ch ); return new WP_Error( 'http_request_failed', $e ); }
    $code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
    curl_close( $ch );
    return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

require __DIR__ . '/../inc/contact-mantis.php';

$fails = 0;
function check( $label, $cond ) { global $fails; echo ( $cond ? "  ok  - " : "  FAIL- " ) . "$label\n"; if ( ! $cond ) { $fails++; } }

/* Skip gracefully if Mantis isn't reachable. */
$ping = @file_get_contents( rtrim( NSW_THEME_MANTIS_URL, '/' ) . '/api/rest/issues?page_size=1', false,
    stream_context_create( array( 'http' => array( 'header' => 'Authorization: ' . NSW_THEME_MANTIS_TOKEN, 'ignore_errors' => true, 'timeout' => 3 ) ) ) );
if ( false === $ping ) { echo "SKIP: Mantis not reachable at " . NSW_THEME_MANTIS_URL . "\n"; exit( 0 ); }

$data = array(
    'fullName'     => 'Arben Krasniqi',
    'email'        => 'arben@example.al',
    'organization' => 'Doganat Shqiptare',
    'category'     => 'lpco',
    'agency'       => 'customs',
    'subject'      => 'LPCO upload fails',
    'message'      => "Përshëndetje,\nnuk mund të ngarkoj dokumentin LPCO.",
);

$res = nsw_theme_contact_create_mantis_issue( $data );
check( 'client returned an issue id (not WP_Error)', is_array( $res ) && ( $res['id'] ?? 0 ) > 0 );
if ( is_wp_error( $res ) ) { echo "  error: " . $res->get_error_message() . "\n\n$fails FAILED\n"; exit( 1 ); }
$id = (int) $res['id'];
echo "  created ticket #$id\n";

/* Fetch it back and verify the mapped fields. */
$raw = file_get_contents( rtrim( NSW_THEME_MANTIS_URL, '/' ) . "/api/rest/issues/$id", false,
    stream_context_create( array( 'http' => array( 'header' => 'Authorization: ' . NSW_THEME_MANTIS_TOKEN, 'ignore_errors' => true ) ) ) );
$issue = json_decode( $raw, true )['issues'][0] ?? ( json_decode( $raw, true )['issue'] ?? array() );

check( 'summary matches subject', ( $issue['summary'] ?? '' ) === 'LPCO upload fails' );
check( 'category mapped to "LPCO process"', ( $issue['category']['name'] ?? '' ) === 'LPCO process' );
check( 'reporter is the service account nswal_web', ( $issue['reporter']['name'] ?? '' ) === 'nswal_web' );
$cf = array();
foreach ( ( $issue['custom_fields'] ?? array() ) as $row ) { $cf[ $row['field']['name'] ] = $row['value']; }
check( 'cf Customer Name',   ( $cf['Customer Name']   ?? '' ) === 'Arben Krasniqi' );
check( 'cf Organization',    ( $cf['Organization']    ?? '' ) === 'Doganat Shqiptare' );
check( 'cf Relevant Agency mapped to "Customs"', ( $cf['Relevant Agency'] ?? '' ) === 'Customs' );
check( 'cf Source Channel = Portal', ( $cf['Source Channel'] ?? '' ) === 'Portal' );
check( 'description starts with From: line', strpos( (string) ( $issue['description'] ?? '' ), 'From: Arben Krasniqi <arben@example.al>' ) === 0 );

echo $fails === 0 ? "\nINTEGRATION PASS (ticket #$id)\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
