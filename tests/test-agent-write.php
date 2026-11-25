<?php
/**
 * Tests de l'agent, lot B : écritures validées, sauvegardes, restauration, annulation.
 *
 * @package Upsidly_Agent
 */

/**
 * Agent : outils d'écriture.
 */
class Test_Upsidly_Agent_Agent_Write extends WP_UnitTestCase {

	/**
	 * Faux client.
	 *
	 * @var Upsidly_Agent_Test_Fake_Client
	 */
	private $client;

	/**
	 * Dossier de test dans wp-content.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Préparation.
	 */
	public function set_up() {
		parent::set_up();

		Upsidly_Agent_Agent_Store::create_tables();
		update_option( 'upsidly_agent_key', 'upa_' . str_repeat( 'e', 40 ) );
		update_option( 'upsidly_agent_agent_enabled', '1' );
		set_transient( 'upsidly_agent_status', array( 'agent' => array( 'level' => 'write' ) ), 300 );
		get_role( 'administrator' )->add_cap( 'upsidly_agent_use' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $admin, 'upsidly_agent_ack', '1' );
		wp_set_current_user( $admin );

		$this->client                     = new Upsidly_Agent_Test_Fake_Client();
		Upsidly_Agent_Agent_Loop::$client = $this->client;
		add_filter(
			'upsidly_agent_health_check',
			static function () {
				return array(
					'ok'      => true,
					'checked' => true,
				);
			}
		);

		$this->dir = WP_CONTENT_DIR . '/upsidly-agent-write-test';
		wp_mkdir_p( $this->dir );
		file_put_contents( $this->dir . '/a.php', "<?php\n// Bonjour\necho 'avant';\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Nettoyage.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server                   = null;
		Upsidly_Agent_Agent_Loop::$client = null;
		remove_all_filters( 'upsidly_agent_health_check' );
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( is_dir( $this->dir . '/sous' ) ) {
			rmdir( $this->dir . '/sous' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		deactivate_plugins( 'hello.php', true );
		parent::tear_down();
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
	 * Réponse du proxy demandant un outil.
	 *
	 * @param string $name  Outil.
	 * @param array  $input Entrées.
	 * @return array
	 */
	private static function tool_turn( $name, array $input ) {
		return self::turn(
			array(
				array(
					'type' => 'text',
					'text' => 'Je propose une modification.',
				),
				array(
					'type'  => 'tool_use',
					'id'    => 'toolu_' . wp_generate_password( 8, false ),
					'name'  => $name,
					'input' => $input + array( 'raison' => 'Pour le test.' ),
				),
			)
		);
	}

	/**
	 * Réponse du proxy.
	 *
	 * @param array  $content Blocs.
	 * @param string $stop    stop_reason.
	 * @return array
	 */
	private static function turn( array $content, $stop = 'tool_use' ) {
		return array(
			'status' => 200,
			'body'   => array(
				'content'      => $content,
				'stop_reason'  => $stop,
				'credits'      => array(
					'limit'     => 1000,
					'used'      => 1,
					'remaining' => 999,
					'resets_on' => '2026-12-01',
					'charged'   => 1,
				),
				'conversation' => array(
					'credits_used' => 1,
					'budget'       => 200,
					'steps'        => 1,
					'max_steps'    => 40,
				),
			),
		);
	}

	/**
	 * Démarre une conversation et exécute la première étape (proposition de l'agent).
	 *
	 * @param string $name  Outil.
	 * @param array  $input Entrées.
	 * @return array{id: string, step: array, action: array|null}
	 */
	private function propose( $name, array $input ) {
		$this->client->queue['/v1/agent/turn'] = array(
			self::tool_turn( $name, $input ),
			self::turn(
				array(
					array(
						'type' => 'text',
						'text' => 'Terminé.',
					),
				),
				'end_turn'
			),
		);
		$id   = $this->rest( 'POST', '/agent/conversations', array( 'message' => 'Fais la modification' ) )->get_data()['conversation']['id'];
		$step = $this->rest( 'POST', "/agent/conversations/{$id}/step" )->get_data();
		$step = $this->rest( 'POST', "/agent/conversations/{$id}/step" )->get_data();

		return array(
			'id'     => $id,
			'step'   => $step,
			'action' => $step['actions'][0] ?? null,
		);
	}

	/**
	 * Résultat d'outil envoyé au proxy à l'étape suivant la décision.
	 *
	 * @return array
	 */
	private function last_tool_result() {
		$request = end( $this->client->requests );
		$history = $request['body']['messages'];
		$last    = end( $history );
		return $last['content'][0];
	}

	/**
	 * edit_file : attente, aperçu, rien d'écrit ; validation → écrit, sauvegardé ; annulation → restauré.
	 */
	public function test_edit_file_approve_and_undo() {
		$proposal = $this->propose(
			'edit_file',
			array(
				'path'     => 'upsidly-agent-write-test/a.php',
				'old_text' => "echo 'avant';",
				'new_text' => "echo 'après';",
			)
		);

		$this->assertSame( 'awaiting', $proposal['step']['status'] );
		$this->assertSame( 'pending', $proposal['action']['status'] );
		$this->assertSame( 'Pour le test.', $proposal['action']['reason'] );
		$this->assertStringContainsString( 'upsidly-agent-write-test/a.php', $proposal['action']['title'] );
		$this->assertStringContainsString( 'diff', $proposal['action']['preview'] );
		$this->assertStringContainsString( "'avant'", file_get_contents( $this->dir . '/a.php' ), 'Rien d\'écrit avant validation' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertCount( 1, $this->client->requests, 'Le proxy n\'est pas rappelé tant que la personne n\'a pas décidé' );

		$decision = $this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) )->get_data();
		$this->assertSame( 'running', $decision['status'] );
		$this->assertSame( 'applied', $decision['actions'][0]['status'] );
		$this->assertTrue( $decision['actions'][0]['can_undo'] );
		$this->assertStringContainsString( "'après'", file_get_contents( $this->dir . '/a.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->rest( 'POST', "/agent/conversations/{$proposal['id']}/step" );
		$result = $this->last_tool_result();
		$this->assertSame( 'tool_result', $result['type'] );
		$this->assertFalse( $result['is_error'] );
		$this->assertStringContainsString( 'modifié', $result['content'] );

		$undo = $this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" )->get_data();
		$this->assertSame( 'undone', $undo['actions'][0]['status'] );
		$this->assertStringContainsString( "'avant'", file_get_contents( $this->dir . '/a.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( 409, $this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" )->get_status() );
		$this->assertSame( 409, $this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) )->get_status() );
	}

	/**
	 * Refus avec commentaire : rien d'écrit, commentaire transmis à l'agent.
	 */
	public function test_reject_with_comment() {
		$proposal = $this->propose(
			'edit_file',
			array(
				'path'     => 'upsidly-agent-write-test/a.php',
				'old_text' => "echo 'avant';",
				'new_text' => "echo 'non';",
			)
		);
		$decision = $this->rest(
			'POST',
			"/agent/actions/{$proposal['action']['id']}/decide",
			array(
				'decision' => 'reject',
				'comment'  => 'Passe plutôt par le thème enfant',
			)
		)->get_data();

		$this->assertSame( 'rejected', $decision['actions'][0]['status'] );
		$this->assertStringContainsString( "'avant'", file_get_contents( $this->dir . '/a.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->rest( 'POST', "/agent/conversations/{$proposal['id']}/step" );
		$result = $this->last_tool_result();
		$this->assertTrue( $result['is_error'] );
		$this->assertStringContainsString( 'Passe plutôt par le thème enfant', $result['content'] );
	}

	/**
	 * Erreur de syntaxe PHP : refus immédiat, sans attente, fichier intact.
	 */
	public function test_php_syntax_error_is_refused_before_validation() {
		$proposal = $this->propose(
			'edit_file',
			array(
				'path'     => 'upsidly-agent-write-test/a.php',
				'old_text' => "echo 'avant';",
				'new_text' => "echo 'cassé'",
			)
		);

		$this->assertSame( 'idle', $proposal['step']['status'], 'Pas d\'attente : l\'erreur est renvoyée à l\'agent' );
		$this->assertSame( 'failed', $proposal['action']['status'] );
		$this->assertStringContainsString( 'syntaxe', $proposal['action']['result'] );
		$this->assertStringContainsString( "'avant'", file_get_contents( $this->dir . '/a.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Site en erreur après la modification : restauration automatique.
	 */
	public function test_health_check_failure_reverts() {
		$proposal = $this->propose(
			'edit_file',
			array(
				'path'     => 'upsidly-agent-write-test/a.php',
				'old_text' => "echo 'avant';",
				'new_text' => "echo 'après';",
			)
		);
		remove_all_filters( 'upsidly_agent_health_check' );
		add_filter(
			'upsidly_agent_health_check',
			static function () {
				return array(
					'ok'      => false,
					'checked' => true,
					'detail'  => 'accueil répond 500',
				);
			}
		);

		$decision = $this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) )->get_data();

		$this->assertSame( 'reverted', $decision['actions'][0]['status'] );
		$this->assertFalse( $decision['actions'][0]['can_undo'] );
		$this->assertStringContainsString( 'accueil répond 500', $decision['actions'][0]['result'] );
		$this->assertStringContainsString( "'avant'", file_get_contents( $this->dir . '/a.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Niveau d'agent en lecture seule : écriture refusée sans attente.
	 */
	public function test_writes_not_allowed_in_read_level() {
		set_transient( 'upsidly_agent_status', array( 'agent' => array( 'level' => 'read' ) ), 300 );
		$proposal = $this->propose(
			'edit_file',
			array(
				'path'     => 'upsidly-agent-write-test/a.php',
				'old_text' => "echo 'avant';",
				'new_text' => "echo 'x';",
			)
		);

		$this->assertSame( 'failed', $proposal['action']['status'] );
		$this->assertStringContainsString( 'lecture seule', $proposal['action']['result'] );
	}

	/**
	 * create_file : création validée puis annulée ; chemins interdits refusés.
	 */
	public function test_create_file() {
		$proposal = $this->propose(
			'create_file',
			array(
				'path'    => 'upsidly-agent-write-test/sous/nouveau.css',
				'content' => 'body { color: red; }',
			)
		);
		$this->assertSame( 'pending', $proposal['action']['status'] );
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) );
		$this->assertFileExists( $this->dir . '/sous/nouveau.css' );

		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" );
		$this->assertFileDoesNotExist( $this->dir . '/sous/nouveau.css' );

		foreach ( array( '../wp-config.php', 'upsidly-agent-write-test/../../x.php', 'upsidly-agent-write-test/a.exe', 'upsidly-agent-write-test/a.php' ) as $path ) {
			$preview = Upsidly_Agent_Tool_Registry::preview(
				'create_file',
				array(
					'path'    => $path,
					'content' => 'x',
				)
			);
			$this->assertFalse( $preview['ok'], $path );
		}
	}

	/**
	 * update_option : options protégées refusées ; modification et annulation.
	 */
	public function test_update_option() {
		foreach ( array( 'siteurl', 'default_role', 'users_can_register', 'upsidly_agent_key', 'stripe_secret_key' ) as $name ) {
			$preview = Upsidly_Agent_Tool_Registry::preview(
				'update_option',
				array(
					'name'  => $name,
					'value' => 'x',
				)
			);
			$this->assertFalse( $preview['ok'], $name );
		}

		update_option( 'blogdescription', 'Ancien slogan' );
		$proposal = $this->propose(
			'update_option',
			array(
				'name'  => 'blogdescription',
				'value' => 'Nouveau slogan',
			)
		);
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) );
		$this->assertSame( 'Nouveau slogan', get_option( 'blogdescription' ) );
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" );
		$this->assertSame( 'Ancien slogan', get_option( 'blogdescription' ) );

		update_option( 'upsidly_test_tableau', array( 'a' => 1 ) );
		$bad = Upsidly_Agent_Tool_Registry::preview(
			'update_option',
			array(
				'name'  => 'upsidly_test_tableau',
				'value' => 'pas du json',
			)
		);
		$this->assertFalse( $bad['ok'] );
		$good = Upsidly_Agent_Tools_Write::apply_update_option(
			array(
				'name'  => 'upsidly_test_tableau',
				'value' => '{"a":2,"b":3}',
			),
			0
		);
		$this->assertSame( 'applied', $good['status'] );
		$this->assertSame(
			array(
				'a' => 2,
				'b' => 3,
			),
			get_option( 'upsidly_test_tableau' )
		);
	}

	/**
	 * save_content : création puis annulation (corbeille), modification puis annulation, Elementor refusé.
	 */
	public function test_save_content() {
		$proposal = $this->propose(
			'save_content',
			array(
				'post_type' => 'page',
				'title'     => 'Mentions légales',
				'content'   => "<!-- wp:paragraph -->\n<p>Texte.<script>x</script></p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) );
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'title'       => 'Mentions légales',
				'post_status' => 'any',
			)
		);
		$this->assertCount( 1, $pages );
		$page = $pages[0];
		$this->assertSame( 'draft', $page->post_status );
		$this->assertStringNotContainsString( '<script', $page->post_content );
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" );
		$this->assertSame( 'trash', get_post_status( $page->ID ) );

		$post     = self::factory()->post->create(
			array(
				'post_title'   => 'Ancien titre',
				'post_content' => 'Ancien contenu',
			)
		);
		$proposal = $this->propose(
			'save_content',
			array(
				'id'    => $post,
				'title' => 'Nouveau titre',
				'meta'  => array( 'mon_champ' => 'valeur' ),
			)
		);
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) );
		$this->assertSame( 'Nouveau titre', get_post( $post )->post_title );
		$this->assertSame( 'valeur', get_post_meta( $post, 'mon_champ', true ) );
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" );
		$this->assertSame( 'Ancien titre', get_post( $post )->post_title );
		$this->assertSame( '', get_post_meta( $post, 'mon_champ', true ) );

		$elementor = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $elementor, '_elementor_edit_mode', 'builder' );
		$refused = Upsidly_Agent_Tool_Registry::preview(
			'save_content',
			array(
				'id'      => $elementor,
				'content' => 'x',
			)
		);
		$this->assertFalse( $refused['ok'] );
		$this->assertStringContainsString( 'Elementor', $refused['error'] );

		$meta = Upsidly_Agent_Tool_Registry::preview(
			'save_content',
			array(
				'id'   => $post,
				'meta' => array( '_elementor_data' => 'x' ),
			)
		);
		$this->assertFalse( $meta['ok'] );
	}

	/**
	 * Extensions : activation validée puis annulée ; l'agent ne peut pas se désactiver lui-même.
	 */
	public function test_plugin_activation() {
		if ( ! file_exists( WP_PLUGIN_DIR . '/hello.php' ) ) {
			$this->markTestSkipped( 'Extension hello.php absente.' );
		}
		$proposal = $this->propose( 'plugin_activate', array( 'plugin' => 'hello.php' ) );
		$this->assertSame( 'pending', $proposal['action']['status'] );
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) );
		$this->assertTrue( is_plugin_active( 'hello.php' ) );
		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" );
		$this->assertFalse( is_plugin_active( 'hello.php' ) );

		$self = Upsidly_Agent_Tool_Registry::preview( 'plugin_deactivate', array( 'plugin' => plugin_basename( UPSIDLY_AGENT_FILE ) ) );
		$this->assertFalse( $self['ok'] );
	}

	/**
	 * Arrêt pendant l'attente : action clôturée comme refusée ; tout annuler.
	 */
	public function test_stop_while_awaiting_and_undo_all() {
		$proposal = $this->propose(
			'edit_file',
			array(
				'path'     => 'upsidly-agent-write-test/a.php',
				'old_text' => "echo 'avant';",
				'new_text' => "echo 'x';",
			)
		);
		$stop = $this->rest( 'POST', "/agent/conversations/{$proposal['id']}/stop" )->get_data();
		$this->assertSame( 'rejected', $stop['actions'][0]['status'] );

		// Deux modifications appliquées puis « Tout annuler ».
		update_option( 'blogdescription', 'A' );
		$id = $proposal['id'];
		foreach ( array( 'B', 'C' ) as $value ) {
			$action_id = Upsidly_Agent_Agent_Store::add_action(
				$id,
				array(
					'tool_use_id' => 'manuel_' . $value,
					'tool'        => 'update_option',
					'risk'        => 'write',
					'input'       => array(
						'name'  => 'blogdescription',
						'value' => $value,
					),
					'status'      => 'pending',
				)
			);
			Upsidly_Agent_Agent_Loop::decide( $action_id, 'approve' );
		}
		$this->assertSame( 'C', get_option( 'blogdescription' ) );

		$all = $this->rest( 'POST', "/agent/conversations/{$id}/undo-all" )->get_data();
		$this->assertSame( array(), $all['errors'] );
		$this->assertSame( 'A', get_option( 'blogdescription' ) );
	}

	/**
	 * Une autre personne ne peut pas décider d'une action.
	 */
	public function test_decide_requires_owner() {
		$proposal = $this->propose(
			'edit_file',
			array(
				'path'     => 'upsidly-agent-write-test/a.php',
				'old_text' => "echo 'avant';",
				'new_text' => "echo 'x';",
			)
		);
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		$this->assertSame( 403, $this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) )->get_status() );
		$this->assertStringContainsString( "'avant'", file_get_contents( $this->dir . '/a.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
