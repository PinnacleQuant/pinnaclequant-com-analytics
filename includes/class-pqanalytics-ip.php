<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_IP {

	public static function is_valid( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	public static function is_public( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	public static function normalize( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return '';
		}
		if ( preg_match( '/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $ip, $m ) ) {
			$ip = $m[1];
		} elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m ) ) {
			$ip = $m[1];
		}
		if ( 0 === stripos( $ip, '::ffff:' ) ) {
			$v4 = substr( $ip, 7 );
			if ( self::is_valid( $v4 ) ) {
				$ip = $v4;
			}
		}
		return self::is_valid( $ip ) ? $ip : '';
	}

	public static function last_public_from_list( $header ) {
		$last_valid = '';
		foreach ( array_reverse( explode( ',', (string) $header ) ) as $part ) {
			$candidate = self::normalize( $part );
			if ( '' === $candidate ) {
				continue;
			}
			if ( '' === $last_valid ) {
				$last_valid = $candidate;
			}
			if ( self::is_public( $candidate ) ) {
				return $candidate;
			}
		}
		return $last_valid;
	}

	public static function cloudflare_ranges() {
		return array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
	}

	public static function in_range( $ip, $cidr ) {
		$parts = explode( '/', (string) $cidr, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		$net  = @inet_pton( $parts[0] );
		$addr = @inet_pton( (string) $ip );
		$bits = (int) $parts[1];
		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( $bytes > 0 && substr( $net, 0, $bytes ) !== substr( $addr, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = 0xFF << ( 8 - $rest ) & 0xFF;
		return ( ord( $net[ $bytes ] ) & $mask ) === ( ord( $addr[ $bytes ] ) & $mask );
	}

	public static function from_cloudflare( $remote ) {
		if ( '' === $remote ) {
			return false;
		}
		foreach ( self::cloudflare_ranges() as $cidr ) {
			if ( self::in_range( $remote, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_local( $remote ) {
		return '' === $remote || ! self::is_public( $remote );
	}

	public static function resolve( $mode, $server ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? self::normalize( $server['REMOTE_ADDR'] ) : '';
		$cf     = isset( $server['HTTP_CF_CONNECTING_IP'] ) ? self::normalize( $server['HTTP_CF_CONNECTING_IP'] ) : '';
		$xreal  = isset( $server['HTTP_X_REAL_IP'] ) ? self::normalize( $server['HTTP_X_REAL_IP'] ) : '';
		$xff    = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? self::last_public_from_list( $server['HTTP_X_FORWARDED_FOR'] ) : '';

		switch ( $mode ) {
			case 'remote':
				return $remote;
			case 'cf':
				if ( '' !== $cf && ( self::from_cloudflare( $remote ) || self::is_local( $remote ) ) ) {
					return $cf;
				}
				return $remote;
			case 'xreal':
				return '' !== $xreal ? $xreal : $remote;
			case 'xff':
				return '' !== $xff ? $xff : $remote;
			case 'auto':
			default:
				if ( '' !== $cf && self::from_cloudflare( $remote ) ) {
					return $cf;
				}
				if ( self::is_local( $remote ) ) {
					if ( '' !== $xreal && self::is_public( $xreal ) ) {
						return $xreal;
					}
					if ( '' !== $xff && self::is_public( $xff ) ) {
						return $xff;
					}
				}
				return $remote;
		}
	}

	public static function current() {
		$mode = PQAnalytics_Settings::get( 'ip_source' );
		return self::resolve( $mode ? $mode : 'auto', $_SERVER );
	}
}
