<?php
/**
 * Déclaration des outils de l'agent, niveau de risque, exécution.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registre des outils exécutables sur le site.
 */
class Upsidly_Agent_Tool_Registry {

	const RISK_READ      = 'read';
	const RISK_WRITE     = 'write';
	const RISK_DANGEROUS = 'dangerous';

	/**
	 * Niveau d'agent imposé par une demande signée d'Upsidly (connecteur), sinon null.
	 *
	 * @var string|null
	 */
	private static $level = null;

	/**
	 * Impose le niveau d'agent pour la requête courante (demande signée du connecteur).
	 *
	 * @param string|null $level off | read | write | full.
	 */
	public static function set_level( $level ) {
		self::$level = in_array( $level, array( 'off', 'read', 'write', 'full' ), true ) ? $level : null;
	}

	/**
	 * Outils disponibles : nom => [classe, méthode, risque].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function tools() {
		$tools = array(
			'site_overview' => array( 'Upsidly_Agent_Tools_Diagnostics', 'site_overview', self::RISK_READ ),
			'read_logs'     => array( 'Upsidly_Agent_Tools_Diagnostics', 'read_logs', self::RISK_READ ),
			'fetch_page'    => array( 'Upsidly_Agent_Tools_Diagnostics', 'fetch_page', self::RISK_READ ),
			'list_files'    => array( 'Upsidly_Agent_Tools_Files', 'list_files', self::RISK_READ ),
			'read_file'     => array( 'Upsidly_Agent_Tools_Files', 'read_file', self::RISK_READ ),
			'search_files'  => array( 'Upsidly_Agent_Tools_Files', 'search_files', self::RISK_READ ),
			'get_options'   => array( 'Upsidly_Agent_Tools_Settings', 'get_options', self::RISK_READ ),
			'list_content'  => array( 'Upsidly_Agent_Tools_Content', 'list_content', self::RISK_READ ),
			'get_content'   => array( 'Upsidly_Agent_Tools_Content', 'get_content', self::RISK_READ ),
			'db_query_read' => array( 'Upsidly_Agent_Tools_Database', 'db_query_read', self::RISK_READ ),
			'theme_info'    => array( 'Upsidly_Agent_Tools_Theme', 'theme_info', self::RISK_READ ),
			'get_template'  => array( 'Upsidly_Agent_Tools_Theme', 'get_template', self::RISK_READ ),
		);
		foreach ( array( 'edit_file', 'create_file', 'plugin_activate', 'plugin_deactivate', 'theme_activate', 'plugin_update', 'theme_update', 'plugin_install', 'update_option', 'save_content', 'save_term', 'save_template' ) as $write ) {
			$tools[ $write ] = array( 'Upsidly_Agent_Tools_Write', $write, self::RISK_WRITE );
		}
		foreach ( array( 'db_query_write', 'run_php', 'delete_file', 'plugin_delete', 'theme_delete' ) as $dangerous ) {
			$tools[ $dangerous ] = array( 'Upsidly_Agent_Tools_Dangerous', $dangerous, self::RISK_DANGEROUS );
		}

		/**
		 * Filtre le registre des outils (tests, lots suivants).
		 *
		 * @param array $tools Outils.
		 */
		return (array) apply_filters( 'upsidly_agent_tools', $tools );
	}

	/**
	 * Niveau de risque d'un outil (null si inconnu).
	 *
	 * @param string $name Outil.
	 * @return string|null
	 */
	public static function risk( $name ) {
		$tools = self::tools();
		return isset( $tools[ $name ] ) ? $tools[ $name ][2] : null;
	}

	/**
	 * Écritures autorisées pour ce site ? (niveau d'agent fixé par Upsidly : write ou full).
	 *
	 * @return bool
	 */
	public static function writes_allowed() {
		return in_array( self::site_level(), array( 'write', 'full' ), true );
	}

	/**
	 * Outils sensibles autorisés pour ce site ? (niveau full).
	 *
	 * @return bool
	 */
	public static function dangerous_allowed() {
		return 'full' === self::site_level();
	}

	/**
	 * Une action de ce niveau de risque est-elle autorisée ?
	 *
	 * @param string $risk Niveau de risque.
	 * @return bool
	 */
	public static function allowed( $risk ) {
		return self::RISK_DANGEROUS === $risk ? self::dangerous_allowed() : ( self::RISK_WRITE === $risk ? self::writes_allowed() : true );
	}

	/**
	 * Niveau d'agent du site, fixé par Upsidly à chaque demande signée.
	 *
	 * @return string
	 */
	private static function site_level() {
		if ( null !== self::$level ) {
			return self::$level;
		}
		// Sans niveau transmis par Upsidly (demande signée), rien n'est permis au-delà de la lecture.
		return 'off';
	}

	/**
	 * Aperçu d'un outil d'écriture (rien n'est modifié).
	 *
	 * @param string $name  Outil.
	 * @param mixed  $input Entrées.
	 * @return array{ok: bool, title: string, preview: string, error?: string}
	 */
	public static function preview( $name, $input ) {
		$tools = self::tools();
		if ( ! isset( $tools[ $name ] ) || self::RISK_READ === $tools[ $name ][2] ) {
			return array(
				'ok'      => false,
				'title'   => '',
				'preview' => '',
				'error'   => sprintf( 'Outil « %s » indisponible.', $name ),
			);
		}
		try {
			return call_user_func( array( $tools[ $name ][0], 'preview_' . $tools[ $name ][1] ), is_array( $input ) ? $input : array() );
		} catch ( Throwable $e ) {
			return array(
				'ok'      => false,
				'title'   => '',
				'preview' => '',
				'error'   => 'Erreur pendant la préparation : ' . Upsidly_Agent_Guard::redact_text( $e->getMessage() ),
			);
		}
	}

	/**
	 * Applique un outil d'écriture validé.
	 *
	 * @param string $name      Outil.
	 * @param mixed  $input     Entrées.
	 * @param int    $action_id Action.
	 * @return array{status: string, content: string, is_error: bool, backup_id: int}
	 */
	public static function apply( $name, $input, $action_id ) {
		$tools = self::tools();
		if ( ! isset( $tools[ $name ] ) || self::RISK_READ === $tools[ $name ][2] ) {
			return array(
				'status'    => 'failed',
				'content'   => sprintf( 'Outil « %s » indisponible.', $name ),
				'is_error'  => true,
				'backup_id' => 0,
			);
		}
		try {
			return call_user_func( array( $tools[ $name ][0], 'apply_' . $tools[ $name ][1] ), is_array( $input ) ? $input : array(), (int) $action_id );
		} catch ( Throwable $e ) {
			return array(
				'status'    => 'failed',
				'content'   => 'Erreur pendant l\'application : ' . Upsidly_Agent_Guard::redact_text( $e->getMessage() ),
				'is_error'  => true,
				'backup_id' => 0,
			);
		}
	}

	/**
	 * Exécute un outil et renvoie le résultat pour l'IA.
	 *
	 * @param string $name  Outil.
	 * @param mixed  $input Entrées fournies par l'IA.
	 * @return array{content: string, is_error: bool}
	 */
	public static function execute( $name, $input ) {
		$tools = self::tools();
		if ( ! isset( $tools[ $name ] ) ) {
			return array(
				'content'  => sprintf( 'Outil « %s » indisponible sur ce site.', $name ),
				'is_error' => true,
			);
		}
		if ( self::RISK_READ !== $tools[ $name ][2] ) {
			return array(
				'content'  => 'Cette action modifie le site : elle doit être validée par la personne.',
				'is_error' => true,
			);
		}

		list( $class, $method ) = $tools[ $name ];
		$input                  = is_array( $input ) ? $input : array();

		try {
			$result = call_user_func( array( $class, $method ), $input );
		} catch ( Throwable $e ) {
			return array(
				'content'  => 'Erreur pendant l\'exécution de l\'outil : ' . Upsidly_Agent_Guard::redact_text( $e->getMessage() ),
				'is_error' => true,
			);
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'content'  => $result->get_error_message(),
				'is_error' => true,
			);
		}

		return array(
			'content'  => '' === (string) $result ? '(résultat vide)' : (string) $result,
			'is_error' => false,
		);
	}
}
