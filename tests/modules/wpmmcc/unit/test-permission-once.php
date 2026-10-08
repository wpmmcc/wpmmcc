<?php
/**
 * WPMMCC permission gates run once per request (WMC-12) and the unauthenticated
 * ping bucket is not caller-chosen (WMC-13).
 *
 * WordPress core evaluates a route's permission_callback TWICE for one HTTP
 * request, with the very same WP_REST_Request object: once in
 * WP_REST_Server::respond_to_request(), and again in rest_send_allow_header()
 * (wp-includes/rest-api.php, hooked on rest_post_dispatch) to build the
 * `Allow:` header. A gate that spends state (a rate-limit slot, a nonce) has to
 * be evaluated once per request object and answer the repeat from memory.
 * Before the fix, every authenticated request spent two rate-limit slots (the
 * documented 240/min was really 120/min) and, because the second evaluation
 * found the nonce the first one had just stored, wrote a forged
 * `nonce_replayed` row into the audit journal.
 *
 * Every scenario drives the real gates through that two-call sequence.
 *
 * Scenarios (run one with:  php test-permission-once.php --only=<name>):
 *   hmac_once_per_dispatch   a good request: one rate slot, one nonce row, no
 *                            audit row, same verdict twice; a forged request:
 *                            same error twice, one audit row.
 *   every_route_once_per_dispatch  every route the plugin registers whose gate
 *                            is not __return_true obeys the same contract (a
 *                            new stateful gate cannot slip in unnoticed).
 *   gates_keep_separate_memory  two different gates on one request object each
 *                            remember their own verdict.
 *   replay_still_rejected    memory is per request object: the same nonce on
 *                            another object, or on the same object after its
 *                            body / nonce changed, is still refused.
 *   no_identity_bleed        a freed request object's identity is never reused
 *                            to wave a different request through.
 *   memo_is_bounded          the memory keeps a bounded number of requests.
 *   effective_limit_is_documented_limit  240 good requests fit into the 240 per
 *                            minute budget, the 241st is answered 429.
 *   ping_once_per_dispatch   the client-token gate of /sync/ping obeys the same
 *                            contract (token, wrong token, discovery mode).
 *   ping_bucket_not_caller_chosen  the discovery-mode rate bucket comes from
 *                            the connection, not from a request parameter.
 *
 * catalog: WP-HOOK-wpmmcc-rate-limit-per-minute
 * oracle: L1
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\REST\Rest_Middleware;
use WPMMCC\REST\Rest_Ping;

echo "=== Running Permission Gate Once-Per-Request Test (WMC-12 / WMC-13) ===\n";

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

/** A request object that reports when PHP frees it. */
class Po_Tracked_Request extends WP_REST_Request {
	public static $destroyed = 0;

