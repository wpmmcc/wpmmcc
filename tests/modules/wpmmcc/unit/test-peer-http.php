<?php
/**
 * WPMMCC outbound peer HTTP (WMC-09, server-side request forgery).
 *
 * The peer URL is input: an admin types it, and a handshake a remote party
 * answers writes it. Every peer request used a plain wp_remote_post(), so a
 * peer row pointing at 127.0.0.1, the LAN or 169.254.169.254 made the
 * WordPress host fetch it. All of it now goes through Peer_Http::post().
 *
 * The scripted WordPress layer below models the two pieces of core that matter
 * here, wp_http_validate_url() (private hosts, the safe-port list, the two
 * filters that can relax them) and wp_safe_remote_post() (validates the URL and,
 * when asked to follow one, the redirect target), plus a wp_remote_post() that
 * records a violation. Core's list of private hosts comes in two sizes, which the
 * model can play: "short" (the x.y.0 releases: 0/8, 10/8, 127/8, 172.16/12,
 * 192.168/16, the default) and "full" (patched releases: the whole IANA
 * special-purpose list). Like every scripted layer it is only a model of core:
 * tests/e2e/peer-http-vs-wordpress.php checks the lists against the real
 * functions in the lab.
 *
 * Scenarios (run one with:  php test-peer-http.php --only=<name>):
 *   url_policy                 which URLs are refused (private, loopback,
 *                              link-local, carrier NAT, benchmarking, test and
 *                              reserved ranges, IPv6, bad scheme, userinfo,
 *                              unresolvable, one private record among public
 *                              ones) and which pass, to the address.
 *   opt_in                     the option opens private networks but never the
 *                              link-local range; the filter decides per host and
 *                              can open anything, or shut what the option opened.
 *   covers_wordpress_list      nothing the full WordPress list refuses passes as
 *                              public here (every range edge, every /16 block),
 *                              and a host that fake-IP DNS sends to 198.18/15 is
 *                              refused with our reason, not WordPress's "A valid
 *                              URL was not provided.", under either list size.
 *   safe_transport             a peer request is sent with
 *                              wp_safe_remote_post and never wp_remote_post; a
 *                              refused URL sends nothing at all.
 *   wordpress_check_is_scoped  while a request is in flight WordPress's own
 *                              validation is relaxed for the vetted host and
 *                              port only, never for a neighbour, and a DNS
 *                              answer that changed is still refused by it.
 *   redirects_are_not_followed a 3xx answer, even one aimed at 169.254.169.254
 *                              (which WordPress's own redirect check accepts),
 *                              is returned as it is and never followed.
 *   filters_do_not_leak        the two bridging filters are gone after every
 *                              call, including one that throws.
 *   remote_call_is_wired       Manual_Sync::remote_call sends through
 *                              Peer_Http, keeps the signed envelope, and the
 *                              admin "test connection" reports the refusal.
 *
 * oracle: L1
 *
 * @package WPMMCC\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__ ) . '/support/peer-dns-stub.php';

	$GLOBALS['ph_filters'] = array();

	// A real (priority-less) filter registry: the bootstrap's hooks are stubs.
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ph_filters'][ $hook ][] = array( $callback, (int) $accepted_args, (int) $priority );
		return true;
	}

	function remove_filter( $hook, $callback, $priority = 10 ) {
		foreach ( $GLOBALS['ph_filters'][ $hook ] ?? array() as $i => $entry ) {
			if ( $entry[0] === $callback && $entry[2] === (int) $priority ) {
				unset( $GLOBALS['ph_filters'][ $hook ][ $i ] );
				return true;
			}
		}
		return false;
	}

	function apply_filters( $hook, $value, ...$args ) {
		global $wp_mock_filter_overrides;
		if ( is_array( $wp_mock_filter_overrides ) && array_key_exists( $hook, $wp_mock_filter_overrides ) ) {
			return $wp_mock_filter_overrides[ $hook ];
		}
		foreach ( $GLOBALS['ph_filters'][ $hook ] ?? array() as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) );
		}
		return $value;
	}

	if ( ! function_exists( '__return_false' ) ) {
		function __return_false() {
			return false;
		}
	}
	if ( ! function_exists( '__return_true' ) ) {
		function __return_true() {
			return true;
		}
	}

	/**
	 * The addresses core's wp_http_validate_url() calls internal, in the size the
	 * test picked: $GLOBALS['ph_wp_list'] is "short" or "full".
	 */
	function ph_wp_refuses_address( array $p ): bool {
		if ( 127 === $p[0] || 10 === $p[0] || 0 === $p[0] || ( 172 === $p[0] && $p[1] >= 16 && $p[1] <= 31 ) || ( 192 === $p[0] && 168 === $p[1] ) ) {
			return true;
		}
		if ( 'full' !== ( $GLOBALS['ph_wp_list'] ?? 'short' ) ) {
			return false;
		}
		return ( 192 === $p[0] && 0 === $p[1] && ( 0 === $p[2] || 2 === $p[2] ) )
			|| ( 192 === $p[0] && 88 === $p[1] && 99 === $p[2] )
			|| ( 198 === $p[0] && 51 === $p[1] && 100 === $p[2] )
			|| ( 203 === $p[0] && 0 === $p[1] && 113 === $p[2] )
			|| ( 169 === $p[0] && 254 === $p[1] )
			|| ( 100 === $p[0] && $p[1] >= 64 && $p[1] <= 127 )
			|| ( 198 === $p[0] && $p[1] >= 18 && $p[1] <= 19 )
			|| $p[0] >= 224;
	}

	/** Model of wp_http_validate_url() in WordPress core. */
	function wp_http_validate_url( $url ) {
		$parsed = parse_url( (string) $url );
		if ( ! $parsed || empty( $parsed['host'] ) || ! in_array( $parsed['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) || false !== strpbrk( $parsed['host'], ':#?[]' ) ) {
			return false;
		}
		$home      = parse_url( (string) get_option( 'home', '' ) );
		$same_host = isset( $home['host'] ) && strtolower( $home['host'] ) === strtolower( $parsed['host'] );
		$host      = trim( $parsed['host'], '.' );

		if ( ! $same_host ) {
			if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$ip = $host;
			} else {
				// WordPress resolves a second time; $ph_dns_wp is what that answer can be.
				$answer = $GLOBALS['ph_dns_wp'][ $host ] ?? $GLOBALS['wpmmcc_test_dns'][ $host ] ?? false;
				if ( false === $answer ) {
					return false;
				}
				$ip = $answer[0];
			}
			if ( ph_wp_refuses_address( array_map( 'intval', explode( '.', $ip ) ) ) ) {
				if ( ! apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
					return false;
				}
			}
		}

		if ( empty( $parsed['port'] ) ) {
			return $url;
		}
		$allowed_ports = apply_filters( 'http_allowed_safe_ports', array( 80, 443, 8080 ), $host, $url );
		if ( is_array( $allowed_ports ) && in_array( (int) $parsed['port'], $allowed_ports, true ) ) {
			return $url;
		}
		return false;
	}

	function ph_fresh_wire(): array {
		return array(
			'calls'    => array(),  // every wp_safe_remote_post() call: url + args.
			'unsafe'   => array(),  // every wp_remote_post() call (there must be none).
			'reply'    => null,     // array(code, body, redirect_to?) or a Throwable to throw.
			'probe'    => array(),  // URLs the model asks its validator about while a call is in flight.
			'probed'   => array(),  // url => did WordPress accept it.
			'followed' => array(),  // redirect targets the model followed.
		);
	}

	$GLOBALS['ph_wire'] = ph_fresh_wire();

	/**
	 * Model of wp_safe_remote_post(): validates the URL, and the target of a
	 * redirect it is asked to follow. "probe" stands for the other URLs core
	 * validates during a request (redirect targets); they are asked while the
	 * call is in flight, which is when Peer_Http's bridging filters exist.
	 */
	function wp_safe_remote_post( $url, $args = array() ) {
		$wire            = &$GLOBALS['ph_wire'];
		$wire['calls'][] = array( 'url' => $url, 'args' => $args );
		if ( false === wp_http_validate_url( $url ) ) {
			return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
		}
		foreach ( $wire['probe'] as $probe ) {
			$wire['probed'][ $probe ] = false !== wp_http_validate_url( $probe );
		}
		$reply = $wire['reply'];
		if ( $reply instanceof \Throwable ) {
			throw $reply;
		}
		$reply = $reply ?: array( 'code' => 200, 'body' => '{"success":true,"data":{"items":[]}}' );
		if ( isset( $reply['redirect_to'] ) && (int) ( $args['redirection'] ?? 5 ) > 0 ) {
			if ( false === wp_http_validate_url( $reply['redirect_to'] ) ) {
				return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
			}
			$wire['followed'][] = $reply['redirect_to'];
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"followed":true}',
			);
		}
		$response = array(
			'response' => array( 'code' => (int) $reply['code'] ),
			'body'     => (string) $reply['body'],
		);
		if ( isset( $args['headers']['X-WPMMCC-Nonce'] ) ) {
			$headers = $args['headers'];
			$route = preg_replace( '#^/wp-json#', '', (string) parse_url( $url, PHP_URL_PATH ) );
			$canonical = implode( "\n", array(
				'WPMMCC-RESPONSE-v1', $headers['X-WPMMCC-Nonce'], $headers['X-WPMMCC-Timestamp'],
				$headers['X-WPMMCC-Site-UUID'], 'POST', $route,
				(string) $reply['code'], hash( 'sha256', (string) $reply['body'] ),
			) );
			$response['headers'] = array( 'x-wpmmcc-response-signature' => hash_hmac( 'sha256', $canonical, 'ph-shared-secret' ) );
		}
		return $response;
	}

	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['ph_wire']['unsafe'][] = $url;
		return new WP_Error( 'unsafe_transport_used', 'a peer request must not use wp_remote_post()' );
	}

	require_once dirname( __DIR__ ) . '/bootstrap.php';
	require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

	use WPMMCC\REST\Peer_Http;
	use WPMMCC\REST\Rest_Middleware;
	use WPMMCC\Sync\Manual_Sync;

	echo "=== Running Peer HTTP Test (WMC-09) ===\n";

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

	// An ordinary routable address. The documentation ranges are not an option
	// here: they are refused like the other reserved ones. DNS and HTTP are
	// stubbed, so nothing is ever sent to it.
	const PH_PUBLIC = '93.184.216.34';

	function ph_reset(): void {
		global $wpdb, $wp_mock_options, $wp_mock_cache, $wp_mock_filter_overrides;
		$wpdb = new Mini_SQL_wpdb();
		$wpdb->create_table(
			'wp_wpmmcc_journal',
			array(
				'occurred_at'        => '',
				'event_type'         => '',
				'direction'          => '',
				'canonical_uuid'     => '',
				'object_type'        => 'post',
				'local_object_id'    => 0,
				'peer_uuid'          => '',
				'remote_object_id'   => 0,
				'packet_id'          => '',
				'source_fingerprint' => '',
				'outcome'            => '',
				'duration_ms'        => 0,
				'payload_digest'     => '',
				'diff_summary'       => null,
				'message'            => null,
				'consumed'           => 0,
			)
		);
		$wp_mock_options          = array(
			'wpmmcc_origin_uuid' => 'urn:uuid:site-self',
			'home'               => 'https://this-site.example.test',
		);
		$wp_mock_cache            = array();
		$wp_mock_filter_overrides = array();
		$GLOBALS['ph_filters']    = array();
		$GLOBALS['ph_wire']       = ph_fresh_wire();
		$GLOBALS['ph_wp_list']    = 'short';
		$GLOBALS['wpmmcc_test_dns'] = array(
			'peer.example.test'     => array( PH_PUBLIC ),
			'other.example.test'    => array( '93.184.216.35' ),
			'localhost'             => array( '127.0.0.1' ),
			'intranet.example.test' => array( '10.0.0.7' ),
			'mixed.example.test'    => array( PH_PUBLIC, '10.0.0.7' ),
			'rebind.example.test'   => array( PH_PUBLIC ),
		);
		$GLOBALS['ph_dns_wp']     = array();
	}

	function ph_blocked( $result ): bool {
		return is_wp_error( $result ) && 'wpmmcc_peer_url_blocked' === $result->get_error_code();
	}

	function ph_filter_count(): int {
		$n = 0;
		foreach ( array( 'http_request_host_is_external', 'http_allowed_safe_ports' ) as $hook ) {
			$n += count( $GLOBALS['ph_filters'][ $hook ] ?? array() );
		}
		return $n;
	}

	// -----------------------------------------------------------------------
	// Scenarios
	// -----------------------------------------------------------------------

	function ph_scenario_url_policy(): void {
		echo "\n--- url_policy: which peer URLs are refused ---\n";
		ph_reset();

		$refused = array(
			'loopback'                       => 'http://127.0.0.1:8081/wp-json/wpmmcc/v1/sync/push',
			'loopback, top of the range'     => 'http://127.255.255.254/',
			'this network'                   => 'http://0.0.0.0/',
			'this network, top'              => 'http://0.255.255.254/',
			'10/8'                           => 'http://10.1.2.3/',
			'10/8, top'                      => 'http://10.255.255.254/',
			'172.16/12, bottom'              => 'http://172.16.0.1/',
			'172.16/12, top'                 => 'http://172.31.255.254/',
			'192.168/16'                     => 'http://192.168.0.9/',
			'192.168/16, top'                => 'http://192.168.255.254/',
			'link-local, top'                => 'http://169.254.255.254/',
			'link-local (cloud metadata)'    => 'http://169.254.169.254/latest/meta-data/',
			'carrier NAT, bottom'            => 'http://100.64.0.1/',
			'carrier NAT, top'               => 'http://100.127.255.254/',
			'IETF protocol assignments'      => 'http://192.0.0.8/',
			'TEST-NET-1'                     => 'http://192.0.2.1/',
			'6to4 relay anycast'             => 'http://192.88.99.1/',
			'benchmarking, bottom'           => 'http://198.18.0.1/',
			'benchmarking (fake-IP DNS)'     => 'http://198.18.0.53/',
			'benchmarking, top'              => 'http://198.19.255.254/',
			'TEST-NET-2'                     => 'http://198.51.100.7/',
			'TEST-NET-3'                     => 'http://203.0.113.7/',
			'multicast, bottom'              => 'http://224.0.0.1/',
			'multicast, top'                 => 'http://239.255.255.254/',
			'reserved, bottom'               => 'http://240.0.0.1/',
			'broadcast'                      => 'http://255.255.255.255/',
			'localhost by name'              => 'http://localhost/',
			'a name that resolves privately' => 'https://intranet.example.test/',
			'one private record of two'      => 'https://mixed.example.test/',
		);
		foreach ( $refused as $label => $url ) {
			$r = Peer_Http::vet( $url );
			assert_test( ph_blocked( $r ), "refused: {$label}" );
			assert_test( false === strpos( $r->get_error_message(), $url ), "the refusal for {$label} does not disclose the URL" );
			assert_test( false !== strpos( $r->get_error_message(), 'was refused' ), "the refusal for {$label} reports the policy refusal" );
		}

		// The remedy in the message has to be one that works: the option does not open link-local.
		$meta = Peer_Http::vet( 'http://169.254.169.254/latest/meta-data/' )->get_error_message();
		assert_test( false !== strpos( $meta, 'never opens' ) && false !== strpos( $meta, 'wpmmcc_allow_private_peer_host' ), 'the link-local refusal says the option cannot open it and the filter can' );
		assert_test( false === strpos( $meta, 'Enable the wpmmcc_allow_private_peers option' ), 'and does not send the admin to a switch that cannot help' );
		$lan = Peer_Http::vet( 'http://10.1.2.3/' )->get_error_message();
		assert_test( false !== strpos( $lan, 'Enable the wpmmcc_allow_private_peers option' ), 'a private-network refusal says to enable the option' );

		$malformed = array(
			'ftp'           => 'ftp://' . PH_PUBLIC . '/',
			'file'          => 'file:///etc/passwd',
			'no scheme'     => '//' . PH_PUBLIC . '/x',
			'empty'         => '',
			'no host'       => 'http:///path',
			'dot host'      => 'http://./x',
			'userinfo'      => 'http://user:pw@' . PH_PUBLIC . '/',
			'IPv6 literal'  => 'http://[::1]/',
			'unresolvable'  => 'http://unresolvable.example.test/',
		);
		foreach ( $malformed as $label => $url ) {
			assert_test( ph_blocked( Peer_Http::vet( $url ) ), "refused: {$label}" );
		}
		assert_test( false !== strpos( Peer_Http::vet( 'http://unresolvable.example.test/' )->get_error_message(), 'does not resolve' ), 'an unresolvable host says so' );
		assert_test( false !== strpos( Peer_Http::vet( 'http://./x' )->get_error_message(), 'not a plain http(s) URL' ), 'a URL whose host is nothing is refused as malformed, before any lookup' );

		// Just outside each range, and an ordinary routable address: passes.
		$public = array(
			'1.0.0.0'          => 'http://1.0.0.0/',
			'9.255.255.255'    => 'http://9.255.255.255/',
			'11.0.0.0'         => 'http://11.0.0.0/',
			'100.63.255.255'   => 'http://100.63.255.255/',
			'100.128.0.0'      => 'http://100.128.0.0/',
			'126.255.255.255'  => 'http://126.255.255.255/',
			'128.0.0.1'        => 'http://128.0.0.1/',
			'169.253.255.255'  => 'http://169.253.255.255/',
			'169.255.0.0'      => 'http://169.255.0.0/',
			'172.15.255.255'   => 'http://172.15.255.255/',
			'172.32.0.0'       => 'http://172.32.0.0/',
			'192.167.255.255'  => 'http://192.167.255.255/',
			'192.169.0.0'      => 'http://192.169.0.0/',
			'192.0.1.1'        => 'http://192.0.1.1/',
			'192.0.3.0'        => 'http://192.0.3.0/',
			'192.88.98.255'    => 'http://192.88.98.255/',
			'192.88.100.0'     => 'http://192.88.100.0/',
			'198.17.255.255'   => 'http://198.17.255.255/',
			'198.20.0.0'       => 'http://198.20.0.0/',
			'198.51.99.255'    => 'http://198.51.99.255/',
			'198.51.101.0'     => 'http://198.51.101.0/',
			'203.0.112.255'    => 'http://203.0.112.255/',
			'203.0.114.0'      => 'http://203.0.114.0/',
			'223.255.255.254'  => 'http://223.255.255.254/',
			'a routable name'  => 'https://' . PH_PUBLIC . '/x',
		);
		foreach ( $public as $label => $url ) {
			$r = Peer_Http::vet( $url );
			assert_test( is_array( $r ) && false === $r['internal'], "passes as public: {$label}" );
		}

		$r = Peer_Http::vet( 'https://Peer.Example.Test.:8443/wp-json' );
		assert_test( is_array( $r ) && 'peer.example.test' === $r['host'] && 8443 === $r['port'] && false === $r['internal'], 'a public name passes; host is normalised, the explicit port is reported' );
		$r = Peer_Http::vet( 'https://peer.example.test/wp-json' );
		assert_test( is_array( $r ) && 0 === $r['port'], 'no explicit port is reported as 0' );
	}

	function ph_scenario_opt_in(): void {
		echo "\n--- opt_in: private peers are allowed on purpose, metadata never by accident ---\n";
		global $wp_mock_options;

		ph_reset();
		assert_test( ph_blocked( Peer_Http::vet( 'http://10.1.2.3:8081/' ) ), 'off by default' );

		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		foreach ( array( 'http://10.1.2.3:8081/', 'http://172.20.0.5/', 'http://192.168.1.20/', 'http://127.0.0.1:8082/', 'http://100.64.0.9/', 'http://198.18.0.53/', 'http://localhost/' ) as $url ) {
			$r = Peer_Http::vet( $url );
			assert_test( is_array( $r ) && true === $r['internal'], "the option opens {$url}" );
		}
		$r = Peer_Http::vet( 'https://mixed.example.test/' );
		assert_test( is_array( $r ) && true === $r['internal'], 'a name with a private record is allowed when private peers are' );
		assert_test( ph_blocked( Peer_Http::vet( 'http://169.254.169.254/latest/meta-data/' ) ), 'but the option never opens the link-local range (cloud metadata)' );
		$r = Peer_Http::vet( 'https://' . PH_PUBLIC . '/' );
		assert_test( is_array( $r ) && false === $r['internal'], 'a public peer is not marked internal just because the option is on' );

		// The filter decides per host, sees the address, and outranks the option.
		ph_reset();
		$seen = array();
		add_filter(
			'wpmmcc_allow_private_peer_host',
			static function ( $allowed, $host, $url, $address ) use ( &$seen ) {
				$seen[] = array( $allowed, $host, $url, $address );
				return '10.1.2.3' === $host;
			},
			10,
			4
		);
		assert_test( is_array( Peer_Http::vet( 'http://10.1.2.3:8081/x' ) ), 'the filter can allow one host' );
		assert_test( ph_blocked( Peer_Http::vet( 'http://10.9.9.9/x' ) ), 'and not another' );
		assert_test( array( false, '10.1.2.3', 'http://10.1.2.3:8081/x', '10.1.2.3' ) === $seen[0], 'the filter receives the default, the host, the URL and the resolved address' );

		ph_reset();
		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		add_filter( 'wpmmcc_allow_private_peer_host', '__return_false', 10, 4 );
		assert_test( ph_blocked( Peer_Http::vet( 'http://10.1.2.3/' ) ), 'the filter can shut what the option opened' );

		ph_reset();
		add_filter( 'wpmmcc_allow_private_peer_host', '__return_true', 10, 4 );
		$r = Peer_Http::vet( 'http://169.254.169.254/' );
		assert_test( is_array( $r ) && true === $r['internal'], 'the filter, which is code, can open the link-local range' );
	}

	function ph_scenario_covers_wordpress_list(): void {
		echo "\n--- covers_wordpress_list: nothing WordPress refuses is public here ---\n";
		global $wp_mock_options;

		// range => address just below, first, last, address just above (null = edge of the space).
		$edges = array(
			'0/8'           => array( null, '0.0.0.0', '0.255.255.255', '1.0.0.0' ),
			'10/8'          => array( '9.255.255.255', '10.0.0.0', '10.255.255.255', '11.0.0.0' ),
			'100.64/10'     => array( '100.63.255.255', '100.64.0.0', '100.127.255.255', '100.128.0.0' ),
			'127/8'         => array( '126.255.255.255', '127.0.0.0', '127.255.255.255', '128.0.0.0' ),
			'169.254/16'    => array( '169.253.255.255', '169.254.0.0', '169.254.255.255', '169.255.0.0' ),
			'172.16/12'     => array( '172.15.255.255', '172.16.0.0', '172.31.255.255', '172.32.0.0' ),
			'192.0.0/24'    => array( '191.255.255.255', '192.0.0.0', '192.0.0.255', '192.0.1.0' ),
			'192.0.2/24'    => array( '192.0.1.255', '192.0.2.0', '192.0.2.255', '192.0.3.0' ),
			'192.88.99/24'  => array( '192.88.98.255', '192.88.99.0', '192.88.99.255', '192.88.100.0' ),
			'192.168/16'    => array( '192.167.255.255', '192.168.0.0', '192.168.255.255', '192.169.0.0' ),
			'198.18/15'     => array( '198.17.255.255', '198.18.0.0', '198.19.255.255', '198.20.0.0' ),
			'198.51.100/24' => array( '198.51.99.255', '198.51.100.0', '198.51.100.255', '198.51.101.0' ),
			'203.0.113/24'  => array( '203.0.112.255', '203.0.113.0', '203.0.113.255', '203.0.114.0' ),
			'224/4 + 240/4' => array( '223.255.255.255', '224.0.0.0', '255.255.255.255', null ),
		);

		ph_reset();
		$GLOBALS['ph_wp_list'] = 'full';
		foreach ( $edges as $range => $e ) {
			foreach ( array( 0 => false, 1 => true, 2 => true, 3 => false ) as $i => $internal ) {
				if ( null === $e[ $i ] ) {
					continue;
				}
				$url = 'http://' . $e[ $i ] . '/';
				assert_test( $internal === ( false === wp_http_validate_url( $url ) ), "model: WordPress's full list " . ( $internal ? 'refuses' : 'accepts' ) . " {$e[$i]} ({$range} edge)" );
				assert_test( $internal === ph_blocked( Peer_Http::vet( $url ) ), "Peer_Http " . ( $internal ? 'refuses' : 'accepts' ) . " {$e[$i]} ({$range} edge)" );
			}
		}

		// Every /16 block, at its two ends: whatever WordPress refuses, Peer_Http refuses.
		$gaps      = array();
		$overblock = array();
		for ( $a = 0; $a <= 255; $a++ ) {
			for ( $b = 0; $b <= 255; $b++ ) {
				foreach ( array( "{$a}.{$b}.0.1", "{$a}.{$b}.255.254" ) as $ip ) {
					$wp_refuses = false === wp_http_validate_url( "http://{$ip}/" );
					$ours       = ph_blocked( Peer_Http::vet( "http://{$ip}/" ) );
					if ( $wp_refuses && ! $ours ) {
						$gaps[] = $ip;
					}
					if ( $ours && ! $wp_refuses ) {
						$overblock[] = $ip;
					}
				}
			}
		}
		assert_test( array() === $gaps, 'no address WordPress refuses passes as public here' . ( $gaps ? ': ' . implode( ', ', array_slice( $gaps, 0, 5 ) ) : '' ) );
		assert_test( array() === $overblock, 'and nothing is refused here that WordPress accepts' . ( $overblock ? ': ' . implode( ', ', array_slice( $overblock, 0, 5 ) ) : '' ) );

		// The case that surfaced all this: fake-IP DNS answers 198.18.x.x for every name.
		foreach ( array( 'short', 'full' ) as $list ) {
			ph_reset();
			$GLOBALS['ph_wp_list']                             = $list;
			$GLOBALS['wpmmcc_test_dns']['fakeip.example.test'] = array( '198.18.0.53' );
			$res = Peer_Http::post( 'https://fakeip.example.test/wp-json', array() );
			assert_test( ph_blocked( $res ), "{$list} list: a name that resolves into 198.18/15 is refused with our reason, not WordPress's \"A valid URL was not provided.\"" );
			assert_test( false !== strpos( $res->get_error_message(), 'wpmmcc_allow_private_peers' ), "{$list} list: and the refusal says how to allow it" );
			assert_test( array() === $GLOBALS['ph_wire']['calls'], "{$list} list: nothing was sent" );

			$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
			$res = Peer_Http::post( 'https://fakeip.example.test/wp-json', array() );
			assert_test( ! is_wp_error( $res ) && 1 === count( $GLOBALS['ph_wire']['calls'] ), "{$list} list: with private peers allowed it is reached, WordPress's own check included" );
		}
	}

	function ph_scenario_safe_transport(): void {
		echo "\n--- safe_transport: wp_safe_remote_post, nothing sent for a refused URL ---\n";
		global $wp_mock_options;

		ph_reset();
		$args = array(
			'timeout' => 10,
			'headers' => array( 'X-Test' => 'yes' ),
			'body'    => '{"a":1}',
		);
		$url  = 'https://' . PH_PUBLIC . '/wp-json/wpmmcc/v1/sync/push';
		$res  = Peer_Http::post( $url, $args );
		assert_test( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ), 'a public peer is reached and its answer returned unchanged' );
		assert_test( 1 === count( $GLOBALS['ph_wire']['calls'] ), 'exactly one request went out' );
		assert_test( $url === $GLOBALS['ph_wire']['calls'][0]['url'] && array_merge( $args, array( 'redirection' => 0 ) ) === $GLOBALS['ph_wire']['calls'][0]['args'], 'with the URL and arguments the caller gave, and no redirects' );
		assert_test( array() === $GLOBALS['ph_wire']['unsafe'], 'through wp_safe_remote_post, never wp_remote_post' );

		ph_reset();
		foreach ( array( 'http://10.0.0.5/wp-json/wpmmcc/v1/sync/push', 'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:8081/x', 'https://intranet.example.test/x' ) as $blocked ) {
			$res = Peer_Http::post( $blocked, $args );
			assert_test( ph_blocked( $res ), "refused: {$blocked}" );
		}
		assert_test( array() === $GLOBALS['ph_wire']['calls'] && array() === $GLOBALS['ph_wire']['unsafe'], 'and nothing was sent for any of them' );

		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		$res = Peer_Http::post( 'http://10.0.0.5:8081/wp-json/wpmmcc/v1/sync/push', $args );
		assert_test( ! is_wp_error( $res ) && 1 === count( $GLOBALS['ph_wire']['calls'] ), 'an opted-in private peer on a custom port is reached' );
		assert_test( array() === $GLOBALS['ph_wire']['unsafe'], 'through the safe transport as well' );
	}

	function ph_scenario_wordpress_check_is_scoped(): void {
		echo "\n--- wordpress_check_is_scoped: WordPress's own check is relaxed for the vetted host and port only ---\n";
		global $wp_mock_options;

		// What the model says about these URLs on their own (no bridge).
		ph_reset();
		assert_test( false === wp_http_validate_url( 'http://10.0.0.5:8081/x' ), 'model: WordPress alone refuses a private host' );
		assert_test( false === wp_http_validate_url( 'https://peer.example.test:8443/x' ), 'model: WordPress alone refuses an unusual port even on a public host' );

		// A public peer on an unusual port works: the port is vetted for that host.
		ph_reset();
		$GLOBALS['ph_wire']['probe'] = array(
			'https://peer.example.test:8443/elsewhere',
			'https://peer.example.test:9443/x',
			'https://other.example.test:8443/x',
			'https://other.example.test:9443/x',
			'https://other.example.test/x',
			'http://10.0.0.5/admin',
			'http://10.0.0.5:8443/admin',
		);
		$res = Peer_Http::post( 'https://peer.example.test:8443/wp-json', array() );
		assert_test( ! is_wp_error( $res ), 'a public peer on port 8443 is reached' );
		$probed = $GLOBALS['ph_wire']['probed'];
		assert_test( true === $probed['https://peer.example.test:8443/elsewhere'], 'while it is in flight, WordPress accepts that host on that port' );
		assert_test( false === $probed['https://peer.example.test:9443/x'], 'but not the same host on another unusual port' );
		assert_test( false === $probed['https://other.example.test:8443/x'], 'nor another host on the same unusual port' );
		assert_test( false === $probed['https://other.example.test:9443/x'], 'nor another host on another port' );
		assert_test( true === $probed['https://other.example.test/x'], 'an ordinary public URL is unaffected' );
		assert_test( false === $probed['http://10.0.0.5/admin'] && false === $probed['http://10.0.0.5:8443/admin'], 'a public peer never makes a private host acceptable' );

		// The opt-in covers that one private host and port, not its neighbours.
		ph_reset();
		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		$GLOBALS['ph_wire']['probe']                   = array(
			'http://10.0.0.5:8081/elsewhere',
			'http://10.0.0.5:9081/x',
			'http://10.0.0.6:8081/x',
			'http://127.0.0.1:8081/x',
			'http://10.0.0.5/x',
		);
		$res = Peer_Http::post( 'http://10.0.0.5:8081/wp-json', array() );
		assert_test( ! is_wp_error( $res ), 'an opted-in private peer is reached' );
		$probed = $GLOBALS['ph_wire']['probed'];
		assert_test( true === $probed['http://10.0.0.5:8081/elsewhere'], 'while it is in flight, WordPress accepts that private host on its port' );
		assert_test( false === $probed['http://10.0.0.5:9081/x'], 'but not another port of it' );
		assert_test( false === $probed['http://10.0.0.6:8081/x'], 'nor a neighbouring private host' );
		assert_test( false === $probed['http://127.0.0.1:8081/x'], 'nor loopback' );

		// Outside a call, none of that is in force.
		assert_test( false === wp_http_validate_url( 'http://10.0.0.5:8081/elsewhere' ), 'after the call WordPress refuses that host again' );

		// A host that was public when vetted and private when WordPress looks
		// again gets no override: the second check stays independent.
		ph_reset();
		$GLOBALS['ph_dns_wp'] = array( 'rebind.example.test' => array( '10.0.0.5' ) );
		$res                  = Peer_Http::post( 'https://rebind.example.test/wp-json', array() );
		assert_test( is_wp_error( $res ) && 'http_request_failed' === $res->get_error_code(), 'a DNS answer that turns private between the two checks is refused by WordPress' );
		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		$res = Peer_Http::post( 'https://rebind.example.test/wp-json', array() );
		assert_test( is_wp_error( $res ), 'and the option does not change that: the host was vetted as public' );
	}

	function ph_scenario_redirects_are_not_followed(): void {
		echo "\n--- redirects_are_not_followed: a peer cannot bounce the request somewhere else ---\n";
		global $wp_mock_options;

		ph_reset();
		Peer_Http::post( 'https://peer.example.test/wp-json', array( 'timeout' => 5 ) );
		assert_test( 0 === $GLOBALS['ph_wire']['calls'][0]['args']['redirection'], 'the request says redirection 0' );
		assert_test( 5 === $GLOBALS['ph_wire']['calls'][0]['args']['timeout'], 'the caller\'s other arguments are kept' );

		ph_reset();
		Peer_Http::post( 'https://peer.example.test/wp-json', array( 'redirection' => 5 ) );
		assert_test( 0 === $GLOBALS['ph_wire']['calls'][0]['args']['redirection'], 'a caller cannot turn redirects back on' );

		// On a release whose list stops at the RFC 1918 ranges (the model's
		// default) 169.254.169.254 is outside WordPress's list, so its redirect
		// check would follow a public peer there.
		$model_follows = 'http://169.254.169.254/latest/meta-data/';
		assert_test( false !== wp_http_validate_url( $model_follows ), 'model: WordPress itself would accept the metadata address as a redirect target' );

		foreach ( array( $model_follows, 'http://10.0.0.5/admin', 'https://other.example.test/x' ) as $target ) {
			ph_reset();
			$GLOBALS['ph_wire']['reply'] = array( 'code' => 302, 'body' => '', 'redirect_to' => $target );
			$res                         = Peer_Http::post( 'https://peer.example.test/wp-json', array() );
			assert_test( ! is_wp_error( $res ) && 302 === (int) wp_remote_retrieve_response_code( $res ), "a redirect to {$target} is returned as the answer" );
			assert_test( array() === $GLOBALS['ph_wire']['followed'], "and was not followed" );
		}

		ph_reset();
		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		$GLOBALS['ph_wire']['reply']                   = array( 'code' => 301, 'body' => '', 'redirect_to' => 'http://10.0.0.5:8081/elsewhere' );
		$res = Peer_Http::post( 'http://10.0.0.5:8081/wp-json', array() );
		assert_test( 301 === (int) wp_remote_retrieve_response_code( $res ) && array() === $GLOBALS['ph_wire']['followed'], 'an opted-in private peer is not followed either' );

		// The answer is handled like any other refusal: not a success.
		ph_reset();
		$GLOBALS['ph_wire']['reply'] = array( 'code' => 302, 'body' => '', 'redirect_to' => $model_follows );
		$peer                        = array(
			'peer_uuid'     => 'urn:uuid:peer-w',
			'endpoint_url'  => 'https://peer.example.test/wp-json/wpmmcc/v1',
			'shared_secret' => Rest_Middleware::encrypt_shared_secret( 'ph-shared-secret', str_pad( 'phsalt', 32, 'x' ) ),
			'key_salt'      => str_pad( 'phsalt', 32, 'x' ),
		);
		$test = Manual_Sync::test_connection( $peer );
		assert_test( false === $test['ok'], 'the admin connection test does not count a redirect as reachable' );
		assert_test( array() === $GLOBALS['ph_wire']['followed'], 'and nothing was followed' );
	}

	function ph_scenario_filters_do_not_leak(): void {
		echo "\n--- filters_do_not_leak: nothing stays registered after a call ---\n";
		global $wp_mock_options;

		ph_reset();
		Peer_Http::post( 'https://peer.example.test:8443/x', array() );
		assert_test( 0 === ph_filter_count(), 'after a call to a public peer on a custom port' );

		Peer_Http::post( 'http://10.0.0.5/x', array() );
		assert_test( 0 === ph_filter_count(), 'after a refused call' );

		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		Peer_Http::post( 'http://10.0.0.5:8081/x', array() );
		assert_test( 0 === ph_filter_count(), 'after a call to an opted-in private peer' );

		$GLOBALS['ph_wire']['reply'] = new \RuntimeException( 'transport exploded' );
		$thrown                      = false;
		try {
			Peer_Http::post( 'http://10.0.0.5:8081/x', array() );
		} catch ( \RuntimeException $e ) {
			$thrown = true;
		}
		assert_test( $thrown, 'setup: the transport exception reaches the caller' );
		assert_test( 0 === ph_filter_count(), 'after a call whose transport threw' );
	}

	function ph_scenario_remote_call_is_wired(): void {
		echo "\n--- remote_call_is_wired: the sync legs use Peer_Http ---\n";
		global $wp_mock_options;

		ph_reset();
		$salt = substr( str_pad( 'phsalt', 32, 'x' ), 0, 32 );
		$peer = function ( string $endpoint ) use ( $salt ): array {
			return array(
				'peer_uuid'     => 'urn:uuid:peer-w',
				'endpoint_url'  => $endpoint,
				'shared_secret' => Rest_Middleware::encrypt_shared_secret( 'ph-shared-secret', $salt ),
				'key_salt'      => $salt,
			);
		};

		$private = $peer( 'http://10.0.0.5:8081/wp-json/wpmmcc/v1' );
		$res     = Manual_Sync::remote_call( $private, '/sync/digest', array( 'limit' => 1 ) );
		assert_test( ph_blocked( $res ), 'a peer endpoint on a private network is refused by remote_call' );
		assert_test( array() === $GLOBALS['ph_wire']['calls'] && array() === $GLOBALS['ph_wire']['unsafe'], 'and nothing was sent' );

		$test = Manual_Sync::test_connection( $private );
		assert_test( false === $test['ok'] && false !== strpos( $test['message'], 'was refused' ), 'the admin connection test reports why' );

		$res = Manual_Sync::remote_call( $peer( 'https://' . PH_PUBLIC . '/wp-json/wpmmcc/v1' ), '/sync/digest', array( 'limit' => 1 ) );
		assert_test( is_array( $res ) && 200 === $res['status'], 'a public peer is reached and its answer parsed' );
		$call = $GLOBALS['ph_wire']['calls'][0] ?? array();
		assert_test( 'https://' . PH_PUBLIC . '/wp-json/wpmmcc/v1/sync/digest' === ( $call['url'] ?? '' ), 'the URL is endpoint + path' );
		assert_test( 'urn:uuid:site-self' === ( $call['args']['headers']['X-WPMMCC-Site-UUID'] ?? '' ) && 1 === preg_match( '/^[0-9a-f]{64}$/', (string) ( $call['args']['headers']['X-WPMMCC-Signature'] ?? '' ) ), 'the signed envelope is intact' );
		assert_test( Manual_Sync::REMOTE_TIMEOUT === ( $call['args']['timeout'] ?? null ) && '{"limit":1}' === ( $call['args']['body'] ?? '' ), 'timeout and body are intact' );
		assert_test( array() === $GLOBALS['ph_wire']['unsafe'], 'through the safe transport' );

		$wp_mock_options['wpmmcc_allow_private_peers'] = 1;
		$res = Manual_Sync::remote_call( $private, '/sync/digest', array( 'limit' => 1 ) );
		assert_test( is_array( $res ) && 200 === $res['status'], 'with private peers allowed the same endpoint is reached' );
	}

	// -----------------------------------------------------------------------
	// Runner
	// -----------------------------------------------------------------------

	$scenarios = array(
		'url_policy',
		'opt_in',
		'covers_wordpress_list',
		'safe_transport',
		'wordpress_check_is_scoped',
		'redirects_are_not_followed',
		'filters_do_not_leak',
		'remote_call_is_wired',
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
		call_user_func( 'ph_scenario_' . $name );
	}

	echo "\n==================================================\n";
	echo "PASS: All {$pass_count} Peer HTTP tests passed successfully!\n";
	echo "==================================================\n";
	exit( 0 );
}
