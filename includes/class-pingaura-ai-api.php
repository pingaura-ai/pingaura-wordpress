<?php
/**
 * Connection to the PingAura app.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the site's PingAura credentials and sends signed requests to the app.
 *
 * Each connected site holds a site ID and a site secret. Every request to the
 * app is signed with the secret (HMAC-SHA256), and responses that carry fixes
 * are signed by the app with the same secret and checked before use.
 */
final class PingAura_AI_Api {

	const DEFAULT_APP_URL = 'https://www.pingaura.ai';

	const OPTION_SITE_ID = 'pingaura_ai_site_id';

	const OPTION_SECRET = 'pingaura_ai_site_secret';

	const OPTION_SITE = 'pingaura_ai_site';

	const OPTION_LAST_TS = 'pingaura_ai_last_ts';

	const TIMEOUT_SECONDS = 10;

	const MAX_CLOCK_SKEW = 300;

	/**
	 * The app's origin. PINGAURA_AI_APP_URL in wp-config.php overrides it.
	 *
	 * @return string
	 */
	public static function app_url() {
		$url = defined( 'PINGAURA_AI_APP_URL' ) ? (string) PINGAURA_AI_APP_URL : '';
		if ( '' === $url ) {
			return self::DEFAULT_APP_URL;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return self::DEFAULT_APP_URL;
		}

		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * Whether the site holds PingAura credentials.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::site_id() && '' !== self::secret();
	}

	/**
	 * The connected site's ID, or an empty string.
	 *
	 * @return string
	 */
	public static function site_id() {
		$id = get_option( self::OPTION_SITE_ID, '' );
		return is_string( $id ) && self::is_valid_id( $id ) ? $id : '';
	}

	/**
	 * The site secret, or an empty string.
	 *
	 * @return string
	 */
	private static function secret() {
		$secret = get_option( self::OPTION_SECRET, '' );
		return is_string( $secret ) && self::is_valid_secret( $secret ) ? $secret : '';
	}

	/**
	 * Details of the connection for display: team slug, domain and time.
	 *
	 * @return array{team_slug: string, domain: string, connected_at: int}
	 */
	public static function site() {
		$site = get_option( self::OPTION_SITE, array() );

		return wp_parse_args(
			is_array( $site ) ? $site : array(),
			array(
				'team_slug'    => '',
				'domain'       => '',
				'connected_at' => 0,
			)
		);
	}

	/**
	 * Saves the credentials returned by the code exchange. Not autoloaded.
	 *
	 * @param array $data Response from the token endpoint.
	 * @return bool Whether the data was valid and saved.
	 */
	public static function save_connection( array $data ) {
		$site_id = isset( $data['site_id'] ) && is_string( $data['site_id'] ) ? $data['site_id'] : '';
		$secret  = isset( $data['site_secret'] ) && is_string( $data['site_secret'] ) ? $data['site_secret'] : '';

		if ( ! self::is_valid_id( $site_id ) || ! self::is_valid_secret( $secret ) ) {
			return false;
		}

		update_option( self::OPTION_SITE_ID, $site_id, false );
		update_option( self::OPTION_SECRET, $secret, false );
		update_option(
			self::OPTION_SITE,
			array(
				'team_slug'    => isset( $data['team_slug'] ) && is_string( $data['team_slug'] ) ? sanitize_title( $data['team_slug'] ) : '',
				'domain'       => isset( $data['domain'] ) && is_string( $data['domain'] ) ? sanitize_text_field( $data['domain'] ) : '',
				'connected_at' => time(),
			),
			false
		);

		return true;
	}

	/**
	 * Removes the credentials from this site.
	 */
	public static function forget_connection() {
		delete_option( self::OPTION_SITE_ID );
		delete_option( self::OPTION_SECRET );
		delete_option( self::OPTION_SITE );
		delete_option( self::OPTION_LAST_TS );
	}

	/**
	 * 32 random bytes, base64url encoded.
	 *
	 * @return string|WP_Error
	 */
	public static function random_token() {
		try {
			return self::base64url( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'pingaura_ai_random', __( 'Could not generate a secure random value.', 'pingaura-telemetry' ) );
		}
	}

