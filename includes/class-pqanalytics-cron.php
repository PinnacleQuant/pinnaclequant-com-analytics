<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Cron {

	const PRUNE_HOOK   = 'pqanalytics_daily_prune';
	const MORE_HOOK    = 'pqanalytics_prune_more';
	const REFRESH_HOOK = 'pqanalytics_geo_refresh';
	const RESUME_HOOK  = 'pqanalytics_geo_resume';
	const FINISH_HOOK  = 'pqanalytics_geo_finish';
	const BATCH        = 2000;
	const PRUNE_SECONDS = 20;

	public static function init() {
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
		add_action( self::MORE_HOOK, array( __CLASS__, 'prune' ) );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh' ) );
		add_action( self::RESUME_HOOK, array( __CLASS__, 'resume' ), 10, 2 );
		add_action( self::FINISH_HOOK, array( __CLASS__, 'finish' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
		if ( PQAnalytics_Geo::enabled() ) {
			self::schedule_refresh();
		}
		PQAnalytics_Report::schedule();
	}

	public static function schedule_refresh() {
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::REFRESH_HOOK );
		}
	}

	public static function schedule_finish() {
		if ( ! PQAnalytics_Geo::enabled() || ! PQAnalytics_Geo::pending() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::FINISH_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::FINISH_HOOK );
		}
	}

	public static function unschedule() {
		foreach ( array( self::PRUNE_HOOK, self::MORE_HOOK, self::REFRESH_HOOK, self::RESUME_HOOK, self::FINISH_HOOK, 'pqanalytics_geo_auto' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		PQAnalytics_Report::unschedule();
	}

	public static function prune() {
		PQAnalytics_DB::cleanup_stale_names();
		$days     = (int) PQAnalytics_Settings::get( 'retention_days' );
		$days     = $days > 0 ? $days : 90;
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deadline = time() + self::PRUNE_SECONDS;

		$done = self::prune_rows( PQAnalytics_DB::table( 'hits' ), 'hit_time', $cutoff, $deadline )
			&& self::prune_rows( PQAnalytics_DB::table( 'visits' ), 'started', $cutoff, $deadline )
			&& self::prune_visitors( $cutoff, $deadline );

		if ( ! $done && ! wp_next_scheduled( self::MORE_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::MORE_HOOK );
		}
	}

	private static function delete_ids( $table, $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( array( $table ), $ids )
			)
		);
	}

	private static function prune_rows( $table, $column, $cutoff, $deadline ) {
		global $wpdb;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE %i < %s LIMIT %d', $table, $column, $cutoff, self::BATCH ) );
			if ( empty( $ids ) ) {
				return true;
			}
			self::delete_ids( $table, $ids );
			if ( count( $ids ) < self::BATCH ) {
				return true;
			}
		} while ( time() < $deadline );
		return false;
	}

	private static function prune_visitors( $cutoff, $deadline ) {
		global $wpdb;
		$table = PQAnalytics_DB::table( 'visitors' );
		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE last_seen < %s AND name = '' AND email = '' AND phone = '' AND label = '' AND (notes IS NULL OR notes = '') LIMIT %d",
					$table,
					$cutoff,
					self::BATCH
				)
			);
			if ( empty( $ids ) ) {
				return true;
			}
			self::delete_ids( $table, $ids );
			if ( count( $ids ) < self::BATCH ) {
				return true;
			}
		} while ( time() < $deadline );
		return false;
	}

	public static function finish() {
		if ( ! PQAnalytics_Geo::enabled() ) {
			return;
		}
		foreach ( array( 'city', 'asn' ) as $kind ) {
			if ( PQAnalytics_Geo::has( $kind ) ) {
				continue;
			}
			self::run_download( $kind, true );
			return;
		}
	}

	public static function refresh() {
		if ( ! PQAnalytics_Geo::enabled() ) {
			return;
		}
		$info = PQAnalytics_Geo::info();
		foreach ( array( 'city', 'asn' ) as $kind ) {
			if ( ! PQAnalytics_Geo::has( $kind ) ) {
				continue;
			}
			$updated = isset( $info[ $kind ]['updated'] ) ? (int) $info[ $kind ]['updated'] : 0;
			if ( time() - $updated < 31 * DAY_IN_SECONDS ) {
				continue;
			}
			self::run_download( $kind );
			return;
		}
	}

	public static function resume( $kind, $chain = false ) {
		$kind = (string) $kind;
		if ( ! PQAnalytics_Geo::enabled() ) {
			return;
		}
		if ( PQAnalytics_Geo::has( $kind ) && ! PQAnalytics_Geo::in_progress( $kind ) ) {
			if ( $chain ) {
				self::schedule_finish();
			}
			return;
		}
		self::run_download( $kind, (bool) $chain );
	}

	private static function run_download( $kind, $chain = false ) {
		$deadline = time() + 40;
		$attempts = 0;
		do {
			$result = PQAnalytics_Geo::step( $kind );
			$attempts++;
			if ( ! empty( $result['busy'] ) ) {
				break;
			}
			if ( ! empty( $result['error'] ) ) {
				$tries = (int) get_option( 'pqanalytics_geo_tries_' . $kind, 0 ) + 1;
				update_option( 'pqanalytics_geo_tries_' . $kind, $tries, false );
				PQAnalytics_Geo::reset( $kind );
				if ( $chain && $tries < 10 && ! wp_next_scheduled( self::RESUME_HOOK, array( $kind, true ) ) ) {
					wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, self::RESUME_HOOK, array( $kind, true ) );
				}
				return;
			}
			if ( $result['done'] ) {
				delete_option( 'pqanalytics_geo_tries_' . $kind );
				if ( $chain ) {
					self::schedule_finish();
				}
				return;
			}
		} while ( time() < $deadline && $attempts < 30 );

		if ( ! wp_next_scheduled( self::RESUME_HOOK, array( $kind, $chain ) ) ) {
			wp_schedule_single_event( time() + ( ! empty( $result['busy'] ) ? 2 : 5 ) * MINUTE_IN_SECONDS, self::RESUME_HOOK, array( $kind, $chain ) );
		}
	}
}
