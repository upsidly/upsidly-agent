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
		);

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
				'content'  => 'Les actions qui modifient le site ne sont pas encore disponibles dans cette version de l\'agent. Explique à la personne la modification à faire.',
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
