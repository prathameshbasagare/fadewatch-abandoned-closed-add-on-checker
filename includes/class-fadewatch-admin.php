<?php
/**
 * Report and settings page (Tools → Plugin Maintenance, or
 * Network Admin → Plugins → Plugin Maintenance on multisite).
 *
 * @package Fadewatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
final class Fadewatch_Admin {

	const PAGE = 'fadewatch';

	/** Set on activation; shows the consent prompt once on the Plugins screen. */
	const PROMPT_FLAG = 'fadewatch_consent_prompt';

	/**
	 * Whether the one-time activation prompt is shown in this request.
	 *
	 * @var bool
	 */
	private static $activation_prompt = false;

	/**
	 * Whether the Plugins screen offers "Allow checks" in this request.
	 *
	 * @var bool
	 */
	private static $plugins_consent = false;

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
		add_action( 'admin_post_fadewatch_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_fadewatch_export_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_post_fadewatch_grant_consent', array( __CLASS__, 'handle_grant_consent' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_action( 'load-plugins.php', array( __CLASS__, 'setup_plugins_screen_consent' ) );

		$basename = plugin_basename( FADEWATCH_FILE );
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
		$title = __( 'Plugin Maintenance', 'fadewatch-abandoned-closed-add-on-checker' );

		if ( is_multisite() ) {
			self::$hook = (string) add_submenu_page( 'plugins.php', $title, $title, Fadewatch_Settings::capability(), self::PAGE, array( __CLASS__, 'render_page' ) );
		} else {
			self::$hook = (string) add_management_page( $title, $title, Fadewatch_Settings::capability(), self::PAGE, array( __CLASS__, 'render_page' ) );
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

		wp_enqueue_style( 'fadewatch-admin', FADEWATCH_URL . 'assets/css/admin.css', array( 'dashicons' ), FADEWATCH_VERSION );

		// The Plugins screen only needs the script while checks are off.
		if ( ! $is_page && ! self::$plugins_consent ) {
			return;
		}

		wp_enqueue_script( 'fadewatch-admin', FADEWATCH_URL . 'assets/js/admin.js', array(), FADEWATCH_VERSION, true );

		$state   = Fadewatch_Store::state();
		$meta    = Fadewatch_Store::meta();
		$allowed = Fadewatch_Settings::checks_allowed();
		$report  = $is_page && ! self::is_settings_tab();

		wp_localize_script(
			'fadewatch-admin',
			'fadewatchAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Fadewatch_Ajax::NONCE ),
				// The consent dialog opens by itself only once, on the Plugins
				// screen right after activation. Otherwise only on request.
				'autoConsent' => ! $allowed && self::$activation_prompt,
				'askConsent'  => ! $allowed,
				// Continue an unfinished scan in the browser (covers broken WP-Cron),
				// or run the very first scan if none has completed yet.
				'resume'      => $allowed && ! empty( $state['queue'] ),
				'start'       => $allowed && $report && empty( $state['queue'] ) && ! $meta['last_full_scan'],
				'i18n'        => array(
					/* translators: 1: number of checked plugins, 2: total number of plugins. */
					'progress' => __( 'Checked %1$d of %2$d plugins…', 'fadewatch-abandoned-closed-add-on-checker' ),
					'waiting'  => __( 'Another check is running. Waiting…', 'fadewatch-abandoned-closed-add-on-checker' ),
					'done'     => __( 'Check complete. Reloading…', 'fadewatch-abandoned-closed-add-on-checker' ),
					'failed'   => __( 'The check failed. Please try again.', 'fadewatch-abandoned-closed-add-on-checker' ),
					'starting' => __( 'Starting check…', 'fadewatch-abandoned-closed-add-on-checker' ),
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
		if ( Fadewatch_Settings::current_user_can() ) {
			array_unshift(
				$links,
				Fadewatch_Settings::checks_allowed()
					? sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Report', 'fadewatch-abandoned-closed-add-on-checker' ) )
					: sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( self::page_url() ), esc_html__( 'Allow checks', 'fadewatch-abandoned-closed-add-on-checker' ) ),
				sprintf( '<a href="%s">%s</a>', esc_url( self::page_url( 'settings' ) ), esc_html__( 'Settings', 'fadewatch-abandoned-closed-add-on-checker' ) )
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
		if ( ! Fadewatch_Settings::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'fadewatch-abandoned-closed-add-on-checker' ), 403 );
		}

		$settings_tab = self::is_settings_tab();
		?>
		<div class="wrap fadewatch-wrap">
			<h1><?php esc_html_e( 'Plugin Maintenance', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secondary menu', 'fadewatch-abandoned-closed-add-on-checker' ); ?>">
				<a href="<?php echo esc_url( self::page_url() ); ?>" class="nav-tab<?php echo $settings_tab ? '' : ' nav-tab-active'; ?>"<?php echo $settings_tab ? '' : ' aria-current="page"'; ?>><?php esc_html_e( 'Report', 'fadewatch-abandoned-closed-add-on-checker' ); ?></a>
				<a href="<?php echo esc_url( self::page_url( 'settings' ) ); ?>" class="nav-tab<?php echo $settings_tab ? ' nav-tab-active' : ''; ?>"<?php echo $settings_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'fadewatch-abandoned-closed-add-on-checker' ); ?></a>
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
		if ( isset( $_GET['fadewatch_saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['fadewatch_saved'] ) ) ) {
			wp_admin_notice(
				__( 'Settings saved.', 'fadewatch-abandoned-closed-add-on-checker' ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			wp_admin_notice(
				__( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Automatic checks only run if a real cron job calls wp-cron.php. You can always use "Check now".', 'fadewatch-abandoned-closed-add-on-checker' ),
				array( 'type' => 'info' )
			);
		}

		if ( Fadewatch_Settings::scheduled_scans_paused() ) {
			wp_admin_notice(
				__( 'Scheduled checks are paused because this is a local environment. "Check now" still works.', 'fadewatch-abandoned-closed-add-on-checker' ),
				array( 'type' => 'info' )
			);
		}
	}

	/**
	 * Report tab.
	 */
	private static function render_report() {
		$rows   = Fadewatch_Report::rows();
		$counts = Fadewatch_Report::counts();
		$meta   = Fadewatch_Store::meta();
		$extras = Fadewatch_Resolver::unchecked_extras();

		$summary = array(
			Fadewatch_Status::CLOSED,
			Fadewatch_Status::ABANDONED,
			Fadewatch_Status::AGEING,
			Fadewatch_Status::MAINTAINED,
			Fadewatch_Status::NOT_ON_ORG,
			Fadewatch_Status::UNKNOWN,
		);

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=fadewatch_export_csv' ), 'fadewatch_export_csv' );
		$allowed    = Fadewatch_Settings::checks_allowed();

		if ( ! $allowed ) {
			self::render_consent();
		}
		?>
		<ul class="fadewatch-summary">
			<?php foreach ( $summary as $status ) : ?>
				<li class="fadewatch-summary__item fadewatch-status--<?php echo esc_attr( $status ); ?>">
					<span class="dashicons <?php echo esc_attr( Fadewatch_Render::icon( $status ) ); ?>" aria-hidden="true"></span>
					<span class="fadewatch-summary__count"><?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?></span>
					<span class="fadewatch-summary__label"><?php echo esc_html( Fadewatch_Status::label( $status ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="fadewatch-toolbar">
			<p class="fadewatch-toolbar__meta">
				<?php
				if ( $meta['last_full_scan'] ) {
					printf(
						/* translators: 1: relative time such as "3 hours", 2: date and time. */
						esc_html__( 'Last full check: %1$s ago (%2$s).', 'fadewatch-abandoned-closed-add-on-checker' ),
						esc_html( human_time_diff( (int) $meta['last_full_scan'] ) ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $meta['last_full_scan'] ) )
					);
				} else {
					esc_html_e( 'No full check has completed yet.', 'fadewatch-abandoned-closed-add-on-checker' );
				}
				?>
			</p>
			<?php if ( $allowed ) : ?>
				<button type="button" class="button button-primary" id="fadewatch-check-now"><?php esc_html_e( 'Check now', 'fadewatch-abandoned-closed-add-on-checker' ); ?></button>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'fadewatch-abandoned-closed-add-on-checker' ); ?></a>
		</div>

		<div class="fadewatch-progress" id="fadewatch-progress" hidden>
			<progress id="fadewatch-progress-bar" max="100" value="0"></progress>
			<p id="fadewatch-progress-text" class="fadewatch-progress__text" role="status" aria-live="polite"></p>
		</div>

		<table class="widefat striped fadewatch-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Maintenance status of installed plugins, most serious first', 'fadewatch-abandoned-closed-add-on-checker' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Plugin', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tested up to', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Active installs', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rating', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Support threads resolved', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version (installed / latest)', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No plugins found.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $row ) :
					$item   = $row['item'];
					$eval   = $row['eval'];
					$record = $row['record'];
					$ok     = is_array( $record ) && isset( $record['result'] ) && 'ok' === $record['result'] && Fadewatch_Status::UNVERIFIED !== $eval['status'];
					?>
					<tr>
						<td class="fadewatch-table__plugin">
							<strong><?php echo esc_html( $item['name'] ); ?></strong>
							<span class="fadewatch-detail">
								<?php echo esc_html( $item['active'] ? __( 'Active', 'fadewatch-abandoned-closed-add-on-checker' ) : __( 'Inactive', 'fadewatch-abandoned-closed-add-on-checker' ) ); ?>
								<?php if ( Fadewatch_Render::has_org_page( $eval ) && Fadewatch_Status::CLOSED !== $eval['status'] ) : ?>
									· <a href="<?php echo esc_url( Fadewatch_Render::org_url( $item['slug'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['slug'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'fadewatch-abandoned-closed-add-on-checker' ); ?></span></a>
								<?php else : ?>
									· <?php echo esc_html( $item['slug'] ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td>
							<?php
							echo Fadewatch_Render::badge( $eval ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Fadewatch_Render::badge().
							echo Fadewatch_Render::details_html( $eval, $item, $record ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Fadewatch_Render::details_html().
							?>
						</td>
						<td><?php echo $ok && '' !== $record['tested'] ? esc_html( $record['tested'] ) : '&mdash;'; ?></td>
						<td><?php echo $ok ? esc_html( number_format_i18n( $record['active_installs'] ) . '+' ) : '&mdash;'; ?></td>
						<td>
							<?php
							if ( $ok && $record['num_ratings'] > 0 ) {
								printf(
									/* translators: 1: rating percentage, 2: number of ratings. */
									esc_html__( '%1$d%% (%2$s ratings)', 'fadewatch-abandoned-closed-add-on-checker' ),
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
									esc_html__( '%1$d of %2$d', 'fadewatch-abandoned-closed-add-on-checker' ),
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
			<h2><?php esc_html_e( 'Not checked', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h2>
			<p><?php esc_html_e( 'Must-use plugins and drop-ins are not installed from WordPress.org, so they are not checked.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
			<ul class="fadewatch-extras">
				<?php foreach ( $extras as $extra ) : ?>
					<li>
						<?php echo esc_html( $extra['name'] ); ?>
						<span class="fadewatch-detail-inline">(<?php echo esc_html( 'mustuse' === $extra['type'] ? __( 'must-use plugin', 'fadewatch-abandoned-closed-add-on-checker' ) : __( 'drop-in', 'fadewatch-abandoned-closed-add-on-checker' ) ); ?>, <code><?php echo esc_html( $extra['file'] ); ?></code>)</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Opt-in notice on the report page. "Allow checks" opens the consent
	 * dialog; nothing opens by itself. Nothing is sent to WordPress.org
	 * until the administrator clicks "Allow checks and scan now" in it.
	 */
	private static function render_consent() {
		?>
		<div class="notice notice-info inline fadewatch-consent">
			<h2><?php esc_html_e( 'Allow checks to scan your plugins', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h2>
			<p><?php esc_html_e( 'Checks are off, so Fadewatch has not looked up any of your plugins yet. With checks on, it asks the WordPress.org Plugins API about each installed plugin and shows:', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
			<ul class="fadewatch-consent__list">
				<li><?php esc_html_e( 'whether the plugin has been closed on WordPress.org, and why (for example, a security issue);', 'fadewatch-abandoned-closed-add-on-checker' ); ?></li>
				<li><?php esc_html_e( 'how long ago it was last updated, so abandoned plugins stand out;', 'fadewatch-abandoned-closed-add-on-checker' ); ?></li>
				<li><?php esc_html_e( 'the WordPress version it was tested up to, its rating, active installs and resolved support threads.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'Only the slug (folder name) of each plugin is sent. Nothing is sent until you allow it.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
			<p><?php self::render_consent_button( 'button button-primary' ); ?></p>
		</div>
		<?php
		self::render_consent_form();
		self::render_consent_dialog();
	}

	/**
	 * "Allow checks" button. It opens the consent dialog; without
	 * JavaScript it submits the consent form directly.
	 *
	 * @param string $classes CSS classes.
	 */
	public static function render_consent_button( $classes ) {
		printf(
			'<button type="submit" form="fadewatch-consent-form" class="%1$s" data-fadewatch-open-consent>%2$s</button>',
			esc_attr( $classes ),
			esc_html__( 'Allow checks', 'fadewatch-abandoned-closed-add-on-checker' )
		);
	}

	/**
	 * The form that records consent. It has no visible fields; the dialog's
	 * "Allow checks and scan now" button submits it through its `form`
	 * attribute.
	 */
	private static function render_consent_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="fadewatch-consent-form">
			<input type="hidden" name="action" value="fadewatch_grant_consent" />
			<?php wp_nonce_field( 'fadewatch_grant_consent' ); ?>
		</form>
		<?php
	}

	/**
	 * While checks are off, the Plugins screen offers "Allow checks" in the
	 * Maintenance column. Right after activation the consent dialog also
	 * opens once by itself (the flag is deleted when it is shown); after
	 * that it only opens when the administrator clicks "Allow checks".
	 */
	public static function setup_plugins_screen_consent() {
		if ( ! Fadewatch_Settings::current_user_can() || Fadewatch_Settings::checks_allowed() || ( is_multisite() && ! is_network_admin() ) ) {
			return;
		}

		self::$plugins_consent = true;

		if ( get_site_transient( self::PROMPT_FLAG ) ) {
			delete_site_transient( self::PROMPT_FLAG );
			self::$activation_prompt = true;
		}

		add_action(
			'admin_footer',
			static function () {
				self::render_consent_form();
				self::render_consent_dialog();
			}
		);
	}

	/**
	 * The consent dialog.
	 */
	private static function render_consent_dialog() {
		?>
		<dialog id="fadewatch-consent-dialog" class="fadewatch-dialog" aria-labelledby="fadewatch-consent-title" aria-describedby="fadewatch-consent-desc">
			<h2 id="fadewatch-consent-title"><?php esc_html_e( 'Allow Fadewatch to check your plugins?', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h2>
			<p id="fadewatch-consent-desc"><?php esc_html_e( 'To show whether your plugins are maintained, closed or abandoned, Fadewatch looks them up in the WordPress.org Plugins API (api.wordpress.org).', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
			<ul class="fadewatch-dialog__list">
				<li><?php esc_html_e( 'Only the slug (folder name) of each installed plugin is sent.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></li>
				<li><?php esc_html_e( 'No personal data and not your site address.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></li>
				<li><?php esc_html_e( 'Checks run once a day, after a plugin is installed, updated or activated, and when you click "Check now".', 'fadewatch-abandoned-closed-add-on-checker' ); ?></li>
				<li><?php esc_html_e( 'You can turn checks off at any time under Plugin Maintenance → Settings → "WordPress.org checks". Fadewatch then makes no external requests.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></li>
			</ul>
			<p>
				<a href="https://wordpress.org/about/privacy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'WordPress.org privacy policy', 'fadewatch-abandoned-closed-add-on-checker' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'fadewatch-abandoned-closed-add-on-checker' ); ?></span></a>
			</p>
			<div class="fadewatch-dialog__actions">
				<button type="submit" form="fadewatch-consent-form" class="button button-primary" autofocus><?php esc_html_e( 'Allow checks and scan now', 'fadewatch-abandoned-closed-add-on-checker' ); ?></button>
				<button type="button" class="button" data-fadewatch-close><?php esc_html_e( 'Not now', 'fadewatch-abandoned-closed-add-on-checker' ); ?></button>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Settings tab.
	 */
	private static function render_settings() {
		$s = Fadewatch_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fadewatch_save_settings" />
			<?php wp_nonce_field( 'fadewatch_save_settings' ); ?>

			<h2><?php esc_html_e( 'Thresholds', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fadewatch_ageing_months"><?php esc_html_e( '"Ageing" after', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label></th>
					<td>
						<input type="number" min="1" max="120" step="1" class="small-text" id="fadewatch_ageing_months" name="fadewatch_ageing_months" value="<?php echo esc_attr( $s['ageing_months'] ); ?>" aria-describedby="fadewatch_ageing_months_desc" />
						<?php esc_html_e( 'months without an update', 'fadewatch-abandoned-closed-add-on-checker' ); ?>
						<p class="description" id="fadewatch_ageing_months_desc"><?php esc_html_e( 'Default: 12.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fadewatch_abandoned_months"><?php esc_html_e( '"Possibly abandoned" after', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label></th>
					<td>
						<input type="number" min="2" max="240" step="1" class="small-text" id="fadewatch_abandoned_months" name="fadewatch_abandoned_months" value="<?php echo esc_attr( $s['abandoned_months'] ); ?>" aria-describedby="fadewatch_abandoned_months_desc" />
						<?php esc_html_e( 'months without an update', 'fadewatch-abandoned-closed-add-on-checker' ); ?>
						<p class="description" id="fadewatch_abandoned_months_desc"><?php esc_html_e( 'Default: 24. Must be higher than the "Ageing" value.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'What to check', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress.org checks', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<td>
						<label><input type="checkbox" name="fadewatch_allow_checks" value="1" <?php checked( $s['allow_checks'] ); ?> aria-describedby="fadewatch_allow_checks_desc" /> <?php esc_html_e( 'Allow Fadewatch to look up installed plugins on WordPress.org', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label>
						<p class="description" id="fadewatch_allow_checks_desc"><?php esc_html_e( 'Only plugin slugs are sent to api.wordpress.org. When this is off, Fadewatch makes no external requests.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Inactive plugins', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<td>
						<label><input type="checkbox" name="fadewatch_include_inactive" value="1" <?php checked( $s['include_inactive'] ); ?> /> <?php esc_html_e( 'Check inactive plugins too', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended: the files of inactive plugins are still on the server and can still be attacked.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Local environments', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<td>
						<label><input type="checkbox" name="fadewatch_skip_local" value="1" <?php checked( $s['skip_local'] ); ?> /> <?php esc_html_e( 'Pause scheduled checks when the environment type is "local"', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: environment type such as "production". */
								esc_html__( 'Current environment type: %s.', 'fadewatch-abandoned-closed-add-on-checker' ),
								'<code>' . esc_html( wp_get_environment_type() ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'E-mail notifications', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send e-mails', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Send e-mails', 'fadewatch-abandoned-closed-add-on-checker' ); ?></legend>
							<label><input type="radio" name="fadewatch_notify" value="changes" <?php checked( $s['notify'], 'changes' ); ?> /> <?php esc_html_e( 'Only when something changes (recommended)', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label><br />
							<label><input type="radio" name="fadewatch_notify" value="weekly" <?php checked( $s['notify'], 'weekly' ); ?> /> <?php esc_html_e( 'When something changes, plus a weekly summary of open issues', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label><br />
							<label><input type="radio" name="fadewatch_notify" value="off" <?php checked( $s['notify'], 'off' ); ?> /> <?php esc_html_e( 'Never', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label>
							<p class="description"><?php esc_html_e( 'A plugin closed for a security issue triggers an immediate e-mail. Other changes are collected into one e-mail per check.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fadewatch_recipients"><?php esc_html_e( 'Recipients', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="fadewatch_recipients" name="fadewatch_recipients" value="<?php echo esc_attr( $s['recipients'] ); ?>" placeholder="<?php echo esc_attr( get_site_option( 'admin_email' ) ); ?>" aria-describedby="fadewatch_recipients_desc" />
						<p class="description" id="fadewatch_recipients_desc"><?php esc_html_e( 'Comma-separated e-mail addresses. Leave empty to use the administration e-mail address.', 'fadewatch-abandoned-closed-add-on-checker' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'fadewatch-abandoned-closed-add-on-checker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Data', 'fadewatch-abandoned-closed-add-on-checker' ); ?></th>
					<td>
						<label><input type="checkbox" name="fadewatch_delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?> /> <?php esc_html_e( 'Delete all data of this plugin when it is deleted', 'fadewatch-abandoned-closed-add-on-checker' ); ?></label>
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
		if ( ! Fadewatch_Settings::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fadewatch-abandoned-closed-add-on-checker' ), 403 );
		}
		check_admin_referer( 'fadewatch_save_settings' );

		$before = Fadewatch_Settings::get();

		$saved = Fadewatch_Settings::update(
			array(
				'ageing_months'       => isset( $_POST['fadewatch_ageing_months'] ) ? absint( wp_unslash( $_POST['fadewatch_ageing_months'] ) ) : 0,
				'abandoned_months'    => isset( $_POST['fadewatch_abandoned_months'] ) ? absint( wp_unslash( $_POST['fadewatch_abandoned_months'] ) ) : 0,
				'include_inactive'    => self::posted_checkbox( 'fadewatch_include_inactive' ),
				'skip_local'          => self::posted_checkbox( 'fadewatch_skip_local' ),
				'delete_on_uninstall' => self::posted_checkbox( 'fadewatch_delete_on_uninstall' ),
				'allow_checks'        => self::posted_checkbox( 'fadewatch_allow_checks' ),
				'notify'              => isset( $_POST['fadewatch_notify'] ) ? sanitize_key( wp_unslash( $_POST['fadewatch_notify'] ) ) : '',
				'recipients'          => isset( $_POST['fadewatch_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['fadewatch_recipients'] ) ) : '',
			)
		);

		if ( $saved['allow_checks'] && ! $before['allow_checks'] ) {
			// Consent given from the settings screen.
			Fadewatch_Settings::grant_consent();
			Fadewatch_Scanner::start_full_scan( 'consent' );
		} elseif ( ! $saved['allow_checks'] && $before['allow_checks'] ) {
			// Consent withdrawn: drop anything still queued.
			Fadewatch_Store::save_state( array() );
		} elseif ( $saved['include_inactive'] && ! $before['include_inactive'] ) {
			// Newly included inactive plugins need data.
			Fadewatch_Scanner::start_full_scan( 'settings' );
		}

		wp_safe_redirect( add_query_arg( 'fadewatch_saved', '1', self::page_url( 'settings' ) ) );
		exit;
	}

	/**
	 * Consent form fallback for browsers without JavaScript.
	 */
	public static function handle_grant_consent() {
		if ( ! Fadewatch_Settings::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fadewatch-abandoned-closed-add-on-checker' ), 403 );
		}
		check_admin_referer( 'fadewatch_grant_consent' );

		Fadewatch_Settings::grant_consent();
		Fadewatch_Scanner::start_full_scan( 'consent' );

		wp_safe_redirect( self::page_url() );
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
		if ( ! Fadewatch_Settings::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fadewatch-abandoned-closed-add-on-checker' ), 403 );
		}
		check_admin_referer( 'fadewatch_export_csv' );

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
				__( 'Plugin', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Plugin file', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Slug', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Active', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Status', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Severity', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Details', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Latest update release', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Closed date', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Closure reason', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Tested up to', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Active installs', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Rating', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Installed version', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Latest version', 'fadewatch-abandoned-closed-add-on-checker' ),
				__( 'Last checked', 'fadewatch-abandoned-closed-add-on-checker' ),
			)
		);

		foreach ( Fadewatch_Report::rows() as $row ) {
			$item   = $row['item'];
			$eval   = $row['eval'];
			$record = is_array( $row['record'] ) ? $row['record'] : array();
			$ok     = isset( $record['result'] ) && 'ok' === $record['result'] && Fadewatch_Status::UNVERIFIED !== $eval['status'];

			self::csv_row(
				$out,
				array(
					$item['name'],
					$item['file'],
					$item['slug'],
					$item['active'] ? __( 'Yes', 'fadewatch-abandoned-closed-add-on-checker' ) : __( 'No', 'fadewatch-abandoned-closed-add-on-checker' ),
					Fadewatch_Status::label( $eval['status'] ),
					$eval['severity'],
					implode( ' ', Fadewatch_Render::details( $eval, $item, $row['record'] ) ),
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

		$content = '<p>' . esc_html__( 'Fadewatch sends the slugs (short names) of installed plugins to api.wordpress.org to look up their maintenance status. No personal data about visitors or users is sent or stored, and no tracking is used.', 'fadewatch-abandoned-closed-add-on-checker' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Fadewatch', 'fadewatch-abandoned-closed-add-on-checker' ), wp_kses_post( $content ) );
	}
}