	public function __destruct() {
		self::$destroyed++;
	}
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

function po_reset(): void {
	global $wpdb, $wp_mock_transients, $wp_mock_filter_overrides;
	$wp_mock_transients       = array();
	$wp_mock_filter_overrides = array();
	$wpdb->queries            = array();
	$wpdb->row_callback       = null;
	$wpdb->var_callback       = null;
	unset( $_SERVER['REMOTE_ADDR'] );
	wp_cache_flush();
	delete_option( 'wpmmcc_client_token' );
	delete_option( 'wpmmcc_route_secret' );
}

function po_register_peer( string $uuid, string $secret ): void {
	global $wpdb;
	$salt = bin2hex( random_bytes( 8 ) );
	$row  = (object) array(
		'peer_uuid'     => $uuid,
		'shared_secret' => Rest_Middleware::encrypt_shared_secret( $secret, $salt ),
		'key_salt'      => $salt,
		'status'        => 'active',
	);
	$wpdb->row_callback = function ( $sql ) use ( $row ) {
		if ( false !== strpos( $sql, 'wpmmcc_peers' ) && false !== strpos( $sql, "'" . $row->peer_uuid . "'" ) ) {
			return $row;
		}
		return null;
	};
	$wpdb->var_callback = function ( $sql ) {
		return null;
	};
}

function po_signed( string $route, string $body, string $uuid, string $secret, string $class = 'WP_REST_Request' ): WP_REST_Request {
	$ts    = time();
	$nonce = bin2hex( random_bytes( 8 ) );
	$req   = new $class( 'POST', $route );
	$req->set_body( $body );
	$req->set_header( 'x-wpmmcc-site-uuid', $uuid );
	$req->set_header( 'x-wpmmcc-timestamp', (string) $ts );
	$req->set_header( 'x-wpmmcc-nonce', $nonce );
	$req->set_header( 'x-wpmmcc-signature', Rest_Middleware::sign_request( 'POST', $route, $ts, $nonce, $uuid, $body, $secret ) );
	return $req;
}

/** Everything a peer put on the wire for one request. */
function po_wire( WP_REST_Request $src ): array {
	$wire = array(
		'method'  => $src->get_method(),
		'route'   => $src->get_route(),
		'body'    => $src->get_body(),
		'headers' => array(),
	);
	foreach ( array( 'x-wpmmcc-site-uuid', 'x-wpmmcc-timestamp', 'x-wpmmcc-nonce', 'x-wpmmcc-signature' ) as $header ) {
		$wire['headers'][ $header ] = $src->get_header( $header );
	}
	return $wire;
}

function po_from_wire( array $wire ): WP_REST_Request {
	$req = new WP_REST_Request( $wire['method'], $wire['route'] );
	$req->set_body( $wire['body'] );
	foreach ( $wire['headers'] as $header => $value ) {
		$req->set_header( $header, $value );
	}
	return $req;
}

/** A second request object carrying exactly the same wire data. */
function po_replay_copy( WP_REST_Request $src ): WP_REST_Request {
	return po_from_wire( po_wire( $src ) );
}

/**
 * The two evaluations WordPress core performs for one HTTP request:
 * respond_to_request() first, then rest_send_allow_header() on the same object.
 *
 * @return array{0:mixed,1:mixed} [dispatch verdict, allow-header verdict]
 */
function po_core_permission_calls( $permission_callback, WP_REST_Request $request ): array {
	$dispatch = call_user_func( $permission_callback, $request );
	$allow    = call_user_func( $permission_callback, $request );
	return array( $dispatch, $allow );
}

/** Rate-limit slots spent on a bucket (current + previous window: test-speed safe). */
function po_slots( string $bucket ): int {
	$window = (int) floor( time() / 60 );
	$total  = 0;
	foreach ( array( $window - 1, $window ) as $w ) {
		$total += (int) get_transient( 'wpmmcc_rl_' . substr( md5( $bucket ), 0, 24 ) . '_' . $w );
	}
	return $total;
}

function po_inserts( string $table ): int {
	global $wpdb;
	$n = 0;
	foreach ( $wpdb->queries as $q ) {
		if ( 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_' . $table . ' ' ) ) {
			$n++;
		}
	}
	return $n;
}

/** message column of every journal row written so far. */
function po_journal_messages(): array {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->queries as $q ) {
		if ( 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_journal ' ) ) {
			$row   = json_decode( substr( $q, strpos( $q, '{' ) ), true );
			$out[] = (string) ( $row['message'] ?? '' );
		}
	}
	return $out;
}

function po_is_error( $value, string $code ): bool {
	return is_wp_error( $value ) && $code === $value->get_error_code();
}

const PO_HMAC_GATE = array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' );

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

function po_scenario_hmac_once_per_dispatch(): void {
	echo "\n--- hmac_once_per_dispatch ---\n";
	po_reset();
	$uuid   = wp_generate_uuid4();
	$secret = 'po-hmac-shared-secret';
	po_register_peer( $uuid, $secret );
	$route = '/wpmmcc/v1/sync/push';

	$good = po_signed( $route, '{"probe":"good"}', $uuid, $secret );
	list( $dispatch, $allow ) = po_core_permission_calls( PO_HMAC_GATE, $good );
	assert_test( true === $dispatch, 'dispatch evaluation of a correctly signed request is true' );
	assert_test( true === $allow, 'the allow-header re-evaluation of the same request object is true as well (pre-fix: nonce_replayed)' );
	$slots = po_slots( 'peer|' . $uuid );
	assert_test( 1 === $slots, "one request spends exactly one rate-limit slot (got {$slots})" );
	assert_test( array() === po_journal_messages(), 'a good request writes no audit row (pre-fix: a forged nonce_replayed row)' );
	assert_test( 1 === po_inserts( 'nonces' ), 'the nonce is stored exactly once' );

	$forged = po_signed( $route, '{"probe":"forged"}', $uuid, 'some-other-secret' );
	list( $dispatch, $allow ) = po_core_permission_calls( PO_HMAC_GATE, $forged );
	assert_test( po_is_error( $dispatch, 'wpmmcc_signature_mismatch' ), 'dispatch evaluation of a forged request is wpmmcc_signature_mismatch' );
	assert_test( po_is_error( $allow, 'wpmmcc_signature_mismatch' ), 'the repeat gives the same error (the Allow header never lists the method)' );
	$slots = po_slots( 'peer|' . $uuid );
	assert_test( 1 === $slots, "a forged request cannot spend an authenticated peer slot (total {$slots})" );
	assert_test( 1 === po_slots( 'preauth|ip|unknown' ), 'the forged request spends one separate preauth slot, not two' );
	assert_test( array( 'signature_mismatch' ) === po_journal_messages(), 'one audit row for the forged request, reason signature_mismatch' );
}

