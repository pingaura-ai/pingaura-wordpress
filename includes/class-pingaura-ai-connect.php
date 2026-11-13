<?php
/**
 * Connecting the site to PingAura.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * The connect flow.
 *
 * 1. An administrator clicks Connect. The plugin stores a random state and a
 *    PKCE verifier in a transient and sends the browser to PingAura.
 * 2. PingAura proves the administrator controls this site by calling the
 *    connect-check REST route, which answers only for the pending state.
 * 3. After approval, PingAura sends the browser back with a one-time code,
 *    which the plugin exchanges, with the verifier, for the site credentials.
 */
final class PingAura_AI_Connect {

	const TRANSIENT = 'pingaura_ai_connect';

	const TTL = 15 * MINUTE_IN_SECONDS;

	const REST_NAMESPACE = 'pingaura/v1';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Registers the ownership check route.
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/connect-check',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_check' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'state' => array(
						'type' => 'string',
					),
				),
			)
		);
	}

	/**
	 * Answers PingAura's ownership check with the pending state and challenge,
	 * or 404 when nothing is pending or the state does not match.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_check( WP_REST_Request $request ) {
		$state   = (string) $request->get_param( 'state' );
		$pending = self::pending();

		if ( null === $pending || '' === $state || ! hash_equals( $pending['state'], $state ) ) {
			$response = new WP_REST_Response( array( 'code' => 'not_found' ), 404 );
		} else {
			$response = new WP_REST_Response(
				array(
					'state'          => $pending['state'],
					'code_challenge' => $pending['challenge'],
				),
				200
			);
		}

		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Starts a connect and returns the approval URL on PingAura.
	 *
	 * @return string|WP_Error
	 */
	public static function start() {
		$state    = PingAura_AI_Api::random_token();
		$verifier = PingAura_AI_Api::random_token();
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( is_wp_error( $verifier ) ) {
			return $verifier;
		}

		$challenge = PingAura_AI_Api::challenge( $verifier );

		set_transient(
			self::TRANSIENT,
			array(
				'state'     => $state,
				'verifier'  => $verifier,
				'challenge' => $challenge,
				'created'   => time(),
				'user'      => get_current_user_id(),
			),
			self::TTL
		);

		return add_query_arg(
			array(
				'site_url'       => rawurlencode( home_url() ),
				'return_url'     => rawurlencode( PingAura_AI_Admin::panel_url() ),
				'state'          => rawurlencode( $state ),
				'code_challenge' => rawurlencode( $challenge ),
				'plugin_version' => rawurlencode( PINGAURA_TELEMETRY_VERSION ),
			),
			PingAura_AI_Api::app_url() . '/wordpress/connect'
		);
	}

	/**
	 * Completes a connect when the browser returns from PingAura.
	 *
	 * The state must match the pending one, created by the same user. The
	 * pending connect is cleared once the code has been tried, because the
	 * code works only once.
	 *
	 * @param string $code  One-time code.
	 * @param string $state State returned with it.
	 * @return true|WP_Error
	 */
	public static function finish( $code, $state ) {
		$pending = self::pending();

		if ( null === $pending || '' === $state || ! hash_equals( $pending['state'], $state ) || get_current_user_id() !== $pending['user'] ) {
			return new WP_Error( 'pingaura_ai_state', __( 'This connection request expired after 15 minutes, or was started in another browser or by another user. Click Connect to PingAura again.', 'pingaura-telemetry' ) );
		}

		delete_transient( self::TRANSIENT );

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{16,256}$/', $code ) ) {
			return new WP_Error( 'pingaura_ai_code', __( 'PingAura returned an invalid code. Click Connect to PingAura to try again.', 'pingaura-telemetry' ) );
		}

		$data = PingAura_AI_Api::exchange_code( $code, $pending['verifier'] );
		if ( is_wp_error( $data ) ) {
			$error = $data->get_error_data();
			if ( is_array( $error ) && isset( $error['error'] ) && 'ownership_failed' === $error['error'] ) {
				// PingAura could not confirm the request with this site, usually because it expired.
				return new WP_Error( 'pingaura_ai_state', __( 'PingAura could not confirm this connection request with your site, usually because it expired after 15 minutes. Click Connect to PingAura again.', 'pingaura-telemetry' ) );
			}
			return $data;
		}
		if ( ! PingAura_AI_Api::save_connection( $data ) ) {
			return new WP_Error( 'pingaura_ai_bad_response', __( 'PingAura sent an unexpected response.', 'pingaura-telemetry' ) );
		}

		// Send the page list from WP-Cron in a few seconds, so the redirect is not held up.
		PingAura_AI_Pages::on_connected();

		return true;
	}

	/**
	 * Disconnects: tells PingAura, then removes the credentials here.
	 *
	 * @param bool $blocking Wait for PingAura's answer.
	 * @return bool Whether PingAura confirmed it.
	 */
	public static function disconnect( $blocking ) {
		$confirmed = PingAura_AI_Api::disconnect_remote( $blocking );
		PingAura_AI_Api::forget_connection();
		PingAura_AI_Pages::forget();
		delete_transient( self::TRANSIENT );
		return $confirmed;
	}

	/**
	 * Disconnects without waiting when the plugin is deactivated.
	 */
	public static function on_deactivate() {
		self::disconnect( false );
	}

	/**
	 * The pending connect, if any and not expired.
	 *
	 * @return array{state: string, verifier: string, challenge: string, created: int, user: int}|null
	 */
	private static function pending() {
		$pending = get_transient( self::TRANSIENT );

		if ( ! is_array( $pending ) ) {
			return null;
		}
		foreach ( array( 'state', 'verifier', 'challenge' ) as $key ) {
			if ( empty( $pending[ $key ] ) || ! is_string( $pending[ $key ] ) ) {
				return null;
			}
		}
		if ( empty( $pending['created'] ) || time() - (int) $pending['created'] > self::TTL ) {
			return null;
		}

		$pending['user'] = isset( $pending['user'] ) ? (int) $pending['user'] : 0;
		return $pending;
	}
}
