<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Admin_API {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can( $request ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( preg_match( '#/admin/(block|unblock|note|geo/step|geo/reset|report/sample)$#', $route ) ) {
			return current_user_can( 'manage_options' );
		}
		return current_user_can( PQAnalytics_Admin::view_cap() );
	}

	public static function routes() {
		$routes = array(
			array( '/admin/overview', 'GET', 'overview' ),
			array( '/admin/visit/(?P<id>\d+)/path', 'GET', 'path' ),
			array( '/admin/visitor/(?P<vid>[a-f0-9]{32})/history', 'GET', 'history' ),
			array( '/admin/block', 'POST', 'block' ),
			array( '/admin/unblock', 'POST', 'unblock' ),
			array( '/admin/note', 'POST', 'note' ),
			array( '/admin/geo/step', 'POST', 'geo_step' ),
			array( '/admin/geo/reset', 'POST', 'geo_reset' ),
			array( '/admin/geo/status', 'GET', 'geo_status' ),
			array( '/admin/flow', 'GET', 'flow' ),
			array( '/admin/report/sample', 'POST', 'report_sample' ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				'pqanalytics/v1',
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( __CLASS__, $route[2] ),
					'permission_callback' => array( __CLASS__, 'can' ),
				)
			);
		}
	}

	public static function search_params( $source ) {
		$get = function ( $key ) use ( $source ) {
			if ( is_object( $source ) && method_exists( $source, 'get_param' ) ) {
				return $source->get_param( $key );
			}
			return isset( $source[ $key ] ) ? $source[ $key ] : null;
		};
		return array(
			'in'     => sanitize_key( (string) $get( 'in' ) ),
			'unique' => (int) $get( 'unique' ),
			'page1'  => sanitize_text_field( (string) $get( 'page1' ) ),
			'page2'  => sanitize_text_field( (string) $get( 'page2' ) ),
			'from'   => sanitize_text_field( (string) $get( 'from' ) ),
			'to'     => sanitize_text_field( (string) $get( 'to' ) ),
			'tfrom'  => sanitize_text_field( (string) $get( 'tfrom' ) ),
			'tto'    => sanitize_text_field( (string) $get( 'tto' ) ),
		);
	}

	public static function overview( $request ) {
		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$light  = (bool) $request->get_param( 'light' );
		$opts   = self::search_params( $request );

		$data = array(
			'cards'    => PQAnalytics_Stats::cards(),
			'visitors' => PQAnalytics_Stats::visitors( $filter, $search, $page, $opts ),
			'now'      => PQAnalytics_Stats::local( PQAnalytics_DB::now(), 'g:i:s A' ),
		);
		if ( ! $light ) {
			$data['hourly']  = PQAnalytics_Stats::hourly();
			$data['daily']   = PQAnalytics_Stats::daily( 30 );
			$data['screens'] = PQAnalytics_Stats::resolutions();
		}
		if ( $request->get_param( 'paths' ) ) {
			$data['paths'] = PQAnalytics_Stats::path_list();
		}
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function path( $request ) {
		return new WP_REST_Response( array( 'hits' => PQAnalytics_Stats::path( (int) $request['id'] ) ), 200 );
	}

	public static function history( $request ) {
		$vid  = sanitize_text_field( (string) $request['vid'] );
		$data = PQAnalytics_Stats::history( $vid );
		if ( null === $data ) {
			return new WP_Error( 'pqanalytics_bad_vid', __( 'Unknown visitor.', 'pinnaclequant-com-analytics' ), array( 'status' => 404 ) );
		}
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function block( $request ) {
		$ip     = sanitize_text_field( (string) $request->get_param( 'ip' ) );
		$note   = sanitize_text_field( (string) $request->get_param( 'note' ) );
		$result = PQAnalytics_Blocker::block( $ip, $note );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return new WP_REST_Response(
			array(
				'ok'      => 1,
				'ip'      => PQAnalytics_IP::normalize( $ip ),
				'blocked' => PQAnalytics_Blocker::blocked(),
			),
			200
		);
	}

	public static function unblock( $request ) {
		$ip = sanitize_text_field( (string) $request->get_param( 'ip' ) );
		PQAnalytics_Blocker::unblock( $ip );
		return new WP_REST_Response(
			array(
				'ok'      => 1,
				'blocked' => PQAnalytics_Blocker::blocked(),
			),
			200
		);
	}

	public static function note( $request ) {
		$vid  = sanitize_text_field( (string) $request->get_param( 'vid' ) );
		$note = sanitize_text_field( (string) $request->get_param( 'note' ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $vid ) ) {
			return new WP_Error( 'pqanalytics_bad_vid', __( 'Unknown visitor.', 'pinnaclequant-com-analytics' ), array( 'status' => 400 ) );
		}
		PQAnalytics_DB::set_label( $vid, $note );
		return new WP_REST_Response( array( 'ok' => 1, 'note' => PQAnalytics_DB::cut( $note, 60 ) ), 200 );
	}

	public static function geo_step( $request ) {
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		PQAnalytics_Geo::enable();
		$result           = PQAnalytics_Geo::step( $kind );
		if ( ! $result['done'] && '' === $result['error'] ) {
			PQAnalytics_Cron::schedule_finish();
		}
		$result['status'] = PQAnalytics_Geo::status();
		return new WP_REST_Response( $result, 200 );
	}

	public static function geo_reset( $request ) {
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		PQAnalytics_Geo::reset( $kind );
		return new WP_REST_Response( array( 'status' => PQAnalytics_Geo::status() ), 200 );
	}

	public static function flow( $request ) {
		$range = sanitize_key( (string) $request->get_param( 'range' ) );
		$from  = PQAnalytics_DB::cut( wp_strip_all_tags( (string) $request->get_param( 'from' ) ), 500 );
		$response = new WP_REST_Response( PQAnalytics_Stats::flow( $range, $from ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function report_sample( $request ) {
		$kind = sanitize_key( (string) $request->get_param( 'report' ) );
		$kind = ( 'weekly' === $kind ) ? 'weekly' : 'daily';
		$to   = PQAnalytics_Report::recipients( sanitize_text_field( (string) $request->get_param( 'to' ) ), false );
		if ( empty( $to ) ) {
			return new WP_Error( 'pqanalytics_no_email', __( 'Enter a valid email address in Send to first.', 'pinnaclequant-com-analytics' ), array( 'status' => 400 ) );
		}
		$error = '';
		$ok    = PQAnalytics_Report::send( $kind, $to, time(), $error );
		if ( ! $ok ) {
			$message = __( 'WordPress could not send the email.', 'pinnaclequant-com-analytics' );
			if ( '' !== $error ) {
				$message .= ' ' . $error;
			}
			return new WP_Error( 'pqanalytics_mail_failed', $message, array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'ok' => 1, 'to' => implode( ', ', $to ) ), 200 );
	}

	public static function geo_status() {
		return new WP_REST_Response( array( 'status' => PQAnalytics_Geo::status() ), 200 );
	}
}
