<?php
/**
 * Persistent storage for results, scan state and change log.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store.
 *
 * Everything is kept in network options. On single sites those map to
 * regular options with autoload disabled, so nothing is loaded on the
 * front end.
 */
final class ABPC_Store {

	const RESULTS = 'abpc_results';
	const STATE   = 'abpc_scan_state';
	const META    = 'abpc_meta';
	const CHANGES = 'abpc_pending_changes';
	const LOCK    = 'abpc_scan_lock';

	/**
	 * All option names, used by uninstall.php.
	 *
	 * @return string[]
	 */
	public static function option_names() {
		return array( ABPC_Settings::OPTION, self::RESULTS, self::STATE, self::META, self::CHANGES );
	}

	/**
	 * Per-plugin results keyed by plugin file.
	 *
	 * @return array
	 */
	public static function results() {
		$value = get_site_option( self::RESULTS, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Saves results.
	 *
	 * @param array $results Results keyed by plugin file.
	 */
	public static function save_results( array $results ) {
		update_site_option( self::RESULTS, $results );
	}

	/**
	 * Scan queue state.
	 *
	 * @return array{queue:string[], total:int, done:int, full:bool, started_at:int}
	 */
	public static function state() {
		$defaults = array(
			'queue'      => array(),
			'total'      => 0,
			'done'       => 0,
			'full'       => false,
			'started_at' => 0,
		);
		$value    = get_site_option( self::STATE, array() );
		$state    = wp_parse_args( is_array( $value ) ? $value : array(), $defaults );

		$state['queue'] = array_values( array_filter( (array) $state['queue'], 'is_string' ) );
		return $state;
	}

	/**
	 * Saves scan state.
	 *
	 * @param array $state State.
	 */
	public static function save_state( array $state ) {
		update_site_option( self::STATE, $state );
	}

	/**
	 * Bookkeeping data.
	 *
	 * @return array{last_full_scan:int, baseline_done:bool}
	 */
	public static function meta() {
		$value = get_site_option( self::META, array() );
		return wp_parse_args(
			is_array( $value ) ? $value : array(),
			array(
				'last_full_scan' => 0,
				'baseline_done'  => false,
			)
		);
	}

	/**
	 * Merges values into the bookkeeping data.
	 *
	 * @param array $values Values to merge.
	 */
	public static function update_meta( array $values ) {
		update_site_option( self::META, array_merge( self::meta(), $values ) );
	}

	/**
	 * Queues a status change for the next digest e-mail.
	 *
	 * @param array $change Change data.
	 */
	public static function add_change( array $change ) {
		$changes   = get_site_option( self::CHANGES, array() );
		$changes   = is_array( $changes ) ? $changes : array();
		$changes[] = $change;
		update_site_option( self::CHANGES, array_slice( $changes, -200 ) );
	}

	/**
	 * Returns and clears queued changes.
	 *
	 * @return array
	 */
	public static function pull_changes() {
		$changes = get_site_option( self::CHANGES, array() );
		delete_site_option( self::CHANGES );
		return is_array( $changes ) ? $changes : array();
	}

	/**
	 * Takes the scan lock. Prevents cron and "Check now" from processing
	 * the same batch at the same time.
	 *
	 * @return bool True when the lock was acquired.
	 */
	public static function lock() {
		if ( get_site_transient( self::LOCK ) ) {
			return false;
		}
		set_site_transient( self::LOCK, time(), 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Releases the scan lock.
	 */
	public static function unlock() {
		delete_site_transient( self::LOCK );
	}
}
