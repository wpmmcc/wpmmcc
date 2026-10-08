<?php
/**
 * Peer_Http against the WordPress it runs on (WMC-09).
 *
 * tests/unit/test-peer-http.php plays wp_http_validate_url() from a model. This
 * is the same question put to the real one: every IPv4 address WordPress refuses
 * as an HTTP target must also be refused by Peer_Http::vet() under its default
 * policy. A gap means vet() calls a host public and wp_safe_remote_post() then
 * refuses it with "A valid URL was not provided.", which tells the admin nothing.
 * WordPress lengthens that list in security releases, so run this again after
 * upgrading the image.
 *
 * Usage, inside a container that has WordPress and the wpmmcc plugin active:
 *   wp eval-file /path/to/peer-http-vs-wordpress.php
 *
 * Exit status: 0 when Peer_Http covers everything WordPress refuses, 1 on a gap,
 * 2 when the plugin is not loaded. Addresses that Peer_Http refuses and this
 * WordPress accepts are listed but are not a failure: an older WordPress has the
 * shorter list, and that is what Peer_Http's own list is for.
 *
 * @package WPMMCC\Tests\E2E
 */

use WPMMCC\REST\Peer_Http;

if ( ! class_exists( Peer_Http::class ) ) {
	echo "WPMMCC\\REST\\Peer_Http is not loaded: activate the wpmmcc plugin on this site.\n";
	exit( 2 );
}

// Default policy, and nothing that relaxes WordPress's own answer: a lab mu-plugin
// opens private peers, a site may filter http_request_host_is_external.
remove_all_filters( 'pre_option_wpmmcc_allow_private_peers' );
remove_all_filters( 'wpmmcc_allow_private_peer_host' );
add_filter( 'pre_option_wpmmcc_allow_private_peers', '__return_zero', PHP_INT_MAX );
remove_all_filters( 'http_request_host_is_external' );

// WordPress skips its check for the site's own host; an address that is the home host proves nothing.
$home_host = strtolower( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );

$edges = array(
	'0.0.0.0', '0.255.255.255', '1.0.0.0',
	'9.255.255.255', '10.0.0.0', '10.255.255.255', '11.0.0.0',
	'100.63.255.255', '100.64.0.0', '100.127.255.255', '100.128.0.0',
	'126.255.255.255', '127.0.0.0', '127.255.255.255', '128.0.0.0',
	'169.253.255.255', '169.254.0.0', '169.254.169.254', '169.254.255.255', '169.255.0.0',
	'172.15.255.255', '172.16.0.0', '172.31.255.255', '172.32.0.0',
	'191.255.255.255', '192.0.0.0', '192.0.0.255', '192.0.1.0', '192.0.1.255', '192.0.2.0', '192.0.2.255', '192.0.3.0',
	'192.88.98.255', '192.88.99.0', '192.88.99.255', '192.88.100.0',
	'192.167.255.255', '192.168.0.0', '192.168.255.255', '192.169.0.0',
	'198.17.255.255', '198.18.0.0', '198.18.0.53', '198.19.255.255', '198.20.0.0',
	'198.51.99.255', '198.51.100.0', '198.51.100.255', '198.51.101.0',
	'203.0.112.255', '203.0.113.0', '203.0.113.255', '203.0.114.0',
	'223.255.255.255', '224.0.0.0', '239.255.255.255', '240.0.0.0', '255.255.255.255',
);

// Every /16 block at both ends, for a range nobody thought to list above.
$addresses = $edges;
for ( $a = 0; $a <= 255; $a++ ) {
	for ( $b = 0; $b <= 255; $b++ ) {
		$addresses[] = "{$a}.{$b}.0.1";
		$addresses[] = "{$a}.{$b}.255.254";
	}
}
$addresses = array_values( array_unique( $addresses ) );

$checked   = 0;
$wp_refuse = 0;
$gaps      = array();
$extra     = array();
foreach ( $addresses as $ip ) {
	if ( $ip === $home_host ) {
		continue;
	}
	++$checked;
	$url        = 'http://' . $ip . '/';
	$wp_refuses = false === wp_http_validate_url( $url );
	$blocked    = is_wp_error( Peer_Http::vet( $url ) );
	$wp_refuse += $wp_refuses ? 1 : 0;
	if ( $wp_refuses && ! $blocked ) {
		$gaps[] = $ip;
	}
	if ( $blocked && ! $wp_refuses ) {
		$extra[] = $ip;
	}
}

global $wp_version;
printf( "WordPress %s: %d addresses checked, WordPress refuses %d of them.\n", $wp_version, $checked, $wp_refuse );
if ( $extra ) {
	printf( "NOTE: Peer_Http also refuses %d address(es) this WordPress accepts (shorter list on this release): %s\n", count( $extra ), implode( ', ', array_slice( $extra, 0, 12 ) ) );
}
if ( $gaps ) {
	printf( "FAIL: %d address(es) WordPress refuses pass as public in Peer_Http: %s\n", count( $gaps ), implode( ', ', array_slice( $gaps, 0, 20 ) ) );
	exit( 1 );
}
echo "PASS: Peer_Http refuses every address this WordPress refuses.\n";
exit( 0 );
