<?php
/**
 * Uninstall: removes scheduled events and (unless the admin chose to keep
 * it) all stored data.
 *
 * @package AbandonedPluginChecker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$abpc_hooks = array( 'abpc_daily_scan', 'abpc_process_queue', 'abpc_recheck', 'abpc_weekly_summary' );

// Events are scheduled on the main site only.
$abpc_switched = is_multisite() && ! is_main_site();
if ( $abpc_switched ) {
	switch_to_blog( get_main_site_id() );
}
foreach ( $abpc_hooks as $abpc_hook ) {
	wp_clear_scheduled_hook( $abpc_hook );
}
if ( $abpc_switched ) {
	restore_current_blog();
}

delete_site_transient( 'abpc_scan_lock' );

$abpc_settings = get_site_option( 'abpc_settings', array() );
$abpc_keep     = is_array( $abpc_settings ) && isset( $abpc_settings['delete_on_uninstall'] ) && ! $abpc_settings['delete_on_uninstall'];

if ( ! $abpc_keep ) {
	foreach ( array( 'abpc_settings', 'abpc_results', 'abpc_scan_state', 'abpc_meta', 'abpc_pending_changes' ) as $abpc_option ) {
		delete_site_option( $abpc_option );
	}
}
