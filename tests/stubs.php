<?php
/**
 * Lightweight stand-ins for the few WordPress / Loupe Search classes the plugin
 * references via instanceof or type hints in the code under test.
 */

declare(strict_types=1);

namespace {

if ( ! class_exists( 'WP_Site' ) ) {
	class WP_Site {
		public $blog_id = 0;
		public $public = '1';
		public $archived = '0';
		public $deleted = '0';
		public $spam = '0';
		public $domain = '';
		public $path = '/';
		public $blogname = '';

		public function __construct( array $props = [] ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_type = 'post';
		public $post_status = 'publish';
		public $post_title = '';
		public $post_content = '';
		public $post_excerpt = '';
		public $post_date = '';
		public $post_password = '';

		public function __construct( array $props = [] ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

}

namespace Soderlind\Plugin\WPLoupe {

// Stand-in for Loupe Search's indexer; the plugin only calls prepare_document().
if ( ! class_exists( __NAMESPACE__ . '\\WP_Loupe_Indexer' ) ) {
	class WP_Loupe_Indexer {
		/** @var array<string,mixed> */
		public static array $next_document = [
			'post_title'   => 'Prepared Title',
			'post_content' => 'Prepared content',
			'post_excerpt' => 'Prepared excerpt',
			'post_date'    => '2020-01-01 00:00:00',
		];

		public function __construct( $post_types = null, bool $register_hooks = true ) {
		}

		public function prepare_document( \WP_Post $post ): array {
			return self::$next_document;
		}
	}
}

}
