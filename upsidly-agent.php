<?php
/**
 * Plugin Name:       Upsidly Agent
 * Plugin URI:        https://github.com/upsidly/upsidly-agent
 * Description:       Assistant IA dans l'administration WordPress : rédige des articles et des pages en brouillon, prêts à relire.
 * Version:           0.4.4
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
	define( 'UPSIDLY_AGENT_VERSION', '0.4.4' );
}

if ( ! defined( 'UPSIDLY_AGENT_API_URL' ) ) {
	define( 'UPSIDLY_AGENT_API_URL', 'https://api.upsidly.app' );
}

// UPSIDLY_AGENT_KEY : optionnelle, à définir dans wp-config.php pour imposer la clé de site.

define( 'UPSIDLY_AGENT_FILE', __FILE__ );
define( 'UPSIDLY_AGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'UPSIDLY_AGENT_URL', plugin_dir_url( __FILE__ ) );

require_once UPSIDLY_AGENT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Upsidly_Agent_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Upsidly_Agent_Plugin', 'deactivate' ) );

Upsidly_Agent_Plugin::instance()->init();
