<?php
/**
 * Turns a form submission into a PingAura lead.
 *
 * Plain PHP with no WordPress calls, so it can be tested on its own.
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps form fields, normalises phone numbers and classifies where a visitor
 * came from.
 */
final class PingAura_Telemetry_Lead_Mapper {

	const MAX_TEXT = 5000;

	const MAX_KEY = 40;

	const MAX_CUSTOM_FIELDS = 20;

	const DEFAULT_COUNTRY_CODE = '+91';

	/**
	 * Field types that are never sent.
	 */
	const SKIPPED_TYPES = array( 'hidden', 'honeypot', 'recaptcha', 'recaptcha_v3', 'step', 'acceptance', 'html', 'password', 'upload' );

	/**
	 * Field types sent as custom fields.
	 */
	const CUSTOM_TYPES = array( 'text', 'select', 'radio', 'checkbox', 'number', 'date', 'textarea' );

	/**
	 * Field ids and labels, as slugs, that hold the visitor's name.
	 */
	const NAME_SLUGS = array( 'name', 'full_name', 'fullname', 'your_name', 'yourname' );

	/**
	 * Field ids and labels, as slugs, of text fields that may hold a phone
	 * number. Used only when no phone field gave one.
	 */
	const PHONE_SLUGS = array( 'phone', 'mobile', 'whatsapp', 'contact_number', 'contact', 'phone_number' );

	/**
	 * Referrer domains of AI assistants. A host matches a domain or any of its
	 * subdomains. Same order and values as PingAura's classifier.
	 */
	const AI_HOSTS = array(
		array( array( 'chatgpt.com', 'openai.com' ), 'chatgpt' ),
		array( array( 'claude.ai', 'anthropic.com' ), 'claude' ),
		array( array( 'perplexity.ai' ), 'perplexity' ),
		array( array( 'gemini.google.com', 'bard.google.com' ), 'gemini' ),
		array( array( 'copilot.microsoft.com' ), 'copilot' ),
		array( array( 'deepseek.com' ), 'deepseek' ),
		array( array( 'grok.com', 'x.ai' ), 'grok' ),
	);

	/**
	 * Query parameter values that name an AI assistant: parameter, lowercase
	 * value, source. Same order and values as PingAura's classifier.
	 */
	const AI_PARAMS = array(
		array( 'ct-referrer', 'perplexity', 'perplexity' ),
		array( 'ref', 'chatgpt.com', 'chatgpt' ),
		array( 'ref', 'chatgpt', 'chatgpt' ),
		array( 'utm_source', 'perplexity', 'perplexity' ),
		array( 'utm_source', 'chatgpt.com', 'chatgpt' ),
		array( 'utm_source', 'chatgpt', 'chatgpt' ),
		array( 'utm_source', 'openai', 'chatgpt' ),
		array( 'utm_source', 'gemini', 'gemini' ),
		array( 'utm_source', 'claude', 'claude' ),
		array( 'utm_source', 'anthropic', 'claude' ),
		array( 'utm_source', 'copilot', 'copilot' ),
		array( 'utm_source', 'deepseek', 'deepseek' ),
		array( 'utm_source', 'grok', 'grok' ),
	);

	const SEARCH_HOSTS = array( 'search.yahoo.com', 'com.google.android.googlequicksearchbox', 'bing.com', 'duckduckgo.com', 'ecosia.org', 'baidu.com', 'yandex.ru', 'yandex.com', 'search.brave.com' );

	const SEARCH_PREFIXES = array( 'google.', 'yahoo.' );

	const EMAIL_HOSTS = array( 'mail.google.com', 'com.google.android.gm', 'mail.yahoo.com', 'outlook.live.com', 'outlook.office.com' );

	const SOCIAL_HOSTS = array( 'linkedin.com', 'lnkd.in', 'x.com', 't.co', 'twitter.com', 'facebook.com', 'fb.com', 'instagram.com', 'reddit.com', 'youtube.com', 'quora.com', 'medium.com', 'threads.net' );

	const EMAIL_MEDIUMS = array( 'email', 'e-mail', 'newsletter' );

	const PAID_MEDIUMS = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_social', 'display' );

