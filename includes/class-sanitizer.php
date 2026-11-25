<?php
/**
 * Nettoyage du contenu reçu du proxy (blocs et HTML).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Le contenu reçu n'est jamais inséré tel quel.
 */
class Upsidly_Agent_Sanitizer {

	const ALLOWED_BLOCKS = array(
		'core/heading',
		'core/paragraph',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/separator',
		'core/buttons',
		'core/button',
	);

	const TITLE_MAX = 200;
	const META_MAX  = 320;
	const TAGS_MAX  = 6;

	/**
	 * Nettoie un contenu en blocs.
	 *
	 * @param string $content Contenu brut renvoyé par le proxy.
	 * @return array{content: string, removed: int}
	 */
	public static function content( $content ) {
		$content  = (string) $content;
		$removed  = 0;
		$freeform = '';
		$blocks   = self::filter_blocks( parse_blocks( $content ), $removed, $freeform );

		if ( array() === $blocks ) {
			// Aucun bloc valide : le texte libre est converti en paragraphes (jamais de core/html).
			return array(
				'content' => '' === trim( $freeform ) ? '' : self::to_paragraphs( $freeform ),
				'removed' => $removed,
			);
		}

		if ( '' !== trim( $freeform ) ) {
			// Texte libre glissé entre des blocs valides : retiré et signalé.
			++$removed;
		}

		return array(
			'content' => serialize_blocks( $blocks ),
			'removed' => $removed,
		);
	}

	/**
	 * Garde uniquement les blocs autorisés, récursivement, et nettoie leur HTML.
	 *
	 * @param array  $blocks   Blocs issus de parse_blocks().
	 * @param int    $removed  Compteur de blocs retirés (par référence).
	 * @param string $freeform Texte libre hors blocs, accumulé (par référence).
	 * @return array
	 */
	private static function filter_blocks( array $blocks, &$removed, &$freeform = '' ) {
		$kept = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			if ( null === $name ) {
				// Texte libre hors blocs : jamais inséré tel quel ; conservé pour une éventuelle conversion en paragraphes.
				$html = (string) ( $block['innerHTML'] ?? '' );
				if ( '' !== trim( $html ) ) {
					$freeform .= "\n\n" . $html;
				}
				continue;
			}

			if ( ! in_array( $name, self::ALLOWED_BLOCKS, true ) ) {
				++$removed;
				continue;
			}

			$inner_removed        = 0;
			$block['innerBlocks'] = self::filter_blocks( $block['innerBlocks'] ?? array(), $inner_removed );
			$removed             += $inner_removed;

			$block['innerHTML']    = wp_kses_post( (string) ( $block['innerHTML'] ?? '' ) );
			$block['innerContent'] = self::clean_inner_content( $block['innerContent'] ?? array(), count( $block['innerBlocks'] ), $inner_removed > 0 );
			$block['attrs']        = self::clean_attrs( is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );

			$kept[] = $block;
		}

