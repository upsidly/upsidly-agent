<?php
/**
 * Boucle de l'agent : appel au proxy, exécution des outils, arrêt.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Une étape = exécution des outils demandés (lecture) + un appel au proxy au plus.
 */
class Upsidly_Agent_Agent_Loop {

	const DEFAULT_MAX_STEPS = 40;
	const LOCK_SECONDS      = 180;

	/**
	 * Client du proxy (remplaçable pour les tests).
	 *
	 * @var Upsidly_Agent_Client|null
	 */
	public static $client = null;

	/**
	 * Client du proxy.
	 *
	 * @return Upsidly_Agent_Client
	 */
	public static function client() {
		return self::$client ? self::$client : new Upsidly_Agent_Client();
	}

	/**
	 * Ajoute un message de l'utilisateur et prépare la boucle.
	 *
	 * @param string $conversation_id Conversation.
	 * @param string $text            Message.
	 * @return true|WP_Error
	 */
	public static function add_user_message( $conversation_id, $text ) {
		$conversation = Upsidly_Agent_Agent_Store::get( $conversation_id );
		if ( ! $conversation ) {
			return new WP_Error( 'upsidly_agent_conversation', __( 'Conversation introuvable.', 'upsidly-agent' ), array( 'status' => 404 ) );
		}
		if ( 'running' === $conversation['status'] && self::is_locked( $conversation_id ) ) {
			return new WP_Error( 'upsidly_agent_busy', __( 'L\'agent travaille encore : attendez la fin ou cliquez sur Arrêter.', 'upsidly-agent' ), array( 'status' => 409 ) );
		}

		$messages = Upsidly_Agent_Agent_Store::messages( $conversation_id );
		$last     = $messages ? end( $messages ) : null;

		// Demande d'outils restée sans réponse (arrêt, erreur) : on la clôt pour garder un historique valide.
		if ( $last && 'assistant' === $last['role'] && self::tool_uses( $last['content'] ) ) {
			Upsidly_Agent_Agent_Store::add_message( $conversation_id, 'user', self::interrupted_results( $last['content'] ) );
			$messages = Upsidly_Agent_Agent_Store::messages( $conversation_id );
			$last     = end( $messages );
		}

		$block = array(
			'type' => 'text',
			'text' => $text,
		);
		if ( $last && 'user' === $last['role'] ) {
			// Deux messages utilisateur consécutifs : fusion.
			$content   = is_array( $last['content'] ) ? $last['content'] : array(
				array(
					'type' => 'text',
					'text' => (string) $last['content'],
				),
			);
			$content[] = $block;
			Upsidly_Agent_Agent_Store::replace_message( $last['id'], $content );
		} else {
			Upsidly_Agent_Agent_Store::add_message( $conversation_id, 'user', array( $block ) );
		}

		Upsidly_Agent_Agent_Store::update(
			$conversation_id,
			array(
				'status' => 'running',
				'steps'  => 0,
			)
		);
		return true;
	}

	/**
	 * Une étape de la boucle.
	 *
	 * @param string $conversation_id Conversation.
	 * @return array|WP_Error {status, messages (nouveaux), credits, conversation}
	 */
	public static function step( $conversation_id ) {
		$conversation = Upsidly_Agent_Agent_Store::get( $conversation_id );
		if ( ! $conversation ) {
			return new WP_Error( 'upsidly_agent_conversation', __( 'Conversation introuvable.', 'upsidly-agent' ), array( 'status' => 404 ) );
		}
		if ( 'running' !== $conversation['status'] ) {
			return array(
				'status'   => $conversation['status'],
				'messages' => array(),
			);
		}
		if ( ! self::lock( $conversation_id ) ) {
			return new WP_Error( 'upsidly_agent_busy', __( 'Une étape est déjà en cours.', 'upsidly-agent' ), array( 'status' => 409 ) );
		}

		try {
			return self::run_step( $conversation );
		} finally {
			self::unlock( $conversation_id );
		}
	}

	/**
	 * Corps de l'étape (verrou posé).
	 *
	 * @param array $conversation Conversation.
	 * @return array|WP_Error
	 */
	private static function run_step( array $conversation ) {
		$id        = (string) $conversation['id'];
		$messages  = Upsidly_Agent_Agent_Store::messages( $id );
		$last      = $messages ? end( $messages ) : null;
		$last_id   = $last ? (int) $last['id'] : 0;
		$steps     = (int) $conversation['steps'];
		$max_steps = self::max_steps();

		if ( ! $last ) {
			Upsidly_Agent_Agent_Store::update( $id, array( 'status' => 'idle' ) );
			return array(
				'status'   => 'idle',
				'messages' => array(),
			);
		}

		// 1. Outils demandés à la dernière étape : exécution (lecture uniquement dans ce lot).
		if ( 'assistant' === $last['role'] ) {
			$uses = self::tool_uses( $last['content'] );
			if ( ! $uses ) {
				Upsidly_Agent_Agent_Store::update( $id, array( 'status' => 'idle' ) );
				return array(
					'status'   => 'idle',
					'messages' => array(),
				);
			}

			$results = array();
			foreach ( $uses as $use ) {
				$result    = Upsidly_Agent_Tool_Registry::execute( (string) $use['name'], $use['input'] ?? array() );
				$results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => (string) $use['id'],
					'content'     => $result['content'],
					'is_error'    => $result['is_error'],
				);
			}
			Upsidly_Agent_Agent_Store::add_message( $id, 'user', $results );

			if ( $steps >= $max_steps ) {
				Upsidly_Agent_Agent_Store::update( $id, array( 'status' => 'paused' ) );
				return self::response( $id, 'paused', $last_id );
			}
		}

