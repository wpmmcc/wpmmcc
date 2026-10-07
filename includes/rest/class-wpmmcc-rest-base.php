<?php
/**
 * WPMMCC REST Base Controller.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Base
 *
 * Abstract base controller for all WPMMCC endpoints under namespace `wpmmcc/v1`.
 */
abstract class Rest_Base extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wpmmcc/v1';

	/**
	 * Compute the site installation signature for ADR-5 loop prevention.
	 * sha256( site_url || '|' || network_id )
	 *
	 * @return string 64-character lowercase hex sha256.
	 */
	public static function compute_install_signature(): string {
		$site_url   = function_exists( 'get_site_url' ) ? (string) get_site_url() : ( function_exists( 'site_url' ) ? (string) site_url() : '' );
		$network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
		return hash( 'sha256', $site_url . '|' . $network_id );
	}

	/**
	 * Send JSON response formatted consistently.
	 *
	 * @param mixed $data Data payload.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response
	 */
	protected function format_success( $data = array(), int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Send error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP status code.
	 * @return WP_Error
	 */
	protected function format_error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array( 'status' => $status )
		);
	}
}
