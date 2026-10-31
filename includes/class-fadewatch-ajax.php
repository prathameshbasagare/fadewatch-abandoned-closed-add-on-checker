<?php
/**
 * AJAX endpoints for "Check now".
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers. Every request needs a valid nonce and the plugin's
 * capability. The browser drives the batches, so "Check now" also works
 * when WP-Cron is disabled or broken.
 */
final class Fadewatch_Ajax {

	const NONCE = 'fadewatch_scan';

	/**
	 * Registers hooks (logged-in users only; there is no nopriv handler).
	 */
	public static function register_hooks() {
		add_action( 'wp_ajax_fadewatch_scan_start', array( __CLASS__, 'start' ) );
		add_action( 'wp_ajax_fadewatch_scan_step', array( __CLASS__, 'step' ) );
		add_action( 'wp_ajax_fadewatch_grant_consent', array( __CLASS__, 'grant_consent' ) );
	}

	/**
	 * Records consent from the opt-in dialog and queues the first scan.
	 */
	public static function grant_consent() {
		self::authorize();
		Fadewatch_Settings::grant_consent();
		wp_send_json_success( Fadewatch_Scanner::start_full_scan( 'consent' ) );
	}

	/**
	 * Queues a full scan.
	 */
	public static function start() {
		self::authorize();
		wp_send_json_success( Fadewatch_Scanner::start_full_scan( 'manual' ) );
	}

	/**
	 * Processes one batch.
	 */
	public static function step() {
		self::authorize();
		wp_send_json_success( Fadewatch_Scanner::process_batch() );
	}

	/**
	 * Verifies nonce and capability, or ends the request.
	 */
	private static function authorize() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'fadewatch-abandoned-closed-add-on-checker' ) ), 403 );
		}
		if ( ! Fadewatch_Settings::current_user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'fadewatch-abandoned-closed-add-on-checker' ) ), 403 );
		}
	}
}
