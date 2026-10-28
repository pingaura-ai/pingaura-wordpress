<?php
/**
 * Stored settings.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and saves the plugin settings.
 */
final class PingAura_Telemetry_Settings {

	const OPTION = 'pingaura_telemetry';

	const MODES = array( 'off', 'test', 'live' );

	/**
	 * Settings merged with their defaults.
	 *
	 * @return array{ingest_key: string, domain: string, mode: string, crawlers: bool}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'ingest_key' => '',
				'domain'     => self::site_domain(),
				'mode'       => 'test',
				'crawlers'   => false,
			)
		);
	}

	/**
	 * The ingest key. PINGAURA_TELEMETRY_KEY in wp-config.php takes precedence
	 * over the saved value, which keeps the key out of the database.
	 *
	 * @return string
	 */
	public static function ingest_key() {
		if ( self::key_is_constant() ) {
			return (string) PINGAURA_TELEMETRY_KEY;
		}
		return (string) self::get()['ingest_key'];
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_is_constant() {
		return defined( 'PINGAURA_TELEMETRY_KEY' ) && '' !== (string) PINGAURA_TELEMETRY_KEY;
	}

	/**
	 * Validates submitted values and stores them.
	 *
	 * A blank or malformed key keeps the stored one, so a browser autofilling a
	 * saved password into the key field can never replace it.
	 *
	 * @param array $input Raw form values.
	 */
	public static function save( array $input ) {
		$current = self::get();
		$key     = isset( $input['ingest_key'] ) ? trim( sanitize_text_field( $input['ingest_key'] ) ) : '';
		$domain  = isset( $input['domain'] ) ? self::normalize_domain( sanitize_text_field( $input['domain'] ) ) : '';
		$mode    = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : '';

		if ( ! empty( $input['clear_key'] ) ) {
			$key = '';
		} elseif ( ! self::is_valid_key( $key ) ) {
			$key = $current['ingest_key'];
		}

		update_option(
			self::OPTION,
			array(
				'ingest_key' => $key,
				'domain'     => '' !== $domain ? $domain : self::site_domain(),
				'mode'       => in_array( $mode, self::MODES, true ) ? $mode : 'test',
				'crawlers'   => ! empty( $input['crawlers'] ),
			),
			true
		);
	}

	/**
	 * Whether a value has the shape of a PingAura key: pa_<id>_<secret>.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^pa_[A-Za-z0-9]+_[A-Za-z0-9_-]+$/', $key );
	}

	/**
	 * The non-secret pa_<id> part of a key, safe to display.
	 *
	 * @param string $key Ingest key.
	 * @return string
	 */
	public static function key_hint( $key ) {
		if ( ! self::is_valid_key( $key ) ) {
			return '';
		}
		$parts = explode( '_', $key );
		return $parts[0] . '_' . $parts[1] . '_…';
	}

	/**
	 * The site's host without a www. prefix.
	 *
	 * @return string
	 */
	public static function site_domain() {
		return self::normalize_domain( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Lowercases a host and strips a scheme, path and www. prefix.
	 *
	 * @param string $value Host or URL.
	 * @return string
	 */
	public static function normalize_domain( $value ) {
		$value = strtolower( trim( $value ) );
		if ( false !== strpos( $value, '://' ) ) {
			$value = (string) wp_parse_url( $value, PHP_URL_HOST );
		}
		$value = preg_replace( '#/.*$#', '', $value );
		return (string) preg_replace( '/^www\./', '', $value );
	}
}
