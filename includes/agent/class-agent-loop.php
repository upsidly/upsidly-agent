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
			self::close_pending( $conversation_id, __( 'Action non validée : la personne a envoyé un nouveau message à la place.', 'upsidly-agent' ) );
			Upsidly_Agent_Agent_Store::add_message( $conversation_id, 'user', self::results_for( $conversation_id, $last['content'] ) );
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

		// 1. Outils demandés à la dernière étape : lecture exécutée, écriture soumise à validation.
		if ( 'assistant' === $last['role'] ) {
			$uses = self::tool_uses( $last['content'] );
			if ( ! $uses ) {
				Upsidly_Agent_Agent_Store::update( $id, array( 'status' => 'idle' ) );
				return array(
					'status'   => 'idle',
					'messages' => array(),
				);
			}

			self::register_actions( $id, $uses );
			if ( self::has_pending( $id ) ) {
				Upsidly_Agent_Agent_Store::update( $id, array( 'status' => 'awaiting' ) );
				return self::response( $id, 'awaiting', $last_id );
			}

			Upsidly_Agent_Agent_Store::add_message( $id, 'user', self::results_for( $id, $last['content'] ) );

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
		// Crédits à jour dans le statut en cache, sans perdre le niveau d'agent du site.
		$cached = get_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );
		if ( is_array( $cached ) && isset( $response['credits'] ) && is_array( $response['credits'] ) ) {
			$cached['credits'] = array_diff_key( $response['credits'], array( 'charged' => true ) );
			set_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT, $cached, 5 * MINUTE_IN_SECONDS );
		}

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
		self::close_pending( $conversation_id, __( 'Action non validée : la personne a arrêté l\'agent.', 'upsidly-agent' ) );
		Upsidly_Agent_Agent_Store::update( $conversation_id, array( 'status' => 'stopped' ) );
		return array(
			'status'  => 'stopped',
			'actions' => self::actions_payload( $conversation_id ),
		);
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
			'actions'  => self::actions_payload( $id ),
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
	 * Enregistre les demandes d'outils d'une étape : lecture exécutée tout de suite, écriture en attente de validation.
	 *
	 * @param string $id   Conversation.
	 * @param array  $uses Blocs tool_use.
	 */
	public static function register_actions( $id, array $uses ) {
		$known = array();
		foreach ( Upsidly_Agent_Agent_Store::actions( $id ) as $action ) {
			$known[ $action['tool_use_id'] ] = true;
		}

		foreach ( $uses as $use ) {
			$tool_use_id = (string) $use['id'];
			if ( isset( $known[ $tool_use_id ] ) ) {
				continue;
			}
			$name  = (string) $use['name'];
			$input = is_array( $use['input'] ?? null ) ? $use['input'] : array();
			$risk  = Upsidly_Agent_Tool_Registry::risk( $name );

			if ( Upsidly_Agent_Tool_Registry::RISK_READ === $risk || null === $risk ) {
				Upsidly_Agent_Agent_Store::add_action(
					$id,
					array(
						'tool_use_id' => $tool_use_id,
						'tool'        => $name,
						'risk'        => Upsidly_Agent_Tool_Registry::RISK_READ,
						'input'       => $input,
						'status'      => 'done',
						'result'      => Upsidly_Agent_Tool_Registry::execute( $name, $input ),
					)
				);
				continue;
			}

			$fields = array(
				'tool_use_id' => $tool_use_id,
				'tool'        => $name,
				'risk'        => $risk,
				'input'       => $input,
			);
			if ( ! Upsidly_Agent_Tool_Registry::allowed( $risk ) ) {
				$fields['status'] = 'failed';
				$fields['result'] = array(
					'content'  => Upsidly_Agent_Tool_Registry::RISK_DANGEROUS === $risk
						? 'Les actions sensibles ne sont pas autorisées pour ce site. Utilise un outil d\'écriture standard ou explique à la personne la modification à faire.'
						: 'Les modifications ne sont pas autorisées pour ce site (niveau d\'agent en lecture seule). Explique à la personne la modification à faire.',
					'is_error' => true,
				);
			} else {
				$preview = Upsidly_Agent_Tool_Registry::preview( $name, $input );
				if ( empty( $preview['ok'] ) ) {
					$fields['status'] = 'failed';
					$fields['title']  = $preview['title'] ?? '';
					$fields['result'] = array(
						'content'  => 'Action impossible, rien n\'a été modifié : ' . ( $preview['error'] ?? 'erreur inconnue' ),
						'is_error' => true,
					);
				} else {
					$fields['status']  = 'pending';
					$fields['title']   = $preview['title'];
					$fields['preview'] = $preview['preview'];
				}
			}
			Upsidly_Agent_Agent_Store::add_action( $id, $fields );
		}
	}

	/**
	 * Actions en attente de validation ?
	 *
	 * @param string $id Conversation.
	 * @return bool
	 */
	public static function has_pending( $id ) {
		foreach ( Upsidly_Agent_Agent_Store::actions( $id ) as $action ) {
			if ( 'pending' === $action['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clôt les actions en attente (arrêt, nouveau message).
	 *
	 * @param string $id     Conversation.
	 * @param string $reason Texte renvoyé à l'IA.
	 */
	private static function close_pending( $id, $reason ) {
		foreach ( Upsidly_Agent_Agent_Store::actions( $id ) as $action ) {
			if ( 'pending' === $action['status'] ) {
				Upsidly_Agent_Agent_Store::update_action(
					$action['id'],
					array(
						'status' => 'rejected',
						'result' => array(
							'content'  => $reason,
							'is_error' => true,
						),
					)
				);
			}
		}
	}

	/**
	 * Blocs tool_result pour toutes les demandes d'un message de l'agent (« interrompue » si sans résultat).
	 *
	 * @param string $id      Conversation.
	 * @param array  $content Contenu assistant.
	 * @return array
	 */
	public static function results_for( $id, array $content ) {
		$by_use = array();
		foreach ( Upsidly_Agent_Agent_Store::actions( $id ) as $action ) {
			$by_use[ $action['tool_use_id'] ] = $action;
		}

		$results = array();
		foreach ( self::tool_uses( $content ) as $use ) {
			$action    = $by_use[ (string) $use['id'] ] ?? null;
			$result    = $action && is_array( $action['result'] ) ? $action['result'] : array(
				'content'  => 'Action non exécutée : interrompue par la personne.',
				'is_error' => true,
			);
			$results[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => (string) $use['id'],
				'content'     => (string) ( $result['content'] ?? '' ),
				'is_error'    => ! empty( $result['is_error'] ),
			);
		}
		return $results;
	}

	/**
	 * Décision de la personne sur une action d'écriture.
	 *
	 * @param int    $action_id Action.
	 * @param string $decision  approve | reject.
	 * @param string $comment   Commentaire facultatif (refus).
	 * @return array|WP_Error
	 */
	public static function decide( $action_id, $decision, $comment = '' ) {
		$action = Upsidly_Agent_Agent_Store::get_action( $action_id );
		if ( ! $action ) {
			return new WP_Error( 'upsidly_agent_action', __( 'Action introuvable.', 'upsidly-agent' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $action['status'] ) {
			return new WP_Error( 'upsidly_agent_action', __( 'Cette action a déjà été traitée.', 'upsidly-agent' ), array( 'status' => 409 ) );
		}
		$id = (string) $action['conversation_id'];

		if ( 'approve' === $decision ) {
			if ( ! Upsidly_Agent_Tool_Registry::allowed( $action['risk'] ) ) {
				return new WP_Error( 'upsidly_agent_write', __( 'Cette modification n\'est pas autorisée pour ce site.', 'upsidly-agent' ), array( 'status' => 403 ) );
			}
			// Marquée tout de suite pour éviter une double application.
			Upsidly_Agent_Agent_Store::update_action( $action['id'], array( 'status' => 'applying' ) );
			$result = Upsidly_Agent_Tool_Registry::apply( $action['tool'], $action['input'], $action['id'] );
			Upsidly_Agent_Agent_Store::update_action(
				$action['id'],
				array(
					'status'     => $result['status'],
					'result'     => array(
						'content'  => $result['content'],
						'is_error' => $result['is_error'],
					),
					'backup_id'  => $result['backup_id'],
					'decided_by' => get_current_user_id(),
				)
			);
			Upsidly_Agent_Log::add(
				array(
					'action' => 'agent',
					'skill'  => $action['tool'],
					'status' => 'applied' === $result['status'] ? 'ok' : 'error',
					'error'  => 'applied' === $result['status'] ? null : $result['content'],
				)
			);
		} else {
			$comment = trim( wp_kses_no_null( wp_check_invalid_utf8( (string) $comment ) ) );
			Upsidly_Agent_Agent_Store::update_action(
				$action['id'],
				array(
					'status'     => 'rejected',
					'result'     => array(
						'content'  => 'Refusé par la personne. Rien n\'a été modifié.' . ( '' !== $comment ? ' Son commentaire : ' . mb_substr( $comment, 0, 2000 ) : '' ),
						'is_error' => true,
					),
					'decided_by' => get_current_user_id(),
				)
			);
		}

		$status = (string) Upsidly_Agent_Agent_Store::get( $id )['status'];
		if ( 'awaiting' === $status && ! self::has_pending( $id ) ) {
			$status = 'running';
			Upsidly_Agent_Agent_Store::update( $id, array( 'status' => $status ) );
		}

		return array(
			'status'  => $status,
			'actions' => self::actions_payload( $id ),
		);
	}

	/**
	 * Annule une action appliquée (restauration de sa sauvegarde).
	 *
	 * @param int $action_id Action.
	 * @return array|WP_Error
	 */
	public static function undo( $action_id ) {
		$action = Upsidly_Agent_Agent_Store::get_action( $action_id );
		if ( ! $action || 'applied' !== $action['status'] || ! $action['backup_id'] ) {
			return new WP_Error( 'upsidly_agent_undo', __( 'Cette modification ne peut pas être annulée.', 'upsidly-agent' ), array( 'status' => 409 ) );
		}
		$restored = Upsidly_Agent_Backup::restore( $action['backup_id'] );
		if ( is_wp_error( $restored ) ) {
			$restored->add_data( array( 'status' => 500 ) );
			return $restored;
		}
		Upsidly_Agent_Agent_Store::update_action( $action['id'], array( 'status' => 'undone' ) );
		Upsidly_Agent_Log::add(
			array(
				'action' => 'undo',
				'skill'  => $action['tool'],
				'status' => 'ok',
			)
		);
		return array( 'actions' => self::actions_payload( (string) $action['conversation_id'] ) );
	}

	/**
	 * Annule toutes les modifications appliquées d'une conversation, dans l'ordre inverse.
	 *
	 * @param string $id Conversation.
	 * @return array|WP_Error
	 */
	public static function undo_all( $id ) {
		$errors = array();
		foreach ( array_reverse( Upsidly_Agent_Agent_Store::actions( $id ) ) as $action ) {
			if ( 'applied' === $action['status'] && $action['backup_id'] ) {
				$result = self::undo( $action['id'] );
				if ( is_wp_error( $result ) ) {
					$errors[] = ( $action['title'] ? $action['title'] : $action['tool'] ) . ' : ' . $result->get_error_message();
				}
			}
		}
		return array(
			'actions' => self::actions_payload( $id ),
			'errors'  => $errors,
		);
	}

	/**
	 * Actions d'une conversation pour l'interface.
	 *
	 * @param string $id Conversation.
	 * @return array<int, array>
	 */
	public static function actions_payload( $id ) {
		return array_map(
			static function ( $action ) {
				return array(
					'id'          => $action['id'],
					'tool_use_id' => $action['tool_use_id'],
					'tool'        => $action['tool'],
					'risk'        => $action['risk'],
					'status'      => $action['status'],
					'title'       => (string) $action['title'],
					'reason'      => is_array( $action['input'] ) ? (string) ( $action['input']['raison'] ?? '' ) : '',
					'preview'     => 'read' === $action['risk'] ? '' : (string) $action['preview'],
					'result'      => is_array( $action['result'] ) ? (string) ( $action['result']['content'] ?? '' ) : '',
					'can_undo'    => 'applied' === $action['status'] && $action['backup_id'] > 0,
				);
			},
			array_values(
				array_filter(
					Upsidly_Agent_Agent_Store::actions( $id ),
					static function ( $action ) {
						return 'read' !== $action['risk'];
					}
				)
			)
		);
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
