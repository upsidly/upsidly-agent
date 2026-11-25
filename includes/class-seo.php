<?php
/**
 * Écriture de la méta-description selon le plugin SEO actif.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Méta-description : Rank Math, Yoast, SEOPress, ou méta propre au plugin.
 */
class Upsidly_Agent_Seo {

	const META_KEYS = array(
		'rank-math' => 'rank_math_description',
		'yoast'     => '_yoast_wpseo_metadesc',
		'seopress'  => '_seopress_titles_desc',
	);

	const FALLBACK_KEY = '_upsidly_agent_meta_description';

	/**
	 * Écrit la méta-description dans tous les plugins SEO détectés (ou dans la méta du plugin).
	 *
	 * @param int    $post_id     Contenu.
	 * @param string $description Méta-description.
	 * @return string[] Clés de méta écrites.
	 */
	public static function write( $post_id, $description ) {
		$description = Upsidly_Agent_Sanitizer::text( $description, Upsidly_Agent_Sanitizer::META_MAX );
		if ( '' === $description ) {
			return array();
		}

		$keys = self::meta_keys();
		foreach ( $keys as $key ) {
			update_post_meta( (int) $post_id, $key, $description );
		}
		return $keys;
	}

	/**
	 * Lit la méta-description (premier plugin détecté, sinon méta du plugin).
	 *
	 * @param int $post_id Contenu.
	 * @return string
	 */
	public static function read( $post_id ) {
		foreach ( self::meta_keys() as $key ) {
			$value = (string) get_post_meta( (int) $post_id, $key, true );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Copie la méta-description d'un contenu vers un autre (application d'une révision).
	 *
	 * @param int $from_id Source (brouillon).
	 * @param int $to_id   Cible (page d'origine).
	 */
	public static function copy( $from_id, $to_id ) {
		$description = self::read( $from_id );
		if ( '' !== $description ) {
			self::write( $to_id, $description );
		}
	}

	/**
	 * Clés de méta à utiliser selon les plugins SEO actifs.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		$keys = array();
		foreach ( Upsidly_Agent_Environment::seo_plugins() as $plugin ) {
			if ( isset( self::META_KEYS[ $plugin ] ) ) {
				$keys[] = self::META_KEYS[ $plugin ];
			}
		}
		return $keys ? $keys : array( self::FALLBACK_KEY );
	}
}
