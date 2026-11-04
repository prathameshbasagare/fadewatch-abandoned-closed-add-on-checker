<?php
/**
 * Site Health tests.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site Health integration. Tests read stored data only.
 */
final class Fadewatch_Site_Health {

	/**
	 * Registers hooks.
	 */
	public static function register_hooks() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
	}

	/**
	 * Adds the tests.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function register_tests( $tests ) {
		$tests['direct']['fadewatch_closed_plugins'] = array(
			'label' => __( 'Installed plugins closed on WordPress.org', 'fadewatch-abandoned-closed-plugin-checker' ),
			'test'  => array( __CLASS__, 'test_closed' ),
		);
		$tests['direct']['fadewatch_abandoned_plugins'] = array(
			'label' => __( 'Plugins without updates for a long time', 'fadewatch-abandoned-closed-plugin-checker' ),
			'test'  => array( __CLASS__, 'test_abandoned' ),
		);
		return $tests;
	}

	/**
	 * Closed plugins test. Critical when one was closed for a security issue.
	 *
	 * @return array
	 */
	public static function test_closed() {
		$closed   = self::rows_with_status( Fadewatch_Status::CLOSED );
		$security = array_filter(
			$closed,
			static function ( $row ) {
				return 'security-issue' === $row['eval']['reason'];
			}
		);

		if ( empty( $closed ) ) {
			return self::result(
				'fadewatch_closed_plugins',
				'good',
				self::has_data()
					? __( 'No installed plugins have been closed on WordPress.org', 'fadewatch-abandoned-closed-plugin-checker' )
					: __( 'Plugin maintenance status has not been checked yet', 'fadewatch-abandoned-closed-plugin-checker' ),
				__( 'Plugins closed on WordPress.org no longer receive updates, including security fixes.', 'fadewatch-abandoned-closed-plugin-checker' ),
				array()
			);
		}

		$list = array();
		foreach ( $closed as $row ) {
			$list[] = $row['item']['name'] . ( $row['eval']['reason_text'] ? ' (' . $row['eval']['reason_text'] . ')' : '' );
		}

		return self::result(
			'fadewatch_closed_plugins',
			$security ? 'critical' : 'recommended',
			sprintf(
				/* translators: %d: number of plugins. */
				_n( '%d installed plugin has been closed on WordPress.org', '%d installed plugins have been closed on WordPress.org', count( $closed ), 'fadewatch-abandoned-closed-plugin-checker' ),
				count( $closed )
			),
			__( 'Closed plugins no longer receive updates, including security fixes. Replace them with maintained alternatives.', 'fadewatch-abandoned-closed-plugin-checker' ),
			$list
		);
	}

	/**
	 * Possibly abandoned plugins test.
	 *
	 * @return array
	 */
	public static function test_abandoned() {
		$rows       = self::rows_with_status( Fadewatch_Status::ABANDONED );
		$thresholds = Fadewatch_Settings::thresholds();

		$description = sprintf(
			/* translators: %d: number of months. */
			_n( 'Plugins that have not been updated in over %d month may no longer be maintained.', 'Plugins that have not been updated in over %d months may no longer be maintained.', $thresholds['abandoned'], 'fadewatch-abandoned-closed-plugin-checker' ),
			$thresholds['abandoned']
		);

		if ( empty( $rows ) ) {
			return self::result(
				'fadewatch_abandoned_plugins',
				'good',
				__( 'All checked plugins have been updated recently', 'fadewatch-abandoned-closed-plugin-checker' ),
				$description,
				array()
			);
		}

		$list = array();
		foreach ( $rows as $row ) {
			$list[] = sprintf(
				/* translators: 1: plugin name, 2: date. */
				__( '%1$s (latest update release: %2$s)', 'fadewatch-abandoned-closed-plugin-checker' ),
				$row['item']['name'],
				wp_date( get_option( 'date_format' ), $row['eval']['last_updated'] )
			);
		}

		return self::result(
			'fadewatch_abandoned_plugins',
			'recommended',
			sprintf(
				/* translators: %d: number of plugins. */
				_n( '%d plugin may be abandoned', '%d plugins may be abandoned', count( $rows ), 'fadewatch-abandoned-closed-plugin-checker' ),
				count( $rows )
			),
			$description,
			$list
		);
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string   $test        Test ID.
	 * @param string   $status      good | recommended | critical.
	 * @param string   $label       Label.
	 * @param string   $description Description (plain text).
	 * @param string[] $list        Plugin lines (plain text).
	 * @return array
	 */
	private static function result( $test, $status, $label, $description, array $list ) {
		$html = '<p>' . esc_html( $description ) . '</p>';
		if ( $list ) {
			$html .= '<ul>';
			foreach ( $list as $line ) {
				$html .= '<li>' . esc_html( $line ) . '</li>';
			}
			$html .= '</ul>';
		}

		$actions = '';
		if ( Fadewatch_Settings::current_user_can() ) {
			$actions = sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( Fadewatch_Admin::page_url() ),
				esc_html__( 'View the plugin maintenance report', 'fadewatch-abandoned-closed-plugin-checker' )
			);
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'fadewatch-abandoned-closed-plugin-checker' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => $html,
			'actions'     => $actions,
			'test'        => $test,
		);
	}

	/**
	 * Report rows with one status.
	 *
	 * @param string $status Status.
	 * @return array
	 */
	private static function rows_with_status( $status ) {
		return array_filter(
			Fadewatch_Report::rows(),
			static function ( $row ) use ( $status ) {
				return $status === $row['eval']['status'];
			}
		);
	}

	/**
	 * Whether any plugin has been checked.
	 *
	 * @return bool
	 */
	private static function has_data() {
		return (bool) Fadewatch_Store::meta()['last_full_scan'] || ! empty( Fadewatch_Store::results() );
	}
}
