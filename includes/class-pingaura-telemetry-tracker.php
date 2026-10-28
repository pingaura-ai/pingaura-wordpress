<?php
/**
 * Front-end tracking.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records visits and AI crawler requests.
 *
 * Visits are reported by a small script that runs after the page has loaded,
 * so they are counted on cached pages too. The script posts to a REST route on
 * this site, which adds the visitor's IP address and user agent and forwards
 * the event to PingAura after the response has been sent. The ingest key
 * never reaches the browser.
 *
 * AI crawlers do not run scripts, so they are reported from PHP. That only
 * works for requests a page cache lets through to WordPress.
 */
final class PingAura_Telemetry_Tracker {

	const TEST_COOKIE = 'pingaura_test';

	const REST_NAMESPACE = 'pingaura-telemetry/v1';

	const MAX_BODY_BYTES = 4096;

	const CRAWLER_PATTERN = '/GPTBot|ChatGPT-User|OAI-SearchBot|ClaudeBot|Claude-User|Claude-SearchBot|anthropic-ai|PerplexityBot|Perplexity-User|Google-Extended|Google-CloudVertexBot|Gemini-Deep-Research|Bytespider|CCBot|Amazonbot|Applebot-Extended|meta-externalagent|cohere-ai|DuckAssistBot|MistralAI-User|YouBot/i';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'print_script' ), 99 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'template_redirect', array( __CLASS__, 'track_crawler' ), 0 );
	}

	/**
	 * Prints the visit script.
	 *
	 * In Live mode it is left out for logged-in users. In Test mode it is
	 * printed for everyone but only sends from a browser holding the test
	 * cookie. It is marked so that optimisation plugins do not delay it until
	 * the visitor interacts with the page.
	 */
	public static function print_script() {
		$settings = PingAura_Telemetry_Settings::get();

		if ( 'off' === $settings['mode'] || ! PingAura_Telemetry_Settings::is_valid_key( PingAura_Telemetry_Settings::ingest_key() ) ) {
			return;
		}
		if ( 'live' === $settings['mode'] && is_user_logged_in() && ! self::track_logged_in_users() ) {
			return;
		}

		/** This filter is documented in includes/class-pingaura-telemetry-tracker.php */
		if ( ! apply_filters( 'pingaura_telemetry_should_track', true, 'visit' ) ) {
			return;
		}

		$config = wp_json_encode(
			array(
				'endpoint' => rest_url( self::REST_NAMESPACE . '/visit' ),
				'cookie'   => 'test' === $settings['mode'] ? self::TEST_COOKIE . '=1' : '',
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
		);

		$script = <<<JS
(function (c) {
	try {
		if (c.cookie && document.cookie.indexOf(c.cookie) === -1) return;
		if (!navigator.sendBeacon || navigator.webdriver) return;
		var send = function () {
			var body = JSON.stringify({ u: location.href, r: document.referrer, t: document.title });
			navigator.sendBeacon(c.endpoint, new Blob([body], { type: 'text/plain' }));
		};
		if (document.readyState === 'complete') { setTimeout(send, 0); } else { addEventListener('load', function () { setTimeout(send, 0); }); }
	} catch (e) {}
})($config);
JS;

		wp_print_inline_script_tag(
			$script,
			array(
				'id'                      => 'pingaura-telemetry',
				'data-no-optimize'        => '1',
				'data-no-defer'           => '1',
				'data-cfasync'            => 'false',
				'data-pagespeed-no-defer' => true,
			)
		);
	}

	/**
	 * Registers the REST route the visit script posts to.
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/visit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_visit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Accepts a visit from the script. Always answers 204 straight away and
	 * forwards the visit to PingAura once the response has been sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_visit( WP_REST_Request $request ) {
		$done = new WP_REST_Response( null, 204 );

		if ( ! self::is_enabled_for_visitor() || ! self::is_same_origin( $request ) ) {
			return $done;
		}

		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return $done;
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['u'] ) || ! is_string( $data['u'] ) || ! self::is_own_url( $data['u'] ) ) {
			return $done;
		}

		/**
		 * Filters whether to record an event.
		 *
		 * Return false to skip it, for example when the visitor has not given
		 * consent.
		 *
		 * @param bool   $track Whether to record the event.
		 * @param string $kind  'visit' for a page view, 'crawler' for an AI crawler request.
		 */
		if ( ! apply_filters( 'pingaura_telemetry_should_track', true, 'visit' ) ) {
			return $done;
		}

		$event = PingAura_Telemetry_Client::page_view(
			array(
				'url'        => $data['u'],
				'referrer'   => isset( $data['r'] ) && is_string( $data['r'] ) ? $data['r'] : '',
				'title'      => isset( $data['t'] ) && is_string( $data['t'] ) ? $data['t'] : '',
				'user_agent' => self::server_value( 'HTTP_USER_AGENT' ),
			)
		);

		self::send_after_response( $event, self::client_ip() );

		return $done;
	}

	/**
	 * Reports a request from a known AI crawler once the response has been sent.
	 */
	public static function track_crawler() {
		$settings = PingAura_Telemetry_Settings::get();

		if ( ! $settings['crawlers'] || 'live' !== $settings['mode'] || ! PingAura_Telemetry_Settings::is_valid_key( PingAura_Telemetry_Settings::ingest_key() ) ) {
			return;
		}
		if ( 'GET' !== self::server_value( 'REQUEST_METHOD' ) ) {
			return;
		}

		$user_agent = self::server_value( 'HTTP_USER_AGENT' );

		/**
		 * Filters the pattern that identifies AI crawlers by user agent.
		 *
		 * @param string $pattern PCRE pattern.
		 */
		$pattern = apply_filters( 'pingaura_telemetry_crawler_pattern', self::CRAWLER_PATTERN );

		if ( '' === $user_agent || ! is_string( $pattern ) || 1 !== @preg_match( $pattern, $user_agent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid filtered pattern must not raise warnings on the front end.
			return;
		}

		/** This filter is documented in includes/class-pingaura-telemetry-tracker.php */
		if ( ! apply_filters( 'pingaura_telemetry_should_track', true, 'crawler' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url         = set_url_scheme( 'http://' . wp_parse_url( home_url(), PHP_URL_HOST ) . $request_uri );
		$ip          = self::client_ip();

		add_action(
			'shutdown',
			static function () use ( $url, $user_agent, $ip ) {
				$status = http_response_code();

				self::finish_response();
				PingAura_Telemetry_Client::send(
					PingAura_Telemetry_Client::page_view(
						array(
							'url'        => $url,
							'user_agent' => $user_agent,
							'status'     => is_int( $status ) ? $status : null,
						)
					),
					$ip
				);
			},
			PHP_INT_MAX
		);
	}

	/**
	 * The visitor's IP address.
	 *
	 * REMOTE_ADDR is used when it is a public address. When it is private, the
	 * site is behind a local proxy, and the last public address in
	 * X-Forwarded-For is used: entries to its left are supplied by the client
	 * and cannot be trusted. Behind a CDN such as Cloudflare, use the
	 * pingaura_telemetry_client_ip filter to read the CDN's own header.
	 *
	 * @return string|null
	 */
	public static function client_ip() {
		$remote = self::server_value( 'REMOTE_ADDR' );
		$ip     = self::is_public_ip( $remote ) ? $remote : null;

		if ( null === $ip && '' !== $remote ) {
			$forwarded = array_reverse( array_map( 'trim', explode( ',', self::server_value( 'HTTP_X_FORWARDED_FOR' ) ) ) );
			foreach ( $forwarded as $candidate ) {
				if ( self::is_public_ip( $candidate ) ) {
					$ip = $candidate;
					break;
				}
			}
		}

		/**
		 * Filters the visitor IP sent to PingAura. Return null to send none.
		 *
		 * @param string|null $ip Detected IP address.
		 */
		$ip = apply_filters( 'pingaura_telemetry_client_ip', $ip );

		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	/**
	 * Sends an event once the response to the current request has been sent.
	 *
	 * @param array       $event Event.
	 * @param string|null $ip    Visitor IP.
	 */
	private static function send_after_response( array $event, $ip ) {
		add_action(
			'shutdown',
			static function () use ( $event, $ip ) {
				self::finish_response();
				PingAura_Telemetry_Client::send( $event, $ip );
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Flushes the response to the client where the server supports it.
	 */
	private static function finish_response() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Whether visits from logged-in users are recorded in Live mode.
	 *
	 * @return bool
	 */
	private static function track_logged_in_users() {
		/**
		 * Filters whether logged-in users are tracked in Live mode. Off by default
		 * so editors and administrators do not inflate the numbers.
		 *
		 * @param bool $track Whether to track logged-in users.
		 */
		return (bool) apply_filters( 'pingaura_telemetry_track_logged_in_users', false );
	}

	/**
	 * Whether the current visitor may be tracked in the current mode.
	 *
	 * @return bool
	 */
	private static function is_enabled_for_visitor() {
		$settings = PingAura_Telemetry_Settings::get();

		if ( 'off' === $settings['mode'] || ! PingAura_Telemetry_Settings::is_valid_key( PingAura_Telemetry_Settings::ingest_key() ) ) {
			return false;
		}

		return 'live' === $settings['mode']
			|| ( isset( $_COOKIE[ self::TEST_COOKIE ] ) && '1' === $_COOKIE[ self::TEST_COOKIE ] );
	}

	/**
	 * Whether the request came from a page on this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function is_same_origin( WP_REST_Request $request ) {
		foreach ( array( 'origin', 'referer' ) as $header ) {
			$value = (string) $request->get_header( $header );
			if ( '' !== $value && 'null' !== $value ) {
				return self::is_own_url( $value );
			}
		}
		return false;
	}

	/**
	 * Whether a URL belongs to this site.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_own_url( $url ) {
		return (bool) preg_match( '#^https?://#i', $url )
			&& PingAura_Telemetry_Settings::normalize_domain( $url ) === PingAura_Telemetry_Settings::site_domain();
	}

	/**
	 * Whether a value is a public IP address.
	 *
	 * @param string $ip Candidate.
	 * @return bool
	 */
	private static function is_public_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		// Carrier-grade NAT (100.64.0.0/10) is not excluded by the flags before PHP 8.2.
		$long = ip2long( $ip );
		return false === $long || ( $long & 0xFFC00000 ) !== ip2long( '100.64.0.0' );
	}

	/**
	 * A sanitized $_SERVER value.
	 *
	 * @param string $key Server variable.
	 * @return string
	 */
	private static function server_value( $key ) {
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}
}
