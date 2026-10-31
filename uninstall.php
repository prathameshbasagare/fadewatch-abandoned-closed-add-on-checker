<?php
/**
 * Uninstall: removes scheduled events and (unless the admin chose to keep
 * it) all stored data.
 *
 * @package Fadewatch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$fadewatch_hooks = array( 'fadewatch_daily_scan', 'fadewatch_process_queue', 'fadewatch_recheck', 'fadewatch_weekly_summary' );

// Events are scheduled on the main site only.
$fadewatch_switched = is_multisite() && ! is_main_site();
if ( $fadewatch_switched ) {
	switch_to_blog( get_main_site_id() );
}
foreach ( $fadewatch_hooks as $fadewatch_hook ) {
	wp_clear_scheduled_hook( $fadewatch_hook );
}
if ( $fadewatch_switched ) {
	restore_current_blog();
}

delete_site_transient( 'fadewatch_scan_lock' );

$fadewatch_settings = get_site_option( 'fadewatch_settings', array() );
$fadewatch_keep     = is_array( $fadewatch_settings ) && isset( $fadewatch_settings['delete_on_uninstall'] ) && ! $fadewatch_settings['delete_on_uninstall'];

if ( ! $fadewatch_keep ) {
	foreach ( array( 'fadewatch_settings', 'fadewatch_results', 'fadewatch_scan_state', 'fadewatch_meta', 'fadewatch_pending_changes' ) as $fadewatch_option ) {
		delete_site_option( $fadewatch_option );
	}
}
