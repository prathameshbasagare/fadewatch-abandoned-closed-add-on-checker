<?php
/**
 * Background scanning: queue, batches, schedules and change detection.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 *
 * All HTTP requests happen here, from WP-Cron, from "Check now" (AJAX)
 * or from the upgrader hooks. Never while an admin page renders.
 */
final class Fadewatch_Scanner {

	const BATCH_SIZE = 10;

	/** Stop a batch early after this many seconds to stay below PHP time limits. */
	const TIME_BUDGET = 15;

	/** After this many consecutive failures, stop the 6-hourly retries. */
	const MAX_FAST_RETRIES = 3;

	/** Fallback full scan when the last one is older than this. */
	const FALLBACK_AFTER = 2 * DAY_IN_SECONDS;

	const HOOK_DAILY   = 'fadewatch_daily_scan';
	const HOOK_QUEUE   = 'fadewatch_process_queue';
	const HOOK_RECHECK = 'fadewatch_recheck';
	const HOOK_WEEKLY  = 'fadewatch_weekly_summary';

	/**
	 * Registers hooks.
	 */
	public static function register_hooks() {
		add_action( self::HOOK_DAILY, array( __CLASS__, 'cron_full_scan' ) );
		add_action( self::HOOK_QUEUE, array( __CLASS__, 'cron_process' ) );
		add_action( self::HOOK_RECHECK, array( __CLASS__, 'recheck_problem_items' ) );
		add_action( self::HOOK_WEEKLY, array( 'Fadewatch_Notifier', 'send_weekly_summary' ) );

		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_activated' ) );
		add_action( 'deleted_plugin', array( __CLASS__, 'on_deleted' ), 10, 2 );

		add_action( 'admin_init', array( __CLASS__, 'ensure_schedules' ) );
		add_action( 'load-plugins.php', array( __CLASS__, 'maybe_fallback_scan' ) );
	}

