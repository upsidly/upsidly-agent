<?php
/**
 * Création des brouillons, révisions de page et application.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brouillons générés. Rien n'est jamais publié automatiquement.
 */
class Upsidly_Agent_Drafts {

	const META_GENERATED   = '_upsidly_agent_generated';
	const META_SKILL       = '_upsidly_agent_skill';
	const META_NOTES       = '_upsidly_agent_notes';
	const META_SOURCE_PAGE = '_upsidly_agent_source_page';
	const META_TITLE       = '_upsidly_agent_title';

	/**
	 * Page construite avec Elementor ?
	 *
	 * @param int $post_id Contenu.
	 * @return bool
	 */
	public static function is_elementor( $post_id ) {
		return 'builder' === get_post_meta( (int) $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Crée un article en brouillon (rediger-article).
	 *
	 * @param array  $fields  Champs nettoyés (Upsidly_Agent_Sanitizer::fields()).
	 * @param string $content Contenu nettoyé.
	 * @param array  $notes   Notes de départ.
	 * @return array{post_id: int, notes: string[]}|WP_Error
	 */
	public static function create_article( array $fields, $content, array $notes = array() ) {
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
					'post_title'   => '' !== $fields['titre'] ? $fields['titre'] : __( 'Article sans titre', 'upsidly-agent' ),
					'post_name'    => $fields['slug'],
					'post_content' => $content,
					'post_excerpt' => $fields['extrait'],
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$notes = array_merge( $notes, self::assign_category( $post_id, $fields['categorie'] ) );
		$notes = array_merge( $notes, self::assign_tags( $post_id, $fields['etiquettes'] ) );

		self::finalize( $post_id, 'rediger-article', $fields, $notes );

		return array(
			'post_id' => $post_id,
			'notes'   => $notes,
		);
	}

	/**
	 * Crée un contenu de n'importe quel type (rediger-contenu) : article, produit WooCommerce ou type sur mesure.
	 *
	 * @param string $post_type Type de contenu (déjà autorisé pour l'utilisateur).
	 * @param array  $fields    Champs nettoyés.
	 * @param string $content   Contenu nettoyé.
	 * @param string $status    draft | pending | publish (déjà validé).
	 * @param array  $imposed   Taxonomie => identifiants de termes choisis par l'utilisateur (déjà validés).
	 * @param array  $suggested Taxonomie => noms de termes proposés par l'IA.
	 * @param array  $values    Champs personnalisés proposés par l'IA (nom => texte).
	 * @param array  $product   Données produit nettoyées (si produit).
	 * @param array  $notes     Notes de départ.
	 * @return array{post_id: int, notes: string[], terms: array<string, string[]>}|WP_Error
	 */
	public static function create_content( $post_type, array $fields, $content, $status, array $imposed, array $suggested, array $values, array $product, array $notes = array() ) {
		if ( 'product' === $post_type ) {
			$post_id = Upsidly_Agent_Products::create( $fields, $content, $product, $status, $notes );
		} else {
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => $post_type,
						'post_status'  => $status,
						'post_author'  => get_current_user_id(),
						'post_title'   => '' !== $fields['titre'] ? $fields['titre'] : __( 'Contenu sans titre', 'upsidly-agent' ),
						'post_name'    => $fields['slug'],
						'post_content' => $content,
						'post_excerpt' => $fields['extrait'],
					)
				),
				true
			);
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$assigned = self::assign_terms( $post_id, $post_type, $imposed, $suggested, $notes );
		self::write_custom_fields( $post_id, $post_type, $values, $notes );
		self::finalize( $post_id, 'rediger-contenu', $fields, $notes );

		return array(
			'post_id' => (int) $post_id,
			'notes'   => $notes,
			'terms'   => $assigned,
		);
	}

