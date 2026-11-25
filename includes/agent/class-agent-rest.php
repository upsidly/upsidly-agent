<?php
/**
 * Endpoints REST internes de l'agent.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes upsidly-agent/v1/agent/*.
 */
class Upsidly_Agent_Agent_Rest {

	const CAPABILITY  = 'upsidly_agent_use';
	const ACK_META    = 'upsidly_agent_ack';
	const MAX_MESSAGE = 8000;

	/**
	 * Branche les hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * L'agent est-il utilisable par l'utilisateur courant ? (capacité + réglage du site).
	 *
	 * @return bool
	 */
	public static function can_use() {
		return current_user_can( self::CAPABILITY ) && Upsidly_Agent_Settings::agent_enabled() && '' !== Upsidly_Agent_Settings::get_key();
	}

	/**
	 * Déclare les routes.
	 */
	public static function register_routes() {
		$ns = Upsidly_Agent_Rest::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/agent/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_conversations' ),
					'permission_callback' => array( __CLASS__, 'can_use' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_conversation' ),
					'permission_callback' => array( __CLASS__, 'can_use' ),
					'args'                => array(
						'message' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		$id = '(?P<id>[a-z0-9]{26})';

		register_rest_route(
			$ns,
			'/agent/conversations/' . $id,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_conversation' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);
		register_rest_route(
			$ns,
			'/agent/conversations/' . $id . '/messages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_message' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/agent/conversations/' . $id . '/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'step' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);
		register_rest_route(
			$ns,
			'/agent/conversations/' . $id . '/stop',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stop' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);
		register_rest_route(
			$ns,
			'/agent/ack',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ack' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);
	}

	/**
	 * Accès à une conversation : agent utilisable et conversation de l'utilisateur.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return bool
	 */
	public static function can_access( $request ) {
		if ( ! self::can_use() ) {
			return false;
		}
		$conversation = Upsidly_Agent_Agent_Store::get( (string) $request['id'] );
		return $conversation && get_current_user_id() === (int) $conversation['user_id'];
	}

	/**
	 * Message de l'utilisateur, nettoyé.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return string|WP_Error
	 */
	private static function message( $request ) {
		$text = trim( wp_kses_no_null( wp_check_invalid_utf8( (string) $request->get_param( 'message' ) ) ) );
		if ( '' === $text ) {
			return new WP_Error( 'upsidly_agent_empty', __( 'Écrivez votre demande.', 'upsidly-agent' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > self::MAX_MESSAGE ) {
			return new WP_Error( 'upsidly_agent_too_long', __( 'Message trop long (8000 caractères maximum).', 'upsidly-agent' ), array( 'status' => 400 ) );
		}
		if ( ! get_user_meta( get_current_user_id(), self::ACK_META, true ) ) {
			return new WP_Error( 'upsidly_agent_ack', __( 'Acceptez d\'abord l\'avertissement de l\'agent.', 'upsidly-agent' ), array( 'status' => 403 ) );
		}
		return $text;
	}

	/**
	 * GET /agent/conversations.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_conversations() {
		return rest_ensure_response( Upsidly_Agent_Agent_Store::for_user( get_current_user_id(), 50 ) );
	}

	/**
	 * POST /agent/conversations : crée une conversation avec un premier message.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_conversation( $request ) {
		$text = self::message( $request );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$id     = Upsidly_Agent_Agent_Store::create( get_current_user_id(), wp_trim_words( $text, 12, '…' ) );
		$result = Upsidly_Agent_Agent_Loop::add_user_message( $id, $text );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( self::conversation_payload( $id ) );
	}

	/**
	 * GET /agent/conversations/{id}.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function get_conversation( $request ) {
		return rest_ensure_response( self::conversation_payload( (string) $request['id'] ) );
	}

	/**
	 * POST /agent/conversations/{id}/messages.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_message( $request ) {
		$text = self::message( $request );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$result = Upsidly_Agent_Agent_Loop::add_user_message( (string) $request['id'], $text );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( self::conversation_payload( (string) $request['id'] ) );
	}

	/**
	 * POST /agent/conversations/{id}/step.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function step( $request ) {
		$result = Upsidly_Agent_Agent_Loop::step( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /agent/conversations/{id}/stop.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stop( $request ) {
		$result = Upsidly_Agent_Agent_Loop::stop( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /agent/ack : avertissement accepté par l'administrateur.
	 *
	 * @return WP_REST_Response
	 */
	public static function ack() {
		update_user_meta( get_current_user_id(), self::ACK_META, gmdate( 'Y-m-d H:i:s' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Conversation et ses messages.
	 *
	 * @param string $id Conversation.
	 * @return array
	 */
	private static function conversation_payload( $id ) {
		$conversation = Upsidly_Agent_Agent_Store::get( $id );
		return array(
			'conversation' => $conversation ? array(
				'id'      => $conversation['id'],
				'title'   => $conversation['title'],
				'status'  => $conversation['status'],
				'credits' => (int) $conversation['credits'],
				'steps'   => (int) $conversation['steps'],
			) : null,
			'messages'     => Upsidly_Agent_Agent_Store::messages( $id ),
		);
	}
}
