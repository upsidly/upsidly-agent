<?php
/**
 * Détection des types de contenu, taxonomies, champs personnalisés et attributs de produit.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Types de contenu et taxonomies, classés par origine : WordPress, WooCommerce, sur mesure (ACF, JetEngine, code).
 */
class Upsidly_Agent_Content_Types {

	const MAX_TERMS_UI     = 300;
	const MAX_TERMS_PROMPT = 150;

	/**
	 * Types internes jamais proposés.
	 */
	const EXCLUDED_TYPES = array(
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'product_variation',
		'shop_order',
		'shop_order_refund',
		'shop_order_placehold',
		'shop_coupon',
		'shop_webhook',
		'elementor_library',
		'e-landing-page',
		'e-floating-buttons',
		'acf-field-group',
		'acf-field',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
		'wpcf7_contact_form',
		'jet-engine',
		'jet-menu',
		'jet-popup',
		'jet-smart-filters',
		'jet-theme-core',
		'jet-woo-builder',
		'jet-engine-booking',
		'oceanwp_library',
		'astra-advanced-hook',
		'rank_math_schema',
		'seopress_schemas',
		'upsidly_agent',
	);

	/**
	 * Taxonomies internes jamais proposées.
	 */
	const EXCLUDED_TAXONOMIES = array( 'post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'product_type', 'product_visibility', 'elementor_library_type', 'elementor_library_category', 'elementor_font_type' );

	/**
	 * Types et taxonomies de WooCommerce.
	 */
	const WOOCOMMERCE = array( 'product', 'product_cat', 'product_tag', 'product_brand', 'product_shipping_class' );

	/**
	 * Cache des origines.
	 *
	 * @var array|null
	 */
	private static $origins = null;

	/**
	 * Libellés des origines.
	 *
	 * @return array<string, string>
	 */
	public static function origin_labels() {
		return array(
			'wordpress'   => __( 'WordPress', 'upsidly-agent' ),
			'woocommerce' => __( 'WooCommerce', 'upsidly-agent' ),
			'acf'         => __( 'Sur mesure (ACF)', 'upsidly-agent' ),
			'jetengine'   => __( 'Sur mesure (JetEngine)', 'upsidly-agent' ),
			'code'        => __( 'Sur mesure', 'upsidly-agent' ),
		);
	}

	/**
	 * Types de contenu que l'utilisateur courant peut créer.
	 *
	 * @return array<int, array>
	 */
	public static function post_types() {
		$labels = self::origin_labels();
		$types  = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $slug => $object ) {
			if ( in_array( $slug, self::EXCLUDED_TYPES, true ) || ! post_type_supports( $slug, 'title' ) ) {
				continue;
			}
			if ( 'product' === $slug && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			if ( ! current_user_can( $object->cap->create_posts ) ) {
				continue;
			}

			$origin  = self::origin( 'post_type', $slug, ! empty( $object->_builtin ) );
			$types[] = array(
				'slug'        => $slug,
				'label'       => $object->labels->name,
				'singular'    => $object->labels->singular_name,
				'origin'      => $origin,
				'originLabel' => $labels[ $origin ],
				'canPublish'  => current_user_can( $object->cap->publish_posts ),
				'isProduct'   => 'product' === $slug,
				'hasEditor'   => post_type_supports( $slug, 'editor' ),
				'taxonomies'  => self::taxonomies_for( $slug ),
				'fields'      => count( self::custom_fields( $slug ) ),
			);
		}

		$order = array_flip( array_keys( $labels ) );
		usort(
			$types,
			static function ( $a, $b ) use ( $order ) {
				if ( 'post' === $a['slug'] || 'post' === $b['slug'] ) {
					return 'post' === $a['slug'] ? -1 : 1;
				}
				return array( $order[ $a['origin'] ], $a['label'] ) <=> array( $order[ $b['origin'] ], $b['label'] );
			}
		);

		return $types;
	}

	/**
	 * Type de contenu autorisé pour l'utilisateur courant ?
	 *
	 * @param string $slug Type.
	 * @return bool
	 */
	public static function is_allowed_type( $slug ) {
		foreach ( self::post_types() as $type ) {
			if ( $type['slug'] === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Taxonomies d'un type de contenu, avec leurs termes.
	 *
	 * @param string $post_type Type.
	 * @return array<int, array>
	 */
	public static function taxonomies_for( $post_type ) {
		$labels = self::origin_labels();
		$list   = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $slug => $taxonomy ) {
			if ( empty( $taxonomy->show_ui ) || in_array( $slug, self::EXCLUDED_TAXONOMIES, true ) || 0 === strpos( $slug, 'pa_' ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $slug,
					'hide_empty' => false,
					'number'     => self::MAX_TERMS_UI,
					'orderby'    => 'name',
				)
			);

			$origin = self::origin( 'taxonomy', $slug, ! empty( $taxonomy->_builtin ) );
			$list[] = array(
				'slug'         => $slug,
				'label'        => $taxonomy->labels->name,
				'origin'       => $origin,
				'originLabel'  => $labels[ $origin ],
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'canAssign'    => current_user_can( $taxonomy->cap->assign_terms ),
				'canCreate'    => current_user_can( $taxonomy->cap->manage_terms ),
				'terms'        => is_wp_error( $terms ) ? array() : array_map(
					static function ( $term ) {
						return array(
							'id'     => (int) $term->term_id,
							'name'   => $term->name,
							'parent' => (int) $term->parent,
						);
					},
					$terms
				),
			);
		}

		return $list;
	}

	/**
	 * Description des taxonomies pour l'IA (termes existants et termes imposés).
	 *
	 * @param string $post_type Type.
	 * @param array  $imposed   Taxonomie => identifiants de termes imposés.
	 * @return string
	 */
	public static function taxonomies_prompt( $post_type, array $imposed ) {
		$lines = array();
		foreach ( self::taxonomies_for( $post_type ) as $taxonomy ) {
			$names = array_slice( wp_list_pluck( $taxonomy['terms'], 'name' ), 0, self::MAX_TERMS_PROMPT );
			$line  = sprintf( '- %s (%s, %s)', $taxonomy['slug'], $taxonomy['label'], $taxonomy['hierarchical'] ? 'hiérarchique' : 'étiquettes' );

			$chosen = array();
			foreach ( $imposed[ $taxonomy['slug'] ] ?? array() as $term_id ) {
				$term = get_term( (int) $term_id, $taxonomy['slug'] );
				if ( $term && ! is_wp_error( $term ) ) {
					$chosen[] = $term->name;
				}
			}
			if ( $chosen ) {
				$line .= ' — déjà choisis par la personne (ne pas répéter) : ' . implode( ', ', $chosen );
			}
			$line   .= ' — termes existants : ' . ( $names ? implode( ', ', $names ) : '(aucun)' );
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Champs personnalisés texte d'un type (ACF, JetEngine).
	 *
	 * @param string $post_type Type.
	 * @return array<int, array{name: string, key: string, label: string, type: string, source: string, instructions: string}>
	 */
	public static function custom_fields( $post_type ) {
		$fields = array();
		$types  = array( 'text', 'textarea', 'wysiwyg' );

		// ACF : groupes de champs affectés au type.
		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( ! empty( $field['name'] ) && in_array( $field['type'] ?? '', $types, true ) ) {
						$fields[ $field['name'] ] = array(
							'name'         => (string) $field['name'],
							'key'          => (string) ( $field['key'] ?? $field['name'] ),
							'label'        => (string) ( $field['label'] ?? $field['name'] ),
							'type'         => (string) $field['type'],
							'source'       => 'acf',
							'instructions' => wp_strip_all_tags( (string) ( $field['instructions'] ?? '' ) ),
						);
					}
				}
			}
		}

		// JetEngine : champs méta des types de contenu et des meta boxes.
		foreach ( self::jetengine_fields( $post_type ) as $field ) {
			if ( ! isset( $fields[ $field['name'] ] ) && in_array( $field['type'], array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		return array_values( $fields );
	}

	/**
	 * Description des champs pour l'IA.
	 *
	 * @param string $post_type Type.
	 * @return string
	 */
	public static function fields_prompt( $post_type ) {
		$names = array(
			'text'     => 'texte court',
			'textarea' => 'texte long',
			'wysiwyg'  => 'texte mis en forme (HTML simple)',
		);
		$lines = array();
		foreach ( self::custom_fields( $post_type ) as $field ) {
			$line = sprintf( '- %s (%s, %s)', $field['name'], $field['label'], $names[ $field['type'] ] ?? $field['type'] );
			if ( '' !== $field['instructions'] ) {
				$line .= ' : ' . mb_substr( $field['instructions'], 0, 200 );
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Champs JetEngine (configuration lue en base, sans dépendre de l'API de l'extension).
	 *
	 * @param string $post_type Type.
	 * @return array<int, array>
	 */
	private static function jetengine_fields( $post_type ) {
		global $wpdb;
		if ( ! function_exists( 'jet_engine' ) ) {
			return array();
		}

		$raw = array();

		// Champs méta définis sur le type de contenu JetEngine.
		$table = $wpdb->prefix . 'jet_post_types';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table de JetEngine, lecture seule.
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table de JetEngine, lecture seule.
			$meta = $wpdb->get_var( $wpdb->prepare( "SELECT meta_fields FROM {$table} WHERE slug = %s AND status = 'publish' LIMIT 1", $post_type ) );
			$raw  = array_merge( $raw, (array) maybe_unserialize( (string) $meta ) );
		}

		// Meta boxes JetEngine affectées au type.
		foreach ( (array) get_option( 'jet_engine_meta_boxes', array() ) as $box ) {
			$args    = is_array( $box ) ? ( $box['args'] ?? array() ) : array();
			$allowed = (array) ( $args['allowed_post_type'] ?? array() );
			if ( 'post' === ( $args['object_type'] ?? 'post' ) && in_array( $post_type, $allowed, true ) ) {
				$raw = array_merge( $raw, (array) ( $box['meta_fields'] ?? array() ) );
			}
		}

		$fields = array();
		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || 'field' !== ( $field['object_type'] ?? 'field' ) ) {
				continue;
			}
			$fields[] = array(
				'name'         => (string) $field['name'],
				'key'          => (string) $field['name'],
				'label'        => (string) ( $field['title'] ?? $field['name'] ),
				'type'         => (string) ( $field['type'] ?? '' ),
				'source'       => 'jetengine',
				'instructions' => wp_strip_all_tags( (string) ( $field['description'] ?? '' ) ),
			);
		}
		return $fields;
	}

	/**
	 * Attributs de produit globaux (WooCommerce) et leurs termes, pour l'IA.
	 *
	 * @return string
	 */
	public static function attributes_prompt() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return '';
		}
		$lines = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 100,
					'fields'     => 'names',
				)
			);
			$lines[]  = sprintf( '- %s : %s', $attribute->attribute_label, is_wp_error( $terms ) || ! $terms ? '(aucune valeur)' : implode( ', ', $terms ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Origine d'un type ou d'une taxonomie.
	 *
	 * @param string $kind     post_type | taxonomy.
	 * @param string $slug     Nom technique.
	 * @param bool   $built_in Natif de WordPress.
	 * @return string WordPress | woocommerce | acf | jetengine | code.
	 */
	public static function origin( $kind, $slug, $built_in ) {
		if ( $built_in ) {
			return 'WordPress';
		}
		if ( in_array( $slug, self::WOOCOMMERCE, true ) ) {
			return 'woocommerce';
		}
		$origins = self::origins();
		return $origins[ $kind ][ $slug ] ?? 'code';
	}

	/**
	 * Types et taxonomies déclarés par ACF et JetEngine (lecture de leur configuration).
	 *
	 * @return array{post_type: array<string, string>, taxonomy: array<string, string>}
	 */
	private static function origins() {
		global $wpdb;
		if ( null !== self::$origins ) {
			return self::$origins;
		}
		$origins = array(
			'post_type' => array(),
			'taxonomy'  => array(),
		);

		// ACF 6.1+ : types et taxonomies enregistrés comme contenus internes.
		foreach ( array(
			'acf-post-type' => 'post_type',
			'acf-taxonomy'  => 'taxonomy',
		) as $internal => $kind ) {
			if ( ! post_type_exists( $internal ) ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'      => $internal,
					'post_status'    => array( 'publish', 'acf-disabled' ),
					'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- configuration ACF, rarement plus de quelques dizaines.
				)
			);
			foreach ( $posts as $post ) {
				$settings = maybe_unserialize( $post->post_content );
				if ( is_array( $settings ) && ! empty( $settings[ $kind ] ) ) {
					$origins[ $kind ][ (string) $settings[ $kind ] ] = 'acf';
				}
			}
		}

		// JetEngine : tables de configuration.
		foreach ( array(
			'jet_post_types' => 'post_type',
			'jet_taxonomies' => 'taxonomy',
		) as $table_name => $kind ) {
			$table = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table de JetEngine, lecture seule.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table de JetEngine, lecture seule.
			foreach ( (array) $wpdb->get_col( "SELECT slug FROM {$table}" ) as $slug ) {
				$origins[ $kind ][ (string) $slug ] = 'jetengine';
			}
		}

		self::$origins = $origins;
		return $origins;
	}

	/**
	 * Vide le cache (tests).
	 */
	public static function reset() {
		self::$origins = null;
	}
}
