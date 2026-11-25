<?php
/**
 * Faux client du proxy pour les tests.
 *
 * @package Upsidly_Agent
 */

/**
 * Renvoie des réponses préparées et garde les requêtes reçues.
 */
class Upsidly_Agent_Test_Fake_Client extends Upsidly_Agent_Client {

	/**
	 * Réponses à renvoyer, par chemin : array{status: int, body: mixed}.
	 *
	 * @var array<string, array>
	 */
	public $responses = array();

	/**
	 * Requêtes reçues.
	 *
	 * @var array<int, array>
	 */
	public $requests = array();

	/**
	 * Requête simulée.
	 *
	 * @param string      $method  Méthode.
	 * @param string      $path    Chemin.
	 * @param mixed       $body    Corps.
	 * @param int         $timeout Délai.
	 * @param string|null $key     Clé.
	 * @return array|WP_Error
	 */
	protected function request( $method, $path, $body, $timeout, $key = null ) {
		$this->requests[] = array(
			'method'  => $method,
			'path'    => $path,
			'body'    => json_decode( (string) wp_json_encode( $body ), true ),
			'timeout' => $timeout,
		);

		$response = $this->responses[ $path ] ?? array(
			'status' => 500,
			'body'   => null,
		);

		return self::normalize( $response );
	}
}
