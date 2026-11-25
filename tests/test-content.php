<?php
/**
 * Tests de « Rédiger un contenu » : types, taxonomies, champs, produits.
 *
 * @package Upsidly_Agent
 */

/**
 * Upsidly_Agent_Content_Types + Upsidly_Agent_Products + /run rediger-contenu.
 */
class Test_Upsidly_Agent_Content extends WP_UnitTestCase {

	/**
	 * Faux client.
	 *
	 * @var Upsidly_Agent_Test_Fake_Client
	 */
	private $client;

	/**
	 * Préparation : type « temoignage » et taxonomie « secteur » sur mesure.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type(
			'temoignage',
			array(
				'label'    => 'Témoignages',
				'labels'   => array(
					'name'          => 'Témoignages',
					'singular_name' => 'Témoignage',
				),
				'public'   => true,
				'show_ui'  => true,
				'supports' => array( 'title', 'editor', 'excerpt' ),
			)
		);
		register_taxonomy(
			'secteur',
			'temoignage',
			array(
				'label'        => 'Secteurs',
				'labels'       => array( 'name' => 'Secteurs' ),
				'show_ui'      => true,
				'hierarchical' => true,
			)
		);
		Upsidly_Agent_Content_Types::reset();

		update_option( 'upsidly_agent_key', 'upa_' . str_repeat( 'd', 40 ) );
		$this->client               = new Upsidly_Agent_Test_Fake_Client();
		Upsidly_Agent_Rest::$client = $this->client;

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Nettoyage.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server             = null;
		Upsidly_Agent_Rest::$client = null;
		unregister_taxonomy( 'secteur' );
		unregister_post_type( 'temoignage' );
		parent::tear_down();
	}

	/**
	 * Réponse /v1/run.
	 *
	 * @param array $result Résultat.
	 * @return array
	 */
	private static function run_response( array $result ) {
		return array(
			'status' => 200,
			'body'   => array(
				'skill'   => 'rediger-contenu',
				'result'  => $result,
				'credits' => array(
					'limit'     => 1000,
					'used'      => 20,
					'remaining' => 980,
					'resets_on' => '2026-12-01',
					'charged'   => 6,
				),
			),
		);
	}

	/**
	 * Appelle /run.
	 *
	 * @param array $body Corps.
	 * @return WP_REST_Response
	 */
	private function run( array $body ) {
		$request = new WP_REST_Request( 'POST', '/upsidly-agent/v1/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'skill' => 'rediger-contenu' ) + $body ) );
		return rest_do_request( $request );
	}

	/**
	 * Détection : types et taxonomies classés par origine, types internes exclus.
	 */
	public function test_detection() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$types = Upsidly_Agent_Content_Types::post_types();
		$slugs = wp_list_pluck( $types, 'slug' );

		$this->assertSame( 'post', $slugs[0], 'Articles en premier' );
		$this->assertContains( 'temoignage', $slugs );
		$this->assertNotContains( 'page', $slugs, 'Les pages ont leur propre skill' );
		$this->assertNotContains( 'attachment', $slugs );
		$this->assertNotContains( 'wp_block', $slugs );

		$by_slug = array_column( $types, null, 'slug' );
		$this->assertSame( 'wordpress', $by_slug['post']['origin'] );
		$this->assertSame( 'code', $by_slug['temoignage']['origin'] );
		$this->assertSame( 'Sur mesure', $by_slug['temoignage']['originLabel'] );

		$taxonomies = array_column( $by_slug['temoignage']['taxonomies'], null, 'slug' );
		$this->assertArrayHasKey( 'secteur', $taxonomies );
		$this->assertTrue( $taxonomies['secteur']['hierarchical'] );

		$post_taxonomies = wp_list_pluck( $by_slug['post']['taxonomies'], 'slug' );
		$this->assertContains( 'category', $post_taxonomies );
		$this->assertNotContains( 'post_format', $post_taxonomies );

		// Un contributeur ne voit pas les types qu'il ne peut pas créer, et ne peut pas publier.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$contributor = array_column( Upsidly_Agent_Content_Types::post_types(), null, 'slug' );
		$this->assertFalse( $contributor['post']['canPublish'] );
	}

	/**
	 * Type sur mesure : termes imposés + proposés (existant, nouveau), statut publié, journal.
	 */
	public function test_custom_type_with_terms() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$industrie = self::factory()->term->create(
			array(
				'taxonomy' => 'secteur',
				'name'     => 'Industrie',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'secteur',
				'name'     => 'Santé',
			)
		);

		$this->client->responses['/v1/run'] = self::run_response(
			array(
				'titre'            => 'Témoignage de Marie',
				'slug'             => 'temoignage-marie',
				'meta_description' => 'Marie raconte son expérience.',
				'extrait'          => 'Un retour très positif.',
				'contenu'          => "<!-- wp:paragraph -->\n<p>Super.</p>\n<!-- /wp:paragraph -->",
				'termes'           => array(
					array(
						'taxonomie' => 'secteur',
						'termes'    => array( 'santé', 'Agriculture' ),
					),
					array(
						'taxonomie' => 'inconnue',
						'termes'    => array( 'x' ),
					),
				),
				'notes'            => '',
			)
		);