	/**
	 * The PKCE challenge for a verifier: base64url(sha256(verifier)).
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes random bytes for the connect flow.
	}

	/**
	 * Exchanges the one-time code from the approval page for credentials.
	 *
	 * @param string $code     One-time code.
	 * @param string $verifier PKCE code verifier.
	 * @return array|WP_Error Decoded response.
	 */
	public static function exchange_code( $code, $verifier ) {
		$response = wp_remote_post(
			self::app_url() . '/api/wordpress/connect/token',
			array(
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
				'user-agent'  => self::user_agent(),
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'code'          => $code,
						'code_verifier' => $verifier,
						'site_url'      => home_url(),
					)
				),
			)
		);

		return self::decode( $response );
	}

	/**
	 * Requests a short-lived panel URL.
	 *
	 * @return string|WP_Error
	 */
	public static function panel_session() {
		$data = self::decode( self::request( 'POST', '/api/wordpress/panel-session', new stdClass() ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$url    = isset( $data['url'] ) && is_string( $data['url'] ) ? $data['url'] : '';
		$prefix = self::app_url() . '/wordpress/site/';
		if ( 0 !== strpos( $url, $prefix ) ) {
			return new WP_Error( 'pingaura_ai_bad_response', __( 'PingAura sent an unexpected response.', 'pingaura-telemetry' ) );
		}

		return $url;
	}

	/**
	 * Fetches the fixes PingAura proposes for this site. The response must carry
	 * a valid signature, or nothing is returned.
	 *
	 * @return array[]|WP_Error Raw fixes, not yet validated.
	 */
	public static function fixes() {
		$response = self::request( 'GET', '/api/wordpress/fixes' );
		$data     = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! self::verify_response( $response ) ) {
			return new WP_Error( 'pingaura_ai_signature', __( 'The response from PingAura could not be verified, so no fixes are shown.', 'pingaura-telemetry' ) );
		}
		if ( ! isset( $data['fixes'] ) || ! is_array( $data['fixes'] ) ) {
			return new WP_Error( 'pingaura_ai_bad_response', __( 'PingAura sent an unexpected response.', 'pingaura-telemetry' ) );
		}

		return array_values( array_filter( $data['fixes'], 'is_array' ) );
	}

	/**
	 * Tells PingAura a fix is live on the site.
	 *
	 * @param string $fix_id Fix ID.
	 * @param string $sha256 Hex SHA-256 of the applied content.
	 * @return bool
	 */
	public static function report_applied( $fix_id, $sha256 ) {
		$response = self::request( 'POST', '/api/wordpress/fixes/' . rawurlencode( $fix_id ) . '/applied', array( 'applied_content_sha256' => $sha256 ) );
		return ! is_wp_error( self::decode( $response ) );
	}

	/**
	 * Tells PingAura a fix was undone. A 404 counts as done: PingAura no longer
	 * knows the fix, for example after the site reconnected.
	 *
	 * @param string $fix_id Fix ID.
	 * @return bool
	 */
	public static function report_reverted( $fix_id ) {
		$response = self::request( 'POST', '/api/wordpress/fixes/' . rawurlencode( $fix_id ) . '/reverted', new stdClass() );
		$data     = self::decode( $response );
		if ( ! is_wp_error( $data ) ) {
			return true;
		}
		$error = $data->get_error_data();
		return is_array( $error ) && isset( $error['status'] ) && 404 === $error['status'];
	}

	/**
	 * Tells PingAura the site is disconnecting. Never throws.
	 *
	 * @param bool $blocking Wait for the answer.
	 * @return bool Whether PingAura confirmed it. Always false when not blocking.
	 */
	public static function disconnect_remote( $blocking ) {
		if ( ! self::is_connected() ) {
			return false;
		}

		$response = self::request(
			'POST',
			'/api/wordpress/disconnect',
			new stdClass(),
			array(
				'blocking' => (bool) $blocking,
				'timeout'  => $blocking ? 5 : 1,
			)
		);

		if ( ! $blocking || is_wp_error( $response ) ) {
			return false;
		}

		// A 401 means PingAura already refuses this site's credentials, so it is disconnected there too.
		return 401 === (int) wp_remote_retrieve_response_code( $response ) || ! is_wp_error( self::decode( $response ) );
	}

	/**
	 * Sends a signed request to the app.
	 *
	 * The signature is hex(HMAC-SHA256(site_secret, "{timestamp}\n{METHOD}\n{path}\n{hex(sha256(body))}")),
	 * where path includes the query string and an empty body hashes the empty string.
	 * The timestamp is strictly increasing per site, so two identical requests in
	 * the same second still carry different signatures and neither is refused as
	 * a replay.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path on the app, starting with a slash.
	 * @param mixed|null $body   Value to send as JSON, or null for no body.
	 * @param array      $args   Extra arguments for wp_remote_request().
	 * @return array|WP_Error
	 */
	public static function request( $method, $path, $body = null, array $args = array() ) {
		$secret  = self::secret();
		$site_id = self::site_id();
		if ( '' === $secret || '' === $site_id ) {
			return new WP_Error( 'pingaura_ai_not_connected', __( 'This site is not connected to PingAura.', 'pingaura-telemetry' ) );
		}

		$method  = strtoupper( $method );
		$payload = null === $body ? '' : wp_json_encode( $body );
		if ( false === $payload ) {
			return new WP_Error( 'pingaura_ai_encode', __( 'Could not encode the request.', 'pingaura-telemetry' ) );
		}

		$last = (int) get_option( self::OPTION_LAST_TS, 0 );
		if ( $last > time() + 60 ) {
			// The clock was moved back; start again from now rather than run ahead of it.
			$last = 0;
		}
		$ts        = max( time(), $last + 1 );
		$timestamp = (string) $ts;
		update_option( self::OPTION_LAST_TS, $ts, false );
		$signature = hash_hmac( 'sha256', $timestamp . "\n" . $method . "\n" . $path . "\n" . hash( 'sha256', $payload ), $secret );

		$headers = array(
			'X-PingAura-Site'      => $site_id,
			'X-PingAura-Timestamp' => $timestamp,
			'X-PingAura-Signature' => $signature,
		);
		if ( '' !== $payload ) {
			$headers['Content-Type'] = 'application/json';
		}

		$request = array_merge(
			array(
				'method'      => $method,
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
				'user-agent'  => self::user_agent(),
				'headers'     => $headers,
			),
			$args
		);
		if ( '' !== $payload ) {
			$request['body'] = $payload;
		}

		return wp_remote_request( self::app_url() . $path, $request );
	}

	/**
	 * Checks a response signature: hex(HMAC-SHA256(site_secret, "{X-PingAura-Timestamp}\n{body}")).
	 *
	 * @param array $response Response from wp_remote_request().
	 * @return bool
	 */
	public static function verify_response( $response ) {
		$secret    = self::secret();
		$timestamp = (string) wp_remote_retrieve_header( $response, 'x-pingaura-timestamp' );
		$signature = strtolower( (string) wp_remote_retrieve_header( $response, 'x-pingaura-signature' ) );

		if ( '' === $secret || ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . "\n" . wp_remote_retrieve_body( $response ), $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Decodes a JSON response, turning connection errors and non-2xx codes into WP_Error.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array|WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pingaura_ai_connection', __( 'Could not reach PingAura. Try again in a moment.', 'pingaura-telemetry' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code && self::is_connected() ) {
			return new WP_Error(
				'pingaura_ai_http_401',
				__( 'PingAura no longer accepts this site\'s connection, for example because it was disconnected in PingAura. On the PingAura AI page, click Disconnect, then connect again.', 'pingaura-telemetry' ),
				array( 'status' => 401 )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$error = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? sanitize_key( $data['error'] ) : '';
			return new WP_Error(
				'pingaura_ai_http_' . $code,
				sprintf(
					/* translators: %s: HTTP status code and error code from PingAura. */
					__( 'PingAura replied: %s', 'pingaura-telemetry' ),
					trim( $code . ' ' . $error )
				),
				array( 'status' => $code )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Whether a value looks like a site or fix ID.
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id );
	}

	/**
	 * Whether a value looks like a site secret.
	 *
	 * @param string $secret Candidate.
	 * @return bool
	 */
	private static function is_valid_secret( $secret ) {
		return is_string( $secret ) && 1 === preg_match( '/^[A-Za-z0-9_-]{32,128}$/', $secret );
	}

	/**
	 * The user agent for requests to the app.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return 'pingaura-wordpress/' . ( defined( 'PINGAURA_TELEMETRY_VERSION' ) ? PINGAURA_TELEMETRY_VERSION : 'unknown' );
	}
}
