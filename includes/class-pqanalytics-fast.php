<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers the plugin's most frequent REST calls as soon as all plugins are
 * loaded, before the theme, the init hooks and the full REST router run.
 *
 * Only three routes are answered here: the page signal (hit), the heartbeat
 * (beat) and the Visitors page refresh (admin/overview). For these, a
 * WP_REST_Server is created with just the plugin's own routes registered,
 * WordPress's default REST filters are installed (rest_api_default_filters),
 * and the request is then served the way WP_REST_Server::serve_request()
 * serves every REST call: the same headers, check_authentication() with
 * WordPress's own cookie and nonce check, WordPress's own dispatch() with
 * rest_pre_dispatch, route matching, parameter validation, the permission
 * callback and the route callback, then rest_post_dispatch, the no-cache
 * headers, rest_pre_serve_request and rest_pre_echo_response.
 *
 * What is skipped is everything WordPress loads between plugins_loaded and
 * the REST router: the theme, every plugin's init work, rewrite rules and the
 * registration of every other plugin's REST routes. A hook another plugin
 * adds during init or rest_api_init is therefore not seen here. A request
 * that does not match one of the three routes exactly is left to WordPress
 * untouched.
 */
class PQAnalytics_Fast {

	const NAMESPACE_SLUG = 'pqanalytics/v1';

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_serve' ), 99 );
	}

	/**
	 * Route => array( methods, callback, permission_callback ), in the form
	 * register_rest_route() stores them.
	 */
	private static function routes() {
		return array(
			'/hit'            => array( 'POST', array( 'PQAnalytics_Visits', 'rest_hit' ), '__return_true' ),
			'/beat'           => array( 'POST', array( 'PQAnalytics_Visits', 'rest_beat' ), '__return_true' ),
			'/admin/overview' => array( 'GET', array( 'PQAnalytics_Admin_API', 'overview' ), array( 'PQAnalytics_Admin_API', 'can' ) ),
		);
	}

	public static function maybe_serve() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( (string) $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method && 'GET' !== $method ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) || defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) || defined( 'WP_INSTALLING' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return;
		}
		if ( get_option( 'pqanalytics_db_version' ) !== PQANALYTICS_DB_VERSION ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Route matching only; the REST nonce is checked by WordPress's own rest_cookie_check_errors() during check_authentication().
		if ( isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) || isset( $_GET['_method'] ) || isset( $_GET['_envelope'] ) || isset( $_GET['_jsonp'] ) || isset( $_GET['_fields'] ) || isset( $_GET['_embed'] ) || isset( $_GET['_pretty'] ) ) {
			return;
		}
		// phpcs:enable
		foreach ( array( 'WP_REST_Server', 'WP_REST_Request', 'WP_REST_Response', 'WP_Error' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return;
			}
		}
		foreach ( array( 'rest_ensure_response', 'rest_convert_error_to_response', 'rest_api_default_filters', 'rest_get_url_prefix', 'wp_get_nocache_headers', 'status_header' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return;
			}
		}
		$route  = self::rest_route();
		$routes = self::routes();
		if ( '' === $route || ! isset( $routes[ $route ] ) || $routes[ $route ][0] !== $method ) {
			return;
		}
		self::serve( $method, '/' . self::NAMESPACE_SLUG . $route );
	}

	/**
	 * The part of the request path after the namespace, for both URL forms
	 * (/wp-json/pqanalytics/v1/... and ?rest_route=/pqanalytics/v1/...).
	 */
	private static function rest_route() {
		$ns = '/' . self::NAMESPACE_SLUG;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Route matching only.
		if ( isset( $_GET['rest_route'] ) ) {
			$rr = '/' . trim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' );
			return preg_match( '#^' . preg_quote( $ns, '#' ) . '(/.+)$#', $rr, $m ) ? rtrim( $m[1], '/' ) : '';
		}
		// phpcs:enable
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return '';
		}
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		if ( ! preg_match( '#/' . preg_quote( rest_get_url_prefix(), '#' ) . preg_quote( $ns, '#' ) . '(/.+)$#', $path, $m ) ) {
			return '';
		}
		return rtrim( $m[1], '/' );
	}

	/**
	 * From here on the request is answered here and the script ends.
	 */
	private static function serve( $method, $route ) {
		// What WordPress sets up between plugins_loaded and the REST router that the
		// callbacks, filters and core REST functions rely on.
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
		if ( function_exists( 'wp_functionality_constants' ) ) {
			wp_functionality_constants();
		}
		if ( function_exists( 'wp_magic_quotes' ) ) {
			wp_magic_quotes();
		}
		if ( empty( $GLOBALS['wp_rewrite'] ) && class_exists( 'WP_Rewrite' ) ) {
			$GLOBALS['wp_rewrite'] = new WP_Rewrite();
		}

		// A server with only this plugin's routes, plus WordPress's default REST filters.
		$server                    = new WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		rest_api_default_filters();
		foreach ( self::routes() as $path => $def ) {
			$server->register_route(
				self::NAMESPACE_SLUG,
				'/' . self::NAMESPACE_SLUG . $path,
				array(
					array(
						'methods'             => $def[0],
						'callback'            => $def[1],
						'permission_callback' => $def[2],
						'args'                => array(),
					),
				)
			);
		}

		// During init, WP::init() determines the current user from the cookies; the REST
		// cookie check relies on that having happened, so it is done here first.
		if ( function_exists( 'wp_get_current_user' ) ) {
			wp_get_current_user();
		}

		// WP_REST_Server::serve_request(), step by step.
		global $current_user;
		if ( $current_user instanceof WP_User && ! $current_user->exists() ) {
			$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Same reset WordPress makes at the start of serve_request().
		}

		$server->send_header( 'Content-Type', 'application/json; charset=' . get_option( 'blog_charset' ) );
		$server->send_header( 'X-Robots-Tag', 'noindex' );
		if ( function_exists( 'get_rest_url' ) && ! empty( $GLOBALS['wp_rewrite'] ) ) {
			$api_root = get_rest_url();
			if ( ! empty( $api_root ) ) {
				$server->send_header( 'Link', '<' . sanitize_url( $api_root ) . '>; rel="https://api.w.org/"' );
			}
		}
		$server->send_header( 'X-Content-Type-Options', 'nosniff' );
		$server->send_header( 'Access-Control-Expose-Headers', 'X-WP-Total, X-WP-TotalPages, Link' );
		$server->send_header( 'Access-Control-Allow-Headers', 'Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type' );

		$request = new WP_REST_Request( $method, $route );
		$request->set_query_params( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Same hand-over as WP_REST_Server::serve_request(); each callback sanitizes what it reads.
		$request->set_body_params( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- Same as above.
		$request->set_file_params( $_FILES );
		$request->set_headers( $server->get_headers( wp_unslash( $_SERVER ) ) );
		$request->set_body( WP_REST_Server::get_raw_data() );

		self::cron();

		$result = $server->check_authentication();
		if ( ! is_wp_error( $result ) ) {
			$result = $server->dispatch( $request );
		}
		$result = rest_ensure_response( $result );
		if ( is_wp_error( $result ) ) {
			$result = rest_convert_error_to_response( $result );
		}
		$result = apply_filters( 'rest_post_dispatch', rest_ensure_response( $result ), $server, $request );

		$server->send_headers( $result->get_headers() );
		status_header( $result->get_status() );

		if ( apply_filters( 'rest_send_nocache_headers', is_user_logged_in() ) ) {
			foreach ( wp_get_nocache_headers() as $header => $header_value ) {
				if ( empty( $header_value ) ) {
					$server->remove_header( $header );
				} else {
					$server->send_header( $header, $header_value );
				}
			}
		}

		$served = apply_filters( 'rest_pre_serve_request', false, $result, $request, $server );
		if ( ! $served ) {
			$data = $server->response_to_data( $result, false );
			$data = apply_filters( 'rest_pre_echo_response', $data, $server, $request );
			$json = wp_json_encode( $data, (int) apply_filters( 'rest_json_encode_options', 0, $request ) );
			if ( false === $json ) {
				status_header( 500 );
				$error = new WP_Error( 'rest_encode_error', json_last_error_msg(), array( 'status' => 500 ) );
				$json  = wp_json_encode( rest_convert_error_to_response( $error )->get_data() );
			}
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON reply, encoded by wp_json_encode().
		}
		exit;
	}

	/**
	 * WordPress starts due scheduled tasks from any uncached request during
	 * init, which these requests never reach, so the same call is made here,
	 * before any output. It reads one cached option and does nothing unless a
	 * task is due.
	 */
	private static function cron() {
		if ( ! defined( 'WP_CRON_LOCK_TIMEOUT' ) || ! function_exists( 'wp_cron' ) || ( function_exists( 'wp_installing' ) && wp_installing() ) ) {
			return;
		}
		wp_cron();
	}
}
