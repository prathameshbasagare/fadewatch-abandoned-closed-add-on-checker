<?php
/**
 * Combines installed plugins with stored data into report rows.
 *
 * @package AbandonedPluginChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report.
 */
final class ABPC_Report {

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $rows = null;

	/**
	 * Evaluated rows keyed by plugin file, most severe first.
	 *
	 * Reads one option; never makes HTTP requests.
	 *
	 * @return array<string, array{item:array, record:array|null, eval:array}>
	 */
	public static function rows() {
		if ( null !== self::$rows ) {
			return self::$rows;
		}

		$results = ABPC_Store::results();
		$context = self::context();
		$rows    = array();

		foreach ( ABPC_Resolver::get_items() as $file => $item ) {
			$record        = isset( $results[ $file ] ) ? $results[ $file ] : null;
			$rows[ $file ] = array(
				'item'   => $item,
				'record' => $record,
				'eval'   => ABPC_Status::evaluate( $item, $record, $context ),
			);
		}

		uasort( $rows, array( __CLASS__, 'compare_rows' ) );

		self::$rows = $rows;
		return $rows;
	}

	/**
	 * Clears the request cache (after a scan step changes data).
	 */
	public static function flush() {
		self::$rows = null;
	}

	/**
	 * Number of plugins per status.
	 *
	 * @return array<string, int>
	 */
	public static function counts() {
		$counts = array_fill_keys( ABPC_Status::all(), 0 );
		foreach ( self::rows() as $row ) {
			++$counts[ $row['eval']['status'] ];
		}
		return $counts;
	}

	/**
	 * Evaluation context shared by every row.
	 *
	 * @return array
	 */
	public static function context() {
		return array(
			'thresholds' => ABPC_Settings::thresholds(),
			'now'        => time(),
			'wp_version' => self::newest_wp_version(),
		);
	}

	/**
	 * The newest WordPress version we know about: the latest release
	 * reported by core's own update check, or the installed version.
	 *
	 * @return string
	 */
	public static function newest_wp_version() {
		$version = get_bloginfo( 'version' );
		$core    = get_site_transient( 'update_core' );

		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $update ) {
				if ( isset( $update->current ) && version_compare( $update->current, $version, '>' ) ) {
					$version = $update->current;
				}
			}
		}
		return $version;
	}

	/**
	 * Sort callback: severity desc, then name.
	 *
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return int
	 */
	public static function compare_rows( $a, $b ) {
		$diff = ABPC_Status::severity_rank( $b['eval']['severity'] ) - ABPC_Status::severity_rank( $a['eval']['severity'] );
		if ( 0 !== $diff ) {
			return $diff;
		}
		return strcasecmp( $a['item']['name'], $b['item']['name'] );
	}
}
