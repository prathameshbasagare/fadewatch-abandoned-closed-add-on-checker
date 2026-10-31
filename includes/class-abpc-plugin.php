<?php
/**
 * Bootstrap: wires every component to WordPress.
 *
 * @package Stalewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class ABPC_Plugin {

	/**
	 * Registers hooks. Runs on `plugins_loaded`.
	 *
	 * Translations are loaded automatically by WordPress (4.6+) for plugins
	 * hosted on WordPress.org, so no load_plugin_textdomain() call is needed.
	 */
	public static function init() {
		// Cron, upgrader and deletion hooks must run outside wp-admin too.
		ABPC_Scanner::register_hooks();
		ABPC_Site_Health::register_hooks();

		if ( is_admin() ) {
			ABPC_Admin::register_hooks();
			ABPC_Plugins_Screen::register_hooks();
			ABPC_Ajax::register_hooks();
		}
	}

	/**
	 * Activation: schedule events and queue the first scan.
	 *
	 * The plugin declares `Network: true`, so on multisite this only runs
	 * for network activation.
	 */
	public static function activate() {
		ABPC_Scanner::ensure_schedules();
		ABPC_Scanner::start_full_scan( 'activation' );
	}

	/**
	 * Deactivation: remove scheduled events and the scan lock.
	 * Stored data is kept until the plugin is deleted (see uninstall.php).
	 */
	public static function deactivate() {
		ABPC_Scanner::clear_schedules();
		ABPC_Store::unlock();
	}
}
