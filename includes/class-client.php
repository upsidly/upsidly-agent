<?php
/**
 * Appels HTTP au proxy Upsidly et traduction des erreurs.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client du proxy (serveur à serveur uniquement).
 */
class Upsidly_Agent_Client {

	const TIMEOUT_SHORT = 10;
	const TIMEOUT_RUN   = 150;

	/**
	 * GET /v1/status.
	 *
	 * @return array|WP_Error
	 */
	public function status() {
		return $this->request( 'GET', '/v1/status', null, self::TIMEOUT_SHORT );
	}

	/**
	 * POST /v1/connect.
	 *
	 * @param array       $env Environnement du site (Upsidly_Agent_Environment::data()).
	 * @param string|null $key Clé à utiliser (par défaut : clé enregistrée).
	 * @return array|WP_Error
	 */
	public function connect( array $env, $key = null ) {
		return $this->request( 'POST', '/v1/connect', $env, self::TIMEOUT_SHORT, $key );
	}

	/**
	 * POST /v1/disconnect.
	 *
	 * @param string|null $key     Clé à utiliser (par défaut : clé enregistrée).
	 * @param int         $timeout Délai en secondes.
	 * @return array|WP_Error
	 */
	public function disconnect( $key = null, $timeout = self::TIMEOUT_SHORT ) {
		return $this->request( 'POST', '/v1/disconnect', new stdClass(), (int) $timeout, $key );
	}

	/**
	 * POST /v1/run.
	 *
	 * @param string $skill   Identifiant de la skill.
	 * @param array  $inputs  Entrées.
	 * @param array  $context Contexte du site.
	 * @return array|WP_Error
	 */
	public function run( $skill, array $inputs, array $context ) {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- peut être désactivée par l'hébergeur.
			@set_time_limit( 180 );
		}

