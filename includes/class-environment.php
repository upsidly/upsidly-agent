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
	 * Environnement envoyé au proxy à chaque étape de l'agent.
	 *
	 * @return array
	 */
	public static function agent_data() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$names  = array();
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $file ) {
			$names[] = isset( $all[ $file ] ) ? $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'] : (string) $file;
		}
		$theme = wp_get_theme();

		return array(
			'wp_version'        => (string) get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'theme'             => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (enfant de ' . $theme->parent()->get( 'Name' ) . ')' : '' ),
			'page_builder'      => self::page_builder(),
			'seo_plugin'        => self::seo_plugin(),
			'is_multisite'      => is_multisite(),
			'file_edit_allowed' => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
			'file_mods_allowed' => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'active_plugins'    => array_slice( $names, 0, 80 ),
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
