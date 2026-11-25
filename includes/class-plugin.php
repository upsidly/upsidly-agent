<?php
/**
 * Chargement des classes et des hooks.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Point d'entrée du plugin.
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
		'class-settings.php'                      => 'Upsidly_Agent_Settings',
		'class-client.php'                        => 'Upsidly_Agent_Client',
		'class-fake-proxy.php'                    => 'Upsidly_Agent_Fake_Proxy',
		'class-environment.php'                   => 'Upsidly_Agent_Environment',
		'class-sanitizer.php'                     => 'Upsidly_Agent_Sanitizer',
		'class-seo.php'                           => 'Upsidly_Agent_Seo',
		'class-log.php'                           => 'Upsidly_Agent_Log',
		'class-content-types.php'                 => 'Upsidly_Agent_Content_Types',
		'class-products.php'                      => 'Upsidly_Agent_Products',
		'class-drafts.php'                        => 'Upsidly_Agent_Drafts',
		'class-rest.php'                          => 'Upsidly_Agent_Rest',
		'class-heartbeat.php'                     => 'Upsidly_Agent_Heartbeat',
		'class-admin.php'                         => 'Upsidly_Agent_Admin',
		'class-connector.php'                     => 'Upsidly_Agent_Connector',
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
		'agent/class-agent-loop.php'              => 'Upsidly_Agent_Agent_Loop',
		'agent/class-agent-rest.php'              => 'Upsidly_Agent_Agent_Rest',
	);

	/**
	 * Version du schéma (tables, capacités, crons) : déclenche la mise à niveau.
	 */
	const SCHEMA_VERSION = '0.3.0';
	const SCHEMA_OPTION  = 'upsidly_agent_schema';
	const PURGE_HOOK     = 'upsidly_agent_daily_purge';

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

		Upsidly_Agent_Settings::init();
		Upsidly_Agent_Rest::init();
		Upsidly_Agent_Heartbeat::init();
		Upsidly_Agent_Admin::init();
		Upsidly_Agent_Agent_Rest::init();
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
	 * Mises à jour depuis les releases GitHub (Plugin Update Checker).
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

		$checker = $factory::buildUpdateChecker(
			'https://github.com/upsidly/upsidly-agent/',
			UPSIDLY_AGENT_FILE,
			'upsidly-agent'
		);

		$api = $checker->getVcsApi();
		if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/upsidly-agent\.zip$/i' );
		}
	}

	/**
	 * Activation : table du journal, options, cron, redirection vers Réglages.
	 *
	 * @param bool $network_wide Activation réseau (multisite).
	 */
	public static function activate( $network_wide = false ) {
		self::load();

		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Activation pour le site courant.
	 */
	private static function activate_site() {
		Upsidly_Agent_Log::create_table();
		Upsidly_Agent_Settings::add_options();
		Upsidly_Agent_Heartbeat::schedule();
		self::upgrade_site();

		if ( '' === Upsidly_Agent_Settings::get_key() ) {
			set_transient( 'upsidly_agent_activation_redirect', 1, 60 );
		}
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
	 * Tables de l'agent, capacité des administrateurs, cron de purge.
	 */
	private static function upgrade_site() {
		Upsidly_Agent_Log::create_table();
		Upsidly_Agent_Agent_Store::create_tables();
		Upsidly_Agent_Settings::add_options();

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( Upsidly_Agent_Agent_Rest::CAPABILITY ) ) {
			$role->add_cap( Upsidly_Agent_Agent_Rest::CAPABILITY );
		}

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Désactivation : déconnexion (sans bloquer), déprogrammation du cron. Les données sont conservées.
	 *
	 * @param bool $network_wide Désactivation réseau (multisite).
	 */
	public static function deactivate( $network_wide = false ) {
		self::load();

		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Désactivation pour le site courant.
	 */
	private static function deactivate_site() {
		Upsidly_Agent_Heartbeat::unschedule();
		wp_clear_scheduled_hook( self::PURGE_HOOK );

		if ( '' !== Upsidly_Agent_Settings::get_key() ) {
			// Erreurs ignorées : la désactivation ne doit jamais être bloquée.
			( new Upsidly_Agent_Client() )->disconnect( null, 5 );
		}
		delete_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );
	}
}