		$response = $this->run(
			array(
				'inputs' => array(
					'type_contenu' => 'temoignage',
					'demande'      => 'Le témoignage de Marie, infirmière',
					'taxonomies'   => 'injecté',
				),
				'status' => 'publish',
				'terms'  => array( 'secteur' => array( $industrie, 999999 ) ),
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$post = get_post( $data['post_id'] );

		$this->assertSame( 'temoignage', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'publish', $data['result']['statut'] );
		$this->assertNotEmpty( $data['view_url'] );
		$this->assertEqualSets( array( 'Industrie', 'Santé', 'Agriculture' ), wp_get_post_terms( $post->ID, 'secteur', array( 'fields' => 'names' ) ) );
		$this->assertSame( 'rediger-contenu', get_post_meta( $post->ID, '_upsidly_agent_skill', true ) );

		// Entrées envoyées au proxy : complétées côté serveur.
		$sent = $this->client->requests[0]['body']['inputs'];
		$this->assertSame( 'Témoignage', $sent['type_libelle'] );
		$this->assertStringContainsString( 'secteur (Secteurs, hiérarchique)', $sent['taxonomies'] );
		$this->assertStringContainsString( 'déjà choisis par la personne (ne pas répéter) : Industrie', $sent['taxonomies'] );
		$this->assertStringNotContainsString( 'injecté', $sent['taxonomies'] );
		$this->assertArrayNotHasKey( 'attributs_existants', $sent );
	}

	/**
	 * Droits : publication refusée sans le droit de publier ; terme nouveau en notes sans droit de créer.
	 */
	public function test_contributor_rights() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->client->responses['/v1/run'] = self::run_response(
			array(
				'titre'            => 'Article',
				'meta_description' => 'm',
				'contenu'          => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->",
				'termes'           => array(
					array(
						'taxonomie' => 'post_tag',
						'termes'    => array( 'nouvelle-etiquette' ),
					),
				),
			)
		);

		$denied = $this->run(
			array(
				'inputs' => array(
					'type_contenu' => 'post',
					'demande'      => 'x',
				),
				'status' => 'publish',
			)
		);
		$this->assertSame( 403, $denied->get_status() );
		$this->assertSame( 'upsidly_agent_publish', $denied->get_data()['code'] );
		$this->assertSame( array(), $this->client->requests );

		$type_denied = $this->run(
			array(
				'inputs' => array(
					'type_contenu' => 'page',
					'demande'      => 'x',
				),
			)
		);
		$this->assertSame( 403, $type_denied->get_status() );

		$ok = $this->run(
			array(
				'inputs' => array(
					'type_contenu' => 'post',
					'demande'      => 'x',
				),
				'status' => 'pending',
			)
		);
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 'pending', get_post_status( $ok->get_data()['post_id'] ) );
		$this->assertFalse( term_exists( 'nouvelle-etiquette', 'post_tag' ) ? true : false );
		$this->assertStringContainsString( 'nouvelle-etiquette', $ok->get_data()['result']['notes'] );
	}

	/**
	 * Produit variable : attribut global, variations et prix, description courte (WooCommerce requis).
	 */
	public function test_variable_product() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce absent de l\'environnement de test.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attribute_id = wc_create_attribute(
			array(
				'name' => 'Taille',
				'slug' => 'taille',
			)
		);
		register_taxonomy( 'pa_taille', 'product', array( 'hierarchical' => false ) );
		wp_insert_term( 'S', 'pa_taille' );

		$this->client->responses['/v1/run'] = self::run_response(
			array(
				'titre'            => 'T-shirt Lin',
				'slug'             => 't-shirt-lin',
				'meta_description' => 'T-shirt en lin.',
				'extrait'          => '<p>Léger et <strong>respirant</strong>.</p>',
				'contenu'          => "<!-- wp:paragraph -->\n<p>Description longue.</p>\n<!-- /wp:paragraph -->",
				'produit'          => array(
					'type_produit' => 'variable',
					'ugs'          => 'TSL',
					'attributs'    => array(
						array(
							'nom'             => 'taille',
							'valeurs'         => array( 'S', 'M' ),
							'pour_variations' => true,
						),
						array(
							'nom'             => 'Matière',
							'valeurs'         => array( 'Lin' ),
							'pour_variations' => false,
						),
					),
					'variations'   => array(
						array(
							'attributs' => array( 'Taille' => 'S' ),
							'prix'      => '29,00 €',
						),
						array(
							'attributs'  => array( 'Taille' => 'M' ),
							'prix'       => '35',
							'prix_promo' => '30',
							'stock'      => 4,
						),
					),
				),
			)
		);

		$response = $this->run(
			array(
				'inputs' => array(
					'type_contenu' => 'product',
					'demande'      => 'T-shirt Lin variable S et M',
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data    = $response->get_data();
		$product = wc_get_product( $data['post_id'] );

		$this->assertTrue( $product->is_type( 'variable' ) );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( 'TSL', $product->get_sku() );
		$this->assertStringContainsString( '<strong>respirant</strong>', $product->get_short_description() );
		$this->assertCount( 2, $product->get_children() );
		$this->assertSame( 'variable', $data['result']['produit']['type'] );
		$this->assertSame( 2, $data['result']['produit']['variations'] );

		$attributes = $product->get_attributes();
		$this->assertArrayHasKey( 'pa_taille', $attributes, 'Attribut global réutilisé' );
		$this->assertSame( $attribute_id, $attributes['pa_taille']->get_id() );
		$this->assertTrue( (bool) term_exists( 'M', 'pa_taille' ), 'Valeur créée (administrateur)' );
		$this->assertArrayHasKey( 'matiere', $attributes, 'Attribut propre au produit' );

		$prices = array();
		foreach ( $product->get_children() as $child ) {
			$variation                                         = wc_get_product( $child );
			$prices[ $variation->get_attribute( 'pa_taille' ) ] = $variation;
		}
		$this->assertEquals( 29.0, (float) $prices['S']->get_regular_price() );
		$this->assertSame( '', $prices['S']->get_sale_price() );
		$this->assertNull( $prices['S']->get_stock_quantity() );
		$this->assertEquals( 35.0, (float) $prices['M']->get_regular_price() );
		$this->assertEquals( 30.0, (float) $prices['M']->get_sale_price() );
		$this->assertSame( 4, (int) $prices['M']->get_stock_quantity() );

		$this->assertStringContainsString( 'Taille', $this->client->requests[0]['body']['inputs']['attributs_existants'] );
	}

	/**
	 * Produit simple sans prix : signalé dans les notes, rien d'inventé.
	 */
	public function test_simple_product_without_price() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce absent de l\'environnement de test.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->client->responses['/v1/run'] = self::run_response(
			array(
				'titre'            => 'Mug',
				'meta_description' => 'Mug.',
				'contenu'          => "<!-- wp:paragraph -->\n<p>Mug.</p>\n<!-- /wp:paragraph -->",
				'produit'          => array(
					'type_produit' => 'simple',
					'prix'         => 'à définir',
				),
			)
		);

		$data    = $this->run(
			array(
				'inputs' => array(
					'type_contenu' => 'product',
					'demande'      => 'Un mug',
				),
			)
		)->get_data();
		$product = wc_get_product( $data['post_id'] );

		$this->assertTrue( $product->is_type( 'simple' ) );
		$this->assertSame( '', $product->get_regular_price() );
		$this->assertStringContainsString( 'Prix non renseigné', $data['result']['notes'] );
	}

	/**
	 * Champs ACF texte remplis, champs non remplis signalés (ACF requis).
	 */
	public function test_acf_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'ACF absent de l\'environnement de test.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		acf_add_local_field_group(
			array(
				'key'      => 'group_upsidly_test',
				'title'    => 'Témoignage',
				'fields'   => array(
					array(
						'key'   => 'field_upsidly_auteur',
						'name'  => 'auteur',
						'label' => 'Auteur',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_upsidly_citation',
						'name'  => 'citation',
						'label' => 'Citation',
						'type'  => 'textarea',
					),
					array(
						'key'   => 'field_upsidly_note',
						'name'  => 'note',
						'label' => 'Note',
						'type'  => 'number',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'temoignage',
						),
					),
				),
			)
		);

		$fields = wp_list_pluck( Upsidly_Agent_Content_Types::custom_fields( 'temoignage' ), 'name' );
		$this->assertSame( array( 'auteur', 'citation' ), $fields, 'Champs texte uniquement' );

		$this->client->responses['/v1/run'] = self::run_response(
			array(
				'titre'            => 'Témoignage',
				'meta_description' => 'm',
				'contenu'          => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->",
				'champs'           => array(
					'auteur' => 'Marie <script>x</script>',
					'note'   => '5',
				),
			)
		);

		$data = $this->run(
			array(
				'inputs' => array(
					'type_contenu' => 'temoignage',
					'demande'      => 'x',
				),
			)
		)->get_data();

		$this->assertSame( 'Marie', get_field( 'auteur', $data['post_id'] ) );
		$this->assertEmpty( get_post_meta( $data['post_id'], 'note', true ), 'Champ non texte ignoré' );
		$this->assertStringContainsString( 'Champs à compléter : Citation', $data['result']['notes'] );
		$this->assertStringContainsString( 'auteur (Auteur, texte court)', $this->client->requests[0]['body']['inputs']['champs'] );
	}
}
