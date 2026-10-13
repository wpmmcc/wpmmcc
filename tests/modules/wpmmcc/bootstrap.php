<?php
/**
 * WPMMCC Test Bootstrap & Mock Harness.
 *
 * Provides a self-contained test environment for unit and contract tests
 * without requiring a full live WordPress installation for unit testing.
 *
 * @package WPMMCC\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
}

if ( ! defined( 'WPMMCC_TEST_RUN' ) ) {
	define( 'WPMMCC_TEST_RUN', true );
}

// Emulate WordPress constants
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-salt-32-chars-long-12345' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

// In-memory options and transients store for standalone testing
global $wp_mock_options, $wp_mock_transients, $wp_mock_posts, $wp_mock_meta, $wpdb, $wp_mock_rest_routes;
$wp_mock_options    = array();
$wp_mock_transients = array();
$wp_mock_posts      = array();
$wp_mock_meta       = array();
$wp_mock_rest_routes = array();

// Mock WPDB
if ( ! class_exists( 'Mock_wpdb' ) ) {
	class Mock_wpdb {
		public $prefix = 'wp_';
		public $insert_id = 1;
		public $options = 'wp_options';
		public $last_error = '';
		public $sitemeta = 'wp_sitemeta';
		public $posts = 'wp_posts';
		public $postmeta = 'wp_postmeta';
		public $terms = 'wp_terms';
		public $termmeta = 'wp_termmeta';
		public $term_taxonomy = 'wp_term_taxonomy';
		public $term_relationships = 'wp_term_relationships';
		public $last_query = '';
		public $queries = array();
		public $dbh;
		public $connection_id = 1;
		public $database = 'wp_mock';
		public $blocked_locks = array();
		public $autocommit = 1;
		private $named_locks = array();

		public function __construct() {
			$this->dbh = (object) array( 'server_status' => 0 );
		}

		protected function native_builtin( string $sql, &$handled ) {
			$this->last_error = '';
			$handled = true;
			$norm = trim( $sql );
			if ( preg_match( "/^SELECT\\s+(GET_LOCK|RELEASE_LOCK|IS_USED_LOCK)\\(\\s*'([^']+)'(?:\\s*,\\s*0)?\\s*\\)\\s*$/i", $norm, $m ) ) {
				$name = stripslashes( $m[2] );
				switch ( strtoupper( $m[1] ) ) {
					case 'GET_LOCK':
						if ( isset( $this->blocked_locks[ $name ] ) ) {
							return '0';
						}
						$this->named_locks[ $name ] = ( $this->named_locks[ $name ] ?? 0 ) + 1;
						return '1';
					case 'IS_USED_LOCK':
						return isset( $this->named_locks[ $name ] ) ? (string) $this->connection_id : null;
					case 'RELEASE_LOCK':
						if ( ! isset( $this->named_locks[ $name ] ) ) {
							return null;
						}
						if ( 0 === --$this->named_locks[ $name ] ) {
							unset( $this->named_locks[ $name ] );
						}
						return '1';
				}
			}
			if ( preg_match( '/^SELECT\\s+CONNECTION_ID\\(\\s*\\)\\s*$/i', $norm ) ) {
				return (string) $this->connection_id;
			}
			if ( preg_match( '/^SELECT\\s+DATABASE\\(\\s*\\)\\s*$/i', $norm ) ) {
				return $this->database;
			}
			if ( 'SELECT @@SESSION.AUTOCOMMIT' === strtoupper( $norm ) ) {
				return (string) $this->autocommit;
			}
			$handled = false;
			return null;
		}

		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function prepare( $query, ...$args ) {
			if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
				$args = $args[0];
			}
			$remaining = array();
			$index     = 0;
			$query     = preg_replace_callback(
				'/%(%|i|[sdf])/',
				function ( $match ) use ( $args, &$index, &$remaining ) {
					if ( '%' === $match[1] ) {
						return '%%';
					}
					$arg = $args[ $index ] ?? '';
					$index++;
					if ( 'i' === $match[1] ) {
						$ident = str_replace( '`', '``', (string) $arg );
						return '`' . $ident . '`';
					}
					$remaining[] = "'" . addslashes( (string) $arg ) . "'";
					return '%s';
				},
				$query
			);
			if ( empty( $remaining ) ) {
				return str_replace( '%%', '%', $query );
			}
			return vsprintf( $query, $remaining );
		}

		public $var_callback = null;
		public $row_callback = null;
		public $results_callback = null;

		public function query( $sql ) {
			$this->last_query = $sql;
			$this->queries[]  = $sql;
			return true;
		}

		public function get_var( $sql ) {
			$this->last_query = $sql;
			$value = $this->native_builtin( (string) $sql, $handled );
			if ( $handled ) {
				return $value;
			}
			if ( is_callable( $this->var_callback ) ) {
				return call_user_func( $this->var_callback, $sql );
			}
			return null;
		}

		public function get_row( $sql ) {
			$this->last_query = $sql;
			if ( is_callable( $this->row_callback ) ) {
				return call_user_func( $this->row_callback, $sql );
			}
			return null;
		}

		public function get_results( $sql ) {
			$this->last_query = $sql;
			if ( is_callable( $this->results_callback ) ) {
				return call_user_func( $this->results_callback, $sql );
			}
			return array();
		}

		public function insert( $table, $data, $format = null ) {
			$this->insert_id++;
			$this->last_query = "INSERT INTO {$table} " . json_encode( $data );
			$this->queries[]  = $this->last_query;
			return 1;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->last_query = "UPDATE {$table} SET " . json_encode( $data ) . " WHERE " . json_encode( $where );
			$this->queries[]  = $this->last_query;
			return 1;
		}

		public function replace( $table, $data, $format = null ) {
			return 1;
		}

		public function delete( $table, $where, $where_format = null ) {
			return 1;
		}
	}
}

$wpdb = new Mock_wpdb();

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		foreach ( (array) ( $response['headers'] ?? array() ) as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, (string) $header ) ) {
				return $value;
			}
		}
		return '';
	}
}

// Mock WP Core functions if not defined
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		global $wp_mock_options;
		return $wp_mock_options[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $val, $autoload = null ) {
		global $wp_mock_options, $wp_mock_option_autoload;
		$wp_mock_options[ $key ]         = $val;
		$wp_mock_option_autoload[ $key ] = $autoload; // Recorded so tests can pin "never autoloaded".
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $val, $deprecated = '', $autoload = 'yes' ) {
		global $wp_mock_options;
		if ( ! isset( $wp_mock_options[ $key ] ) ) {
			$wp_mock_options[ $key ] = $val;
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		global $wp_mock_options;
		unset( $wp_mock_options[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( $key ) {
		return delete_option( $key );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $wp_mock_transients;
		if ( isset( $wp_mock_transients[ $key ] ) ) {
			if ( $wp_mock_transients[ $key ]['expires'] >= time() ) {
				return $wp_mock_transients[ $key ]['value'];
			}
			unset( $wp_mock_transients[ $key ] );
		}
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $val, $ttl = 0 ) {
		global $wp_mock_transients;
		$wp_mock_transients[ $key ] = array(
			'value'   => $val,
			'expires' => time() + ( $ttl ?: 86400 ),
		);
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		global $wp_mock_transients;
		unset( $wp_mock_transients[ $key ] );
		return true;
	}
}

global $wp_mock_cache;
$wp_mock_cache = array();

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		global $wp_mock_cache;
		$composite_key = $group . ':' . $key;
		if ( isset( $wp_mock_cache[ $composite_key ] ) ) {
			$found = true;
			return $wp_mock_cache[ $composite_key ];
		}
		$found = false;
		return false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		global $wp_mock_cache;
		$composite_key = $group . ':' . $key;
		$wp_mock_cache[ $composite_key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		global $wp_mock_cache;
		$composite_key = $group . ':' . $key;
		if ( isset( $wp_mock_cache[ $composite_key ] ) ) {
			unset( $wp_mock_cache[ $composite_key ] );
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		global $wp_mock_cache;
		$wp_mock_cache = array();
		return true;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		return @unlink( $file );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() {
		return 'https://site-a.example.com';
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url() {
		return get_site_url();
	}
}

if ( ! function_exists( 'get_current_network_id' ) ) {
	function get_current_network_id() {
		return 1;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 1;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		$data = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return substr( hash( 'sha256', 'wpmmcc-test-nonce|' . (string) $action ), 0, 10 );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		if ( ! is_array( $args ) ) {
			return (string) $url;
		}
		$parts = parse_url( (string) $url );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		foreach ( $args as $key => $value ) {
			$query[ $key ] = $value;
		}
		$base = '';
		if ( isset( $parts['scheme'] ) ) {
			$base .= $parts['scheme'] . '://' . ( $parts['host'] ?? '' );
		}
		$base .= $parts['path'] ?? '';
		return $base . ( $query ? '?' . http_build_query( $query ) : '' );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		$nonce = (string) $nonce;
		if ( '' === $nonce ) {
			return false;
		}
		return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( preg_replace( '/[^a-z0-9\-]/i', '-', (string) $title ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return trim( preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $filename ), '-.' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		switch ( $type ) {
			case 'mysql':
				return gmdate( 'Y-m-d H:i:s' );
			case 'timestamp':
				return time();
			default:
				return gmdate( $type );
		}
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $data ) {
		return addslashes( (string) $data );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		return 'WordPress Site';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return (string) get_option( 'WPLANG', 'en_US' );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
		global $wp_mock_meta;
		if ( ! isset( $wp_mock_meta[ $post_id ] ) ) {
			$wp_mock_meta[ $post_id ] = array();
		}
		$wp_mock_meta[ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		global $wp_mock_meta;
		if ( '' === $key ) {
			return $wp_mock_meta[ $post_id ] ?? array();
		}
		if ( isset( $wp_mock_meta[ $post_id ][ $key ] ) ) {
			$val = $wp_mock_meta[ $post_id ][ $key ];
			return $single ? $val : array( $val );
		}
		return $single ? '' : array();
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
		global $wp_mock_meta;
		unset( $wp_mock_meta[ $post_id ][ $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return $value;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 1;
		public $post_title = 'Sample Post';
		public $post_content = 'Sample Content';
		public $post_excerpt = 'Sample Excerpt';
		public $post_status = 'publish';
		public $post_type = 'post';
		public $post_name = 'sample-post';
		public $post_date_gmt = '2026-09-16 00:00:00';
		public $post_modified_gmt = '2026-09-16 00:00:00';
		public $menu_order = 0;
		public $comment_status = 'open';
		public $ping_status = 'open';
		public $post_parent = 0;

		public function __construct( $data = array() ) {
			foreach ( $data as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null, $output = 'OBJECT', $filter = 'raw' ) {
		global $wp_mock_posts, $wp_mock_posts_strict;
		if ( $post instanceof WP_Post ) {
			return $post;
		}
		$id = is_numeric( $post ) ? (int) $post : 1;
		if ( isset( $wp_mock_posts[ $id ] ) ) {
			return $wp_mock_posts[ $id ];
		}
		// Strict mode (real WordPress semantics): a missing post is null.
		if ( ! empty( $wp_mock_posts_strict ) ) {
			return null;
		}
		$p = new WP_Post( array( 'ID' => $id, 'post_title' => "Mock Post {$id}", 'post_content' => "Mock content for post {$id}", 'post_name' => "post-{$id}" ) );
		$wp_mock_posts[ $id ] = $p;
		return $p;
	}
}

if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $post_id = 0 ) {
		$post = get_post( $post_id );
		if ( $post ) {
			$post->post_status = 'trash';
		}
		return $post;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ) {
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post ) {
		return false;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $post_id = 0, $force_delete = false ) {
		global $wp_mock_posts;
		unset( $wp_mock_posts[ $post_id ] );
		return true;
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $object_type, $output = 'names' ) {
		if ( 'product' === $object_type ) {
			return array( 'product_cat', 'product_tag' );
		}
		return array( 'category', 'post_tag' );
	}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $product_ids, $taxonomies, $args = array() ) {
		return array(
			(object) array(
				'term_id'     => 10,
				'name'        => 'Electronics',
				'slug'        => 'electronics',
				'parent'      => 0,
				'description' => 'Electronic gadgets',
			),
			(object) array(
				'term_id'     => 11,
				'name'        => 'Headphones',
				'slug'        => 'headphones',
				'parent'      => 10,
				'description' => 'Over-ear headphones',
			),
		);
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term, $taxonomy = '', $output = 'OBJECT', $filter = 'raw' ) {
		if ( 10 === (int) $term ) {
			return (object) array( 'term_id' => 10, 'name' => 'Electronics', 'slug' => 'electronics', 'parent' => 0 );
		}
		return (object) array( 'term_id' => (int) $term, 'name' => 'Parent Term', 'slug' => 'parent-term', 'parent' => 0 );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) {
		return 8812;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $post_id = 0 ) {
		if ( isset( $GLOBALS['wp_mock_attachment_files'][ $post_id ] ) ) {
			return wp_upload_dir()['baseurl'] . '/' . basename( $GLOBALS['wp_mock_attachment_files'][ $post_id ] );
		}
		return 'https://site-a.example.com/wp-content/uploads/2026/09/sample-image.jpg';
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id, $unfiltered = false ) {
		if ( isset( $GLOBALS['wp_mock_attachment_files'][ $attachment_id ] ) ) {
			return $GLOBALS['wp_mock_attachment_files'][ $attachment_id ];
		}
		return '/tmp/sample-image.jpg';
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $ID = '' ) {
		return 'image/jpeg';
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		return 'Featured Attachment Image';
	}
}

global $wp_mock_crons;
$wp_mock_crons = array();

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		global $wp_mock_crons;
		return $wp_mock_crons[ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
		global $wp_mock_crons;
		$wp_mock_crons[ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
		global $wp_mock_crons;
		unset( $wp_mock_crons[ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = null ) {
		return array();
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy = 'post_tag', $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		return array();
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		global $wp_mock_posts;
		static $id = 1000;
		$id++;
		$postarr['ID'] = $id;
		$wp_mock_posts[ $id ] = (object) array_merge(
			array(
				'ID'                => $id,
				'post_title'        => '',
				'post_content'      => '',
				'post_excerpt'      => '',
				'post_status'       => 'publish',
				'post_name'         => '',
				'post_type'         => 'post',
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			$postarr
		);
		return $id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr = array(), $wp_error = false ) {
		global $wp_mock_posts;
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id > 0 && isset( $wp_mock_posts[ $id ] ) ) {
			foreach ( $postarr as $key => $value ) {
				if ( 'ID' === $key ) {
					continue;
				}
				$wp_mock_posts[ $id ]->{$key} = $value;
			}
		}
		return $id > 0 ? $id : 1;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0, $leavename = false ) {
		return 'https://site-a.example.com/?p=' . ( is_object( $post ) ? $post->ID : $post );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries ) {
		return array();
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		if ( ! file_exists( $target ) ) {
			return @mkdir( $target, 0755, true );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		$dir = $GLOBALS['wp_mock_upload_dir'] ?? sys_get_temp_dir() . '/wpmmcc_test_uploads';
		if ( ! file_exists( $dir ) ) {
			@mkdir( $dir, 0777, true );
		}
		return array(
			'basedir' => $dir,
			'baseurl' => 'https://site-a.example.com/wp-content/uploads',
		);
	}
}

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) {
		return is_writable( $path );
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'post', 'page', 'product', 'courses', 'topic' ), true );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		protected $data;

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

		public function get_error_data( $code = '' ) {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	abstract class WP_REST_Controller {}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE = 'GET';
		const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		protected $method = 'GET';
		protected $route = '';
		protected $headers = array();
		protected $params = array();
		protected $body = '';

		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = strtoupper( $method );
			$this->route  = $route;
		}

		public function set_header( $key, $val ) {
			$this->headers[ strtolower( $key ) ] = $val;
		}

		public function get_header( $key ) {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function set_param( $key, $val ) {
			$this->params[ $key ] = $val;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_params() {
			return $this->params;
		}

		public function get_json_params() {
			return json_decode( $this->body, true ) ?: array();
		}

		public function set_body( $body ) {
			$this->body = $body;
		}

		public function get_body() {
			return $this->body;
		}

		public function get_method() {
			return $this->method;
		}

		public function set_method( $method ) {
			$this->method = strtoupper( $method );
		}

		public function get_route() {
			return $this->route;
		}

		public function set_route( $route ) {
			$this->route = $route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		protected $data;
		protected $status;
		protected $headers = array();

		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'https://site-a.example.com/wp-content/plugins/wpmmcc/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		return false;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

// The two accessors of a wp_remote_*() response, with WordPress's own contract:
// a WP_Error or a malformed response reads as an empty string.
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) || ! isset( $response['response'] ) || ! is_array( $response['response'] ) ) {
			return '';
		}
		return $response['response']['code'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) || ! isset( $response['body'] ) ) {
			return '';
		}
		return $response['body'];
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Identity unless a test pins a hook: $wp_mock_filter_overrides[ $hook ] = $value.
	 * Lets a test prove that the code really reads a filter and what it does
	 * with an out-of-range answer, without a full callback registry.
	 */
	function apply_filters( $hook, $value, ...$args ) {
		global $wp_mock_filter_overrides;
		if ( is_array( $wp_mock_filter_overrides ) && array_key_exists( $hook, $wp_mock_filter_overrides ) ) {
			return $wp_mock_filter_overrides[ $hook ];
		}
		return $value;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Capture REST route registrations so route-level (permission_callback +
	 * callback dispatch) tests can resolve the registered definitions.
	 */
	function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
		$GLOBALS['wp_mock_rest_routes'][ $namespace . $route ] = $args;
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}

