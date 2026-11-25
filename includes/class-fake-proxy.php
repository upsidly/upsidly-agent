<?php
/**
 * Proxy factice pour le développement (UPSIDLY_AGENT_FAKE_PROXY, ignoré si WP_DEBUG est faux).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Réponses factices pour le développement (UPSIDLY_AGENT_FAKE_PROXY + WP_DEBUG).
 *
 * Erreurs simulées via inputs.sujet ou inputs.brief : __erreur_quota__, __erreur_cle__, __erreur_site__,
 * __erreur_service__, __erreur_incomplet__, __erreur_saisie__.
 */
class Upsidly_Agent_Fake_Proxy {

	/**
	 * Réponse simulée.
	 *
	 * @param string $method Méthode.
	 * @param string $path   Chemin.
	 * @param mixed  $body   Corps envoyé.
	 * @return array{status: int, body: mixed}
	 */
	public static function respond( $method, $path, $body ) {
		$credits = array(
			'limit'     => 1000,
			'used'      => 120,
			'remaining' => 880,
			'resets_on' => gmdate( 'Y-m-01', strtotime( 'first day of next month' ) ),
		);

		if ( '/v1/disconnect' === $path ) {
			return array(
				'status' => 200,
				'body'   => array( 'ok' => true ),
			);
		}

		if ( '/v1/run' !== $path ) {
			return array(
				'status' => 200,
				'body'   => array(
					'site'    => array(
						'name' => get_bloginfo( 'name' ),
						'plan' => 'pilote',
					),
					'credits' => $credits,
					'skills'  => self::skills(),
				),
			);
		}

		$body   = json_decode( (string) wp_json_encode( $body ), true );
		$skill  = isset( $body['skill'] ) ? (string) $body['skill'] : '';
		$inputs = isset( $body['inputs'] ) && is_array( $body['inputs'] ) ? $body['inputs'] : array();
		$marker = (string) ( $inputs['sujet'] ?? $inputs['brief'] ?? '' );

		$errors = array(
			'__erreur_quota__'     => array( 402, 'uap_quota_exceeded', 'Crédits du mois épuisés.' ),
			'__erreur_cle__'       => array( 401, 'uap_bad_key', 'Clé de site inconnue.' ),
			'__erreur_site__'      => array( 403, 'uap_site_disabled', 'Ce site est désactivé.' ),
			'__erreur_service__'   => array( 503, 'uap_upstream_error', 'Le service IA est surchargé.' ),
			'__erreur_incomplet__' => array( 502, 'uap_incomplete', 'La réponse de l\'IA est incomplète.' ),
			'__erreur_saisie__'    => array( 413, 'uap_input_too_long', 'Champ « sujet » trop long (500 caractères maximum).' ),
		);
		if ( isset( $errors[ $marker ] ) ) {
			list( $status, $code, $message ) = $errors[ $marker ];
			$error                           = array(
				'code'    => $code,
				'message' => $message,
			);
			if ( 'uap_quota_exceeded' === $code ) {
				$error['credits'] = array_merge(
					$credits,
					array(
						'used'      => 1000,
						'remaining' => 0,
					)
				);
			}
			return array(
				'status' => $status,
				'body'   => array( 'error' => $error ),
			);
		}

		$content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Introduction</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Contenu factice généré en mode développement.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Premier point</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Second point</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";

		if ( 'rediger-contenu' === $skill ) {
			$is_product = 'product' === ( $inputs['type_contenu'] ?? '' );
			$result     = array(
				'titre'            => 'Contenu factice',
				'slug'             => 'contenu-factice',
				'meta_description' => str_repeat( 'Description factice du contenu. ', 5 ),
				'extrait'          => 'Extrait factice.',
				'contenu'          => $content,
				'termes'           => array(),
				'champs'           => array(),
				'notes'            => 'Réponse factice : rien à vérifier.',
			);
			if ( $is_product ) {
				$result['produit'] = array(
					'type_produit' => 'variable',
					'attributs'    => array(
						array(
							'nom'             => 'Taille',
							'valeurs'         => array( 'S', 'M' ),
							'pour_variations' => true,
						),
					),
					'variations'   => array(
						array(
							'attributs' => array( 'Taille' => 'S' ),
							'prix'      => '20',
						),
						array(
							'attributs' => array( 'Taille' => 'M' ),
							'prix'      => '22',
						),
					),
				);
			}
		} else {
			$result = null;
		}

		$result = null !== $result ? $result : ( 'creer-page' === $skill
			? array(
				'titre'              => 'Page factice',
				'meta_description'   => str_repeat( 'Description factice de la page. ', 5 ),
				'contenu'            => $content,
				'resume_changements' => 'modifier' === ( $inputs['mode'] ?? '' ) ? 'Ajout d\'une liste à puces.' : '',
				'notes'              => 'Réponse factice : rien à vérifier.',
			)
			: array(
				'titre'            => 'Article factice : ' . (string) ( $inputs['sujet'] ?? '' ),
				'slug'             => 'article-factice',
				'meta_description' => str_repeat( 'Description factice de l\'article. ', 5 ),
				'extrait'          => 'Extrait factice.',
				'contenu'          => $content,
				'categorie'        => 'Conseils',
				'etiquettes'       => array( 'factice', 'test', 'upsidly' ),
				'notes'            => 'Réponse factice : rien à vérifier.',
			) );

		return array(
			'status' => 200,
			'body'   => array(
				'skill'   => $skill,
				'result'  => $result,
				'credits' => array_merge(
					$credits,
					array(
						'used'      => 128,
						'remaining' => 872,
						'charged'   => 8,
					)
				),
			),
		);
	}

