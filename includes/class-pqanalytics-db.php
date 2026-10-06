<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_DB {

	public static function table( $key ) {
		global $wpdb;
		return $wpdb->prefix . 'pqanalytics_' . $key;
	}

	public static function fs() {
		static $fs = null;
		if ( null === $fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$fs = new WP_Filesystem_Direct( false );
		}
		return $fs;
	}

	public static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public static function migrate_names() {
		global $wpdb;
		if ( false !== get_option( 'pqanalytics_db_version' ) || false === get_option( 'pqa_db_version' ) ) {
			return;
		}
		foreach ( array( 'visitors', 'visits', 'hits', 'blocked' ) as $key ) {
			$old = $wpdb->prefix . 'pqa_' . $key;
			$new = self::table( $key );
			if ( self::table_exists( $old ) && ! self::table_exists( $new ) ) {
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old, $new ) );
			}
		}
		$options = array(
			'pqa_settings'      => array( 'pqanalytics_settings', true ),
			'pqa_show_setup'    => array( 'pqanalytics_show_setup', true ),
			'pqa_geo_info'      => array( 'pqanalytics_geo_info', false ),
			'pqa_blocked_cache' => array( 'pqanalytics_blocked_cache', true ),
		);
		foreach ( $options as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value && false !== $value ) {
				update_option( $new[0], $value, $new[1] );
			}
			delete_option( $old );
		}
		foreach ( array( 'pqa_geo_dl', 'pqa_geo_tries_city', 'pqa_geo_tries_asn' ) as $old ) {
			delete_option( $old );
		}
		foreach ( array( 'pqa_daily_prune', 'pqa_geo_refresh', 'pqa_geo_resume', 'pqa_geo_auto' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		foreach ( array( 'pqa_geo_lock_city', 'pqa_geo_lock_asn', 'pqa_geo_url_city', 'pqa_geo_url_asn' ) as $transient ) {
			delete_transient( $transient );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_pqa_rl_' ) . '%', $wpdb->esc_like( '_transient_timeout_pqa_rl_' ) . '%' ) );
		$upload  = wp_upload_dir();
		$old_dir = trailingslashit( $upload['basedir'] ) . 'pqa-geo';
		$new_dir = trailingslashit( $upload['basedir'] ) . 'pqanalytics-geo';
		if ( is_dir( $old_dir ) && ! is_dir( $new_dir ) ) {
			self::fs()->move( $old_dir, $new_dir );
		}
		delete_option( 'pqa_db_version' );
	}

	public static function cleanup_stale_names() {
		global $wpdb;
		if ( false === get_option( 'pqa_db_version' ) ) {
			return;
		}
		foreach ( array( 'hits', 'visits', 'visitors', 'blocked' ) as $key ) {
			$old = $wpdb->prefix . 'pqa_' . $key;
			if ( self::table_exists( $old ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $old ) ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $old ) );
			}
		}
		foreach ( array( 'pqa_settings', 'pqa_show_setup', 'pqa_geo_info', 'pqa_blocked_cache', 'pqa_geo_dl', 'pqa_geo_tries_city', 'pqa_geo_tries_asn', 'pqa_db_version' ) as $old ) {
			delete_option( $old );
		}
		foreach ( array( 'pqa_daily_prune', 'pqa_geo_refresh', 'pqa_geo_resume', 'pqa_geo_auto' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$visitors = self::table( 'visitors' );
		$visits   = self::table( 'visits' );
		$hits     = self::table( 'hits' );
		$blocked  = self::table( 'blocked' );

		$sql = "CREATE TABLE {$visitors} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  vid char(32) NOT NULL,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  visits int(10) unsigned NOT NULL DEFAULT 1,
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  notes text NULL,
  label varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY vid (vid),
  KEY last_seen (last_seen),
  KEY email (email)
) {$charset};
CREATE TABLE {$visits} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visitor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visit_no int(10) unsigned NOT NULL DEFAULT 1,
  member varchar(120) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  started datetime NOT NULL,
  last_beat datetime NOT NULL,
  pages int(10) unsigned NOT NULL DEFAULT 1,
  entry_url varchar(1000) NOT NULL DEFAULT '',
  last_url varchar(1000) NOT NULL DEFAULT '',
  last_title varchar(255) NOT NULL DEFAULT '',
  referrer varchar(1000) NOT NULL DEFAULT '',
  source varchar(40) NOT NULL DEFAULT '',
  source_type varchar(20) NOT NULL DEFAULT '',
  click_id_type varchar(20) NOT NULL DEFAULT '',
  keyword varchar(255) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(150) NOT NULL DEFAULT '',
  country_code char(2) NOT NULL DEFAULT '',
  country varchar(80) NOT NULL DEFAULT '',
  region varchar(100) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  postal varchar(20) NOT NULL DEFAULT '',
  isp varchar(150) NOT NULL DEFAULT '',
  asn int(10) unsigned NOT NULL DEFAULT 0,
  screen_w smallint(5) unsigned NOT NULL DEFAULT 0,
  screen_h smallint(5) unsigned NOT NULL DEFAULT 0,
  dpr decimal(6,4) NOT NULL DEFAULT 1.0000,
  viewport_w smallint(5) unsigned NOT NULL DEFAULT 0,
  viewport_h smallint(5) unsigned NOT NULL DEFAULT 0,
  screen_class varchar(16) NOT NULL DEFAULT '',
  browser varchar(60) NOT NULL DEFAULT '',
  browser_ver varchar(20) NOT NULL DEFAULT '',
  os varchar(60) NOT NULL DEFAULT '',
  language varchar(16) NOT NULL DEFAULT '',
  ua varchar(500) NOT NULL DEFAULT '',
  is_bot tinyint(1) NOT NULL DEFAULT 0,
  bot_name varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY visitor_id (visitor_id),
  KEY started (started),
  KEY last_beat (last_beat),
  KEY ip (ip),
  KEY is_bot (is_bot)
) {$charset};
CREATE TABLE {$hits} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visit_id bigint(20) unsigned NOT NULL,
  url varchar(1000) NOT NULL DEFAULT '',
  path varchar(500) NOT NULL DEFAULT '',
  prev_path varchar(500) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  hit_time datetime NOT NULL,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY visit_id (visit_id),
  KEY hit_time (hit_time),
  KEY path (path(191))
) {$charset};
CREATE TABLE {$blocked} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ip varchar(45) NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ip (ip)
) {$charset};";

		dbDelta( $sql );
		update_option( 'pqanalytics_db_version', PQANALYTICS_DB_VERSION );
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function cut( $value, $max ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	public static function update_visitor_contact( $vid, $name = null, $email = null, $phone = null, $notes = null ) {
		global $wpdb;
		$vid = (string) $vid;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $vid ) ) {
			return false;
		}
		$data    = array();
		$formats = array();
		if ( null !== $name ) {
			$data['name'] = self::cut( sanitize_text_field( $name ), 120 );
			$formats[]    = '%s';
		}
		if ( null !== $email ) {
			$data['email'] = self::cut( sanitize_email( $email ), 190 );
			$formats[]     = '%s';
		}
		if ( null !== $phone ) {
			$data['phone'] = self::cut( preg_replace( '/[^0-9+()\-\s.]/', '', (string) $phone ), 40 );
			$formats[]     = '%s';
		}
		if ( null !== $notes ) {
			$data['notes'] = self::cut( sanitize_textarea_field( $notes ), 2000 );
			$formats[]     = '%s';
		}
		if ( empty( $data ) ) {
			return false;
		}
		$table = self::table( 'visitors' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE vid = %s', $table, $vid ) );
		if ( ! $exists ) {
			$now = self::now();
			$wpdb->insert(
				$table,
				array_merge(
					array(
						'vid'        => $vid,
						'first_seen' => $now,
						'last_seen'  => $now,
						'visits'     => 1,
					),
					$data
				)
			);
			return (bool) $wpdb->insert_id;
		}
		return false !== $wpdb->update( $table, $data, array( 'vid' => $vid ), $formats, array( '%s' ) );
	}

	public static function set_label( $vid, $label ) {
		global $wpdb;
		$vid = (string) $vid;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $vid ) ) {
			return false;
		}
		$label = self::cut( sanitize_text_field( (string) $label ), 60 );
		$table = self::table( 'visitors' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE vid = %s', $table, $vid ) );
		if ( ! $id ) {
			$now = self::now();
			$wpdb->insert(
				$table,
				array(
					'vid'        => $vid,
					'first_seen' => $now,
					'last_seen'  => $now,
					'visits'     => 1,
					'label'      => $label,
				),
				array( '%s', '%s', '%s', '%d', '%s' )
			);
			return (bool) $wpdb->insert_id;
		}
		return false !== $wpdb->update( $table, array( 'label' => $label ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	public static function clean_title( $title ) {
		$title = trim( (string) $title );
		$title = preg_replace( '/^\s*[|\-\x{2013}\x{2014}:\x{00bb}]+\s*/u', '', $title );
		return is_string( $title ) ? trim( $title ) : '';
	}

	public static function path_of( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = ( is_array( $parts ) && isset( $parts['path'] ) && '' !== $parts['path'] ) ? $parts['path'] : '/';
		$path  = rtrim( $path, '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( is_array( $parts ) && ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$keep = array();
			foreach ( array( 'p', 'page_id', 'cat', 'tag' ) as $key ) {
				if ( isset( $query[ $key ] ) && is_scalar( $query[ $key ] ) && '' !== $query[ $key ] ) {
					$keep[] = $key . '=' . rawurlencode( (string) $query[ $key ] );
				}
			}
			if ( ! empty( $keep ) ) {
				$path .= '?' . implode( '&', $keep );
			}
		}
		return self::cut( $path, 500 );
	}

	public static function visitor_by_vid( $vid ) {
		global $wpdb;
		$vid = (string) $vid;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $vid ) ) {
			return null;
		}
		$table = self::table( 'visitors' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE vid = %s', $table, $vid ), ARRAY_A );
		return $row ? $row : null;
	}
}
