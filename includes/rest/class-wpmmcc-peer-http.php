<?php
/**
 * WPMMCC outbound HTTP to a peer site.
 *
 * The one place this plugin opens a connection to a peer. The URL comes from
 * an admin form or from a handshake a remote party answers, so it is input:
 * sent with a plain wp_remote_post() it lets whoever can set it aim the WordPress
 * host at its own loopback, its private network or a cloud metadata service
 * (WMC-09, server-side request forgery).
 *
 * The request goes out through wp_safe_remote_post(), which refuses private
 * addresses and unusual ports and re-checks every redirect, behind two more
 * rules of ours:
 *  - WordPress resolves a host name to its first address only, and how much it
 *    calls private depends on the release: the x.y.0 releases up to at least
 *    6.8.0 stop at 0/8, 10/8, 127/8, 172.16/12 and 192.168/16 (no 169.254.0.0/16,
 *    where cloud metadata services live, no carrier NAT), while patched releases
 *    refuse the whole IANA special-purpose list. The host is checked here against
 *    every address it resolves to and against that whole list, so the answer does
 *    not change with the release (the plugin supports 5.8 and up).
 *  - A peer on a private network is a legitimate setup (LAN, Docker, one host
 *    running both sites), so it is opt-in: the wpmmcc_allow_private_peers option
 *    or the wpmmcc_allow_private_peer_host filter. The option alone never opens
 *    the link-local range; only the filter, which is code, can.
 *  - Redirects are not followed. A public host that redirects to 169.254.169.254
 *    would pass WordPress's redirect check, and a peer endpoint that redirects
 *    at all is a wrong URL: 301/302 turn the POST into a GET, so the call never
 *    worked. The 3xx answer is returned and reported like any other refusal.
 *
 * What is NOT closed: the address is resolved here and again by the HTTP
 * client, so a DNS answer that changes between the two is not caught by this
 * class. wp_safe_remote_post()'s own check still runs on the second answer.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Peer_Http
 */
class Peer_Http {

