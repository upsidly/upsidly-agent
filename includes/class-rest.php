<?php
/**
 * Endpoints REST internes utilisés par l'interface React.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Namespace upsidly-agent/v1 sur le site client. Aucun appel au proxy depuis le navigateur.
 */
class Upsidly_Agent_Rest {

	const NAMESPACE_V1 = 'upsidly-agent/v1';

	/**
	 * Client du proxy (remplaçable pour les tests).
	 *
	 * @var Upsidly_Agent_Client|null
	 */
	public static $client = null;

	/**
	 * Branche les hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Client du proxy.
	 *
	 * @return Upsidly_Agent_Client
	 */
	public static function client() {
		if ( null === self::$client ) {
			self::$client = new Upsidly_Agent_Client();
		}
		return self::$client;
	}

	/**
	 * Déclare les routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
				'args'                => array(
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'can_run' ),
				'args'                => array(
					'skill'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'inputs'  => array(
						'type'    => 'object',
						'default' => array(),
					),
					'page_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'status'  => array(
						'type'    => 'string',
						'default' => 'draft',
						'enum'    => array( 'draft', 'pending', 'publish' ),
					),
					'terms'   => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/diff/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_diff' ),
				'permission_callback' => array( __CLASS__, 'can_edit_draft' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/apply/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'apply' ),
				'permission_callback' => array( __CLASS__, 'can_apply' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_history' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			)
		);
	}

	/**
	 * Capacité edit_posts.
	 *
	 * @return bool
	 */
	public static function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Capacités pour /run : edit_posts, et edit_pages pour creer-page.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return bool
	 */
	public static function can_run( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		if ( 'creer-page' === (string) $request->get_param( 'skill' ) && ! current_user_can( 'edit_pages' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Capacité edit_post sur le brouillon.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return bool
	 */
	public static function can_edit_draft( $request ) {
		$id = (int) $request['id'];
		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	/**
	 * Capacités pour /apply : publish_pages et edit_post sur la page d'origine.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return bool
	 */
	public static function can_apply( $request ) {
		$draft_id  = (int) $request['id'];
		$source_id = $draft_id > 0 ? (int) get_post_meta( $draft_id, Upsidly_Agent_Drafts::META_SOURCE_PAGE, true ) : 0;

		return current_user_can( 'publish_pages' )
			&& $source_id > 0
			&& current_user_can( 'edit_post', $source_id )
			&& current_user_can( 'edit_post', $draft_id );
	}

	/**
	 * GET /status : réponse de /v1/status du proxy (cache 5 minutes).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_status( $request ) {
		$status = self::status( (bool) $request->get_param( 'refresh' ) );
		return is_wp_error( $status ) ? $status : rest_ensure_response( $status );
	}

	/**
	 * Statut du proxy, avec cache.
	 *
	 * @param bool $refresh Forcer l'appel.
	 * @return array|WP_Error
	 */
	public static function status( $refresh = false ) {
		if ( '' === Upsidly_Agent_Settings::get_key() ) {
			return new WP_Error( 'upsidly_agent_no_key', __( 'Aucune clé n\'est configurée. Renseignez-la dans Réglages.', 'upsidly-agent' ), array( 'status' => 400 ) );
		}

		if ( ! $refresh ) {
			$cached = get_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$status = self::client()->status();
		if ( ! is_wp_error( $status ) ) {
			set_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT, $status, 5 * MINUTE_IN_SECONDS );
		}
		return $status;
	}

	/**
	 * POST /run : complète les entrées, appelle le proxy, nettoie et crée le brouillon.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run( $request ) {
		if ( 'rediger-contenu' === (string) $request->get_param( 'skill' ) ) {
			return self::run_content( $request );
		}

		$skill   = (string) $request->get_param( 'skill' );
		$inputs  = $request->get_param( 'inputs' );
		$inputs  = is_array( $inputs ) ? $inputs : array();
		$page_id = (int) $request->get_param( 'page_id' );
		$source  = null;

		// Champs remplis uniquement côté serveur.
		unset( $inputs['categories_existantes'], $inputs['titre_actuel'], $inputs['contenu_actuel'] );

		if ( 'rediger-article' === $skill ) {
			$inputs['categories_existantes'] = self::category_names( 50 );
		}

		if ( 'creer-page' === $skill && 'modifier' === ( $inputs['mode'] ?? '' ) ) {
			$source = self::source_page( $page_id );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$inputs['titre_actuel']   = $source->post_title;
			$inputs['contenu_actuel'] = $source->post_content;
		}

		$response = self::client()->run( $skill, $inputs, self::context() );

		if ( is_wp_error( $response ) ) {
			$data = (array) $response->get_error_data();
			Upsidly_Agent_Log::add(
				array(
					'action'         => 'run',
					'skill'          => $skill,
					'source_page_id' => $source ? $source->ID : null,
					'status'         => 'error',
					'credits'        => 0,
					'error'          => $response->get_error_code() . ' : ' . $response->get_error_message(),
				)
			);
			if ( 'uap_quota_exceeded' === $response->get_error_code() || 'uap_incomplete' === $response->get_error_code() ) {
				delete_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );
			}
			return new WP_Error( $response->get_error_code(), $response->get_error_message(), $data );
		}

		$credits = isset( $response['credits'] ) && is_array( $response['credits'] ) ? $response['credits'] : array();
		$charged = isset( $credits['charged'] ) ? (int) $credits['charged'] : 0;
		$raw     = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();

		$fields  = Upsidly_Agent_Sanitizer::fields( $raw );
		$cleaned = Upsidly_Agent_Sanitizer::content( $raw['contenu'] ?? '' );
		$notes   = '' !== $fields['notes'] ? array( $fields['notes'] ) : array();
		if ( $cleaned['removed'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: nombre de blocs. */
				_n( '%d bloc non pris en charge a été retiré.', '%d blocs non pris en charge ont été retirés.', $cleaned['removed'], 'upsidly-agent' ),
				$cleaned['removed']
			);
		}

		$created = 'creer-page' === $skill
			? Upsidly_Agent_Drafts::create_page( $fields, $cleaned['content'], $source, $notes )
			: Upsidly_Agent_Drafts::create_article( $fields, $cleaned['content'], $notes );

		delete_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );

		if ( is_wp_error( $created ) ) {
			Upsidly_Agent_Log::add(
				array(
					'action'         => 'run',
					'skill'          => $skill,
					'source_page_id' => $source ? $source->ID : null,
					'status'         => 'error',
					'credits'        => $charged,
					'error'          => $created->get_error_message(),
				)
			);
			return new WP_Error(
				'upsidly_agent_draft_failed',
				__( 'Le contenu a été généré mais le brouillon n\'a pas pu être créé.', 'upsidly-agent' ),
				array(
					'status'  => 500,
					'credits' => $credits,
				)
			);
		}

		$post_id = (int) $created['post_id'];
		Upsidly_Agent_Log::add(
			array(
				'action'         => 'run',
				'skill'          => $skill,
				'post_id'        => $post_id,
				'source_page_id' => $source ? $source->ID : null,
				'status'         => 'ok',
				'credits'        => $charged,
			)
		);

		return rest_ensure_response(
			array(
				'post_id'        => $post_id,
				'edit_url'       => (string) get_edit_post_link( $post_id, 'raw' ),
				'preview_url'    => (string) get_preview_post_link( $post_id ),
				'result'         => array(
					'titre'              => get_the_title( $post_id ),
					'extrait'            => $fields['extrait'],
					'meta_description'   => $fields['meta_description'],
					'categorie'          => $fields['categorie'],
					'etiquettes'         => $fields['etiquettes'],
					'notes'              => implode( "\n", $created['notes'] ),
					'resume_changements' => $fields['resume_changements'],
					'contenu_html'       => do_blocks( $cleaned['content'] ),
				),
				'source_page_id' => $source ? $source->ID : null,
				'credits'        => $credits,
			)
		);
	}

