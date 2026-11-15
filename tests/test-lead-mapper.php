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

// Phone numbers.
check( 'bare 10 digits', '+919876543210', $m::normalize_phone( '9876543210', '+91' ) );
check( 'spaces and dashes', '+919876543210', $m::normalize_phone( '98765 43-210', '+91' ) );
check( 'brackets and dots', '+919876543210', $m::normalize_phone( '(98765).43210', '+91' ) );
check( 'trunk zero', '+919876543210', $m::normalize_phone( '09876543210', '+91' ) );
check( '00 prefix', '+447911123456', $m::normalize_phone( '0044 7911 123456', '+91' ) );
check( 'already E.164', '+14155550123', $m::normalize_phone( '+1 (415) 555-0123', '+91' ) );
check( 'country code without plus', '+919876543210', $m::normalize_phone( '919876543210', '+91' ) );
check( 'other default code', '+19876543210', $m::normalize_phone( '9876543210', '+1' ) );
check( 'invalid default falls back to +91', '+919876543210', $m::normalize_phone( '9876543210', 'abc' ) );
check( 'too short', '', $m::normalize_phone( '12345', '+91' ) );
check( 'letters', '', $m::normalize_phone( 'call me', '+91' ) );
check( 'plus zero', '', $m::normalize_phone( '+0123456789', '+91' ) );
check( 'too long', '', $m::normalize_phone( '+1234567890123456', '+91' ) );

// Country codes.
check( 'country code with plus', '+91', $m::normalize_country_code( '+91' ) );
check( 'country code without plus', '+44', $m::normalize_country_code( '44' ) );
check( 'country code invalid', '', $m::normalize_country_code( '+0' ) );
check( 'country code too long', '', $m::normalize_country_code( '+12345' ) );

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
		'value' => '098765 43210',
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

$lead = $m::map_fields( $fields, '+91' );
check( 'name by label', 'Asha', isset( $lead['name'] ) ? $lead['name'] : null );
check( 'email by type, lowercased', 'asha@example.com', isset( $lead['email'] ) ? $lead['email'] : null );
check( 'phone by type', '+919876543210', isset( $lead['phone'] ) ? $lead['phone'] : null );
check( 'message', 'Hello', isset( $lead['message'] ) ? $lead['message'] : null );
check( 'consent', true, $lead['consent'] );
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
	),
	'+91'
);
check( 'invalid email dropped', false, isset( $none['email'] ) );
check( 'invalid phone dropped', false, isset( $none['phone'] ) );
check( 'no consent', false, $none['consent'] );

$long = $m::map_fields(
	array(
		array(
			'id'    => 'notes',
			'type'  => 'text',
			'title' => 'Notes',
			'value' => str_repeat( 'a', 6000 ),
		),
	),
	'+91'
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
check( 'custom fields capped', 20, count( $m::map_fields( $many, '+91' )['custom_fields'] ) );

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
