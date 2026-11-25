<?php
/**
 * Plugin Name:       Upsidly Agent
 * Plugin URI:        https://github.com/upsidly/upsidly-agent
 * Description:       Connecteur Upsidly : reliez ce site à votre tableau de bord upsidly.app pour y piloter l'agent IA et la rédaction. Aucun réglage dans WordPress.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Upsidly
 * Author URI:        https://www.upsidly.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       upsidly-agent
 * Domain Path:       /languages
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'UPSIDLY_AGENT_VERSION' ) ) {
	define( 'UPSIDLY_AGENT_VERSION', '1.0.0' );
}

if ( ! defined( 'UPSIDLY_AGENT_API_URL' ) ) {
	define( 'UPSIDLY_AGENT_API_URL', 'https://api.upsidly.app' );
}

if ( ! defined( 'UPSIDLY_AGENT_DASHBOARD_URL' ) ) {
	define( 'UPSIDLY_AGENT_DASHBOARD_URL', 'https://www.upsidly.app/fr/dashboard' );
}

define( 'UPSIDLY_AGENT_FILE', __FILE__ );
define( 'UPSIDLY_AGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'UPSIDLY_AGENT_URL', plugin_dir_url( __FILE__ ) );

require_once UPSIDLY_AGENT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Upsidly_Agent_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Upsidly_Agent_Plugin', 'deactivate' ) );

Upsidly_Agent_Plugin::instance()->init();
