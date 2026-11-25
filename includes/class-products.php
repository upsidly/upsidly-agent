<?php
/**
 * Création de produits WooCommerce (simples ou variables) à partir du résultat de l'IA.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Produits WooCommerce.
 */
class Upsidly_Agent_Products {

	const MAX_VARIATIONS = 100;

	/**
	 * Crée le produit.
	 *
	 * @param array  $fields  Champs nettoyés (titre, slug, extrait…).
	 * @param string $content Description longue nettoyée.
	 * @param array  $product Données produit nettoyées (Upsidly_Agent_Sanitizer::product()).
	 * @param string $status  draft | pending | publish.
	 * @param array  $notes   Notes (par référence).
	 * @return int|WP_Error Identifiant du produit.
	 */
	public static function create( array $fields, $content, array $product, $status, array &$notes ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'upsidly_agent_no_woocommerce', __( 'WooCommerce n\'est pas actif sur ce site.', 'upsidly-agent' ), array( 'status' => 400 ) );
		}

		$variable = 'variable' === $product['type'] && ( $product['variations'] || self::has_variation_attributes( $product ) );
		$wc       = $variable ? new WC_Product_Variable() : new WC_Product_Simple();

		try {
			$wc->set_name( '' !== $fields['titre'] ? $fields['titre'] : __( 'Produit sans titre', 'upsidly-agent' ) );
			if ( '' !== $fields['slug'] ) {
				$wc->set_slug( $fields['slug'] );
			}
			$wc->set_status( $status );
			$wc->set_description( $content );
			$wc->set_short_description( $fields['extrait_html'] );

			if ( ! $variable ) {
				self::set_prices( $wc, $product['prix'], $product['prix_promo'], $notes );
			}
			self::set_stock( $wc, $product['stock'] );
			self::set_sku( $wc, $product['ugs'], $notes );

			$wc->set_attributes( self::attributes( $product, $variable, $notes ) );
			$product_id = $wc->save();
		} catch ( Exception $e ) {
			return new WP_Error( 'upsidly_agent_product', __( 'Le produit n\'a pas pu être créé : ', 'upsidly-agent' ) . $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( ! $product_id ) {
			return new WP_Error( 'upsidly_agent_product', __( 'Le produit n\'a pas pu être créé.', 'upsidly-agent' ), array( 'status' => 500 ) );
		}

		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_author' => get_current_user_id(),
			)
		);

		if ( $variable ) {
			self::create_variations( $product_id, $product, $notes );
			WC_Product_Variable::sync( $product_id );
		}

		if ( '' === $product['prix'] && ! $variable ) {
			$notes[] = __( 'Prix non renseigné : à compléter avant de publier le produit.', 'upsidly-agent' );
		}

		return (int) $product_id;
	}

	/**
	 * Attributs utilisés pour les variations ?
	 *
	 * @param array $product Données produit.
	 * @return bool
	 */
	private static function has_variation_attributes( array $product ) {
		foreach ( $product['attributs'] as $attribute ) {
			if ( $attribute['pour_variations'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prix normal et promotionnel.
	 *
	 * @param WC_Product $wc      Produit ou variation.
	 * @param string     $regular Prix normal.
	 * @param string     $sale    Prix promotionnel.
	 * @param array      $notes   Notes.
	 */
	private static function set_prices( $wc, $regular, $sale, array &$notes ) {
		if ( '' !== $regular ) {
			$wc->set_regular_price( $regular );
		}
		if ( '' !== $sale ) {
			if ( '' !== $regular && (float) $sale >= (float) $regular ) {
				$notes[] = sprintf(
					/* translators: 1: prix promotionnel, 2: prix normal. */
					__( 'Prix promotionnel ignoré (%1$s ≥ prix normal %2$s).', 'upsidly-agent' ),
					$sale,
					$regular
				);
			} else {
				$wc->set_sale_price( $sale );
			}
		}
	}

	/**
	 * Stock géré si une quantité est fournie.
	 *
	 * @param WC_Product $wc    Produit ou variation.
	 * @param int|null   $stock Quantité.
	 */
	private static function set_stock( $wc, $stock ) {
		if ( null !== $stock ) {
			$wc->set_manage_stock( true );
			$wc->set_stock_quantity( $stock );
		}
	}

	/**
	 * Référence (UGS), ignorée si déjà utilisée.
	 *
	 * @param WC_Product $wc    Produit ou variation.
	 * @param string     $sku   Référence.
	 * @param array      $notes Notes.
	 */
	private static function set_sku( $wc, $sku, array &$notes ) {
		if ( '' === $sku ) {
			return;
		}
		try {
			$wc->set_sku( $sku );
		} catch ( Exception $e ) {
			$notes[] = sprintf(
				/* translators: %s: référence. */
				__( 'Référence « %s » déjà utilisée : non enregistrée.', 'upsidly-agent' ),
				$sku
			);
		}
	}

	/**
	 * Attributs du produit : attribut global existant (taxonomie pa_*) ou attribut propre au produit.
	 *
	 * @param array $product  Données produit.
	 * @param bool  $variable Produit variable.
	 * @param array $notes    Notes.
	 * @return WC_Product_Attribute[]
	 */
	private static function attributes( array $product, $variable, array &$notes ) {
		$attributes = array();
		$position   = 0;

		foreach ( $product['attributs'] as $data ) {
			$attribute = new WC_Product_Attribute();
			$global    = self::global_attribute( $data['nom'] );

			if ( $global ) {
				$term_ids = array();
				foreach ( $data['valeurs'] as $value ) {
					$term_id = self::attribute_term( $global['taxonomy'], $value, $notes );
					if ( $term_id ) {
						$term_ids[] = $term_id;
					}
				}
				if ( ! $term_ids ) {
					continue;
				}
				$attribute->set_id( $global['id'] );
				$attribute->set_name( $global['taxonomy'] );
				$attribute->set_options( $term_ids );
			} else {
				$attribute->set_id( 0 );
				$attribute->set_name( $data['nom'] );
				$attribute->set_options( $data['valeurs'] );
			}

			$attribute->set_position( $position++ );
			$attribute->set_visible( $data['visible'] );
			$attribute->set_variation( $variable && $data['pour_variations'] );
			$attributes[] = $attribute;
		}

		return $attributes;
	}

	/**
	 * Attribut global correspondant à un nom (insensible à la casse).
	 *
	 * @param string $name Nom de l'attribut.
	 * @return array{id: int, taxonomy: string}|null
	 */
	private static function global_attribute( $name ) {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( 0 === strcasecmp( $attribute->attribute_label, $name ) || 0 === strcasecmp( $attribute->attribute_name, sanitize_title( $name ) ) ) {
				return array(
					'id'       => (int) $attribute->attribute_id,
					'taxonomy' => wc_attribute_taxonomy_name( $attribute->attribute_name ),
				);
			}
		}
		return null;
	}

	/**
	 * Terme d'un attribut global : existant, ou créé si l'utilisateur en a le droit.
	 *
	 * @param string $taxonomy Taxonomie pa_*.
	 * @param string $value    Valeur.
	 * @param array  $notes    Notes.
	 * @return int Identifiant du terme (0 si absent).
	 */
	private static function attribute_term( $taxonomy, $value, array &$notes ) {
		$existing = get_term_by( 'name', $value, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! current_user_can( $tax->cap->manage_terms ) ) {
			$notes[] = sprintf(
				/* translators: 1: valeur, 2: attribut. */
				__( 'Valeur « %1$s » absente de l\'attribut %2$s : à créer par un gestionnaire de la boutique.', 'upsidly-agent' ),
				$value,
				$tax ? $tax->labels->singular_name : $taxonomy
			);
			return 0;
		}
		$created = wp_insert_term( $value, $taxonomy );
		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	/**
	 * Variations d'un produit variable.
	 *
	 * @param int   $product_id Produit parent.
	 * @param array $product    Données produit.
	 * @param array $notes      Notes.
	 */
	private static function create_variations( $product_id, array $product, array &$notes ) {
		$parent  = wc_get_product( $product_id );
		$mapping = array();
		foreach ( $parent->get_attributes() as $key => $attribute ) {
			if ( ! $attribute->get_variation() ) {
				continue;
			}
			$label = $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();

			$mapping[ mb_strtolower( $label ) ] = array(
				'key'      => $key,
				'taxonomy' => $attribute->is_taxonomy() ? $attribute->get_name() : '',
			);
		}

		$missing_price = false;
		foreach ( array_slice( $product['variations'], 0, self::MAX_VARIATIONS ) as $data ) {
			$variation  = new WC_Product_Variation();
			$attributes = array();
			foreach ( $data['attributs'] as $name => $value ) {
				$map = $mapping[ mb_strtolower( $name ) ] ?? null;
				if ( ! $map ) {
					continue;
				}
				if ( '' !== $map['taxonomy'] ) {
					$term                      = get_term_by( 'name', $value, $map['taxonomy'] );
					$attributes[ $map['key'] ] = $term ? $term->slug : sanitize_title( $value );
				} else {
					$attributes[ $map['key'] ] = $value;
				}
			}
			if ( ! $attributes ) {
				continue;
			}

			try {
				$variation->set_parent_id( $product_id );
				$variation->set_attributes( $attributes );
				$variation->set_status( 'publish' );
				self::set_prices( $variation, $data['prix'], $data['prix_promo'], $notes );
				self::set_stock( $variation, $data['stock'] );
				self::set_sku( $variation, $data['ugs'], $notes );
				$variation->save();
			} catch ( Exception $e ) {
				$notes[] = __( 'Une variation n\'a pas pu être créée : ', 'upsidly-agent' ) . $e->getMessage();
				continue;
			}
			if ( '' === $data['prix'] ) {
				$missing_price = true;
			}
		}

		if ( count( $product['variations'] ) > self::MAX_VARIATIONS ) {
			$notes[] = sprintf(
				/* translators: %d: nombre maximal. */
				__( 'Seules les %d premières variations ont été créées.', 'upsidly-agent' ),
				self::MAX_VARIATIONS
			);
		}
		if ( $missing_price ) {
			$notes[] = __( 'Certaines variations n\'ont pas de prix : elles ne seront pas achetables tant qu\'il n\'est pas renseigné.', 'upsidly-agent' );
		}
	}

	/**
	 * Résumé du produit pour la carte de résultat.
	 *
	 * @param int $product_id Produit.
	 * @return array
	 */
	public static function summary( $product_id ) {
		$wc = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $wc ) {
			return array();
		}
		return array(
			'type'       => $wc->get_type(),
			'prix'       => wp_strip_all_tags( $wc->get_price_html() ),
			'ugs'        => $wc->get_sku(),
			'variations' => $wc->is_type( 'variable' ) ? count( $wc->get_children() ) : 0,
			'attributs'  => array_values(
				array_map(
					static function ( $attribute ) {
						return $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();
					},
					$wc->get_attributes()
				)
			),
		);
	}
}
