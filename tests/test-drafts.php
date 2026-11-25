<?php
/**
 * Tests des brouillons, de la modification de page, de l'application, des capacités et du SEO.
 *
 * @package Upsidly_Agent
 */

/**
 * Upsidly_Agent_Rest + Upsidly_Agent_Drafts + Upsidly_Agent_Seo.
 */
class Test_Upsidly_Agent_Drafts extends WP_UnitTestCase {

	/**
	 * Faux client.
	 *
	 * @var Upsidly_Agent_Test_Fake_Client
	 */
	private $client;

	/**
	 * Préparation.
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'upsidly_agent_key', 'upa_' . str_repeat( 'b', 40 ) );
		delete_transient( 'upsidly_agent_status' );

		$this->client              = new Upsidly_Agent_Test_Fake_Client();
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
		remove_all_filters( 'upsidly_agent_seo_plugins' );
		parent::tear_down();
	}

	/**
	 * Réponse /v1/run réussie.
	 *
	 * @param array $result Résultat de la skill.
	 * @return array
	 */
	private static function run_response( array $result ) {
		return array(
			'status' => 200,
			'body'   => array(
				'skill'   => 'x',
				'result'  => $result,
				'credits' => array(
					'limit'     => 1000,
					'used'      => 128,
					'remaining' => 872,
					'resets_on' => '2026-12-01',
					'charged'   => 8,
				),
			),
		);
	}

	/**
	 * Résultat d'article.
	 *
	 * @param string $category Catégorie proposée.
	 * @return array
	 */
	private static function article( $category ) {
		return array(
			'titre'            => 'Choisir ses fenêtres en aluminium',
			'slug'             => 'choisir-fenetres-aluminium',
			'meta_description' => 'Comment choisir ses fenêtres en aluminium : critères, isolation, budget.',
			'extrait'          => 'Les critères essentiels.',
			'contenu'          => "<!-- wp:paragraph -->\n<p>Texte.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:html -->\n<script>x</script>\n<!-- /wp:html -->",
			'categorie'        => $category,
			'etiquettes'       => array( 'fenêtre', 'aluminium', 'isolation' ),
			'notes'            => 'Vérifier les prix.',
		);
	}