		return $this->request(
			'POST',
			'/v1/run',
			array(
				'skill'   => (string) $skill,
				'inputs'  => (object) $inputs,
				'context' => (object) $context,
			),
			self::TIMEOUT_RUN
		);
	}

	/**
	 * POST /v1/agent/turn : une étape de l'agent.
	 *
	 * @param string $conversation_id Conversation.
	 * @param array  $messages        Historique (format API Messages).
	 * @param array  $context         Contexte du site.
	 * @param array  $environment     Environnement du site.
	 * @return array|WP_Error
	 */
	public function agent_turn( $conversation_id, array $messages, array $context, array $environment ) {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- peut être désactivée par l'hébergeur.
			@set_time_limit( 180 );
		}

		return $this->request(
			'POST',
			'/v1/agent/turn',
			array(
				'conversation_id' => (string) $conversation_id,
				'messages'        => self::with_objects( $messages ),
				'context'         => (object) $context,
				'environment'     => (object) $environment,
			),
			self::TIMEOUT_RUN
		);
	}

	/**
	 * Les entrées d'outils vides doivent être encodées en objet JSON ({}), pas en tableau.
	 *
	 * @param array $messages Historique.
	 * @return array
	 */
	private static function with_objects( array $messages ) {
		foreach ( $messages as &$message ) {
			if ( ! is_array( $message['content'] ) ) {
				continue;
			}
			foreach ( $message['content'] as &$block ) {
				if ( is_array( $block ) && 'tool_use' === ( $block['type'] ?? '' ) ) {
					$block['input'] = (object) ( is_array( $block['input'] ?? null ) ? $block['input'] : array() );
				}
			}
			unset( $block );
		}
		unset( $message );
		return $messages;
	}

	/**
	 * Mode factice activé ? (constante UPSIDLY_AGENT_FAKE_PROXY, ignorée si WP_DEBUG est faux).
	 *
	 * @return bool
	 */
	public static function is_fake() {
		return defined( 'UPSIDLY_AGENT_FAKE_PROXY' ) && UPSIDLY_AGENT_FAKE_PROXY && defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Requête vers le proxy.
	 *
	 * @param string      $method  GET ou POST.
	 * @param string      $path    Chemin, ex. /v1/status.
	 * @param mixed       $body    Corps JSON (null pour GET).
	 * @param int         $timeout Délai en secondes.
	 * @param string|null $key     Clé à utiliser.
	 * @return array|WP_Error
	 */
	protected function request( $method, $path, $body, $timeout, $key = null ) {
		$key = null === $key ? Upsidly_Agent_Settings::get_key() : (string) $key;
		if ( '' === $key ) {
			return new WP_Error(
				'upsidly_agent_no_key',
				__( 'Aucune clé n\'est configurée. Renseignez-la dans Réglages.', 'upsidly-agent' ),
				array( 'status' => 400 )
			);
		}

		if ( self::is_fake() ) {
			return self::normalize( Upsidly_Agent_Fake_Proxy::respond( $method, $path, $body ) );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array(
				'Authorization'            => 'Bearer ' . $key,
				'X-Upsidly-Key'            => $key,
				'X-Upsidly-Site'           => home_url(),
				'X-Upsidly-Plugin-Version' => UPSIDLY_AGENT_VERSION,
				'Content-Type'             => 'application/json',
				'Accept'                   => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( untrailingslashit( UPSIDLY_AGENT_API_URL ) . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'upsidly_agent_network',
				self::message_for( 'upsidly_agent_network' ),
				array( 'status' => 502 )
			);
		}

		return self::normalize(
			array(
				'status' => (int) wp_remote_retrieve_response_code( $response ),
				'body'   => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
			)
		);
	}

	/**
	 * Réponse brute → tableau décodé ou WP_Error traduit.
	 *
	 * @param array{status: int, body: mixed} $response Statut HTTP et corps décodé.
	 * @return array|WP_Error
	 */
	public static function normalize( array $response ) {
		$status = (int) $response['status'];
		$body   = $response['body'];

		if ( $status >= 200 && $status < 300 && is_array( $body ) ) {
			return $body;
		}

		$error   = ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) ? $body['error'] : array();
		$code    = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : 'upsidly_agent_unexpected';
		$credits = isset( $error['credits'] ) && is_array( $error['credits'] ) ? $error['credits'] : null;
		$proxy   = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';

		$data = array( 'status' => $status > 0 ? $status : 502 );
		if ( null !== $credits ) {
			$data['credits'] = $credits;
		}

		return new WP_Error( $code, self::message_for( $code, $proxy, $credits ), $data );
	}

	/**
	 * Message affiché à l'utilisateur pour un code d'erreur du proxy.
	 *
	 * @param string     $code    Code d'erreur.
	 * @param string     $proxy   Message du proxy.
	 * @param array|null $credits Bloc credits éventuel.
	 * @return string
	 */
	public static function message_for( $code, $proxy = '', $credits = null ) {
		switch ( $code ) {
			case 'uap_no_key':
			case 'uap_bad_key':
				return __( 'La clé de connexion est invalide. Vérifiez-la dans Réglages.', 'upsidly-agent' );

			case 'uap_site_disabled':
				return __( 'L\'accès à l\'assistant est suspendu pour ce site. Contactez Upsidly.', 'upsidly-agent' );

			case 'uap_site_mismatch':
				return __( 'Cette clé est associée à un autre site.', 'upsidly-agent' );

			case 'uap_quota_exceeded':
				$resets = is_array( $credits ) && ! empty( $credits['resets_on'] ) ? (string) $credits['resets_on'] : '';
				$time   = '' !== $resets ? strtotime( $resets . ' 00:00:00 UTC' ) : false;
				return sprintf(
					/* translators: %s: date de renouvellement des crédits. */
					__( 'Vos crédits du mois sont épuisés. Ils seront renouvelés le %s.', 'upsidly-agent' ),
					false !== $time ? wp_date( get_option( 'date_format' ), $time, new DateTimeZone( 'UTC' ) ) : __( '1er du mois prochain', 'upsidly-agent' )
				);

			case 'uap_agent_disabled':
				return __( 'L\'agent n\'est pas activé pour ce site. Contactez Upsidly.', 'upsidly-agent' );

			case 'uap_conversation_budget':
				return __( 'Le budget de cette conversation est épuisé. Commencez une nouvelle conversation pour continuer.', 'upsidly-agent' );

			case 'uap_rate_limited':
				return __( 'Trop de demandes en peu de temps. Réessayez dans une minute.', 'upsidly-agent' );

			case 'uap_global_cap':
			case 'uap_pricing_missing':
			case 'uap_upstream_unreachable':
			case 'uap_upstream_error':
				return __( 'Le service est momentanément indisponible. Réessayez dans quelques minutes.', 'upsidly-agent' );

			case 'uap_incomplete':
				return __( 'La génération n\'a pas abouti. Essayez avec une demande plus courte.', 'upsidly-agent' );

			case 'uap_missing_input':
			case 'uap_bad_input':
			case 'uap_input_too_long':
				return '' !== $proxy ? $proxy : __( 'Une erreur inattendue s\'est produite.', 'upsidly-agent' );

			case 'upsidly_agent_network':
				return __( 'Impossible de joindre le service. Réessayez dans quelques minutes.', 'upsidly-agent' );

			default:
				return __( 'Une erreur inattendue s\'est produite.', 'upsidly-agent' );
		}
	}
}
