<?php
/**
 * Plugin Name:       PinnacleQuant.com Analytics
 * Plugin URI:        https://pinnaclequant.com/analytics-systems/
 * Description:       See who is on your site right now: IP address, city and state, ISP, screen size, browser, where they came from, returning visitors, the pages they read, and one-click blocking. Everything stays in your own database.
 * Version:           1.7.8
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            PinnacleQuant.com
 * Author URI:        https://profiles.wordpress.org/pinnaclequant/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pinnaclequant-com-analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PQANALYTICS_VERSION', '1.7.8' );
define( 'PQANALYTICS_DB_VERSION', '9' );
define( 'PQANALYTICS_FILE', __FILE__ );
define( 'PQANALYTICS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PQANALYTICS_URL', plugin_dir_url( __FILE__ ) );
define( 'PQANALYTICS_SESSION_MINUTES', 30 );
define( 'PQANALYTICS_LIVE_SECONDS', 60 );
define( 'PQANALYTICS_SAME_PAGE_SECONDS', 90 );
define( 'PQANALYTICS_SITE_URL', 'https://pinnaclequant.com/' );
define( 'PQANALYTICS_CHAT_PAGE_URL', 'https://pinnaclequant.com/analytics-systems' );
define( 'PQANALYTICS_CHAT_EMAIL', 'Info@PinnacleQuant.com' );
define( 'PQANALYTICS_REVIEW_URL', 'https://wordpress.org/support/plugin/pinnaclequant-com-analytics/reviews/' );

require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-db.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-settings.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-ip.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-ua.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-referrer.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-geo.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-blocker.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-visits.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-stats.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-admin.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-admin-api.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-cron.php';
require_once PQANALYTICS_DIR . 'includes/class-pqanalytics-report.php';

function pqanalytics_geo_state() {
	wp_unschedule_hook( 'pqanalytics_geo_auto' );
	if ( ! PQAnalytics_Geo::enabled() && ( PQAnalytics_Geo::has( 'city' ) || PQAnalytics_Geo::has( 'asn' ) ) ) {
		update_option( 'pqanalytics_geo_enabled', 1, false );
	}
	if ( ! PQAnalytics_Geo::enabled() ) {
		wp_unschedule_hook( PQAnalytics_Cron::REFRESH_HOOK );
	}
}

function pqanalytics_install_site() {
	PQAnalytics_DB::migrate_names();
	PQAnalytics_DB::install();
	PQAnalytics_Settings::install_defaults();
	PQAnalytics_Blocker::rebuild_cache();
	PQAnalytics_Geo::ensure_dir();
	pqanalytics_geo_state();
	PQAnalytics_Cron::schedule();
	update_option( 'pqanalytics_show_setup', 1 );
}

function pqanalytics_upgrade_site() {
	PQAnalytics_DB::migrate_names();
	PQAnalytics_DB::install();
	PQAnalytics_Settings::install_defaults();
	PQAnalytics_Blocker::rebuild_cache();
	pqanalytics_geo_state();
	if ( did_action( 'init' ) ) {
		PQAnalytics_Cron::schedule();
	} else {
		add_action( 'init', array( 'PQAnalytics_Cron', 'schedule' ), 1 );
	}
}

function pqanalytics_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			pqanalytics_install_site();
			restore_current_blog();
		}
		return;
	}
	pqanalytics_install_site();
}
register_activation_hook( __FILE__, 'pqanalytics_activate' );

function pqanalytics_deactivate() {
	PQAnalytics_Cron::unschedule();
}
register_deactivation_hook( __FILE__, 'pqanalytics_deactivate' );

function pqanalytics_init() {
	if ( get_option( 'pqanalytics_db_version' ) !== PQANALYTICS_DB_VERSION ) {
		pqanalytics_upgrade_site();
	}
	PQAnalytics_Blocker::init();
	PQAnalytics_Visits::init();
	PQAnalytics_Cron::init();
	PQAnalytics_Report::init();
	if ( is_admin() ) {
		PQAnalytics_Admin::init();
	}
	PQAnalytics_Admin_API::init();
}
add_action( 'plugins_loaded', 'pqanalytics_init' );
