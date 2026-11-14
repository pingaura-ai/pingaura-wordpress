<?php
/**
 * Sending the site's page list to PingAura.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the addresses and titles of published pages, posts and products to
 * PingAura, so it can audit them even when it cannot read the sitemap, for
 * example while the site discourages search engines, is in maintenance mode
 * or sends visitors to a login page.
 *
 * A sync runs through WP-Cron: once a day, shortly after connecting, and
 * shortly after the search engine visibility setting changes. It runs only
 * while the site is connected.
 */
final class PingAura_AI_Pages {

	const CRON_HOOK = 'pingaura_ai_sync_pages';

	/**
	 * Arguments of the one-off event, so it is told apart from the daily one.
	 */
	const SOON_ARGS = array( 'soon' );

	const SOON_DELAY = 5;

	const STATUS_OPTION = 'pingaura_ai_pages_status';

	const LOCK_TRANSIENT = 'pingaura_ai_pages_lock';

	const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	const MAX_PAGES = 1000;

	const PAGES_PER_REQUEST = 100;

	const MAX_REQUESTS = 10;

	/**
	 * Upper bound for the encoded pages in one request, below PingAura's 64 KB
	 * limit to leave room for the rest of the body.
	 */
	const MAX_PAGES_BYTES = 60000;

	const MAX_TITLE_CHARS = 300;

	const MAX_URL_CHARS = 2048;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync' ), 10, 0 );
		add_action( 'update_option_blog_public', array( __CLASS__, 'schedule_soon' ), 10, 0 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_daily' ) );
	}

	/**
	 * Schedules a sync shortly after a connect, and the daily sync.
	 */
	public static function on_connected() {
		self::schedule_soon();
		self::maybe_schedule_daily();
	}

	/**
	 * Schedules a sync in a few seconds, unless one is already pending.
	 */
	public static function schedule_soon() {
		if ( ! PingAura_AI_Api::is_connected() ) {
			return;
		}
		wp_schedule_single_event( time() + self::SOON_DELAY, self::CRON_HOOK, self::SOON_ARGS );
	}

