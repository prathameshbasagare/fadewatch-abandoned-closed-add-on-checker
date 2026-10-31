<?php
/**
 * AJAX endpoints for "Check now".
 *
 * @package Stalewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers. Every request needs a valid nonce and the plugin's
 * capability. The browser drives the batches, so "Check now" also works
 * when WP-Cron is disabled or broken.
 */
final class ABPC_Ajax {

	const NONCE = 'abpc_scan';

	/**
	 * Registers hooks (logged-in users only; there is no nopriv handler).
	 */
	public static function register_hooks() {
		add_action( 'wp_ajax_abpc_scan_start', array( __CLASS__, 'start' ) );
		add_action( 'wp_ajax_abpc_scan_step', array( __CLASS__, 'step' ) );
	}

	/**
	 * Queues a full scan.
	 */
	public static function start() {
		self::authorize();
		wp_send_json_success( ABPC_Scanner::start_full_scan( 'manual' ) );
	}

	/**
	 * Processes one batch.
	 */
	public static function step() {
		self::authorize();
		wp_send_json_success( ABPC_Scanner::process_batch() );
	}

	/**
	 * Verifies nonce and capability, or ends the request.
	 */
	private static function authorize() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'stalewatch' ) ), 403 );
		}
		if ( ! ABPC_Settings::current_user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'stalewatch' ) ), 403 );
		}
	}
}
