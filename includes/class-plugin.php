<?php
/**
 * Chargement des classes et des hooks.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Point d'entrée du plugin : un connecteur entre le site et le tableau de bord Upsidly (upsidly.app).
 */
final class Upsidly_Agent_Plugin {

	/**
	 * Instance unique.
	 *
	 * @var Upsidly_Agent_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Classes chargées par le plugin (fichier => classe).
	 *
	 * @var array<string, string>
	 */
	const CLASSES = array(
		'class-environment.php'                   => 'Upsidly_Agent_Environment',
		'class-sanitizer.php'                     => 'Upsidly_Agent_Sanitizer',
		'class-seo.php'                           => 'Upsidly_Agent_Seo',
		'class-log.php'                           => 'Upsidly_Agent_Log',
		'class-content-types.php'                 => 'Upsidly_Agent_Content_Types',
		'class-products.php'                      => 'Upsidly_Agent_Products',
		'class-drafts.php'                        => 'Upsidly_Agent_Drafts',
		'class-writer.php'                        => 'Upsidly_Agent_Writer',
		'agent/class-guard.php'                   => 'Upsidly_Agent_Guard',
		'agent/class-agent-store.php'             => 'Upsidly_Agent_Agent_Store',
		'agent/class-tool-registry.php'           => 'Upsidly_Agent_Tool_Registry',
		'agent/class-backup.php'                  => 'Upsidly_Agent_Backup',
		'agent/class-health-check.php'            => 'Upsidly_Agent_Health_Check',
		'agent/tools/class-tools-write.php'       => 'Upsidly_Agent_Tools_Write',
		'agent/tools/class-tools-dangerous.php'   => 'Upsidly_Agent_Tools_Dangerous',
		'agent/tools/class-tools-files.php'       => 'Upsidly_Agent_Tools_Files',
		'agent/tools/class-tools-diagnostics.php' => 'Upsidly_Agent_Tools_Diagnostics',
		'agent/tools/class-tools-settings.php'    => 'Upsidly_Agent_Tools_Settings',
		'agent/tools/class-tools-content.php'     => 'Upsidly_Agent_Tools_Content',
		'agent/tools/class-tools-database.php'    => 'Upsidly_Agent_Tools_Database',
		'agent/tools/class-tools-theme.php'       => 'Upsidly_Agent_Tools_Theme',
		'agent/class-actions.php'                 => 'Upsidly_Agent_Actions',
		'class-connector.php'                     => 'Upsidly_Agent_Connector',
	);

	/**
	 * Version du schéma (tables, crons) : déclenche la mise à niveau.
	 */
	const SCHEMA_VERSION = '1.0.0';
	const SCHEMA_OPTION  = 'upsidly_agent_schema';
	const PURGE_HOOK     = 'upsidly_agent_daily_purge';

	/**
	 * Options et tâches des versions 0.x (interface dans WordPress), retirées à la mise à niveau.
	 */
	const LEGACY_OPTIONS = array( 'upsidly_agent_key', 'upsidly_agent_site_description', 'upsidly_agent_brand_voice', 'upsidly_agent_connection', 'upsidly_agent_agent_enabled' );

	/**
	 * Renvoie l'instance unique.
	 *
	 * @return Upsidly_Agent_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Charge les fichiers des classes.
	 */
	public static function load() {
		foreach ( array_keys( self::CLASSES ) as $file ) {
			require_once UPSIDLY_AGENT_DIR . 'includes/' . $file;
		}
	}

	/**
	 * Branche les hooks.
	 */
	public function init() {
		self::load();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		Upsidly_Agent_Connector::init();

		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( self::PURGE_HOOK, array( 'Upsidly_Agent_Agent_Store', 'purge' ) );

		$this->init_updates();
	}

	/**
	 * Charge les traductions.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'upsidly-agent', false, dirname( plugin_basename( UPSIDLY_AGENT_FILE ) ) . '/languages' );
	}

	/**
	 * Mises à jour proposées par WordPress depuis api.upsidly.app (Plugin Update Checker).
	 */
	private function init_updates() {
		$loader = UPSIDLY_AGENT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;

		$factory = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		// Fiche de version servie par Upsidly (les versions 1.x ne passent plus par les releases GitHub).
		$factory::buildUpdateChecker(
			rtrim( UPSIDLY_AGENT_API_URL, '/' ) . '/plugin/info.json',
			UPSIDLY_AGENT_FILE,
			'upsidly-agent'
		);

		// Mises à jour automatiques de l'extension (désactivables : define( 'UPSIDLY_AGENT_AUTO_UPDATE', false ); dans wp-config.php).
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
	}

	/**
	 * L'extension se met à jour toute seule, comme WordPress pour ses mises à jour de sécurité.
	 *
	 * @param bool|null $update Décision de WordPress ou d'une autre extension.
	 * @param object    $item   Extension concernée.
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		$file = is_object( $item ) && isset( $item->plugin ) ? (string) $item->plugin : '';
		if ( plugin_basename( UPSIDLY_AGENT_FILE ) !== $file ) {
			return $update;
		}
		if ( defined( 'UPSIDLY_AGENT_AUTO_UPDATE' ) && ! UPSIDLY_AGENT_AUTO_UPDATE ) {
			return $update;
		}
		return true;
	}

	/**
	 * Activation : tables, cron, puis liaison lancée seule au prochain écran d'administration.
	 *
	 * @param bool $network_wide Activation réseau (multisite).
	 */
	public static function activate( $network_wide = false ) {
		self::load();

		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::upgrade_site();
				restore_current_blog();
			}
			return;
		}

		self::upgrade_site();
		Upsidly_Agent_Connector::schedule_activation_redirect();
	}

	/**
	 * Mise à niveau si le schéma a changé (plugin mis à jour sans réactivation).
	 */
	public static function maybe_upgrade() {
		if ( self::SCHEMA_VERSION !== get_option( self::SCHEMA_OPTION ) ) {
			self::upgrade_site();
		}
	}

	/**
	 * Tables (journal, actions et sauvegardes de l'agent), cron de purge, nettoyage des versions 0.x.
	 */
	private static function upgrade_site() {
		Upsidly_Agent_Log::create_table();
		Upsidly_Agent_Agent_Store::create_tables();

		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_transient( 'upsidly_agent_status' );
		wp_clear_scheduled_hook( 'upsidly_agent_daily_connect' );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Désactivation : déprogrammation du cron. La liaison et les sauvegardes sont conservées.
	 *
	 * @param bool $network_wide Désactivation réseau (multisite).
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				wp_clear_scheduled_hook( self::PURGE_HOOK );
				restore_current_blog();
			}
			return;
		}
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}
}
