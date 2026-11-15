<?php
/**
 * Form leads and WhatsApp link text.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends Elementor Pro form submissions to PingAura Leads, and adds the page
 * to WhatsApp chat links that have no message. Both are off by default.
 *
 * A submission is sent after the response to the visitor has been sent, so
 * the form is never slowed down or broken by PingAura. Where the server can
 * finish the response early (PHP-FPM or LiteSpeed), the request waits up to
 * five seconds for PingAura's answer and records its HTTP status. Elsewhere
 * it is sent without waiting for an answer.
 */
final class PingAura_Telemetry_Leads {

	const OPTION = 'pingaura_telemetry_leads';

	const STATUS_OPTION = 'pingaura_telemetry_leads_status';

	const SOURCE_COOKIE = 'pa_src';

	const SCRIPT_ID = 'pingaura-telemetry-leads';

	const API_BASE = 'https://www.pingaura.ai/api/v1/domains/';

	const BLOCKING_TIMEOUT = 5;

	const NON_BLOCKING_TIMEOUT = 3;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'handle_elementor_record' ), 10, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'print_script' ), 99 );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'exclude_script' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( __CLASS__, 'exclude_script' ) );
	}

	/**
	 * Settings merged with their defaults.
	 *
	 * @return array{enabled: bool, api_key: string, domain_id: string, country_code: string, whatsapp_text: bool}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'       => false,
				'api_key'       => '',
				'domain_id'     => '',
				'country_code'  => PingAura_Telemetry_Lead_Mapper::DEFAULT_COUNTRY_CODE,
				'whatsapp_text' => false,
			)
		);
	}

	/**
	 * Validates submitted values and stores them.
	 *
	 * A blank or malformed API key keeps the stored one, as for the ingest key.
	 *
	 * @param array $input Raw form values.
	 */
	public static function save( array $input ) {
		$current = self::get();
		$key     = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		$domain  = isset( $input['domain_id'] ) ? strtolower( trim( sanitize_text_field( $input['domain_id'] ) ) ) : '';
		$country = isset( $input['country_code'] ) ? PingAura_Telemetry_Lead_Mapper::normalize_country_code( sanitize_text_field( $input['country_code'] ) ) : '';

		if ( ! empty( $input['clear_api_key'] ) ) {
			$key = '';
		} elseif ( ! PingAura_Telemetry_Lead_Mapper::is_valid_api_key( $key ) ) {
			$key = $current['api_key'];
		}

		if ( '' !== $domain && ! PingAura_Telemetry_Lead_Mapper::is_uuid( $domain ) ) {
			$domain = $current['domain_id'];
		}

		update_option(
			self::OPTION,
			array(
				'enabled'       => ! empty( $input['enabled'] ),
				'api_key'       => $key,
				'domain_id'     => $domain,
				'country_code'  => '' !== $country ? $country : PingAura_Telemetry_Lead_Mapper::DEFAULT_COUNTRY_CODE,
				'whatsapp_text' => ! empty( $input['whatsapp_text'] ),
			),
			true
		);
	}

	/**
	 * Whether a valid API key is saved.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return PingAura_Telemetry_Lead_Mapper::is_valid_api_key( self::get()['api_key'] );
	}

	/**
	 * Whether form submissions are sent: turned on, with a key and a domain ID.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$settings = self::get();

		return $settings['enabled']
			&& PingAura_Telemetry_Lead_Mapper::is_valid_api_key( $settings['api_key'] )
			&& PingAura_Telemetry_Lead_Mapper::is_uuid( $settings['domain_id'] );
	}

	/**
	 * Sends an Elementor Pro form submission to PingAura Leads.
	 *
	 * Runs on elementor_pro/forms/new_record. Never throws, so Elementor's own
	 * actions, such as its email, are unaffected.
	 *
	 * @param object $record  Elementor Pro form record.
	 * @param object $handler Elementor Pro AJAX handler. Unused.
	 */
	public static function handle_elementor_record( $record, $handler = null ) {
		unset( $handler );

		if ( ! self::is_active() || ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		try {
			$fields = $record->get( 'fields' );
			if ( ! is_array( $fields ) ) {
				return;
			}

			$form_id   = '';
			$form_name = '';
			if ( method_exists( $record, 'get_form_settings' ) ) {
				$form_id   = (string) $record->get_form_settings( 'id' );
				$form_name = (string) $record->get_form_settings( 'form_name' );
			}

			$payload = self::build_payload( $fields, $form_id, $form_name );
			if ( null !== $payload ) {
				self::send_after_response( $payload );
			}
		} catch ( Throwable $e ) {
			self::debug_log( 'lead not sent: ' . get_class( $e ) );
		}
	}

	/**
	 * Builds the request body for a submission, or null when it has neither an
	 * email address nor a phone number.
	 *
	 * @param array  $fields    Form fields.
	 * @param string $form_id   Form id.
	 * @param string $form_name Form name.
	 * @return array|null
	 */
	public static function build_payload( array $fields, $form_id, $form_name ) {
		$settings = self::get();
		$lead     = PingAura_Telemetry_Lead_Mapper::map_fields( $fields, $settings['country_code'] );

		if ( ! isset( $lead['email'] ) && ! isset( $lead['phone'] ) ) {
			return null;
		}

		$form_key = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $form_id ), 0, 40 );
		$form     = substr( sanitize_title( $form_name ), 0, 64 );
		if ( '' === $form || 1 === preg_match( '/^\+?[\d\s().-]{7,}$/', $form ) ) {
			$form = 'elementor' . ( '' !== $form ? '-' . $form : '' );
			$form = substr( $form, 0, 64 );
		}

		$body = array();
		foreach ( array( 'name', 'email', 'phone', 'message' ) as $field ) {
			if ( isset( $lead[ $field ] ) ) {
				$body[ $field ] = $lead[ $field ];
			}
		}

		$body['consent_state'] = $lead['consent'] ? 'granted' : 'not_required';
		$body['external_id']   = 'elementor-' . ( '' !== $form_key ? $form_key : 'form' ) . '-' . wp_generate_uuid4();
		$body['source_meta']   = array(
			'form' => $form,
			'path' => self::page_path(),
		);

		if ( $lead['custom_fields'] ) {
			$body['custom_fields'] = $lead['custom_fields'];
		}

		$source            = self::visitor_source();
		$own_host          = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$body['ai_source'] = PingAura_Telemetry_Lead_Mapper::classify_ai_source( $source['host'], $source['params'] );

		$other = 'none' === $body['ai_source']
			? PingAura_Telemetry_Lead_Mapper::classify_source( $source['host'], $source['params'], $own_host )
			: null;
		if ( null !== $other ) {
			$body['source_kind'] = $other['kind'];
			$body['source_name'] = $other['name'];
		}

		return $body;
	}

	/**
	 * Prints the front-end script: the visit source cookie for form leads,
	 * and the WhatsApp link text. Prints nothing when neither is on.
	 */
	public static function print_script() {
		$settings = self::get();

		/** This filter is documented in includes/class-pingaura-telemetry-tracker.php */
		$source = self::is_active() && self::elementor_pro_active() && apply_filters( 'pingaura_telemetry_should_track', true, 'source' );
		$wa     = (bool) $settings['whatsapp_text'];

		if ( ! $source && ! $wa ) {
			return;
		}

		$config = wp_json_encode(
			array(
				'src'    => (bool) $source,
				'wa'     => $wa,
				'host'   => PingAura_Telemetry_Settings::normalize_domain( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				'cookie' => self::SOURCE_COOKIE,
				'prefix' => __( 'Hi, I\'d like to know more about', 'pingaura-telemetry' ),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
		);

		$script = '(function(c){try{'
			. 'if(c.src){'
			. "var cl=function(v){return String(v||'').toLowerCase().replace(/[^a-z0-9._-]/g,'_').slice(0,60);};"
			. "var h='';try{if(document.referrer){h=new URL(document.referrer).hostname.toLowerCase().replace(/^www\\./,'');}}catch(e){}"
			. "if(h===c.host){h='';}"
			. 'var q=new URLSearchParams(location.search);'
			. "var v={h:h.replace(/[^a-z0-9.-]/g,'').slice(0,100),s:cl(q.get('utm_source')),m:cl(q.get('utm_medium')),r:cl(q.get('ref')),c:cl(q.get('ct-referrer'))};"
			. 'var p=[];for(var k in v){if(v[k]){p.push(k+\'=\'+v[k]);}}'
			. "var has=new RegExp('(?:^|;\\\\s*)'+c.cookie+'=').test(document.cookie);"
			. 'if(p.length||!has){'
			. "document.cookie=c.cookie+'='+encodeURIComponent(p.join('&').slice(0,200))+';path=/;max-age=1800;samesite=lax'+(location.protocol==='https:'?';secure':'');"
			. '}'
			. '}'
			. 'if(c.wa){'
			. "document.addEventListener('click',function(e){try{"
			. "var a=e.target&&e.target.closest?e.target.closest('a[href]'):null;"
			. "if(!a||a.closest('.joinchat'))return;"
			. "var href=a.getAttribute('href')||'';"
			. 'if(!/api\\.whatsapp\\.com\\/send|wa\\.me\\//i.test(href))return;'
			. 'var u=new URL(a.href,location.href);'
			. "if(!(u.hostname==='wa.me'||u.hostname==='api.whatsapp.com'))return;"
			. "if(u.searchParams.get('text'))return;"
			. "u.searchParams.delete('text');u.hash='';"
			. "var t=c.prefix+' '+document.title+' '+location.origin+location.pathname;"
			. "a.href=u.toString()+(u.search?'&':'?')+'text='+encodeURIComponent(t);"
			. '}catch(err){}},true);'
			. '}'
			. '}catch(e){}})(' . $config . ');';

		wp_print_inline_script_tag(
			$script,
			array(
				'id'                      => self::SCRIPT_ID,
				'data-no-optimize'        => '1',
				'data-no-defer'           => '1',
				'data-cfasync'            => 'false',
				'data-pagespeed-no-defer' => true,
			)
		);
	}

	/**
	 * Keeps optimisation plugins from delaying the script until the visitor
	 * interacts with the page, so the landing source is stored on arrival.
	 *
	 * @param mixed $excluded Excluded script patterns.
	 * @return mixed
	 */
	public static function exclude_script( $excluded ) {
		if ( ! is_array( $excluded ) ) {
			return $excluded;
		}
		$excluded[] = self::SCRIPT_ID;
		return $excluded;
	}

	/**
	 * The last answer from the leads endpoint, for the settings page.
	 *
	 * @return array{status: string, at: string}|null
	 */
	public static function last_status() {
		$last = get_option( self::STATUS_OPTION );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Whether Elementor Pro is active.
	 *
	 * @return bool
	 */
	private static function elementor_pro_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Plugin', false );
	}

	/**
	 * The source stored for this visit by the front-end script.
	 *
	 * @return array{host: string, params: array<string, string>}
	 */
	private static function visitor_source() {
		$cookie = isset( $_COOKIE[ self::SOURCE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::SOURCE_COOKIE ] ) ) : '';
		return PingAura_Telemetry_Lead_Mapper::parse_source_cookie( $cookie );
	}

	/**
	 * The path of the page the form was submitted from, without its query
	 * string, or / when it is not known.
	 *
	 * @return string
	 */
	private static function page_path() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only; Elementor Pro has already validated the submission.
		$url = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		if ( '' === $url ) {
			$url = (string) wp_get_raw_referer();
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = (string) preg_replace( '#[^A-Za-z0-9/._~%-]#', '', $path );

		return '' !== $path ? substr( $path, 0, 200 ) : '/';
	}

	/**
	 * Sends a lead once the response to the visitor has been sent.
	 *
	 * @param array $payload Request body.
	 */
	private static function send_after_response( array $payload ) {
		$settings = self::get();
		$body     = wp_json_encode( $payload );
		if ( false === $body ) {
			return;
		}

		$url  = self::API_BASE . rawurlencode( $settings['domain_id'] ) . '/leads';
		$args = array(
			'redirection' => 0,
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $settings['api_key'],
			),
			'user-agent'  => 'pingaura-wordpress/' . PINGAURA_TELEMETRY_VERSION,
			'body'        => $body,
		);

		add_action(
			'shutdown',
			static function () use ( $url, $args ) {
				static $sent = false;
				if ( $sent ) {
					return;
				}
				$sent = true;

				$finished = self::finish_response();

				$args['blocking'] = $finished;
				$args['timeout']  = $finished ? self::BLOCKING_TIMEOUT : self::NON_BLOCKING_TIMEOUT;

				$response = wp_remote_post( $url, $args );

				if ( ! $finished ) {
					return;
				}

				$status = is_wp_error( $response ) ? 'connection error' : (string) (int) wp_remote_retrieve_response_code( $response );
				self::record_status( $status );
				self::debug_log( 'lead sent, PingAura replied ' . $status );
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Flushes the response to the visitor where the server supports it.
	 *
	 * @return bool Whether the response was flushed.
	 */
	private static function finish_response() {
		// A second call, after another plugin has already flushed, returns false
		// but the response is flushed all the same.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return true;
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
			return true;
		}
		return false;
	}

	/**
	 * Stores the status only when it changes.
	 *
	 * @param string $status HTTP status code, or "connection error".
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
	 * Writes a line to the debug log when WP_DEBUG_LOG is on. Never pass
	 * submitted values.
	 *
	 * @param string $message Message.
	 */
	private static function debug_log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'PingAura AI: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Status codes only, and only with WP_DEBUG_LOG on.
		}
	}
}
