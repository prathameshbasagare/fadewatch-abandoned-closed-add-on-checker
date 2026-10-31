<?php
/**
 * Status model: turns stored data into exactly one status per plugin.
 *
 * @package AbandonedPluginChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status.
 */
final class ABPC_Status {

	const MAINTAINED = 'maintained';
	const AGEING     = 'ageing';
	const ABANDONED  = 'abandoned';
	const CLOSED     = 'closed';
	const NOT_ON_ORG = 'not_on_org';
	const UNKNOWN    = 'unknown';
	const UNVERIFIED = 'unverified';
	const PENDING    = 'pending';
	const SKIPPED    = 'skipped';

	/** Results older than this are shown as "data out of date". */
	const STALE_AFTER = WEEK_IN_SECONDS;

	/** "Tested up to" this many major releases behind triggers a warning. */
	const TESTED_BEHIND_WARNING = 3;

	/**
	 * All statuses.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::CLOSED,
			self::ABANDONED,
			self::AGEING,
			self::MAINTAINED,
			self::NOT_ON_ORG,
			self::UNVERIFIED,
			self::UNKNOWN,
			self::PENDING,
			self::SKIPPED,
		);
	}

	/**
	 * Human-readable labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels() {
		return array(
			self::MAINTAINED => __( 'Maintained', 'abandoned-plugin-checker' ),
			self::AGEING     => __( 'Ageing', 'abandoned-plugin-checker' ),
			self::ABANDONED  => __( 'Possibly abandoned', 'abandoned-plugin-checker' ),
			self::CLOSED     => __( 'Closed', 'abandoned-plugin-checker' ),
			self::NOT_ON_ORG => __( 'Not on WordPress.org', 'abandoned-plugin-checker' ),
			self::UNKNOWN    => __( "Couldn't check", 'abandoned-plugin-checker' ),
			self::UNVERIFIED => __( 'Not verified', 'abandoned-plugin-checker' ),
			self::PENDING    => __( 'Not checked yet', 'abandoned-plugin-checker' ),
			self::SKIPPED    => __( 'Not checked', 'abandoned-plugin-checker' ),
		);
	}

	/**
	 * Label for one status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function label( $status ) {
		$labels = self::labels();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Numeric rank for a severity, used for sorting.
	 *
	 * @param string $severity Severity.
	 * @return int
	 */
	public static function severity_rank( $severity ) {
		$ranks = array(
			'critical' => 5,
			'high'     => 4,
			'medium'   => 3,
			'low'      => 2,
			'info'     => 1,
			'none'     => 0,
		);
		return isset( $ranks[ $severity ] ) ? $ranks[ $severity ] : 0;
	}

	/**
	 * Computes the status of one installed plugin.
	 *
	 * @param array      $item    Installed plugin (from ABPC_Resolver).
	 * @param array|null $record  Stored WordPress.org data, if any.
	 * @param array      $context {
	 *     @type array  $thresholds  Thresholds in months.
	 *     @type int    $now         Current timestamp.
	 *     @type string $wp_version  Newest known WordPress version.
	 * }
	 * @return array
	 */
	public static function evaluate( array $item, $record, array $context ) {
		$now = isset( $context['now'] ) ? (int) $context['now'] : time();

		$out = array(
			'status'        => self::PENDING,
			'severity'      => 'info',
			'note'          => '',
			'last_updated'  => 0,
			'closed_date'   => '',
			'reason'        => '',
			'reason_text'   => '',
			'stale'         => false,
			'error'         => '',
			'tested_behind' => 0,
		);

		if ( '' !== $item['skip'] ) {
			$out['note'] = $item['skip'];
			if ( 'update_uri' === $item['skip'] ) {
				$out['status'] = self::NOT_ON_ORG;
			} else {
				$out['status']   = self::SKIPPED;
				$out['severity'] = 'none';
			}
			return self::apply_filter( $out, $item, $record );
		}

		if ( ! is_array( $record ) || empty( $record['result'] ) ) {
			return self::apply_filter( $out, $item, $record );
		}

		$out['error'] = isset( $record['error'] ) ? (string) $record['error'] : '';

		switch ( $record['result'] ) {
			case 'error':
				$out['status'] = self::UNKNOWN;
				return self::apply_filter( $out, $item, $record );

			case 'not_found':
				$out['status'] = self::NOT_ON_ORG;
				$out['note']   = preg_match( '/-(pro|premium)$/', $item['slug'] ) ? 'premium' : 'not_found';
				break;

			case 'closed':
			case 'ok':
				if ( ! self::matches_installed( $item, $record ) ) {
					$out['status'] = self::UNVERIFIED;
					$out['note']   = 'collision';
					break;
				}

				if ( 'closed' === $record['result'] ) {
					$out['status']      = self::CLOSED;
					$out['closed_date'] = isset( $record['closed_date'] ) ? (string) $record['closed_date'] : '';
					$out['reason']      = isset( $record['reason'] ) ? (string) $record['reason'] : '';
					$out['reason_text'] = isset( $record['reason_text'] ) ? (string) $record['reason_text'] : '';
					$out['severity']    = self::closed_severity( $out['reason'] );
					break;
				}

				$out['last_updated']  = isset( $record['last_updated'] ) ? (int) $record['last_updated'] : 0;
				$out['tested_behind'] = self::majors_behind( isset( $record['tested'] ) ? $record['tested'] : '', isset( $context['wp_version'] ) ? $context['wp_version'] : '' );

				$age = self::age_status( $out['last_updated'], $context['thresholds'], $now );
				if ( null === $age ) {
					$out['status'] = self::UNKNOWN;
				} else {
					$out['status']   = $age;
					$out['severity'] = self::ABANDONED === $age ? 'medium' : ( self::AGEING === $age ? 'low' : 'none' );
				}
				break;

			default:
				$out['status'] = self::UNKNOWN;
		}

		$checked_at   = isset( $record['checked_at'] ) ? (int) $record['checked_at'] : 0;
		$out['stale'] = $checked_at > 0 && ( $now - $checked_at ) > self::STALE_AFTER;

		return self::apply_filter( $out, $item, $record );
	}