		return $kept;
	}

	/**
	 * Nettoie innerContent (morceaux de HTML et emplacements null des blocs enfants).
	 *
	 * @param array $inner_content  Morceaux d'origine.
	 * @param int   $children_count Nombre de blocs enfants conservés.
	 * @param bool  $children_cut   Vrai si des blocs enfants ont été retirés.
	 * @return array
	 */
	private static function clean_inner_content( array $inner_content, $children_count, $children_cut ) {
		$result = array();
		$slots  = 0;

		foreach ( $inner_content as $chunk ) {
			if ( null === $chunk ) {
				if ( $slots < $children_count ) {
					$result[] = null;
					++$slots;
				}
				continue;
			}
			$result[] = wp_kses_post( (string) $chunk );
		}

		if ( $children_cut ) {
			// Évite les morceaux de séparation orphelins laissés par les enfants retirés.
			$result = array_values( $result );
		}

		return $result;
	}

	/**
	 * Attributs de bloc : scalaires uniquement, chaînes nettoyées.
	 *
	 * @param array $attrs Attributs.
	 * @return array
	 */
	private static function clean_attrs( array $attrs ) {
		$clean = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9]*$/', $key ) ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = 'url' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value );
			}
		}
		return $clean;
	}

	/**
	 * Convertit un texte ou du HTML sans blocs en blocs core/paragraph.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function to_paragraphs( $text ) {
		$html = wp_kses_post( $text );

		if ( preg_match_all( '#<p[^>]*>(.*?)</p>#is', $html, $matches ) ) {
			$parts = $matches[1];
		} else {
			$parts = preg_split( '/\R\s*\R/', $html );
		}

		$blocks = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( wp_kses( (string) $part, self::inline_tags() ) );
			if ( '' === $part ) {
				continue;
			}
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . $part . "</p>\n<!-- /wp:paragraph -->";
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Balises en ligne autorisées dans un paragraphe converti.
	 *
	 * @return array
	 */
	private static function inline_tags() {
		return array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
			'code'   => array(),
		);
	}

	/**
	 * Champ texte court.
	 *
	 * @param mixed $value Valeur.
	 * @param int   $max   Longueur maximale.
	 * @return string
	 */
	public static function text( $value, $max = 0 ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return $max > 0 ? mb_substr( $value, 0, $max ) : $value;
	}

	/**
	 * Termes proposés par l'IA : taxonomie => noms (20 au plus par taxonomie).
	 *
	 * @param mixed $terms Valeur « termes » du résultat.
	 * @return array<string, string[]>
	 */
	public static function terms( $terms ) {
		$clean = array();
		foreach ( is_array( $terms ) ? $terms : array() as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['taxonomie'] ) || ! is_array( $entry['termes'] ?? null ) ) {
				continue;
			}
			$taxonomy = sanitize_key( (string) $entry['taxonomie'] );
			foreach ( $entry['termes'] as $name ) {
				$name = self::text( $name, 200 );
				if ( '' !== $name ) {
					$clean[ $taxonomy ][] = $name;
				}
			}
			if ( isset( $clean[ $taxonomy ] ) ) {
				$clean[ $taxonomy ] = array_slice( array_values( array_unique( $clean[ $taxonomy ] ) ), 0, 20 );
			}
		}
		return $clean;
	}

	/**
	 * Valeurs de champs personnalisés : nom => texte brut (nettoyé ensuite selon le type du champ).
	 *
	 * @param mixed $fields Valeur « champs » du résultat.
	 * @return array<string, string>
	 */
	public static function custom_values( $fields ) {
		$clean = array();
		foreach ( is_array( $fields ) ? $fields : array() as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$clean[ $name ] = mb_substr( (string) $value, 0, 20000 );
			}
		}
		return $clean;
	}

	/**
	 * Prix : « 49,90 € » → « 49.90 » ; vide si non numérique.
	 *
	 * @param mixed $value Prix.
	 * @return string
	 */
	public static function price( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = str_replace( array( ',', ' ', "\u{00A0}", '€', 'EUR', '$' ), array( '.', '', '', '', '', '' ), trim( (string) $value ) );
		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			return '';
		}
		return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $value ) : (string) round( (float) $value, 2 );
	}

	/**
	 * Données produit WooCommerce nettoyées.
	 *
	 * @param mixed $product Valeur « produit » du résultat.
	 * @return array{type: string, prix: string, prix_promo: string, ugs: string, stock: int|null, attributs: array, variations: array}
	 */
	public static function product( $product ) {
		$product = is_array( $product ) ? $product : array();
		$stock   = static function ( $value ) {
			return is_numeric( $value ) ? max( 0, (int) $value ) : null;
		};

		$attributes = array();
		foreach ( array_slice( (array) ( $product['attributs'] ?? array() ), 0, 10 ) as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$name   = self::text( $attribute['nom'] ?? '', 100 );
			$values = array();
			foreach ( (array) ( $attribute['valeurs'] ?? array() ) as $value ) {
				$value = self::text( $value, 100 );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
			if ( '' === $name || ! $values ) {
				continue;
			}
			$attributes[] = array(
				'nom'             => $name,
				'valeurs'         => array_slice( array_values( array_unique( $values ) ), 0, 50 ),
				'pour_variations' => ! empty( $attribute['pour_variations'] ),
				'visible'         => ! isset( $attribute['visible'] ) || ! empty( $attribute['visible'] ),
			);
		}

		$variations = array();
		foreach ( (array) ( $product['variations'] ?? array() ) as $variation ) {
			if ( ! is_array( $variation ) || ! is_array( $variation['attributs'] ?? null ) ) {
				continue;
			}
			$combination = array();
			foreach ( $variation['attributs'] as $name => $value ) {
				$name  = self::text( $name, 100 );
				$value = self::text( $value, 100 );
				if ( '' !== $name && '' !== $value ) {
					$combination[ $name ] = $value;
				}
			}
			if ( ! $combination ) {
				continue;
			}
			$variations[] = array(
				'attributs'  => $combination,
				'prix'       => self::price( $variation['prix'] ?? '' ),
				'prix_promo' => self::price( $variation['prix_promo'] ?? '' ),
				'ugs'        => self::text( $variation['ugs'] ?? '', 100 ),
				'stock'      => $stock( $variation['stock'] ?? null ),
			);
		}

		return array(
			'type'       => 'variable' === ( $product['type_produit'] ?? '' ) ? 'variable' : 'simple',
			'prix'       => self::price( $product['prix'] ?? '' ),
			'prix_promo' => self::price( $product['prix_promo'] ?? '' ),
			'ugs'        => self::text( $product['ugs'] ?? '', 100 ),
			'stock'      => $stock( $product['stock'] ?? null ),
			'attributs'  => $attributes,
			'variations' => $variations,
		);
	}

	/**
	 * Nettoie le résultat renvoyé par une skill (hors contenu).
	 *
	 * @param array $result Résultat brut.
	 * @return array{titre: string, slug: string, meta_description: string, extrait: string, categorie: string, etiquettes: string[], notes: string, resume_changements: string}
	 */
	public static function fields( array $result ) {
		$tags = array();
		if ( isset( $result['etiquettes'] ) && is_array( $result['etiquettes'] ) ) {
			foreach ( $result['etiquettes'] as $tag ) {
				$tag = self::text( $tag, 100 );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
		}

		return array(
			'titre'              => self::text( $result['titre'] ?? '', self::TITLE_MAX ),
			'slug'               => sanitize_title( self::text( $result['slug'] ?? '', 200 ) ),
			'meta_description'   => self::text( $result['meta_description'] ?? '', self::META_MAX ),
			'extrait'            => self::text( $result['extrait'] ?? '', 1000 ),
			'extrait_html'       => mb_substr( wp_kses_post( is_scalar( $result['extrait'] ?? '' ) ? (string) ( $result['extrait'] ?? '' ) : '' ), 0, 5000 ),
			'categorie'          => self::text( $result['categorie'] ?? '', 200 ),
			'etiquettes'         => array_slice( array_values( array_unique( $tags ) ), 0, self::TAGS_MAX ),
			'notes'              => self::text( $result['notes'] ?? '', 2000 ),
			'resume_changements' => self::text( $result['resume_changements'] ?? '', 2000 ),
		);
	}
}