	/**
	 * Schedules the daily sync while connected, for example for sites that
	 * connected before this sync existed.
	 */
	public static function maybe_schedule_daily() {
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) || ! PingAura_AI_Api::is_connected() ) {
			return;
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Stops syncing and forgets the last result. Used on disconnect.
	 */
	public static function forget() {
		wp_unschedule_hook( self::CRON_HOOK );
		delete_option( self::STATUS_OPTION );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Sends the page list to PingAura, in up to MAX_REQUESTS requests. A
	 * refused request stops the sync; the next run tries again.
	 */
	public static function sync() {
		if ( ! PingAura_AI_Api::is_connected() ) {
			wp_unschedule_hook( self::CRON_HOOK );
			return;
		}
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );

		$state   = self::state();
		$whole   = true;
		$entries = self::collect( $whole );
		$all     = self::chunk( $entries );
		$chunks  = array_slice( $all, 0, self::MAX_REQUESTS );
		$last    = count( $chunks ) - 1;
		// PingAura removes pages missing from the list only when every page was sent.
		$whole  = $whole && count( $all ) === count( $chunks ) && count( $entries ) === array_sum( array_map( 'count', $all ) );
		$sync   = array(
			'sync_id'    => wp_generate_uuid4(),
			'started_at' => time(),
		);
		$totals = array(
			'received' => 0,
			'added'    => 0,
			'updated'  => 0,
			'skipped'  => 0,
		);
		$result = 'sent';
		$code   = 200;

		foreach ( $chunks as $index => $chunk ) {
			$data = PingAura_AI_Api::send_pages( $chunk, $state, $whole && $index === $last, $sync );

			if ( is_wp_error( $data ) ) {
				$error  = $data->get_error_data();
				$code   = is_array( $error ) && isset( $error['status'] ) ? (int) $error['status'] : 0;
				$result = self::describe_failure( $code );
				break;
			}

			$totals['received'] += isset( $data['received'] ) && is_numeric( $data['received'] ) ? (int) $data['received'] : count( $chunk );
			foreach ( array( 'added', 'updated', 'skipped' ) as $key ) {
				$totals[ $key ] += isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? (int) $data[ $key ] : 0;
			}
		}

		self::save_status( $result, $code, $totals );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * When pages were last accepted by PingAura, and how many.
	 *
	 * @return array{at: int, pages: int}|null
	 */
	public static function last_sent() {
		$status = get_option( self::STATUS_OPTION );
		if ( ! is_array( $status ) || empty( $status['sent_at'] ) ) {
			return null;
		}

		return array(
			'at'    => (int) $status['sent_at'],
			'pages' => isset( $status['sent_pages'] ) ? (int) $status['sent_pages'] : 0,
		);
	}

	/**
	 * Whether search engines may index the site, and whether it is in maintenance mode.
	 *
	 * @return array{blog_public: bool, maintenance: bool}
	 */
	private static function state() {
		$maintenance = function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode();

		/**
		 * Filters whether the site is in maintenance mode, for maintenance plugins
		 * that WordPress does not detect. WordPress's own maintenance mode is
		 * reported either way.
		 *
		 * @param bool $maintenance Whether the site is in maintenance mode. Default false.
		 */
		$filtered = (bool) apply_filters( 'pingaura_ai_maintenance_mode', false );

		return array(
			'blog_public' => (bool) get_option( 'blog_public' ),
			'maintenance' => $maintenance || $filtered,
		);
	}

	/**
	 * The home page, then published pages, posts and products, each newest
	 * change first, up to MAX_PAGES entries without duplicate addresses.
	 *
	 * @param bool $whole Set to false when MAX_PAGES may have left pages out.
	 * @return array[] Entries with url, title and type.
	 */
	private static function collect( &$whole ) {
		$entries = array();
		self::add_entry( $entries, home_url( '/' ), get_bloginfo( 'name' ), 'page' );

		$types = array( 'page', 'post' );
		if ( class_exists( 'WooCommerce' ) || post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}

		foreach ( $types as $type ) {
			$remaining = self::MAX_PAGES - count( $entries );
			if ( $remaining <= 0 ) {
				$whole = false;
				break;
			}

			$query = new WP_Query(
				array(
					'post_type'              => $type,
					'post_status'            => 'publish',
					'has_password'           => false,
					'fields'                 => 'ids',
					'posts_per_page'         => $remaining,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$ids = array_map( 'intval', $query->posts );
			if ( count( $ids ) >= $remaining ) {
				$whole = false;
			}
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $ids, true, false );
			}

			foreach ( $ids as $post_id ) {
				$url = get_permalink( $post_id );
				if ( is_string( $url ) ) {
					self::add_entry( $entries, $url, get_the_title( $post_id ), $type );
				}
			}
		}

		return array_values( array_slice( $entries, 0, self::MAX_PAGES ) );
	}

	/**
	 * Adds an entry unless its address is already listed, unusable or carries a
	 * query string, as with plain permalinks, which PingAura does not take.
	 *
	 * @param array[] $entries Entries keyed by address.
	 * @param string  $url     Absolute address.
	 * @param string  $title   Title, possibly with HTML and entities.
	 * @param string  $type    page, post or product.
	 */
	private static function add_entry( array &$entries, $url, $title, $type ) {
		$url    = esc_url_raw( $url, array( 'http', 'https' ) );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( '' === $url || ! is_string( $scheme ) || strlen( $url ) > self::MAX_URL_CHARS || isset( $entries[ $url ] ) || null !== wp_parse_url( $url, PHP_URL_QUERY ) ) {
			return;
		}

		$entries[ $url ] = array(
			'url'   => $url,
			'title' => self::plain_title( $title ),
			'type'  => $type,
		);
	}

	/**
	 * A title as plain text: tags stripped, entities decoded, whitespace
	 * collapsed, at most MAX_TITLE_CHARS characters.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function plain_title( $title ) {
		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
		if ( ! wp_check_invalid_utf8( $title ) ) {
			return '';
		}
		return mb_substr( $title, 0, self::MAX_TITLE_CHARS, 'UTF-8' );
	}

	/**
	 * Splits entries into requests of at most PAGES_PER_REQUEST entries and
	 * MAX_PAGES_BYTES of JSON.
	 *
	 * @param array[] $entries Entries.
	 * @return array[] Lists of entries.
	 */
	private static function chunk( array $entries ) {
		$chunks  = array();
		$current = array();
		$bytes   = 0;

		foreach ( $entries as $entry ) {
			$encoded = wp_json_encode( $entry );
			if ( false === $encoded ) {
				continue;
			}
			$size = strlen( $encoded ) + 1;

			if ( ! empty( $current ) && ( count( $current ) >= self::PAGES_PER_REQUEST || $bytes + $size > self::MAX_PAGES_BYTES ) ) {
				$chunks[] = $current;
				$current  = array();
				$bytes    = 0;
			}
			$current[] = $entry;
			$bytes    += $size;
		}
		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * A short name for why a request was refused.
	 *
	 * @param int $code HTTP status, or 0 when PingAura could not be reached.
	 * @return string
	 */
	private static function describe_failure( $code ) {
		$names = array(
			0   => 'unreachable',
			401 => 'unauthorized',
			404 => 'unsupported',
			413 => 'too_large',
			422 => 'invalid',
			429 => 'rate_limited',
		);
		return isset( $names[ $code ] ) ? $names[ $code ] : 'failed';
	}

	/**
	 * Records the result of a sync. The time and count of the last accepted
	 * pages are kept when a later sync fails. Not autoloaded.
	 *
	 * @param string $result sent, or why the sync stopped.
	 * @param int    $code   HTTP status of the last request, 0 when unreachable.
	 * @param int[]  $totals received, added, updated and skipped counts.
	 */
	private static function save_status( $result, $code, array $totals ) {
		$previous = self::last_sent();
		$accepted = $totals['received'] > 0;

		update_option(
			self::STATUS_OPTION,
			array(
				'attempted_at' => time(),
				'result'       => $result,
				'code'         => (int) $code,
				'received'     => $totals['received'],
				'added'        => $totals['added'],
				'updated'      => $totals['updated'],
				'skipped'      => $totals['skipped'],
				'sent_at'      => $accepted ? time() : ( null !== $previous ? $previous['at'] : 0 ),
				'sent_pages'   => $accepted ? $totals['received'] : ( null !== $previous ? $previous['pages'] : 0 ),
			),
			false
		);
	}
}
