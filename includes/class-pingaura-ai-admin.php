<?php
/**
 * PingAura AI admin menu.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * The PingAura AI menu: the PingAura panel, the Fixes page and the settings.
 */
final class PingAura_AI_Admin {

	const MENU = 'pingaura-ai';

	const FIXES_PAGE = 'pingaura-ai-fixes';

	const NOTICE_TRANSIENT = 'pingaura_ai_notice';

	const STYLE_HANDLE = 'pingaura-ai-admin';

	/**
	 * The PingAura beacon (small master) as the menu icon; WordPress recolours it to the admin colour scheme.
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9Ii00MzEuMjIgMCA4NjIuNDQgNzcwIj48ZyBmaWxsPSIjYTdhYWFkIj48cGF0aCBkPSJNMCAwTDEwNi4wNiAxODkuMzhBNDUyIDQ1MiAwIDAgMSAtMTA2LjA2IDE4OS4zOFoiPjwvcGF0aD48cGF0aCBkPSJNLTEzMi4zNCAyMzYuMzFBNTA0IDUwNCAwIDAgMCAxMzIuMzQgMjM2LjMxTDE2MS40NiAyODguMzFBNTYyIDU2MiAwIDAgMSAtMTYxLjQ2IDI4OC4zMVoiPjwvcGF0aD48cGF0aCBkPSJNLTE4Ny40NCAzMzQuNjlBNjE0IDYxNCAwIDAgMCAxODcuNDQgMzM0LjY5TDIyMS4yNyAzOTUuMTFBNjgyIDY4MiAwIDAgMSAtMjIxLjI3IDM5NS4xMVoiPjwvcGF0aD48cGF0aCBkPSJNLTI0Ny4wNyA0NDEuMTdBNzM0IDczNCAwIDAgMCAyNDcuMDcgNDQxLjE3TDQzMS4yMiA3NzBMMCA1MzUuODdMLTQzMS4yMiA3NzBaIj48L3BhdGg+PC9nPjwvc3ZnPg==';

	/**
	 * Hook suffixes of the plugin's pages.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_pages' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_pingaura_ai_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_pingaura_ai_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_pingaura_ai_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_pingaura_ai_undo', array( __CLASS__, 'handle_undo' ) );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_app_host' ) );
	}

	/**
	 * Adds the top-level menu with the panel and Fixes pages. The settings
	 * page is added to the same menu by PingAura_Telemetry_Admin.
	 */
	public static function add_pages() {
		$panel = add_menu_page(
			__( 'PingAura AI', 'pingaura-telemetry' ),
			__( 'PingAura AI', 'pingaura-telemetry' ),
			'manage_options',
			self::MENU,
			array( __CLASS__, 'render_panel' ),
			self::MENU_ICON,
			81
		);
		add_submenu_page(
			self::MENU,
			__( 'PingAura AI', 'pingaura-telemetry' ),
			__( 'Dashboard', 'pingaura-telemetry' ),
			'manage_options',
			self::MENU,
			array( __CLASS__, 'render_panel' )
		);
		$fixes = add_submenu_page(
			self::MENU,
			__( 'Fixes', 'pingaura-telemetry' ),
			__( 'Fixes', 'pingaura-telemetry' ),
			'manage_options',
			self::FIXES_PAGE,
			array( __CLASS__, 'render_fixes' )
		);

		self::$hooks = array_filter( array( $panel, $fixes ) );

		if ( $panel ) {
			add_action( 'load-' . $panel, array( __CLASS__, 'maybe_finish_connect' ) );
		}
	}

	/**
	 * The panel page URL. PingAura returns the browser here after approval.
	 *
	 * @return string
	 */
	public static function panel_url() {
		return admin_url( 'admin.php?page=' . self::MENU );
	}

	/**
	 * The Fixes page URL.
	 *
	 * @return string
	 */
	public static function fixes_url() {
		return admin_url( 'admin.php?page=' . self::FIXES_PAGE );
	}

