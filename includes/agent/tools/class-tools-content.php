<?php
/**
 * Outils contenus (lecture) : list_content, get_content.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Articles, pages, produits, menus, médias.
 */
class Upsidly_Agent_Tools_Content {

	/**
	 * Liste de contenus.
	 *
	 * @param array $input post_type, search, status, limit.
	 * @return string|WP_Error
	 */
	public static function list_content( array $input ) {
		$type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		if ( ! post_type_exists( $type ) ) {
			return new WP_Error( 'upsidly_agent_type', sprintf( 'Type de contenu inconnu : %s. Types disponibles : %s', $type, implode( ', ', get_post_types() ) ) );
		}

		$query = new WP_Query(
			array(
				'post_type'           => $type,
				'post_status'         => ! empty( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : array_values( array_diff( get_post_stati(), array( 'trash', 'auto-draft' ) ) ),
				's'                   => (string) ( $input['search'] ?? '' ),
				'posts_per_page'      => max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) ),
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'        => $post->ID,
				'titre'     => get_the_title( $post ),
				'statut'    => $post->post_status,
				'date'      => $post->post_date,
				'modifie'   => $post->post_modified,
				'auteur'    => get_the_author_meta( 'display_name', (int) $post->post_author ),
				'parent'    => (int) $post->post_parent,
				'lien'      => get_permalink( $post ),
				'elementor' => 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ),
			);
		}

		return Upsidly_Agent_Guard::to_result(
			array(
				'type'    => $type,
				'total'   => (int) $query->found_posts,
				'affiche' => count( $items ),
				'items'   => $items,
			)
		);
	}

	/**
	 * Détail d'un contenu.
	 *
	 * @param array $input id.
	 * @return string|WP_Error
	 */
	public static function get_content( array $input ) {
		$post = get_post( (int) ( $input['id'] ?? 0 ) );
		if ( ! $post ) {
			return new WP_Error( 'upsidly_agent_post', __( 'Contenu introuvable.', 'upsidly-agent' ) );
		}

		$meta = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			$value = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
			if ( is_string( $value ) && strlen( $value ) > 2000 ) {
				$value = mb_substr( $value, 0, 2000 ) . ' [… tronqué]';
			}
			$meta[ $key ] = $value;
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$names = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) && $names ) {
				$terms[ $taxonomy ] = $names;
			}
		}

		$editor = 'classique';
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$editor = 'elementor';
		} elseif ( has_blocks( $post ) ) {
			$editor = 'blocs';
		}

		return Upsidly_Agent_Guard::to_result(
			array(
				'id'         => $post->ID,
				'type'       => $post->post_type,
				'titre'      => $post->post_title,
				'statut'     => $post->post_status,
				'slug'       => $post->post_name,
				'parent'     => (int) $post->post_parent,
				'date'       => $post->post_date,
				'modifie'    => $post->post_modified,
				'lien'       => get_permalink( $post ),
				'modele'     => get_page_template_slug( $post ),
				'editeur'    => $editor,
				'extrait'    => $post->post_excerpt,
				'contenu'    => mb_substr( $post->post_content, 0, 12000 ) . ( mb_strlen( $post->post_content ) > 12000 ? ' [… tronqué]' : '' ),
				'taxonomies' => $terms,
				'metas'      => $meta,
			)
		);
	}
}