	/**
	 * Catalogue factice, identique au schéma public du proxy.
	 *
	 * @return array
	 */
	private static function skills() {
		return array(
			array(
				'id'          => 'rediger-contenu',
				'label'       => 'Rédiger un contenu',
				'description' => 'Rédige un article, un produit ou tout autre type de contenu du site.',
				'inputs'      => array(
					'type_contenu' => array(
						'type'     => 'string',
						'required' => true,
						'max'      => 100,
					),
					'demande'      => array(
						'type'     => 'string',
						'required' => true,
						'max'      => 6000,
					),
					'mot_cle'      => array(
						'type'     => 'string',
						'required' => false,
						'max'      => 120,
					),
					'longueur'     => array(
						'type'     => 'int',
						'required' => false,
						'min'      => 100,
						'max'      => 3000,
						'default'  => 800,
					),
				),
			),
			array(
				'id'          => 'creer-page',
				'label'       => 'Créer ou modifier une page',
				'description' => 'Crée une page à partir d\'un brief, ou révise une page existante selon une demande, en blocs Gutenberg.',
				'inputs'      => array(
					'mode'           => array(
						'type'     => 'enum',
						'required' => false,
						'label'    => 'Mode',
						'options'  => array( 'creer', 'modifier' ),
						'default'  => 'creer',
					),
					'brief'          => array(
						'type'     => 'string',
						'required' => true,
						'label'    => 'Brief ou demande de modification',
						'max'      => 3000,
					),
					'titre_actuel'   => array(
						'type'     => 'string',
						'required' => false,
						'label'    => 'Titre actuel',
						'max'      => 200,
					),
					'contenu_actuel' => array(
						'type'     => 'text',
						'required' => false,
						'label'    => 'Contenu actuel',
						'max'      => 60000,
					),
				),
			),
			array(
				'id'          => 'rediger-article',
				'label'       => 'Rédiger un article',
				'description' => 'Rédige un article de blog structuré et optimisé pour le référencement, livré prêt à relire en brouillon.',
				'inputs'      => array(
					'sujet'                 => array(
						'type'     => 'string',
						'required' => true,
						'label'    => 'Sujet de l\'article',
						'max'      => 500,
					),
					'mot_cle'               => array(
						'type'     => 'string',
						'required' => false,
						'label'    => 'Mot-clé principal',
						'max'      => 120,
					),
					'cible'                 => array(
						'type'     => 'string',
						'required' => false,
						'label'    => 'Lecteur visé',
						'max'      => 300,
					),
					'longueur'              => array(
						'type'     => 'int',
						'required' => false,
						'label'    => 'Longueur visée (en mots)',
						'min'      => 300,
						'max'      => 3000,
						'default'  => 1000,
					),
					'consignes'             => array(
						'type'     => 'string',
						'required' => false,
						'label'    => 'Consignes particulières',
						'max'      => 2000,
					),
					'categories_existantes' => array(
						'type'      => 'list',
						'required'  => false,
						'label'     => 'Catégories existantes',
						'max_items' => 50,
					),
				),
			),
		);
	}
}
