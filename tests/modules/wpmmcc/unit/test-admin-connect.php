<?php
/**
 * WPMMCC admin "connect a peer site" form (WMC-02, and the admin side of WMC-09).
 *
 * The form used to offer "Sync & Translate" although nothing between two
 * WordPress sites translates: the mode was stored on the peer row and never
 * read, so an admin who picked it believed content would be translated. It is
 * now not offered, and a posted value (a stale form, a hand-made request) is
 * refused by the server. Translation belongs to the pair the WPMMCC ATS client
 * makes, which sends that mode in its own handshake.
 *
 * The handler is driven for real: the request goes in $_POST, the redirect a
 * WordPress admin_post handler ends with is an exception here, and the
 * handshake goes through a scripted transport, so the assertions are about what
 * was stored and what was sent, not about source text.
 *
 * Scenarios (run one with:  php test-admin-connect.php --only=<name>):
 *   sync_mode_offer                 the form offers Sync Only alone; the
 *                                   validator maps every value to a stored mode
 *                                   or to a refusal that names the client.
 *   forged_translate_is_refused     a posted sync_and_translate is answered with
 *                                   the refusal, sends nothing, stores nothing.
 *   direct_pairing_is_sync_only     a direct pairing is stored as sync_only,
 *                                   whatever else was posted, and keeps the
 *                                   rest of the form (direction, strategy).
 *   handshake_goes_through_peer_http  the handshake request uses the safe
 *                                   transport, a private-network address is
 *                                   refused with the reason and nothing is sent.
 *
 * oracle: L1
 *
 * @package WPMMCC\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__ ) . '/support/peer-dns-stub.php';

	/** A redirect, the way an admin_post handler ends (it would exit next). */
	class AC_Redirect extends \RuntimeException {
		public $location;
		public function __construct( string $location ) {
			parent::__construct( $location );
			$this->location = $location;
		}
	}

	function current_user_can( $capability ) {
		return true;
	}
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		return 1;
	}
	function admin_url( $path = '' ) {
		return 'https://this-site.example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
	function home_url( $path = '' ) {
		return 'https://this-site.example.test' . $path;
	}
	function wp_safe_redirect( $location, $status = 302 ) {
		throw new AC_Redirect( (string) $location );
	}
	function wp_die( $message = '' ) {
		throw new \RuntimeException( 'wp_die: ' . (string) $message );
	}

	$GLOBALS['ac_wire'] = array(
		'calls'  => array(),
		'reply'  => null,
		'unsafe' => array(),
	);

	/** Scripted transport: records the call, answers ac_wire['reply'] or a failure. */
	function wp_safe_remote_post( $url, $args = array() ) {
		$GLOBALS['ac_wire']['calls'][] = array( 'url' => $url, 'args' => $args );
		$reply                         = $GLOBALS['ac_wire']['reply'];
		if ( null === $reply ) {
			return new WP_Error( 'http_request_failed', 'no scripted reply' );
		}
		return array(
			'response' => array( 'code' => (int) $reply['status'] ),
			'body'     => (string) $reply['body'],
		);
	}
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['ac_wire']['unsafe'][] = $url;
		return new WP_Error( 'unsafe_transport_used', 'a peer request must not use wp_remote_post()' );
	}

	require_once dirname( __DIR__ ) . '/bootstrap.php';
	require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

	use WPMMCC\Admin\Admin;

	echo "=== Running Admin Connect Test (WMC-02 / WMC-09) ===\n";

	$pass_count = 0;

	function assert_test( $condition, $message ) {
		global $pass_count;
		if ( ! $condition ) {
			echo "FAIL: {$message}\n";
			exit( 1 );
		}
		$pass_count++;
		echo "  [PASS] {$message}\n";
	}

	function ac_reset(): void {
		global $wpdb, $wp_mock_options, $wp_mock_cache;
		$wpdb = new Mini_SQL_wpdb();
		$wpdb->create_table(
			'wp_wpmmcc_peers',
			array(
				'peer_uuid'         => '',
				'peer_name'         => '',
				'peer_type'         => '',
				'endpoint_url'      => '',
				'shared_secret'     => '',
				'key_salt'          => '',
				'direction'         => 'bidirectional',
				'sync_mode'         => '',
				'source_lang'       => '',
				'target_lang'       => '',
				'conflict_strategy' => 'lww',
				'status'            => 'active',
				'install_signature' => '',
				'created_at'        => '',
				'updated_at'        => '',
			)
		);
		$wp_mock_options                = array( 'wpmmcc_origin_uuid' => 'urn:uuid:site-self' );
		$wp_mock_cache                  = array();
		$GLOBALS['ac_wire']             = array( 'calls' => array(), 'reply' => null, 'unsafe' => array() );
		$GLOBALS['wpmmcc_test_dns']     = array(
			'remote.example.test'   => array( '93.184.216.36' ),
			'intranet.example.test' => array( '10.0.0.7' ),
		);
		$GLOBALS['ac_redirects']        = array();
		$_POST                          = array();
	}

	/** A successful handshake answer from the remote site. */
	function ac_handshake_ok(): array {
		return array(
			'status' => 200,
			'body'   => json_encode(
				array(
					'success' => true,
					'data'    => array(
						'peer_uuid'         => 'urn:uuid:site-remote',
						'pairing_secret'    => str_repeat( 'ab', 16 ),
						'peer_name'         => 'Remote Site',
						'install_signature' => str_repeat( 'cd', 32 ),
					),
				)
			),
		);
	}

	/**
	 * Post the connect form and return where the handler sent the admin.
	 *
	 * @param array $post Posted fields (merged over a valid form).
	 * @return array{location:string,query:array<string,string>}
	 */
	function ac_submit( array $post = array() ): array {
		$_POST = array_merge(
			array(
				'remote_url'        => 'https://remote.example.test',
				'pairing_code'      => 'code-1234',
				'peer_name'         => 'Remote',
				'direction'         => 'bidirectional',
				'sync_mode'         => 'sync_only',
				'conflict_strategy' => 'lww',
				'source_lang'       => 'en_US',
				'target_lang'       => 'zh_CN',
			),
			$post
		);
		try {
			Admin::handle_connect_peer_site();
		} catch ( AC_Redirect $redirect ) {
			$query = array();
			parse_str( (string) parse_url( $redirect->location, PHP_URL_QUERY ), $query );
			return array( 'location' => $redirect->location, 'query' => $query );
		}
		assert_test( false, 'the handler must end in a redirect' );
		return array();
	}

	function ac_peers(): array {
		return $GLOBALS['wpdb']->rows( 'wp_wpmmcc_peers' );
	}

	// -----------------------------------------------------------------------
	// Scenarios
	// -----------------------------------------------------------------------

	function ac_scenario_sync_mode_offer(): void {
		echo "\n--- sync_mode_offer: what the form offers, what the server accepts ---\n";
		ac_reset();

		$offered = Admin::selectable_sync_modes();
		assert_test( array( 'sync_only' ) === array_keys( $offered ), 'the form offers Sync Only and nothing else' );
		assert_test( '' !== trim( (string) $offered['sync_only'] ), 'with a label' );

		assert_test( 'sync_only' === Admin::validate_sync_mode( 'sync_only' ), 'sync_only is stored as it is' );

		$refused = Admin::validate_sync_mode( 'sync_and_translate' );
		assert_test( is_wp_error( $refused ) && 'wpmmcc_sync_mode_needs_client' === $refused->get_error_code(), 'sync_and_translate is refused with wpmmcc_sync_mode_needs_client' );
		assert_test( false !== strpos( $refused->get_error_message(), 'ATS client' ), 'and the message says where translation lives' );

		foreach ( array( '', 'translate', 'SYNC_ONLY', 'sync_only ', "sync_only'; --", 'sync_and_translate_v2' ) as $odd ) {
			assert_test( 'sync_only' === Admin::validate_sync_mode( $odd ), 'an unknown value (' . var_export( $odd, true ) . ') is stored as sync_only, never as something the plugin does not do' );
		}
	}

	function ac_scenario_forged_translate_is_refused(): void {
		echo "\n--- forged_translate_is_refused: a posted Sync & Translate goes nowhere ---\n";
		ac_reset();
		$GLOBALS['ac_wire']['reply'] = ac_handshake_ok();

		$out = ac_submit( array( 'sync_mode' => 'sync_and_translate' ) );
		assert_test( 0 === strpos( $out['location'], 'https://this-site.example.test/wp-admin/admin.php?page=wpmmcc-sync&connect_error=' ), 'the admin is sent back to the settings page with a connect_error' );
		assert_test( false !== strpos( $out['query']['connect_error'] ?? '', 'ATS client' ), 'that explains the mode needs the client' );
		assert_test( array() === $GLOBALS['ac_wire']['calls'] && array() === $GLOBALS['ac_wire']['unsafe'], 'no handshake was sent: the pairing code is not spent on a pairing that cannot be made' );
		assert_test( array() === ac_peers(), 'no peer row was stored' );
		assert_test( empty( $out['query']['peer_connected'] ), 'and the page does not claim a connection' );
	}

	function ac_scenario_direct_pairing_is_sync_only(): void {
		echo "\n--- direct_pairing_is_sync_only: what a direct pairing stores ---\n";

		ac_reset();
		$GLOBALS['ac_wire']['reply'] = ac_handshake_ok();
		$out                         = ac_submit( array( 'direction' => 'pull_only', 'conflict_strategy' => 'manual_review' ) );
		assert_test( '1' === ( $out['query']['peer_connected'] ?? '' ), 'a valid pairing is connected' );
		$peers = ac_peers();
		assert_test( 1 === count( $peers ), 'one peer row' );
		assert_test( 'urn:uuid:site-remote' === $peers[0]['peer_uuid'] && 'sync_only' === $peers[0]['sync_mode'], 'stored as sync_only' );
		assert_test( 'pull_only' === $peers[0]['direction'] && 'manual_review' === $peers[0]['conflict_strategy'], 'with the direction and the conflict strategy that were chosen' );
		assert_test( 'https://remote.example.test/wp-json/wpmmcc/v1' === $peers[0]['endpoint_url'] && 'active' === $peers[0]['status'], 'and its endpoint' );

		$sent = json_decode( (string) $GLOBALS['ac_wire']['calls'][0]['args']['body'], true );
		assert_test( 'sync_only' === ( $sent['sync_mode'] ?? '' ), 'the handshake told the remote site sync_only' );
		assert_test( 'https://remote.example.test/wp-json/wpmmcc/v1/sync/handshake' === $GLOBALS['ac_wire']['calls'][0]['url'], 'to the handshake endpoint' );

		// A mode the plugin does not know is stored as the one it does.
		ac_reset();
		$GLOBALS['ac_wire']['reply'] = ac_handshake_ok();
		ac_submit( array( 'sync_mode' => 'something_else' ) );
		$peers = ac_peers();
		assert_test( 1 === count( $peers ) && 'sync_only' === $peers[0]['sync_mode'], 'an unknown posted mode is stored as sync_only' );

		// No mode posted at all (an old form).
		ac_reset();
		$GLOBALS['ac_wire']['reply'] = ac_handshake_ok();
		$_POST                       = array(
			'remote_url'   => 'https://remote.example.test',
			'pairing_code' => 'code-1234',
		);
		$location = '';
		try {
			Admin::handle_connect_peer_site();
		} catch ( AC_Redirect $redirect ) {
			$location = $redirect->location;
		}
		assert_test( false !== strpos( $location, 'peer_connected=1' ), 'a form without a sync_mode field still pairs' );
		$peers = ac_peers();
		assert_test( 1 === count( $peers ) && 'sync_only' === $peers[0]['sync_mode'], 'as sync_only' );
	}

	function ac_scenario_handshake_goes_through_peer_http(): void {
		echo "\n--- handshake_goes_through_peer_http: the admin handshake is an outbound request too ---\n";

		// A public host: safe transport, never the plain one.
		ac_reset();
		$GLOBALS['ac_wire']['reply'] = ac_handshake_ok();
		ac_submit();
		assert_test( 1 === count( $GLOBALS['ac_wire']['calls'] ) && array() === $GLOBALS['ac_wire']['unsafe'], 'the handshake goes out through wp_safe_remote_post, never wp_remote_post' );
		assert_test( 0 === $GLOBALS['ac_wire']['calls'][0]['args']['redirection'], 'without following redirects' );

		// An address on a private network, unless the operator allowed it.
		foreach ( array( 'http://10.0.0.5:8081', 'http://169.254.169.254', 'http://127.0.0.1:8082', 'https://intranet.example.test' ) as $url ) {
			ac_reset();
			$GLOBALS['ac_wire']['reply'] = ac_handshake_ok();
			$out                         = ac_submit( array( 'remote_url' => $url ) );
			assert_test( false !== strpos( $out['query']['connect_error'] ?? '', 'was refused' ), "{$url} is refused, and the page says so" );
			assert_test( false !== strpos( $out['query']['connect_error'] ?? '', 'wpmmcc_allow_private_peers' ), 'and how to allow a private peer' );
			assert_test( array() === $GLOBALS['ac_wire']['calls'] && array() === ac_peers(), 'nothing was sent and nothing stored' );
		}

		// Allowed on purpose: a lab or a LAN.
		ac_reset();
		$GLOBALS['wp_mock_options']['wpmmcc_allow_private_peers'] = 1;
		$GLOBALS['ac_wire']['reply']                              = ac_handshake_ok();
		$out                                                      = ac_submit( array( 'remote_url' => 'http://10.0.0.5:8081' ) );
		assert_test( '1' === ( $out['query']['peer_connected'] ?? '' ) && 1 === count( ac_peers() ), 'with private peers allowed the same address pairs' );
		assert_test( 'http://10.0.0.5:8081/wp-json/wpmmcc/v1' === ac_peers()[0]['endpoint_url'], 'and is stored as the peer endpoint' );
		ac_reset();
		$GLOBALS['wp_mock_options']['wpmmcc_allow_private_peers'] = 1;
		$GLOBALS['ac_wire']['reply']                              = ac_handshake_ok();
		ac_submit( array( 'remote_url' => 'http://169.254.169.254' ) );
		assert_test( array() === $GLOBALS['ac_wire']['calls'], 'but the option never opens the link-local range' );
	}

	// -----------------------------------------------------------------------
	// Runner
	// -----------------------------------------------------------------------

	$scenarios = array(
		'sync_mode_offer',
		'forged_translate_is_refused',
		'direct_pairing_is_sync_only',
		'handshake_goes_through_peer_http',
	);

	$only = null;
	foreach ( array_slice( $argv ?? array(), 1 ) as $arg ) {
		if ( 0 === strpos( $arg, '--only=' ) ) {
			$only = substr( $arg, 7 );
		}
	}
	foreach ( $scenarios as $name ) {
		if ( null !== $only && $only !== $name ) {
			continue;
		}
		call_user_func( 'ac_scenario_' . $name );
	}

	echo "\n==================================================\n";
	echo "PASS: All {$pass_count} Admin Connect tests passed successfully!\n";
	echo "==================================================\n";
	exit( 0 );
}
