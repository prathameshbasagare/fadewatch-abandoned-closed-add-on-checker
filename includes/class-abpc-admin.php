<?php
/**
 * Report and settings page (Tools → Plugin Maintenance, or
 * Network Admin → Plugins → Plugin Maintenance on multisite).
 *
 * @package Stalewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
final class ABPC_Admin {

	const PAGE = 'stalewatch';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Registers hooks.
	 */
	public static function register_hooks() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_abpc_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_abpc_export_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );

		$basename = plugin_basename( ABPC_FILE );
		add_filter( 'plugin_action_links_' . $basename, array( __CLASS__, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * URL of the report page.
	 *
	 * @param string $tab Optional tab (settings).
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$url = is_multisite()
			? network_admin_url( 'plugins.php?page=' . self::PAGE )
			: admin_url( 'tools.php?page=' . self::PAGE );

		return $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * Adds the page. No top-level menu: Tools on single sites, Plugins in
	 * the network admin.
	 */
	public static function add_menu() {
		$title = __( 'Plugin Maintenance', 'stalewatch' );

		if ( is_multisite() ) {
			self::$hook = (string) add_submenu_page( 'plugins.php', $title, $title, ABPC_Settings::capability(), self::PAGE, array( __CLASS__, 'render_page' ) );
		} else {
			self::$hook = (string) add_management_page( $title, $title, ABPC_Settings::capability(), self::PAGE, array( __CLASS__, 'render_page' ) );
		}
	}

	/**
	 * Enqueues assets on our page and on the Plugins screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		$is_page    = self::$hook && self::$hook === $hook_suffix;
		$is_plugins = 'plugins.php' === $hook_suffix;

		if ( ! $is_page && ! $is_plugins ) {
			return;
		}

		wp_enqueue_style( 'abpc-admin', ABPC_URL . 'assets/css/admin.css', array( 'dashicons' ), ABPC_VERSION );

		if ( ! $is_page ) {
			return;
		}

		wp_enqueue_script( 'abpc-admin', ABPC_URL . 'assets/js/admin.js', array(), ABPC_VERSION, true );

		$state = ABPC_Store::state();
		$meta  = ABPC_Store::meta();

		wp_localize_script(
			'abpc-admin',
			'abpcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( ABPC_Ajax::NONCE ),
				// Continue an unfinished scan in the browser (covers broken WP-Cron),
				// or run the very first scan if none has completed yet.
				'resume'  => ! empty( $state['queue'] ),
				'start'   => empty( $state['queue'] ) && ! $meta['last_full_scan'] && ! self::is_settings_tab(),
				'i18n'    => array(
					/* translators: 1: number of checked plugins, 2: total number of plugins. */
					'progress' => __( 'Checked %1$d of %2$d plugins…', 'stalewatch' ),
					'waiting'  => __( 'Another check is running. Waiting…', 'stalewatch' ),
					'done'     => __( 'Check complete. Reloading…', 'stalewatch' ),
					'failed'   => __( 'The check failed. Please try again.', 'stalewatch' ),
					'starting' => __( 'Starting check…', 'stalewatch' ),
				),
			)
		);
	}

	/**
	 * Adds a "Settings" link to this plugin's row on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ABPC_Settings::current_user_can() ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Report', 'stalewatch' ) ),
				sprintf( '<a href="%s">%s</a>', esc_url( self::page_url( 'settings' ) ), esc_html__( 'Settings', 'stalewatch' ) )
			);
		}
		return $links;
	}

	/**
	 * Whether the settings tab is requested.
	 *
	 * @return bool
	 */
	private static function is_settings_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		return isset( $_GET['tab'] ) && 'settings' === sanitize_key( wp_unslash( $_GET['tab'] ) );
	}

	/**
	 * Renders the page.
	 */
	public static function render_page() {
		if ( ! ABPC_Settings::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'stalewatch' ), 403 );
		}

		$settings_tab = self::is_settings_tab();
		?>
		<div class="wrap abpc-wrap">
			<h1><?php esc_html_e( 'Plugin Maintenance', 'stalewatch' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secondary menu', 'stalewatch' ); ?>">
				<a href="<?php echo esc_url( self::page_url() ); ?>" class="nav-tab<?php echo $settings_tab ? '' : ' nav-tab-active'; ?>"<?php echo $settings_tab ? '' : ' aria-current="page"'; ?>><?php esc_html_e( 'Report', 'stalewatch' ); ?></a>
				<a href="<?php echo esc_url( self::page_url( 'settings' ) ); ?>" class="nav-tab<?php echo $settings_tab ? ' nav-tab-active' : ''; ?>"<?php echo $settings_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'stalewatch' ); ?></a>
			</nav>

			<?php
			self::render_notices();

			if ( $settings_tab ) {
				self::render_settings();
			} else {
				self::render_report();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Notices for this page.
	 */
	private static function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by our own redirect.
		if ( isset( $_GET['abpc_saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['abpc_saved'] ) ) ) {
			wp_admin_notice(
				__( 'Settings saved.', 'stalewatch' ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			wp_admin_notice(
				__( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Automatic checks only run if a real cron job calls wp-cron.php. You can always use "Check now".', 'stalewatch' ),
				array( 'type' => 'info' )
			);
		}

		if ( ABPC_Settings::scheduled_scans_paused() ) {
			wp_admin_notice(
				__( 'Scheduled checks are paused because this is a local environment. "Check now" still works.', 'stalewatch' ),
				array( 'type' => 'info' )
			);
		}
	}

	/**
	 * Report tab.
	 */
	private static function render_report() {
		$rows   = ABPC_Report::rows();
		$counts = ABPC_Report::counts();
		$meta   = ABPC_Store::meta();
		$extras = ABPC_Resolver::unchecked_extras();

		$summary = array(
			ABPC_Status::CLOSED,
			ABPC_Status::ABANDONED,
			ABPC_Status::AGEING,
			ABPC_Status::MAINTAINED,
			ABPC_Status::NOT_ON_ORG,
			ABPC_Status::UNKNOWN,
		);

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=abpc_export_csv' ), 'abpc_export_csv' );
		?>
		<ul class="abpc-summary">
			<?php foreach ( $summary as $status ) : ?>
				<li class="abpc-summary__item abpc-status--<?php echo esc_attr( $status ); ?>">
					<span class="dashicons <?php echo esc_attr( ABPC_Render::icon( $status ) ); ?>" aria-hidden="true"></span>
					<span class="abpc-summary__count"><?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?></span>
					<span class="abpc-summary__label"><?php echo esc_html( ABPC_Status::label( $status ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="abpc-toolbar">
			<p class="abpc-toolbar__meta">
				<?php
				if ( $meta['last_full_scan'] ) {
					printf(
						/* translators: 1: relative time such as "3 hours", 2: date and time. */
						esc_html__( 'Last full check: %1$s ago (%2$s).', 'stalewatch' ),
						esc_html( human_time_diff( (int) $meta['last_full_scan'] ) ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $meta['last_full_scan'] ) )
					);
				} else {
					esc_html_e( 'No full check has completed yet.', 'stalewatch' );
				}
				?>
			</p>
			<button type="button" class="button button-primary" id="abpc-check-now"><?php esc_html_e( 'Check now', 'stalewatch' ); ?></button>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'stalewatch' ); ?></a>
		</div>

		<div class="abpc-progress" id="abpc-progress" hidden>
			<progress id="abpc-progress-bar" max="100" value="0"></progress>
			<p id="abpc-progress-text" class="abpc-progress__text" role="status" aria-live="polite"></p>
		</div>

		<table class="widefat striped abpc-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Maintenance status of installed plugins, most serious first', 'stalewatch' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Plugin', 'stalewatch' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'stalewatch' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tested up to', 'stalewatch' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Active installs', 'stalewatch' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rating', 'stalewatch' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Support threads resolved', 'stalewatch' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version (installed / latest)', 'stalewatch' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No plugins found.', 'stalewatch' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $row ) :
					$item   = $row['item'];
					$eval   = $row['eval'];
					$record = $row['record'];
					$ok     = is_array( $record ) && isset( $record['result'] ) && 'ok' === $record['result'] && ABPC_Status::UNVERIFIED !== $eval['status'];
					?>
					<tr>
						<td class="abpc-table__plugin">
							<strong><?php echo esc_html( $item['name'] ); ?></strong>
							<span class="abpc-detail">
								<?php echo esc_html( $item['active'] ? __( 'Active', 'stalewatch' ) : __( 'Inactive', 'stalewatch' ) ); ?>
								<?php if ( ABPC_Render::has_org_page( $eval ) && ABPC_Status::CLOSED !== $eval['status'] ) : ?>
									· <a href="<?php echo esc_url( ABPC_Render::org_url( $item['slug'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['slug'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'stalewatch' ); ?></span></a>
								<?php else : ?>
									· <?php echo esc_html( $item['slug'] ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td>
							<?php
							echo ABPC_Render::badge( $eval ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ABPC_Render::badge().
							echo ABPC_Render::details_html( $eval, $item, $record ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ABPC_Render::details_html().
							?>
						</td>
						<td><?php echo $ok && '' !== $record['tested'] ? esc_html( $record['tested'] ) : '&mdash;'; ?></td>
						<td><?php echo $ok ? esc_html( number_format_i18n( $record['active_installs'] ) . '+' ) : '&mdash;'; ?></td>
						<td>
							<?php
							if ( $ok && $record['num_ratings'] > 0 ) {
								printf(
									/* translators: 1: rating percentage, 2: number of ratings. */
									esc_html__( '%1$d%% (%2$s ratings)', 'stalewatch' ),
									(int) $record['rating'],
									esc_html( number_format_i18n( $record['num_ratings'] ) )
								);
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td>
							<?php
							if ( $ok && $record['support_threads'] > 0 ) {
								printf(
									/* translators: 1: resolved threads, 2: total threads in the last two months. */
									esc_html__( '%1$d of %2$d', 'stalewatch' ),
									(int) $record['support_threads_resolved'],
									(int) $record['support_threads']
								);
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td>
							<?php
							echo esc_html( '' !== $item['version'] ? $item['version'] : '?' );
							if ( $ok && '' !== $record['version'] ) {
								echo ' / ' . esc_html( $record['version'] );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $extras ) : ?>
			<h2><?php esc_html_e( 'Not checked', 'stalewatch' ); ?></h2>
			<p><?php esc_html_e( 'Must-use plugins and drop-ins are not installed from WordPress.org, so they are not checked.', 'stalewatch' ); ?></p>
			<ul class="abpc-extras">
				<?php foreach ( $extras as $extra ) : ?>
					<li>
						<?php echo esc_html( $extra['name'] ); ?>
						<span class="abpc-detail-inline">(<?php echo esc_html( 'mustuse' === $extra['type'] ? __( 'must-use plugin', 'stalewatch' ) : __( 'drop-in', 'stalewatch' ) ); ?>, <code><?php echo esc_html( $extra['file'] ); ?></code>)</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Settings tab.
	 */
	private static function render_settings() {
		$s = ABPC_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="abpc_save_settings" />
			<?php wp_nonce_field( 'abpc_save_settings' ); ?>

			<h2><?php esc_html_e( 'Thresholds', 'stalewatch' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="abpc_ageing_months"><?php esc_html_e( '"Ageing" after', 'stalewatch' ); ?></label></th>
					<td>
						<input type="number" min="1" max="120" step="1" class="small-text" id="abpc_ageing_months" name="abpc_ageing_months" value="<?php echo esc_attr( $s['ageing_months'] ); ?>" aria-describedby="abpc_ageing_months_desc" />
						<?php esc_html_e( 'months without an update', 'stalewatch' ); ?>
						<p class="description" id="abpc_ageing_months_desc"><?php esc_html_e( 'Default: 12.', 'stalewatch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="abpc_abandoned_months"><?php esc_html_e( '"Possibly abandoned" after', 'stalewatch' ); ?></label></th>
					<td>
						<input type="number" min="2" max="240" step="1" class="small-text" id="abpc_abandoned_months" name="abpc_abandoned_months" value="<?php echo esc_attr( $s['abandoned_months'] ); ?>" aria-describedby="abpc_abandoned_months_desc" />
						<?php esc_html_e( 'months without an update', 'stalewatch' ); ?>
						<p class="description" id="abpc_abandoned_months_desc"><?php esc_html_e( 'Default: 24, the same as Wordfence. Must be higher than the "Ageing" value.', 'stalewatch' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'What to check', 'stalewatch' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Inactive plugins', 'stalewatch' ); ?></th>
					<td>
						<label><input type="checkbox" name="abpc_include_inactive" value="1" <?php checked( $s['include_inactive'] ); ?> /> <?php esc_html_e( 'Check inactive plugins too', 'stalewatch' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended: the files of inactive plugins are still on the server and can still be attacked.', 'stalewatch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Local environments', 'stalewatch' ); ?></th>
					<td>
						<label><input type="checkbox" name="abpc_skip_local" value="1" <?php checked( $s['skip_local'] ); ?> /> <?php esc_html_e( 'Pause scheduled checks when the environment type is "local"', 'stalewatch' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: environment type such as "production". */
								esc_html__( 'Current environment type: %s.', 'stalewatch' ),
								'<code>' . esc_html( wp_get_environment_type() ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'E-mail notifications', 'stalewatch' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send e-mails', 'stalewatch' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Send e-mails', 'stalewatch' ); ?></legend>
							<label><input type="radio" name="abpc_notify" value="changes" <?php checked( $s['notify'], 'changes' ); ?> /> <?php esc_html_e( 'Only when something changes (recommended)', 'stalewatch' ); ?></label><br />
							<label><input type="radio" name="abpc_notify" value="weekly" <?php checked( $s['notify'], 'weekly' ); ?> /> <?php esc_html_e( 'When something changes, plus a weekly summary of open issues', 'stalewatch' ); ?></label><br />
							<label><input type="radio" name="abpc_notify" value="off" <?php checked( $s['notify'], 'off' ); ?> /> <?php esc_html_e( 'Never', 'stalewatch' ); ?></label>
							<p class="description"><?php esc_html_e( 'A plugin closed for a security issue triggers an immediate e-mail. Other changes are collected into one e-mail per check.', 'stalewatch' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="abpc_recipients"><?php esc_html_e( 'Recipients', 'stalewatch' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="abpc_recipients" name="abpc_recipients" value="<?php echo esc_attr( $s['recipients'] ); ?>" placeholder="<?php echo esc_attr( get_site_option( 'admin_email' ) ); ?>" aria-describedby="abpc_recipients_desc" />
						<p class="description" id="abpc_recipients_desc"><?php esc_html_e( 'Comma-separated e-mail addresses. Leave empty to use the administration e-mail address.', 'stalewatch' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'stalewatch' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Data', 'stalewatch' ); ?></th>
					<td>
						<label><input type="checkbox" name="abpc_delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?> /> <?php esc_html_e( 'Delete all data of this plugin when it is deleted', 'stalewatch' ); ?></label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Saves settings (admin-post.php works in both site and network admin).
	 */
	public static function handle_save_settings() {
		if ( ! ABPC_Settings::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'stalewatch' ), 403 );
		}
		check_admin_referer( 'abpc_save_settings' );

		$before = ABPC_Settings::get();

		$saved = ABPC_Settings::update(
			array(
				'ageing_months'       => isset( $_POST['abpc_ageing_months'] ) ? absint( wp_unslash( $_POST['abpc_ageing_months'] ) ) : 0,
				'abandoned_months'    => isset( $_POST['abpc_abandoned_months'] ) ? absint( wp_unslash( $_POST['abpc_abandoned_months'] ) ) : 0,
				'include_inactive'    => self::posted_checkbox( 'abpc_include_inactive' ),
				'skip_local'          => self::posted_checkbox( 'abpc_skip_local' ),
				'delete_on_uninstall' => self::posted_checkbox( 'abpc_delete_on_uninstall' ),
				'notify'              => isset( $_POST['abpc_notify'] ) ? sanitize_key( wp_unslash( $_POST['abpc_notify'] ) ) : '',
				'recipients'          => isset( $_POST['abpc_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['abpc_recipients'] ) ) : '',
			)
		);

		// Newly included inactive plugins need data.
		if ( $saved['include_inactive'] && ! $before['include_inactive'] ) {
			ABPC_Scanner::start_full_scan( 'settings' );
		}

		wp_safe_redirect( add_query_arg( 'abpc_saved', '1', self::page_url( 'settings' ) ) );
		exit;
	}

	/**
	 * Reads a checkbox from the settings form. Only called after the nonce
	 * check in handle_save_settings().
	 *
	 * @param string $name Field name.
	 * @return bool
	 */
	private static function posted_checkbox( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save_settings().
		return isset( $_POST[ $name ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
	}

	/**
	 * Streams the report as CSV.
	 */
	public static function handle_export_csv() {
		if ( ! ABPC_Settings::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'stalewatch' ), 403 );
		}
		check_admin_referer( 'abpc_export_csv' );

		$host     = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		$filename = sanitize_file_name( 'plugin-maintenance-' . $host . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to the output buffer, not the file system.
		$out = fopen( 'php://output', 'w' );

		self::csv_row(
			$out,
			array(
				__( 'Plugin', 'stalewatch' ),
				__( 'Plugin file', 'stalewatch' ),
				__( 'Slug', 'stalewatch' ),
				__( 'Active', 'stalewatch' ),
				__( 'Status', 'stalewatch' ),
				__( 'Severity', 'stalewatch' ),
				__( 'Details', 'stalewatch' ),
				__( 'Latest update release', 'stalewatch' ),
				__( 'Closed date', 'stalewatch' ),
				__( 'Closure reason', 'stalewatch' ),
				__( 'Tested up to', 'stalewatch' ),
				__( 'Active installs', 'stalewatch' ),
				__( 'Rating', 'stalewatch' ),
				__( 'Installed version', 'stalewatch' ),
				__( 'Latest version', 'stalewatch' ),
				__( 'Last checked', 'stalewatch' ),
			)
		);

		foreach ( ABPC_Report::rows() as $row ) {
			$item   = $row['item'];
			$eval   = $row['eval'];
			$record = is_array( $row['record'] ) ? $row['record'] : array();
			$ok     = isset( $record['result'] ) && 'ok' === $record['result'] && ABPC_Status::UNVERIFIED !== $eval['status'];

			self::csv_row(
				$out,
				array(
					$item['name'],
					$item['file'],
					$item['slug'],
					$item['active'] ? __( 'Yes', 'stalewatch' ) : __( 'No', 'stalewatch' ),
					ABPC_Status::label( $eval['status'] ),
					$eval['severity'],
					implode( ' ', ABPC_Render::details( $eval, $item, $row['record'] ) ),
					$eval['last_updated'] ? gmdate( 'Y-m-d', $eval['last_updated'] ) : '',
					$eval['closed_date'],
					$eval['reason_text'],
					$ok ? $record['tested'] : '',
					$ok ? $record['active_installs'] : '',
					$ok && $record['num_ratings'] ? $record['rating'] . '%' : '',
					$item['version'],
					$ok ? $record['version'] : '',
					! empty( $record['checked_at'] ) ? gmdate( 'Y-m-d H:i', (int) $record['checked_at'] ) . ' UTC' : '',
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See fopen() above.
		fclose( $out );
		exit;
	}

	/**
	 * Writes one CSV row, neutralising spreadsheet formula injection.
	 *
	 * @param resource $handle Output handle.
	 * @param array    $fields Fields.
	 */
	private static function csv_row( $handle, array $fields ) {
		foreach ( $fields as $i => $field ) {
			$field = (string) $field;
			if ( '' !== $field && ! is_numeric( $field ) && in_array( $field[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$field = "'" . $field;
			}
			$fields[ $i ] = $field;
		}
		// Explicit escape argument avoids the PHP 8.4 deprecation notice.
		fputcsv( $handle, $fields, ',', '"', '' );
	}

	/**
	 * Suggested privacy-policy text.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'Stalewatch sends the slugs (short names) of installed plugins to api.wordpress.org to look up their maintenance status. No personal data about visitors or users is sent or stored, and no tracking is used.', 'stalewatch' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Stalewatch', 'stalewatch' ), wp_kses_post( $content ) );
	}
}
