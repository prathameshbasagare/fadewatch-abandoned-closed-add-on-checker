<?php
/**
 * Plugin Name:       Abandoned Plugin Checker
 * Description:       Shows which installed plugins have been closed on WordPress.org (and why), which haven't been updated in years, and which can't be checked.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            CuriosWP Dev
 * Author URI:        https://profiles.wordpress.org/curiouswpdev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       abandoned-plugin-checker
 * Network:           true
 *
 * @package AbandonedPluginChecker
 */

defined( 'ABSPATH' ) || exit;

define( 'ABPC_VERSION', '1.0.0' );
define( 'ABPC_FILE', __FILE__ );
define( 'ABPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABPC_URL', plugin_dir_url( __FILE__ ) );
define( 'ABPC_SLUG', 'abandoned-plugin-checker' );

require_once ABPC_DIR . 'includes/class-abpc-settings.php';
require_once ABPC_DIR . 'includes/class-abpc-store.php';
require_once ABPC_DIR . 'includes/class-abpc-resolver.php';
require_once ABPC_DIR . 'includes/class-abpc-api.php';
require_once ABPC_DIR . 'includes/class-abpc-status.php';
require_once ABPC_DIR . 'includes/class-abpc-report.php';
require_once ABPC_DIR . 'includes/class-abpc-notifier.php';
require_once ABPC_DIR . 'includes/class-abpc-scanner.php';
require_once ABPC_DIR . 'includes/class-abpc-render.php';
require_once ABPC_DIR . 'includes/class-abpc-admin.php';
require_once ABPC_DIR . 'includes/class-abpc-plugins-screen.php';
require_once ABPC_DIR . 'includes/class-abpc-ajax.php';
require_once ABPC_DIR . 'includes/class-abpc-site-health.php';
require_once ABPC_DIR . 'includes/class-abpc-plugin.php';

// Registered at load time so the custom interval exists during activation too.
add_filter( 'cron_schedules', array( 'ABPC_Scanner', 'cron_schedules' ) );

register_activation_hook( __FILE__, array( 'ABPC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ABPC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ABPC_Plugin', 'init' ) );
