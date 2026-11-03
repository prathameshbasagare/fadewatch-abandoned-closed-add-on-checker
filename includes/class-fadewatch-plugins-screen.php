<?php
/**
 * "Maintenance" column, filter links and sorting on the Plugins screen.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugins screen integration. Reads stored data only (one option read);
 * makes no HTTP requests.
 */
final class Fadewatch_Plugins_Screen {

	const COLUMN    = 'fadewatch_maintenance';
	const QUERY_VAR = 'fadewatch_status';
	const SORT_KEY  = 'fadewatch_maintenance';

	/**
	 * Statuses that get a filter link above the table.
	 *
	 * @var string[]
	 */
	private static $filters = array(
		Fadewatch_Status::CLOSED,
		Fadewatch_Status::ABANDONED,
		Fadewatch_Status::NOT_ON_ORG,
		Fadewatch_Status::UNKNOWN,
	);

	/**
	 * Registers hooks.
	 */
	public static function register_hooks() {
		add_action( 'load-plugins.php', array( __CLASS__, 'setup' ) );
	}

	/**
	 * Hooks into the list table, only on plugins.php and only for users
	 * who may use this plugin.
	 */
	public static function setup() {
		if ( ! Fadewatch_Settings::current_user_can() ) {
			return;
		}
		// On multisite, plugins are managed in the network admin only.
		if ( is_multisite() && ! is_network_admin() ) {
			return;
		}

		$screen = is_network_admin() ? 'plugins-network' : 'plugins';

		add_filter( "manage_{$screen}_columns", array( __CLASS__, 'add_column' ) );
		add_filter( "manage_{$screen}_sortable_columns", array( __CLASS__, 'sortable_columns' ) );
		add_action( 'manage_plugins_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( "views_{$screen}", array( __CLASS__, 'add_views' ) );

		// Filtering and sorting change the plugin list. Core also runs the
		// `all_plugins` filter while processing bulk actions and then saves
		// the result (e.g. the auto-update list), so only touch the list on
		// plain page views, never while an action is being processed.
		if ( ! self::is_action_request() && ( self::current_filter() || self::is_sorting() ) ) {
			add_filter( 'all_plugins', array( __CLASS__, 'filter_and_tag_plugins' ), 999 );
		}
	}

	/**
	 * Adds the column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Maintenance', 'fadewatch-abandoned-closed-add-on-checker' );
		return $columns;
	}

	/**
	 * Makes the column sortable, except on views whose rows don't come
	 * from the regular plugin list.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		if ( ! in_array( self::plugin_status(), array( 'mustuse', 'dropins' ), true ) ) {
			$columns[ self::COLUMN ] = array( self::SORT_KEY, true );
		}
		return $columns;
	}

	/**
	 * Renders the column cell.
	 *
	 * @param string $column_name Column.
	 * @param string $plugin_file Plugin file.
	 */
	public static function render_column( $column_name, $plugin_file ) {
		if ( self::COLUMN !== $column_name ) {
			return;
		}

		if ( in_array( self::plugin_status(), array( 'mustuse', 'dropins' ), true ) ) {
			echo '<span class="fadewatch-detail">' . esc_html__( 'Not checked (must-use plugin or drop-in)', 'fadewatch-abandoned-closed-add-on-checker' ) . '</span>';
			return;
		}

		$rows = Fadewatch_Report::rows();
		if ( ! isset( $rows[ $plugin_file ] ) ) {
			return;
		}

		// Checks are off: offer to turn them on instead of a "Pending" badge.
		if ( ! Fadewatch_Settings::checks_allowed() ) {
			echo '<span class="fadewatch-detail">' . esc_html__( 'Not checked: checks are off.', 'fadewatch-abandoned-closed-add-on-checker' ) . '</span> ';
			Fadewatch_Admin::render_consent_button( 'button-link' );
			return;
		}

		$row = $rows[ $plugin_file ];

		echo Fadewatch_Render::badge( $row['eval'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Fadewatch_Render::badge().
		echo Fadewatch_Render::details_html( $row['eval'], $row['item'], $row['record'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Fadewatch_Render::details_html().

		if ( Fadewatch_Render::has_org_page( $row['eval'] ) && Fadewatch_Status::CLOSED !== $row['eval']['status'] ) {
			printf(
				'<a class="fadewatch-detail" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( Fadewatch_Render::org_url( $row['item']['slug'] ) ),
				esc_html__( 'View on WordPress.org', 'fadewatch-abandoned-closed-add-on-checker' ),
				esc_html__( '(opens in a new tab)', 'fadewatch-abandoned-closed-add-on-checker' )
			);
		}
	}

	/**
	 * Adds "Closed (2) | Possibly abandoned (5) | …" links above the table.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function add_views( $views ) {
		$counts  = Fadewatch_Report::counts();
		$current = self::current_filter();

		if ( $current ) {
			// Our filter is active, so core's "All" is not the current view.
			foreach ( $views as $key => $view ) {
				$views[ $key ] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $view );
			}
		}

		foreach ( self::$filters as $status ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}

			$url = add_query_arg(
				array(
					'plugin_status'  => 'all',
					self::QUERY_VAR => $status,
				),
				self_admin_url( 'plugins.php' )
			);

			$is_current = $current === $status;

			$views[ 'fadewatch_' . $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( Fadewatch_Status::label( $status ) ),
				esc_html( number_format_i18n( $counts[ $status ] ) )
			);
		}

		return $views;
	}

	/**
	 * Removes plugins that don't match the active filter, and adds a sort
	 * key that core's list table sorts by (it sorts on plugin data keys).
	 *
	 * @param array $plugins Plugins keyed by file.
	 * @return array
	 */
	public static function filter_and_tag_plugins( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		$rows    = Fadewatch_Report::rows();
		$filter  = self::current_filter();
		$sorting = self::is_sorting();

		foreach ( $plugins as $file => $data ) {
			$status = isset( $rows[ $file ] ) ? $rows[ $file ]['eval']['status'] : '';

			if ( $filter && $status !== $filter ) {
				unset( $plugins[ $file ] );
				continue;
			}

			if ( $sorting && is_array( $data ) ) {
				$severity = isset( $rows[ $file ] ) ? Fadewatch_Status::severity_rank( $rows[ $file ]['eval']['severity'] ) : 0;
				$name     = isset( $data['Name'] ) ? $data['Name'] : $file;
				// Core sorts with strcasecmp(); "9 - severity" puts the most
				// serious first in ascending order.
				$plugins[ $file ][ ucfirst( self::SORT_KEY ) ] = ( 9 - $severity ) . ' ' . $name;
			}
		}

		return $plugins;
	}

	/**
	 * Active status filter, if any.
	 *
	 * @return string
	 */
	private static function current_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		return in_array( $value, self::$filters, true ) ? $value : '';
	}

	/**
	 * Whether the list is being sorted by our column.
	 *
	 * @return bool
	 */
	private static function is_sorting() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort parameter.
		return isset( $_GET['orderby'] ) && self::SORT_KEY === sanitize_key( wp_unslash( $_GET['orderby'] ) );
	}

	/**
	 * Core's current plugin_status view.
	 *
	 * @return string
	 */
	private static function plugin_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		return isset( $_REQUEST['plugin_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['plugin_status'] ) ) : 'all';
	}

	/**
	 * Whether plugins.php is processing an action (activate, bulk update,
	 * auto-update toggles…), mirroring WP_List_Table::current_action().
	 *
	 * @return bool
	 */
	private static function is_action_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- Only detects whether an action is present; core verifies it.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		if ( 'get' !== $method ) {
			return true;
		}
		foreach ( array( 'action', 'action2' ) as $key ) {
			$value = isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			if ( '' !== $value && '-1' !== $value ) {
				return true;
			}
		}
		// phpcs:enable
		return false;
	}
}