function po_scenario_every_route_once_per_dispatch(): void {
	echo "\n--- every_route_once_per_dispatch ---\n";
	po_reset();
	$uuid   = wp_generate_uuid4();
	$secret = 'po-route-secret';
	po_register_peer( $uuid, $secret );
	update_option( 'wpmmcc_client_token', 'po-route-token' );

	$source = (string) file_get_contents( wpmmcc_test_plugin_path( 'includes/class-wpmmcc.php' ) );
	preg_match_all( '/new\s+(Rest_[A-Za-z_]+)\s*\(\s*\)/', $source, $m );
	$controllers = array_values( array_unique( $m[1] ) );
	assert_test( in_array( 'Rest_Push', $controllers, true ) && in_array( 'Rest_Ping', $controllers, true ), 'the plugin bootstrap lists its REST controllers (' . implode( ', ', $controllers ) . ')' );

	$GLOBALS['wp_mock_rest_routes'] = array();
	foreach ( $controllers as $short ) {
		$class = 'WPMMCC\\REST\\' . $short;
		( new $class() )->register_routes();
	}
	$checked = 0;
	foreach ( $GLOBALS['wp_mock_rest_routes'] as $key => $def ) {
		$gate  = $def['permission_callback'] ?? null;
		$route = '/' . $key;
		if ( '__return_true' === $gate ) {
			continue; // Public by design: there is no state to spend.
		}
		if ( PO_HMAC_GATE === $gate ) {
			$before = po_slots( 'peer|' . $uuid );
			$req    = po_signed( $route, '{"route":"' . $key . '"}', $uuid, $secret );
			list( $dispatch, $allow ) = po_core_permission_calls( $gate, $req );
			assert_test( true === $dispatch && true === $allow, "{$route}: signed request is authorised at dispatch and at the allow-header re-evaluation" );
			assert_test( 1 === po_slots( 'peer|' . $uuid ) - $before, "{$route}: one request spends one rate-limit slot" );
			$checked++;
			continue;
		}
		if ( is_array( $gate ) && $gate[0] instanceof Rest_Ping ) {
			$bucket = 'client|' . substr( md5( 'po-route-token' ), 0, 16 );
			$before = po_slots( $bucket );
			$req    = new WP_REST_Request( 'GET', $route );
			$req->set_header( 'x-wpmmcc-client-token', 'po-route-token' );
			list( $dispatch, $allow ) = po_core_permission_calls( $gate, $req );
			assert_test( true === $dispatch && true === $allow, "{$route}: client token is accepted at dispatch and at the allow-header re-evaluation" );
			assert_test( 1 === po_slots( $bucket ) - $before, "{$route}: one request spends one rate-limit slot" );
			$checked++;
			continue;
		}
		assert_test( false, "{$route}: permission_callback is not known to this contract; make it once-per-request (Rest_Middleware::once_per_request) and list it here" );
	}
	assert_test( $checked >= 7, "all stateful gates were exercised ({$checked} routes)" );
}

function po_scenario_gates_keep_separate_memory(): void {
	echo "\n--- gates_keep_separate_memory ---\n";
	po_reset();
	$uuid   = wp_generate_uuid4();
	$secret = 'po-gates-secret';
	po_register_peer( $uuid, $secret );
	update_option( 'wpmmcc_client_token', 'po-gates-token' );
	$ping_gate = array( new Rest_Ping(), 'check_permission' );

	// One request object that both gates can authorise: signed AND carrying the client token.
	$req = po_signed( '/wpmmcc/v1/sync/ping', '', $uuid, $secret );
	$req->set_header( 'x-wpmmcc-client-token', 'po-gates-token' );
	$verdicts = array(
		call_user_func( PO_HMAC_GATE, $req ),
		call_user_func( $ping_gate, $req ),
		call_user_func( PO_HMAC_GATE, $req ),
		call_user_func( $ping_gate, $req ),
	);
	assert_test( array( true, true, true, true ) === $verdicts, 'two different gates alternating on one request object each keep their own memory (no re-evaluation, no replay error)' );
	assert_test( 1 === po_slots( 'peer|' . $uuid ), 'the HMAC gate charged its bucket once' );
	assert_test( 1 === po_slots( 'client|' . substr( md5( 'po-gates-token' ), 0, 16 ) ), 'the token gate charged its bucket once' );
}

