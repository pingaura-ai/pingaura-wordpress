<?php
/**
 * IndexNow submission.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells search engines that support IndexNow (Bing, Yandex, Naver, Seznam and
 * others) when a page is published or updated, so they recrawl it promptly.
 *
 * IndexNow proves ownership with a key file on the site. The key is generated
 * once and served by WordPress at /{key}.txt, so nothing is written to disk.
 */
final class PingAura_Telemetry_IndexNow {

	const OPTION = 'pingaura_telemetry_indexnow';

	const STATUS_OPTION = 'pingaura_telemetry_indexnow_status';

	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	const MAX_URLS_PER_REQUEST = 10000;

	/**
	 * URLs queued during this request, sent once at shutdown.
	 *
	 * @var string[]
	 */
	private static $queue = array();

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve_key_file' ), 0 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_change' ), 10, 3 );
	}

	/**
	 * Settings merged with their defaults.
	 *
	 * @return array{enabled: bool, key: string}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled' => false,
				'key'     => '',
			)
		);
	}

	/**
	 * Turns submission on or off. A key is generated the first time it is enabled.
	 *
	 * @param bool $enabled Whether to submit URLs.
	 */
	public static function set_enabled( $enabled ) {
		$settings = self::settings();
		$key      = self::is_valid_key( $settings['key'] ) ? $settings['key'] : strtolower( wp_generate_password( 32, false, false ) );

		update_option(
			self::OPTION,
			array(
				'enabled' => (bool) $enabled,
				'key'     => $key,
			),
			true
		);
	}

	/**
	 * Whether submission is on and a key exists.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::settings();
		return $settings['enabled'] && self::is_valid_key( $settings['key'] );
	}

	/**
	 * The public URL of the key file.
	 *
	 * @return string
	 */
	public static function key_location() {
		return home_url( '/' . self::settings()['key'] . '.txt' );
	}

	/**
	 * Answers requests for the key file with the key itself.
	 */
	public static function serve_key_file() {
		if ( ! self::is_enabled() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$key       = self::settings()['key'];
		$requested = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$expected  = (string) wp_parse_url( self::key_location(), PHP_URL_PATH );

		if ( $requested !== $expected ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}

	/**
	 * Queues a post's URL when it is published or updated while published.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || ! self::is_enabled() || ! self::is_public( $post ) ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$url = get_permalink( $post );
		if ( ! $url ) {
			return;
		}

		if ( empty( self::$queue ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), PHP_INT_MAX );
		}
		self::$queue[ $url ] = $url;
	}

	/**
	 * Sends queued URLs once the response has been sent.
	 */
	public static function flush_queue() {
		if ( empty( self::$queue ) ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		self::submit( array_values( self::$queue ) );
		self::$queue = array();
	}

	/**
	 * Submits the home page and every published, publicly viewable post.
	 *
	 * @return string Status line.
	 */
	public static function submit_all() {
		$urls = array( home_url( '/' ) );

		$post_types = array_filter( get_post_types( array( 'public' => true ) ), 'is_post_type_viewable' );
		unset( $post_types['attachment'] );

		$per_page = 100;
		$page     = 1;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => array_values( $post_types ),
					'post_status'            => 'publish',
					'has_password'           => false,
					'fields'                 => 'ids',
					'posts_per_page'         => $per_page,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $query->posts as $post_id ) {
				$url = get_permalink( $post_id );
				if ( $url ) {
					$urls[] = $url;
				}
			}
			$fetched = count( $query->posts );
			++$page;
		} while ( $fetched === $per_page );

		return self::submit( array_values( array_unique( $urls ) ) );
	}

	/**
	 * Sends URLs to IndexNow in batches. Never throws.
	 *
	 * @param string[] $urls Absolute URLs on this site.
	 * @return string Status line.
	 */
	public static function submit( array $urls ) {
		if ( ! self::is_enabled() || empty( $urls ) ) {
			return 'not sent: IndexNow is off';
		}

		$settings = self::settings();
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$sent     = 0;
		$status   = '';

		foreach ( array_chunk( $urls, self::MAX_URLS_PER_REQUEST ) as $batch ) {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'user-agent'  => 'pingaura-wordpress/' . PINGAURA_TELEMETRY_VERSION,
					'body'        => wp_json_encode(
						array(
							'host'        => $host,
							'key'         => $settings['key'],
							'keyLocation' => self::key_location(),
							'urlList'     => $batch,
						)
					),
				)
			);

			$code   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$status = self::describe( $code );
			if ( 200 !== $code && 202 !== $code ) {
				break;
			}
			$sent += count( $batch );
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'status' => $status,
				'urls'   => $sent,
				'at'     => gmdate( 'c' ),
			),
			false
		);

		return $status;
	}

	/**
	 * The last submission result, for the settings page.
	 *
	 * @return array{status: string, urls: int, at: string}|null
	 */
	public static function last_status() {
		$last = get_option( self::STATUS_OPTION );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Whether a post is publicly reachable.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function is_public( $post ) {
		return $post instanceof WP_Post
			&& '' === $post->post_password
			&& 'attachment' !== $post->post_type
			&& is_post_type_viewable( $post->post_type );
	}

	/**
	 * Whether a key meets the IndexNow format: 8 to 128 letters, digits or dashes.
	 *
	 * @param mixed $key Candidate key.
	 * @return bool
	 */
	private static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key );
	}

	/**
	 * A short description of an IndexNow response code.
	 *
	 * @param int $code HTTP status, or 0 for a connection error.
	 * @return string
	 */
	private static function describe( $code ) {
		$meanings = array(
			0   => 'connection error',
			200 => '200 submitted',
			202 => '202 accepted, key check pending',
			400 => '400 invalid request',
			403 => '403 key not verified yet, try again in a few minutes',
			422 => '422 URLs do not belong to this site',
			429 => '429 too many requests',
		);

		return isset( $meanings[ $code ] ) ? $meanings[ $code ] : (string) $code;
	}
}
