<?php
/**
 * Rédaction rapide, côté site : catalogue des types de contenu, préparation des entrées de la skill
 * « rediger-contenu » et enregistrement du résultat produit par l'IA (appelé par Upsidly via le connecteur).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rédaction de contenus (articles, types sur mesure, produits WooCommerce).
 */
class Upsidly_Agent_Writer {

	/**
	 * Types de contenu, taxonomies et champs utilisables pour le formulaire du tableau de bord.
	 *
	 * @return array
	 */
	public static function catalog() {
		return array(
			'contentTypes' => Upsidly_Agent_Content_Types::post_types(),
			'origins'      => Upsidly_Agent_Content_Types::origin_labels(),
			'seoPlugin'    => Upsidly_Agent_Environment::seo_plugin(),
			'woocommerce'  => class_exists( 'WooCommerce' ),
		);
	}

	/**
	 * Vérifie la demande et complète les entrées de la skill (taxonomies, champs, attributs du site).
	 *
	 * @param array  $inputs Entrées saisies (type_contenu, sujet…).
	 * @param array  $terms  Termes choisis : taxonomie => identifiants.
	 * @param string $status draft | pending | publish.
	 * @return array{post_type: string, label: string, status: string, imposed: array<string, int[]>, inputs: array}|WP_Error
	 */
	public static function prepare( array $inputs, array $terms, $status ) {
		$post_type = sanitize_key( (string) ( $inputs['type_contenu'] ?? '' ) );
		$object    = get_post_type_object( $post_type );

		if ( ! $object || ! Upsidly_Agent_Content_Types::is_allowed_type( $post_type ) ) {
			return new WP_Error( 'upsidly_agent_type', __( 'Choisissez un type de contenu que vous pouvez créer.', 'upsidly-agent' ), array( 'status' => 403 ) );
		}

		$status = in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ? $status : 'draft';
		if ( 'publish' === $status && ! current_user_can( $object->cap->publish_posts ) ) {
			return new WP_Error( 'upsidly_agent_publish', __( 'Vous n\'avez pas le droit de publier ce type de contenu : enregistrez-le en brouillon ou en attente de relecture.', 'upsidly-agent' ), array( 'status' => 403 ) );
		}

		// Termes choisis : taxonomies du type, droit d'assigner, termes existants.
		$imposed    = array();
		$taxonomies = array();
		foreach ( Upsidly_Agent_Content_Types::taxonomies_for( $post_type ) as $taxonomy ) {
			$taxonomies[ $taxonomy['slug'] ] = $taxonomy;
		}
		foreach ( $terms as $slug => $ids ) {
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

		// Entrées complétées par le site (jamais fournies par l'utilisateur).
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

		return array(
			'post_type' => $post_type,
			'label'     => $object->labels->singular_name,
			'status'    => $status,
			'imposed'   => $imposed,
			'inputs'    => $inputs,
		);
	}

	/**
	 * Enregistre le contenu produit par l'IA (nettoyé), avec les termes, champs et données produit.
	 *
	 * @param array  $inputs Entrées saisies (mêmes que pour prepare).
	 * @param array  $terms  Termes choisis.
	 * @param string $status Statut demandé.
	 * @param array  $raw    Résultat structuré de la skill.
	 * @return array|WP_Error
	 */
	public static function save( array $inputs, array $terms, $status, array $raw ) {
		$prepared = self::prepare( $inputs, $terms, $status );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$post_type = $prepared['post_type'];

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
			$prepared['status'],
			$prepared['imposed'],
			Upsidly_Agent_Sanitizer::terms( $raw['termes'] ?? array() ),
			Upsidly_Agent_Sanitizer::custom_values( $raw['champs'] ?? array() ),
			Upsidly_Agent_Sanitizer::product( $raw['produit'] ?? array() ),
			$notes
		);

		if ( is_wp_error( $created ) ) {
			Upsidly_Agent_Log::add(
				array(
					'action' => 'run',
					'skill'  => 'rediger-contenu',
					'status' => 'error',
					'error'  => $created->get_error_message(),
				)
			);
			return new WP_Error( 'upsidly_agent_draft_failed', __( 'Le contenu a été généré mais n\'a pas pu être enregistré : ', 'upsidly-agent' ) . $created->get_error_message(), array( 'status' => 500 ) );
		}

		$post_id = (int) $created['post_id'];
		Upsidly_Agent_Log::add(
			array(
				'action'  => 'run',
				'skill'   => 'rediger-contenu',
				'post_id' => $post_id,
				'status'  => 'ok',
			)
		);

		$post_status = get_post_status( $post_id );
		return array(
			'post_id'     => $post_id,
			'edit_url'    => (string) get_edit_post_link( $post_id, 'raw' ),
			'preview_url' => 'publish' === $post_status ? (string) get_permalink( $post_id ) : (string) get_preview_post_link( $post_id ),
			'view_url'    => 'publish' === $post_status ? (string) get_permalink( $post_id ) : '',
			'result'      => array(
				'titre'            => get_the_title( $post_id ),
				'type_contenu'     => $prepared['label'],
				'statut'           => $post_status,
				'extrait'          => $fields['extrait'],
				'meta_description' => $fields['meta_description'],
				'termes'           => $created['terms'],
				'produit'          => 'product' === $post_type ? Upsidly_Agent_Products::summary( $post_id ) : null,
				'notes'            => implode( "\n", $created['notes'] ),
				'contenu_html'     => do_blocks( $cleaned['content'] ),
			),
		);
	}

	/**
	 * Contexte du site pour les prompts (nom, langue).
	 *
	 * @return array{site_name: string, language: string}
	 */
	public static function context() {
		return array(
			'site_name' => (string) get_bloginfo( 'name' ),
			'language'  => (string) get_bloginfo( 'language' ),
		);
	}
}
