<?php
/**
 * Plugin Name:       PingAura Telemetry
 * Plugin URI:        https://www.pingaura.ai/docs/ai-traffic/wordpress-setup
 * Description:       See the visits AI assistants send to your site and the AI crawlers that read it, in PingAura.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PingAura AI
 * Author URI:        https://www.pingaura.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pingaura-telemetry
 *
 * @package PingAura_Telemetry
 */

defined( 'ABSPATH' ) || exit;

define( 'PINGAURA_TELEMETRY_VERSION', '1.0.0' );
define( 'PINGAURA_TELEMETRY_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-pingaura-telemetry-settings.php';
require_once __DIR__ . '/includes/class-pingaura-telemetry-client.php';
require_once __DIR__ . '/includes/class-pingaura-telemetry-tracker.php';
require_once __DIR__ . '/includes/class-pingaura-telemetry-admin.php';

PingAura_Telemetry_Tracker::init();

if ( is_admin() ) {
	PingAura_Telemetry_Admin::init();
}
