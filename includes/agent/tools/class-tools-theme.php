<?php
/**
 * Outils de l'agent : compréhension du thème (bloc ou classique, modèles, zones, menus, constructeur de pages)
 * et de la configuration de performance, avant toute modification de l'apparence du site.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lecture du thème et des modèles.
 */
class Upsidly_Agent_Tools_Theme {

	/**
	 * Extensions de cache et de performance reconnues (dossier => libellé).
	 */
	const PERFORMANCE_PLUGINS = array(
		'wp-rocket'                   => 'WP Rocket (cache de pages, optimisation)',
		'litespeed-cache'             => 'LiteSpeed Cache',
		'w3-total-cache'              => 'W3 Total Cache',
		'wp-super-cache'              => 'WP Super Cache',
		'wp-fastest-cache'            => 'WP Fastest Cache',
		'breeze'                      => 'Breeze (Cloudways)',
		'sg-cachepress'               => 'SiteGround Optimizer',
		'autoptimize'                 => 'Autoptimize (CSS/JS)',
		'perfmatters'                 => 'Perfmatters',
		'flying-press'                => 'FlyingPress',
		'nitropack'                   => 'NitroPack',
		'redis-cache'                 => 'Redis Object Cache',
		'cloudflare'                  => 'Cloudflare',
		'imagify'                     => 'Imagify (images)',
		'ewww-image-optimizer'        => 'EWWW Image Optimizer',
		'shortpixel-image-optimiser'  => 'ShortPixel (images)',
		'wp-optimize'                 => 'WP-Optimize',
		'elementor'                   => 'Elementor (constructeur de pages)',
		'elementor-pro'               => 'Elementor Pro (Theme Builder : en-têtes, pieds de page)',
		'divi-builder'                => 'Divi Builder',
		'beaver-builder-lite-version' => 'Beaver Builder',
		'bricks'                      => 'Bricks',
		'oxygen'                      => 'Oxygen',
		'header-footer-elementor'     => 'Elementor Header & Footer Builder',
		'astra-addon'                 => 'Astra Pro (en-tête et pied de page dans le personnalisateur)',
		'generateblocks'              => 'GenerateBlocks',
		'kadence-blocks'              => 'Kadence Blocks',
	);

	/**
	 * Outil theme_info : structure du thème et moyens natifs de modifier chaque zone, performances.
	 *
	 * @param array $input Entrées (aucune).
	 * @return string
	 */
	public static function theme_info( array $input ) {
		unset( $input );
		$theme = wp_get_theme();
		$block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		$info = array(
			'theme'            => array(
				'nom'          => $theme->get( 'Name' ),
				'version'      => $theme->get( 'Version' ),
				'dossier'      => 'themes/' . $theme->get_stylesheet(),
				'type'         => $block ? 'thème bloc (édition complète du site : modèles et parties de modèle)' : 'thème classique (fichiers PHP, personnalisateur, widgets, menus)',
				'theme_enfant' => (bool) $theme->parent(),
				'parent'       => $theme->parent() ? $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' ) : null,
				'theme_json'   => (bool) wp_theme_has_theme_json(),
			),
			'comment_modifier' => $block
				? 'Thème bloc : modifier un modèle (wp_template) ou une partie de modèle (wp_template_part : en-tête, pied de page…) avec get_template puis save_template, comme dans Apparence > Éditeur. La modification est enregistrée en base (personnalisation), conservée lors des mises à jour du thème, et annulable.'
				: 'Thème classique : utiliser d\'abord les réglages du thème (personnalisateur, theme_mods), les widgets et les menus ; sinon un thème enfant (jamais les fichiers du thème parent, écrasés à la mise à jour).',
		);

		if ( $block ) {
			$info['parties_de_modele'] = self::templates( 'wp_template_part' );
			$info['modeles']           = self::templates( 'wp_template' );
		} else {
			global $wp_registered_sidebars;
			$sidebars = array();
			foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
				$widgets    = wp_get_sidebars_widgets();
				$sidebars[] = array(
					'id'      => $id,
					'nom'     => $sidebar['name'] ?? $id,
					'widgets' => count( (array) ( $widgets[ $id ] ?? array() ) ),
				);
			}
			$info['zones_de_widgets'] = $sidebars;
			$mods                     = array();
			foreach ( (array) get_theme_mods() as $key => $value ) {
				if ( is_scalar( $value ) && preg_match( '/footer|header|copyright|credit|pied|entete/i', (string) $key ) ) {
					$mods[ $key ] = mb_substr( (string) $value, 0, 200 );
				}
			}
			$info['reglages_du_theme_lies_en_tete_pied'] = $mods;
		}

		$locations = array();
		foreach ( get_registered_nav_menus() as $location => $label ) {
			$assigned               = get_nav_menu_locations();
			$menu                   = isset( $assigned[ $location ] ) ? wp_get_nav_menu_object( $assigned[ $location ] ) : null;
			$locations[ $location ] = $label . ' : ' . ( $menu ? $menu->name : 'aucun menu' );
		}
		$info['emplacements_de_menus'] = $locations;

		if ( post_type_exists( 'elementor_library' ) ) {
			$info['elementor_modeles_de_theme'] = self::elementor_theme_templates();
		}

		$info['performance'] = self::performance();

