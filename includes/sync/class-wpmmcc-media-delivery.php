<?php
/**
 * Exact, peer-scoped chunk authority. Unknown materialization is not repeated.
 *
 * @package WPMMCC\Sync
 */
namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Delivery {
	const MAX_FILE_BYTES = 536870912;
	const MAX_ROOT_BYTES = 34359738368;

	private static function scope( string $sender, array $params ): array {
		if ( '' === $sender ) {
			throw new \RuntimeException( 'Media authority is missing.' );
		}
		return array(
			'sender' => $sender, 'file_uuid' => $params['file_uuid'], 'total' => $params['total'],
			'filename' => sanitize_file_name( $params['filename'] ),
			'mime_type' => $params['mime_type'], 'expected_sha256' => $params['expected_sha256'],
			'expected_size' => $params['expected_size'],
		);
	}

	private static function state( string $path, string $key ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the authority row can change after a crash; a cached document would finish a damaged session
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT state_json FROM {$wpdb->prefix}wpmmcc_media_deliveries WHERE delivery_key = %s", $key ), ARRAY_A );
		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Original media SQL authority is unavailable.' );
		}
		return Private_Document::decode( $row['state_json'], 'media:' . $key );
	}

	private static function save_state( string $path, string $key, array $state ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wpmmcc_media_deliveries';
		$encoded = Private_Document::encode( $state, 'media:' . $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- media state is compared with the value just read
		$previous = $wpdb->get_var( $wpdb->prepare( 'SELECT state_json FROM %i WHERE delivery_key = %s', $table, $key ) );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Original media authority read failed.' );
		}
		if ( null === $previous ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$changed = $wpdb->query( $wpdb->prepare(
				'INSERT IGNORE INTO %i (delivery_key, state_json, reserved_bytes, created_at) VALUES (%s, %s, %d, %s)',
				$table, $key, $encoded, $state['scope']['expected_size'] * 4 + 8388608, current_time( 'mysql' )
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$changed = $wpdb->query( $wpdb->prepare(
				'UPDATE %i SET state_json = %s WHERE delivery_key = %s AND state_json = %s', $table, $encoded, $key, $previous
			) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the media state just written
		$live = $wpdb->get_var( $wpdb->prepare( 'SELECT state_json FROM %i WHERE delivery_key = %s', $table, $key ) );
		if ( 1 !== $changed || $live !== $encoded || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Original media state was not committed and read back.' );
		}
		wp_cache_delete( 'media_state_' . $key, 'wpmmcc' );
		wp_cache_delete( 'media_reserved_bytes', 'wpmmcc' );
	}

	private static function part( string $path, string $key, int $index ): string {
		$value = Private_Document::decode( self::read( $path, 8388608 ), 'media-part:' . $key . ':' . $index );
		$bytes = base64_decode( (string) ( $value['bytes'] ?? '' ), true );
		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > 4194304 ) {
			throw new \RuntimeException( 'Retained media chunk is invalid.' );
		}
		return $bytes;
	}

	private static function admit( string $root, int $additional ): void {
		$used = 0;
		global $wpdb;
		$booked = wp_cache_get( 'media_reserved_bytes', 'wpmmcc' );
		if ( false === $booked ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$booked = $wpdb->get_var( "SELECT COALESCE(SUM(reserved_bytes), 0) FROM {$wpdb->prefix}wpmmcc_media_deliveries" );
			if ( is_numeric( $booked ) && '' === (string) $wpdb->last_error ) {
				wp_cache_set( 'media_reserved_bytes', $booked, 'wpmmcc' );
			}
		}
		if ( ! is_numeric( $booked ) || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Media reservations are unavailable.' );
		}
		$booked = (int) $booked;
		$count = 0;
		if ( is_dir( $root ) ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( ++$count > 100000 || $file->isLink() || ! $file->isFile() ) {
					throw new \RuntimeException( 'Media inventory is not safe.' );
				}
				$used += $file->getSize();
			}
		}
		if ( max( $used, $booked ) + $additional > self::MAX_ROOT_BYTES ) {
			throw new \RuntimeException( 'Media retained capacity is exhausted; no deletion.' );
		}
	}

	public static function status( string $sender, array $params ): array {
		$scope = self::scope( $sender, $params );
		$proof = array_diff_key( $scope, array( 'sender' => true ) );
		$key = hash( 'sha256', $sender . "\n" . $scope['file_uuid'] );
		$dir = wp_upload_dir()['basedir'] . '/wpmmcc_chunks/' . $key;
		$lease = SQL_Lease::acquire( 'media:' . $key );
		if ( is_wp_error( $lease ) ) {
			throw new \RuntimeException( 'Media authority is busy.' );
		}
		$path = $dir . '/intent.json';
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- media delivery reservation must see a row created by a concurrent request
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT delivery_key FROM {$wpdb->prefix}wpmmcc_media_deliveries WHERE delivery_key = %s", $key ) );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Original media authority read failed.' );
		}
		if ( null === $exists ) {
			// Zero-effect proof: accept() commits the intent row under this
			// same SQL lease BEFORE admitting any chunk bytes, so no row means
			// no chunk, no assembly and no attachment for this exact scope.
			$lease->assert_owner();
			return array_merge( $proof, array( 'completed' => false, 'phase' => 'absent',
				'missing_chunks' => range( 0, $scope['total'] - 1 ) ) );
		}
		$state = self::state( $path, $key );
		if ( 'wpmmcc-media-v1' !== ( $state['format'] ?? '' ) || ( $state['scope'] ?? null ) !== $scope ) {
			throw new \RuntimeException( 'Original media scope differs.' );
		}
		$lease->assert_owner();
		if ( 'applying' === $state['phase'] ) {
			// An unrelated attachment with identical bytes is not original
			// materialization authority. Only the persisted original association
			// and verified metadata/CAS phase can finish without repeating hooks.
			$id = (int) ( $state['materialization']['attachment_id'] ?? 0 );
			if ( $id <= 0 || true !== ( $state['materialization']['verified'] ?? false )
				|| Asset_Registry::lookup_cas_attachment( $scope['expected_sha256'] ) !== $id ) {
				throw new \RuntimeException( 'Original materialization is unknown.' );
			}
			$state['receipt'] = self::receipt( $id, $scope, (bool) $state['materialization']['reused'] );
			$state['phase'] = 'applied';
			self::save_state( $path, $key, $state );
		}
		if ( 'applied' === $state['phase'] ) {
			$receipt = self::receipt( (int) $state['receipt']['attachment_id'], $scope, (bool) $state['receipt']['reused'] );
			return array_merge( $proof, array( 'phase' => 'applied' ), $receipt );
		}
		if ( 'receiving' !== $state['phase'] || ! is_array( $state['parts'] ?? null ) ) {
			throw new \RuntimeException( 'Media authority is invalid.' );
		}
		$missing = array();
		for ( $i = 0; $i < $scope['total']; ++$i ) {
			if ( ! isset( $state['parts'][ $i ] ) ) {
				$missing[] = $i;
				continue;
			}
			$bytes = self::part( $dir . '/part_' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ), $key, $i );
			if ( $state['parts'][ $i ]['sha256'] !== hash( 'sha256', $bytes ) || $state['parts'][ $i ]['size'] !== strlen( $bytes ) ) {
				throw new \RuntimeException( 'Retained chunk association differs.' );
			}
		}
		// All original parts can be present before the receiver entered applying.
		// Replaying exactly one retained part resumes original receiving only.
		if ( empty( $missing ) ) {
			$missing[] = $scope['total'] - 1;
		}
		return array_merge( $proof, array( 'completed' => false, 'phase' => 'receiving', 'missing_chunks' => $missing ) );
	}

	private static function receipt( int $id, array $scope, bool $reused ): array {
		$url = (string) wp_get_attachment_url( $id );
		$file = get_attached_file( $id );
		if ( $id <= 0 || '' === $url || ! $file || ! is_file( $file )
			|| filesize( $file ) !== $scope['expected_size'] || hash_file( 'sha256', $file ) !== $scope['expected_sha256'] ) {
			throw new \RuntimeException( 'Attachment evidence differs.' );
		}
		return array( 'completed' => true, 'assembled_sha256' => $scope['expected_sha256'],
			'attachment_id' => $id, 'attachment_url' => $url, 'reused' => $reused );
	}

	private static function filesystem() {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
			return null;
		}
		return $wp_filesystem;
	}

	private static function read( string $path, int $limit ): string {
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > $limit ) {
			throw new \RuntimeException( 'Invalid retained media file.' );
		}
		$filesystem = self::filesystem();
		$value      = $filesystem ? $filesystem->get_contents( $path ) : false;
		if ( false === $value || strlen( $value ) > $limit ) {
			throw new \RuntimeException( 'Media read failed.' );
		}
		return $value;
	}

	private static function save( string $path, string $bytes ): void {
		$filesystem = self::filesystem();
		$temp       = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		if ( ! $filesystem || $filesystem->exists( $temp ) ) {
			throw new \RuntimeException( 'Media snapshot creation failed.' );
		}
		if ( ! $filesystem->put_contents( $temp, $bytes, 0600 ) ) {
			throw new \RuntimeException( 'Media snapshot write failed.' );
		}
		if ( self::read( $temp, strlen( $bytes ) ) !== $bytes || ! $filesystem->move( $temp, $path, true )
			|| self::read( $path, strlen( $bytes ) ) !== $bytes ) {
			$filesystem->delete( $temp );
			throw new \RuntimeException( 'Media snapshot installation failed.' );
		}
	}

	private static function owner( array $held, string $path ): void {
		clearstatcache( true, $path );
		$live = lstat( $path );
		if ( ! is_array( $live ) || is_link( $path ) || ! is_file( $path )
			|| $held['dev'] !== $live['dev'] || $held['ino'] !== $live['ino'] ) {
			throw new \RuntimeException( 'Media lease path changed.' );
		}
	}

	public static function accept( string $sender, array $params, string $bytes ): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'Media root is unavailable.' );
		}
		$key = hash( 'sha256', $sender . "\n" . $params['file_uuid'] );
		$native = SQL_Lease::acquire( 'media:' . $key );
		$global = SQL_Lease::acquire( 'media-retained-capacity' );
		if ( is_wp_error( $native ) || is_wp_error( $global ) ) {
			throw new \RuntimeException( 'Media execution or capacity authority is busy.' );
		}
		$root = $uploads['basedir'] . '/wpmmcc_chunks';
		$dir = $root . '/' . $key;
		$filesystem = self::filesystem();
		if ( is_link( $root ) || is_link( $dir ) || ! $filesystem ) {
			throw new \RuntimeException( 'Private media root is unavailable.' );
		}
		if ( ! is_dir( $dir ) && ( ( ! is_dir( $root ) && ! $filesystem->mkdir( $root, 0700 ) ) || ! $filesystem->mkdir( $dir, 0700 ) ) ) {
			throw new \RuntimeException( 'Private media root is unavailable.' );
		}
		$lock_path = $dir . '/owner.lock';
		if ( is_link( $lock_path ) ) {
			throw new \RuntimeException( 'Invalid media lease.' );
		}
		$new_lock = ! $filesystem->exists( $lock_path );
		if ( $new_lock && ! $filesystem->put_contents( $lock_path, '', 0600 ) ) {
			throw new \RuntimeException( 'Media lease creation failed.' );
		}
		if ( ! is_file( $lock_path ) || ( $new_lock && ! $filesystem->chmod( $lock_path, 0600 ) ) ) {
			throw new \RuntimeException( 'Media delivery is active.' );
		}
		clearstatcache( true, $lock_path );
		$held = lstat( $lock_path );
		if ( ! is_array( $held ) ) {
			throw new \RuntimeException( 'Media lease creation failed.' );
		}
		try {
			self::owner( $held, $lock_path );
			$path = $dir . '/intent.json';
			$scope = self::scope( $sender, $params );
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- media delivery reservation must see a row created by a concurrent request
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT delivery_key FROM {$wpdb->prefix}wpmmcc_media_deliveries WHERE delivery_key = %s", $key ) );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'Original media authority read failed.' );
			}
			if ( null === $existing ) {
				self::admit( $root, $scope['expected_size'] * 4 + 8388608 );
				$state = array( 'format' => 'wpmmcc-media-v1', 'scope' => $scope, 'phase' => 'receiving', 'parts' => array() );
				$native->assert_owner();
				$global->assert_owner();
				self::save_state( $path, $key, $state );
			} else {
				$state = self::state( $path, $key );
			}
			if ( ! is_array( $state ) || 'wpmmcc-media-v1' !== ( $state['format'] ?? '' )
				|| ( $state['scope'] ?? null ) !== $scope || ! is_array( $state['parts'] ?? null ) ) {
				throw new \RuntimeException( 'Media authority changed.' );
			}
			$index = $params['chunk_index'];
			$part = $dir . '/part_' . str_pad( (string) $index, 6, '0', STR_PAD_LEFT );
			$digest = hash( 'sha256', $bytes );
			if ( isset( $state['parts'][ $index ] ) && $state['parts'][ $index ] !== array( 'sha256' => $digest, 'size' => strlen( $bytes ) ) ) {
				throw new \RuntimeException( 'Original chunk differs.' );
			}
			if ( file_exists( $part ) ) {
				if ( self::part( $part, $key, $index ) !== $bytes ) {
					throw new \RuntimeException( 'Retained chunk differs.' );
				}
			} elseif ( 'receiving' === $state['phase'] ) {
				$size = strlen( $bytes );
				foreach ( $state['parts'] as $association ) {
					$size += $association['size'];
				}
				if ( $size > $scope['expected_size'] ) {
					throw new \RuntimeException( 'Receiving media exceeds its original byte admission.' );
				}
				$native->assert_owner();
				self::save( $part, Private_Document::encode( array( 'bytes' => base64_encode( $bytes ) ), 'media-part:' . $key . ':' . $index ) );
			} else {
				throw new \RuntimeException( 'Original chunk is missing.' );
			}
			$state['parts'][ $index ] = array( 'sha256' => $digest, 'size' => strlen( $bytes ) );
			$ack = array(
				'file_uuid' => $scope['file_uuid'], 'total' => $scope['total'], 'chunk_index' => $index,
				'chunk_acked' => true, 'completed' => false,
				'assembled_sha256' => '', 'attachment_id' => 0, 'attachment_url' => '', 'reused' => false,
			);
			if ( 'applied' === $state['phase'] && is_array( $state['receipt'] ?? null ) ) {
				return array_merge( $ack, self::receipt( (int) $state['receipt']['attachment_id'], $scope, (bool) $state['receipt']['reused'] ) );
			}
			if ( 'receiving' !== $state['phase'] ) {
				throw new \RuntimeException( 'Original media effect is unknown.' );
			}
			self::owner( $held, $lock_path );
			self::save_state( $path, $key, $state );
			if ( count( $state['parts'] ) !== $scope['total'] ) {
				return $ack;
			}
			// Plain assembly is private OS-temporary data, never a web upload
			// path. Original encrypted parts remain the recovery authority.
			$assembled = tempnam( sys_get_temp_dir(), 'wpmmcc-assembly-' );
			if ( ! is_string( $assembled ) || 0 === strpos( realpath( $assembled ), realpath( $uploads['basedir'] ) . DIRECTORY_SEPARATOR ) ) {
				throw new \RuntimeException( 'Private media assembly location is unavailable.' );
			}
			if ( ! $filesystem->chmod( $assembled, 0600 ) ) {
				throw new \RuntimeException( 'Media assembly creation failed.' );
			}
			$size = 0;
			$hash = hash_init( 'sha256' );
			$buffer = '';
			for ( $i = 0; $i < $scope['total']; ++$i ) {
				$chunk = self::part( $dir . '/part_' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ), $key, $i );
				if ( ( $state['parts'][ $i ]['sha256'] ?? '' ) !== hash( 'sha256', $chunk ) ) {
					throw new \RuntimeException( 'Retained media association differs.' );
				}
				$size += strlen( $chunk );
				if ( $size > self::MAX_FILE_BYTES ) {
					throw new \RuntimeException( 'Media assembly exceeds its quota or failed.' );
				}
				$buffer .= $chunk;
				hash_update( $hash, $chunk );
			}
			if ( ! $filesystem->put_contents( $assembled, $buffer, 0600 ) ) {
				throw new \RuntimeException( 'Media assembly flush failed.' );
			}
			$sha256 = hash_final( $hash );
			if ( hash_file( 'sha256', $assembled ) !== $sha256 || $sha256 !== $scope['expected_sha256'] || $size !== $scope['expected_size'] ) {
				throw new \RuntimeException( 'Media assembly verification failed.' );
			}
			$state['phase'] = 'applying';
			$state['assembled_sha256'] = $sha256;
			$state['size_bytes'] = $size;
			$id = Asset_Registry::lookup_cas_attachment( $sha256 );
			$reused = $id > 0;
			$state['materialization'] = array( 'attachment_id' => $id, 'reused' => $reused, 'verified' => $reused );
			self::owner( $held, $lock_path );
			$native->assert_owner();
			self::save_state( $path, $key, $state );
			if ( ! $reused ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$file = array( 'name' => $scope['filename'], 'tmp_name' => $assembled, 'error' => 0, 'size' => $size );
				$upload = wp_handle_sideload( $file, array( 'test_form' => false ) );
				if ( ! empty( $upload['error'] ) || empty( $upload['file'] )
					|| hash_file( 'sha256', $upload['file'] ) !== $sha256 ) {
					throw new \RuntimeException( 'Media library storage is unconfirmed.' );
				}
				$id = wp_insert_attachment( array( 'post_title' => $scope['filename'],
					'post_mime_type' => $upload['type'], 'post_status' => 'inherit' ), $upload['file'], 0, true );
				if ( is_wp_error( $id ) || $id <= 0 ) {
					throw new \RuntimeException( 'Attachment creation is unconfirmed.' );
				}
				$state['materialization']['attachment_id'] = (int) $id;
				$native->assert_owner();
				self::save_state( $path, $key, $state );
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$metadata = wp_generate_attachment_metadata( $id, $upload['file'] );
				if ( ! is_array( $metadata ) ) {
					throw new \RuntimeException( 'Original attachment metadata is invalid.' );
				}
				wp_update_attachment_metadata( $id, $metadata );
				$saved_metadata = wp_get_attachment_metadata( $id );
				// Core deletes empty metadata for ordinary non-image files.
				$empty_saved = array() === $metadata && false === $saved_metadata
					&& '' === get_post_meta( $id, '_wp_attachment_metadata', true );
				if ( $saved_metadata !== $metadata && ! $empty_saved ) {
					throw new \RuntimeException( 'Original attachment metadata was not saved.' );
				}
				if ( ! Asset_Registry::register_cas_asset( (int) $id, $sha256, $size, $sender ) ) {
					throw new \RuntimeException( 'Original media CAS evidence was not saved.' );
				}
				$state['materialization']['verified'] = true;
				$native->assert_owner();
				self::save_state( $path, $key, $state );
			}
			$url = (string) wp_get_attachment_url( $id );
			$file = get_attached_file( $id );
			if ( '' === $url || ! $file || ! is_file( $file ) || hash_file( 'sha256', $file ) !== $sha256 ) {
				throw new \RuntimeException( 'Attachment evidence differs.' );
			}
			$receipt = self::receipt( (int) $id, $scope, $reused );
			$state['phase'] = 'applied';
			$state['receipt'] = $receipt;
			self::owner( $held, $lock_path );
			$native->assert_owner();
			self::save_state( $path, $key, $state );
			return array_merge( $ack, $receipt );
		} finally {
			// Only this invocation's private plaintext assembly is temporary.
			// Original encrypted parts, SQL authority and receipts stay retained.
			if ( isset( $assembled ) && is_string( $assembled ) && ! is_link( $assembled ) && is_file( $assembled ) ) {
				wp_delete_file( $assembled );
			}
		}
	}
}
