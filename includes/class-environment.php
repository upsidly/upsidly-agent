<?php
/**
 * Détection de l'environnement du site (versions, plugin SEO, éditeur).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Environnement envoyé au proxy par /v1/connect. Aucune donnée personnelle.
 */
class Upsidly_Agent_Environment {

	/**
	 * Données envoyées au proxy (et seulement celles-ci).
	 *
	 * @return array{site_name: string, plugin_version: string, wp_version: string, php_version: string, seo_plugin: string, page_builder: string, is_multisite: bool}
	 */
	public static function data() {
		return array(
			'site_name'      => (string) get_bloginfo( 'name' ),
			'plugin_version' => UPSIDLY_AGENT_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'seo_plugin'     => self::seo_plugin(),
			'page_builder'   => self::page_builder(),
			'is_multisite'   => is_multisite(),
		);
	}

	/**
	 * Plugin SEO principal : rank-math, yoast, seopress ou none.
	 *
	 * @return string
	 */
	public static function seo_plugin() {
		$active = self::seo_plugins();
		return $active ? $active[0] : 'none';
	}

	/**
	 * Tous les plugins SEO détectés, dans l'ordre de priorité.
	 *
	 * @return string[]
	 */
	public static function seo_plugins() {
		$active = array();
		if ( class_exists( 'RankMath' ) ) {
			$active[] = 'rank-math';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = 'yoast';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$active[] = 'seopress';
		}

		/**
		 * Filtre la liste des plugins SEO détectés (utile aux tests).
		 *
		 * @param string[] $active Plugins détectés.
		 */
		return (array) apply_filters( 'upsidly_agent_seo_plugins', $active );
	}

	/**
	 * Éditeur de page : elementor si Elementor est actif, sinon blocks.
	 *
	 * @return string
	 */
	public static function page_builder() {
		return defined( 'ELEMENTOR_VERSION' ) ? 'elementor' : 'blocks';
	}
}