	/**
	 * Severity of a closure, based on its reason.
	 *
	 * Author requests are not a security signal, so they are not scary.
	 *
	 * @param string $reason Reason key from WordPress.org.
	 * @return string
	 */
	public static function closed_severity( $reason ) {
		if ( 'security-issue' === $reason ) {
			return 'critical';
		}
		if ( 'author-request' === $reason ) {
			return 'medium';
		}
		return 'high';
	}

	/**
	 * Age status from a last-updated timestamp.
	 *
	 * @param int   $last_updated Timestamp.
	 * @param array $thresholds   Thresholds in months.
	 * @param int   $now          Current timestamp.
	 * @return string|null Null when the date is unknown.
	 */
	public static function age_status( $last_updated, array $thresholds, $now ) {
		if ( $last_updated <= 0 ) {
			return null;
		}

		$base             = ( new DateTimeImmutable( '@' . $now ) );
		$ageing_cutoff    = $base->modify( '-' . (int) $thresholds['ageing'] . ' months' )->getTimestamp();
		$abandoned_cutoff = $base->modify( '-' . (int) $thresholds['abandoned'] . ' months' )->getTimestamp();

		if ( $last_updated >= $ageing_cutoff ) {
			return self::MAINTAINED;
		}
		if ( $last_updated >= $abandoned_cutoff ) {
			return self::AGEING;
		}
		return self::ABANDONED;
	}

	/**
	 * How many major releases "tested up to" is behind a WordPress version.
	 *
	 * Major releases are x.y (6.9 is followed by 7.0), so x*10+y is a
	 * sequential index.
	 *
	 * @param string $tested     "Tested up to" value.
	 * @param string $wp_version WordPress version to compare with.
	 * @return int
	 */
	public static function majors_behind( $tested, $wp_version ) {
		$a = self::major_index( $tested );
		$b = self::major_index( $wp_version );
		if ( null === $a || null === $b ) {
			return 0;
		}
		return max( 0, $b - $a );
	}

	/**
	 * Sequential index of a major version.
	 *
	 * @param string $version Version string.
	 * @return int|null
	 */
	private static function major_index( $version ) {
		if ( ! is_string( $version ) || ! preg_match( '/^(\d+)\.(\d)/', $version, $m ) ) {
			return null;
		}
		return (int) $m[1] * 10 + (int) $m[2];
	}

	/**
	 * Slug-collision guard: does the WordPress.org entry look like the
	 * plugin that is actually installed?
	 *
	 * @param array $item   Installed plugin.
	 * @param array $record WordPress.org data.
	 * @return bool
	 */
	public static function matches_installed( array $item, array $record ) {
		// The plugin itself links to its WordPress.org page.
		if ( '' !== $item['plugin_uri'] && preg_match( '#wordpress\.org/plugins/' . preg_quote( $item['slug'], '#' ) . '(/|$)#i', $item['plugin_uri'] ) ) {
			return true;
		}

		$remote_name = isset( $record['name'] ) ? self::normalise( $record['name'] ) : '';
		$local_name  = self::normalise( $item['name'] );

		// Nothing to compare with: trust the slug.
		if ( '' === $remote_name || '' === $local_name ) {
			return true;
		}

		if ( self::similar( $local_name, $remote_name, 60 ) ) {
			return true;
		}

		$remote_author = isset( $record['author'] ) ? self::normalise( $record['author'] ) : '';
		$local_author  = self::normalise( $item['author'] );

		return '' !== $remote_author && '' !== $local_author && self::similar( $local_author, $remote_author, 70 );
	}

	/**
	 * Whether two normalised strings are similar enough.
	 *
	 * @param string $a         First string.
	 * @param string $b         Second string.
	 * @param int    $threshold Minimum similarity percentage.
	 * @return bool
	 */
	private static function similar( $a, $b, $threshold ) {
		if ( $a === $b ) {
			return true;
		}
		// Containment, e.g. "akismet" in "akismetantispamspamprotection".
		// Very short names are ignored to avoid matching generic words.
		$shorter = strlen( $a ) <= strlen( $b ) ? $a : $b;
		$longer  = $shorter === $a ? $b : $a;
		if ( strlen( $shorter ) >= 5 && false !== strpos( $longer, $shorter ) ) {
			return true;
		}
		similar_text( $a, $b, $percent );
		return $percent >= $threshold;
	}

	/**
	 * Normalises a name for comparison.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function normalise( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
		$value = strtolower( remove_accents( $value ) );
		return (string) preg_replace( '/[^a-z0-9]/', '', $value );
	}

	/**
	 * Applies the public `abpc_status` filter and validates the result.
	 *
	 * @param array      $out    Evaluation.
	 * @param array      $item   Installed plugin.
	 * @param array|null $record WordPress.org data.
	 * @return array
	 */
	private static function apply_filter( array $out, array $item, $record ) {
		/**
		 * Filters the computed maintenance status of a plugin.
		 *
		 * @param array      $out    Evaluation (status, severity, note, dates...).
		 * @param array      $item   Installed plugin data.
		 * @param array|null $record Stored WordPress.org data.
		 */
		$filtered = apply_filters( 'abpc_status', $out, $item, $record );

		if ( is_array( $filtered ) && isset( $filtered['status'] ) && in_array( $filtered['status'], self::all(), true ) ) {
			return array_merge( $out, $filtered );
		}
		return $out;
	}
}
