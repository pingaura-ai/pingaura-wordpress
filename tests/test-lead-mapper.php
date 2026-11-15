<?php
/**
 * Tests for PingAura_Telemetry_Lead_Mapper. Run with: php tests/test-lead-mapper.php
 *
 * @package PingAura_Telemetry
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/class-pingaura-telemetry-lead-mapper.php';

$failures = 0;
$count    = 0;

/**
 * Compares two values and prints the result.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function check( $name, $expected, $actual ) {
	global $failures, $count;
	++$count;
	if ( $expected === $actual ) {
		return;
	}
	++$failures;
	fwrite( STDERR, "FAIL: $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n" );
}

$m = 'PingAura_Telemetry_Lead_Mapper';

// Phone numbers: never given a country code by the plugin.
check( 'E.164 with spaces', '+919876543210', $m::normalize_phone( '+91 98765 43210' ) );
check( 'dashes, dots and brackets', '+14155550123', $m::normalize_phone( '+1 (415) 555-0123' ) );
check( 'dots', '+919876543210', $m::normalize_phone( '+91.98765.43210' ) );
check( '00 prefix', '+919876543210', $m::normalize_phone( '0091 98765 43210' ) );
check( '00 prefix, UK', '+447911123456', $m::normalize_phone( '0044 7911 123456' ) );
check( '(0) after code', '+919876543210', $m::normalize_phone( '+91 (0) 98765 43210' ) );
check( '(0) after code, UK', '+442079460958', $m::normalize_phone( '+44 (0)20 7946 0958' ) );
check( '(0) after 00 code', '+442079460958', $m::normalize_phone( '0044 (0) 20 7946 0958' ) );
check( 'other 0 after code is kept', '+9109876543210', $m::normalize_phone( '+91 09876543210' ) );
check( 'bare 10 digits is not a phone', '', $m::normalize_phone( '9876543210' ) );
check( 'trunk 0 without code is not a phone', '', $m::normalize_phone( '09876543210' ) );
check( 'code without plus is not a phone', '', $m::normalize_phone( '919876543210' ) );
check( 'too short', '', $m::normalize_phone( '+1234567' ) );
check( 'shortest', '+12345678', $m::normalize_phone( '+12345678' ) );
check( 'letters', '', $m::normalize_phone( 'call me' ) );
check( 'plus zero', '', $m::normalize_phone( '+0123456789' ) );
check( 'too long', '', $m::normalize_phone( '+1234567890123456' ) );

// Slugs.
check( 'slug label', 'company_name', $m::slug( 'Company Name', 40 ) );
check( 'slug leading digits', 'budget', $m::slug( '1. Budget?', 40 ) );
check( 'slug length', str_repeat( 'a', 40 ), $m::slug( str_repeat( 'a', 50 ), 40 ) );
check( 'slug trailing underscore after cut', 'abc', $m::slug( 'abc def', 4 ) );
check( 'slug empty', '', $m::slug( '123', 40 ) );

// Field mapping.
$fields = array(
	'field_1' => array(
		'id'    => 'field_1',
		'type'  => 'text',
		'title' => 'Your Name',
		'value' => 'Asha',
	),
	'email'   => array(
		'id'    => 'email',
		'type'  => 'tel',
		'title' => 'Phone',
		'value' => '+91 98765 43210',
	),
	'phone'   => array(
		'id'    => 'phone',
		'type'  => 'email',
		'title' => 'Email',
		'value' => 'Asha@Example.com',
	),
	'second'  => array(
		'id'    => 'second',
		'type'  => 'email',
		'title' => 'Other email',
		'value' => 'other@example.com',
	),
	'msg'     => array(
		'id'    => 'msg',
		'type'  => 'textarea',
		'title' => 'Message',
		'value' => 'Hello',
	),
	'city'    => array(
		'id'    => 'city',
		'type'  => 'select',
		'title' => 'City',
		'value' => 'Pune',
	),
	'f2'      => array(
		'id'    => '',
		'type'  => 'radio',
		'title' => 'Budget range',
		'value' => '10-20',
	),
	'hp'      => array(
		'id'    => 'hp',
		'type'  => 'honeypot',
		'title' => '',
		'value' => 'bot',
	),
	'hid'     => array(
		'id'    => 'hid',
		'type'  => 'hidden',
		'title' => '',
		'value' => 'x',
	),
	'accept'  => array(
		'id'    => 'accept',
		'type'  => 'acceptance',
		'title' => 'I agree',
		'value' => 'on',
	),
	'blank'   => array(
		'id'    => 'blank',
		'type'  => 'text',
		'title' => 'Blank',
		'value' => '',
	),
	'multi'   => array(
		'id'        => 'multi',
		'type'      => 'select',
		'title'     => 'Services',
		'value'     => '',
		'raw_value' => array( 'SEO', 'Ads' ),
	),
);

$lead = $m::map_fields( $fields );
check( 'name by label', 'Asha', isset( $lead['name'] ) ? $lead['name'] : null );
check( 'email by type, lowercased', 'asha@example.com', isset( $lead['email'] ) ? $lead['email'] : null );
check( 'phone by type', '+919876543210', isset( $lead['phone'] ) ? $lead['phone'] : null );
check( 'message', 'Hello', isset( $lead['message'] ) ? $lead['message'] : null );
check( 'consent ticked', 'granted', $lead['consent_state'] );
check(
	'custom fields',
	array(
		'city'         => 'Pune',
		'budget_range' => '10-20',
		'multi'        => 'SEO, Ads',
	),
	$lead['custom_fields']
);

$none = $m::map_fields(
	array(
		array(
			'id'    => 'name',
			'type'  => 'text',
			'title' => 'Name',
			'value' => 'X',
		),
		array(
			'id'    => 'mail',
			'type'  => 'email',
			'title' => 'Email',
			'value' => 'not-an-email',
		),
		array(
			'id'    => 'tel',
			'type'  => 'tel',
			'title' => 'Phone',
			'value' => '123',
		),
	)
);
check( 'invalid email dropped', false, isset( $none['email'] ) );
check( 'invalid phone dropped', false, isset( $none['phone'] ) );
check( 'no acceptance field', 'unknown', $none['consent_state'] );

$long = $m::map_fields(
	array(
		array(
			'id'    => 'notes',
			'type'  => 'text',
			'title' => 'Notes',
			'value' => str_repeat( 'a', 6000 ),
		),
	)
);
check( 'value truncated', 5000, strlen( $long['custom_fields']['notes'] ) );

$many = array();
for ( $i = 0; $i < 25; $i++ ) {
	$many[] = array(
		'id'    => 'q' . $i,
		'type'  => 'text',
		'title' => 'Q',
		'value' => 'v',
	);
}
check( 'custom fields capped', 20, count( $m::map_fields( $many )['custom_fields'] ) );

// Consent.
$unticked = $m::map_fields(
	array(
		array(
			'id'    => 'mail',
			'type'  => 'email',
			'title' => 'Email',
			'value' => 'a@example.com',
		),
		array(
			'id'    => 'ok',
			'type'  => 'acceptance',
			'title' => 'I agree',
			'value' => '',
		),
	)
);
check( 'acceptance unticked', 'refused', $unticked['consent_state'] );

// Phone in a text field.
$text_phone = $m::map_fields(
	array(
		array(
			'id'    => 'field_9',
			'type'  => 'text',
			'title' => 'Mobile',
			'value' => '+91 98765 43210',
		),
		array(
			'id'    => 'whatsapp',
			'type'  => 'text',
			'title' => 'Other',
			'value' => '91234 56789',
		),
	)
);
check( 'phone from text field, email-less form', '+919876543210', isset( $text_phone['phone'] ) ? $text_phone['phone'] : null );
check( 'second phone-like text field kept as custom', array( 'whatsapp' => '91234 56789' ), $text_phone['custom_fields'] );

$text_and_tel = $m::map_fields(
	array(
		array(
			'id'    => 'contact_number',
			'type'  => 'text',
			'title' => 'Contact number',
			'value' => '91234 56789',
		),
		array(
			'id'    => 'tel',
			'type'  => 'tel',
			'title' => 'Phone',
			'value' => '+91 9876543210',
		),
	)
);
check( 'tel field wins over text phone', '+919876543210', $text_and_tel['phone'] );
check( 'text phone kept as custom when tel gave one', array( 'contact_number' => '91234 56789' ), $text_and_tel['custom_fields'] );

$bad_text_phone = $m::map_fields(
	array(
		array(
			'id'    => 'phone',
			'type'  => 'text',
			'title' => 'Phone',
			'value' => 'call after 5',
		),
	)
);
check( 'unreadable text phone not used', false, isset( $bad_text_phone['phone'] ) );
check( 'unreadable text phone kept as custom', array( 'phone' => 'call after 5' ), $bad_text_phone['custom_fields'] );

// Numbers without a country code are kept as the custom field phone.
$bare_tel = $m::map_fields(
	array(
		array(
			'id'    => 'mail',
			'type'  => 'email',
			'title' => 'Email',
			'value' => 'a@example.com',
		),
		array(
			'id'    => 'field_7',
			'type'  => 'tel',
			'title' => 'Phone',
			'value' => '98765 43210',
		),
	)
);
check( 'bare tel not sent as phone', false, isset( $bare_tel['phone'] ) );
check( 'bare tel kept as custom phone', array( 'phone' => '98765 43210' ), $bare_tel['custom_fields'] );

$only_bare = $m::map_fields(
	array(
		array(
			'id'    => 'tel',
			'type'  => 'tel',
			'title' => 'Phone',
			'value' => '9876543210',
		),
	)
);
check( 'only bare tel: no email', false, isset( $only_bare['email'] ) );
check( 'only bare tel: no phone', false, isset( $only_bare['phone'] ) );

$bare_text = $m::map_fields(
	array(
		array(
			'id'    => 'mail',
			'type'  => 'email',
			'title' => 'Email',
			'value' => 'a@example.com',
		),
		array(
			'id'    => 'field_3',
			'type'  => 'text',
			'title' => 'Mobile',
			'value' => '09876543210',
		),
	)
);
check( 'bare text phone not sent as phone', false, isset( $bare_text['phone'] ) );
check( 'bare text phone kept as custom phone', array( 'phone' => '09876543210' ), $bare_text['custom_fields'] );

$by_label = $m::map_fields(
	array(
		array(
			'id'    => 'field_abc',
			'type'  => 'text',
			'title' => 'Phone Number',
			'value' => '+1 415 555 0123',
		),
	)
);
check( 'text phone by label', '+14155550123', isset( $by_label['phone'] ) ? $by_label['phone'] : null );

// Checkboxes.
$boxes = $m::map_fields(
	array(
		array(
			'id'        => 'services',
			'type'      => 'checkbox',
			'title'     => 'Services',
			'value'     => '',
			'raw_value' => array( 'SEO', 'Ads' ),
		),
		array(
			'id'    => 'size',
			'type'  => 'radio',
			'title' => 'Size',
			'value' => 'Small',
		),
	)
);
check(
	'checkbox and radio as custom fields',
	array(
		'services' => 'SEO, Ads',
		'size'     => 'Small',
	),
	$boxes['custom_fields']
);

// Source cookie.
check(
	'cookie parsed',
	array(
		'host'   => 'chatgpt.com',
		'params' => array(
			'utm_source' => 'chatgpt.com',
			'utm_medium' => 'referral',
		),
	),
	$m::parse_source_cookie( 'h=chatgpt.com&s=chatgpt.com&m=referral' )
);
check(
	'cookie rejects junk',
	array(
		'host'   => '',
		'params' => array(),
	),
	$m::parse_source_cookie( 'h=https://evil/x&s=<script>&z=1' )
);

// AI sources.
check( 'chatgpt host', 'chatgpt', $m::classify_ai_source( 'chatgpt.com', array() ) );
check( 'chat.openai.com', 'chatgpt', $m::classify_ai_source( 'chat.openai.com', array() ) );
check( 'perplexity subdomain', 'perplexity', $m::classify_ai_source( 'www.perplexity.ai', array() ) );
check( 'gemini', 'gemini', $m::classify_ai_source( 'gemini.google.com', array() ) );
check( 'bard', 'gemini', $m::classify_ai_source( 'bard.google.com', array() ) );
check( 'copilot', 'copilot', $m::classify_ai_source( 'copilot.microsoft.com', array() ) );
check( 'claude', 'claude', $m::classify_ai_source( 'claude.ai', array() ) );
check( 'deepseek', 'deepseek', $m::classify_ai_source( 'chat.deepseek.com', array() ) );
check( 'grok', 'grok', $m::classify_ai_source( 'grok.com', array() ) );
check( 'x.ai', 'grok', $m::classify_ai_source( 'x.ai', array() ) );
check( 'lookalike host', 'none', $m::classify_ai_source( 'superchatgpt.com', array() ) );
check( 'google search is not gemini', 'none', $m::classify_ai_source( 'www.google.com', array() ) );
check( 'utm chatgpt.com', 'chatgpt', $m::classify_ai_source( '', array( 'utm_source' => 'chatgpt.com' ) ) );
check( 'utm openai', 'chatgpt', $m::classify_ai_source( '', array( 'utm_source' => 'openai' ) ) );
check( 'utm anthropic', 'claude', $m::classify_ai_source( '', array( 'utm_source' => 'anthropic' ) ) );
check( 'ref chatgpt', 'chatgpt', $m::classify_ai_source( '', array( 'ref' => 'chatgpt' ) ) );
check( 'ct-referrer', 'perplexity', $m::classify_ai_source( '', array( 'ct-referrer' => 'perplexity' ) ) );
check( 'utm other', 'none', $m::classify_ai_source( '', array( 'utm_source' => 'newsletter' ) ) );

// Other sources.
check(
	'google search',
	array(
		'kind' => 'search',
		'name' => 'google.co.in',
	),
	$m::classify_source( 'www.google.co.in', array(), 'example.com' )
);
check(
	'bing search',
	array(
		'kind' => 'search',
		'name' => 'bing.com',
	),
	$m::classify_source( 'bing.com', array(), 'example.com' )
);
check(
	'social',
	array(
		'kind' => 'social',
		'name' => 'm.facebook.com',
	),
	$m::classify_source( 'm.facebook.com', array(), 'example.com' )
);
check(
	'email medium',
	array(
		'kind' => 'email',
		'name' => 'weekly',
	),
	$m::classify_source( '', array(
		'utm_medium' => 'email',
		'utm_source' => 'weekly',
	), 'example.com' )
);
check(
	'paid medium',
	array(
		'kind' => 'paid',
		'name' => 'google.com',
	),
	$m::classify_source( 'www.google.com', array( 'utm_medium' => 'cpc' ), 'example.com' )
);
check( 'ai is not other source', null, $m::classify_source( 'chatgpt.com', array(), 'example.com' ) );
check( 'own host', null, $m::classify_source( 'www.example.com', array(), 'example.com' ) );
check( 'plain referral left out', null, $m::classify_source( 'someblog.net', array(), 'example.com' ) );
check( 'direct', null, $m::classify_source( '', array(), 'example.com' ) );

// Keys and ids.
check( 'api key valid', true, $m::is_valid_api_key( 'pa_Ab-1_x2Y_secretvalue' ) );
check( 'api key invalid', false, $m::is_valid_api_key( 'sk_live_123' ) );
check( 'uuid valid', true, $m::is_uuid( '123e4567-e89b-12d3-a456-426614174000' ) );
check( 'uuid invalid', false, $m::is_uuid( 'example.com' ) );

if ( $failures ) {
	fwrite( STDERR, "$failures of $count checks failed.\n" );
	exit( 1 );
}
echo "All $count checks passed.\n";