if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data, $strict = true ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
			case 'a':
			case 'O':
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				return (bool) preg_match( "/^{$token}:[0-9.E+-]+;/", $data );
		}
		return false;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $original ) {
		if ( is_serialized( $original ) ) {
			return @unserialize( $original );
		}
		return $original;
	}
}

// Load WPMMCC source files
$wpmmcc_source_candidates = array(
	dirname( __DIR__, 3 ) . '/wpmmcc/source/wpmmcc.php',
	dirname( __DIR__, 3 ) . '/wpmmcc.php',
);
$wpmmcc_source_loaded = false;
foreach ( $wpmmcc_source_candidates as $wpmmcc_source ) {
	if ( is_file( $wpmmcc_source ) ) {
		require_once $wpmmcc_source;
		$wpmmcc_source_loaded = true;
		break;
	}
}
if ( ! $wpmmcc_source_loaded ) {
	fwrite( STDERR, "WPMMCC plugin source was not found for tests.\n" );
	exit( 1 );
}

if ( ! function_exists( 'wpmmcc_test_plugin_path' ) ) {
	/**
	 * Resolve a plugin file in the monorepo layout or the public repo root.
	 *
	 * @param string $relative Path under wpmmcc/source or the public plugin root.
	 */
	function wpmmcc_test_plugin_path( $relative ) {
		$relative = ltrim( (string) $relative, '/' );
		$roots    = array(
			dirname( __DIR__, 3 ) . '/wpmmcc/source',
			dirname( __DIR__, 3 ),
		);
		foreach ( $roots as $root ) {
			$path = $root . '/' . $relative;
			if ( is_file( $path ) ) {
				return $path;
			}
		}
		fwrite( STDERR, "WPMMCC plugin file was not found: {$relative}\n" );
		exit( 1 );
	}
}