	/**
	 * Adds a 6-hour cron interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['fadewatch_six_hours'] ) ) {
			$schedules['fadewatch_six_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				// Avoid loading translations before `init` if another plugin asks early.
				'display'  => did_action( 'init' ) ? __( 'Every 6 hours', 'fadewatch-abandoned-closed-add-on-checker' ) : 'Every 6 hours',
			);
		}
		return $schedules;
	}

	/**
	 * Schedules recurring events. On multisite they live on the main site
	 * only, so a network does one shared scan instead of one per site.
	 */
	public static function ensure_schedules() {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
		}
		if ( ! wp_next_scheduled( self::HOOK_RECHECK ) ) {
			wp_schedule_event( time() + 6 * HOUR_IN_SECONDS, 'fadewatch_six_hours', self::HOOK_RECHECK );
		}
		if ( ! wp_next_scheduled( self::HOOK_WEEKLY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::HOOK_WEEKLY );
		}
	}

	/**
	 * Removes every scheduled event of this plugin.
	 */
	public static function clear_schedules() {
		foreach ( array( self::HOOK_DAILY, self::HOOK_QUEUE, self::HOOK_RECHECK, self::HOOK_WEEKLY ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Daily cron entry point.
	 */
	public static function cron_full_scan() {
		if ( Fadewatch_Settings::scheduled_scans_paused() ) {
			return;
		}
		self::start_full_scan( 'cron' );
	}

	/**
	 * Queues every checkable plugin and starts processing.
	 *
	 * @param string $source Where the scan was started from (for the action hook).
	 * @return array Progress.
	 */
	public static function start_full_scan( $source = 'manual' ) {
		// Opt-in: never contact WordPress.org before an administrator allows it.
		if ( ! Fadewatch_Settings::checks_allowed() ) {
			return self::progress( Fadewatch_Store::state(), false, true );
		}

		$files = Fadewatch_Resolver::checkable_files();

		self::prune_results( Fadewatch_Resolver::get_items() );

		Fadewatch_Store::save_state(
			array(
				'queue'      => $files,
				'total'      => count( $files ),
				'done'       => 0,
				'full'       => true,
				'started_at' => time(),
			)
		);

		/**
		 * Fires when a full scan is queued.
		 *
		 * @param string $source activation | cron | manual | fallback | settings | consent.
		 */
		do_action( 'fadewatch_scan_started', $source );

		if ( empty( $files ) ) {
			self::finalize( Fadewatch_Store::state() );
		} else {
			self::schedule_processing( 0 );
		}

		return self::progress( Fadewatch_Store::state() );
	}

	/**
	 * Adds plugins to the queue (e.g. after install or update).
	 *
	 * @param string[] $files Plugin files.
	 */
	public static function enqueue( array $files ) {
		if ( ! Fadewatch_Settings::checks_allowed() ) {
			return;
		}

		$items = Fadewatch_Resolver::get_items();
		$files = array_values(
			array_filter(
				$files,
				static function ( $file ) use ( $items ) {
					return isset( $items[ $file ] ) && '' === $items[ $file ]['skip'];
				}
			)
		);

		if ( empty( $files ) ) {
			return;
		}

		$state = Fadewatch_Store::state();
		$new   = array_diff( $files, $state['queue'] );
		if ( empty( $new ) ) {
			return;
		}

		if ( empty( $state['queue'] ) ) {
			$state['total']      = 0;
			$state['done']       = 0;
			$state['full']       = false;
			$state['started_at'] = time();
		}

		$state['queue']  = array_merge( $state['queue'], array_values( $new ) );
		$state['total'] += count( $new );
		Fadewatch_Store::save_state( $state );

		self::schedule_processing( 0 );
	}

	/**
	 * Schedules the next queue run if none is pending.
	 *
	 * @param int $delay Delay in seconds.
	 */
	public static function schedule_processing( $delay ) {
		if ( ! wp_next_scheduled( self::HOOK_QUEUE ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::HOOK_QUEUE );
		}
	}

	/**
	 * Cron entry point: processes one batch and schedules the next.
	 */
	public static function cron_process() {
		$progress = self::process_batch();
		if ( ! $progress['complete'] ) {
			self::schedule_processing( $progress['busy'] ? 120 : 30 );
		}
	}

	/**
	 * Processes up to BATCH_SIZE queued plugins.
	 *
	 * @return array Progress (done, total, remaining, complete, busy).
	 */
	public static function process_batch() {
		$state = Fadewatch_Store::state();
		if ( empty( $state['queue'] ) || ! Fadewatch_Settings::checks_allowed() ) {
			return self::progress( $state, false, true );
		}

		if ( ! Fadewatch_Store::lock() ) {
			return self::progress( $state, true );
		}

		// Take the batch off the queue first, so items enqueued while we
		// work (e.g. by an update in another request) are not lost.
		$batch = array_splice( $state['queue'], 0, self::BATCH_SIZE );
		Fadewatch_Store::save_state( $state );

		$items     = Fadewatch_Resolver::get_items();
		$results   = Fadewatch_Store::results();
		$context   = Fadewatch_Report::context();
		$baseline  = ! Fadewatch_Store::meta()['baseline_done'];
		$started   = microtime( true );
		$leftover  = array();
		$processed = 0;

		foreach ( $batch as $index => $file ) {
			if ( ( microtime( true ) - $started ) > self::TIME_BUDGET ) {
				$leftover = array_slice( $batch, $index );
				break;
			}

			++$processed;

			if ( ! isset( $items[ $file ] ) || '' !== $items[ $file ]['skip'] ) {
				continue;
			}

			$item     = $items[ $file ];
			$previous = isset( $results[ $file ] ) ? $results[ $file ] : null;
			$old      = Fadewatch_Status::evaluate( $item, $previous, $context );

			$results[ $file ] = self::merge_record( $previous, Fadewatch_API::fetch_plugin( $item['slug'] ), $item['slug'] );

			$new = Fadewatch_Status::evaluate( $item, $results[ $file ], $context );

			if ( $old['status'] !== $new['status'] ) {
				self::handle_change( $item, $old, $new, $baseline );
			}
		}

		Fadewatch_Store::save_results( $results );
		Fadewatch_Report::flush();

		// Re-read: other requests may have added items meanwhile.
		$state = Fadewatch_Store::state();
		if ( ! empty( $leftover ) ) {
			$state['queue'] = array_values( array_unique( array_merge( $leftover, $state['queue'] ) ) );
		}
		$state['done'] = min( $state['total'], $state['done'] + $processed );
		Fadewatch_Store::save_state( $state );

		$progress = self::progress( $state );

		if ( empty( $state['queue'] ) ) {
			self::finalize( $state );
			$progress['complete'] = true;
		}

		Fadewatch_Store::unlock();

		return $progress;
	}

	/**
	 * Combines a fresh API result with the previous record.
	 *
	 * A failed request never replaces good earlier data: the previous
	 * result is kept and only the error counter goes up.
	 *
	 * @param array|null $previous Previous record.
	 * @param array      $fetched  Fresh API result.
	 * @param string     $slug     Slug.
	 * @return array
	 */
	public static function merge_record( $previous, array $fetched, $slug ) {
		$now = time();

		if ( 'error' === $fetched['result'] ) {
			$has_good = is_array( $previous ) && isset( $previous['result'] ) && 'error' !== $previous['result'];
			$record   = $has_good ? $previous : array(
				'slug'   => $slug,
				'result' => 'error',
			);

			$record['error']        = $fetched['error'];
			$record['error_count']  = ( is_array( $previous ) && isset( $previous['error_count'] ) ? (int) $previous['error_count'] : 0 ) + 1;
			$record['last_attempt'] = $now;
			return $record;
		}

		return array_merge(
			$fetched,
			array(
				'slug'         => $slug,
				'checked_at'   => $now,
				'last_attempt' => $now,
				'error'        => '',
				'error_count'  => 0,
			)
		);
	}

	/**
	 * Reacts to a status change: fires the action and queues notifications.
	 *
	 * @param array $item     Installed plugin.
	 * @param array $old      Old evaluation.
	 * @param array $new      New evaluation.
	 * @param bool  $baseline Whether this is the first-ever scan.
	 */
	private static function handle_change( array $item, array $old, array $new, $baseline ) {
		/**
		 * Fires when a plugin's maintenance status changes.
		 *
		 * @param string $file       Plugin file.
		 * @param string $old_status Previous status.
		 * @param string $new_status New status.
		 * @param array  $new        New evaluation, including closure reason and dates.
		 */
		do_action( 'fadewatch_status_changed', $item['file'], $old['status'], $new['status'], $new );

		// The first scan sets the baseline; its results are shown in the
		// report and Site Health instead of being e-mailed.
		if ( $baseline || Fadewatch_Status::PENDING === $old['status'] ) {
			return;
		}

		$type = '';
		if ( Fadewatch_Status::CLOSED === $new['status'] ) {
			$type = 'closed';
		} elseif ( Fadewatch_Status::ABANDONED === $new['status'] ) {
			$type = 'abandoned';
		} elseif ( Fadewatch_Status::CLOSED === $old['status'] && Fadewatch_Status::UNKNOWN !== $new['status'] ) {
			$type = 'reopened';
		}

		if ( '' === $type ) {
			return;
		}

		$change = array(
			'type'         => $type,
			'file'         => $item['file'],
			'name'         => $item['name'],
			'slug'         => $item['slug'],
			'closed_date'  => $new['closed_date'],
			'reason'       => $new['reason'],
			'reason_text'  => $new['reason_text'],
			'last_updated' => $new['last_updated'],
			'time'         => time(),
		);

		if ( 'closed' === $type && 'security-issue' === $new['reason'] ) {
			Fadewatch_Notifier::send_security_alert( $change );
			return;
		}

		Fadewatch_Store::add_change( $change );
	}

	/**
	 * Runs when the queue is empty.
	 *
	 * @param array $state Final state.
	 */
	private static function finalize( array $state ) {
		$meta    = Fadewatch_Store::meta();
		$changes = Fadewatch_Store::pull_changes();

		if ( $meta['baseline_done'] ) {
			Fadewatch_Notifier::send_digest( $changes );
		}

		if ( ! empty( $state['full'] ) ) {
			Fadewatch_Store::update_meta(
				array(
					'last_full_scan' => time(),
					'baseline_done'  => true,
				)
			);
		}

		Fadewatch_Store::save_state(
			array(
				'queue'      => array(),
				'total'      => 0,
				'done'       => 0,
				'full'       => false,
				'started_at' => 0,
			)
		);

		/**
		 * Fires when the scan queue has been fully processed.
		 *
		 * @param array $results Stored results keyed by plugin file.
		 */
		do_action( 'fadewatch_scan_completed', Fadewatch_Store::results() );
	}

	/**
	 * Re-checks closed plugins (they can reopen) and failed checks.
	 */
	public static function recheck_problem_items() {
		if ( Fadewatch_Settings::scheduled_scans_paused() ) {
			return;
		}

		$files = array();
		foreach ( Fadewatch_Store::results() as $file => $record ) {
			$errors = isset( $record['error_count'] ) ? (int) $record['error_count'] : 0;
			$closed = isset( $record['result'] ) && 'closed' === $record['result'];
			if ( $closed || ( $errors > 0 && $errors < self::MAX_FAST_RETRIES ) ) {
				$files[] = $file;
			}
		}
		self::enqueue( $files );
	}

	/**
	 * Re-checks plugins right after they are installed or updated.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Extra data.
	 */
	public static function on_upgrade( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$files = array();
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$files = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$files = array( $hook_extra['plugin'] );
		} elseif ( $upgrader instanceof Plugin_Upgrader && method_exists( $upgrader, 'plugin_info' ) ) {
			$installed = $upgrader->plugin_info();
			if ( $installed ) {
				$files = array( $installed );
			}
		}

		// get_plugins() is cached per request; the new plugin must show up.
		wp_clean_plugins_cache( false );
		self::enqueue( array_filter( $files, 'is_string' ) );
	}

	/**
	 * Re-checks a plugin when it is activated.
	 *
	 * @param string $file Plugin file.
	 */
	public static function on_activated( $file ) {
		if ( plugin_basename( FADEWATCH_FILE ) !== $file ) {
			self::enqueue( array( $file ) );
		}
	}

	/**
	 * Forgets data about a deleted plugin.
	 *
	 * @param string $file    Plugin file.
	 * @param bool   $deleted Whether deletion succeeded.
	 */
	public static function on_deleted( $file, $deleted ) {
		if ( ! $deleted ) {
			return;
		}
		$results = Fadewatch_Store::results();
		if ( isset( $results[ $file ] ) ) {
			unset( $results[ $file ] );
			Fadewatch_Store::save_results( $results );
		}
	}

	/**
	 * Fallback for sites where scheduled events stopped running: when an
	 * admin opens the Plugins screen and data is over 48 hours old,
	 * queue a scan (it still runs in the background, not in this request).
	 */
	public static function maybe_fallback_scan() {
		if ( ! Fadewatch_Settings::current_user_can() || Fadewatch_Settings::scheduled_scans_paused() ) {
			return;
		}

		$state = Fadewatch_Store::state();
		if ( ! empty( $state['queue'] ) ) {
			self::schedule_processing( 0 );
			return;
		}

		if ( ( time() - (int) Fadewatch_Store::meta()['last_full_scan'] ) > self::FALLBACK_AFTER ) {
			self::start_full_scan( 'fallback' );
		}
	}

	/**
	 * Removes results for plugins that are no longer installed.
	 *
	 * @param array $items Installed plugins.
	 */
	private static function prune_results( array $items ) {
		$results = Fadewatch_Store::results();
		$pruned  = array_intersect_key( $results, $items );
		if ( count( $pruned ) !== count( $results ) ) {
			Fadewatch_Store::save_results( $pruned );
		}
	}

	/**
	 * Progress payload.
	 *
	 * @param array $state    State.
	 * @param bool  $busy     Whether another process holds the lock.
	 * @param bool  $complete Whether the queue is empty.
	 * @return array
	 */
	public static function progress( array $state, $busy = false, $complete = false ) {
		return array(
			'done'      => (int) $state['done'],
			'total'     => (int) $state['total'],
			'remaining' => count( $state['queue'] ),
			'complete'  => $complete || empty( $state['queue'] ),
			'busy'      => (bool) $busy,
		);
	}
}
