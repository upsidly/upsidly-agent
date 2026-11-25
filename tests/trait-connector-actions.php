<?php
/**
 * Aide aux tests de l'agent : actions proposées, validées et annulées via le connecteur (comme depuis Upsidly).
 *
 * @package Upsidly_Agent
 */

/**
 * Pilotage des actions par Upsidly::dispatch().
 */
trait Upsidly_Agent_Test_Connector_Actions {

	/**
	 * Dernières demandes d'outils par conversation.
	 *
	 * @var array<string, array>
	 */
	private $uses_by_conversation = array();

	/**
	 * Niveau d'agent de la demande (fixé par le test via Tool_Registry::set_level, « write » sinon).
	 *
	 * @var string
	 */
	private $level = 'write';

	/**
	 * Opération du connecteur.
	 *
	 * @param array $payload Corps.
	 * @return array|WP_Error
	 */
	private function dispatch( array $payload ) {
		return Upsidly_Agent_Connector::dispatch( $payload + array( 'level' => $this->level ) );
	}

	/**
	 * L'agent demande un outil : lecture exécutée, écriture en attente (ou refusée).
	 *
	 * @param string $name  Outil.
	 * @param array  $input Entrées.
	 * @return array{id: string, step: array, action: array|null}
	 */
	private function propose( $name, array $input ) {
		$id                                = Upsidly_Agent_Agent_Store::new_id();
		$uses                              = array(
			array(
				'id'    => 'toolu_' . wp_generate_password( 8, false ),
				'name'  => $name,
				'input' => $input,
			),
		);
		$this->uses_by_conversation[ $id ] = $uses;
		$step                              = $this->dispatch(
			array(
				'op'              => 'tools.run',
				'conversation_id' => $id,
				'uses'            => $uses,
			)
		);
		if ( ! $step['actions'] ) {
			$stored = Upsidly_Agent_Agent_Store::actions( $id );
			$step['actions'] = array( array( 'status' => $stored[0]['status'] ?? '' ) );
		}

		return array(
			'id'     => $id,
			'step'   => $step,
			'action' => $step['actions'][0] ?? null,
		);
	}

	/**
	 * Ancienne API REST de l'interface, désormais servie par le connecteur.
	 *
	 * @param string $method Méthode (ignorée).
	 * @param string $route  Route.
	 * @param array  $body   Corps.
	 * @return WP_REST_Response
	 */
	private function rest( $method, $route, array $body = array() ) {
		unset( $method );
		$result = null;
		if ( preg_match( '#^/agent/actions/(\d+)/decide$#', $route, $m ) ) {
			$result = $this->dispatch(
				array(
					'op'        => 'actions.decide',
					'action_id' => (int) $m[1],
					'decision'  => (string) ( $body['decision'] ?? '' ),
					'comment'   => (string) ( $body['comment'] ?? '' ),
				)
			);
		} elseif ( preg_match( '#^/agent/actions/(\d+)/undo$#', $route, $m ) ) {
			$result = $this->dispatch(
				array(
					'op'        => 'actions.undo',
					'action_id' => (int) $m[1],
				)
			);
		} elseif ( preg_match( '#^/agent/conversations/([a-z0-9]+)/(undo-all|stop|step)$#', $route, $m ) ) {
			$ops    = array(
				'undo-all' => 'actions.undo_all',
				'stop'     => 'actions.stop',
				'step'     => 'actions.list',
			);
			$result = $this->dispatch(
				array(
					'op'              => $ops[ $m[2] ],
					'conversation_id' => $m[1],
					'uses'            => $this->uses_by_conversation[ $m[1] ] ?? array(),
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return new WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				(int) ( $data['status'] ?? 400 )
			);
		}
		return new WP_REST_Response( $result, null === $result ? 404 : 200 );
	}

	/**
	 * Résultat d'outil renvoyé à l'IA pour la dernière conversation.
	 *
	 * @return array
	 */
	private function last_tool_result() {
		$id    = array_key_last( $this->uses_by_conversation );
		$state = $this->dispatch(
			array(
				'op'              => 'actions.list',
				'conversation_id' => $id,
				'uses'            => $this->uses_by_conversation[ $id ],
			)
		);
		return $state['results'][0];
	}
}
