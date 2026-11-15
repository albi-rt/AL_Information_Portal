<?php
/**
 * Minimal stand-ins for the WordPress classes the theme uses: WP_Error, WP_Post,
 * WP_User, WP_REST_Request, WP_REST_Response and WP_REST_Server.
 *
 * They follow the public behaviour of the WordPress classes for the methods the theme
 * calls. They are test-only and are never loaded by WordPress.
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string|int, array<int, string>> */
		public $errors = array();
		/** @var array<string|int, mixed> */
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->add( $code, $message, $data );
		}

		public function add( $code, $message, $data = '' ): void {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}

		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		public function get_error_message( $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_messages( $code = '' ): array {
			if ( '' === $code ) {
				$all = array();
				foreach ( $this->errors as $messages ) {
					$all = array_merge( $all, $messages );
				}
				return $all;
			}
			return $this->errors[ $code ] ?? array();
		}

		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return $this->error_data[ $code ] ?? null;
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_author = '0';
		public $post_date = '0000-00-00 00:00:00';
		public $post_date_gmt = '0000-00-00 00:00:00';
		public $post_content = '';
		public $post_title = '';
		public $post_excerpt = '';
		public $post_status = 'publish';
		public $post_name = '';
		public $post_modified = '0000-00-00 00:00:00';
		public $post_modified_gmt = '0000-00-00 00:00:00';
		public $post_parent = 0;
		public $menu_order = 0;
		public $post_type = 'post';
		public $comment_count = '0';

		/** @param object|array<string, mixed> $post */
		public function __construct( $post = array() ) {
			foreach ( (array) $post as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public $user_login = '';
		public $user_email = '';
		public $display_name = '';
		/** @var array<int, string> */
		public $roles = array();
		/** @var array<string, bool> */
		public $allcaps = array();

		/** @param object|array<string, mixed> $user */
		public function __construct( $user = array() ) {
			foreach ( (array) $user as $key => $value ) {
				$this->$key = $value;
			}
		}

		public function exists(): bool {
			return ! empty( $this->ID );
		}

		public function has_cap( $cap, ...$args ): bool {
			return ! empty( $this->allcaps[ $cap ] );
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'POST, PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		protected $method = '';
		protected $route = '';
		/** @var array<string, mixed> */
		protected $params = array();
		/** @var array<string, mixed>|null */
		protected $json_params = null;
		/** @var array<string, array<int, string>> */
		protected $headers = array();
		/** @var array<string, mixed> */
		protected $file_params = array();
		protected $body = '';

		public function __construct( $method = '', $route = '', $attributes = array() ) {
			$this->method = strtoupper( (string) $method );
			$this->route  = (string) $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function set_method( $method ): void {
			$this->method = strtoupper( (string) $method );
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_param( $key ) {
			if ( is_array( $this->json_params ) && array_key_exists( $key, $this->json_params ) ) {
				return $this->json_params[ $key ];
			}
			return $this->params[ $key ] ?? null;
		}

		public function set_param( $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function has_param( $key ): bool {
			return ( is_array( $this->json_params ) && array_key_exists( $key, $this->json_params ) )
				|| array_key_exists( $key, $this->params );
		}

		public function get_params(): array {
			return array_merge( $this->params, is_array( $this->json_params ) ? $this->json_params : array() );
		}

		public function set_query_params( array $params ): void {
			$this->params = array_merge( $this->params, $params );
		}

		public function set_body_params( array $params ): void {
			$this->params = array_merge( $this->params, $params );
		}

		public function get_body_params(): array {
			return $this->params;
		}

		public function get_query_params(): array {
			return $this->params;
		}

		public function get_json_params() {
			return $this->json_params;
		}

		public function set_json_params( ?array $params ): void {
			$this->json_params = $params;
		}

		public function set_body( $body ): void {
			$this->body    = (string) $body;
			$decoded       = json_decode( $this->body, true );
			$this->json_params = is_array( $decoded ) ? $decoded : null;
		}

		public function get_body(): string {
			return $this->body;
		}

		public function get_file_params(): array {
			return $this->file_params;
		}

		public function set_file_params( array $files ): void {
			$this->file_params = $files;
		}

		public function set_header( $key, $value ): void {
			$this->headers[ strtolower( str_replace( '-', '_', (string) $key ) ) ] = (array) $value;
		}

		public function get_header( $key ): ?string {
			$key = strtolower( str_replace( '-', '_', (string) $key ) );
			return isset( $this->headers[ $key ] ) ? implode( ',', $this->headers[ $key ] ) : null;
		}

		public function get_headers(): array {
			return $this->headers;
		}

		#[\ReturnTypeWillChange]
		public function offsetExists( $offset ) {
			return $this->has_param( $offset );
		}

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->get_param( $offset );
		}

		#[\ReturnTypeWillChange]
		public function offsetSet( $offset, $value ) {
			$this->set_param( $offset, $value );
		}

		#[\ReturnTypeWillChange]
		public function offsetUnset( $offset ) {
			unset( $this->params[ $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		/** @var array<string, string> */
		public $headers = array();

		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data    = $data;
			$this->status  = (int) $status;
			$this->headers = (array) $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function set_data( $data ): void {
			$this->data = $data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function set_status( $status ): void {
			$this->status = (int) $status;
		}

		public function get_headers(): array {
			return $this->headers;
		}

		public function header( $key, $value, $replace = true ): void {
			$this->headers[ $key ] = (string) $value;
		}
	}
}
