<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Geo {

	const CHUNK_BYTES  = 10485760;
	const STATE_OPTION = 'pqanalytics_geo_dl';
	const INFO_OPTION  = 'pqanalytics_geo_info';

	private static $readers = array();

	public static function load_reader() {
		if ( class_exists( 'MaxMind\Db\Reader', false ) ) {
			return true;
		}
		$base = PQANALYTICS_DIR . 'vendor/maxmind-db/reader/src/';
		spl_autoload_register(
			function ( $class ) use ( $base ) {
				if ( 0 !== strpos( $class, 'MaxMind\\Db\\' ) ) {
					return;
				}
				$file = $base . str_replace( '\\', '/', $class ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
		return class_exists( 'MaxMind\Db\Reader' );
	}

	public static function dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'pqanalytics-geo/';
	}

	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . 'index.html' ) ) {
			file_put_contents( $dir . 'index.html', '' );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
		}
		return true;
	}

	public static function kinds() {
		return array(
			'city' => __( 'City database (city, state, country)', 'pinnaclequant-com-analytics' ),
			'asn'  => __( 'Network database (ISP / organization)', 'pinnaclequant-com-analytics' ),
		);
	}

	public static function path( $kind ) {
		return self::dir() . $kind . '.mmdb';
	}

	public static function has( $kind ) {
		return is_readable( self::path( $kind ) );
	}

	public static function source_url( $kind, $month_offset = 0 ) {
		$ts    = strtotime( '-' . (int) $month_offset . ' month', time() );
		$month = gmdate( 'Y-m', $ts );
		$file  = ( 'asn' === $kind ) ? 'dbip-asn-lite' : 'dbip-city-lite';
		$url   = 'https://download.db-ip.com/free/' . $file . '-' . $month . '.mmdb.gz';
		return (string) apply_filters( 'pqanalytics_geo_source_url', $url, $kind, $month_offset );
	}

	public static function chunk_bytes() {
		$bytes = (int) apply_filters( 'pqanalytics_geo_chunk_bytes', self::CHUNK_BYTES );
		return $bytes >= 4096 ? $bytes : self::CHUNK_BYTES;
	}

	private static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function info() {
		$info = get_option( self::INFO_OPTION, array() );
		return is_array( $info ) ? $info : array();
	}

	public static function status() {
		$state = self::state();
		$info  = self::info();
		$out   = array();
		foreach ( self::kinds() as $kind => $label ) {
			$path = self::path( $kind );
			$row  = array(
				'kind'      => $kind,
				'label'     => $label,
				'installed' => is_readable( $path ),
				'size'      => is_readable( $path ) ? (int) filesize( $path ) : 0,
				'updated'   => isset( $info[ $kind ]['updated'] ) ? (int) $info[ $kind ]['updated'] : 0,
				'build'     => isset( $info[ $kind ]['build'] ) ? (int) $info[ $kind ]['build'] : 0,
				'type'      => isset( $info[ $kind ]['type'] ) ? (string) $info[ $kind ]['type'] : '',
				'available' => true,
				'progress'  => null,
				'error'     => '',
			);
			if ( isset( $state[ $kind ] ) ) {
				$s               = $state[ $kind ];
				$row['progress'] = array(
					'received' => (int) $s['received'],
					'total'    => (int) $s['total'],
				);
				$row['error']    = isset( $s['error'] ) ? (string) $s['error'] : '';
			}
			$out[] = $row;
		}
		return $out;
	}

	public static function reset( $kind ) {
		$state = self::state();
		if ( isset( $state[ $kind ] ) ) {
			if ( ! empty( $state[ $kind ]['tmp'] ) && file_exists( $state[ $kind ]['tmp'] ) ) {
				wp_delete_file( $state[ $kind ]['tmp'] );
			}
			unset( $state[ $kind ] );
			self::save_state( $state );
		}
	}

	public static function pending() {
		$state = self::state();
		foreach ( array( 'city', 'asn' ) as $kind ) {
			if ( self::has( $kind ) ) {
				continue;
			}
			if ( isset( $state[ $kind ] ) && '' !== (string) $state[ $kind ]['error'] ) {
				continue;
			}
			return true;
		}
		return false;
	}

	public static function enabled() {
		return (bool) get_option( 'pqanalytics_geo_enabled', 0 );
	}

	public static function enable() {
		if ( ! self::enabled() ) {
			update_option( 'pqanalytics_geo_enabled', 1, false );
		}
		PQAnalytics_Cron::schedule_refresh();
	}

	public static function in_progress( $kind ) {
		$state = self::state();
		return isset( $state[ $kind ] );
	}

	public static function locked( $kind ) {
		return (bool) get_transient( 'pqanalytics_geo_lock_' . $kind );
	}

	public static function step( $kind ) {
		if ( ! array_key_exists( $kind, self::kinds() ) ) {
			return self::result( false, 0, 0, __( 'Unknown database.', 'pinnaclequant-com-analytics' ) );
		}
		if ( self::locked( $kind ) ) {
			$state = self::state();
			$out   = self::result( false, isset( $state[ $kind ] ) ? $state[ $kind ]['received'] : 0, isset( $state[ $kind ] ) ? $state[ $kind ]['total'] : 0, '' );
			$out['busy'] = true;
			return $out;
		}
		set_transient( 'pqanalytics_geo_lock_' . $kind, 1, 150 );
		$out = self::run_step( $kind );
		delete_transient( 'pqanalytics_geo_lock_' . $kind );
		return $out;
	}

	private static function run_step( $kind ) {
		if ( ! self::ensure_dir() ) {
			return self::result( false, 0, 0, __( 'The uploads folder is not writable.', 'pinnaclequant-com-analytics' ) );
		}
		if ( ! function_exists( 'gzopen' ) ) {
			return self::result( false, 0, 0, __( 'PHP zlib support is missing on this server.', 'pinnaclequant-com-analytics' ) );
		}

		$state = self::state();
		if ( ! isset( $state[ $kind ] ) ) {
			$url = self::pick_url( $kind );
			if ( '' === $url ) {
				return self::result( false, 0, 0, __( 'The download source did not answer. Try again in a few minutes.', 'pinnaclequant-com-analytics' ) );
			}
			$state[ $kind ] = array(
				'tmp'      => self::dir() . $kind . '.download',
				'received' => 0,
				'total'    => 0,
				'started'  => time(),
				'error'    => '',
			);
			if ( file_exists( $state[ $kind ]['tmp'] ) ) {
				wp_delete_file( $state[ $kind ]['tmp'] );
			}
			self::save_state( $state );
		}

		$s   = $state[ $kind ];
		$url = self::pick_url( $kind );
		if ( '' === $url ) {
			return self::result( false, $s['received'], $s['total'], __( 'The download source did not answer.', 'pinnaclequant-com-analytics' ) );
		}

		$from = (int) $s['received'];
		$to   = $from + self::chunk_bytes() - 1;
		$seg  = $s['tmp'] . '.part';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $seg,
				'headers'  => array( 'Range' => 'bytes=' . $from . '-' . $to ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( $kind, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$len  = file_exists( $seg ) ? (int) filesize( $seg ) : 0;

		if ( 206 === $code ) {
			$range = (string) wp_remote_retrieve_header( $response, 'content-range' );
			if ( preg_match( '/bytes\s+(\d+)-(\d+)\/(\d+|\*)/', $range, $m ) ) {
				if ( (int) $m[1] !== $from ) {
					wp_delete_file( $seg );
					return self::fail( $kind, __( 'The server returned an unexpected byte range.', 'pinnaclequant-com-analytics' ) );
				}
				if ( '*' !== $m[3] ) {
					$s['total'] = (int) $m[3];
				}
			}
			if ( $len <= 0 ) {
				wp_delete_file( $seg );
				return self::fail( $kind, __( 'Empty chunk received.', 'pinnaclequant-com-analytics' ) );
			}
			if ( ! self::append_file( $seg, $s['tmp'] ) ) {
				wp_delete_file( $seg );
				return self::fail( $kind, __( 'Could not write to the uploads folder.', 'pinnaclequant-com-analytics' ) );
			}
			wp_delete_file( $seg );
			$s['received'] = $from + $len;
		} elseif ( 200 === $code ) {
			if ( file_exists( $s['tmp'] ) ) {
				wp_delete_file( $s['tmp'] );
			}
			if ( $len <= 0 || ! PQAnalytics_DB::fs()->move( $seg, $s['tmp'], true ) ) {
				wp_delete_file( $seg );
				return self::fail( $kind, __( 'Could not write to the uploads folder.', 'pinnaclequant-com-analytics' ) );
			}
			$s['received'] = $len;
			$s['total']    = $len;
		} elseif ( 416 === $code ) {
			wp_delete_file( $seg );
			if ( ! ( $s['total'] > 0 && $s['received'] >= $s['total'] ) ) {
				return self::fail( $kind, __( 'The server rejected the requested byte range.', 'pinnaclequant-com-analytics' ) );
			}
		} else {
			wp_delete_file( $seg );
			return self::fail( $kind, __( 'Download failed with HTTP status', 'pinnaclequant-com-analytics' ) . ' ' . (int) $code . '.' );
		}

		$s['error']     = '';
		$state[ $kind ] = $s;
		self::save_state( $state );

		$complete = ( $s['total'] > 0 && $s['received'] >= $s['total'] );
		if ( ! $complete ) {
			return self::result( false, $s['received'], $s['total'], '' );
		}

		$err   = self::finalize( $kind, $s['tmp'] );
		$state = self::state();
		unset( $state[ $kind ] );
		self::save_state( $state );
		if ( file_exists( $s['tmp'] ) ) {
			wp_delete_file( $s['tmp'] );
		}
		if ( '' !== $err ) {
			return self::result( false, $s['received'], $s['total'], $err );
		}
		unset( self::$readers[ $kind ] );
		return self::result( true, $s['received'], $s['total'], '' );
	}

	private static function pick_url( $kind ) {
		$cached = get_transient( 'pqanalytics_geo_url_' . $kind );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$fallback = '';
		foreach ( array( 0, 1, 2 ) as $offset ) {
			$url  = self::source_url( $kind, $offset );
			$head = wp_remote_head( $url, array( 'timeout' => 20 ) );
			if ( is_wp_error( $head ) ) {
				if ( '' === $fallback ) {
					$fallback = $url;
				}
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $head );
			if ( 200 === $code ) {
				set_transient( 'pqanalytics_geo_url_' . $kind, $url, DAY_IN_SECONDS );
				return $url;
			}
			if ( 404 === $code || 403 === $code ) {
				continue;
			}
			return $url;
		}
		return $fallback;
	}

	private static function fail( $kind, $message ) {
		$state = self::state();
		$rec   = 0;
		$tot   = 0;
		if ( isset( $state[ $kind ] ) ) {
			$state[ $kind ]['error'] = $message;
			$rec                     = (int) $state[ $kind ]['received'];
			$tot                     = (int) $state[ $kind ]['total'];
			self::save_state( $state );
		}
		return self::result( false, $rec, $tot, $message );
	}

	private static function result( $done, $received, $total, $error ) {
		return array(
			'done'     => (bool) $done,
			'received' => (int) $received,
			'total'    => (int) $total,
			'error'    => (string) $error,
			'busy'     => false,
		);
	}

	private static function append_file( $src, $dest ) {
		$size = (int) filesize( $src );
		if ( $size <= 0 ) {
			return false;
		}
		$offset = 0;
		while ( $offset < $size ) {
			$buf = file_get_contents( $src, false, null, $offset, 1048576 );
			if ( false === $buf || '' === $buf ) {
				return false;
			}
			if ( false === file_put_contents( $dest, $buf, FILE_APPEND ) ) {
				return false;
			}
			$offset += strlen( $buf );
		}
		return true;
	}

	public static function finalize( $kind, $tmp ) {
		$target  = self::path( $kind );
		$staging = $target . '.new';
		if ( file_exists( $staging ) ) {
			wp_delete_file( $staging );
		}

		$ok = self::gunzip( $tmp, $staging );
		if ( ! $ok || ! file_exists( $staging ) || filesize( $staging ) < 1024 ) {
			if ( file_exists( $staging ) ) {
				wp_delete_file( $staging );
			}
			return __( 'The downloaded file could not be extracted.', 'pinnaclequant-com-analytics' );
		}

		$meta = self::read_metadata( $staging );
		if ( null === $meta ) {
			wp_delete_file( $staging );
			return __( 'The downloaded file is not a valid database.', 'pinnaclequant-com-analytics' );
		}

		if ( file_exists( $target ) ) {
			wp_delete_file( $target );
		}
		if ( ! PQAnalytics_DB::fs()->move( $staging, $target, true ) ) {
			wp_delete_file( $staging );
			return __( 'Could not move the database into place.', 'pinnaclequant-com-analytics' );
		}

		$info          = self::info();
		$info[ $kind ] = array(
			'updated' => time(),
			'build'   => $meta['build'],
			'type'    => $meta['type'],
		);
		update_option( self::INFO_OPTION, $info, false );
		return '';
	}

	public static function gunzip( $src, $dest ) {
		$gz = gzopen( $src, 'rb' );
		if ( ! $gz ) {
			return false;
		}
		if ( false === file_put_contents( $dest, '' ) ) {
			gzclose( $gz );
			return false;
		}
		$ok = true;
		while ( ! gzeof( $gz ) ) {
			$buf = gzread( $gz, 1048576 );
			if ( false === $buf ) {
				$ok = false;
				break;
			}
			if ( '' !== $buf && false === file_put_contents( $dest, $buf, FILE_APPEND ) ) {
				$ok = false;
				break;
			}
		}
		gzclose( $gz );
		return $ok;
	}

	public static function read_metadata( $path ) {
		if ( ! self::load_reader() ) {
			return null;
		}
		try {
			$reader = new \MaxMind\Db\Reader( $path );
			$meta   = $reader->metadata();
			$out    = array(
				'type'  => (string) $meta->databaseType,
				'build' => (int) $meta->buildEpoch,
			);
			$reader->close();
			return $out;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private static function reader( $kind ) {
		if ( array_key_exists( $kind, self::$readers ) ) {
			return self::$readers[ $kind ];
		}
		self::$readers[ $kind ] = null;
		if ( ! self::has( $kind ) || ! self::load_reader() ) {
			return null;
		}
		try {
			self::$readers[ $kind ] = new \MaxMind\Db\Reader( self::path( $kind ) );
		} catch ( \Exception $e ) {
			self::$readers[ $kind ] = null;
		}
		return self::$readers[ $kind ];
	}

	private static function entry( $kind, $ip ) {
		$reader = self::reader( $kind );
		if ( ! $reader ) {
			return null;
		}
		try {
			$rec = $reader->get( $ip );
			return is_array( $rec ) ? $rec : null;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function lookup( $ip ) {
		$out = array(
			'country_code' => '',
			'country'      => '',
			'region'       => '',
			'city'         => '',
			'postal'       => '',
			'isp'          => '',
			'asn'          => 0,
		);
		if ( '' === $ip || ! PQAnalytics_IP::is_public( $ip ) ) {
			return $out;
		}

		$rec = self::entry( 'city', $ip );
		if ( is_array( $rec ) ) {
			$out = array_merge( $out, self::map_city( $rec ) );
		}

		$asn = self::entry( 'asn', $ip );
		if ( is_array( $asn ) ) {
			if ( isset( $asn['autonomous_system_organization'] ) ) {
				$out['isp'] = PQAnalytics_DB::cut( (string) $asn['autonomous_system_organization'], 150 );
			}
			if ( isset( $asn['autonomous_system_number'] ) ) {
				$out['asn'] = (int) $asn['autonomous_system_number'];
			}
		}
		return $out;
	}

	public static function map_city( $rec ) {
		$out = array(
			'country_code' => '',
			'country'      => '',
			'region'       => '',
			'city'         => '',
			'postal'       => '',
		);
		if ( isset( $rec['country']['iso_code'] ) ) {
			$out['country_code'] = PQAnalytics_DB::cut( (string) $rec['country']['iso_code'], 2 );
		}
		if ( isset( $rec['country']['names']['en'] ) ) {
			$out['country'] = PQAnalytics_DB::cut( (string) $rec['country']['names']['en'], 80 );
		}
		if ( isset( $rec['subdivisions'][0]['names']['en'] ) ) {
			$out['region'] = PQAnalytics_DB::cut( (string) $rec['subdivisions'][0]['names']['en'], 100 );
		} elseif ( isset( $rec['subdivisions'][0]['iso_code'] ) ) {
			$out['region'] = PQAnalytics_DB::cut( (string) $rec['subdivisions'][0]['iso_code'], 100 );
		}
		if ( isset( $rec['city']['names']['en'] ) ) {
			$out['city'] = PQAnalytics_DB::cut( (string) $rec['city']['names']['en'], 100 );
		}
		if ( isset( $rec['postal']['code'] ) ) {
			$out['postal'] = PQAnalytics_DB::cut( (string) $rec['postal']['code'], 20 );
		}
		return $out;
	}
}