	/**
	 * Appelle une route REST interne.
	 *
	 * @param string $method Méthode.
	 * @param string $route  Route.
	 * @param array  $body   Corps.
	 * @return WP_REST_Response
	 */
	private function rest( $method, $route, array $body = array() ) {
		$request = new WP_REST_Request( $method, '/upsidly-agent/v1' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Article : catégorie existante assignée, brouillon complet, méta-description de repli, journal.
	 */
	public function test_article_with_existing_category() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$category = self::factory()->category->create( array( 'name' => 'Conseils' ) );
		$this->client->responses['/v1/run'] = self::run_response( self::article( 'conseils' ) );

		$response = $this->rest(
			'POST',
			'/run',
			array(
				'skill'  => 'rediger-article',
				'inputs' => array(
					'sujet'                 => 'Fenêtres',
					'categories_existantes' => array( 'injecté' ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$post = get_post( $data['post_id'] );

		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( get_current_user_id(), (int) $post->post_author );
		$this->assertSame( 'Choisir ses fenêtres en aluminium', $post->post_title );
		$this->assertSame( 'Les critères essentiels.', $post->post_excerpt );
		$this->assertStringNotContainsString( 'script', $post->post_content );
		$this->assertSame( array( $category ), wp_get_post_categories( $post->ID ) );
		$this->assertEqualSets( array( 'fenêtre', 'aluminium', 'isolation' ), wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) );
		$this->assertSame( 'Comment choisir ses fenêtres en aluminium : critères, isolation, budget.', get_post_meta( $post->ID, '_upsidly_agent_meta_description', true ) );
		$this->assertSame( '1', get_post_meta( $post->ID, '_upsidly_agent_generated', true ) );
		$this->assertSame( 'rediger-article', get_post_meta( $post->ID, '_upsidly_agent_skill', true ) );
		$this->assertStringContainsString( '1 bloc non pris en charge a été retiré.', $data['result']['notes'] );
		$this->assertSame( 8, $data['credits']['charged'] );

		// Catégories remplies côté serveur, valeur du navigateur ignorée.
		$sent = $this->client->requests[0]['body'];
		$this->assertContains( 'Conseils', $sent['inputs']['categories_existantes'] );
		$this->assertNotContains( 'injecté', $sent['inputs']['categories_existantes'] );
		$this->assertSame( substr( get_locale(), 0, 2 ), $sent['context']['language'] );
		$this->assertSame( array( 'site_name', 'site_description', 'brand_voice', 'language' ), array_keys( $sent['context'] ) );

		$log = Upsidly_Agent_Log::recent( 1 );
		$this->assertSame( 'ok', $log[0]['status'] );
		$this->assertSame( '8', (string) $log[0]['credits'] );
		$this->assertSame( (string) $post->ID, (string) $log[0]['post_id'] );
	}

	/**
	 * Article : catégorie inconnue en notes, rien de créé.
	 */
	public function test_article_with_unknown_category() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->client->responses['/v1/run'] = self::run_response( self::article( 'Menuiserie' ) );

		$response = $this->rest(
			'POST',
			'/run',
			array(
				'skill'  => 'rediger-article',
				'inputs' => array( 'sujet' => 'Fenêtres' ),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( term_exists( 'Menuiserie', 'category' ) );
		$this->assertStringContainsString( 'Catégorie suggérée : Menuiserie', $data['result']['notes'] );
		$this->assertStringContainsString( 'Catégorie suggérée : Menuiserie', get_post_meta( $data['post_id'], '_upsidly_agent_notes', true ) );
	}

	/**
	 * Erreur du proxy : statut, message traduit, credits, journal.
	 */
	public function test_proxy_error_is_translated_and_logged() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->client->responses['/v1/run'] = array(
			'status' => 402,
			'body'   => array(
				'error' => array(
					'code'    => 'uap_quota_exceeded',
					'message' => 'Crédits du mois épuisés.',
					'credits' => array(
						'limit'     => 1000,
						'used'      => 1000,
						'remaining' => 0,
						'resets_on' => '2026-12-01',
					),
				),
			),
		);

		$response = $this->rest(
			'POST',
			'/run',
			array(
				'skill'  => 'rediger-article',
				'inputs' => array( 'sujet' => 'x' ),
			)
		);

		$this->assertSame( 402, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'uap_quota_exceeded', $data['code'] );
		$this->assertStringStartsWith( 'Vos crédits du mois sont épuisés.', $data['message'] );
		$this->assertSame( 0, $data['data']['credits']['remaining'] );

		$log = Upsidly_Agent_Log::recent( 1 );
		$this->assertSame( 'error', $log[0]['status'] );
	}

	/**
	 * Mode modifier : brouillon de révision créé, original intact ; puis application.
	 */
	public function test_modify_then_apply() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$parent   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$original = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Nos services',
				'post_parent'  => $parent,
				'post_content' => "<!-- wp:paragraph -->\n<p>Ancien texte.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->client->responses['/v1/run'] = self::run_response(
			array(
				'titre'              => 'Nos services',
				'meta_description'   => 'Découvrez nos services de menuiserie aluminium à Caen, de la pose à l\'entretien, avec un devis gratuit.',
				'contenu'            => "<!-- wp:paragraph -->\n<p>Nouveau texte.</p>\n<!-- /wp:paragraph -->",
				'resume_changements' => 'Texte réécrit.',
				'notes'              => '',
			)
		);

		$response = $this->rest(
			'POST',
			'/run',
			array(
				'skill'   => 'creer-page',
				'inputs'  => array(
					'mode'           => 'modifier',
					'brief'          => 'Réécris le texte',
					'contenu_actuel' => 'injecté',
				),
				'page_id' => $original,
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data  = $response->get_data();
		$draft = get_post( $data['post_id'] );

		$this->assertSame( 'page', $draft->post_type );
		$this->assertSame( 'draft', $draft->post_status );
		$this->assertSame( 'Nos services (révision IA)', $draft->post_title );
		$this->assertSame( $parent, (int) $draft->post_parent );
		$this->assertSame( (string) $original, get_post_meta( $draft->ID, '_upsidly_agent_source_page', true ) );
		$this->assertSame( $original, $data['source_page_id'] );
		$this->assertStringContainsString( 'Ancien texte.', get_post( $original )->post_content, 'Original intact' );

		// Le contenu actuel est lu côté serveur.
		$sent = $this->client->requests[0]['body'];
		$this->assertSame( 'Nos services', $sent['inputs']['titre_actuel'] );
		$this->assertStringContainsString( 'Ancien texte.', $sent['inputs']['contenu_actuel'] );

		// Différences.
		$diff = $this->rest( 'GET', '/diff/' . $draft->ID );
		$this->assertSame( 200, $diff->get_status() );
		$this->assertStringContainsString( '<ins>Nouveau</ins>', $diff->get_data()['html'] );
		$this->assertStringContainsString( '<del>Ancien</del>', $diff->get_data()['html'] );

		// Application.
		$apply = $this->rest( 'POST', '/apply/' . $draft->ID );
		$this->assertSame( 200, $apply->get_status(), wp_json_encode( $apply->get_data() ) );
		$updated = get_post( $original );
		$this->assertSame( 'Nos services', $updated->post_title );
		$this->assertStringContainsString( 'Nouveau texte.', $updated->post_content );
		$this->assertSame( 'trash', get_post_status( $draft->ID ) );
		$this->assertSame( 'Découvrez nos services de menuiserie aluminium à Caen, de la pose à l\'entretien, avec un devis gratuit.', get_post_meta( $original, '_upsidly_agent_meta_description', true ) );

		// Une révision permet de revenir à l'ancien contenu.
		$revisions = wp_get_post_revisions( $original );
		$contents  = wp_list_pluck( $revisions, 'post_content' );
		$this->assertNotEmpty( array_filter( $contents, static fn( $c ) => str_contains( $c, 'Ancien texte.' ) ) );
		$this->assertNotEmpty( $apply->get_data()['revisions_url'] );
	}

	/**
	 * Révisions désactivées : application refusée.
	 */
	public function test_apply_refused_without_revisions() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$original = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$draft    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $draft, '_upsidly_agent_source_page', $original );

		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$apply = $this->rest( 'POST', '/apply/' . $draft );
		remove_filter( 'wp_revisions_to_keep', '__return_zero' );

		$this->assertSame( 400, $apply->get_status() );
		$this->assertSame( 'upsidly_agent_revisions_disabled', $apply->get_data()['code'] );
	}

	/**
	 * Page Elementor : modification refusée, sans appel au proxy.
	 */
	public function test_elementor_page_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, '_elementor_edit_mode', 'builder' );

		$response = $this->rest(
			'POST',
			'/run',
			array(
				'skill'   => 'creer-page',
				'inputs'  => array(
					'mode'  => 'modifier',
					'brief' => 'x',
				),
				'page_id' => $page,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'upsidly_agent_elementor', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Elementor', $response->get_data()['message'] );
		$this->assertSame( array(), $this->client->requests );
	}

	/**
	 * Contributeur : article oui, page non, application non.
	 */
	public function test_contributor_capabilities() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->client->responses['/v1/run'] = self::run_response( self::article( 'Inconnue' ) );

		$article = $this->rest(
			'POST',
			'/run',
			array(
				'skill'  => 'rediger-article',
				'inputs' => array( 'sujet' => 'x' ),
			)
		);
		$this->assertSame( 200, $article->get_status(), wp_json_encode( $article->get_data() ) );
		// Le contributeur ne peut pas créer d'étiquettes : elles vont dans les notes.
		$this->assertStringContainsString( 'Étiquettes suggérées', $article->get_data()['result']['notes'] );

		$page = $this->rest(
			'POST',
			'/run',
			array(
				'skill'  => 'creer-page',
				'inputs' => array( 'brief' => 'x' ),
			)
		);
		$this->assertSame( 403, $page->get_status() );

		$original = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$draft    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $draft, '_upsidly_agent_source_page', $original );
		$apply = $this->rest( 'POST', '/apply/' . $draft );
		$this->assertSame( 403, $apply->get_status() );
	}

