<?php
/**
 * Removes the plugin's options when it is deleted, and disconnects the site
 * from PingAura if it is still connected.
 *
 * @package PingAura_Telemetry
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-pingaura-ai-api.php';

/**
 * Deletes the plugin's options and transients on the current site.
 */
function pingaura_telemetry_uninstall_site() {
	delete_option( 'pingaura_telemetry' );
	delete_option( 'pingaura_telemetry_status' );
	delete_option( 'pingaura_telemetry_indexnow' );
	delete_option( 'pingaura_telemetry_indexnow_status' );
	delete_transient( 'pingaura_telemetry_backoff' );

	PingAura_AI_Api::disconnect_remote( false );
	PingAura_AI_Api::forget_connection();
	delete_option( 'pingaura_ai_fixes' );
	delete_transient( 'pingaura_ai_connect' );
}

if ( is_multisite() ) {
	$pingaura_telemetry_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $pingaura_telemetry_site_ids as $pingaura_telemetry_site_id ) {
		switch_to_blog( $pingaura_telemetry_site_id );
		pingaura_telemetry_uninstall_site();
		restore_current_blog();
	}
} else {
	pingaura_telemetry_uninstall_site();
}
