<?php
/**
 * Shared HTML/text rendering helpers. Every method returns escaped HTML
 * or plain text as documented.
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render helpers.
 */
final class Fadewatch_Render {

	/**
	 * Dashicon per status. Colour is never the only signal: every badge
	 * also has an icon and a text label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function icon( $status ) {
		$icons = array(
			Fadewatch_Status::MAINTAINED => 'dashicons-yes-alt',
			Fadewatch_Status::AGEING     => 'dashicons-clock',
			Fadewatch_Status::ABANDONED  => 'dashicons-warning',
			Fadewatch_Status::CLOSED     => 'dashicons-dismiss',
			Fadewatch_Status::NOT_ON_ORG => 'dashicons-external',
			Fadewatch_Status::UNKNOWN    => 'dashicons-editor-help',
			Fadewatch_Status::UNVERIFIED => 'dashicons-flag',
			Fadewatch_Status::PENDING    => 'dashicons-update',
			Fadewatch_Status::SKIPPED    => 'dashicons-minus',
		);
		return isset( $icons[ $status ] ) ? $icons[ $status ] : 'dashicons-marker';
	}

	/**
	 * Status badge.
	 *
	 * @param array $eval Evaluation.
	 * @return string Escaped HTML.
	 */
	public static function badge( array $eval ) {
		$label = Fadewatch_Status::label( $eval['status'] );
		if ( Fadewatch_Status::CLOSED === $eval['status'] && 'security-issue' === $eval['reason'] ) {
			$label = __( 'Closed: security issue', 'fadewatch-abandoned-closed-add-on-checker' );
		}

		return sprintf(
			'<span class="fadewatch-badge fadewatch-status--%1$s fadewatch-severity--%2$s"><span class="dashicons %3$s" aria-hidden="true"></span>%4$s</span>',
			esc_attr( $eval['status'] ),
			esc_attr( $eval['severity'] ),
			esc_attr( self::icon( $eval['status'] ) ),
			esc_html( $label )
		);
	}