function po_scenario_replay_still_rejected(): void {
	echo "\n--- replay_still_rejected ---\n";
	po_reset();
	$uuid   = wp_generate_uuid4();
	$secret = 'po-replay-secret';
	po_register_peer( $uuid, $secret );
	$route = '/wpmmcc/v1/sync/push';

	$req = po_signed( $route, '{"probe":"replay"}', $uuid, $secret );
	list( $dispatch, $allow ) = po_core_permission_calls( PO_HMAC_GATE, $req );
	assert_test( true === $dispatch && true === $allow, 'the original request is authorised' );
	assert_test( array() === po_journal_messages(), 'and leaves no audit row' );

	$copy = po_replay_copy( $req );
	list( $dispatch, $allow ) = po_core_permission_calls( PO_HMAC_GATE, $copy );
	assert_test( po_is_error( $dispatch, 'wpmmcc_nonce_replayed' ) && 403 === $dispatch->get_error_data()['status'], 'the same wire data on a NEW request object is refused as a replay (403)' );
	assert_test( po_is_error( $allow, 'wpmmcc_nonce_replayed' ), 'and so is its allow-header re-evaluation' );
	assert_test( array( 'nonce_replayed' ) === po_journal_messages(), 'exactly one audit row records the replay' );

	// The memory answers only for unchanged input. Whatever the verdict depended
	// on, changing it on the authorised object means the gate looks again.
	$mutations = array(
		'body'      => function ( WP_REST_Request $r ) {
			$r->set_body( '{"tampered":true}' );
		},
		'nonce'     => function ( WP_REST_Request $r ) {
			$r->set_header( 'x-wpmmcc-nonce', bin2hex( random_bytes( 8 ) ) );
		},
		'signature' => function ( WP_REST_Request $r ) {
			$r->set_header( 'x-wpmmcc-signature', str_repeat( 'a', 64 ) );
		},
		'timestamp' => function ( WP_REST_Request $r ) {
			$r->set_header( 'x-wpmmcc-timestamp', (string) ( time() - 1000 ) );
		},
		'peer uuid' => function ( WP_REST_Request $r ) {
			$r->set_header( 'x-wpmmcc-site-uuid', wp_generate_uuid4() );
		},
		'route'     => function ( WP_REST_Request $r ) {
			$r->set_route( '/wpmmcc/v1/sync/pull' );
		},
		'method'    => function ( WP_REST_Request $r ) {
			$r->set_method( 'GET' );
		},
	);
	foreach ( $mutations as $what => $mutate ) {
		$changed = po_signed( $route, '{"probe":"mutate-' . $what . '"}', $uuid, $secret );
		assert_test( true === call_user_func( PO_HMAC_GATE, $changed ), "a fresh request is authorised (before its {$what} changes)" );
		$mutate( $changed );
		assert_test( is_wp_error( call_user_func( PO_HMAC_GATE, $changed ) ), "the authorised object with a changed {$what} is checked again, not waved through" );
	}
}

