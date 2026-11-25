<?php
/**
 * Actions de l'agent sur le site : lecture exécutée tout de suite, écriture en attente de validation,
 * application avec sauvegarde, refus, annulation. Pilotées par Upsidly via le connecteur.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Actions d'une conversation de l'agent.
 */
class Upsidly_Agent_Actions {

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
	 * Arrêt demandé depuis Upsidly : les actions en attente sont clôturées comme refusées.
	 *
	 * @param string $id     Conversation.
	 * @param string $reason Texte renvoyé à l'IA.
	 * @return array
	 */
	public static function stop( $id, $reason = '' ) {
		foreach ( Upsidly_Agent_Agent_Store::actions( $id ) as $action ) {
			if ( 'pending' === $action['status'] ) {
				Upsidly_Agent_Agent_Store::update_action(
					$action['id'],
					array(
						'status' => 'rejected',
						'result' => array(
							'content'  => '' !== $reason ? $reason : 'Arrêté par la personne avant validation. Rien n\'a été modifié.',
							'is_error' => true,
						),
					)
				);
			}
		}
		return array( 'actions' => self::actions_payload( $id ) );
	}
}
