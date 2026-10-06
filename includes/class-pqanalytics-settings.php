<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Settings {

	const OPTION = 'pqanalytics_settings';

	public static function defaults() {
		return array(
			'ip_source'         => 'auto',
			'exclude_logged_in' => 1,
			'editors_view'      => 0,
			'own_ips'           => '',
			'retention_days'    => 90,
			'beat_seconds'      => 20,
			'report'            => 'off',
			'report_hour'       => 7,
			'report_day'        => 1,
			'report_to'         => '',
			'timezone'          => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function install_defaults() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	public static function sanitize( $input ) {
		$out   = self::defaults();
		$input = is_array( $input ) ? $input : array();

		$sources = array( 'auto', 'remote', 'cf', 'xff', 'xreal' );
		if ( isset( $input['ip_source'] ) && in_array( $input['ip_source'], $sources, true ) ) {
			$out['ip_source'] = $input['ip_source'];
		}

		$out['exclude_logged_in'] = empty( $input['exclude_logged_in'] ) ? 0 : 1;
		$out['editors_view']      = empty( $input['editors_view'] ) ? 0 : 1;

		$ips = array();
		if ( isset( $input['own_ips'] ) ) {
			$parts = preg_split( '/[\s,;]+/', (string) $input['own_ips'] );
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' !== $part && PQAnalytics_IP::is_valid( $part ) ) {
					$ips[] = $part;
				}
			}
		}
		$out['own_ips'] = implode( ', ', array_unique( $ips ) );

		$days = isset( $input['retention_days'] ) ? (int) $input['retention_days'] : 90;
		if ( $days < 1 ) {
			$days = 1;
		}
		if ( $days > 3650 ) {
			$days = 3650;
		}
		$out['retention_days'] = $days;

		$beat = isset( $input['beat_seconds'] ) ? (int) $input['beat_seconds'] : 20;
		$out['beat_seconds'] = max( 10, min( 120, $beat ) );

		$reports       = array( 'off', 'daily', 'weekly' );
		$out['report'] = ( isset( $input['report'] ) && in_array( $input['report'], $reports, true ) ) ? $input['report'] : 'off';
		$hour          = isset( $input['report_hour'] ) ? (int) $input['report_hour'] : 7;
		$out['report_hour'] = max( 0, min( 23, $hour ) );
		$day           = isset( $input['report_day'] ) ? (int) $input['report_day'] : 1;
		$out['report_day']  = max( 1, min( 7, $day ) );
		$out['report_to']   = implode( ', ', PQAnalytics_Report::recipients( isset( $input['report_to'] ) ? (string) $input['report_to'] : '', false ) );

		$tz = isset( $input['timezone'] ) ? trim( (string) $input['timezone'] ) : '';
		if ( '' !== $tz && ! in_array( $tz, timezone_identifiers_list(), true ) && ! preg_match( '/^UTC[+-]\d{1,2}(\.\d+)?$/', $tz ) ) {
			$tz = '';
		}
		$out['timezone'] = $tz;

		return $out;
	}

	public static function own_ips() {
		$raw = (string) self::get( 'own_ips' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$parts = preg_split( '/[\s,;]+/', $raw );
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}
}
