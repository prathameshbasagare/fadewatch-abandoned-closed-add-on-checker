<?php
/**
 * Plugin Name:       Fadewatch – Abandoned & Closed Add-on Checker
 * Description:       Shows which installed plugins have been closed on WordPress.org (and why), which haven't been updated in years, and which can't be checked.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            curiouswpdev
 * Author URI:        https://profiles.wordpress.org/curiouswpdev/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       fadewatch-abandoned-closed-add-on-checker
 * Network:           true
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

define( 'FADEWATCH_VERSION', '1.0.1' );
define( 'FADEWATCH_FILE', __FILE__ );
define( 'FADEWATCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'FADEWATCH_URL', plugin_dir_url( __FILE__ ) );
define( 'FADEWATCH_SLUG', 'fadewatch-abandoned-closed-add-on-checker' );

require_once FADEWATCH_DIR . 'includes/class-fadewatch-settings.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-store.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-resolver.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-api.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-status.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-report.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-notifier.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-scanner.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-render.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-admin.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-plugins-screen.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-ajax.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-site-health.php';
require_once FADEWATCH_DIR . 'includes/class-fadewatch-plugin.php';

// Registered at load time so the custom interval exists during activation too.
add_filter( 'cron_schedules', array( 'Fadewatch_Scanner', 'cron_schedules' ) );

register_activation_hook( __FILE__, array( 'Fadewatch_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fadewatch_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Fadewatch_Plugin', 'init' ) );
