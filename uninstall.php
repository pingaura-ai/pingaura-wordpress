<?php
/**
 * Removes the plugin's options when it is deleted.
 *
 * @package PingAura_Telemetry
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's options and transients on the current site.
 */
function pingaura_telemetry_uninstall_site() {
	delete_option( 'pingaura_telemetry' );
	delete_option( 'pingaura_telemetry_status' );
	delete_transient( 'pingaura_telemetry_backoff' );
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