function po_scenario_no_identity_bleed(): void {
	echo "\n--- no_identity_bleed ---\n";
	po_reset();
	global $wp_mock_filter_overrides;
	$wp_mock_filter_overrides['wpmmcc_rate_limit_per_minute'] = 0; // Volume test: not about the budget.
	$uuid   = wp_generate_uuid4();
	$secret = 'po-bleed-secret';
	po_register_peer( $uuid, $secret );
	$route = '/wpmmcc/v1/sync/push';

	$wrong_accept = 0;
	$wrong_refuse = 0;
	for ( $i = 0; $i < 300; $i++ ) {
		$valid = ( 0 === $i % 2 );
		$req   = po_signed( $route, '{"i":' . $i . '}', $uuid, $valid ? $secret : 'forged-secret' );
		$res   = call_user_func( PO_HMAC_GATE, $req );
		if ( $valid && true !== $res ) {
			$wrong_refuse++;
		}
		if ( ! $valid && true === $res ) {
			$wrong_accept++;
		}
		unset( $req ); // PHP hands the freed object id to the next request.
	}
	assert_test( 0 === $wrong_accept, "no forged request was authorised by a previous request's memory ({$wrong_accept} of 150)" );
	assert_test( 0 === $wrong_refuse, "no good request was refused ({$wrong_refuse} of 150)" );

	// The hard case: the authorised request object is gone and its identity is
	// free again. The same wire data on a fresh object is a replay and must not
	// inherit the old verdict from the recycled identity.
	$req  = po_signed( $route, '{"freed":true}', $uuid, $secret );
	$wire = po_wire( $req );
	assert_test( true === call_user_func( PO_HMAC_GATE, $req ), 'a request is authorised once' );
	unset( $req );
	$again = po_from_wire( $wire );
	assert_test( po_is_error( call_user_func( PO_HMAC_GATE, $again ), 'wpmmcc_nonce_replayed' ), 'its wire data replayed on a new object after the first object was released is refused' );
}

function po_scenario_memo_is_bounded(): void {
	echo "\n--- memo_is_bounded ---\n";
	po_reset();
	global $wp_mock_filter_overrides;
	$wp_mock_filter_overrides['wpmmcc_rate_limit_per_minute'] = 0;
	$uuid   = wp_generate_uuid4();
	$secret = 'po-bounded-secret';
	po_register_peer( $uuid, $secret );
	$route = '/wpmmcc/v1/sync/push';

	Po_Tracked_Request::$destroyed = 0;
	$total = 200;
	for ( $i = 0; $i < $total; $i++ ) {
		$req = po_signed( $route, '{"i":' . $i . '}', $uuid, $secret, 'Po_Tracked_Request' );
		po_core_permission_calls( PO_HMAC_GATE, $req );
		unset( $req );
	}
	$retained = $total - Po_Tracked_Request::$destroyed;
	assert_test( $retained <= 64, "after {$total} requests at most 64 request objects are still held by the gate memory (held {$retained})" );

	// Eviction drops the OLDEST entries: the request being served right now is
	// always remembered, however many requests came before it.
	$latest = po_signed( $route, '{"latest":true}', $uuid, $secret );
	list( $dispatch, $allow ) = po_core_permission_calls( PO_HMAC_GATE, $latest );
	assert_test( true === $dispatch && true === $allow, 'with the memory full, the newest request is still evaluated once (dispatch and allow-header both true)' );
}

function po_scenario_effective_limit_is_documented_limit(): void {
	echo "\n--- effective_limit_is_documented_limit ---\n";
	po_reset();
	while ( time() % 60 >= 55 ) {
		usleep( 250000 ); // Keep the whole burst inside one rate-limit window.
	}
	$uuid   = wp_generate_uuid4();
	$secret = 'po-limit-secret';
	po_register_peer( $uuid, $secret );
	$route = '/wpmmcc/v1/sync/push';
	$limit = Rest_Middleware::DEFAULT_RATE_LIMIT_PER_MINUTE;

	$served = 0;
	for ( $i = 0; $i < $limit; $i++ ) {
		$req = po_signed( $route, '{"i":' . $i . '}', $uuid, $secret );
		list( $dispatch ) = po_core_permission_calls( PO_HMAC_GATE, $req );
		if ( true === $dispatch ) {
			$served++;
		}
	}
	assert_test( $limit === $served, "the {$limit} requests the documentation promises per minute are all served (served {$served})" );
	$over = po_signed( $route, '{"over":true}', $uuid, $secret );
	list( $dispatch ) = po_core_permission_calls( PO_HMAC_GATE, $over );
	assert_test( po_is_error( $dispatch, 'wpmmcc_rate_limited' ) && 429 === $dispatch->get_error_data()['status'], 'request number ' . ( $limit + 1 ) . ' is answered 429 wpmmcc_rate_limited' );
}

