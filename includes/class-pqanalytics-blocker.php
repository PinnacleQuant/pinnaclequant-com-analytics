<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Blocker {

	const CACHE_OPTION = 'pqanalytics_blocked_cache';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'enforce' ), 1 );
	}

	public static function blocked() {
		$cache = get_option( self::CACHE_OPTION, null );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		return self::read_list();
	}

	private static function read_list() {
		global $wpdb;
		$table = PQAnalytics_DB::table( 'blocked' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT ip, note FROM %i ORDER BY created DESC', $table ), ARRAY_A );
		$list  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$list[ $row['ip'] ] = (string) $row['note'];
			}
		}
		return $list;
	}

	public static function rebuild_cache() {
		$list = self::read_list();
		update_option( self::CACHE_OPTION, $list, true );
		return $list;
	}

	public static function is_blocked( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$list = self::blocked();
		return isset( $list[ $ip ] );
	}

	public static function block( $ip, $note = '' ) {
		global $wpdb;
		$ip = PQAnalytics_IP::normalize( $ip );
		if ( '' === $ip ) {
			return new WP_Error( 'pqanalytics_bad_ip', __( 'That is not a valid IP address.', 'pinnaclequant-com-analytics' ) );
		}
		if ( $ip === PQAnalytics_IP::current() ) {
			return new WP_Error( 'pqanalytics_own_ip', __( 'That is your own IP address. Blocking it would lock you out.', 'pinnaclequant-com-analytics' ) );
		}
		if ( ! PQAnalytics_IP::is_public( $ip ) ) {
			return new WP_Error( 'pqanalytics_private_ip', __( 'Private and reserved addresses cannot be blocked.', 'pinnaclequant-com-analytics' ) );
		}
		$table = PQAnalytics_DB::table( 'blocked' );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (ip, note, created) VALUES (%s, %s, %s)',
				$table,
				$ip,
				PQAnalytics_DB::cut( sanitize_text_field( $note ), 255 ),
				PQAnalytics_DB::now()
			)
		);
		self::rebuild_cache();
		return true;
	}

	public static function unblock( $ip ) {
		global $wpdb;
		$ip = PQAnalytics_IP::normalize( $ip );
		if ( '' === $ip ) {
			return false;
		}
		$wpdb->delete( PQAnalytics_DB::table( 'blocked' ), array( 'ip' => $ip ), array( '%s' ) );
		self::rebuild_cache();
		return true;
	}

	public static function enforce() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$list = self::blocked();
		if ( empty( $list ) ) {
			return;
		}
		$ip = PQAnalytics_IP::current();
		if ( '' === $ip || ! isset( $list[ $ip ] ) ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}
		nocache_headers();
		wp_die(
			esc_html__( 'Access denied.', 'pinnaclequant-com-analytics' ),
			esc_html__( 'Forbidden', 'pinnaclequant-com-analytics' ),
			array( 'response' => 403 )
		);
	}
}
