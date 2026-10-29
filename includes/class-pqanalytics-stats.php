<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Stats {

	const PER_PAGE = 50;

	public static function tz() {
		$setting = (string) PQAnalytics_Settings::get( 'timezone' );
		if ( '' !== $setting ) {
			if ( preg_match( '/^UTC([+-])(\d{1,2})(?:\.(\d+))?$/', $setting, $m ) ) {
				$minutes = (int) round( (float) ( '0.' . ( isset( $m[3] ) ? $m[3] : '0' ) ) * 60 );
				try {
					return new DateTimeZone( sprintf( '%s%02d:%02d', $m[1], (int) $m[2], $minutes ) );
				} catch ( \Exception $e ) {
					return wp_timezone();
				}
			}
			try {
				return new DateTimeZone( $setting );
			} catch ( \Exception $e ) {
				return wp_timezone();
			}
		}
		return wp_timezone();
	}

	public static function tz_label() {
		$setting = (string) PQAnalytics_Settings::get( 'timezone' );
		if ( '' !== $setting ) {
			return $setting;
		}
		$label = wp_timezone_string();
		return preg_match( '/^[+-]/', $label ) ? 'UTC' . $label : $label;
	}

	public static function offset_seconds() {
		$tz = self::tz();
		return (int) $tz->getOffset( new DateTime( 'now', $tz ) );
	}

	public static function day_start_utc( $days_ago = 0 ) {
		$tz = self::tz();
		$d  = new DateTime( 'now', $tz );
		$d->setTime( 0, 0, 0 );
		if ( $days_ago > 0 ) {
			$d->modify( '-' . (int) $days_ago . ' days' );
		}
		$d->setTimezone( new DateTimeZone( 'UTC' ) );
		return $d->format( 'Y-m-d H:i:s' );
	}

	public static function local( $utc, $format = 'M j, g:i:s A' ) {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '';
		}
		try {
			$d = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$d->setTimezone( self::tz() );
			return $d->format( $format );
		} catch ( \Exception $e ) {
			return $utc;
		}
	}

	private static function humans_between( $from, $to ) {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT visitor_id) AS u, COALESCE(SUM(pages), 0) AS p, COUNT(*) AS n, COALESCE(SUM(pages = 1 AND (UNIX_TIMESTAMP(last_beat) - UNIX_TIMESTAMP(started)) < 10), 0) AS s FROM %i WHERE is_bot = 0 AND started >= %s AND started < %s',
				$visits,
				$from,
				$to
			),
			ARRAY_A
		);
		$n = $row ? (int) $row['n'] : 0;
		return array(
			'u' => $row ? (int) $row['u'] : 0,
			'p' => $row ? (int) $row['p'] : 0,
			'b' => $n ? (int) round( 100 * (int) $row['s'] / $n ) : 0,
		);
	}

	private static function bots_between( $from, $to ) {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS u, COALESCE(SUM(pages), 0) AS p FROM %i WHERE is_bot = 1 AND started >= %s AND started < %s',
				$visits,
				$from,
				$to
			),
			ARRAY_A
		);
		return array(
			'u' => $row ? (int) $row['u'] : 0,
			'p' => $row ? (int) $row['p'] : 0,
		);
	}

	public static function live_count() {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - PQANALYTICS_LIVE_SECONDS );
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(DISTINCT visitor_id) FROM %i WHERE is_bot = 0 AND last_beat >= %s', $visits, $cutoff )
		);
	}

	public static function cards() {
		$today     = self::day_start_utc( 0 );
		$yesterday = self::day_start_utc( 1 );
		$d7        = self::day_start_utc( 6 );
		$d30       = self::day_start_utc( 29 );
		$far       = '2100-01-01 00:00:00';
		return array(
			'live'      => self::live_count(),
			'today'     => self::humans_between( $today, $far ),
			'yesterday' => self::humans_between( $yesterday, $today ),
			'd7'        => self::humans_between( $d7, $far ),
			'd30'       => self::humans_between( $d30, $far ),
			'bots'      => self::bots_between( $today, $far ),
			'bots_d30'  => self::bots_between( $d30, $far ),
		);
	}

	public static function hourly() {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$shift  = self::offset_seconds() + DAY_IN_SECONDS;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT SUBSTR(DATE_ADD(started, INTERVAL %d SECOND), 12, 2) AS h, COUNT(DISTINCT visitor_id) AS u, COALESCE(SUM(pages), 0) AS p FROM %i WHERE is_bot = 0 AND started >= %s GROUP BY h',
				$shift,
				$visits,
				self::day_start_utc( 0 )
			),
			ARRAY_A
		);
		$out = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$out[ $h ] = array(
				'h' => $h,
				'u' => 0,
				'p' => 0,
			);
		}
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$h = (int) $row['h'];
				if ( isset( $out[ $h ] ) ) {
					$out[ $h ]['u'] = (int) $row['u'];
					$out[ $h ]['p'] = (int) $row['p'];
				}
			}
		}
		$current_hour = (int) ( new DateTime( 'now', self::tz() ) )->format( 'G' );
		return array(
			'rows'         => array_values( $out ),
			'current_hour' => $current_hour,
		);
	}

	public static function daily( $days = 30 ) {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$shift  = self::offset_seconds() + DAY_IN_SECONDS;
		$start  = self::day_start_utc( $days - 1 );

		$humans = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT SUBSTR(DATE_ADD(started, INTERVAL %d SECOND), 1, 10) AS d, COUNT(DISTINCT visitor_id) AS u, COALESCE(SUM(pages), 0) AS p FROM %i WHERE is_bot = 0 AND started >= %s GROUP BY d',
				$shift,
				$visits,
				$start
			),
			ARRAY_A
		);
		$bots = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT SUBSTR(DATE_ADD(started, INTERVAL %d SECOND), 1, 10) AS d, COUNT(*) AS b FROM %i WHERE is_bot = 1 AND started >= %s GROUP BY d',
				$shift,
				$visits,
				$start
			),
			ARRAY_A
		);
		$day_before = function ( $key ) {
			try {
				$d = new DateTime( $key . ' 00:00:00', new DateTimeZone( 'UTC' ) );
				$d->modify( '-1 day' );
				return $d->format( 'Y-m-d' );
			} catch ( \Exception $e ) {
				return '';
			}
		};

		$map = array();
		$tz  = self::tz();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d = new DateTime( 'now', $tz );
			$d->modify( '-' . $i . ' days' );
			$key         = $d->format( 'Y-m-d' );
			$map[ $key ] = array(
				'd'     => $key,
				'label' => $d->format( 'D M j' ),
				'u'     => 0,
				'p'     => 0,
				'b'     => 0,
			);
		}
		if ( is_array( $humans ) ) {
			foreach ( $humans as $row ) {
				$key = $day_before( (string) $row['d'] );
				if ( isset( $map[ $key ] ) ) {
					$map[ $key ]['u'] = (int) $row['u'];
					$map[ $key ]['p'] = (int) $row['p'];
				}
			}
		}
		if ( is_array( $bots ) ) {
			foreach ( $bots as $row ) {
				$key = $day_before( (string) $row['d'] );
				if ( isset( $map[ $key ] ) ) {
					$map[ $key ]['b'] = (int) $row['b'];
				}
			}
		}
		return array_values( array_reverse( $map ) );
	}

	public static function resolutions() {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$start  = self::day_start_utc( 29 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT screen_w, screen_h, dpr, screen_class, COUNT(DISTINCT visitor_id) AS u FROM %i WHERE is_bot = 0 AND screen_w > 0 AND started >= %s GROUP BY screen_w, screen_h, dpr, screen_class ORDER BY u DESC LIMIT 25',
				$visits,
				$start
			),
			ARRAY_A
		);
		$classes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT screen_class, COUNT(DISTINCT visitor_id) AS u FROM %i WHERE is_bot = 0 AND started >= %s GROUP BY screen_class ORDER BY u DESC',
				$visits,
				$start
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(DISTINCT visitor_id) FROM %i WHERE is_bot = 0 AND started >= %s', $visits, $start )
		);

		$res = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$dpr   = (float) $row['dpr'];
				$res[] = array(
					'physical' => (int) round( $row['screen_w'] * $dpr ) . ' × ' . (int) round( $row['screen_h'] * $dpr ),
					'css'      => (int) $row['screen_w'] . ' × ' . (int) $row['screen_h'],
					'dpr'      => rtrim( rtrim( number_format( $dpr, 2, '.', '' ), '0' ), '.' ),
					'class'    => (string) $row['screen_class'],
					'u'        => (int) $row['u'],
				);
			}
		}
		$cls = array();
		if ( is_array( $classes ) ) {
			foreach ( $classes as $row ) {
				$cls[] = array(
					'class' => '' !== $row['screen_class'] ? (string) $row['screen_class'] : 'Unknown',
					'u'     => (int) $row['u'],
				);
			}
		}
		return array(
			'resolutions' => $res,
			'classes'     => $cls,
			'total'       => $total,
		);
	}

	public static function search_opts( $opts ) {
		$opts = is_array( $opts ) ? $opts : array();
		$out  = array(
			'in'     => ( isset( $opts['in'] ) && 'note' === $opts['in'] ) ? 'note' : 'all',
			'unique' => ! empty( $opts['unique'] ) ? 1 : 0,
			'page1'  => isset( $opts['page1'] ) ? self::page_needle( $opts['page1'] ) : '',
			'page2'  => isset( $opts['page2'] ) ? self::page_needle( $opts['page2'] ) : '',
			'from'   => isset( $opts['from'] ) ? self::clean_date( $opts['from'] ) : '',
			'to'     => isset( $opts['to'] ) ? self::clean_date( $opts['to'] ) : '',
			'tfrom'  => isset( $opts['tfrom'] ) ? self::clean_time( $opts['tfrom'] ) : '',
			'tto'    => isset( $opts['tto'] ) ? self::clean_time( $opts['tto'] ) : '',
		);
		if ( '' !== $out['from'] && '' !== $out['to'] && $out['from'] > $out['to'] ) {
			$swap        = $out['from'];
			$out['from'] = $out['to'];
			$out['to']   = $swap;
		}
		return $out;
	}

	public static function search_active( $o ) {
		return '' !== $o['page1'] || '' !== $o['page2'] || '' !== $o['from'] || '' !== $o['to'] || '' !== $o['tfrom'] || '' !== $o['tto'] || $o['unique'];
	}

	public static function page_needle( $raw ) {
		$raw = trim( wp_strip_all_tags( (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#^(https?:)?//#i', $raw ) || preg_match( '#^www\.#i', $raw ) ) {
			$url   = preg_match( '#^(https?:)?//#i', $raw ) ? $raw : 'https://' . $raw;
			$parts = wp_parse_url( $url );
			$raw   = ( is_array( $parts ) && isset( $parts['path'] ) ) ? $parts['path'] : '/';
		}
		$raw = rtrim( $raw, '/' );
		if ( '' === $raw ) {
			return '/';
		}
		return PQAnalytics_DB::cut( $raw, 200 );
	}

	public static function clean_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $raw;
		}
		return '';
	}

	public static function clean_time( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m ) && (int) $m[1] <= 23 && (int) $m[2] <= 59 ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}
		return '';
	}

	private static function utc_stamp( $ymd, $hms, $tz ) {
		$d = new DateTime( $ymd . ' ' . $hms, $tz );
		$d->setTimezone( new DateTimeZone( 'UTC' ) );
		return $d->format( 'Y-m-d H:i:s' );
	}

	public static function time_windows( $o ) {
		$tz  = self::tz();
		$out = array(
			'start'   => '',
			'end'     => '',
			'windows' => array(),
		);
		try {
			if ( '' !== $o['from'] ) {
				$out['start'] = self::utc_stamp( $o['from'], '00:00:00', $tz );
			}
			if ( '' !== $o['to'] ) {
				$d = new DateTime( $o['to'] . ' 00:00:00', $tz );
				$d->modify( '+1 day' );
				$d->setTimezone( new DateTimeZone( 'UTC' ) );
				$out['end'] = $d->format( 'Y-m-d H:i:s' );
			}
			if ( '' === $o['tfrom'] && '' === $o['tto'] ) {
				return $out;
			}
			$tf   = '' !== $o['tfrom'] ? $o['tfrom'] : '00:00';
			$tt   = '' !== $o['tto'] ? $o['tto'] : '23:59';
			$last = '' !== $o['to'] ? new DateTime( $o['to'] . ' 00:00:00', $tz ) : new DateTime( 'now', $tz );
			$last->setTime( 0, 0, 0 );
			$first = '' !== $o['from'] ? new DateTime( $o['from'] . ' 00:00:00', $tz ) : clone $last;
			if ( '' === $o['from'] ) {
				$first->modify( '-365 days' );
			}
			$floor = clone $last;
			$floor->modify( '-365 days' );
			if ( $first < $floor ) {
				$first = $floor;
			}
			if ( $first > $last ) {
				$first = clone $last;
			}
			$day = clone $first;
			while ( $day <= $last ) {
				$ymd = $day->format( 'Y-m-d' );
				if ( $tf <= $tt ) {
					$out['windows'][] = array( self::utc_stamp( $ymd, $tf . ':00', $tz ), self::utc_stamp( $ymd, $tt . ':59', $tz ) );
				} else {
					$out['windows'][] = array( self::utc_stamp( $ymd, $tf . ':00', $tz ), self::utc_stamp( $ymd, '23:59:59', $tz ) );
					$out['windows'][] = array( self::utc_stamp( $ymd, '00:00:00', $tz ), self::utc_stamp( $ymd, $tt . ':59', $tz ) );
				}
				$day->modify( '+1 day' );
			}
		} catch ( \Exception $e ) {
			return array(
				'start'   => '',
				'end'     => '',
				'windows' => array(),
			);
		}
		return $out;
	}

	public static function zone_info( $from = '', $to = '' ) {
		$tz    = self::tz();
		$label = self::tz_label();
		$name  = $label;
		if ( false !== strpos( $name, '/' ) ) {
			$name = substr( strrchr( $name, '/' ), 1 );
		}
		$name  = str_replace( '_', ' ', (string) $name );
		$abbrs = array();
		foreach ( array( $from, $to ) as $ymd ) {
			try {
				$d = ( '' !== $ymd ) ? new DateTime( $ymd . ' 12:00:00', $tz ) : new DateTime( 'now', $tz );
				$a = $d->format( 'T' );
				if ( '' !== $a && ! preg_match( '/^(GMT|UTC)?[+-]\d/i', $a ) && $a !== $name && ! in_array( $a, $abbrs, true ) ) {
					$abbrs[] = $a;
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}
		return array(
			'name' => $name,
			'abbr' => implode( '/', $abbrs ),
		);
	}

	private static function path_test( $alias, $needle, &$args ) {
		global $wpdb;
		if ( '/' === $needle ) {
			$args[] = '/';
			return $alias . '.path = %s';
		}
		$args[] = '%' . $wpdb->esc_like( $needle ) . '%';
		return $alias . '.path LIKE %s';
	}

	public static function search_where( $vs, $vr, $filter, $search, $o, $win ) {
		global $wpdb;
		$hits   = PQAnalytics_DB::table( 'hits' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - PQANALYTICS_LIVE_SECONDS );
		$sql    = array();
		$args   = array();

		$sql[]  = $vs . '.is_bot = %d';
		$args[] = ( 'bots' === $filter ) ? 1 : 0;
		$sql[]  = $vs . '.last_beat >= %s';
		$args[] = ( 'live' === $filter ) ? $cutoff : '1970-01-01 00:00:00';
		$sql[]  = $vs . '.visit_no >= %d';
		$args[] = ( 'returning' === $filter ) ? 2 : 1;

		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			if ( 'note' === $o['in'] ) {
				$sql[]  = $vr . '.label LIKE %s';
				$args[] = $like;
			} else {
				$cols  = array( $vs . '.ip', $vs . '.city', $vs . '.region', $vs . '.country', $vs . '.isp', $vs . '.source', $vs . '.last_url', $vs . '.member', $vr . '.name', $vr . '.email', $vr . '.label' );
				$parts = array();
				foreach ( $cols as $col ) {
					$parts[] = $col . ' LIKE %s';
					$args[]  = $like;
				}
				if ( in_array( strtolower( $search ), array( 'member', 'members' ), true ) ) {
					$parts[] = $vs . ".member <> ''";
				}
				$sql[] = '(' . implode( ' OR ', $parts ) . ')';
			}
		}

		if ( '' !== $o['page1'] && '' !== $o['page2'] ) {
			$a     = array( $hits, $hits );
			$t1    = self::path_test( 'h1', $o['page1'], $a );
			$t2    = self::path_test( 'h2', $o['page2'], $a );
			$sql[] = 'EXISTS (SELECT 1 FROM %i h1 INNER JOIN %i h2 ON h2.visit_id = h1.visit_id AND h2.id > h1.id WHERE h1.visit_id = ' . $vs . '.id AND ' . $t1 . ' AND ' . $t2 . ')';
			$args  = array_merge( $args, $a );
		} elseif ( '' !== $o['page1'] || '' !== $o['page2'] ) {
			$a     = array( $hits );
			$t1    = self::path_test( 'h1', '' !== $o['page1'] ? $o['page1'] : $o['page2'], $a );
			$sql[] = 'EXISTS (SELECT 1 FROM %i h1 WHERE h1.visit_id = ' . $vs . '.id AND ' . $t1 . ')';
			$args  = array_merge( $args, $a );
		}

		if ( '' !== $win['start'] ) {
			$sql[]  = $vs . '.started >= %s';
			$args[] = $win['start'];
		}
		if ( '' !== $win['end'] ) {
			$sql[]  = $vs . '.started < %s';
			$args[] = $win['end'];
		}
		if ( ! empty( $win['windows'] ) ) {
			$parts = array();
			foreach ( $win['windows'] as $w ) {
				$parts[] = '(' . $vs . '.started >= %s AND ' . $vs . '.started <= %s)';
				$args[]  = $w[0];
				$args[]  = $w[1];
			}
			$sql[] = '(' . implode( ' OR ', $parts ) . ')';
		}

		return array(
			'sql'  => implode( ' AND ', $sql ),
			'args' => $args,
		);
	}

	private static function search_clause( $filter, $search, $o ) {
		$visits   = PQAnalytics_DB::table( 'visits' );
		$visitors = PQAnalytics_DB::table( 'visitors' );
		$win      = self::time_windows( $o );
		$w        = self::search_where( 'vs', 'vr', $filter, $search, $o, $win );
		$where    = $w['sql'];
		$args     = $w['args'];
		if ( $o['unique'] ) {
			$w2     = self::search_where( 'v2', 'r2', $filter, $search, $o, $win );
			$where .= ' AND vs.id IN (SELECT MAX(v2.id) FROM %i v2 LEFT JOIN %i r2 ON r2.id = v2.visitor_id WHERE ' . $w2['sql'] . ' GROUP BY v2.visitor_id, CASE WHEN v2.visitor_id = 0 THEN v2.ip ELSE %s END)';
			$args   = array_merge( $args, array( $visits, $visitors ), $w2['args'], array( '' ) );
		}
		return array(
			'sql'  => $where,
			'args' => $args,
		);
	}

	public static function visitors( $filter = 'all', $search = '', $page = 1, $opts = array() ) {
		global $wpdb;
		$visits   = PQAnalytics_DB::table( 'visits' );
		$visitors = PQAnalytics_DB::table( 'visitors' );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - PQANALYTICS_LIVE_SECONDS );
		$o        = self::search_opts( $opts );
		$page     = max( 1, (int) $page );
		$offset   = ( $page - 1 ) * self::PER_PAGE;
		$clause   = self::search_clause( $filter, $search, $o );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i vs LEFT JOIN %i vr ON vr.id = vs.visitor_id WHERE ' . $clause['sql'],
				array_merge( array( $visits, $visitors ), $clause['args'] )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT vs.*, vr.vid, vr.first_seen, vr.visits AS visits_total, vr.name, vr.email, vr.phone, vr.label FROM %i vs LEFT JOIN %i vr ON vr.id = vs.visitor_id WHERE ' . $clause['sql'] . ' ORDER BY vs.last_beat DESC LIMIT %d OFFSET %d',
				array_merge( array( $visits, $visitors ), $clause['args'], array( self::PER_PAGE, $offset ) )
			),
			ARRAY_A
		);

		$blocked = PQAnalytics_Blocker::blocked();
		$out     = array();
		$ids     = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = self::format_visit( $r, $cutoff, $blocked );
				$ids[] = (int) $r['id'];
			}
		}
		$recent = self::recent_paths( $ids, 5 );
		foreach ( $out as $i => $row ) {
			$out[ $i ]['recent'] = isset( $recent[ $row['id'] ] ) ? $recent[ $row['id'] ]['hits'] : array();
			$out[ $i ]['more']   = isset( $recent[ $row['id'] ] ) ? $recent[ $row['id'] ]['more'] : 0;
		}
		return array(
			'rows'   => $out,
			'total'  => $total,
			'page'   => $page,
			'pages'  => (int) max( 1, ceil( $total / self::PER_PAGE ) ),
			'unique' => $o['unique'],
			'zone'   => self::zone_info( $o['from'], $o['to'] ),
		);
	}

	public static function path_list( $limit = 2000 ) {
		global $wpdb;
		$hits   = PQAnalytics_DB::table( 'hits' );
		$visits = PQAnalytics_DB::table( 'visits' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.path, COUNT(DISTINCT h.visit_id) AS visits FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND h.path <> '' GROUP BY h.path ORDER BY visits DESC LIMIT %d",
				$hits,
				$visits,
				(int) $limit
			),
			ARRAY_A
		);
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			$out[] = array(
				'p' => (string) $r['path'],
				'n' => (int) $r['visits'],
			);
		}
		return $out;
	}

	public static function recent_paths( $ids, $limit = 5 ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$hits = PQAnalytics_DB::table( 'hits' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, visit_id, url, title, hit_time, duration FROM %i WHERE visit_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY visit_id ASC, id DESC', array_merge( array( $hits ), $ids ) ), ARRAY_A );
		$out  = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			$v = (int) $r['visit_id'];
			if ( ! isset( $out[ $v ] ) ) {
				$out[ $v ] = array( 'hits' => array(), 'more' => 0 );
			}
			if ( count( $out[ $v ]['hits'] ) >= $limit ) {
				$out[ $v ]['more']++;
				continue;
			}
			$out[ $v ]['hits'][] = self::format_hit( $r );
		}
		foreach ( $out as $v => $data ) {
			$out[ $v ]['hits'] = array_reverse( $data['hits'] );
		}
		return $out;
	}

	public static function format_hit( $r ) {
		return array(
			'time'     => self::local( $r['hit_time'], 'g:i A' ),
			'ts'       => (int) strtotime( $r['hit_time'] . ' UTC' ),
			'url'      => (string) $r['url'],
			'title'    => PQAnalytics_DB::clean_title( $r['title'] ),
			'duration' => (int) $r['duration'],
		);
	}

	public static function live_visitors( $limit = 50 ) {
		global $wpdb;
		$visits   = PQAnalytics_DB::table( 'visits' );
		$visitors = PQAnalytics_DB::table( 'visitors' );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - PQANALYTICS_LIVE_SECONDS );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT vs.id, vs.started, vs.last_beat, vs.last_url, vs.last_title, vs.city, vs.region, vs.country_code, vs.visit_no, vs.member, vr.vid, vr.name, vr.email, vr.label FROM %i vs INNER JOIN %i vr ON vr.id = vs.visitor_id WHERE vs.is_bot = 0 AND vs.last_beat >= %s ORDER BY vs.last_beat DESC LIMIT %d',
				$visits,
				$visitors,
				$cutoff,
				(int) $limit
			),
			ARRAY_A
		);
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		$seen = array();
		foreach ( $rows as $r ) {
			if ( isset( $seen[ $r['vid'] ] ) ) {
				continue;
			}
			$seen[ $r['vid'] ] = true;
			$out[] = array(
				'vid'      => (string) $r['vid'],
				'name'     => (string) $r['name'],
				'label'    => isset( $r['label'] ) ? (string) $r['label'] : '',
				'member'   => (string) $r['member'],
				'email'    => (string) $r['email'],
				'location' => implode( ', ', array_filter( array( $r['city'], $r['region'], $r['country_code'] ) ) ),
				'url'      => (string) $r['last_url'],
				'title'    => PQAnalytics_DB::clean_title( $r['last_title'] ),
				'since'    => max( 0, time() - (int) strtotime( $r['started'] . ' UTC' ) ),
				'visit_no' => (int) $r['visit_no'],
			);
		}
		return $out;
	}

	public static function format_visit( $r, $cutoff, $blocked ) {
		$dpr      = (float) $r['dpr'];
		$location = array_filter( array( $r['city'], $r['region'], $r['country_code'] ) );
		$physical = ( (int) $r['screen_w'] > 0 ) ? (int) round( $r['screen_w'] * $dpr ) . '×' . (int) round( $r['screen_h'] * $dpr ) : '';
		return array(
			'id'          => (int) $r['id'],
			'vid'         => isset( $r['vid'] ) ? (string) $r['vid'] : '',
			'live'        => ( ! $r['is_bot'] && $r['last_beat'] >= $cutoff ),
			'time'        => self::local( $r['last_beat'] ),
			'started'     => self::local( $r['started'] ),
			'ip'          => (string) $r['ip'],
			'blocked'     => isset( $blocked[ $r['ip'] ] ),
			'location'    => implode( ', ', $location ),
			'postal'      => (string) $r['postal'],
			'country'     => (string) $r['country'],
			'isp'         => (string) $r['isp'],
			'visit_no'    => (int) $r['visit_no'],
			'visits_total' => isset( $r['visits_total'] ) ? max( (int) $r['visits_total'], (int) $r['visit_no'] ) : (int) $r['visit_no'],
			'member'      => isset( $r['member'] ) ? (string) $r['member'] : '',
			'first_seen'  => isset( $r['first_seen'] ) ? self::local( $r['first_seen'], 'M j, Y' ) : '',
			'name'        => isset( $r['name'] ) ? (string) $r['name'] : '',
			'label'       => isset( $r['label'] ) ? (string) $r['label'] : '',
			'email'       => isset( $r['email'] ) ? (string) $r['email'] : '',
			'phone'       => isset( $r['phone'] ) ? (string) $r['phone'] : '',
			'source'      => (string) $r['source'],
			'source_type' => (string) $r['source_type'],
			'referrer'    => (string) $r['referrer'],
			'keyword'     => (string) $r['keyword'],
			'campaign'    => (string) $r['utm_campaign'],
			'url'         => (string) $r['last_url'],
			'title'       => PQAnalytics_DB::clean_title( $r['last_title'] ),
			'pages'       => (int) $r['pages'],
			'resolution'  => $physical,
			'dpr'         => rtrim( rtrim( number_format( $dpr, 2, '.', '' ), '0' ), '.' ),
			'screen'      => (string) $r['screen_class'],
			'browser'     => trim( $r['browser'] . ' ' . $r['browser_ver'] ),
			'os'          => (string) $r['os'],
			'language'    => (string) $r['language'],
			'is_bot'      => (int) $r['is_bot'],
			'bot_name'    => (string) $r['bot_name'],
		);
	}

	public static function latest_visit_by_vid( $vid ) {
		global $wpdb;
		if ( ! PQAnalytics_Visits::valid_vid( $vid ) ) {
			return null;
		}
		$visits   = PQAnalytics_DB::table( 'visits' );
		$visitors = PQAnalytics_DB::table( 'visitors' );
		$row      = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT vs.*, vr.vid, vr.first_seen, vr.visits AS visits_total, vr.name, vr.email, vr.phone, vr.label FROM %i vs INNER JOIN %i vr ON vr.id = vs.visitor_id WHERE vr.vid = %s ORDER BY vs.id DESC LIMIT 1',
				$visits,
				$visitors,
				$vid
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - PQANALYTICS_LIVE_SECONDS );
		$out    = self::format_visit( $row, $cutoff, PQAnalytics_Blocker::blocked() );
		$out['path'] = self::path( (int) $row['id'] );
		return $out;
	}

	public static function history( $vid ) {
		global $wpdb;
		$visitor = PQAnalytics_DB::visitor_by_vid( $vid );
		if ( ! $visitor ) {
			return null;
		}
		$visits = PQAnalytics_DB::table( 'visits' );
		$hits   = PQAnalytics_DB::table( 'hits' );
		$id     = (int) $visitor['id'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, visit_no, started, last_beat, pages, source, source_type, keyword, utm_campaign, city, region, country_code, screen_class, browser, browser_ver, os FROM %i WHERE visitor_id = %d AND is_bot = 0 ORDER BY id DESC LIMIT 30',
				$visits,
				$id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$ids  = array();
		foreach ( $rows as $r ) {
			$ids[] = (int) $r['id'];
		}

		$by_visit = array();
		if ( ! empty( $ids ) ) {
			$hit_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT visit_id, url, title, hit_time, duration FROM %i WHERE visit_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY visit_id DESC, id ASC LIMIT 2000',
					array_merge( array( $hits ), $ids )
				),
				ARRAY_A
			);
			if ( is_array( $hit_rows ) ) {
				foreach ( $hit_rows as $h ) {
					$v = (int) $h['visit_id'];
					if ( ! isset( $by_visit[ $v ] ) ) {
						$by_visit[ $v ] = array( 'hits' => array(), 'more' => 0 );
					}
					if ( count( $by_visit[ $v ]['hits'] ) >= 40 ) {
						$by_visit[ $v ]['more']++;
						continue;
					}
					$by_visit[ $v ]['hits'][] = self::format_hit( $h );
				}
			}
		}

		$out = array();
		foreach ( $rows as $r ) {
			$v        = (int) $r['id'];
			$seconds  = max( 0, (int) strtotime( $r['last_beat'] . ' UTC' ) - (int) strtotime( $r['started'] . ' UTC' ) );
			$location = array_filter( array( $r['city'], $r['region'], $r['country_code'] ) );
			$out[]    = array(
				'id'       => $v,
				'visit_no' => (int) $r['visit_no'],
				'date'     => self::local( $r['started'], 'D, M j, Y' ),
				'time'     => self::local( $r['started'], 'g:i A' ),
				'seconds'  => $seconds,
				'pages'    => (int) $r['pages'],
				'source'   => (string) $r['source'],
				'keyword'  => (string) $r['keyword'],
				'campaign' => (string) $r['utm_campaign'],
				'location' => implode( ', ', $location ),
				'screen'   => (string) $r['screen_class'],
				'browser'  => trim( $r['browser'] . ' ' . $r['browser_ver'] ),
				'os'       => (string) $r['os'],
				'hits'     => isset( $by_visit[ $v ] ) ? $by_visit[ $v ]['hits'] : array(),
				'more'     => isset( $by_visit[ $v ] ) ? $by_visit[ $v ]['more'] : 0,
			);
		}

		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.path, MAX(h.url) AS url, MAX(h.title) AS title, COUNT(DISTINCT h.visit_id) AS visits, COUNT(*) AS views, SUM(h.duration) AS seconds FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.visitor_id = %d AND v.is_bot = 0 AND h.path <> '' GROUP BY h.path ORDER BY visits DESC, views DESC LIMIT 15",
				$hits,
				$visits,
				$id
			),
			ARRAY_A
		);
		$top = array();
		if ( is_array( $pages ) ) {
			foreach ( $pages as $p ) {
				$top[] = array(
					'path'    => (string) $p['path'],
					'url'     => (string) $p['url'],
					'title'   => PQAnalytics_DB::clean_title( $p['title'] ),
					'visits'  => (int) $p['visits'],
					'views'   => (int) $p['views'],
					'seconds' => (int) $p['seconds'],
				);
			}
		}

		$days = (int) PQAnalytics_Settings::get( 'retention_days' );
		return array(
			'total'      => (int) $visitor['visits'],
			'kept'       => count( $out ),
			'first_seen' => self::local( $visitor['first_seen'], 'M j, Y' ),
			'days'       => $days > 0 ? $days : 90,
			'visits'     => $out,
			'pages'      => $top,
		);
	}

	public static function path( $visit_id ) {
		global $wpdb;
		$hits = PQAnalytics_DB::table( 'hits' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT url, title, hit_time, duration FROM %i WHERE visit_id = %d ORDER BY id ASC LIMIT 500', $hits, (int) $visit_id ),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = self::format_hit( $r );
			}
		}
		return $out;
	}

	public static function report_summary( $from, $to ) {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS n, COUNT(DISTINCT visitor_id) AS u, COALESCE(SUM(pages), 0) AS p, COALESCE(SUM(pages = 1 AND (UNIX_TIMESTAMP(last_beat) - UNIX_TIMESTAMP(started)) < 10), 0) AS s, COUNT(DISTINCT CASE WHEN visit_no > 1 THEN visitor_id END) AS r, COALESCE(AVG((UNIX_TIMESTAMP(last_beat) - UNIX_TIMESTAMP(started))), 0) AS secs FROM %i WHERE is_bot = 0 AND started >= %s AND started < %s',
				$visits,
				$from,
				$to
			),
			ARRAY_A
		);
		$bots = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_bot = 1 AND started >= %s AND started < %s', $visits, $from, $to )
		);
		$n = $row ? (int) $row['n'] : 0;
		return array(
			'visits'    => $n,
			'visitors'  => $row ? (int) $row['u'] : 0,
			'views'     => $row ? (int) $row['p'] : 0,
			'returning' => $row ? (int) $row['r'] : 0,
			'bounce'    => $n ? (int) round( 100 * (int) $row['s'] / $n ) : 0,
			'per'       => $n ? round( (int) $row['p'] / $n, 1 ) : 0,
			'seconds'   => $row ? (int) round( (float) $row['secs'] ) : 0,
			'bots'      => $bots,
		);
	}

	public static function report( $from, $to, $prev_from, $prev_to ) {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$hits   = PQAnalytics_DB::table( 'hits' );
		$host   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$sources = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source, COUNT(DISTINCT visitor_id) AS n FROM %i WHERE is_bot = 0 AND started >= %s AND started < %s GROUP BY source ORDER BY n DESC LIMIT 10',
				$visits,
				$from,
				$to
			),
			ARRAY_A
		);
		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.path, COUNT(*) AS n FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND h.path <> '' GROUP BY h.path ORDER BY n DESC LIMIT 10",
				$hits,
				$visits,
				$from,
				$to
			),
			ARRAY_A
		);
		$places = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT city, region, country_code, COUNT(DISTINCT visitor_id) AS n FROM %i WHERE is_bot = 0 AND started >= %s AND started < %s AND country_code <> '' GROUP BY city, region, country_code ORDER BY n DESC LIMIT 10",
				$visits,
				$from,
				$to
			),
			ARRAY_A
		);
		$screens = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT screen_class, COUNT(DISTINCT visitor_id) AS n FROM %i WHERE is_bot = 0 AND started >= %s AND started < %s GROUP BY screen_class ORDER BY n DESC',
				$visits,
				$from,
				$to
			),
			ARRAY_A
		);

		$out = array(
			'now'     => self::report_summary( $from, $to ),
			'prev'    => self::report_summary( $prev_from, $prev_to ),
			'sources' => array(),
			'pages'   => array(),
			'places'  => array(),
			'screens' => array(),
		);
		if ( is_array( $sources ) ) {
			foreach ( $sources as $r ) {
				$out['sources'][] = array( 'label' => '' !== $r['source'] ? (string) $r['source'] : __( 'Unknown', 'pinnaclequant-com-analytics' ), 'n' => (int) $r['n'] );
			}
		}
		if ( is_array( $pages ) ) {
			foreach ( $pages as $r ) {
				$out['pages'][] = array( 'label' => '/' === $r['path'] ? $host : (string) $r['path'], 'n' => (int) $r['n'] );
			}
		}
		if ( is_array( $places ) ) {
			foreach ( $places as $r ) {
				$out['places'][] = array( 'label' => implode( ', ', array_filter( array( $r['city'], $r['region'], $r['country_code'] ) ) ), 'n' => (int) $r['n'] );
			}
		}
		if ( is_array( $screens ) ) {
			foreach ( $screens as $r ) {
				$out['screens'][] = array( 'label' => '' !== $r['screen_class'] ? (string) $r['screen_class'] : __( 'Unknown', 'pinnaclequant-com-analytics' ), 'n' => (int) $r['n'] );
			}
		}
		return $out;
	}

	public static function export_rows( $days, $filter = null, $search = '', $opts = null ) {
		global $wpdb;
		$visits   = PQAnalytics_DB::table( 'visits' );
		$visitors = PQAnalytics_DB::table( 'visitors' );
		$hits     = PQAnalytics_DB::table( 'hits' );
		$start    = self::day_start_utc( max( 1, (int) $days ) - 1 );
		if ( null === $opts ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT vs.*, vr.first_seen, vr.visits AS visits_total, vr.name, vr.email, vr.phone, vr.label FROM %i vs LEFT JOIN %i vr ON vr.id = vs.visitor_id WHERE vs.started >= %s ORDER BY vs.started DESC LIMIT 50000',
					$visits,
					$visitors,
					$start
				),
				ARRAY_A
			);
		} else {
			$o      = self::search_opts( $opts );
			$clause = self::search_clause( $filter, $search, $o );
			$where  = $clause['sql'];
			$args   = $clause['args'];
			if ( '' === $o['from'] && '' === $o['to'] ) {
				$where .= ' AND vs.started >= %s';
				$args[] = $start;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT vs.*, vr.first_seen, vr.visits AS visits_total, vr.name, vr.email, vr.phone, vr.label FROM %i vs LEFT JOIN %i vr ON vr.id = vs.visitor_id WHERE ' . $where . ' ORDER BY vs.started DESC LIMIT 50000',
					array_merge( array( $visits, $visitors ), $args )
				),
				ARRAY_A
			);
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$paths = array();
		$ids   = array();
		foreach ( $rows as $r ) {
			$ids[] = (int) $r['id'];
		}
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$hit_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT visit_id, path FROM %i WHERE visit_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') ORDER BY visit_id ASC, id ASC',
					array_merge( array( $hits ), $chunk )
				),
				ARRAY_A
			);
			if ( ! is_array( $hit_rows ) ) {
				continue;
			}
			foreach ( $hit_rows as $h ) {
				$v = (int) $h['visit_id'];
				if ( ! isset( $paths[ $v ] ) ) {
					$paths[ $v ] = array();
				}
				if ( count( $paths[ $v ] ) < 200 ) {
					$paths[ $v ][] = (string) $h['path'];
				}
			}
		}
		foreach ( $rows as $i => $r ) {
			$v                        = (int) $r['id'];
			$rows[ $i ]['pages_path'] = isset( $paths[ $v ] ) ? implode( ' > ', $paths[ $v ] ) : '';
		}
		return $rows;
	}

	public static function flow_range( $range ) {
		$far = '2100-01-01 00:00:00';
		switch ( $range ) {
			case 'today':
				return array( self::day_start_utc( 0 ), $far );
			case 'yesterday':
				return array( self::day_start_utc( 1 ), self::day_start_utc( 0 ) );
			case '30':
				return array( self::day_start_utc( 29 ), $far );
			case '90':
				return array( self::day_start_utc( 89 ), $far );
			default:
				return array( self::day_start_utc( 6 ), $far );
		}
	}

	public static function flow( $range, $from = '' ) {
		global $wpdb;
		$visits = PQAnalytics_DB::table( 'visits' );
		$hits   = PQAnalytics_DB::table( 'hits' );
		list( $start, $end ) = self::flow_range( $range );
		$from = PQAnalytics_DB::cut( (string) $from, 500 );

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS visits, COALESCE(SUM(pages), 0) AS views, COALESCE(SUM(pages = 1 AND (UNIX_TIMESTAMP(last_beat) - UNIX_TIMESTAMP(started)) < 10), 0) AS single, COALESCE(AVG((UNIX_TIMESTAMP(last_beat) - UNIX_TIMESTAMP(started))), 0) AS seconds FROM %i WHERE is_bot = 0 AND started >= %s AND started < %s',
				$visits,
				$start,
				$end
			),
			ARRAY_A
		);
		$n = $totals ? (int) $totals['visits'] : 0;
		$cards = array(
			'visits'   => $n,
			'views'    => $totals ? (int) $totals['views'] : 0,
			'per'      => $n ? round( (int) $totals['views'] / $n, 1 ) : 0,
			'single'   => $n ? round( 100 * (int) $totals['single'] / $n ) : 0,
			'seconds'  => $totals ? (int) round( (float) $totals['seconds'] ) : 0,
		);

		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.path, MAX(h.title) AS title, COUNT(*) AS views, COUNT(DISTINCT h.visit_id) AS visits, COALESCE(AVG(NULLIF(h.duration, 0)), 0) AS avg_time, SUM(h.prev_path = '') AS entries FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND h.path <> '' GROUP BY h.path ORDER BY views DESC LIMIT 200",
				$hits,
				$visits,
				$start,
				$end
			),
			ARRAY_A
		);

		$exits = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.path, COUNT(*) AS n FROM %i h INNER JOIN (SELECT MAX(id) AS id FROM %i WHERE hit_time >= %s GROUP BY visit_id) last ON last.id = h.id INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND h.path <> '' GROUP BY h.path ORDER BY n DESC LIMIT 200",
				$hits,
				$hits,
				$start,
				$visits,
				$start,
				$end
			),
			ARRAY_A
		);
		$exit_map = array();
		if ( is_array( $exits ) ) {
			foreach ( $exits as $r ) {
				$exit_map[ $r['path'] ] = (int) $r['n'];
			}
		}

		$page_rows = array();
		$entries   = array();
		if ( is_array( $pages ) ) {
			foreach ( $pages as $r ) {
				$page_rows[] = array(
					'path'    => $r['path'],
					'title'   => PQAnalytics_DB::clean_title( $r['title'] ),
					'views'   => (int) $r['views'],
					'visits'  => (int) $r['visits'],
					'time'    => (int) round( (float) $r['avg_time'] ),
					'entries' => (int) $r['entries'],
					'exits'   => isset( $exit_map[ $r['path'] ] ) ? $exit_map[ $r['path'] ] : 0,
				);
				if ( (int) $r['entries'] > 0 ) {
					$entries[] = array( 'path' => $r['path'], 'n' => (int) $r['entries'] );
				}
			}
		}
		usort( $entries, function ( $a, $b ) { return $b['n'] - $a['n']; } );
		$exit_rows = array();
		if ( is_array( $exits ) ) {
			foreach ( array_slice( $exits, 0, 25 ) as $r ) {
				$exit_rows[] = array( 'path' => $r['path'], 'n' => (int) $r['n'] );
			}
		}

		if ( '' !== $from ) {
			$next = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT h.path AS to_path, COUNT(*) AS n FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND h.prev_path = %s AND h.path <> h.prev_path GROUP BY h.path ORDER BY n DESC LIMIT 25',
					$hits,
					$visits,
					$start,
					$end,
					$from
				),
				ARRAY_A
			);
			$before = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT h.prev_path AS from_path, COUNT(*) AS n FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND h.path = %s AND h.prev_path <> '' AND h.prev_path <> h.path GROUP BY h.prev_path ORDER BY n DESC LIMIT 25",
					$hits,
					$visits,
					$start,
					$end,
					$from
				),
				ARRAY_A
			);
			$left = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i h INNER JOIN (SELECT MAX(id) AS id FROM %i WHERE hit_time >= %s GROUP BY visit_id) last ON last.id = h.id INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND h.path = %s',
					$hits,
					$hits,
					$start,
					$visits,
					$start,
					$end,
					$from
				)
			);
		} else {
			$next = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT h.prev_path AS from_path, h.path AS to_path, COUNT(*) AS n FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND h.prev_path <> '' AND h.path <> h.prev_path GROUP BY h.prev_path, h.path ORDER BY n DESC LIMIT 40",
					$hits,
					$visits,
					$start,
					$end
				),
				ARRAY_A
			);
			$before = array();
			$left   = 0;
		}

		$seq = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.visit_id, h.path FROM %i h INNER JOIN %i v ON v.id = h.visit_id WHERE v.is_bot = 0 AND v.started >= %s AND v.started < %s AND v.pages > 1 AND h.path <> '' ORDER BY h.visit_id, h.id LIMIT 60000",
				$hits,
				$visits,
				$start,
				$end
			),
			ARRAY_A
		);
		$paths   = array();
		$current = 0;
		$chain   = array();
		$flush   = function () use ( &$paths, &$chain ) {
			if ( count( $chain ) >= 2 ) {
				$key = implode( ' > ', array_slice( $chain, 0, 4 ) );
				$paths[ $key ] = isset( $paths[ $key ] ) ? $paths[ $key ] + 1 : 1;
			}
			$chain = array();
		};
		if ( is_array( $seq ) ) {
			foreach ( $seq as $r ) {
				if ( (int) $r['visit_id'] !== $current ) {
					$flush();
					$current = (int) $r['visit_id'];
				}
				if ( empty( $chain ) || end( $chain ) !== $r['path'] ) {
					$chain[] = $r['path'];
				}
			}
			$flush();
		}
		arsort( $paths );
		$common = array();
		foreach ( array_slice( $paths, 0, 30, true ) as $key => $count ) {
			$common[] = array( 'path' => $key, 'n' => (int) $count );
		}

		return array(
			'range'   => $range,
			'from'    => $from,
			'cards'   => $cards,
			'pages'   => $page_rows,
			'entries' => array_slice( $entries, 0, 25 ),
			'exits'   => $exit_rows,
			'next'    => is_array( $next ) ? $next : array(),
			'before'  => is_array( $before ) ? $before : array(),
			'left'    => $left,
			'common'  => $common,
			'capped'  => is_array( $seq ) && count( $seq ) >= 60000 ? 1 : 0,
		);
	}
}