		// 2. Appel au proxy.
		$history  = array_map(
			static function ( $message ) {
				return array(
					'role'    => $message['role'],
					'content' => $message['content'],
				);
			},
			Upsidly_Agent_Agent_Store::messages( $id )
		);
		$response = self::client()->agent_turn( $id, $history, Upsidly_Agent_Rest::context(), Upsidly_Agent_Environment::agent_data() );

		if ( is_wp_error( $response ) ) {
			$code   = $response->get_error_code();
			$status = 'uap_conversation_budget' === $code ? 'budget' : 'error';
			Upsidly_Agent_Agent_Store::update( $id, array( 'status' => $status ) );
			$result          = self::response( $id, $status, $last_id );
			$result['error'] = array(
				'code'    => $code,
				'message' => $response->get_error_message(),
			);
			$data            = (array) $response->get_error_data();
			if ( isset( $data['credits'] ) ) {
				$result['credits'] = $data['credits'];
			}
			return $result;
		}

		$content = isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array();
		if ( ! $content ) {
			$content = array(
				array(
					'type' => 'text',
					'text' => __( '(réponse vide)', 'upsidly-agent' ),
				),
			);
		}
		Upsidly_Agent_Agent_Store::add_message( $id, 'assistant', $content );

		$has_tools = (bool) self::tool_uses( $content );
		$status    = $has_tools ? 'running' : 'idle';

		$conversation_info = isset( $response['conversation'] ) && is_array( $response['conversation'] ) ? $response['conversation'] : array();
		Upsidly_Agent_Agent_Store::update(
			$id,
			array(
				'status'  => $status,
				'steps'   => $steps + 1,
				'credits' => isset( $conversation_info['credits_used'] ) ? (int) $conversation_info['credits_used'] : (int) $conversation['credits'],
			)
		);
		delete_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );

		$result                 = self::response( $id, $status, $last_id );
		$result['credits']      = $response['credits'] ?? null;
		$result['conversation'] = $conversation_info;
		$result['truncated']    = 'max_tokens' === ( $response['stop_reason'] ?? '' );
		return $result;
	}

	/**
	 * Arrête la boucle (bouton « Arrêter »).
	 *
	 * @param string $conversation_id Conversation.
	 * @return array|WP_Error
	 */
	public static function stop( $conversation_id ) {
		$conversation = Upsidly_Agent_Agent_Store::get( $conversation_id );
		if ( ! $conversation ) {
			return new WP_Error( 'upsidly_agent_conversation', __( 'Conversation introuvable.', 'upsidly-agent' ), array( 'status' => 404 ) );
		}
		Upsidly_Agent_Agent_Store::update( $conversation_id, array( 'status' => 'stopped' ) );
		return array( 'status' => 'stopped' );
	}

	/**
	 * Réponse d'étape : statut et nouveaux messages.
	 *
	 * @param string $id       Conversation.
	 * @param string $status   Statut.
	 * @param int    $after_id Messages d'identifiant supérieur.
	 * @return array
	 */
	private static function response( $id, $status, $after_id ) {
		return array(
			'status'   => $status,
			'messages' => Upsidly_Agent_Agent_Store::messages( $id, max( 0, $after_id ) ),
		);
	}

	/**
	 * Blocs tool_use d'un contenu.
	 *
	 * @param mixed $content Contenu.
	 * @return array<int, array>
	 */
	public static function tool_uses( $content ) {
		if ( ! is_array( $content ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$content,
				static function ( $block ) {
					return is_array( $block ) && 'tool_use' === ( $block['type'] ?? '' ) && isset( $block['id'], $block['name'] );
				}
			)
		);
	}

	/**
	 * Résultats « interrompu » pour des demandes d'outils restées sans réponse.
	 *
	 * @param array $content Contenu assistant.
	 * @return array
	 */
	private static function interrupted_results( array $content ) {
		$results = array();
		foreach ( self::tool_uses( $content ) as $use ) {
			$results[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => (string) $use['id'],
				'content'     => 'Action non exécutée : interrompue par la personne.',
				'is_error'    => true,
			);
		}
		return $results;
	}

	/**
	 * Étapes maximum par demande (valeur du proxy si connue).
	 *
	 * @return int
	 */
	public static function max_steps() {
		$status = get_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );
		if ( is_array( $status ) && ! empty( $status['agent']['max_steps'] ) ) {
			return max( 1, (int) $status['agent']['max_steps'] );
		}
		return self::DEFAULT_MAX_STEPS;
	}

	/**
	 * Pose le verrou d'une conversation.
	 *
	 * @param string $id Conversation.
	 * @return bool
	 */
	private static function lock( $id ) {
		if ( self::is_locked( $id ) ) {
			return false;
		}
		set_transient( 'upsidly_agent_lock_' . $id, time(), self::LOCK_SECONDS );
		return true;
	}

	/**
	 * Verrou posé ?
	 *
	 * @param string $id Conversation.
	 * @return bool
	 */
	private static function is_locked( $id ) {
		return (bool) get_transient( 'upsidly_agent_lock_' . $id );
	}

	/**
	 * Retire le verrou.
	 *
	 * @param string $id Conversation.
	 */
	private static function unlock( $id ) {
		delete_transient( 'upsidly_agent_lock_' . $id );
	}
}