	/**
	 * Lets wp_safe_redirect() send the browser to the PingAura app.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public static function allow_app_host( $hosts ) {
		$hosts[] = (string) wp_parse_url( PingAura_AI_Api::app_url(), PHP_URL_HOST );
		return $hosts;
	}

	/**
	 * Adds the styles for the plugin's pages.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, self::$hooks, true ) ) {
			return;
		}

		wp_register_style( self::STYLE_HANDLE, false, array(), PINGAURA_TELEMETRY_VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style(
			self::STYLE_HANDLE,
			'.pingaura-ai-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:8px 0}'
			. '.pingaura-ai-bar form{margin:0}'
			. '.pingaura-ai-actions{display:flex;align-items:center;gap:8px}'
			. '.pingaura-ai-frame{display:block;width:100%;height:calc(100vh - 140px);min-height:560px;border:1px solid #dcdcde;background:#fff}'
			. '.pingaura-ai-fixes pre{max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-word;background:#f6f7f7;padding:8px}'
			. '.pingaura-ai-fixes td form{display:inline-block;margin:0 4px 4px 0}'
		);
	}

	/**
	 * Completes a connect when PingAura sends the browser back with a code.
	 * Runs before the page is output, so it can redirect.
	 */
	public static function maybe_finish_connect() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The state from the pending connect, compared with hash_equals(), protects this request.
		if ( ! isset( $_GET['pingaura_code'], $_GET['state'] ) ) {
			return;
		}
		self::require_capability();

