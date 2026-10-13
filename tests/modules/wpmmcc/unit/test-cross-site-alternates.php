<?php
/**
 * WPMMCC cross-site hreflang alternates (ADR-7, WMC-15).
 *
 * `wptsall_cross_site_alternates` is the one place the plugin tells the SEO
 * emitter which URL is the other site's version of a page. It reads
 * `wpmmcc_cross_mappings.remote_permalink` joined to the peer's language.
 *
 * The ingest used to store the URL of the post IT had just created in that
 * column, i.e. this site's own address. A live pair (slot-g pushes to slot-a)
 * then served, on the receiving page,
 *
 *     hreflang="en-US" href=<this page>   hreflang="zh-CN" href=<this page>
 *
 * the same URL declared as two languages. The ingest no longer writes it
 * (test-peer-identity.php, mapping_holds_no_local_url); this file pins the
 * read side, which also has to cope with rows an earlier release wrote.
 *
 * Scenarios (run one with:  php test-cross-site-alternates.php --only=<name>):
 *   remote_url_is_advertised     a row that holds another site's URL becomes an
 *                                alternate under the peer's language, several
 *                                peers give several languages, and the result
 *                                is merged into what the emitter already had.
 *   own_url_is_never_an_alternate  a legacy row that holds this page's own URL
 *                                (any trailing-slash spelling) produces nothing,
 *                                and does not hide a genuine alternate next to it.
 *   unusable_rows_are_skipped    empty URL / empty language give nothing; the
 *                                query is limited to this post and to active peers.
 *
 * oracle: L1
 *
 * @package WPMMCC\Tests\Unit
 */

// A real permalink has a trailing slash; the bootstrap's stub has none.
function get_permalink( $post = 0, $leavename = false ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;
	return 'https://site-a.example.com/post-' . $id . '/';
}

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once wpmmcc_test_plugin_path( 'includes/class-wpmmcc.php' );

echo "=== Running Cross-Site Alternates Test (ADR-7 / WMC-15) ===\n";

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

const CA_POST = 77;

/** The plugin object without its constructor (it would register every hook). */
function ca_plugin(): \WPMMCC\Plugin {
	return ( new ReflectionClass( \WPMMCC\Plugin::class ) )->newInstanceWithoutConstructor();
}

/**
 * Rows the JOIN returns, fed through the real filter.
 *
 * @param array $rows Each: remote_permalink, target_lang, source_lang.
 * @return array{alternates:array,sql:string}
 */
function ca_run( array $rows, array $existing = array() ): array {
	global $wpdb, $wp_mock_cache;

	$wp_mock_cache               = array();
	$wpdb                        = new Mock_wpdb();
	$wpdb->results_callback      = static function () use ( $rows ) {
		return array_map(
			static function ( $r ) {
				return (object) $r;
			},
			$rows
		);
	};
	$alternates = ca_plugin()->filter_cross_site_alternates( $existing, CA_POST );

	return array(
		'alternates' => $alternates,
		'sql'        => (string) $wpdb->last_query,
	);
}

function ca_row( string $url, string $target = 'zh_CN', string $source = 'en_US' ): array {
	return array(
		'remote_permalink' => $url,
		'target_lang'      => $target,
		'source_lang'      => $source,
	);
}

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

function ca_scenario_remote_url_is_advertised(): void {
	echo "\n--- remote_url_is_advertised: another site's URL becomes an alternate ---\n";

	$out = ca_run( array( ca_row( 'https://site-b.example.com/post-7/' ) ) );
	assert_test( array( 'zh-cn' => 'https://site-b.example.com/post-7/' ) === $out['alternates'], 'a row holding the other site\'s URL is advertised under the peer\'s language (zh_CN -> zh-cn)' );

	$out = ca_run(
		array(
			ca_row( 'https://site-b.example.com/post-7/', 'zh_CN' ),
			ca_row( 'https://site-c.example.com/post-9/', 'de_DE' ),
		)
	);
	assert_test( array( 'zh-cn', 'de-de' ) === array_keys( $out['alternates'] ), 'two peers give two languages' );
	assert_test( 'https://site-c.example.com/post-9/' === $out['alternates']['de-de'], 'each language keeps its own peer\'s URL' );

	$out = ca_run( array( ca_row( 'https://site-b.example.com/post-7/' ) ), array( 'en-us' => 'https://site-a.example.com/post-77/' ) );
	assert_test( array( 'en-us', 'zh-cn' ) === array_keys( $out['alternates'] ), 'the result is merged into what the emitter already had' );
}

function ca_scenario_own_url_is_never_an_alternate(): void {
	echo "\n--- own_url_is_never_an_alternate: rows an earlier release wrote are ignored ---\n";

	$own = get_permalink( CA_POST );
	assert_test( 'https://site-a.example.com/post-77/' === $own, 'setup: this page\'s permalink has a trailing slash' );

	$out = ca_run( array( ca_row( $own ) ) );
	assert_test( array() === $out['alternates'], 'a row that holds this page\'s own URL produces no alternate' );

	$out = ca_run( array( ca_row( rtrim( $own, '/' ) ) ) );
	assert_test( array() === $out['alternates'], 'also without the trailing slash' );

	$out = ca_run( array( ca_row( $own . '/' ) ) );
	assert_test( array() === $out['alternates'], 'and with a doubled one' );

	$out = ca_run( array( ca_row( $own, 'zh_CN' ), ca_row( 'https://site-c.example.com/post-9/', 'de_DE' ) ) );
	assert_test( array( 'de-de' => 'https://site-c.example.com/post-9/' ) === $out['alternates'], 'a genuine alternate next to such a row is still advertised' );

	// Another page of this site is a different URL, not "self": it is the
	// caller's data to get right, not something this filter second-guesses.
	$out = ca_run( array( ca_row( 'https://site-a.example.com/post-78/' ) ) );
	assert_test( array( 'zh-cn' => 'https://site-a.example.com/post-78/' ) === $out['alternates'], 'only the page\'s own URL is dropped, not every URL on this host' );
}

function ca_scenario_unusable_rows_are_skipped(): void {
	echo "\n--- unusable_rows_are_skipped: nothing to advertise, nothing emitted ---\n";

	$out = ca_run( array( ca_row( '' ) ) );
	assert_test( array() === $out['alternates'], 'a row with no URL gives no alternate' );

	$out = ca_run( array( ca_row( 'https://site-b.example.com/post-7/', '' ) ) );
	assert_test( array() === $out['alternates'], 'a row whose peer has no language gives no alternate' );

	$out = ca_run( array() );
	assert_test( array() === $out['alternates'], 'no mapping rows give no alternates' );

	$out = ca_run( array( ca_row( 'https://site-b.example.com/post-7/' ) ) );
	assert_test( false !== strpos( $out['sql'], "local_object_id = '" . CA_POST . "'" ), 'the query is limited to this post' );
	assert_test( false !== strpos( $out['sql'], "p.status = 'active'" ), 'and to active peers' );
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

$scenarios = array(
	'remote_url_is_advertised',
	'own_url_is_never_an_alternate',
	'unusable_rows_are_skipped',
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
	call_user_func( 'ca_scenario_' . $name );
}

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Cross-Site Alternates tests passed successfully!\n";
echo "==================================================\n";
exit( 0 );
