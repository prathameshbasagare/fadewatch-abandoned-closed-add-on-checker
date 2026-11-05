<?php
/**
 * "Maintenance" column on the Plugins screen.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugins screen integration. Adds a read-only column; never adds, removes
 * or reorders plugins in the list. Reads stored data only (one option
 * read); makes no HTTP requests.
 */
final class Fadewatch_Plugins_Screen {

	const COLUMN = 'fadewatch_maintenance';

	/**
	 * Registers hooks.
	 */
	public static function register_hooks() {
		add_action( 'load-plugins.php', array( __CLASS__, 'setup' ) );
	}

	/**
	 * Adds the column, only on plugins.php, only when an administrator has
	 * turned it on in the settings, and only for users who may use this
	 * plugin.
	 */
	public static function setup() {
		if ( ! Fadewatch_Settings::get( 'show_column' ) || ! Fadewatch_Settings::current_user_can() ) {
			return;
		}
		// On multisite, plugins are managed in the network admin only.
		if ( is_multisite() && ! is_network_admin() ) {
			return;
		}

		$screen = is_network_admin() ? 'plugins-network' : 'plugins';

		add_filter( "manage_{$screen}_columns", array( __CLASS__, 'add_column' ) );
		add_action( 'manage_plugins_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Adds the column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Maintenance', 'fadewatch-abandoned-closed-plugin-checker' );
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
			echo '<span class="fadewatch-detail">' . esc_html__( 'Not checked (must-use plugin or drop-in)', 'fadewatch-abandoned-closed-plugin-checker' ) . '</span>';
			return;
		}

		$rows = Fadewatch_Report::rows();
		if ( ! isset( $rows[ $plugin_file ] ) ) {
			return;
		}

		// Checks are off: offer to turn them on instead of a "Pending" badge.
		if ( ! Fadewatch_Settings::checks_allowed() ) {
			echo '<span class="fadewatch-detail">' . esc_html__( 'Not checked: checks are off.', 'fadewatch-abandoned-closed-plugin-checker' ) . '</span> ';
			Fadewatch_Admin::render_consent_button( 'button-link' );
			return;
		}

		$row = $rows[ $plugin_file ];

		echo wp_kses( Fadewatch_Render::badge( $row['eval'] ), Fadewatch_Render::allowed_html() );
		echo wp_kses( Fadewatch_Render::details_html( $row['eval'], $row['item'], $row['record'] ), Fadewatch_Render::allowed_html() );

		if ( Fadewatch_Render::has_org_page( $row['eval'] ) && Fadewatch_Status::CLOSED !== $row['eval']['status'] ) {
			printf(
				'<a class="fadewatch-detail" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( Fadewatch_Render::org_url( $row['item']['slug'] ) ),
				esc_html__( 'View on WordPress.org', 'fadewatch-abandoned-closed-plugin-checker' ),
				esc_html__( '(opens in a new tab)', 'fadewatch-abandoned-closed-plugin-checker' )
			);
		}
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
}