	/**
	 * Explanation lines for a row.
	 *
	 * @param array      $eval   Evaluation.
	 * @param array      $item   Installed plugin.
	 * @param array|null $record Stored data.
	 * @return string[] Plain-text lines (not escaped).
	 */
	public static function details( array $eval, array $item, $record ) {
		$lines = array();

		switch ( $eval['status'] ) {
			case Fadewatch_Status::CLOSED:
				$date = $eval['closed_date'] ? self::format_ymd( $eval['closed_date'] ) : __( 'an unknown date', 'fadewatch-abandoned-closed-add-on-checker' );
				if ( $eval['reason_text'] ) {
					/* translators: 1: date, 2: closure reason such as "Security Issue". */
					$lines[] = sprintf( __( 'Closed on %1$s: %2$s', 'fadewatch-abandoned-closed-add-on-checker' ), $date, $eval['reason_text'] );
				} else {
					/* translators: %s: date. */
					$lines[] = sprintf( __( 'Closed on %s', 'fadewatch-abandoned-closed-add-on-checker' ), $date );
				}
				if ( 'security-issue' === $eval['reason'] ) {
					$lines[] = __( 'It will not receive security fixes. Replace it as soon as possible.', 'fadewatch-abandoned-closed-add-on-checker' );
				} elseif ( 'author-request' === $eval['reason'] ) {
					$lines[] = __( 'Closed at the author\'s request. This is not a security finding, but the plugin will get no more updates.', 'fadewatch-abandoned-closed-add-on-checker' );
				} else {
					$lines[] = __( 'It no longer receives updates from WordPress.org.', 'fadewatch-abandoned-closed-add-on-checker' );
				}
				break;

			case Fadewatch_Status::MAINTAINED:
			case Fadewatch_Status::AGEING:
			case Fadewatch_Status::ABANDONED:
				if ( $eval['last_updated'] ) {
					$lines[] = sprintf(
						/* translators: 1: relative time such as "3 years", 2: date. */
						__( 'Latest update release: %1$s ago (%2$s)', 'fadewatch-abandoned-closed-add-on-checker' ),
						human_time_diff( $eval['last_updated'] ),
						wp_date( get_option( 'date_format' ), $eval['last_updated'] )
					);
				}
				break;

			case Fadewatch_Status::NOT_ON_ORG:
				if ( 'update_uri' === $eval['note'] ) {
					$lines[] = __( 'Gets updates from another source (Update URI header).', 'fadewatch-abandoned-closed-add-on-checker' );
				} elseif ( 'premium' === $eval['note'] ) {
					$lines[] = __( 'Not on WordPress.org (premium version?).', 'fadewatch-abandoned-closed-add-on-checker' );
				} else {
					$lines[] = __( 'Not found on WordPress.org: probably a premium or custom plugin.', 'fadewatch-abandoned-closed-add-on-checker' );
				}
				break;

			case Fadewatch_Status::UNVERIFIED:
				$lines[] = sprintf(
					/* translators: 1: plugin slug, 2: name of the WordPress.org plugin. */
					__( 'The WordPress.org plugin "%1$s" is called "%2$s" and looks like a different plugin, so its data is not used.', 'fadewatch-abandoned-closed-add-on-checker' ),
					$item['slug'],
					is_array( $record ) && ! empty( $record['name'] ) ? $record['name'] : '?'
				);
				break;

			case Fadewatch_Status::UNKNOWN:
				$lines[] = $eval['error']
					/* translators: %s: error message. */
					? sprintf( __( 'Could not reach WordPress.org (%s). It will be retried.', 'fadewatch-abandoned-closed-add-on-checker' ), $eval['error'] )
					: __( 'Could not read the data from WordPress.org. It will be retried.', 'fadewatch-abandoned-closed-add-on-checker' );
				break;

			case Fadewatch_Status::PENDING:
				$lines[] = Fadewatch_Settings::checks_allowed()
					? __( 'Waiting for the first check.', 'fadewatch-abandoned-closed-add-on-checker' )
					: __( 'Checks are off. Allow them on the Plugin Maintenance page.', 'fadewatch-abandoned-closed-add-on-checker' );
				break;

			case Fadewatch_Status::SKIPPED:
				if ( 'inactive' === $eval['note'] ) {
					$lines[] = __( 'Inactive plugins are excluded in the settings.', 'fadewatch-abandoned-closed-add-on-checker' );
				} elseif ( 'filtered' === $eval['note'] ) {
					$lines[] = __( 'Excluded by a filter.', 'fadewatch-abandoned-closed-add-on-checker' );
				} else {
					$lines[] = __( 'No WordPress.org slug could be determined.', 'fadewatch-abandoned-closed-add-on-checker' );
				}
				break;
		}

		if ( $eval['tested_behind'] >= Fadewatch_Status::TESTED_BEHIND_WARNING && is_array( $record ) && ! empty( $record['tested'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: number of releases, 2: version such as "6.2". */
				_n(
					'Not tested with the last %1$d major WordPress release (tested up to %2$s).',
					'Not tested with the last %1$d major WordPress releases (tested up to %2$s).',
					$eval['tested_behind'],
					'fadewatch-abandoned-closed-add-on-checker'
				),
				$eval['tested_behind'],
				$record['tested']
			);
		}

		if ( $eval['stale'] && is_array( $record ) && ! empty( $record['checked_at'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: relative time such as "9 days". */
				__( 'Data out of date: last successful check %s ago.', 'fadewatch-abandoned-closed-add-on-checker' ),
				human_time_diff( (int) $record['checked_at'] )
			);
		} elseif ( Fadewatch_Status::UNKNOWN !== $eval['status'] && $eval['error'] ) {
			/* translators: %s: error message. */
			$lines[] = sprintf( __( 'Last check failed (%s); showing the previous result.', 'fadewatch-abandoned-closed-add-on-checker' ), $eval['error'] );
		}

		return $lines;
	}

	/**
	 * Details as escaped HTML.
	 *
	 * @param array      $eval   Evaluation.
	 * @param array      $item   Installed plugin.
	 * @param array|null $record Stored data.
	 * @return string
	 */
	public static function details_html( array $eval, array $item, $record ) {
		$html = '';
		foreach ( self::details( $eval, $item, $record ) as $line ) {
			$html .= '<span class="fadewatch-detail">' . esc_html( $line ) . '</span>';
		}
		return $html;
	}

	/**
	 * Formats a Y-m-d date with the site's date format.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	public static function format_ymd( $ymd ) {
		$ts = Fadewatch_API::parse_date( $ymd );
		return $ts ? wp_date( get_option( 'date_format' ), $ts, new DateTimeZone( 'UTC' ) ) : $ymd;
	}

	/**
	 * WordPress.org page URL for a plugin.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function org_url( $slug ) {
		return 'https://wordpress.org/plugins/' . rawurlencode( $slug ) . '/';
	}

	/**
	 * Whether the plugin has a public WordPress.org page worth linking to.
	 *
	 * @param array $eval Evaluation.
	 * @return bool
	 */
	public static function has_org_page( array $eval ) {
		return in_array( $eval['status'], array( Fadewatch_Status::MAINTAINED, Fadewatch_Status::AGEING, Fadewatch_Status::ABANDONED, Fadewatch_Status::CLOSED ), true );
	}
}
