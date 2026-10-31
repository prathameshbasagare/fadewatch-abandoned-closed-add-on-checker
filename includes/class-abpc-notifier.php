<?php
/**
 * Plain-text e-mail notifications.
 *
 * @package Stalewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notifier.
 */
final class ABPC_Notifier {

	/**
	 * Sends one e-mail summarising changes since the last scan.
	 * Nothing is sent when nothing changed.
	 *
	 * @param array $changes Changes from ABPC_Store::pull_changes().
	 * @return bool Whether an e-mail was sent.
	 */
	public static function send_digest( array $changes ) {
		if ( empty( $changes ) || 'off' === ABPC_Settings::get( 'notify' ) ) {
			return false;
		}

		$groups = array(
			'closed'    => array(),
			'abandoned' => array(),
			'reopened'  => array(),
		);
		foreach ( $changes as $change ) {
			if ( isset( $change['type'], $groups[ $change['type'] ] ) ) {
				// Keyed by file so the same plugin is listed once.
				$groups[ $change['type'] ][ $change['file'] ] = $change;
			}
		}

		$lines = array(
			/* translators: %s: site or network name. */
			sprintf( __( 'Stalewatch found changes on %s:', 'stalewatch' ), self::site_name() ),
			'',
		);

		if ( $groups['closed'] ) {
			$lines[] = __( 'Closed on WordPress.org:', 'stalewatch' );
			foreach ( $groups['closed'] as $change ) {
				$lines[] = '- ' . self::closed_line( $change );
			}
			$lines[] = '';
		}

		if ( $groups['abandoned'] ) {
			$thresholds = ABPC_Settings::thresholds();
			$lines[]    = sprintf(
				/* translators: %d: number of months. */
				_n( 'Possibly abandoned (no update in over %d month):', 'Possibly abandoned (no update in over %d months):', $thresholds['abandoned'], 'stalewatch' ),
				$thresholds['abandoned']
			);
			foreach ( $groups['abandoned'] as $change ) {
				$lines[] = '- ' . self::abandoned_line( $change );
			}
			$lines[] = '';
		}

		if ( $groups['reopened'] ) {
			$lines[] = __( 'Reopened on WordPress.org:', 'stalewatch' );
			foreach ( $groups['reopened'] as $change ) {
				$lines[] = '- ' . self::name_line( $change );
			}
			$lines[] = '';
		}

		if ( 2 === count( $lines ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: site or network name. */
			__( '[%s] Plugin maintenance changes', 'stalewatch' ),
			self::site_name()
		);

		return self::send( $subject, $lines );
	}

	/**
	 * Sends an immediate alert when a plugin is closed for a security issue.
	 *
	 * @param array $change Change data.
	 * @return bool
	 */
	public static function send_security_alert( array $change ) {
		if ( 'off' === ABPC_Settings::get( 'notify' ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: 1: site or network name, 2: plugin name. */
			__( '[%1$s] Security alert: %2$s was closed on WordPress.org', 'stalewatch' ),
			self::site_name(),
			$change['name']
		);

		$lines = array(
			__( 'An installed plugin was closed on WordPress.org because of a security issue:', 'stalewatch' ),
			'',
			'- ' . self::closed_line( $change ),
			'',
			__( 'Closed plugins no longer receive updates. Deactivate it and replace it with a maintained alternative as soon as possible.', 'stalewatch' ),
			'',
		);

		return self::send( $subject, $lines );
	}

	/**
	 * Weekly summary of open issues (only when that setting is chosen and
	 * there is something to report).
	 *
	 * @return bool
	 */
	public static function send_weekly_summary() {
		if ( 'weekly' !== ABPC_Settings::get( 'notify' ) ) {
			return false;
		}

		$closed    = array();
		$abandoned = array();
		foreach ( ABPC_Report::rows() as $row ) {
			$change = array(
				'name'         => $row['item']['name'],
				'slug'         => $row['item']['slug'],
				'closed_date'  => $row['eval']['closed_date'],
				'reason_text'  => $row['eval']['reason_text'],
				'last_updated' => $row['eval']['last_updated'],
			);
			if ( ABPC_Status::CLOSED === $row['eval']['status'] ) {
				$closed[] = self::closed_line( $change );
			} elseif ( ABPC_Status::ABANDONED === $row['eval']['status'] ) {
				$abandoned[] = self::abandoned_line( $change );
			}
		}

		if ( ! $closed && ! $abandoned ) {
			return false;
		}

		$lines = array(
			/* translators: %s: site or network name. */
			sprintf( __( 'Weekly plugin maintenance summary for %s:', 'stalewatch' ), self::site_name() ),
			'',
		);
		if ( $closed ) {
			$lines[] = __( 'Closed on WordPress.org:', 'stalewatch' );
			foreach ( $closed as $line ) {
				$lines[] = '- ' . $line;
			}
			$lines[] = '';
		}
		if ( $abandoned ) {
			$lines[] = __( 'Possibly abandoned:', 'stalewatch' );
			foreach ( $abandoned as $line ) {
				$lines[] = '- ' . $line;
			}
			$lines[] = '';
		}

		$subject = sprintf(
			/* translators: %s: site or network name. */
			__( '[%s] Weekly plugin maintenance summary', 'stalewatch' ),
			self::site_name()
		);

		return self::send( $subject, $lines );
	}

	/**
	 * Adds the footer and sends the e-mail.
	 *
	 * @param string   $subject Subject.
	 * @param string[] $lines   Body lines.
	 * @return bool
	 */
	private static function send( $subject, array $lines ) {
		$recipients = ABPC_Settings::recipients();
		if ( empty( $recipients ) ) {
			return false;
		}

		$lines[] = __( 'Full report:', 'stalewatch' ) . ' ' . ABPC_Admin::page_url();
		$lines[] = '';
		$lines[] = __( 'You receive this e-mail because notifications are enabled in Stalewatch. Change this here:', 'stalewatch' ) . ' ' . ABPC_Admin::page_url( 'settings' );

		return wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Line for a closed plugin.
	 *
	 * @param array $change Change data.
	 * @return string
	 */
	private static function closed_line( array $change ) {
		$parts = array( self::name_line( $change ) );
		if ( ! empty( $change['closed_date'] ) ) {
			/* translators: %s: date. */
			$parts[] = sprintf( __( 'closed on %s', 'stalewatch' ), ABPC_Render::format_ymd( $change['closed_date'] ) );
		}
		if ( ! empty( $change['reason_text'] ) ) {
			/* translators: %s: closure reason, e.g. "Security Issue". */
			$parts[] = sprintf( __( 'reason: %s', 'stalewatch' ), $change['reason_text'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Line for a possibly abandoned plugin.
	 *
	 * @param array $change Change data.
	 * @return string
	 */
	private static function abandoned_line( array $change ) {
		if ( empty( $change['last_updated'] ) ) {
			return self::name_line( $change );
		}
		return sprintf(
			/* translators: 1: plugin name and slug, 2: date. */
			__( '%1$s, latest update release: %2$s', 'stalewatch' ),
			self::name_line( $change ),
			wp_date( get_option( 'date_format' ), (int) $change['last_updated'] )
		);
	}

	/**
	 * "Name (slug)".
	 *
	 * @param array $change Change data.
	 * @return string
	 */
	private static function name_line( array $change ) {
		return sprintf( '%s (%s)', wp_strip_all_tags( $change['name'] ), $change['slug'] );
	}

	/**
	 * Site name, or network name on multisite.
	 *
	 * @return string
	 */
	private static function site_name() {
		$name = is_multisite() ? get_network()->site_name : get_bloginfo( 'name' );
		return wp_specialchars_decode( (string) $name, ENT_QUOTES );
	}
}
