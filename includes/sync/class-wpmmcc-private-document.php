<?php
/**
 * Purpose-bound encrypted documents for new delivery evidence.
 *
 * @package WPMMCC\Sync
 */
namespace WPMMCC\Sync;

use WPMMCC\REST\Rest_Middleware;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Private_Document {
	public static function encode( array $value, string $purpose ): string {
		if ( ! defined( 'AUTH_KEY' ) || '' === AUTH_KEY || 'put your unique phrase here' === AUTH_KEY ) {
			throw new \RuntimeException( 'A configured encryption key is required; no plaintext fallback.' );
		}
		$raw = wp_json_encode( $value );
		if ( ! is_string( $raw ) || strlen( $raw ) > 16 * 1024 * 1024 ) {
			throw new \RuntimeException( 'Delivery document cannot be encoded within its bound.' );
		}
		$sealed = Rest_Middleware::encrypt_shared_secret( $raw, substr( hash( 'sha256', $purpose ), 0, 32 ) );
		if ( '' === $sealed ) {
			throw new \RuntimeException( 'Delivery evidence encryption failed.' );
		}
		return 'wpm1:' . $sealed;
	}

	public static function decode( string $encoded, string $purpose ): array {
		if ( 0 !== strpos( $encoded, 'wpm1:' ) || strlen( $encoded ) > 24 * 1024 * 1024 ) {
			throw new \RuntimeException( 'Delivery evidence is not an authenticated private document.' );
		}
		$raw = Rest_Middleware::decrypt_shared_secret( substr( $encoded, 5 ), substr( hash( 'sha256', $purpose ), 0, 32 ) );
		if ( ! is_string( $raw ) || '' === $raw ) {
			throw new \RuntimeException( 'Delivery evidence authentication failed.' );
		}
		$value = json_decode( $raw, true, 64 );
		if ( ! is_array( $value ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException( 'Delivery evidence is damaged; original retained.' );
		}
		return $value;
	}
}