	/**
	 * Historique : un auteur ne voit que ses demandes.
	 */
	public function test_history_filtered_for_authors() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		Upsidly_Agent_Log::add(
			array(
				'user_id' => $editor,
				'skill'   => 'rediger-article',
			)
		);
		Upsidly_Agent_Log::add(
			array(
				'user_id' => $author,
				'skill'   => 'rediger-article',
			)
		);

		wp_set_current_user( $author );
		$users = array_unique( wp_list_pluck( $this->rest( 'GET', '/history' )->get_data(), 'user' ) );
		$this->assertSame( array( get_userdata( $author )->display_name ), array_values( $users ) );
	}

	/**
	 * Méta-description écrite dans chaque plugin SEO simulé.
	 */
	public function test_seo_meta_for_each_plugin() {
		$post = self::factory()->post->create();

		$expected = array(
			'rank-math' => 'rank_math_description',
			'yoast'     => '_yoast_wpseo_metadesc',
			'seopress'  => '_seopress_titles_desc',
		);
		foreach ( $expected as $plugin => $meta ) {
			add_filter(
				'upsidly_agent_seo_plugins',
				static function () use ( $plugin ) {
					return array( $plugin );
				}
			);
			$this->assertSame( array( $meta ), Upsidly_Agent_Seo::write( $post, 'Description ' . $plugin ) );
			$this->assertSame( 'Description ' . $plugin, get_post_meta( $post, $meta, true ) );
			remove_all_filters( 'upsidly_agent_seo_plugins' );
		}

		// Plusieurs plugins actifs : écriture dans tous.
		add_filter(
			'upsidly_agent_seo_plugins',
			static function () {
				return array( 'rank-math', 'yoast' );
			}
		);
		$this->assertSame( array( 'rank_math_description', '_yoast_wpseo_metadesc' ), Upsidly_Agent_Seo::write( $post, 'Les deux' ) );
		remove_all_filters( 'upsidly_agent_seo_plugins' );

		// Aucun plugin : méta propre au plugin.
		$this->assertSame( array( '_upsidly_agent_meta_description' ), Upsidly_Agent_Seo::write( $post, 'Repli' ) );
	}

	/**
	 * Statut : sans clé → 400 ; avec clé → réponse du proxy mise en cache.
	 */
	public function test_status_route() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->client->responses['/v1/status'] = array(
			'status' => 200,
			'body'   => array(
				'site'    => array(
					'name' => 'Test',
					'plan' => 'pilote',
				),
				'credits' => array( 'limit' => 1000 ),
				'skills'  => array(),
			),
		);

		$this->assertSame( 200, $this->rest( 'GET', '/status' )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', '/status' )->get_status() );
		$this->assertCount( 1, $this->client->requests, 'Deuxième appel servi par le cache' );

		delete_option( 'upsidly_agent_key' );
		delete_transient( 'upsidly_agent_status' );
		$response = $this->rest( 'GET', '/status' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'upsidly_agent_no_key', $response->get_data()['code'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', '/status' )->get_status() );
	}

	/**
	 * La clé n'apparaît jamais dans les données injectées dans l'Assistant.
	 */
	public function test_app_data_never_contains_key() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$json = wp_json_encode( Upsidly_Agent_Admin::app_data() );

		$this->assertStringNotContainsString( str_repeat( 'b', 40 ), $json );
		$this->assertStringNotContainsString( 'upa_', $json );
	}
}
