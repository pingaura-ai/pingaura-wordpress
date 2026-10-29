<?php
/**
 * Settings screen.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings → PingAura Telemetry.
 */
final class PingAura_Telemetry_Admin {

	const PAGE = 'pingaura-telemetry';

	const NOTICE_TRANSIENT = 'pingaura_telemetry_notice';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_action( 'admin_post_pingaura_telemetry_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_pingaura_telemetry_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_pingaura_telemetry_test_cookie', array( __CLASS__, 'handle_test_cookie' ) );
		add_action( 'admin_post_pingaura_telemetry_indexnow_submit_all', array( __CLASS__, 'handle_indexnow_submit_all' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PINGAURA_TELEMETRY_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the settings page.
	 */
	public static function add_page() {
		add_options_page(
			__( 'PingAura Telemetry', 'pingaura-telemetry' ),
			__( 'PingAura Telemetry', 'pingaura-telemetry' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Suggests text for the site's privacy policy.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'PingAura Telemetry', 'pingaura-telemetry' ),
			wp_kses_post(
				wpautop(
					__( 'When you visit this site, we send the address of the page you viewed (without query parameters other than utm_ campaign tags), its title, the address of the page that referred you, your IP address and your browser\'s user agent to PingAura, an analytics service, to measure visits from AI assistants. Your IP address is used to determine your country. No cookies are set. See PingAura\'s privacy policy: https://www.pingaura.ai/privacy-policy', 'pingaura-telemetry' )
				)
			)
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'pingaura-telemetry' ) )
		);
		return $links;
	}

	/**
	 * Saves the settings form.
	 */
	public static function handle_save() {
		self::require_capability();
		check_admin_referer( 'pingaura_telemetry_save' );

		$input = isset( $_POST[ PingAura_Telemetry_Settings::OPTION ] ) && is_array( $_POST[ PingAura_Telemetry_Settings::OPTION ] )
			? map_deep( wp_unslash( $_POST[ PingAura_Telemetry_Settings::OPTION ] ), 'sanitize_text_field' )
			: array();

		PingAura_Telemetry_Settings::save( $input );
		PingAura_Telemetry_IndexNow::set_enabled( ! empty( $input['indexnow'] ) );

		// Page caches keep serving the old script until they are cleared.
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own purge hook.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		self::redirect( __( 'Settings saved.', 'pingaura-telemetry' ) );
	}

	/**
	 * Sends a sample event so the connection can be checked from wp-admin.
	 */
	public static function handle_test() {
		self::require_capability();
		check_admin_referer( 'pingaura_telemetry_test' );

		$kind = isset( $_POST['kind'] ) && 'crawler' === $_POST['kind'] ? 'crawler' : 'referral';

		$event = 'crawler' === $kind
			? PingAura_Telemetry_Client::page_view(
				array(
					'url'        => home_url( '/?pingaura_test=crawler' ),
					'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
					'status'     => 200,
				)
			)
			: PingAura_Telemetry_Client::page_view(
				array(
					'url'        => home_url( '/?pingaura_test=referral' ),
					'referrer'   => 'https://chatgpt.com/',
					'title'      => 'PingAura test visit',
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				)
			);

		$status = PingAura_Telemetry_Client::send( $event, PingAura_Telemetry_Tracker::client_ip(), true );

		self::redirect(
			sprintf(
				/* translators: %s: response from PingAura. */
				__( 'Test sent. PingAura replied: %s', 'pingaura-telemetry' ),
				$status
			)
		);
	}

	/**
	 * Submits every published page to IndexNow.
	 */
	public static function handle_indexnow_submit_all() {
		self::require_capability();
		check_admin_referer( 'pingaura_telemetry_indexnow_submit_all' );

		$status = PingAura_Telemetry_IndexNow::submit_all();
		$last   = PingAura_Telemetry_IndexNow::last_status();

		self::redirect(
			sprintf(
				/* translators: 1: number of URLs, 2: response from IndexNow. */
				__( 'Submitted %1$d URLs. IndexNow replied: %2$s', 'pingaura-telemetry' ),
				$last ? (int) $last['urls'] : 0,
				$status
			)
		);
	}

	/**
	 * Sets or clears the test cookie in the admin's browser.
	 */
	public static function handle_test_cookie() {
		self::require_capability();
		check_admin_referer( 'pingaura_telemetry_test_cookie' );

		$enable = ! empty( $_POST['enable'] );

		setcookie(
			PingAura_Telemetry_Tracker::TEST_COOKIE,
			$enable ? '1' : '',
			array(
				'expires'  => $enable ? time() + DAY_IN_SECONDS : time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		self::redirect(
			$enable
				? __( 'Test visits are on for this browser for 24 hours.', 'pingaura-telemetry' )
				: __( 'Test visits are off for this browser.', 'pingaura-telemetry' )
		);
	}

	/**
	 * Renders the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = PingAura_Telemetry_Settings::get();
		$last     = PingAura_Telemetry_Client::last_status();
		$notice   = get_transient( self::notice_key() );
		$name     = PingAura_Telemetry_Settings::OPTION;
		$key      = PingAura_Telemetry_Settings::ingest_key();
		$constant = PingAura_Telemetry_Settings::key_is_constant();
		$has_key  = PingAura_Telemetry_Settings::is_valid_key( $key );
		$test_on  = isset( $_COOKIE[ PingAura_Telemetry_Tracker::TEST_COOKIE ] ) && '1' === $_COOKIE[ PingAura_Telemetry_Tracker::TEST_COOKIE ];
		$indexnow = PingAura_Telemetry_IndexNow::is_enabled();
		$in_last  = PingAura_Telemetry_IndexNow::last_status();

		delete_transient( self::notice_key() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PingAura Telemetry', 'pingaura-telemetry' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: link to the setup guide. */
					esc_html__( 'Send visits from AI assistants and AI crawler requests to PingAura. %s', 'pingaura-telemetry' ),
					'<a href="https://www.pingaura.ai/docs/ai-traffic/wordpress-setup" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Setup guide', 'pingaura-telemetry' ) . '</a>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pingaura_telemetry_save' ); ?>
				<input type="hidden" name="action" value="pingaura_telemetry_save">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pingaura-key"><?php esc_html_e( 'Ingest key', 'pingaura-telemetry' ); ?></label></th>
						<td>
							<?php if ( ! $constant ) : ?>
								<input id="pingaura-key" type="password" class="regular-text" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr( $name ); ?>[ingest_key]" value="" placeholder="pa_…">
							<?php endif; ?>
							<p class="description">
								<?php
								if ( $constant ) {
									printf(
										/* translators: 1: the non-secret start of the key, 2: constant name. */
										esc_html__( 'Using %1$s from %2$s in wp-config.php.', 'pingaura-telemetry' ),
										'<code>' . esc_html( PingAura_Telemetry_Settings::key_hint( $key ) ) . '</code>',
										'<code>PINGAURA_TELEMETRY_KEY</code>'
									);
								} elseif ( $has_key ) {
									printf(
										/* translators: %s: the non-secret start of the saved key. */
										esc_html__( 'Saved key: %s. Leave blank to keep it.', 'pingaura-telemetry' ),
										'<code>' . esc_html( PingAura_Telemetry_Settings::key_hint( $key ) ) . '</code>'
									);
								} else {
									esc_html_e( 'Create an ingest key in PingAura under Settings → API keys.', 'pingaura-telemetry' );
								}
								?>
							</p>
							<?php if ( $has_key && ! $constant ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1"> <?php esc_html_e( 'Remove the saved key', 'pingaura-telemetry' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pingaura-domain"><?php esc_html_e( 'Domain', 'pingaura-telemetry' ); ?></label></th>
						<td>
							<input id="pingaura-domain" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[domain]" value="<?php echo esc_attr( $settings['domain'] ); ?>">
							<p class="description"><?php esc_html_e( 'The domain as it is registered in PingAura.', 'pingaura-telemetry' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'pingaura-telemetry' ); ?></th>
						<td>
							<fieldset>
								<?php
								$modes = array(
									'off'  => __( 'Off', 'pingaura-telemetry' ),
									'test' => __( 'Test: only browsers with test visits turned on', 'pingaura-telemetry' ),
									'live' => __( 'Live: all visitors', 'pingaura-telemetry' ),
								);
								foreach ( $modes as $value => $label ) :
									?>
									<label style="display:block;margin-bottom:4px">
										<input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['mode'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI crawlers', 'pingaura-telemetry' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[crawlers]" value="1" <?php checked( $settings['crawlers'] ); ?>>
								<?php esc_html_e( 'Report requests from AI crawlers such as GPTBot and ClaudeBot (Live mode only)', 'pingaura-telemetry' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Crawlers do not run scripts, so they are recorded on the server. A page cache answers most requests before WordPress runs; exclude AI crawler user agents from your cache to record them.', 'pingaura-telemetry' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'IndexNow', 'pingaura-telemetry' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[indexnow]" value="1" <?php checked( $indexnow ); ?>>
								<?php esc_html_e( 'Tell Bing and other IndexNow search engines when a page is published or updated', 'pingaura-telemetry' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Bing powers the web results in ChatGPT search and Copilot, so new pages can appear in AI answers sooner. Works without a PingAura key.', 'pingaura-telemetry' ); ?></p>
							<?php if ( $indexnow ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: key file URL. */
										esc_html__( 'Key file: %s', 'pingaura-telemetry' ),
										'<a href="' . esc_url( PingAura_Telemetry_IndexNow::key_location() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( PingAura_Telemetry_IndexNow::key_location() ) . '</a>'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Check the connection', 'pingaura-telemetry' ); ?></h2>
			<p><?php esc_html_e( 'Test events appear in your PingAura data like real ones.', 'pingaura-telemetry' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<?php wp_nonce_field( 'pingaura_telemetry_test' ); ?>
				<input type="hidden" name="action" value="pingaura_telemetry_test">
				<button class="button" name="kind" value="referral"><?php esc_html_e( 'Send a ChatGPT visit', 'pingaura-telemetry' ); ?></button>
				<button class="button" name="kind" value="crawler"><?php esc_html_e( 'Send a GPTBot request', 'pingaura-telemetry' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<?php wp_nonce_field( 'pingaura_telemetry_test_cookie' ); ?>
				<input type="hidden" name="action" value="pingaura_telemetry_test_cookie">
				<input type="hidden" name="enable" value="<?php echo $test_on ? '' : '1'; ?>">
				<button class="button">
					<?php if ( $test_on ) : ?>
						<?php esc_html_e( 'Turn off test visits for this browser', 'pingaura-telemetry' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Turn on test visits for this browser', 'pingaura-telemetry' ); ?>
					<?php endif; ?>
				</button>
			</form>

			<?php if ( $indexnow ) : ?>
				<h2><?php esc_html_e( 'Submit existing pages', 'pingaura-telemetry' ); ?></h2>
				<p><?php esc_html_e( 'Send every published page to IndexNow once, for example right after turning it on.', 'pingaura-telemetry' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'pingaura_telemetry_indexnow_submit_all' ); ?>
					<input type="hidden" name="action" value="pingaura_telemetry_indexnow_submit_all">
					<button class="button"><?php esc_html_e( 'Submit all published pages', 'pingaura-telemetry' ); ?></button>
				</form>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Last response', 'pingaura-telemetry' ); ?></th>
					<td><code><?php echo esc_html( $last ? $last['status'] . ' · ' . $last['at'] : __( 'None yet', 'pingaura-telemetry' ) ); ?></code></td>
				</tr>
				<?php if ( $in_last ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last IndexNow submission', 'pingaura-telemetry' ); ?></th>
						<td>
							<code>
								<?php
								printf(
									/* translators: 1: status, 2: number of URLs, 3: time. */
									esc_html__( '%1$s · %2$d URLs · %3$s', 'pingaura-telemetry' ),
									esc_html( $in_last['status'] ),
									(int) $in_last['urls'],
									esc_html( $in_last['at'] )
								);
								?>
							</code>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Your IP address', 'pingaura-telemetry' ); ?></th>
					<td><code><?php echo esc_html( (string) PingAura_Telemetry_Tracker::client_ip() ); ?></code></td>
				</tr>
			</table>
		</div>
		<?php
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
	 * Returns to the settings page with a notice.
	 *
	 * @param string $message Notice text.
	 */
	private static function redirect( $message ) {
		set_transient( self::notice_key(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
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

	/**
	 * The settings page URL.
	 *
	 * @return string
	 */
	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}
}
