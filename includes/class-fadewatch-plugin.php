<?php
/**
 * Bootstrap: wires every component to WordPress.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Fadewatch_Plugin {

	/**
	 * Registers hooks. Runs on `plugins_loaded`.
	 *
	 * Translations are loaded automatically by WordPress (4.6+) for plugins
	 * hosted on WordPress.org, so no load_plugin_textdomain() call is needed.
	 */
	public static function init() {
		// Cron, upgrader and deletion hooks must run outside wp-admin too.
		Fadewatch_Scanner::register_hooks();
		Fadewatch_Site_Health::register_hooks();

		if ( is_admin() ) {
			Fadewatch_Admin::register_hooks();
			Fadewatch_Plugins_Screen::register_hooks();
			Fadewatch_Ajax::register_hooks();
		}
	}

	/**
	 * Activation: schedule events. Checks start only after consent; until
	 * then a one-time prompt is shown on the next Plugins screen load.
	 *
	 * The plugin declares `Network: true`, so on multisite this only runs
	 * for network activation.
	 */
	public static function activate() {
		Fadewatch_Scanner::ensure_schedules();

		if ( Fadewatch_Settings::checks_allowed() ) {
			Fadewatch_Scanner::start_full_scan( 'activation' );
		} else {
			set_site_transient( Fadewatch_Admin::PROMPT_FLAG, 1, WEEK_IN_SECONDS );
		}
	}

	/**
	 * Deactivation: remove scheduled events and the scan lock.
	 * Stored data is kept until the plugin is deleted (see uninstall.php).
	 */
	public static function deactivate() {
		Fadewatch_Scanner::clear_schedules();
		Fadewatch_Store::unlock();
		delete_site_transient( Fadewatch_Admin::PROMPT_FLAG );
	}
}
