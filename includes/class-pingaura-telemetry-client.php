<?php
/**
 * PingAura API client.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds page_view events and sends them to PingAura.
 */
final class PingAura_Telemetry_Client {

	const ENDPOINT = 'https://telemetry.pingaura.ai/v1/events';

	const STATUS_OPTION = 'pingaura_telemetry_status';

	const BACKOFF_TRANSIENT = 'pingaura_telemetry_backoff';

	const BACKOFF_SECONDS = 120;

	const TIMEOUT_SECONDS = 2;

	/**
	 * Builds a page_view event.
	 *
	 * Query strings are dropped from the page URL except utm_ parameters, and
	 * from the referrer entirely, because they can carry personal data.
	 * Fragments are always dropped.
	 *
	 * @param array $context {
	 *     Event context.
	 *
	 *     @type string   $url        Absolute page URL.
	 *     @type string   $referrer   Referring URL, if any.
	 *     @type string   $title      Page title, if known.
	 *     @type string   $user_agent Visitor user agent.
	 *     @type int|null $status     HTTP status of the response, if known.
	 * }
	 * @return array
	 */
	public static function page_view( array $context ) {
		$now  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$url  = self::clean_url( isset( $context['url'] ) ? $context['url'] : '', true );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$event_context = array(
			'url'  => $url,
			'path' => '' !== $path ? $path : '/',
		);

		$referrer = self::clean_url( isset( $context['referrer'] ) ? $context['referrer'] : '', false );
		if ( '' !== $referrer ) {
			$event_context['referrer'] = $referrer;
		}
		if ( ! empty( $context['title'] ) ) {
			$event_context['title'] = self::clean_text( wp_strip_all_tags( $context['title'] ), 512 );
		}
		if ( ! empty( $context['user_agent'] ) ) {
			$event_context['user_agent'] = self::clean_text( $context['user_agent'], 512 );
		}
		if ( isset( $context['status'] ) && is_int( $context['status'] ) && $context['status'] >= 100 && $context['status'] <= 599 ) {
			$event_context['status'] = $context['status'];
		}

		return array(
			'event_id'       => wp_generate_uuid4(),
			'schema_version' => 1,
			'type'           => 'page_view',
			'timestamp'      => $now,
			'sent_at'        => $now,
			'context'        => $event_context,
			'properties'     => new stdClass(),
			'library'        => array(
				'name'    => 'pingaura-wordpress',
				'version' => PINGAURA_TELEMETRY_VERSION,
			),
		);
	}

	/**
	 * Sends one event and returns a short status line. Never throws.
	 *
	 * After a failed request, sending pauses for BACKOFF_SECONDS so an outage
	 * at PingAura never ties up the site's PHP workers.
	 *
	 * @param array       $event     Event from page_view().
	 * @param string|null $client_ip Visitor IP, used for country and crawler verification.
	 * @param bool        $force     Send even while backing off. Used by the connection test.
	 * @return string
	 */
	public static function send( array $event, $client_ip = null, $force = false ) {
		$key = PingAura_Telemetry_Settings::ingest_key();

		if ( ! PingAura_Telemetry_Settings::is_valid_key( $key ) ) {
			return 'not sent: no ingest key';
		}
		if ( ! $force && get_transient( self::BACKOFF_TRANSIENT ) ) {
			return 'not sent: paused after an error';
		}

		$body = wp_json_encode(
			array(
				'domain' => PingAura_Telemetry_Settings::get()['domain'],
				'events' => array( $event ),
			)
		);
		if ( false === $body ) {
			return 'not sent: could not encode the event';
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $key,
		);
		if ( $client_ip ) {
			$headers['X-PA-Client-IP'] = $client_ip;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
				'headers'     => $headers,
				'user-agent'  => 'pingaura-wordpress/' . PINGAURA_TELEMETRY_VERSION,
				'body'        => $body,
			)
		);

		$status = self::describe( $response );
		$code   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_SECONDS );
		}

		self::record_status( $status );

		return $status;
	}

	/**
	 * The last response from PingAura, for the settings page.
	 *
	 * @return array{status: string, at: string}|null
	 */
	public static function last_status() {
		$last = get_option( self::STATUS_OPTION );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * A stable, short description of a response: the HTTP code, plus the error
	 * code PingAura returned for a failure.
	 *
	 * @param array|WP_Error $response Response from wp_remote_post().
	 * @return string
	 */
	private static function describe( $response ) {
		if ( is_wp_error( $response ) ) {
			return 'connection error';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return (string) $code;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ? sanitize_key( $body['error'] ) : '';

		return trim( $code . ' ' . $error );
	}

	/**
	 * Stores the status only when it changes, so a healthy site does not write
	 * to the database on every pageview.
	 *
	 * @param string $status Status line.
	 */
	private static function record_status( $status ) {
		$last = self::last_status();
		if ( null === $last || $last['status'] !== $status ) {
			update_option(
				self::STATUS_OPTION,
				array(
					'status' => $status,
					'at'     => gmdate( 'c' ),
				),
				false
			);
		}
	}

	/**
	 * Returns an http(s) URL without its fragment and, optionally, with only
	 * its utm_ query parameters. Anything else returns an empty string.
	 *
	 * @param mixed $url       URL.
	 * @param bool  $keep_utm  Whether to keep utm_ parameters.
	 * @return string
	 */
	private static function clean_url( $url, $keep_utm ) {
		if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$url   = self::clean_text( $url, 2048 );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$clean = strtolower( $parts['scheme'] ) . '://' . $parts['host']
			. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '/' );

		if ( $keep_utm && ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
			$utm = array();
			foreach ( $query as $name => $value ) {
				if ( 0 === strpos( (string) $name, 'utm_' ) && is_string( $value ) ) {
					$utm[ $name ] = $value;
				}
			}
			if ( $utm ) {
				$clean .= '?' . http_build_query( $utm, '', '&', PHP_QUERY_RFC3986 );
			}
		}

		return $clean;
	}

	/**
	 * Removes invalid UTF-8 and control characters, then truncates.
	 *
	 * @param string $value  Text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function clean_text( $value, $length ) {
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
