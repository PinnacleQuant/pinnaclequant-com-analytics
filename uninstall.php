<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function pqanalytics_uninstall() {
	global $wpdb;

	foreach ( array( 'hits', 'visits', 'visitors', 'blocked' ) as $key ) {
		foreach ( array( 'pqanalytics_', 'pqa_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $prefix . $key ) );
		}
	}

	foreach ( array( 'settings', 'db_version', 'show_setup', 'geo_dl', 'geo_info', 'geo_enabled', 'blocked_cache', 'geo_tries_city', 'geo_tries_asn', 'report_next', 'report_sent' ) as $option ) {
		delete_option( 'pqanalytics_' . $option );
		delete_option( 'pqa_' . $option );
	}

	foreach ( array( 'geo_lock_city', 'geo_lock_asn', 'geo_url_city', 'geo_url_asn' ) as $transient ) {
		delete_transient( 'pqanalytics_' . $transient );
		delete_transient( 'pqa_' . $transient );
	}
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_pqanalytics_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pqanalytics_rl_' ) . '%',
			$wpdb->esc_like( '_transient_pqa_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pqa_rl_' ) . '%'
		)
	);

	foreach ( array( 'daily_prune', 'geo_refresh', 'geo_resume', 'geo_auto', 'geo_finish', 'email_report' ) as $hook ) {
		wp_unschedule_hook( 'pqanalytics_' . $hook );
		wp_unschedule_hook( 'pqa_' . $hook );
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	$fs = new WP_Filesystem_Direct( false );
	$upload = wp_upload_dir();
	foreach ( array( 'pqanalytics-geo', 'pqa-geo' ) as $folder ) {
		$dir = trailingslashit( $upload['basedir'] ) . $folder;
		if ( is_dir( $dir ) ) {
			$fs->rmdir( $dir, true );
		}
	}
}

pqanalytics_uninstall();
