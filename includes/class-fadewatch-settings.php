<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 *
 * Stored as a single network option (a normal, non-autoloaded option on
 * single sites) because plugins are managed network-wide.
 */
final class Fadewatch_Settings {

	const OPTION = 'fadewatch_settings';

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'ageing_months'       => 12,
			'abandoned_months'    => 24,
			'include_inactive'    => true,
			'notify'              => 'changes', // off | changes | weekly.
			'recipients'          => '',
			'skip_local'          => false,
			'delete_on_uninstall' => true,
		);
	}

	/**
	 * Returns all settings, or one setting when $key is given.
	 *
	 * @param string|null $key Optional setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$saved       = get_site_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		if ( null === $key ) {
			return self::$cache;
		}

		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Saves sanitised settings.
	 *
	 * @param array $settings Raw settings.
	 * @return array The saved settings.
	 */
	public static function update( array $settings ) {
		$clean = self::sanitize( $settings );
		update_site_option( self::OPTION, $clean );
		self::$cache = null;
		return $clean;
	}

	/**
	 * Sanitises a settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = array();

		$out['ageing_months'] = isset( $input['ageing_months'] ) ? absint( $input['ageing_months'] ) : $defaults['ageing_months'];
		$out['ageing_months'] = min( 120, max( 1, $out['ageing_months'] ) );

		$out['abandoned_months'] = isset( $input['abandoned_months'] ) ? absint( $input['abandoned_months'] ) : $defaults['abandoned_months'];
		$out['abandoned_months'] = min( 240, max( $out['ageing_months'] + 1, $out['abandoned_months'] ) );

		$out['include_inactive']    = ! empty( $input['include_inactive'] );
		$out['skip_local']          = ! empty( $input['skip_local'] );
		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		$notify        = isset( $input['notify'] ) ? sanitize_key( $input['notify'] ) : $defaults['notify'];
		$out['notify'] = in_array( $notify, array( 'off', 'changes', 'weekly' ), true ) ? $notify : $defaults['notify'];

		$out['recipients'] = isset( $input['recipients'] ) ? self::sanitize_recipients( (string) $input['recipients'] ) : '';

		return $out;
	}

	/**
	 * Keeps only valid e-mail addresses from a comma-separated list.
	 *
	 * @param string $value Raw list.
	 * @return string
	 */
	public static function sanitize_recipients( $value ) {
		$valid = array();
		foreach ( explode( ',', $value ) as $address ) {
			$address = sanitize_email( trim( $address ) );
			if ( $address && is_email( $address ) ) {
				$valid[] = $address;
			}
		}
		return implode( ', ', array_unique( $valid ) );
	}

	/**
	 * E-mail recipients, falling back to the (network) admin e-mail.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$list = array_filter( array_map( 'trim', explode( ',', (string) self::get( 'recipients' ) ) ) );
		if ( empty( $list ) ) {
			$list = array( get_site_option( 'admin_email' ) );
		}

		/**
		 * Filters the notification e-mail recipients.
		 *
		 * @param string[] $list E-mail addresses.
		 */
		$list = apply_filters( 'fadewatch_email_recipients', $list );

		return array_values( array_filter( (array) $list, 'is_email' ) );
	}

	/**
	 * Age thresholds in months.
	 *
	 * @return array{ageing:int, abandoned:int}
	 */
	public static function thresholds() {
		$thresholds = array(
			'ageing'    => (int) self::get( 'ageing_months' ),
			'abandoned' => (int) self::get( 'abandoned_months' ),
		);

		/**
		 * Filters the age thresholds (in months).
		 *
		 * @param array{ageing:int, abandoned:int} $thresholds Thresholds.
		 */
		$filtered = apply_filters( 'fadewatch_thresholds', $thresholds );

		$ageing    = isset( $filtered['ageing'] ) ? max( 1, (int) $filtered['ageing'] ) : $thresholds['ageing'];
		$abandoned = isset( $filtered['abandoned'] ) ? max( $ageing + 1, (int) $filtered['abandoned'] ) : $thresholds['abandoned'];

		return array(
			'ageing'    => $ageing,
			'abandoned' => $abandoned,
		);
	}

	/**
	 * Whether scheduled scans should be skipped on this environment.
	 *
	 * @return bool
	 */
	public static function scheduled_scans_paused() {
		return self::get( 'skip_local' ) && 'local' === wp_get_environment_type();
	}

	/**
	 * Capability required for every action in this plugin.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Whether the current user may use this plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		return current_user_can( self::capability() );
	}
}
