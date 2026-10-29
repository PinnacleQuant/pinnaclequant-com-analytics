<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Visits {

	const MAX_NEW_VISITS_PER_MINUTE = 120;
	const MAX_PAGES_PER_MINUTE      = 60;
	const MAX_PAGES_PER_VISIT       = 500;
	const MIN_BEAT_SECONDS          = 3;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'log_bot_request' ), 5 );
		add_action( 'wp_ajax_nopriv_pqanalytics_hit', array( __CLASS__, 'ajax_hit' ) );
		add_action( 'wp_ajax_pqanalytics_hit', array( __CLASS__, 'ajax_hit' ) );
		add_action( 'wp_ajax_nopriv_pqanalytics_beat', array( __CLASS__, 'ajax_beat' ) );
		add_action( 'wp_ajax_pqanalytics_beat', array( __CLASS__, 'ajax_beat' ) );
	}

	public static function should_track_request() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( PQAnalytics_Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}
		return true;
	}

	public static function enqueue() {
		if ( ! self::should_track_request() ) {
			return;
		}
		wp_register_script( 'pqanalytics-visitors', PQANALYTICS_URL . 'assets/visitors.js', array(), PQANALYTICS_VERSION, true );
		$cfg = array(
			'hit'      => rest_url( 'pqanalytics/v1/hit' ),
			'beat'     => rest_url( 'pqanalytics/v1/beat' ),
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'interval' => max( 10, min( 120, (int) PQAnalytics_Settings::get( 'beat_seconds' ) ) ) * 1000,
			'session'  => PQANALYTICS_SESSION_MINUTES * 60 * 1000,
			'm'        => self::member_token(),
		);
		wp_add_inline_script( 'pqanalytics-visitors', 'window.PQANALYTICS_CFG = ' . wp_json_encode( $cfg ) . ';', 'before' );
		wp_enqueue_script( 'pqanalytics-visitors' );
	}

	public static function member_token() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$id = (int) get_current_user_id();
		if ( $id <= 0 ) {
			return '';
		}
		return $id . '.' . hash_hmac( 'sha256', 'pqanalytics-member|' . $id, wp_salt( 'auth' ) );
	}

	public static function member_from_token( $token ) {
		$token = (string) $token;
		if ( '' === $token || strlen( $token ) > 90 || ! preg_match( '/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $m ) ) {
			return '';
		}
		$id = (int) $m[1];
		if ( $id <= 0 || ! hash_equals( hash_hmac( 'sha256', 'pqanalytics-member|' . $id, wp_salt( 'auth' ) ), $m[2] ) ) {
			return '';
		}
		$user = get_userdata( $id );
		if ( ! $user ) {
			return '';
		}
		$name = trim( trim( (string) $user->first_name ) . ' ' . trim( (string) $user->last_name ) );
		if ( '' === $name ) {
			$name = sprintf( 'Member #%d', $id );
		}
		return PQAnalytics_DB::cut( sanitize_text_field( $name ), 120 );
	}

	public static function routes() {
		register_rest_route(
			'pqanalytics/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_hit' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'pqanalytics/v1',
			'/beat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_beat' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function body( $request ) {
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			$data = $request->get_json_params();
		}
		return is_array( $data ) ? $data : array();
	}

	public static function finish( $out, $data, $kind ) {
		$x     = ( isset( $data['x'] ) && is_array( $data['x'] ) ) ? $data['x'] : array();
		$extra = apply_filters( 'pqanalytics_beacon_extra', array(), $x, $kind );
		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$out['x'] = $extra;
		}
		return $out;
	}

	public static function rest_hit( $request ) {
		$response = new WP_REST_Response( self::save_hit( self::body( $request ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function rest_beat( $request ) {
		$response = new WP_REST_Response( self::save_beat( self::body( $request ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function ajax_payload() {
		$data = json_decode( (string) file_get_contents( 'php://input' ), true );
		return is_array( $data ) ? $data : array();
	}

	public static function ajax_hit() {
		nocache_headers();
		wp_send_json( self::save_hit( self::ajax_payload() ) );
	}

	public static function ajax_beat() {
		nocache_headers();
		wp_send_json( self::save_beat( self::ajax_payload() ) );
	}

	public static function ua() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return PQAnalytics_DB::cut( $ua, 500 );
	}

	public static function allow( $ip ) {
		return '' !== (string) $ip;
	}

	public static function visit_allowed( $ip ) {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$since  = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		$count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ip = %s AND started >= %s', $visits, $ip, $since ) );
		return $count < self::MAX_NEW_VISITS_PER_MINUTE;
	}

	public static function page_allowed( $visit ) {
		$age = max( 0, time() - (int) strtotime( $visit->started . ' UTC' ) );
		$cap = min( self::MAX_PAGES_PER_VISIT, self::MAX_PAGES_PER_MINUTE * ( 1 + intdiv( $age, MINUTE_IN_SECONDS ) ) );
		return (int) $visit->pages < $cap;
	}

	public static function ignored( $ip ) {
		if ( '' === $ip ) {
			return true;
		}
		if ( in_array( $ip, PQAnalytics_Settings::own_ips(), true ) ) {
			return true;
		}
		if ( PQAnalytics_Blocker::is_blocked( $ip ) ) {
			return true;
		}
		return false;
	}

	public static function valid_vid( $vid ) {
		return is_string( $vid ) && preg_match( '/^[a-f0-9]{32}$/', $vid );
	}

	public static function screen_class( $sw, $sh, $dpr, $device ) {
		if ( 'phone' === $device ) {
			return 'Phone';
		}
		if ( 'tablet' === $device ) {
			return 'Tablet';
		}
		if ( $sw <= 0 ) {
			return 'Unknown';
		}
		$pw = (int) round( $sw * $dpr );
		$ph = (int) round( $sh * $dpr );
		if ( $sw < 600 ) {
			return 'Phone';
		}
		if ( $sw < 1024 ) {
			return 'Tablet';
		}
		if ( $pw >= 3840 || $ph >= 3840 ) {
			return '4K and up';
		}
		if ( $pw >= 2560 ) {
			return '2K to 3K';
		}
		if ( $pw >= 1920 ) {
			return '1080p';
		}
		return 'Under 1080p';
	}

	public static function save_hit( $data ) {
		return self::finish( self::record_hit( $data ), $data, 'hit' );
	}

	public static function save_beat( $data ) {
		return self::finish( self::record_beat( $data ), $data, 'beat' );
	}

	public static function record_hit( $data ) {
		global $wpdb;

		$ip = PQAnalytics_IP::current();
		if ( self::ignored( $ip ) ) {
			return array( 'skip' => 1 );
		}

		$ua     = self::ua();
		$parsed = PQAnalytics_UA::parse( $ua );
		if ( $parsed['is_bot'] ) {
			return array( 'skip' => 1 );
		}

		$vid = ( isset( $data['vid'] ) && self::valid_vid( $data['vid'] ) ) ? $data['vid'] : md5( $ip . '|' . $ua . '|' . gmdate( 'Y-m-d' ) );

		$url   = isset( $data['url'] ) ? esc_url_raw( PQAnalytics_DB::cut( (string) $data['url'], 1000 ) ) : '';
		$ref   = isset( $data['ref'] ) ? esc_url_raw( PQAnalytics_DB::cut( (string) $data['ref'], 1000 ) ) : '';
		$title = isset( $data['t'] ) ? PQAnalytics_DB::cut( sanitize_text_field( (string) $data['t'] ), 255 ) : '';
		$sw    = isset( $data['sw'] ) ? min( 16000, max( 0, (int) $data['sw'] ) ) : 0;
		$sh    = isset( $data['sh'] ) ? min( 16000, max( 0, (int) $data['sh'] ) ) : 0;
		$vw    = isset( $data['vw'] ) ? min( 16000, max( 0, (int) $data['vw'] ) ) : 0;
		$vh    = isset( $data['vh'] ) ? min( 16000, max( 0, (int) $data['vh'] ) ) : 0;
		$dpr   = isset( $data['dpr'] ) ? (float) $data['dpr'] : 1;
		if ( $dpr < 0.5 || $dpr > 8 ) {
			$dpr = 1;
		}
		$dpr  = round( $dpr, 4 );
		$lang = isset( $data['lang'] ) ? PQAnalytics_DB::cut( preg_replace( '/[^A-Za-z\-]/', '', (string) $data['lang'] ), 16 ) : '';
		$member = isset( $data['m'] ) ? self::member_from_token( $data['m'] ) : '';

		$now      = PQAnalytics_DB::now();
		$visitors = PQAnalytics_DB::table( 'visitors' );
		$visits   = PQAnalytics_DB::table( 'visits' );
		$hits     = PQAnalytics_DB::table( 'hits' );

		$visitor     = $wpdb->get_row( $wpdb->prepare( 'SELECT id, visits FROM %i WHERE vid = %s', $visitors, $vid ) );
		$new_visitor = false;
		$visit       = null;

		if ( $visitor ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - PQANALYTICS_SESSION_MINUTES * 60 );
			$visit  = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, started, pages FROM %i WHERE visitor_id = %d AND last_beat >= %s ORDER BY id DESC LIMIT 1',
					$visits,
					$visitor->id,
					$cutoff
				)
			);
		}
		if ( ! $visit && ! self::visit_allowed( $ip ) ) {
			return array( 'skip' => 1 );
		}
		if ( ! $visitor ) {
			$wpdb->insert(
				$visitors,
				array(
					'vid'        => $vid,
					'first_seen' => $now,
					'last_seen'  => $now,
					'visits'     => 1,
				),
				array( '%s', '%s', '%s', '%d' )
			);
			$visitor     = (object) array(
				'id'     => (int) $wpdb->insert_id,
				'visits' => 1,
			);
			$new_visitor = true;
			if ( ! $visitor->id ) {
				return array( 'skip' => 1 );
			}
		}

		$path      = PQAnalytics_DB::path_of( $url );
		$prev_path = '';
		if ( $visit ) {
			$visit_id  = (int) $visit->id;
			$last      = $wpdb->get_row( $wpdb->prepare( 'SELECT id, path, hit_time, duration FROM %i WHERE visit_id = %d ORDER BY id DESC LIMIT 1', $hits, $visit_id ) );
			$prev_path = $last ? (string) $last->path : '';
			$same_page = $last && '' !== $path && $path === (string) $last->path && strtotime( $last->hit_time . ' UTC' ) + (int) $last->duration >= time() - PQANALYTICS_SAME_PAGE_SECONDS;
			if ( $same_page || ! self::page_allowed( $visit ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET last_beat = %s, last_url = %s, last_title = %s WHERE id = %d',
						$visits,
						$now,
						$url,
						$title,
						$visit_id
					)
				);
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_seen = %s WHERE id = %d', $visitors, $now, $visitor->id ) );
				if ( '' !== $member ) {
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET member = %s WHERE id = %d AND member = ''", $visits, $member, $visit_id ) );
				}
				return array(
					'v' => $visit_id,
					'h' => $last ? (int) $last->id : 0,
				);
			}
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET pages = pages + 1, last_beat = %s, last_url = %s, last_title = %s WHERE id = %d',
					$visits,
					$now,
					$url,
					$title,
					$visit_id
				)
			);
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_seen = %s WHERE id = %d', $visitors, $now, $visitor->id ) );
			if ( '' !== $member ) {
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET member = %s WHERE id = %d AND member = ''", $visits, $member, $visit_id ) );
			}
		} else {
			$visit_no = $new_visitor ? 1 : ( (int) $visitor->visits + 1 );
			$wpdb->update(
				$visitors,
				array(
					'visits'    => $visit_no,
					'last_seen' => $now,
				),
				array( 'id' => $visitor->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);

			$cls = PQAnalytics_Referrer::classify( $ref, $url, PQAnalytics_Referrer::host( home_url() ) );
			$geo = PQAnalytics_Geo::lookup( $ip );

			$row = array(
				'visitor_id'    => (int) $visitor->id,
				'visit_no'      => $visit_no,
				'member'        => $member,
				'ip'            => $ip,
				'started'       => $now,
				'last_beat'     => $now,
				'pages'         => 1,
				'entry_url'     => $url,
				'last_url'      => $url,
				'last_title'    => $title,
				'referrer'      => $cls['referrer'],
				'source'        => $cls['source'],
				'source_type'   => $cls['source_type'],
				'click_id_type' => $cls['click_id_type'],
				'keyword'       => $cls['keyword'],
				'utm_source'    => $cls['utm_source'],
				'utm_medium'    => $cls['utm_medium'],
				'utm_campaign'  => $cls['utm_campaign'],
				'country_code'  => $geo['country_code'],
				'country'       => $geo['country'],
				'region'        => $geo['region'],
				'city'          => $geo['city'],
				'postal'        => $geo['postal'],
				'isp'           => $geo['isp'],
				'asn'           => (int) $geo['asn'],
				'screen_w'      => $sw,
				'screen_h'      => $sh,
				'dpr'           => $dpr,
				'viewport_w'    => $vw,
				'viewport_h'    => $vh,
				'screen_class'  => self::screen_class( $sw, $sh, $dpr, $parsed['device'] ),
				'browser'       => $parsed['browser'],
				'browser_ver'   => PQAnalytics_DB::cut( $parsed['browser_ver'], 20 ),
				'os'            => $parsed['os'],
				'language'      => $lang,
				'ua'            => $ua,
				'is_bot'        => 0,
				'bot_name'      => '',
			);
			$wpdb->insert( $visits, $row );
			$visit_id = (int) $wpdb->insert_id;
			if ( ! $visit_id ) {
				return array( 'skip' => 1 );
			}
		}

		$wpdb->insert(
			$hits,
			array(
				'visit_id'  => $visit_id,
				'url'       => $url,
				'path'      => $path,
				'prev_path' => $prev_path,
				'title'     => $title,
				'hit_time'  => $now,
				'duration'  => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return array(
			'v' => $visit_id,
			'h' => (int) $wpdb->insert_id,
		);
	}

	public static function record_beat( $data ) {
		global $wpdb;

		$v   = isset( $data['v'] ) ? (int) $data['v'] : 0;
		$h   = isset( $data['h'] ) ? (int) $data['h'] : 0;
		$vid = ( isset( $data['vid'] ) && self::valid_vid( $data['vid'] ) ) ? $data['vid'] : '';
		if ( $v <= 0 || $h <= 0 || '' === $vid ) {
			return array( 'ok' => 0 );
		}

		$ip = PQAnalytics_IP::current();
		if ( self::ignored( $ip ) ) {
			return array( 'ok' => 0 );
		}

		$visitors = PQAnalytics_DB::table( 'visitors' );
		$visits   = PQAnalytics_DB::table( 'visits' );
		$hits     = PQAnalytics_DB::table( 'hits' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT vs.id, vs.visitor_id, vs.last_beat FROM %i vs INNER JOIN %i vr ON vr.id = vs.visitor_id WHERE vs.id = %d AND vr.vid = %s',
				$visits,
				$visitors,
				$v,
				$vid
			)
		);
		if ( ! $row ) {
			return array( 'ok' => 0 );
		}
		$last_beat = (int) strtotime( $row->last_beat . ' UTC' );
		if ( $last_beat < time() - PQANALYTICS_SESSION_MINUTES * 60 ) {
			return array( 'ok' => 0, 'expired' => 1 );
		}
		if ( $last_beat > time() - self::MIN_BEAT_SECONDS ) {
			return array( 'ok' => 1 );
		}

		$now = PQAnalytics_DB::now();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET duration = LEAST(7200, GREATEST(duration, UNIX_TIMESTAMP(%s) - UNIX_TIMESTAMP(hit_time))) WHERE id = %d AND visit_id = %d',
				$hits,
				$now,
				$h,
				$v
			)
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_beat = %s WHERE id = %d', $visits, $now, $v ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_seen = %s WHERE id = %d', $visitors, $now, (int) $row->visitor_id ) );

		return array( 'ok' => 1 );
	}

	public static function log_bot_request() {
		global $wpdb;

		if ( ! self::should_track_request() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		$ua     = self::ua();
		$parsed = PQAnalytics_UA::parse( $ua );
		if ( ! $parsed['is_bot'] ) {
			return;
		}
		$ip = PQAnalytics_IP::current();
		if ( self::ignored( $ip ) ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url  = esc_url_raw( PQAnalytics_DB::cut( home_url( $uri ), 1000 ) );
		$now  = PQAnalytics_DB::now();
		$name = '' !== $parsed['bot_name'] ? $parsed['bot_name'] : 'Bot';

		$visits = PQAnalytics_DB::table( 'visits' );
		$hits   = PQAnalytics_DB::table( 'hits' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - PQANALYTICS_SESSION_MINUTES * 60 );
		$visit  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, started, pages FROM %i WHERE is_bot = 1 AND ip = %s AND ua = %s AND last_beat >= %s ORDER BY id DESC LIMIT 1',
				$visits,
				$ip,
				$ua,
				$cutoff
			)
		);
		if ( ! $visit && ! self::visit_allowed( $ip ) ) {
			return;
		}

		$path      = PQAnalytics_DB::path_of( $url );
		$prev_path = '';
		if ( $visit ) {
			$visit_id = (int) $visit->id;
			if ( ! self::page_allowed( $visit ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_beat = %s, last_url = %s WHERE id = %d', $visits, $now, $url, $visit_id ) );
				return;
			}
			$prev_path = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT path FROM %i WHERE visit_id = %d ORDER BY id DESC LIMIT 1', $hits, $visit_id ) );
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET pages = pages + 1, last_beat = %s, last_url = %s WHERE id = %d',
					$visits,
					$now,
					$url,
					$visit_id
				)
			);
		} else {
			$geo = PQAnalytics_Geo::lookup( $ip );
			$wpdb->insert(
				$visits,
				array(
					'visitor_id'   => 0,
					'visit_no'     => 1,
					'ip'           => $ip,
					'started'      => $now,
					'last_beat'    => $now,
					'pages'        => 1,
					'entry_url'    => $url,
					'last_url'     => $url,
					'source'       => $name,
					'source_type'  => 'bot',
					'country_code' => $geo['country_code'],
					'country'      => $geo['country'],
					'region'       => $geo['region'],
					'city'         => $geo['city'],
					'postal'       => $geo['postal'],
					'isp'          => $geo['isp'],
					'asn'          => (int) $geo['asn'],
					'screen_class' => 'Bot',
					'browser'      => $name,
					'os'           => $parsed['os'],
					'ua'           => $ua,
					'is_bot'       => 1,
					'bot_name'     => PQAnalytics_DB::cut( $name, 60 ),
				)
			);
			$visit_id = (int) $wpdb->insert_id;
			if ( ! $visit_id ) {
				return;
			}
		}

		$wpdb->insert(
			$hits,
			array(
				'visit_id' => $visit_id,
				'url'      => $url,
				'title'    => '',
				'hit_time' => $now,
				'duration' => 0,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);
	}
}
