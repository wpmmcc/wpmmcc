<?php
/**
 * DNS for the tests.
 *
 * Peer_Http resolves a peer's host name with an unqualified gethostbynamel()
 * inside WPMMCC\REST, so a function of that name in the same namespace stands
 * in for DNS and no test needs a network. A host answers only what a test put
 * in $GLOBALS['wpmmcc_test_dns'] (host => list of IPv4 addresses); any other
 * host does not resolve.
 *
 * @package WPMMCC\Tests\Support
 */

namespace WPMMCC\REST;

if ( ! function_exists( __NAMESPACE__ . '\gethostbynamel' ) ) {
	/**
	 * Test double for gethostbynamel().
	 *
	 * @param string $host Host name.
	 * @return string[]|false
	 */
	function gethostbynamel( $host ) {
		return $GLOBALS['wpmmcc_test_dns'][ $host ] ?? false;
	}
}