	/**
	 * POST /run pour « rediger-contenu » : type de contenu, taxonomies, champs personnalisés, produit.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_content( $request ) {
		$inputs    = $request->get_param( 'inputs' );
		$inputs    = is_array( $inputs ) ? $inputs : array();
		$post_type = sanitize_key( (string) ( $inputs['type_contenu'] ?? '' ) );
		$object    = get_post_type_object( $post_type );

		if ( ! $object || ! Upsidly_Agent_Content_Types::is_allowed_type( $post_type ) ) {
			return new WP_Error( 'upsidly_agent_type', __( 'Choisissez un type de contenu que vous pouvez créer.', 'upsidly-agent' ), array( 'status' => 403 ) );
		}

		// Statut : publication directe seulement si l'utilisateur peut publier ce type.
		$status = (string) $request->get_param( 'status' );
		if ( 'publish' === $status && ! current_user_can( $object->cap->publish_posts ) ) {
			return new WP_Error( 'upsidly_agent_publish', __( 'Vous n\'avez pas le droit de publier ce type de contenu : enregistrez-le en brouillon ou en attente de relecture.', 'upsidly-agent' ), array( 'status' => 403 ) );
		}

		// Termes choisis par l'utilisateur : taxonomies du type, droit d'assigner, termes existants.
		$imposed    = array();
		$taxonomies = array();
		foreach ( Upsidly_Agent_Content_Types::taxonomies_for( $post_type ) as $taxonomy ) {
			$taxonomies[ $taxonomy['slug'] ] = $taxonomy;
		}
		foreach ( (array) $request->get_param( 'terms' ) as $slug => $ids ) {
			$slug = sanitize_key( (string) $slug );
			if ( ! isset( $taxonomies[ $slug ] ) || ! $taxonomies[ $slug ]['canAssign'] ) {
				continue;
			}
			foreach ( (array) $ids as $id ) {
				$term = get_term( (int) $id, $slug );
				if ( $term && ! is_wp_error( $term ) ) {
					$imposed[ $slug ][] = (int) $term->term_id;
				}
			}
		}

		// Entrées complétées côté serveur.
		unset( $inputs['taxonomies'], $inputs['champs'], $inputs['attributs_existants'] );
		$inputs['type_contenu'] = $post_type;
		$inputs['type_libelle'] = $object->labels->singular_name;
		$inputs['taxonomies']   = Upsidly_Agent_Content_Types::taxonomies_prompt( $post_type, $imposed );
		$inputs['champs']       = Upsidly_Agent_Content_Types::fields_prompt( $post_type );
		if ( 'product' === $post_type ) {
			$inputs['attributs_existants'] = Upsidly_Agent_Content_Types::attributes_prompt();
		}
		foreach ( array( 'taxonomies', 'champs', 'attributs_existants' ) as $key ) {
			if ( isset( $inputs[ $key ] ) && '' === $inputs[ $key ] ) {
				unset( $inputs[ $key ] );
			}
		}

		$response = self::client()->run( 'rediger-contenu', $inputs, self::context() );

		if ( is_wp_error( $response ) ) {
			Upsidly_Agent_Log::add(
				array(
					'action'  => 'run',
					'skill'   => 'rediger-contenu',
					'status'  => 'error',
					'credits' => 0,
					'error'   => $response->get_error_code() . ' : ' . $response->get_error_message(),
				)
			);
			if ( in_array( $response->get_error_code(), array( 'uap_quota_exceeded', 'uap_incomplete' ), true ) ) {
				delete_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );
			}
			return new WP_Error( $response->get_error_code(), $response->get_error_message(), (array) $response->get_error_data() );
		}

		$credits = isset( $response['credits'] ) && is_array( $response['credits'] ) ? $response['credits'] : array();
		$charged = isset( $credits['charged'] ) ? (int) $credits['charged'] : 0;
		$raw     = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();

		$fields  = Upsidly_Agent_Sanitizer::fields( $raw );
		$cleaned = Upsidly_Agent_Sanitizer::content( $raw['contenu'] ?? '' );
		$notes   = '' !== $fields['notes'] ? array( $fields['notes'] ) : array();
		if ( $cleaned['removed'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: nombre de blocs. */
				_n( '%d bloc non pris en charge a été retiré.', '%d blocs non pris en charge ont été retirés.', $cleaned['removed'], 'upsidly-agent' ),
				$cleaned['removed']
			);
		}

		$created = Upsidly_Agent_Drafts::create_content(
			$post_type,
			$fields,
			$cleaned['content'],
			$status,
			$imposed,
			Upsidly_Agent_Sanitizer::terms( $raw['termes'] ?? array() ),
			Upsidly_Agent_Sanitizer::custom_values( $raw['champs'] ?? array() ),
			Upsidly_Agent_Sanitizer::product( $raw['produit'] ?? array() ),
			$notes
		);
		delete_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );

		if ( is_wp_error( $created ) ) {
			Upsidly_Agent_Log::add(
				array(
					'action'  => 'run',
					'skill'   => 'rediger-contenu',
					'status'  => 'error',
					'credits' => $charged,
					'error'   => $created->get_error_message(),
				)
			);
			return new WP_Error(
				'upsidly_agent_draft_failed',
				__( 'Le contenu a été généré mais n\'a pas pu être enregistré : ', 'upsidly-agent' ) . $created->get_error_message(),
				array(
					'status'  => 500,
					'credits' => $credits,
				)
			);
		}

		$post_id = (int) $created['post_id'];
		Upsidly_Agent_Log::add(
			array(
				'action'  => 'run',
				'skill'   => 'rediger-contenu',
				'post_id' => $post_id,
				'status'  => 'ok',
				'credits' => $charged,
			)
		);

		$post_status = get_post_status( $post_id );
		return rest_ensure_response(
			array(
				'post_id'        => $post_id,
				'edit_url'       => (string) get_edit_post_link( $post_id, 'raw' ),
				'preview_url'    => 'publish' === $post_status ? (string) get_permalink( $post_id ) : (string) get_preview_post_link( $post_id ),
				'view_url'       => 'publish' === $post_status ? (string) get_permalink( $post_id ) : '',
				'result'         => array(
					'titre'            => get_the_title( $post_id ),
					'type_contenu'     => $object->labels->singular_name,
					'statut'           => $post_status,
					'extrait'          => $fields['extrait'],
					'meta_description' => $fields['meta_description'],
					'termes'           => $created['terms'],
					'produit'          => 'product' === $post_type ? Upsidly_Agent_Products::summary( $post_id ) : null,
					'notes'            => implode( "\n", $created['notes'] ),
					'contenu_html'     => do_blocks( $cleaned['content'] ),
				),
				'source_page_id' => null,
				'credits'        => $credits,
			)
		);
	}

	/**
	 * Page d'origine pour une modification : existante, de type page, modifiable, non Elementor.
	 *
	 * @param int $page_id Page.
	 * @return WP_Post|WP_Error
	 */
	private static function source_page( $page_id ) {
		$page = $page_id > 0 ? get_post( $page_id ) : null;

		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return new WP_Error( 'upsidly_agent_no_page', __( 'Choisissez une page existante à modifier.', 'upsidly-agent' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $page->ID ) ) {
			return new WP_Error( 'upsidly_agent_forbidden', __( 'Vous n\'avez pas le droit de modifier cette page.', 'upsidly-agent' ), array( 'status' => 403 ) );
		}
		if ( Upsidly_Agent_Drafts::is_elementor( $page->ID ) ) {
			return new WP_Error( 'upsidly_agent_elementor', __( 'Cette page est construite avec Elementor : elle ne peut pas être modifiée par l\'assistant.', 'upsidly-agent' ), array( 'status' => 400 ) );
		}
		return $page;
	}

	/**
	 * Contexte du site envoyé au proxy.
	 *
	 * @return array{site_name: string, site_description: string, brand_voice: string, language: string}
	 */
	public static function context() {
		return array(
			'site_name'        => (string) get_bloginfo( 'name' ),
			'site_description' => Upsidly_Agent_Settings::get_site_description(),
			'brand_voice'      => Upsidly_Agent_Settings::get_brand_voice(),
			'language'         => substr( get_locale(), 0, 2 ),
		);
	}

	/**
	 * Noms des catégories existantes.
	 *
	 * @param int $limit Nombre maximal.
	 * @return string[]
	 */
	public static function category_names( $limit ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => (int) $limit,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'fields'     => 'names',
			)
		);
		return is_wp_error( $terms ) ? array() : array_values( array_map( 'strval', $terms ) );
	}

	/**
	 * GET /diff/{id}.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_diff( $request ) {
		$diff = Upsidly_Agent_Drafts::diff( (int) $request['id'] );
		if ( is_wp_error( $diff ) ) {
			return $diff;
		}
		return rest_ensure_response(
			array(
				'draft_id'       => (int) $request['id'],
				'source_page_id' => (int) get_post_meta( (int) $request['id'], Upsidly_Agent_Drafts::META_SOURCE_PAGE, true ),
				'html'           => $diff,
			)
		);
	}

	/**
	 * POST /apply/{id}.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function apply( $request ) {
		$draft_id = (int) $request['id'];
		$result   = Upsidly_Agent_Drafts::apply( $draft_id );

		Upsidly_Agent_Log::add(
			array(
				'action'         => 'apply',
				'skill'          => (string) get_post_meta( $draft_id, Upsidly_Agent_Drafts::META_SKILL, true ),
				'post_id'        => is_wp_error( $result ) ? null : $result['page_id'],
				'source_page_id' => (int) get_post_meta( $draft_id, Upsidly_Agent_Drafts::META_SOURCE_PAGE, true ),
				'status'         => is_wp_error( $result ) ? 'error' : 'ok',
				'credits'        => 0,
				'error'          => is_wp_error( $result ) ? $result->get_error_message() : null,
			)
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /history.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_history() {
		return rest_ensure_response( Upsidly_Agent_Log::for_current_user( 50 ) );
	}
}
