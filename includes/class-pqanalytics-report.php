<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Report {

	const HOOK        = 'pqanalytics_email_report';
	const OPTION_NEXT = 'pqanalytics_report_next';
	const OPTION_SENT = 'pqanalytics_report_sent';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'update_option_' . PQAnalytics_Settings::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'add_option_' . PQAnalytics_Settings::OPTION, array( __CLASS__, 'schedule' ) );
	}

	public static function kind() {
		$kind = (string) PQAnalytics_Settings::get( 'report' );
		return in_array( $kind, array( 'daily', 'weekly' ), true ) ? $kind : 'off';
	}

	public static function recipients( $raw = null, $fallback = true ) {
		$raw = ( null === $raw ) ? (string) PQAnalytics_Settings::get( 'report_to' ) : (string) $raw;
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $part ) {
			$part = sanitize_email( trim( (string) $part ) );
			if ( '' !== $part && is_email( $part ) ) {
				$out[] = $part;
			}
		}
		if ( empty( $out ) && $fallback ) {
			$admin = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( is_email( $admin ) ) {
				$out[] = $admin;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function next_time( $after ) {
		$kind = self::kind();
		if ( 'off' === $kind ) {
			return 0;
		}
		$hour = max( 0, min( 23, (int) PQAnalytics_Settings::get( 'report_hour' ) ) );
		$day  = max( 1, min( 7, (int) PQAnalytics_Settings::get( 'report_day' ) ) );
		$d    = new DateTime( '@' . (int) $after );
		$d->setTimezone( PQAnalytics_Stats::tz() );
		$d->setTime( $hour, 0, 0 );
		if ( $d->getTimestamp() <= (int) $after ) {
			$d->modify( '+1 day' );
		}
		if ( 'weekly' === $kind ) {
			$guard = 0;
			while ( (int) $d->format( 'N' ) !== $day && $guard < 7 ) {
				$d->modify( '+1 day' );
				$guard++;
			}
		}
		return $d->getTimestamp();
	}

	public static function schedule() {
		if ( 'off' === self::kind() ) {
			self::unschedule();
			return;
		}
		$now      = time();
		$existing = (int) get_option( self::OPTION_NEXT, 0 );
		if ( $existing > 0 && $existing <= $now && wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_unschedule_hook( self::HOOK );
		$next = self::next_time( $now );
		wp_schedule_single_event( $next, self::HOOK );
		update_option( self::OPTION_NEXT, $next, false );
	}

	public static function unschedule() {
		wp_unschedule_hook( self::HOOK );
		delete_option( self::OPTION_NEXT );
	}

	public static function next_label() {
		if ( 'off' === self::kind() ) {
			return '';
		}
		$next = (int) get_option( self::OPTION_NEXT, 0 );
		if ( $next <= 0 ) {
			return '';
		}
		$date_format = get_option( 'date_format' ) ? get_option( 'date_format' ) : 'F j, Y';
		$time_format = get_option( 'time_format' ) ? get_option( 'time_format' ) : 'g:i a';
		return wp_date( 'l, ' . $date_format . ', ' . $time_format, $next, PQAnalytics_Stats::tz() );
	}

	public static function run() {
		$kind = self::kind();
		if ( 'off' === $kind ) {
			self::unschedule();
			return;
		}
		$target = (int) get_option( self::OPTION_NEXT, 0 );
		$now    = time();
		$ref    = ( $target > 0 ) ? $target : $now;
		$period = self::period( $kind, $ref );
		if ( get_option( self::OPTION_SENT ) !== $period['key'] ) {
			self::send( $kind, self::recipients(), $ref );
			update_option( self::OPTION_SENT, $period['key'], false );
		}
		wp_unschedule_hook( self::HOOK );
		$next = self::next_time( max( $now, $target ) );
		wp_schedule_single_event( $next, self::HOOK );
		update_option( self::OPTION_NEXT, $next, false );
	}

	public static function period( $kind, $ref ) {
		$days = ( 'weekly' === $kind ) ? 7 : 1;
		$tz   = PQAnalytics_Stats::tz();
		$end  = new DateTime( '@' . (int) $ref );
		$end->setTimezone( $tz );
		$end->setTime( 0, 0, 0 );
		$start = clone $end;
		$start->modify( '-' . $days . ' days' );
		$prev = clone $start;
		$prev->modify( '-' . $days . ' days' );
		$last = clone $end;
		$last->modify( '-1 day' );

		$utc = new DateTimeZone( 'UTC' );
		$fmt = function ( $d ) use ( $utc ) {
			$c = clone $d;
			$c->setTimezone( $utc );
			return $c->format( 'Y-m-d H:i:s' );
		};

		if ( 1 === $days ) {
			$label = wp_date( 'l, F j, Y', $start->getTimestamp(), $tz );
			$short = wp_date( 'D, M j', $start->getTimestamp(), $tz );
		} else {
			$label = wp_date( 'F j', $start->getTimestamp(), $tz ) . ' – ' . wp_date( 'F j, Y', $last->getTimestamp(), $tz );
			$short = wp_date( 'M j', $start->getTimestamp(), $tz ) . ' – ' . wp_date( 'M j', $last->getTimestamp(), $tz );
		}

		return array(
			'kind'      => $kind,
			'days'      => $days,
			'from'      => $fmt( $start ),
			'to'        => $fmt( $end ),
			'prev_from' => $fmt( $prev ),
			'prev_to'   => $fmt( $start ),
			'label'     => $label,
			'short'     => $short,
			'key'       => $kind . ':' . $start->format( 'Y-m-d' ),
		);
	}

	public static function send( $kind, $to, $ref, &$error = '' ) {
		$kind   = ( 'weekly' === $kind ) ? 'weekly' : 'daily';
		$period = self::period( $kind, $ref );
		$data   = PQAnalytics_Stats::report( $period['from'], $period['to'], $period['prev_from'], $period['prev_to'] );
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === trim( $site ) ) {
			$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		}
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		/* translators: 1: site host name, 2: date or date range */
		$subject = sprintf( __( 'Your Analytics Report for %1$s, %2$s', 'pinnaclequant-com-analytics' ), $host, $period['short'] );
		$html    = self::html( $period, $data, $site );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$error   = '';
		$catch   = function ( $e ) use ( &$error ) {
			$error = is_wp_error( $e ) ? $e->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'wp_mail_failed', $catch );
		return (bool) $ok;
	}

	private static function duration( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 60 ) {
			/* translators: %s: number of seconds */
			return sprintf( __( '%ss', 'pinnaclequant-com-analytics' ), $seconds );
		}
		/* translators: 1: minutes, 2: seconds */
		return sprintf( __( '%1$sm %2$ss', 'pinnaclequant-com-analytics' ), (int) floor( $seconds / 60 ), $seconds % 60 );
	}

	private static function stat_cell( $label, $value, $previous, $before ) {
		$html  = '<td width="33%" valign="top" style="padding:10px 8px;">';
		$html .= '<div style="font-size:12px;color:#646970;margin-bottom:4px;">' . esc_html( $label ) . '</div>';
		$html .= '<div style="font-size:26px;line-height:1.1;font-weight:600;color:#1d2327;">' . esc_html( $value ) . '</div>';
		$html .= '<div style="font-size:12px;color:#646970;margin-top:4px;">' . esc_html( $before ) . ' ' . esc_html( $previous ) . '</div>';
		$html .= '</td>';
		return $html;
	}

	private static function list_table( $title, $rows, $col, $total ) {
		if ( empty( $rows ) ) {
			return '';
		}
		$html  = '<tr><td style="padding:22px 24px 0;">';
		$html .= '<div style="font-size:15px;font-weight:600;color:#1d2327;margin-bottom:8px;">' . esc_html( $title ) . '</div>';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">';
		foreach ( $rows as $row ) {
			$share = $total > 0 ? round( 100 * $row['n'] / $total ) . '%' : '';
			$html .= '<tr>';
			$html .= '<td style="padding:6px 0;border-top:1px solid #e6e8ea;color:#1d2327;word-break:break-all;">' . esc_html( $row['label'] ) . '</td>';
			$html .= '<td width="70" align="right" style="padding:6px 0 6px 10px;border-top:1px solid #e6e8ea;color:#1d2327;white-space:nowrap;">' . esc_html( number_format_i18n( $row['n'] ) ) . '</td>';
			$html .= '<td width="46" align="right" style="padding:6px 0 6px 10px;border-top:1px solid #e6e8ea;color:#646970;white-space:nowrap;">' . esc_html( $share ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</table>';
		$html .= '<div style="font-size:11px;color:#8c8f94;margin-top:4px;">' . esc_html( $col ) . '</div>';
		$html .= '</td></tr>';
		return $html;
	}

	public static function html( $period, $data, $site ) {
		$now    = $data['now'];
		$prev   = $data['prev'];
		$weekly = ( 'weekly' === $period['kind'] );
		$before = $weekly ? __( 'week before:', 'pinnaclequant-com-analytics' ) : __( 'day before:', 'pinnaclequant-com-analytics' );
		$host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$admin  = admin_url( 'admin.php?page=' . PQAnalytics_Admin::SLUG_VISITORS );
		$tz     = PQAnalytics_Stats::tz_label();
		$title  = __( 'Your Analytics Report', 'pinnaclequant-com-analytics' );

		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . esc_html( $title ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f0f0f1;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f0f1;"><tr><td align="center" style="padding:20px 10px;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:12px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">';

		$html .= '<tr><td style="padding:26px 24px 0;">';
		$html .= '<div style="font-size:22px;font-weight:600;color:#1d2327;">' . esc_html( $title ) . '</div>';
		$html .= '<div style="font-size:14px;color:#50575e;margin-top:6px;">' . esc_html( $site ) . '</div>';
		$html .= '<div style="font-size:14px;color:#50575e;margin-top:2px;">' . esc_html( $period['label'] ) . '</div>';
		$html .= '</td></tr>';

		if ( 0 === (int) $now['visits'] && 0 === (int) $now['bots'] ) {
			$html .= '<tr><td style="padding:22px 24px;font-size:14px;color:#50575e;">' . esc_html__( 'No visits in this period.', 'pinnaclequant-com-analytics' ) . '</td></tr>';
		} else {
			$html .= '<tr><td style="padding:14px 16px 0;">';
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">';
			$html .= '<tr>';
			$html .= self::stat_cell( __( 'Visitors', 'pinnaclequant-com-analytics' ), number_format_i18n( $now['visitors'] ), number_format_i18n( $prev['visitors'] ), $before );
			$html .= self::stat_cell( __( 'Page views', 'pinnaclequant-com-analytics' ), number_format_i18n( $now['views'] ), number_format_i18n( $prev['views'] ), $before );
			$html .= self::stat_cell( __( 'Returning visitors', 'pinnaclequant-com-analytics' ), number_format_i18n( $now['returning'] ), number_format_i18n( $prev['returning'] ), $before );
			$html .= '</tr><tr>';
			$html .= self::stat_cell( __( 'Bounce rate', 'pinnaclequant-com-analytics' ), $now['bounce'] . '%', $prev['bounce'] . '%', $before );
			$html .= self::stat_cell( __( 'Average visit', 'pinnaclequant-com-analytics' ), self::duration( $now['seconds'] ), self::duration( $prev['seconds'] ), $before );
			$html .= self::stat_cell( __( 'Bots', 'pinnaclequant-com-analytics' ), number_format_i18n( $now['bots'] ), number_format_i18n( $prev['bots'] ), $before );
			$html .= '</tr></table>';
			$html .= '<div style="font-size:12px;color:#646970;padding:6px 8px 0;">' . esc_html__( 'Bounce rate is the share of visits that viewed one page and left within 10 seconds. Bots are not included in the other numbers.', 'pinnaclequant-com-analytics' ) . '</div>';
			$html .= '</td></tr>';

			$html .= self::list_table( __( 'Came from', 'pinnaclequant-com-analytics' ), $data['sources'], __( 'visitors and their share of all visitors', 'pinnaclequant-com-analytics' ), $now['visitors'] );
			$html .= self::list_table( __( 'Most read pages', 'pinnaclequant-com-analytics' ), $data['pages'], __( 'page views and their share of all page views', 'pinnaclequant-com-analytics' ), $now['views'] );
			$html .= self::list_table( __( 'Locations', 'pinnaclequant-com-analytics' ), $data['places'], __( 'visitors and their share of all visitors', 'pinnaclequant-com-analytics' ), $now['visitors'] );
			$html .= self::list_table( __( 'Screens', 'pinnaclequant-com-analytics' ), $data['screens'], __( 'visitors and their share of all visitors', 'pinnaclequant-com-analytics' ), $now['visitors'] );
		}

		$html .= '<tr><td style="padding:24px 24px 8px;">';
		$html .= '<a href="' . esc_url( $admin ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 18px;border-radius:6px;">' . esc_html__( 'Open the Visitors page', 'pinnaclequant-com-analytics' ) . '</a>';
		$html .= '</td></tr>';

		$html .= '<tr><td style="padding:12px 24px 26px;font-size:12px;line-height:1.5;color:#8c8f94;">';
		/* translators: 1: site host name, 2: timezone */
		$html .= esc_html( sprintf( __( 'This report comes from PinnacleQuant.com Analytics on %1$s. Times and days follow the site setting (%2$s). To change the day, time or address, or to stop these emails, open PinnacleQuant.com Analytics, Settings, in your WordPress admin.', 'pinnaclequant-com-analytics' ), $host, $tz ) );
		$html .= '</td></tr>';

		$html .= '</table></td></tr></table></body></html>';
		return $html;
	}
}