	/**
	 * Termes : choisis par l'utilisateur + proposés par l'IA (existants, ou créés si l'utilisateur en a le droit).
	 *
	 * @param int    $post_id   Contenu.
	 * @param string $post_type Type.
	 * @param array  $imposed   Taxonomie => identifiants.
	 * @param array  $suggested Taxonomie => noms.
	 * @param array  $notes     Notes (par référence).
	 * @return array<string, string[]> Libellé de taxonomie => noms des termes assignés.
	 */
	private static function assign_terms( $post_id, $post_type, array $imposed, array $suggested, array &$notes ) {
		$assigned = array();

		foreach ( Upsidly_Agent_Content_Types::taxonomies_for( $post_type ) as $taxonomy ) {
			$slug = $taxonomy['slug'];
			$ids  = array_map( 'intval', $imposed[ $slug ] ?? array() );

			foreach ( $suggested[ $slug ] ?? array() as $name ) {
				$term = get_term_by( 'name', $name, $slug );
				if ( $term ) {
					$ids[] = (int) $term->term_id;
				} elseif ( $taxonomy['canAssign'] && $taxonomy['canCreate'] ) {
					$created = wp_insert_term( $name, $slug );
					if ( ! is_wp_error( $created ) ) {
						$ids[] = (int) $created['term_id'];
					}
				} else {
					$notes[] = sprintf(
						/* translators: 1: taxonomie, 2: terme. */
						__( '%1$s suggéré (non créé) : %2$s', 'upsidly-agent' ),
						$taxonomy['label'],
						$name
					);
				}
			}

			$ids = array_values( array_unique( array_filter( $ids ) ) );
			if ( ! $ids || ! $taxonomy['canAssign'] ) {
				continue;
			}
			wp_set_object_terms( $post_id, $ids, $slug, false );

			$names = array();
			foreach ( $ids as $id ) {
				$term = get_term( $id, $slug );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = $term->name;
				}
			}
			$assigned[ $taxonomy['label'] ] = $names;
		}