		return Upsidly_Agent_Guard::to_result( $info );
	}

	/**
	 * Outil get_template : contenu d'un modèle ou d'une partie de modèle (thème bloc).
	 *
	 * @param array $input type (wp_template | wp_template_part), slug.
	 * @return string|WP_Error
	 */
	public static function get_template( array $input ) {
		$template = self::find( $input );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		return Upsidly_Agent_Guard::to_result(
			array(
				'id'                => $template->id,
				'type'              => $template->type,
				'slug'              => $template->slug,
				'titre'             => $template->title,
				'zone'              => $template->area ?? null,
				'source'            => 'custom' === $template->source ? 'personnalisé (enregistré en base)' : 'fichier du thème (non modifié)',
				'fichier_du_theme'  => (bool) $template->has_theme_file,
				'contenu'           => $template->content,
				'parties_utilisees' => self::parts_used( (string) $template->content ),
			)
		);
	}

	/**
	 * Modèle ou partie de modèle demandé(e), pour le thème actif.
	 *
	 * @param array $input type, slug.
	 * @return WP_Block_Template|WP_Error
	 */
	public static function find( array $input ) {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return new WP_Error( 'upsidly_agent_theme', 'Le thème actif n\'est pas un thème bloc : il n\'a pas de modèles modifiables de cette façon.' );
		}
		$type = (string) ( $input['type'] ?? 'wp_template_part' );
		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		if ( ! in_array( $type, array( 'wp_template', 'wp_template_part' ), true ) || '' === $slug ) {
			return new WP_Error( 'upsidly_agent_theme', 'Indiquer type (wp_template ou wp_template_part) et slug (ex. footer).' );
		}
		$template = get_block_template( get_stylesheet() . '//' . $slug, $type );
		if ( ! $template ) {
			return new WP_Error( 'upsidly_agent_theme', sprintf( 'Aucun %s « %s » pour ce thème : voir la liste dans theme_info.', 'wp_template' === $type ? 'modèle' : 'partie de modèle', $slug ) );
		}
		return $template;
	}

	/**
	 * Modèles d'un type : slug, titre, zone, source.
	 *
	 * @param string $type wp_template | wp_template_part.
	 * @return array
	 */
	private static function templates( $type ) {
		$list = array();
		foreach ( get_block_templates( array(), $type ) as $template ) {
			$row = array(
				'slug'   => $template->slug,
				'titre'  => $template->title,
				'source' => 'custom' === $template->source ? 'personnalisé' : 'thème',
			);
			if ( 'wp_template_part' === $type ) {
				$row['zone'] = $template->area;
			} else {
				$row['parties'] = self::parts_used( (string) $template->content );
			}
			$list[] = $row;
		}
		return $list;
	}

	/**
	 * Parties de modèle incluses dans un contenu de blocs.
	 *
	 * @param string $content Contenu.
	 * @return array<int, string>
	 */
	private static function parts_used( $content ) {
		preg_match_all( '/<!--\s*wp:template-part\s+(\{.*?\})\s*\/?-->/', $content, $matches );
		$parts = array();
		foreach ( $matches[1] as $json ) {
			$attrs = json_decode( $json, true );
			if ( is_array( $attrs ) && isset( $attrs['slug'] ) ) {
				$parts[] = (string) $attrs['slug'];
			}
		}
		return array_values( array_unique( $parts ) );
	}

	/**
	 * Modèles du Theme Builder d'Elementor (en-tête, pied de page…).
	 *
	 * @return array
	 */
	private static function elementor_theme_templates() {
		$list = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'elementor_library',
				'posts_per_page' => 30,
				'post_status'    => array( 'publish', 'draft' ),
			)
		) as $post ) {
			$list[] = array(
				'id'     => $post->ID,
				'titre'  => $post->post_title,
				'type'   => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
				'statut' => $post->post_status,
			);
		}
		return $list;
	}

	/**
	 * Configuration de performance : cache de pages, cache objet, optimisation, réglages PHP.
	 *
	 * @return array
	 */
	private static function performance() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active   = (array) get_option( 'active_plugins', array() );
		$detected = array();
		foreach ( $active as $file ) {
			$folder = strtok( (string) $file, '/' );
			if ( isset( self::PERFORMANCE_PLUGINS[ $folder ] ) ) {
				$detected[] = self::PERFORMANCE_PLUGINS[ $folder ];
			}
		}
		return array(
			'extensions_cache_optimisation' => $detected,
			'cache_de_pages_wp_cache'       => defined( 'WP_CACHE' ) && WP_CACHE,
			'advanced_cache_php'            => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'cache_objet'                   => wp_using_ext_object_cache() ? 'actif (' . ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'object-cache.php' : 'externe' ) . ')' : 'aucun',
			'opcache'                       => function_exists( 'opcache_get_status' ) && is_array( @opcache_get_status( false ) ) ? 'actif' : 'inconnu ou inactif', // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache_get_status peut être restreint par l'hébergeur.
			'memoire_php'                   => ini_get( 'memory_limit' ),
			'cron_desactive'                => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'conseil'                       => 'Après une modification de l\'apparence, le cache de pages peut afficher l\'ancienne version : le signaler à la personne (ou vider le cache de l\'extension détectée).',
		);
	}
}