	/**
	 * Builds the lead fields from a list of form fields.
	 *
	 * Fields are matched by type, not by id, because ids are often left at
	 * their defaults or mislabelled. A text field named like a phone number
	 * is used as the phone only when no phone field gave one.
	 *
	 * consent_state is granted when the form has a ticked acceptance field,
	 * refused when it has an unticked one, and unknown when it has none.
	 *
	 * @param array  $fields       Form fields, each with type, id, title and value.
	 * @param string $country_code Country code for numbers entered without one.
	 * @return array{name?: string, email?: string, phone?: string, message?: string, custom_fields: array<string, string>, consent_state: string}
	 */
	public static function map_fields( array $fields, $country_code ) {
		$lead = array(
			'custom_fields' => array(),
			'consent_state' => 'unknown',
		);

		$tel_phone = self::first_tel_phone( $fields, $country_code );

		foreach ( $fields as $field_key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type  = isset( $field['type'] ) && is_string( $field['type'] ) ? strtolower( $field['type'] ) : 'text';
			$id    = isset( $field['id'] ) && is_scalar( $field['id'] ) ? (string) $field['id'] : (string) $field_key;
			$label = isset( $field['title'] ) && is_scalar( $field['title'] ) ? (string) $field['title'] : '';
			$value = self::field_value( $field );

			if ( 'acceptance' === $type ) {
				if ( '' !== $value ) {
					$lead['consent_state'] = 'granted';
				} elseif ( 'granted' !== $lead['consent_state'] ) {
					$lead['consent_state'] = 'refused';
				}
				continue;
			}
			if ( '' === $value || in_array( $type, self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			if ( 'email' === $type ) {
				if ( ! isset( $lead['email'] ) && self::is_email( $value ) ) {
					$lead['email'] = strtolower( $value );
				}
				continue;
			}

			if ( 'tel' === $type ) {
				if ( ! isset( $lead['phone'] ) && '' !== $tel_phone ) {
					$lead['phone'] = $tel_phone;
				}
				continue;
			}

			if ( 'text' === $type && '' === $tel_phone && ! isset( $lead['phone'] ) && self::slug_in( $id, $label, self::PHONE_SLUGS ) ) {
				$phone = self::normalize_phone( $value, $country_code );
				if ( '' !== $phone ) {
					$lead['phone'] = $phone;
					continue;
				}
			}

			if ( 'textarea' === $type && ! isset( $lead['message'] ) ) {
				$lead['message'] = self::truncate( $value, self::MAX_TEXT );
				continue;
			}

			if ( 'text' === $type && ! isset( $lead['name'] ) && self::slug_in( $id, $label, self::NAME_SLUGS ) ) {
				$lead['name'] = self::truncate( $value, self::MAX_TEXT );
				continue;
			}

			if ( ! in_array( $type, self::CUSTOM_TYPES, true ) || count( $lead['custom_fields'] ) >= self::MAX_CUSTOM_FIELDS ) {
				continue;
			}

			$key = self::slug( $id, self::MAX_KEY );
			if ( '' === $key ) {
				$key = self::slug( $label, self::MAX_KEY );
			}
			if ( '' === $key || isset( $lead['custom_fields'][ $key ] ) ) {
				continue;
			}

			$lead['custom_fields'][ $key ] = self::truncate( $value, self::MAX_TEXT );
		}

		return $lead;
	}

	/**
	 * Turns a phone number into E.164 (+919876543210), or an empty string when
	 * it cannot be read as one.
	 *
	 * @param string $raw          Number as typed.
	 * @param string $country_code Country code such as +91, used for a bare
	 *                             10-digit number or one with a leading 0.
	 *                             A 0 typed right after it is dropped.
	 * @return string
	 */
	public static function normalize_phone( $raw, $country_code ) {
		$phone = preg_replace( '/[\s\-.()\[\]]/u', '', trim( (string) $raw ) );
		$cc    = self::normalize_country_code( $country_code );
		$cc    = '' !== $cc ? $cc : self::DEFAULT_COUNTRY_CODE;

		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}

		if ( 1 === preg_match( '/^\d{10}$/', $phone ) ) {
			$phone = $cc . $phone;
		} elseif ( 1 === preg_match( '/^0(\d{10})$/', $phone, $match ) ) {
			$phone = $cc . $match[1];
		} elseif ( 1 === preg_match( '/^\d+$/', $phone ) && strlen( $phone ) === strlen( $cc ) - 1 + 10 && 0 === strpos( $phone, substr( $cc, 1 ) ) ) {
			// The country code typed without its plus sign, such as 919876543210.
			$phone = '+' . $phone;
		}

		// A trunk 0 kept after the country code, such as +91 (0) 98765 43210.
		if ( 0 === strpos( $phone, $cc . '0' ) ) {
			$phone = $cc . substr( $phone, strlen( $cc ) + 1 );
		}

		return 1 === preg_match( '/^\+[1-9]\d{6,14}$/', $phone ) ? $phone : '';
	}

	/**
	 * A country code such as +91, or an empty string when the value is not one.
	 *
	 * @param string $value Country code, with or without the plus sign.
	 * @return string
	 */
	public static function normalize_country_code( $value ) {
		$value = preg_replace( '/[\s\-]/', '', trim( (string) $value ) );
		if ( '' !== $value && '+' !== $value[0] ) {
			$value = '+' . $value;
		}
		return 1 === preg_match( '/^\+[1-9]\d{0,3}$/', $value ) ? $value : '';
	}

	/**
	 * A key made of lowercase letters, digits and underscores that starts with
	 * a letter, or an empty string.
	 *
	 * @param string $value Text.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	public static function slug( $value, $max ) {
		$slug = strtolower( (string) $value );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '_', $slug );
		$slug = (string) preg_replace( '/^[^a-z]+/', '', $slug );
		$slug = substr( $slug, 0, (int) $max );
		return rtrim( $slug, '_' );
	}

	/**
	 * Reads the source stored in the pa_src cookie: the referring host and the
	 * utm_source, utm_medium, ref and ct-referrer values. Anything malformed is
	 * left out.
	 *
	 * @param string $cookie Cookie value, already URL-decoded by PHP.
	 * @return array{host: string, params: array<string, string>}
	 */
	public static function parse_source_cookie( $cookie ) {
		$result = array(
			'host'   => '',
			'params' => array(),
		);

		$cookie = (string) $cookie;
		if ( '' === $cookie || strlen( $cookie ) > 400 ) {
			return $result;
		}

		$names = array(
			's' => 'utm_source',
			'm' => 'utm_medium',
			'r' => 'ref',
			'c' => 'ct-referrer',
		);

		foreach ( explode( '&', $cookie ) as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$value = strtolower( rawurldecode( $parts[1] ) );

			if ( 'h' === $parts[0] ) {
				if ( 1 === preg_match( '/^[a-z0-9.-]{1,253}$/', $value ) && false !== strpos( $value, '.' ) ) {
					$result['host'] = $value;
				}
			} elseif ( isset( $names[ $parts[0] ] ) && 1 === preg_match( '/^[a-z0-9._-]{1,100}$/', $value ) ) {
				$result['params'][ $names[ $parts[0] ] ] = $value;
			}
		}

		return $result;
	}

	/**
	 * The AI assistant a visitor came from, or 'none'.
	 *
	 * @param string $host   Referring host.
	 * @param array  $params Query parameter values, by name.
	 * @return string
	 */
	public static function classify_ai_source( $host, array $params ) {
		$host = strtolower( (string) $host );

		if ( '' !== $host ) {
			foreach ( self::AI_HOSTS as $rule ) {
				if ( self::host_matches( $host, $rule[0] ) ) {
					return $rule[1];
				}
			}
		}

		foreach ( self::AI_PARAMS as $rule ) {
			if ( isset( $params[ $rule[0] ] ) && strtolower( (string) $params[ $rule[0] ] ) === $rule[1] ) {
				return $rule[2];
			}
		}

		return 'none';
	}

	/**
	 * Where a visitor who did not come from an AI assistant came from, for the
	 * clear cases only: search engines, social sites, email and paid campaigns.
	 *
	 * @param string $host     Referring host.
	 * @param array  $params   Query parameter values, by name.
	 * @param string $own_host This site's host, which is never a source.
	 * @return array{kind: string, name: string}|null
	 */
	public static function classify_source( $host, array $params, $own_host ) {
		if ( 'none' !== self::classify_ai_source( $host, $params ) ) {
			return null;
		}

		$host     = self::bare_host( strtolower( (string) $host ) );
		$own_host = self::bare_host( strtolower( (string) $own_host ) );
		$medium   = isset( $params['utm_medium'] ) ? strtolower( (string) $params['utm_medium'] ) : '';
		$source   = isset( $params['utm_source'] ) ? strtolower( (string) $params['utm_source'] ) : '';
		$campaign = 1 === preg_match( '/^[a-z0-9._-]{1,100}$/', $source ) ? $source : '';
		$valid    = 1 === preg_match( '/^[a-z0-9.-]{1,253}$/', $host ) && false !== strpos( $host, '.' ) && $host !== $own_host;

		if ( in_array( $medium, self::EMAIL_MEDIUMS, true ) ) {
			return self::source( 'email', '' !== $campaign ? $campaign : 'email' );
		}
		if ( in_array( $medium, self::PAID_MEDIUMS, true ) ) {
			return self::source( 'paid', '' !== $campaign ? $campaign : ( $valid ? $host : 'paid' ) );
		}
		if ( ! $valid ) {
			return null;
		}
		if ( self::host_matches( $host, self::EMAIL_HOSTS ) ) {
			return self::source( 'email', $host );
		}
		if ( self::host_matches( $host, self::SEARCH_HOSTS ) ) {
			return self::source( 'search', $host );
		}
		foreach ( self::SEARCH_PREFIXES as $prefix ) {
			if ( 0 === strpos( $host, $prefix ) ) {
				return self::source( 'search', $host );
			}
		}
		if ( self::host_matches( $host, self::SOCIAL_HOSTS ) ) {
			return self::source( 'social', $host );
		}

		return null;
	}

	/**
	 * Whether a value looks like a PingAura API key: pa_<prefix>_<secret>.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function is_valid_api_key( $key ) {
		return is_string( $key ) && strlen( $key ) <= 200 && 1 === preg_match( '/^pa_[A-Za-z0-9_-]{8,}$/', $key );
	}

	/**
	 * Whether a value is a UUID.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * A field's submitted value as one line of text.
	 *
	 * @param array $field Form field.
	 * @return string
	 */
	private static function field_value( array $field ) {
		$value = isset( $field['value'] ) ? $field['value'] : '';
		if ( ( '' === $value || null === $value ) && isset( $field['raw_value'] ) ) {
			$value = $field['raw_value'];
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Whether a field's id or label, as a slug, is one of the given slugs.
	 *
	 * @param string   $id    Field id.
	 * @param string   $label Field label.
	 * @param string[] $slugs Slugs.
	 * @return bool
	 */
	private static function slug_in( $id, $label, array $slugs ) {
		return in_array( self::slug( $id, self::MAX_KEY ), $slugs, true )
			|| in_array( self::slug( $label, self::MAX_KEY ), $slugs, true );
	}

	/**
	 * The first phone field value that reads as a phone number, in E.164, or
	 * an empty string.
	 *
	 * @param array  $fields       Form fields.
	 * @param string $country_code Default country code.
	 * @return string
	 */
	private static function first_tel_phone( array $fields, $country_code ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['type'] ) || ! is_string( $field['type'] ) || 'tel' !== strtolower( $field['type'] ) ) {
				continue;
			}
			$phone = self::normalize_phone( self::field_value( $field ), $country_code );
			if ( '' !== $phone ) {
				return $phone;
			}
		}
		return '';
	}

	/**
	 * Whether a value is an email address.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_email( $value ) {
		return strlen( $value ) <= 320 && false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Whether a host is one of the domains or a subdomain of one.
	 *
	 * @param string   $host    Host.
	 * @param string[] $domains Domains.
	 * @return bool
	 */
	private static function host_matches( $host, array $domains ) {
		foreach ( $domains as $domain ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A host without a www. prefix.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare_host( $host ) {
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * A source result.
	 *
	 * @param string $kind Kind.
	 * @param string $name Name.
	 * @return array{kind: string, name: string}
	 */
	private static function source( $kind, $name ) {
		return array(
			'kind' => $kind,
			'name' => substr( $name, 0, 100 ),
		);
	}

	/**
	 * Cuts text to a number of characters.
	 *
	 * @param string $value  Text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function truncate( $value, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