function po_scenario_ping_once_per_dispatch(): void {
	echo "\n--- ping_once_per_dispatch ---\n";
	po_reset();
	$ping = new Rest_Ping();
	$gate = array( $ping, 'check_permission' );
	$make = function ( ?string $token ): WP_REST_Request {
		$req = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
		if ( null !== $token ) {
			$req->set_header( 'x-wpmmcc-client-token', $token );
		}
		return $req;
	};

	update_option( 'wpmmcc_client_token', 'po-ping-token' );
	$bucket = 'client|' . substr( md5( 'po-ping-token' ), 0, 16 );
	list( $dispatch, $allow ) = po_core_permission_calls( $gate, $make( 'po-ping-token' ) );
	assert_test( true === $dispatch && true === $allow, 'the right client token is accepted at dispatch and on the repeat' );
	$slots = po_slots( $bucket );
	assert_test( 1 === $slots, "one ping spends exactly one rate-limit slot (got {$slots})" );

	list( $dispatch, $allow ) = po_core_permission_calls( $gate, $make( 'wrong-token' ) );
	assert_test( po_is_error( $dispatch, 'wpmmcc_unauthorized' ) && po_is_error( $allow, 'wpmmcc_unauthorized' ), 'a wrong token is refused both times with the same error' );
	assert_test( 1 === po_slots( $bucket ), 'a refused token spends no slot of the real token bucket' );

	// Each place a token can come from: once accepted, swapping it for a wrong
	// one on the same request object is looked at again.
	$sources = array(
		'x-wpmmcc-client-token'  => function ( WP_REST_Request $r, string $token ) {
			$r->set_header( 'x-wpmmcc-client-token', $token );
		},
		'x-wptsall-client-token' => function ( WP_REST_Request $r, string $token ) {
			$r->set_header( 'x-wptsall-client-token', $token );
		},
		'authorization bearer'   => function ( WP_REST_Request $r, string $token ) {
			$r->set_header( 'authorization', '' === $token ? '' : 'Bearer ' . $token );
		},
		'token parameter'        => function ( WP_REST_Request $r, string $token ) {
			$r->set_param( 'token', $token );
		},
	);
	foreach ( $sources as $name => $put ) {
		$req = $make( null );
		$put( $req, 'po-ping-token' );
		assert_test( true === call_user_func( $gate, $req ), "the token in {$name} is accepted" );
		$put( $req, 'swapped-wrong-token' );
		assert_test( po_is_error( call_user_func( $gate, $req ), 'wpmmcc_unauthorized' ), "swapping the token in {$name} on the same request object is checked again and refused" );
	}

	delete_option( 'wpmmcc_client_token' );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	list( $dispatch, $allow ) = po_core_permission_calls( $gate, $make( null ) );
	assert_test( true === $dispatch && true === $allow, 'discovery mode (no token configured) answers true at dispatch and on the repeat' );
	$slots = po_slots( 'client|ip|203.0.113.9' );
	assert_test( 1 === $slots, "a discovery ping spends exactly one slot of the connection's bucket (got {$slots})" );
}

function po_scenario_ping_bucket_not_caller_chosen(): void {
	echo "\n--- ping_bucket_not_caller_chosen ---\n";
	po_reset();
	global $wp_mock_filter_overrides;
	$wp_mock_filter_overrides['wpmmcc_rate_limit_per_minute'] = 3;
	$gate = array( new Rest_Ping(), 'check_permission' );

	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	$verdicts               = array();
	for ( $i = 1; $i <= 5; $i++ ) {
		$req = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
		$req->set_param( 'request_ip', '198.51.100.' . $i ); // The caller names a fresh "address" every time.
		$res        = call_user_func( $gate, $req );
		$verdicts[] = is_wp_error( $res ) ? $res->get_error_code() : 'ok';
	}
	assert_test(
		array( 'ok', 'ok', 'ok', 'wpmmcc_rate_limited', 'wpmmcc_rate_limited' ) === $verdicts,
		'one connection cannot dodge the discovery limit by inventing a request_ip each time (' . implode( ',', $verdicts ) . ')'
	);

	$_SERVER['REMOTE_ADDR'] = '198.51.100.250';
	$req                    = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
	assert_test( true === call_user_func( $gate, $req ), 'a different connection has its own bucket' );
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

$scenarios = array(
	'hmac_once_per_dispatch',
	'every_route_once_per_dispatch',
	'gates_keep_separate_memory',
	'replay_still_rejected',
	'no_identity_bleed',
	'memo_is_bounded',
	'effective_limit_is_documented_limit',
	'ping_once_per_dispatch',
	'ping_bucket_not_caller_chosen',
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
	call_user_func( 'po_scenario_' . $name );
}

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Permission Gate tests passed successfully!\n";
echo "==================================================\n";
exit( 0 );