		$code  = sanitize_text_field( wp_unslash( $_GET['pingaura_code'] ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = PingAura_AI_Connect::finish( $code, $state );

		self::redirect(
			self::panel_url(),
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Connected to PingAura.', 'pingaura-telemetry' ),
			is_wp_error( $result ) ? 'error' : 'success'
		);
	}

	/**
	 * Starts a connect and sends the browser to PingAura.
	 */
	public static function handle_connect() {
		self::require_capability();
		check_admin_referer( 'pingaura_ai_connect' );

		$url = PingAura_AI_Connect::start();
		if ( is_wp_error( $url ) ) {
			self::redirect( self::panel_url(), $url->get_error_message(), 'error' );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Disconnects the site.
	 */
	public static function handle_disconnect() {
		self::require_capability();
		check_admin_referer( 'pingaura_ai_disconnect' );

		$confirmed = PingAura_AI_Connect::disconnect( true );

		self::redirect(
			self::panel_url(),
			$confirmed
				? __( 'Disconnected from PingAura. Fixes you applied stay in place; undo them under PingAura AI, Fixes.', 'pingaura-telemetry' )
				: __( 'Disconnected on this site. PingAura could not be reached, so the connection may still show in PingAura until you remove it there. Fixes you applied stay in place.', 'pingaura-telemetry' ),
			$confirmed ? 'success' : 'warning'
		);
	}

	/**
	 * Applies a fix. Its content is fetched again from PingAura, with the
	 * signature checked, instead of being taken from the form. The form carries
	 * a SHA-256 over the kind, target path and content the admin previewed, and
	 * the fix is applied only if the fresh fix still matches all three.
	 */
	public static function handle_apply() {
		self::require_capability();
		$fix_id = self::posted_fix_id();
		$sha256 = self::posted_fix_sha256();
		check_admin_referer( 'pingaura_ai_apply_' . $fix_id . '_' . $sha256 );

		$fixes = self::fetch_fixes();
		if ( is_wp_error( $fixes ) ) {
			self::redirect( self::fixes_url(), $fixes->get_error_message(), 'error' );
		}

		$fix = null;
		foreach ( $fixes as $candidate ) {
			if ( $candidate['id'] === $fix_id ) {
				$fix = $candidate;
				break;
			}
		}
		if ( null === $fix ) {
			self::redirect( self::fixes_url(), __( 'PingAura no longer proposes this fix.', 'pingaura-telemetry' ), 'error' );
		}
		if ( ! hash_equals( self::fix_sha256( $fix ), $sha256 ) ) {
			self::redirect( self::fixes_url(), __( 'This fix changed since you previewed it. Review it again.', 'pingaura-telemetry' ), 'warning' );
		}

		$result = PingAura_AI_Fixes::apply( $fix );
		if ( is_wp_error( $result ) ) {
			self::redirect( self::fixes_url(), $result->get_error_message(), 'error' );
		}

		if ( null !== $result['replaced'] && $result['replaced']['id'] !== $fix_id ) {
			PingAura_AI_Api::report_reverted( $result['replaced']['id'] );
		}
		$reported = PingAura_AI_Api::report_applied( $fix_id, $result['applied']['sha256'] );

		self::redirect(
			self::fixes_url(),
			$reported
				? __( 'Fix applied.', 'pingaura-telemetry' )
				: __( 'Fix applied. PingAura could not be told yet, so it may show the fix as not applied for a while.', 'pingaura-telemetry' ),
			$reported ? 'success' : 'warning'
		);
	}

	/**
	 * Undoes a fix.
	 */
	public static function handle_undo() {
		self::require_capability();
		$fix_id = self::posted_fix_id();
		check_admin_referer( 'pingaura_ai_undo_' . $fix_id );

		$result = PingAura_AI_Fixes::undo( $fix_id );
		if ( is_wp_error( $result ) ) {
			self::redirect( self::fixes_url(), $result->get_error_message(), 'error' );
		}

		$reported = true;
		if ( PingAura_AI_Api::is_connected() ) {
			$reported = PingAura_AI_Api::report_reverted( $fix_id );
			if ( null !== $result['restored'] ) {
				$reported = PingAura_AI_Api::report_applied( $result['restored']['id'], $result['restored']['sha256'] ) && $reported;
			}
		}

		$message = null !== $result['restored']
			? __( 'Fix undone. The fix it replaced is live again.', 'pingaura-telemetry' )
			: __( 'Fix undone.', 'pingaura-telemetry' );
		if ( ! $reported ) {
			$message .= ' ' . __( 'PingAura could not be told yet.', 'pingaura-telemetry' );
		}

		self::redirect( self::fixes_url(), $message, $reported ? 'success' : 'warning' );
	}

	/**
	 * Renders the panel page: a connect prompt, or the PingAura panel.
	 */
	public static function render_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PingAura AI', 'pingaura-telemetry' ); ?></h1>
			<?php self::print_notice(); ?>

			<?php if ( ! PingAura_AI_Api::is_connected() ) : ?>
				<p><?php esc_html_e( 'Connect this site to PingAura to see how AI assistants such as ChatGPT, Gemini and Perplexity mention your brand, check your pages for problems that keep them out of AI answers, and apply suggested fixes from WordPress.', 'pingaura-telemetry' ); ?></p>
				<p><?php esc_html_e( 'You will sign in to PingAura, or create a free account, and approve the connection. PingAura then checks this site to confirm you manage it. Nothing on your site changes until you apply a fix yourself.', 'pingaura-telemetry' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'pingaura_ai_connect' ); ?>
					<input type="hidden" name="action" value="pingaura_ai_connect">
					<?php submit_button( __( 'Connect to PingAura', 'pingaura-telemetry' ), 'primary', 'submit', false ); ?>
				</form>
				<p class="description">
					<?php
					printf(
						/* translators: 1: link to the terms of service, 2: link to the privacy policy. */
						esc_html__( 'By connecting you agree to PingAura\'s %1$s and %2$s.', 'pingaura-telemetry' ),
						'<a href="https://www.pingaura.ai/terms-of-service" target="_blank" rel="noopener noreferrer">' . esc_html__( 'terms of service', 'pingaura-telemetry' ) . '</a>',
						'<a href="https://www.pingaura.ai/privacy-policy" target="_blank" rel="noopener noreferrer">' . esc_html__( 'privacy policy', 'pingaura-telemetry' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<?php $url = PingAura_AI_Api::panel_session(); ?>
				<div class="pingaura-ai-bar">
					<span>
						<?php
						$site = PingAura_AI_Api::site();
						if ( '' !== $site['domain'] ) {
							printf(
								/* translators: %s: domain name. */
								esc_html__( 'Connected as %s.', 'pingaura-telemetry' ),
								'<strong>' . esc_html( $site['domain'] ) . '</strong>'
							);
						} else {
							esc_html_e( 'Connected.', 'pingaura-telemetry' );
						}
						?>
						<a href="<?php echo esc_url( self::fixes_url() ); ?>"><?php esc_html_e( 'Apply fixes', 'pingaura-telemetry' ); ?></a>
						<?php self::pages_sent_line(); ?>
					</span>
					<span class="pingaura-ai-actions">
						<a class="button" href="<?php echo esc_url( self::panel_url() ); ?>"><?php esc_html_e( 'Reload', 'pingaura-telemetry' ); ?></a>
						<?php self::disconnect_form(); ?>
					</span>
				</div>
				<?php if ( is_wp_error( $url ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $url->get_error_message() ); ?></p></div>
					<p><a class="button" href="<?php echo esc_url( self::panel_url() ); ?>"><?php esc_html_e( 'Try again', 'pingaura-telemetry' ); ?></a></p>
				<?php else : ?>
					<iframe class="pingaura-ai-frame" src="<?php echo esc_url( self::with_locale( $url ) ); ?>" title="<?php esc_attr_e( 'PingAura', 'pingaura-telemetry' ); ?>" referrerpolicy="strict-origin"></iframe>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the Fixes page.
	 */
	public static function render_fixes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connected    = PingAura_AI_Api::is_connected();
		$empty_reason = '';
		$fixes        = $connected ? self::fetch_fixes( $empty_reason ) : array();
		$listed       = array();
		?>
		<div class="wrap pingaura-ai-fixes">
			<h1><?php esc_html_e( 'Fixes', 'pingaura-telemetry' ); ?></h1>
			<?php self::print_notice(); ?>

			<p><?php esc_html_e( 'PingAura suggests these changes to help AI assistants understand and cite your site. Nothing changes until you click Apply, and Undo puts things back as they were. Fixes are added through WordPress; your theme and files are not edited.', 'pingaura-telemetry' ); ?></p>

			<?php if ( ! $connected ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: link to the PingAura AI page. */
						esc_html__( 'This site is not connected to PingAura. %s to see suggested fixes.', 'pingaura-telemetry' ),
						'<a href="' . esc_url( self::panel_url() ) . '">' . esc_html__( 'Connect it', 'pingaura-telemetry' ) . '</a>'
					);
					?>
				</p>
			<?php elseif ( is_wp_error( $fixes ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $fixes->get_error_message() ); ?></p></div>
			<?php elseif ( empty( $fixes ) && ! self::empty_reason_notice( $empty_reason ) ) : ?>
				<p><?php esc_html_e( 'No fixes to suggest right now.', 'pingaura-telemetry' ); ?></p>
			<?php endif; ?>

			<?php if ( ! is_wp_error( $fixes ) && ! empty( $fixes ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Fix', 'pingaura-telemetry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'pingaura-telemetry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'pingaura-telemetry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'pingaura-telemetry' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $fixes as $fix ) :
							$listed[] = $fix['id'];
							$live     = PingAura_AI_Fixes::find_applied( $fix['id'] );
							$blocker  = null === $live ? PingAura_AI_Fixes::blocker( $fix['kind'] ) : '';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( '' !== $fix['title'] ? $fix['title'] : self::kind_label( $fix['kind'] ) ); ?></strong>
									<?php if ( '' !== $fix['summary'] ) : ?>
										<p><?php echo esc_html( $fix['summary'] ); ?></p>
									<?php endif; ?>
									<details>
										<summary><?php esc_html_e( 'Preview', 'pingaura-telemetry' ); ?></summary>
										<pre><?php echo esc_html( $fix['content'] ); ?></pre>
									</details>
									<?php if ( '' !== $blocker ) : ?>
										<p class="description"><?php echo esc_html( $blocker ); ?></p>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( self::where( $fix ) ); ?></td>
								<td><?php echo esc_html( null !== $live ? __( 'Applied', 'pingaura-telemetry' ) : __( 'Not applied', 'pingaura-telemetry' ) ); ?></td>
								<td>
									<?php
									if ( null !== $live ) {
										self::action_form( 'undo', $fix['id'], __( 'Undo', 'pingaura-telemetry' ) );
									} elseif ( '' === $blocker ) {
										self::action_form( 'apply', $fix['id'], __( 'Apply', 'pingaura-telemetry' ), self::fix_sha256( $fix ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			$others = array();
			foreach ( PingAura_AI_Fixes::applied() as $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) && ! in_array( $entry['id'], $listed, true ) ) {
					$others[] = $entry;
				}
			}
			?>
			<?php if ( ! empty( $others ) ) : ?>
				<h2><?php esc_html_e( 'Applied on this site', 'pingaura-telemetry' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $others as $entry ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( '' !== $entry['title'] ? $entry['title'] : self::kind_label( $entry['kind'] ) ); ?></strong>
									<details>
										<summary><?php esc_html_e( 'Preview', 'pingaura-telemetry' ); ?></summary>
										<pre><?php echo esc_html( $entry['content'] ); ?></pre>
									</details>
								</td>
								<td><?php echo esc_html( self::where( $entry ) ); ?></td>
								<td><?php self::action_form( 'undo', $entry['id'], __( 'Undo', 'pingaura-telemetry' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Adds the admin's language to a panel URL, before the token fragment, so the panel matches wp-admin.
	 *
	 * @param string $url Panel URL from PingAura.
	 * @return string
	 */
	private static function with_locale( $url ) {
		$language = strtolower( substr( get_user_locale(), 0, 2 ) );
		if ( 1 !== preg_match( '/^[a-z]{2}$/', $language ) ) {
			return $url;
		}

		$parts = explode( '#', $url, 2 );

		return add_query_arg( 'locale', $language, $parts[0] ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
	}

	/**
	 * Fetches and validates the proposed fixes.
	 *
	 * @param string $empty_reason Set to why the list is empty, or an empty string.
	 * @return array[]|WP_Error
	 */
	private static function fetch_fixes( &$empty_reason = '' ) {
		$empty_reason = '';
		$raw          = PingAura_AI_Api::fixes();
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$empty_reason = $raw['empty_reason'];
		return array_values( array_filter( array_map( array( 'PingAura_AI_Fixes', 'validate' ), $raw['fixes'] ) ) );
	}

	/**
	 * Prints why PingAura has no fixes to suggest, when it says so.
	 *
	 * @param string $reason no_audit, audit_running, site_unreachable or none_needed.
	 * @return bool Whether a notice was printed.
	 */
	private static function empty_reason_notice( $reason ) {
		$messages = array(
			'no_audit'         => __( 'PingAura has not audited this site yet. Run a site audit from the PingAura dashboard, then check back.', 'pingaura-telemetry' ),
			'audit_running'    => __( 'A site audit is running. Fixes appear here when it finishes.', 'pingaura-telemetry' ),
			'site_unreachable' => __( 'PingAura could not read this site\'s pages. If the site is in maintenance mode, private or password protected, make it public, then run the audit again.', 'pingaura-telemetry' ),
			'none_needed'      => __( 'No fixes needed right now: the latest audit found nothing PingAura can fix here.', 'pingaura-telemetry' ),
		);
		if ( ! isset( $messages[ $reason ] ) ) {
			return false;
		}

		printf(
			'<div class="notice %1$s inline"><p>%2$s</p></div>',
			esc_attr( 'site_unreachable' === $reason ? 'notice-warning' : 'notice-info' ),
			esc_html( $messages[ $reason ] )
		);
		return true;
	}

	/**
	 * Prints when the page list was last sent to PingAura, if it has been.
	 */
	private static function pages_sent_line() {
		$last = PingAura_AI_Pages::last_sent();
		if ( null === $last ) {
			return;
		}

		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['at'] );
		?>
		<br>
		<span class="description">
			<?php
			printf(
				/* translators: 1: date and time, 2: number of pages. */
				esc_html( _n( 'Pages last sent to PingAura: %1$s (%2$s page)', 'Pages last sent to PingAura: %1$s (%2$s pages)', $last['pages'], 'pingaura-telemetry' ) ),
				esc_html( (string) $when ),
				esc_html( number_format_i18n( $last['pages'] ) )
			);
			?>
		</span>
		<?php
	}

	/**
	 * Prints an Apply or Undo button.
	 *
	 * @param string $action apply or undo.
	 * @param string $fix_id Fix ID.
	 * @param string $label  Button label.
	 * @param string $sha256 For apply: fix_sha256() of the previewed fix.
	 */
	private static function action_form( $action, $fix_id, $label, $sha256 = '' ) {
		$nonce_action = 'pingaura_ai_' . $action . '_' . $fix_id . ( '' !== $sha256 ? '_' . $sha256 : '' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $nonce_action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( 'pingaura_ai_' . $action ); ?>">
			<input type="hidden" name="fix_id" value="<?php echo esc_attr( $fix_id ); ?>">
			<?php if ( '' !== $sha256 ) : ?>
				<input type="hidden" name="fix_sha256" value="<?php echo esc_attr( $sha256 ); ?>">
			<?php endif; ?>
			<button class="button<?php echo 'apply' === $action ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Prints the Disconnect button.
	 */
	private static function disconnect_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pingaura_ai_disconnect' ); ?>
			<input type="hidden" name="action" value="pingaura_ai_disconnect">
			<button class="button"><?php esc_html_e( 'Disconnect', 'pingaura-telemetry' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Where a fix takes effect, for display.
	 *
	 * @param array $fix Fix or applied entry.
	 * @return string
	 */
	private static function where( array $fix ) {
		if ( 'llms_txt' === $fix['kind'] ) {
			return '/llms.txt';
		}
		if ( 'robots_txt' === $fix['kind'] ) {
			return '/robots.txt';
		}
		return sprintf(
			/* translators: %s: page path. */
			__( 'Structured data on %s', 'pingaura-telemetry' ),
			$fix['target_path']
		);
	}

	/**
	 * A readable name for a fix kind.
	 *
	 * @param string $kind Fix kind.
	 * @return string
	 */
	private static function kind_label( $kind ) {
		$labels = array(
			'jsonld'     => __( 'Structured data (JSON-LD)', 'pingaura-telemetry' ),
			'llms_txt'   => __( 'llms.txt', 'pingaura-telemetry' ),
			'robots_txt' => __( 'robots.txt rules', 'pingaura-telemetry' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	/**
	 * The fix ID from the submitted form.
	 *
	 * @return string
	 */
	private static function posted_fix_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce includes the fix ID and is checked right after.
		$fix_id = isset( $_POST['fix_id'] ) ? sanitize_text_field( wp_unslash( $_POST['fix_id'] ) ) : '';
		if ( ! PingAura_AI_Api::is_valid_id( $fix_id ) ) {
			wp_die( esc_html__( 'Unknown fix.', 'pingaura-telemetry' ), 400 );
		}
		return $fix_id;
	}

	/**
	 * The checksum Apply binds to: hex SHA-256 of "{kind}\n{target_path}\n{content}",
	 * so a fix cannot be moved to another kind or path after it was previewed.
	 *
	 * @param array $fix Validated fix.
	 * @return string
	 */
	private static function fix_sha256( array $fix ) {
		return hash( 'sha256', $fix['kind'] . "\n" . $fix['target_path'] . "\n" . $fix['content'] );
	}

	/**
	 * The checksum of the previewed fix from the submitted Apply form.
	 *
	 * @return string
	 */
	private static function posted_fix_sha256() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce includes the checksum and is checked right after.
		$sha256 = isset( $_POST['fix_sha256'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['fix_sha256'] ) ) ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha256 ) ) {
			self::redirect( self::fixes_url(), __( 'This fix changed since you previewed it. Review it again.', 'pingaura-telemetry' ), 'warning' );
		}
		return $sha256;
	}

	/**
	 * Prints and clears the one-time notice.
	 */
	private static function print_notice() {
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( self::notice_key() );

		$type = isset( $notice['type'] ) && in_array( $notice['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Stops unless the current user may manage options.
	 */
	private static function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'pingaura-telemetry' ), 403 );
		}
	}

	/**
	 * Redirects to a plugin page with a notice.
	 *
	 * @param string $url     Page URL.
	 * @param string $message Notice text.
	 * @param string $type    success, warning, error or info.
	 */
	private static function redirect( $url, $message, $type ) {
		set_transient(
			self::notice_key(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Per-user key for the one-time notice.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return self::NOTICE_TRANSIENT . '_' . get_current_user_id();
	}
}
