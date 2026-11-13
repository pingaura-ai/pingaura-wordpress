<?php
/**
 * Fixes from PingAura, applied through WordPress hooks.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies and undoes fixes proposed by PingAura.
 *
 * Nothing is written to theme files or to disk. Each applied fix is kept in an
 * option and served through a hook:
 *
 * - jsonld: a JSON-LD script in wp_head on the fix's target path.
 * - llms_txt: the body of /llms.txt, when no llms.txt file exists on disk.
 * - robots_txt: a block appended to the robots.txt WordPress generates. The
 *   site's own rules are kept.
 *
 * A fix takes a slot (its kind, plus the target path for JSON-LD). Applying a
 * fix to a taken slot keeps the earlier fix, so Undo puts it back.
 */
final class PingAura_AI_Fixes {

	const OPTION = 'pingaura_ai_fixes';

	const KINDS = array( 'jsonld', 'llms_txt', 'robots_txt' );

	const STATUSES = array( 'proposed', 'applied', 'reverted' );

	const MAX_CONTENT_BYTES = 100000;

	const MAX_JSONLD_BYTES = 32000;

	const MAX_ROBOTS_BYTES = 5000;

	const MAX_HISTORY = 5;

	/**
	 * Query parameters that only track a visit and do not change the page.
	 * Parameters starting with utm_ are ignored too.
	 */
	const IGNORED_QUERY_PARAMS = array( 'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'twclid', 'ttclid', 'li_fat_id', 'mc_cid', 'mc_eid', '_ga', '_gl', 'srsltid' );

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_jsonld' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_llms_txt' ), 1 );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 100, 2 );
	}

	/**
	 * Checks and normalises a fix received from PingAura.
	 *
	 * @param mixed $raw Fix from the API.
	 * @return array{id: string, kind: string, status: string, title: string, summary: string, content: string, target_path: string}|null
	 */
	public static function validate( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$id      = isset( $raw['id'] ) ? $raw['id'] : '';
		$kind    = isset( $raw['kind'] ) ? $raw['kind'] : '';
		$status  = isset( $raw['status'] ) ? $raw['status'] : '';
		$content = isset( $raw['content'] ) ? $raw['content'] : '';
		$target  = isset( $raw['target_path'] ) && is_string( $raw['target_path'] ) ? $raw['target_path'] : '/';

		if ( ! PingAura_AI_Api::is_valid_id( $id ) || ! in_array( $kind, self::KINDS, true ) || ! in_array( $status, self::STATUSES, true ) ) {
			return null;
		}
		if ( ! is_string( $content ) || '' === $content || strlen( $content ) > self::MAX_CONTENT_BYTES || ! wp_check_invalid_utf8( $content ) ) {
			return null;
		}
		if ( ! self::is_valid_path( $target ) || ! self::is_valid_content( $kind, $content ) ) {
			return null;
		}

		return array(
			'id'          => $id,
			'kind'        => $kind,
			'status'      => $status,
			'title'       => isset( $raw['title'] ) && is_string( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
			'summary'     => isset( $raw['summary'] ) && is_string( $raw['summary'] ) ? sanitize_textarea_field( $raw['summary'] ) : '',
			'content'     => str_replace( "\r\n", "\n", $content ),
			'target_path' => $target,
		);
	}

	/**
	 * Applied fixes, by slot.
	 *
	 * @return array<string, array>
	 */
	public static function applied() {
		$applied = get_option( self::OPTION, array() );
		return is_array( $applied ) ? $applied : array();
	}

	/**
	 * The applied entry for a fix ID, if it is live.
	 *
	 * @param string $fix_id Fix ID.
	 * @return array|null
	 */
	public static function find_applied( $fix_id ) {
		foreach ( self::applied() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) && $entry['id'] === $fix_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Reasons a fix cannot work on this site, if any.
	 *
	 * @param string $kind Fix kind.
	 * @return string Empty when the fix can be applied.
	 */
	public static function blocker( $kind ) {
		if ( 'llms_txt' === $kind && file_exists( self::home_dir() . 'llms.txt' ) ) {
			return __( 'Your site already has an llms.txt file on the server, and it takes precedence. Replace its contents with this preview by hand, or remove the file and apply the fix.', 'pingaura-telemetry' );
		}
		if ( 'robots_txt' === $kind && file_exists( self::home_dir() . 'robots.txt' ) ) {
			return __( 'Your site has a robots.txt file on the server, so WordPress does not generate one. Add these lines to that file by hand.', 'pingaura-telemetry' );
		}
		if ( in_array( $kind, array( 'llms_txt', 'robots_txt' ), true ) && '' === get_option( 'permalink_structure' ) ) {
			return __( 'Turn on pretty permalinks in Settings > Permalinks so WordPress can serve this file.', 'pingaura-telemetry' );
		}
		if ( 'robots_txt' === $kind && '0' === (string) get_option( 'blog_public' ) ) {
			return __( 'Search engines are discouraged from indexing this site (Settings, Reading), so robots.txt rules are not added.', 'pingaura-telemetry' );
		}
		return '';
	}

	/**
	 * Applies a validated fix.
	 *
	 * @param array $fix Fix from validate().
	 * @return array{applied: array, replaced: array|null}|WP_Error The new entry and the one it replaced.
	 */
	public static function apply( array $fix ) {
		$blocker = self::blocker( $fix['kind'] );
		if ( '' !== $blocker ) {
			return new WP_Error( 'pingaura_ai_blocked', $blocker );
		}

		$content = $fix['content'];
		if ( ! self::is_valid_content( $fix['kind'], $content ) ) {
			return new WP_Error( 'pingaura_ai_invalid', __( 'This fix is not in the expected format, so it was not applied.', 'pingaura-telemetry' ) );
		}

		$applied = self::applied();
		$slot    = self::slot( $fix['kind'], $fix['target_path'] );
		$current = isset( $applied[ $slot ] ) && is_array( $applied[ $slot ] ) ? $applied[ $slot ] : null;

		if ( null !== $current && $current['id'] === $fix['id'] && $current['content'] === $content ) {
			return new WP_Error( 'pingaura_ai_already', __( 'This fix is already applied.', 'pingaura-telemetry' ) );
		}

		$entry = array(
			'id'          => $fix['id'],
			'kind'        => $fix['kind'],
			'target_path' => $fix['target_path'],
			'title'       => $fix['title'],
			'content'     => $content,
			'sha256'      => hash( 'sha256', $content ),
			'applied_at'  => time(),
			'previous'    => self::trim_history( $current ),
		);

		$applied[ $slot ] = $entry;
		update_option( self::OPTION, $applied, false );

		return array(
			'applied'  => $entry,
			'replaced' => $current,
		);
	}

	/**
	 * Undoes an applied fix and puts back what its slot held before.
	 *
	 * @param string $fix_id Fix ID.
	 * @return array{restored: array|null}|WP_Error The entry that is live again, if any.
	 */
	public static function undo( $fix_id ) {
		$applied = self::applied();

		foreach ( $applied as $slot => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || $entry['id'] !== $fix_id ) {
				continue;
			}

			$previous = isset( $entry['previous'] ) && is_array( $entry['previous'] ) ? $entry['previous'] : null;
			if ( null === $previous ) {
				unset( $applied[ $slot ] );
			} else {
				$applied[ $slot ] = $previous;
			}

			if ( empty( $applied ) ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $applied, false );
			}

			return array( 'restored' => $previous );
		}

		return new WP_Error( 'pingaura_ai_not_applied', __( 'This fix is not applied.', 'pingaura-telemetry' ) );
	}

	/**
	 * Prints applied JSON-LD for the current path. The stored text is decoded
	 * and encoded again, so it can never close the script tag.
	 *
	 * Nothing is printed when the address has a query string other than
	 * tracking parameters, because ?p=5 or ?page_id=2 can show a different page
	 * on the same path.
	 */
	public static function print_jsonld() {
		$path = self::request_path();
		if ( null === $path || self::request_has_query() ) {
			return;
		}

		foreach ( self::applied() as $entry ) {
			if ( ! is_array( $entry ) || 'jsonld' !== $entry['kind'] || self::site_path( $entry['target_path'] ) !== $path ) {
				continue;
			}

			$decoded = json_decode( $entry['content'] );
			$json    = is_object( $decoded ) ? wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) : false;
			if ( false === $json ) {
				continue;
			}

			wp_print_inline_script_tag(
				$json,
				array(
					'type'  => 'application/ld+json',
					'class' => 'pingaura-ai-jsonld',
				)
			);
		}
	}

	/**
	 * Serves /llms.txt when an llms.txt fix is applied and nothing else
	 * answered the request.
	 */
	public static function serve_llms_txt() {
		if ( ! is_404() ) {
			return;
		}

		$path = self::request_path();
		if ( null === $path || self::site_path( '/llms.txt' ) !== $path ) {
			return;
		}

		$applied = self::applied();
		$entry   = isset( $applied['llms_txt'] ) && is_array( $applied['llms_txt'] ) ? $applied['llms_txt'] : null;
		if ( null === $entry ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $entry['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text response, served with a text/plain content type and nosniff.
		exit;
	}

	/**
	 * Appends the applied robots.txt block. The site's own rules come first and
	 * are never changed. Nothing is added while the site discourages indexing.
	 *
	 * @param string $output    robots.txt generated so far.
	 * @param string $is_public The blog_public option.
	 * @return string
	 */
	public static function filter_robots_txt( $output, $is_public ) {
		$applied = self::applied();
		$entry   = isset( $applied['robots_txt'] ) && is_array( $applied['robots_txt'] ) ? $applied['robots_txt'] : null;

		if ( null === $entry || '0' === (string) $is_public || ! self::is_valid_robots_block( $entry['content'] ) ) {
			return $output;
		}

		return rtrim( (string) $output ) . "\n\n# PingAura AI\n" . trim( $entry['content'] ) . "\n";
	}

	/**
	 * Whether content suits its kind: JSON-LD must decode to a JSON object and
	 * a robots.txt block may hold only User-agent and Allow rules and comments.
	 *
	 * @param string $kind    Fix kind.
	 * @param string $content Content.
	 * @return bool
	 */
	private static function is_valid_content( $kind, $content ) {
		if ( 'jsonld' === $kind ) {
			return strlen( $content ) <= self::MAX_JSONLD_BYTES && is_object( json_decode( $content ) );
		}
		if ( 'robots_txt' === $kind ) {
			return self::is_valid_robots_block( $content );
		}
		return true;
	}

	/**
	 * Whether every line is a User-agent or Allow rule, a comment or blank.
	 * PingAura only sends rules that let crawlers in, so Disallow and other
	 * directives are refused.
	 *
	 * @param string $content Rules block.
	 * @return bool
	 */
	private static function is_valid_robots_block( $content ) {
		if ( ! is_string( $content ) || strlen( $content ) > self::MAX_ROBOTS_BYTES ) {
			return false;
		}
		foreach ( explode( "\n", str_replace( "\r\n", "\n", $content ) ) as $line ) {
			if ( 1 !== preg_match( '/^\s*(#[^\x00-\x08\x0B-\x1F\x7F]*|(user-agent|allow)\s*:[^\x00-\x1F\x7F]*)?\s*$/i', $line ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The slot a fix occupies.
	 *
	 * @param string $kind        Fix kind.
	 * @param string $target_path Target path.
	 * @return string
	 */
	private static function slot( $kind, $target_path ) {
		return 'jsonld' === $kind ? 'jsonld:' . self::normalize_path( $target_path ) : $kind;
	}

	/**
	 * Drops the oldest history so the option stays small.
	 *
	 * @param array|null $entry Entry with its chain of previous entries.
	 * @param int        $depth Depth already kept.
	 * @return array|null
	 */
	private static function trim_history( $entry, $depth = 1 ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$entry['previous'] = $depth >= self::MAX_HISTORY ? null : self::trim_history( isset( $entry['previous'] ) ? $entry['previous'] : null, $depth + 1 );
		return $entry;
	}

	/**
	 * Whether a target path is a plain absolute path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_valid_path( $path ) {
		return strlen( $path ) <= 2048 && 1 === preg_match( '#^/(?!/)[^\s?\#\\\\]*$#', $path );
	}

	/**
	 * The full request path for a path relative to the site's home URL.
	 *
	 * @param string $path Path such as /about/.
	 * @return string
	 */
	private static function site_path( $path ) {
		return self::normalize_path( (string) wp_parse_url( home_url( $path ), PHP_URL_PATH ) );
	}

	/**
	 * The current request path, normalised, or null outside a front-end request.
	 *
	 * @return string|null
	 */
	private static function request_path() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}
		$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return is_string( $path ) ? self::normalize_path( $path ) : null;
	}

	/**
	 * Whether the current request has query parameters other than tracking ones.
	 *
	 * @return bool
	 */
	private static function request_has_query() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$query = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '=', $pair, 2 );
			$name  = strtolower( rawurldecode( $parts[0] ) );
			if ( 0 === strpos( $name, 'utm_' ) || in_array( $name, self::IGNORED_QUERY_PARAMS, true ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Decodes percent escapes and removes a trailing slash, except from the root.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$path = rawurldecode( $path );
		$path = '/' === $path || '' === $path ? '/' : untrailingslashit( $path );
		return '' === $path ? '/' : $path;
	}

	/**
	 * The directory that holds the site's root files, with a trailing slash.
	 *
	 * @return string
	 */
	private static function home_dir() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() );
	}
}
