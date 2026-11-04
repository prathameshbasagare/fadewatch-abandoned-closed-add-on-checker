<?php
/**
 * WordPress.org Plugins API client.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * API client.
 *
 * Only the plugin slug is sent, and only to api.wordpress.org over HTTPS.
 */
final class Fadewatch_API {

	const ENDPOINT = 'https://api.wordpress.org/plugins/info/1.2/';
	const TIMEOUT  = 10;

	/**
	 * Fetches maintenance data for one plugin.
	 *
	 * @param string $slug WordPress.org slug.
	 * @return array Normalised record. `result` is one of ok | closed | not_found | error.
	 */
	public static function fetch_plugin( $slug ) {
		$response = wp_remote_get(
			self::build_url( $slug ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				// Deliberately omits the site URL that the default user agent includes.
				'user-agent'  => 'FadeWatch/' . FADEWATCH_VERSION,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error( $response->get_error_message() );
		}

		return self::parse_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * Builds the request URL, asking only for the fields we use.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function build_url( $slug ) {
		$args = array(
			'action'  => 'plugin_information',
			'request' => array(
				'slug'   => $slug,
				'fields' => array(
					'sections'          => 0,
					'description'       => 0,
					'short_description' => 0,
					'versions'          => 0,
					'screenshots'       => 0,
					'reviews'           => 0,
					'banners'           => 0,
					'icons'             => 0,
					'contributors'      => 0,
					'donate_link'       => 0,
					'upgrade_notice'    => 0,
					'compatibility'     => 0,
					'ratings'           => 0,
					'downloadlink'      => 0,
				),
			),
		);

		return self::ENDPOINT . '?' . http_build_query( $args, '', '&' );
	}

	/**
	 * Turns an HTTP status and body into a normalised record.
	 *
	 * Both "closed" and "not found" are HTTP 404; only the body tells them
	 * apart. Anything unexpected is reported as an error and must never be
	 * treated as closed or not found.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return array
	 */
	public static function parse_response( $code, $body ) {
		$data = json_decode( $body, true );

		if ( 200 === $code ) {
			if ( is_array( $data ) && ! isset( $data['error'] ) && ! empty( $data['slug'] ) ) {
				return self::normalise_ok( $data );
			}
			return self::error( __( 'WordPress.org returned an unexpected response.', 'fadewatch-abandoned-closed-plugin-checker' ) );
		}

		if ( 404 === $code && is_array( $data ) ) {
			if ( ! empty( $data['closed'] ) ) {
				return self::normalise_closed( $data );
			}
			if ( isset( $data['error'] ) && is_string( $data['error'] ) && false !== stripos( $data['error'], 'not found' ) ) {
				return array( 'result' => 'not_found' );
			}
		}

		if ( 429 === $code ) {
			return self::error( __( 'WordPress.org rate limit reached.', 'fadewatch-abandoned-closed-plugin-checker' ) );
		}

		return self::error(
			/* translators: %d: HTTP status code. */
			sprintf( __( 'WordPress.org returned HTTP %d.', 'fadewatch-abandoned-closed-plugin-checker' ), $code )
		);
	}

	/**
	 * Normalises a successful response.
	 *
	 * @param array $data Decoded response.
	 * @return array
	 */
	private static function normalise_ok( array $data ) {
		$tags = isset( $data['tags'] ) && is_array( $data['tags'] ) ? array_map( 'sanitize_key', array_keys( $data['tags'] ) ) : array();

		return array(
			'result'                   => 'ok',
			'name'                     => self::text( $data, 'name' ),
			'author'                   => self::text( $data, 'author' ),
			'version'                  => self::text( $data, 'version' ),
			'tested'                   => self::text( $data, 'tested' ),
			'requires'                 => self::text( $data, 'requires' ),
			'requires_php'             => self::text( $data, 'requires_php' ),
			'rating'                   => isset( $data['rating'] ) ? (int) $data['rating'] : 0,
			'num_ratings'              => isset( $data['num_ratings'] ) ? (int) $data['num_ratings'] : 0,
			'active_installs'          => isset( $data['active_installs'] ) ? (int) $data['active_installs'] : 0,
			'support_threads'          => isset( $data['support_threads'] ) ? (int) $data['support_threads'] : 0,
			'support_threads_resolved' => isset( $data['support_threads_resolved'] ) ? (int) $data['support_threads_resolved'] : 0,
			'last_updated'             => self::parse_date( isset( $data['last_updated'] ) ? $data['last_updated'] : '' ),
			'tags'                     => array_slice( $tags, 0, 5 ),
		);
	}

	/**
	 * Normalises a "closed" response.
	 *
	 * @param array $data Decoded response.
	 * @return array
	 */
	private static function normalise_closed( array $data ) {
		$date = isset( $data['closed_date'] ) && is_string( $data['closed_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['closed_date'] ) ? $data['closed_date'] : '';

		return array(
			'result'      => 'closed',
			'name'        => self::text( $data, 'name' ),
			'closed_date' => $date,
			'reason'      => isset( $data['reason'] ) ? sanitize_key( $data['reason'] ) : '',
			'reason_text' => self::text( $data, 'reason_text' ),
		);
	}

	/**
	 * Error record.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private static function error( $message ) {
		return array(
			'result' => 'error',
			'error'  => sanitize_text_field( $message ),
		);
	}

	/**
	 * Reads a plain-text field (entities decoded, tags stripped).
	 *
	 * @param array  $data Data.
	 * @param string $key  Key.
	 * @return string
	 */
	private static function text( array $data, $key ) {
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return '';
		}
		return sanitize_text_field( html_entity_decode( (string) $data[ $key ], ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Parses WordPress.org dates such as "2026-10-21 4:06pm GMT" as UTC.
	 *
	 * @param string $value Date string.
	 * @return int Unix timestamp, or 0 when unparseable.
	 */
	public static function parse_date( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return 0;
		}
		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
			return $date->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}
}