	/**
	 * IPv4 ranges that are not the public internet: the entries of the IANA
	 * special-purpose registry that are not globally reachable.
	 *
	 * Keep it a superset of what wp_http_validate_url() refuses. An address that
	 * passes vet() but fails WordPress's own check comes back as "A valid URL was
	 * not provided.", which tells the admin nothing; 198.18.0.0/15 is the one to
	 * remember, since fake-IP DNS (common on developer machines) answers there for
	 * every name.
	 */
	const INTERNAL_RANGES = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
	);

	/**
	 * Ranges the wpmmcc_allow_private_peers option does not open: link-local,
	 * where cloud metadata services (169.254.169.254) answer.
	 */
	const NOT_OPENED_BY_OPTION = array( '169.254.0.0/16' );

	/**
	 * POST to a peer endpoint.
	 *
	 * @param string $url  Full peer URL.
	 * @param array  $args wp_remote_post() arguments.
	 * @return array|WP_Error Response, or wpmmcc_peer_url_blocked when the URL is refused (nothing was sent).
	 */
	public static function post( string $url, array $args = array() ) {
		$vetted = self::vet( $url );
		if ( is_wp_error( $vetted ) ) {
			return $vetted;
		}

		$args['redirection'] = 0;

		// WordPress validates the URL again and would refuse what was allowed
		// here on purpose. Both answers are scoped to this host and port, and a
		// public host gets no host override at all, so the second check stays an
		// independent one if its DNS answer changed.
		$host       = $vetted['host'];
		$same_host  = static function ( $filter_host ) use ( $host ): bool {
			return strtolower( trim( (string) $filter_host, '.' ) ) === $host;
		};
		$allow_host = static function ( $is_external, $filter_host = '' ) use ( $same_host ) {
			return $is_external || $same_host( $filter_host );
		};
		$allow_port = static function ( $ports, $filter_host = '' ) use ( $same_host, $vetted ) {
			if ( $vetted['port'] > 0 && is_array( $ports ) && $same_host( $filter_host ) ) {
				$ports[] = $vetted['port'];
			}
			return $ports;
		};

		if ( $vetted['internal'] ) {
			add_filter( 'http_request_host_is_external', $allow_host, 10, 2 );
		}
		add_filter( 'http_allowed_safe_ports', $allow_port, 10, 2 );
		try {
			return wp_safe_remote_post( $url, $args );
		} finally {
			remove_filter( 'http_request_host_is_external', $allow_host, 10 );
			remove_filter( 'http_allowed_safe_ports', $allow_port, 10 );
		}
	}

	/**
	 * Decide whether a peer URL may be contacted.
	 *
	 * @param string $url Peer URL.
	 * @return array{host:string,port:int,internal:bool}|WP_Error internal = it resolves to a private or local address that was opted in.
	 */
	public static function vet( string $url ) {
		$parts  = wp_parse_url( $url );
		$parts  = is_array( $parts ) ? $parts : array();
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '.' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| isset( $parts['query'] ) || isset( $parts['fragment'] ) || false !== strpbrk( $host, ':#?[]' ) ) {
			return self::refusal( 'it is not a plain http(s) URL with a host name or an IPv4 address' );
		}

		$addresses = self::resolve( $host );
		if ( empty( $addresses ) ) {
			return self::refusal( 'the configured host does not resolve' );
		}

		$internal = false;
		foreach ( $addresses as $address ) {
			if ( ! self::in_ranges( (string) $address, self::INTERNAL_RANGES ) ) {
				continue;
			}
			if ( ! self::internal_allowed( $host, $url, (string) $address ) ) {
				return self::refusal( self::internal_reason( (string) $address ) );
			}
			$internal = true;
		}

		return array(
			'host'     => $host,
			'port'     => isset( $parts['port'] ) ? (int) $parts['port'] : 0,
			'internal' => $internal,
		);
	}

	/**
	 * Every IPv4 address a host resolves to; an IPv4 literal resolves to itself.
	 *
	 * @param string $host Host name or address.
	 * @return string[]
	 */
	private static function resolve( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return array( $host );
		}
		$resolved = gethostbynamel( $host );
		return is_array( $resolved ) ? array_values( $resolved ) : array();
	}

	/**
	 * Is an IPv4 address inside any of the ranges? Anything that is not a valid
	 * IPv4 address counts as inside: the answer is used to refuse.
	 *
	 * @param string   $ip     Address.
	 * @param string[] $ranges CIDR ranges.
	 * @return bool
	 */
	private static function in_ranges( string $ip, array $ranges ): bool {
		$long = ip2long( $ip );
		if ( false === $long ) {
			return true;
		}
		foreach ( $ranges as $cidr ) {
			list( $network, $bits ) = explode( '/', $cidr );
			$mask                   = -1 << ( 32 - (int) $bits );
			if ( ( $long & $mask ) === ( ip2long( $network ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Has the operator allowed this peer address on a private network?
	 *
	 * @param string $host    Peer host.
	 * @param string $url     Peer URL.
	 * @param string $address The internal address the host resolves to.
	 * @return bool
	 */
	private static function internal_allowed( string $host, string $url, string $address ): bool {
		$by_option = (bool) get_option( 'wpmmcc_allow_private_peers', false ) && ! self::in_ranges( $address, self::NOT_OPENED_BY_OPTION );
		return (bool) apply_filters( 'wpmmcc_allow_private_peer_host', $by_option, $host, $url, $address );
	}

	/**
	 * Why a host that resolves to an internal address was refused, and what can
	 * change that. Enabling the option does nothing for link-local, so say so
	 * instead of sending the admin to a switch that cannot help.
	 *
	 * @param string $address The internal address the host resolves to.
	 * @return string
	 */
	private static function internal_reason( string $address ): string {
		if ( self::in_ranges( $address, self::NOT_OPENED_BY_OPTION ) ) {
			return 'the configured host resolves to a link-local address. The wpmmcc_allow_private_peers option never opens this range; only the wpmmcc_allow_private_peer_host filter can';
		}
		return 'the configured host resolves to a non-public address. Enable the wpmmcc_allow_private_peers option (or the wpmmcc_allow_private_peer_host filter) only if this is an intended private peer';
	}

	/**
	 * The refusal every blocked URL answers with.
	 *
	 * @param string $reason Static policy reason, never endpoint or DNS values.
	 * @return WP_Error
	 */
	private static function refusal( string $reason ): WP_Error {
		return new WP_Error( 'wpmmcc_peer_url_blocked', 'Peer endpoint was refused by URL or network policy: ' . $reason . '.' );
	}
}
