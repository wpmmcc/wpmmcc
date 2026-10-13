<?php
/**
 * TEST SHIM ONLY — do not confuse with WordPress core.
 *
 * In the private standalone test harness ABSPATH points at this fixture,
 * so the REST media controllers'
 * `require_once ABSPATH . 'wp-admin/includes/file.php'` resolves HERE
 * instead of to a real WordPress core file. Everything below is guarded
 * with function_exists/class_exists so a production environment (where
 * the genuine core file is loaded) is never affected.
 *
 * @package WPMMCC\Tests\Shim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
}

if ( ! class_exists( 'Mock_WP_Filesystem' ) ) {
	/**
	 * Minimal direct-filesystem mock for chunked media tests.
	 */
	class Mock_WP_Filesystem {
		public function put_contents( $file, $contents, $mode = 0644 ) {
			return false !== file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		public function get_contents( $file ) {
			return (string) file_get_contents( $file );
		}

		public function exists( $file ) {
			return file_exists( $file );
		}

		public function size( $file ) {
			return (int) filesize( $file );
		}

		public function mkdir( $path, $chmod = 0755, $chown = false, $chgrp = false ) {
			return is_dir( $path ) || mkdir( $path, 0700, true );
		}

		public function chmod( $file, $mode = false, $recursive = false ) {
			return true;
		}

		public function move( $source, $destination, $overwrite = false ) {
			if ( ! $overwrite && file_exists( $destination ) ) {
				return false;
			}
			return rename( $source, $destination );
		}

		public function delete( $file, $recursive = false, $type = false ) {
			return @unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test shim implements the direct fs API on purpose.
		}

		public function rmdir( $dir ) {
			return @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test shim implements the direct fs API on purpose.
		}
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	/**
	 * Initialise the mock filesystem used by the media controllers.
	 *
	 * @return object|null
	 */
	function WP_Filesystem() {
		$GLOBALS['wp_filesystem'] = new Mock_WP_Filesystem();
		return $GLOBALS['wp_filesystem'];
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	/**
	 * Minimal filetype guesser (ext map mirrors core defaults).
	 *
	 * @param string $filename Filename.
	 * @param array  $mimes    Optional mime map.
	 * @return array
	 */
	function wp_check_filetype( $filename, $mimes = null ) {
		$map = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'gif'          => 'image/gif',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'bin'          => 'application/octet-stream',
			'zip'          => 'application/zip',
		);
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		foreach ( $map as $pattern => $mime ) {
			if ( in_array( $ext, explode( '|', $pattern ), true ) ) {
				return array( 'ext' => $ext, 'type' => $mime );
			}
		}
		return array( 'ext' => $ext, 'type' => '' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Minimal filename sanitizer.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		return trim( preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $filename ), '-.' );
	}
}

if ( ! function_exists( 'wp_upload_bits' ) ) {
	/**
	 * Store raw bytes under the (mock) uploads dir.
	 *
	 * @param string $name     Suggested filename.
	 * @param mixed  $deprecated Unused.
	 * @param string $bits     Raw file contents.
	 * @return array
	 */
	function wp_upload_bits( $name, $deprecated, $bits ) {
		$upload = wp_upload_dir();
		$dir    = $upload['basedir'] . '/wpmmcc_media';
		if ( ! file_exists( $dir ) ) {
			@mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$name   = sanitize_file_name( $name );
		$path   = $dir . '/' . $name;
		$stored = file_put_contents( $path, $bits ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'file' => $path,
			'url'  => $upload['baseurl'] . '/wpmmcc_media/' . $name,
			'type' => wp_check_filetype( $name )['type'],
			'error' => ( false === $stored ) ? 'Could not write file.' : false,
		);
	}
}

if ( ! function_exists( 'wp_insert_attachment' ) ) {
	/**
	 * Insert an attachment post via the mocked wp_insert_post.
	 *
	 * @param array   $postarr    Post data.
	 * @param string  $file       File path (unused by the mock).
	 * @param int     $parent     Parent post ID.
	 * @param bool    $wp_error   Unused.
	 * @param bool    $unique_filename Unused.
	 * @return int Post ID.
	 */
	function wp_insert_attachment( $postarr, $file = false, $parent = 0, $wp_error = false, $unique_filename = false ) {
		$postarr['post_type']   = 'attachment';
		$postarr['post_parent'] = $parent;
		$postarr['post_status']  = $postarr['post_status'] ?? 'inherit';
		$id = wp_insert_post( $postarr );
		if ( ! is_wp_error( $id ) && $file ) {
			$GLOBALS['wp_mock_attachment_files'][ $id ] = $file;
		}
		return $id;
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	/**
	 * Minimal attachment metadata stub.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          File path.
	 * @return array
	 */
	function wp_generate_attachment_metadata( $attachment_id, $file ) {
		if ( ! empty( $GLOBALS['wp_mock_empty_attachment_metadata'] ) ) {
			return array();
		}
		return array( 'file' => (string) $file, 'width' => 0, 'height' => 0 );
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	/**
	 * Metadata persistence through the same mock meta store used by readback.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $data          Metadata.
	 * @return bool
	 */
	function wp_update_attachment_metadata( $attachment_id, $data ) {
		if ( empty( $data ) ) {
			return delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
		}
		return update_post_meta( $attachment_id, '_wp_attachment_metadata', $data );
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $attachment_id ) {
		if ( ! empty( $GLOBALS['wp_mock_corrupt_attachment_metadata'] ) ) {
			return array( 'unexpected' => 'retained fault' );
		}
		if ( '' === get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) ) {
			return false;
		}
		return get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
	}
}

if ( ! function_exists( 'wp_handle_sideload' ) ) {
	function wp_handle_sideload( &$file, $overrides = array() ) {
		$upload = wp_upload_dir();
		$path = $upload['basedir'] . '/' . bin2hex( random_bytes( 8 ) ) . '-' . sanitize_file_name( $file['name'] );
		if ( ! rename( $file['tmp_name'], $path ) ) {
			return array( 'error' => 'Fixture sideload failed.' );
		}
		return array( 'file' => $path, 'url' => $upload['baseurl'] . '/' . basename( $path ), 'type' => 'text/plain' );
	}
}