		return $assigned;
	}

	/**
	 * Champs personnalisés texte (ACF, JetEngine).
	 *
	 * @param int    $post_id   Contenu.
	 * @param string $post_type Type.
	 * @param array  $values    Nom => texte.
	 * @param array  $notes     Notes (par référence).
	 */
	private static function write_custom_fields( $post_id, $post_type, array $values, array &$notes ) {
		$missing = array();

		foreach ( Upsidly_Agent_Content_Types::custom_fields( $post_type ) as $field ) {
			$value = $values[ $field['name'] ] ?? '';
			if ( '' === trim( $value ) ) {
				$missing[] = $field['label'];
				continue;
			}

			if ( 'wysiwyg' === $field['type'] ) {
				$value = wp_kses_post( $value );
			} elseif ( 'textarea' === $field['type'] ) {
				$value = sanitize_textarea_field( $value );
			} else {
				$value = sanitize_text_field( $value );
			}

			if ( 'acf' === $field['source'] && function_exists( 'update_field' ) ) {
				update_field( $field['key'], $value, $post_id );
			} else {
				update_post_meta( $post_id, $field['name'], wp_slash( $value ) );
			}
		}

		if ( $missing ) {
			$notes[] = sprintf(
				/* translators: %s: liste de champs. */
				__( 'Champs à compléter : %s', 'upsidly-agent' ),
				implode( ', ', $missing )
			);
		}
	}

	/**
	 * Crée une page en brouillon (creer-page). En mode modifier : nouvelle page « (révision IA) », l'original n'est pas touché.
	 *
	 * @param array        $fields  Champs nettoyés.
	 * @param string       $content Contenu nettoyé.
	 * @param WP_Post|null $source  Page d'origine (mode modifier), sinon null.
	 * @param array        $notes   Notes de départ.
	 * @return array{post_id: int, notes: string[]}|WP_Error
	 */
	public static function create_page( array $fields, $content, $source = null, array $notes = array() ) {
		$title = '' !== $fields['titre'] ? $fields['titre'] : ( $source ? $source->post_title : __( 'Page sans titre', 'upsidly-agent' ) );

		$postarr = array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_title'   => $title,
			'post_content' => $content,
		);
		if ( $source instanceof WP_Post ) {
			$postarr['post_title'] = sprintf(
				/* translators: %s: titre de la page. */
				__( '%s (révision IA)', 'upsidly-agent' ),
				$title
			);
			$postarr['post_parent'] = (int) $source->post_parent;
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $source instanceof WP_Post ) {
			update_post_meta( $post_id, self::META_SOURCE_PAGE, (int) $source->ID );
			update_post_meta( $post_id, self::META_TITLE, wp_slash( $title ) );
		}

		self::finalize( $post_id, 'creer-page', $fields, $notes );

		return array(
			'post_id' => $post_id,
			'notes'   => $notes,
		);
	}

	/**
	 * Métas communes et méta-description.
	 *
	 * @param int    $post_id Brouillon.
	 * @param string $skill   Skill.
	 * @param array  $fields  Champs nettoyés.
	 * @param array  $notes   Notes.
	 */
	private static function finalize( $post_id, $skill, array $fields, array $notes ) {
		update_post_meta( $post_id, self::META_GENERATED, 1 );
		update_post_meta( $post_id, self::META_SKILL, $skill );
		update_post_meta( $post_id, self::META_NOTES, wp_slash( implode( "\n", $notes ) ) );
		Upsidly_Agent_Seo::write( $post_id, $fields['meta_description'] );
	}

	/**
	 * Assigne la catégorie si elle existe déjà (insensible à la casse). Sinon : note, sans rien créer.
	 *
	 * @param int    $post_id Article.
	 * @param string $name    Catégorie proposée.
	 * @return string[] Notes.
	 */
	private static function assign_category( $post_id, $name ) {
		if ( '' === $name ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( mb_strtolower( $term->name ) === mb_strtolower( $name ) ) {
					wp_set_post_categories( $post_id, array( (int) $term->term_id ) );
					return array();
				}
			}
		}

		return array(
			sprintf(
				/* translators: %s: nom de catégorie. */
				__( 'Catégorie suggérée : %s', 'upsidly-agent' ),
				$name
			),
		);
	}

	/**
	 * Étiquettes : assignées si l'utilisateur en a le droit ; créées seulement avec manage_categories.
	 *
	 * @param int      $post_id Article.
	 * @param string[] $tags    Étiquettes proposées.
	 * @return string[] Notes.
	 */
	private static function assign_tags( $post_id, array $tags ) {
		if ( array() === $tags ) {
			return array();
		}

		$taxonomy  = get_taxonomy( 'post_tag' );
		$can_set   = $taxonomy && current_user_can( $taxonomy->cap->assign_terms );
		$can_add   = current_user_can( 'manage_categories' );
		$assign    = array();
		$suggested = array();

		foreach ( $tags as $tag ) {
			if ( $can_set && ( $can_add || term_exists( $tag, 'post_tag' ) ) ) {
				$assign[] = $tag;
			} else {
				$suggested[] = $tag;
			}
		}

		if ( $assign ) {
			wp_set_post_tags( $post_id, $assign, false );
		}

		return $suggested
			? array(
				sprintf(
					/* translators: %s: liste d'étiquettes. */
					__( 'Étiquettes suggérées : %s', 'upsidly-agent' ),
					implode( ', ', $suggested )
				),
			)
			: array();
	}

	/**
	 * Applique une révision IA à la page d'origine.
	 *
	 * @param int $draft_id Brouillon de révision.
	 * @return array{page_id: int, edit_url: string, view_url: string, revisions_url: string}|WP_Error
	 */
	public static function apply( $draft_id ) {
		$draft     = get_post( (int) $draft_id );
		$source_id = $draft ? (int) get_post_meta( $draft->ID, self::META_SOURCE_PAGE, true ) : 0;
		$source    = $source_id ? get_post( $source_id ) : null;

		if ( ! $draft || ! $source || 'page' !== $source->post_type || 'trash' === $source->post_status ) {
			return new WP_Error( 'upsidly_agent_no_source', __( 'La page d\'origine de cette révision est introuvable.', 'upsidly-agent' ), array( 'status' => 404 ) );
		}
		if ( self::is_elementor( $source->ID ) ) {
			return new WP_Error( 'upsidly_agent_elementor', __( 'Cette page est construite avec Elementor : elle ne peut pas être modifiée par l\'assistant.', 'upsidly-agent' ), array( 'status' => 400 ) );
		}
		if ( ! wp_revisions_enabled( $source ) ) {
			return new WP_Error( 'upsidly_agent_revisions_disabled', __( 'Les révisions sont désactivées : impossible de garantir un retour arrière.', 'upsidly-agent' ), array( 'status' => 400 ) );
		}

		// Révision de l'état actuel, pour garantir le retour arrière même si la page n'en avait aucune.
		wp_save_post_revision( $source->ID );
		$revisions   = wp_get_post_revisions( $source->ID, array( 'posts_per_page' => 1 ) );
		$previous_id = $revisions ? (int) array_key_first( $revisions ) : 0;

		$title  = (string) get_post_meta( $draft->ID, self::META_TITLE, true );
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $source->ID,
					'post_title'   => '' !== $title ? $title : $source->post_title,
					'post_content' => $draft->post_content,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Upsidly_Agent_Seo::copy( $draft->ID, $source->ID );
		wp_trash_post( $draft->ID );

		return array(
			'page_id'       => $source->ID,
			'edit_url'      => (string) get_edit_post_link( $source->ID, 'raw' ),
			'view_url'      => (string) get_permalink( $source->ID ),
			'revisions_url' => $previous_id ? admin_url( 'revision.php?revision=' . $previous_id ) : '',
		);
	}

	/**
	 * Différences entre la page d'origine et le brouillon de révision (HTML de wp_text_diff()).
	 *
	 * @param int $draft_id Brouillon de révision.
	 * @return string|WP_Error
	 */
	public static function diff( $draft_id ) {
		$draft     = get_post( (int) $draft_id );
		$source_id = $draft ? (int) get_post_meta( $draft->ID, self::META_SOURCE_PAGE, true ) : 0;
		$source    = $source_id ? get_post( $source_id ) : null;

		if ( ! $draft || ! $source ) {
			return new WP_Error( 'upsidly_agent_no_source', __( 'La page d\'origine de cette révision est introuvable.', 'upsidly-agent' ), array( 'status' => 404 ) );
		}

		$title = (string) get_post_meta( $draft->ID, self::META_TITLE, true );
		$left  = $source->post_title . "\n\n" . self::to_text( $source->post_content );
		$right = ( '' !== $title ? $title : $draft->post_title ) . "\n\n" . self::to_text( $draft->post_content );

		$diff = wp_text_diff(
			$left,
			$right,
			array(
				'title_left'  => __( 'Page d\'origine', 'upsidly-agent' ),
				'title_right' => __( 'Révision IA', 'upsidly-agent' ),
			)
		);

		return '' !== $diff ? $diff : '<p>' . esc_html__( 'Aucune différence de texte.', 'upsidly-agent' ) . '</p>';
	}

	/**
	 * Contenu en blocs → texte sans balises, titres et paragraphes séparés par des sauts de ligne.
	 *
	 * @param string $content Contenu.
	 * @return string
	 */
	public static function to_text( $content ) {
		$text = preg_replace( '/<!--.*?-->/s', '', (string) $content );
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );
		$text = preg_replace( '#</(p|h[1-6]|li|blockquote|div|ul|ol|figure|figcaption|pre|table|tr)>#i', "$0\n", (string) $text );
		$text = html_entity_decode( wp_strip_all_tags( (string) $text, false ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{2,}/", "\n", (string) $text );
		return trim( (string) $text );
	}
}
