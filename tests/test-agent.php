<?php
/**
 * Tests de l'agent (lot A) : garde-fous, outils de lecture, boucle, accès.
 *
 * @package Upsidly_Agent
 */

/**
 * Agent.
 */
class Test_Upsidly_Agent_Agent extends WP_UnitTestCase {

	/**
	 * Faux client.
	 *
	 * @var Upsidly_Agent_Test_Fake_Client
	 */
	private $client;

	/**
	 * Administrateur.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Préparation.
	 */
	public function set_up() {
		parent::set_up();

		Upsidly_Agent_Agent_Store::create_tables();
		update_option( 'upsidly_agent_key', 'upa_' . str_repeat( 'c', 40 ) );
		update_option( 'upsidly_agent_agent_enabled', '1' );
		get_role( 'administrator' )->add_cap( 'upsidly_agent_use' );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $this->admin, 'upsidly_agent_ack', '2026-11-25 00:00:00' );
		wp_set_current_user( $this->admin );

		$this->client                     = new Upsidly_Agent_Test_Fake_Client();
		Upsidly_Agent_Agent_Loop::$client = $this->client;
		add_filter( 'upsidly_agent_sql_read_only_transaction', '__return_false' );

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
		remove_all_filters( 'upsidly_agent_sql_read_only_transaction' );
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
	 * Réponse du proxy pour une étape.
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
					'used'      => 10,
					'remaining' => 990,
					'resets_on' => '2026-12-01',
					'charged'   => 2,
				),
				'conversation' => array(
					'credits_used' => 4,
					'budget'       => 200,
					'steps'        => 2,
					'max_steps'    => 40,
				),
			),
		);
	}

	/**
	 * Chemins : wp-content uniquement, fichiers sensibles refusés.
	 */
	public function test_guard_paths() {
		$this->assertWPError( Upsidly_Agent_Guard::resolve( '../wp-config.php', 'file' ) );
		$this->assertWPError( Upsidly_Agent_Guard::resolve( '../../etc/passwd', 'file' ) );
		$this->assertWPError( Upsidly_Agent_Guard::resolve( 'themes/../../wp-config.php', 'file' ) );

		$dir = WP_CONTENT_DIR . '/upsidly-agent-test';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/wp-config.php', '<?php // test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/.env', 'SECRET=1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/ok.php', "<?php\necho 'ok';\ndefine( 'MY_API_KEY', 'abc123456789' );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertWPError( Upsidly_Agent_Guard::resolve( 'upsidly-agent-test/wp-config.php', 'file' ) );
		$this->assertWPError( Upsidly_Agent_Guard::resolve( 'upsidly-agent-test/.env', 'file' ) );
		$this->assertSame( realpath( $dir . '/ok.php' ), Upsidly_Agent_Guard::resolve( 'wp-content/upsidly-agent-test/ok.php', 'file' ) );

		$read = Upsidly_Agent_Tools_Files::read_file( array( 'path' => 'upsidly-agent-test/ok.php' ) );
		$this->assertStringContainsString( "2 | echo 'ok';", $read );
		$this->assertStringNotContainsString( 'abc123456789', $read );

		$search = Upsidly_Agent_Tools_Files::search_files(
			array(
				'query' => 'echo',
				'path'  => 'upsidly-agent-test',
			)
		);
		$this->assertStringContainsString( 'upsidly-agent-test/ok.php:2:', $search );

		if ( function_exists( 'symlink' ) && @symlink( ABSPATH . 'wp-config-sample.php', $dir . '/lien.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->assertWPError( Upsidly_Agent_Guard::resolve( 'upsidly-agent-test/lien.php', 'file' ), 'Lien symbolique sortant refusé' );
			unlink( $dir . '/lien.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		foreach ( array( 'wp-config.php', '.env', 'ok.php' ) as $file ) {
			unlink( $dir . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Masquage des secrets et des données personnelles.
	 */
	public function test_redaction() {
		$text = "define( 'AUTH_KEY', 'super-secret-value' );\nclé sk-ant-abcdefghijklmnopqrstuvwxyz\ncontact : jean.dupont@example.com, 06 12 34 56 78\napi_key = 'zzzzzzzzzz'";
		$out  = Upsidly_Agent_Guard::redact_text( $text );

		$this->assertStringNotContainsString( 'super-secret-value', $out );
		$this->assertStringNotContainsString( 'sk-ant-abcdefghijklmnopqrstuvwxyz', $out );
		$this->assertStringNotContainsString( 'jean.dupont@example.com', $out );
		$this->assertStringNotContainsString( '06 12 34 56 78', $out );
		$this->assertStringNotContainsString( 'zzzzzzzzzz', $out );

		$value = Upsidly_Agent_Guard::redact_value(
			array(
				'stripe_secret_key' => 'sk_live_123',
				'billing_email'     => 'a@b.fr',
				'titre'             => 'Bonjour',
				'meta_key'          => 'mon_reglage',
			)
		);
		$this->assertSame( '[masqué]', $value['stripe_secret_key'] );
		$this->assertSame( '[masqué]', $value['billing_email'] );
		$this->assertSame( 'Bonjour', $value['titre'] );
		$this->assertSame( 'mon_reglage', $value['meta_key'] );

		$options = Upsidly_Agent_Tools_Settings::get_options( array( 'names' => array( 'blogname', 'upsidly_agent_key', 'admin_email' ) ) );
		$this->assertStringNotContainsString( str_repeat( 'c', 40 ), $options );
		$this->assertStringNotContainsString( get_option( 'admin_email' ), $options );
		$this->assertStringContainsString( get_option( 'blogname' ), $options );
	}

	/**
	 * SQL : lecture seule, une instruction, LIMIT imposé, données personnelles masquées.
	 */
	public function test_read_only_sql() {
		$this->assertWPError( Upsidly_Agent_Guard::read_only_sql( 'DELETE FROM wp_posts' ) );
		$this->assertWPError( Upsidly_Agent_Guard::read_only_sql( 'SELECT 1; DROP TABLE wp_posts' ) );
		$this->assertWPError( Upsidly_Agent_Guard::read_only_sql( "SELECT * FROM wp_users INTO OUTFILE '/tmp/x'" ) );
		$this->assertWPError( Upsidly_Agent_Guard::read_only_sql( 'UPDATE wp_options SET option_value = 1' ) );
		$this->assertSame( 'SELECT 1 LIMIT 200', Upsidly_Agent_Guard::read_only_sql( '  SELECT 1;  ' ) );
		$this->assertSame( 'SELECT 1 LIMIT 5', Upsidly_Agent_Guard::read_only_sql( 'SELECT 1 LIMIT 5' ) );

		global $wpdb;
		$result = Upsidly_Agent_Tools_Database::db_query_read( array( 'sql' => "SELECT user_login, user_email, user_pass FROM {$wpdb->users}" ) );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '[masqué]', $result );
		$this->assertStringNotContainsString( '$P$', $result );
		$this->assertStringNotContainsString( '$wp$', $result );
	}

	/**
	 * Outils de diagnostic et de contenu : sortie exploitable.
	 */
	public function test_read_tools() {
		$overview = Upsidly_Agent_Tool_Registry::execute( 'site_overview', array() );
		$this->assertFalse( $overview['is_error'] );
		$this->assertStringContainsString( '"prefixe_tables"', $overview['content'] );

		$post = self::factory()->post->create( array( 'post_title' => 'Bonjour test' ) );
		$list = Upsidly_Agent_Tool_Registry::execute( 'list_content', array( 'search' => 'Bonjour test' ) );
		$this->assertStringContainsString( 'Bonjour test', $list['content'] );
		$item = Upsidly_Agent_Tool_Registry::execute( 'get_content', array( 'id' => $post ) );
		$this->assertStringContainsString( '"editeur"', $item['content'] );

		$unknown = Upsidly_Agent_Tool_Registry::execute( 'edit_file', array() );
		$this->assertTrue( $unknown['is_error'] );

		$long = Upsidly_Agent_Guard::truncate( str_repeat( 'a', 25000 ) );
		$this->assertStringContainsString( 'tronqué', $long );
		$this->assertLessThan( 20300, mb_strlen( $long ) );
	}

	/**
	 * Boucle : outil de lecture exécuté, résultat renvoyé au proxy, conversation terminée.
	 */
	public function test_loop_runs_tools_and_finishes() {
		$this->client->queue['/v1/agent/turn'] = array(
			self::turn(
				array(
					array(
						'type' => 'text',
						'text' => 'Je regarde.',
					),
					array(
						'type'  => 'tool_use',
						'id'    => 'toolu_1',
						'name'  => 'site_overview',
						'input' => array(),
					),
				)
			),
			self::turn(
				array(
					array(
						'type' => 'text',
						'text' => 'Tout va bien.',
					),
				),
				'end_turn'
			),
		);

		$created = $this->rest( 'POST', '/agent/conversations', array( 'message' => 'Mon site va bien ?' ) );
		$this->assertSame( 200, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id = $created->get_data()['conversation']['id'];
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{26}$/', $id );

		$step1 = $this->rest( 'POST', "/agent/conversations/{$id}/step" )->get_data();
		$this->assertSame( 'running', $step1['status'] );
		$this->assertSame( 'assistant', end( $step1['messages'] )['role'] );

		$step2 = $this->rest( 'POST', "/agent/conversations/{$id}/step" )->get_data();
		$this->assertSame( 'idle', $step2['status'] );

		// Deuxième appel au proxy : l'historique contient le résultat de site_overview.
		$sent    = $this->client->requests[1]['body'];
		$history = $sent['messages'];
		$this->assertSame( 'user', $history[2]['role'] );
		$this->assertSame( 'tool_result', $history[2]['content'][0]['type'] );
		$this->assertSame( 'toolu_1', $history[2]['content'][0]['tool_use_id'] );
		$this->assertStringContainsString( 'prefixe_tables', $history[2]['content'][0]['content'] );
		$this->assertSame( $id, $sent['conversation_id'] );
		$this->assertArrayHasKey( 'file_edit_allowed', $sent['environment'] );
		$this->assertStringNotContainsString( str_repeat( 'c', 40 ), wp_json_encode( $sent ) );

		$conversation = Upsidly_Agent_Agent_Store::get( $id );
		$this->assertSame( 'idle', $conversation['status'] );
		$this->assertSame( '4', (string) $conversation['credits'] );

		$this->assertSame( 'idle', $this->rest( 'POST', "/agent/conversations/{$id}/step" )->get_data()['status'] );
	}

	/**
	 * Arrêt puis nouveau message : demandes d'outils closes, historique valide.
	 */
	public function test_stop_then_new_message() {
		$this->client->queue['/v1/agent/turn'] = array(
			self::turn(
				array(
					array(
						'type'  => 'tool_use',
						'id'    => 'toolu_9',
						'name'  => 'read_logs',
						'input' => array(),
					),
				)
			),
		);

		$id = $this->rest( 'POST', '/agent/conversations', array( 'message' => 'Erreur ?' ) )->get_data()['conversation']['id'];
		$this->rest( 'POST', "/agent/conversations/{$id}/step" );
		$this->assertSame( 'stopped', $this->rest( 'POST', "/agent/conversations/{$id}/stop" )->get_data()['status'] );
		$this->assertSame( 'stopped', $this->rest( 'POST', "/agent/conversations/{$id}/step" )->get_data()['status'] );

		$this->rest( 'POST', "/agent/conversations/{$id}/messages", array( 'message' => 'Autre chose' ) );
		$messages = Upsidly_Agent_Agent_Store::messages( $id );
		$last     = end( $messages );

		$this->assertSame( 'user', $last['role'] );
		$this->assertSame( 'tool_result', $last['content'][0]['type'] );
		$this->assertTrue( $last['content'][0]['is_error'] );
		$this->assertSame( 'Autre chose', $last['content'][1]['text'] );
		$this->assertSame( 'running', Upsidly_Agent_Agent_Store::get( $id )['status'] );
	}

	/**
	 * Erreur du proxy : statut error, message traduit, historique intact.
	 */
	public function test_proxy_error() {
		$this->client->queue['/v1/agent/turn'] = array(
			array(
				'status' => 403,
				'body'   => array(
					'error' => array(
						'code'    => 'uap_agent_disabled',
						'message' => 'Agent désactivé.',
					),
				),
			),
		);

		$id   = $this->rest( 'POST', '/agent/conversations', array( 'message' => 'Bonjour' ) )->get_data()['conversation']['id'];
		$step = $this->rest( 'POST', "/agent/conversations/{$id}/step" )->get_data();

		$this->assertSame( 'error', $step['status'] );
		$this->assertSame( 'uap_agent_disabled', $step['error']['code'] );
		$this->assertStringContainsString( 'pas activé', $step['error']['message'] );
		$this->assertCount( 1, Upsidly_Agent_Agent_Store::messages( $id ) );
	}

	/**
	 * Accès : capacité, réglage du site, avertissement, propriétaire de la conversation.
	 */
	public function test_access_rules() {
		$id = $this->rest( 'POST', '/agent/conversations', array( 'message' => 'Bonjour' ) )->get_data()['conversation']['id'];

		// Autre administrateur : pas d'accès à la conversation.
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $other, 'upsidly_agent_ack', '1' );
		wp_set_current_user( $other );
		$this->assertSame( 403, $this->rest( 'GET', "/agent/conversations/{$id}" )->get_status() );

		// Éditeur : pas de capacité.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', '/agent/conversations' )->get_status() );

		// Agent désactivé sur le site.
		wp_set_current_user( $this->admin );
		update_option( 'upsidly_agent_agent_enabled', '0' );
		$this->assertSame( 403, $this->rest( 'GET', '/agent/conversations' )->get_status() );
		update_option( 'upsidly_agent_agent_enabled', '1' );

		// Avertissement non accepté.
		delete_user_meta( $this->admin, 'upsidly_agent_ack' );
		$response = $this->rest( 'POST', '/agent/conversations', array( 'message' => 'Bonjour' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'upsidly_agent_ack', $response->get_data()['code'] );
		$this->assertSame( 200, $this->rest( 'POST', '/agent/ack' )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', '/agent/conversations', array( 'message' => 'Bonjour' ) )->get_status() );
	}

	/**
	 * Purge : conversations de plus de 90 jours supprimées.
	 */
	public function test_purge() {
		global $wpdb;
		$old = Upsidly_Agent_Agent_Store::create( $this->admin, 'Ancienne' );
		Upsidly_Agent_Agent_Store::add_message( $old, 'user', 'x' );
		$new = Upsidly_Agent_Agent_Store::create( $this->admin, 'Récente' );
		$wpdb->update( Upsidly_Agent_Agent_Store::table( 'conversations' ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ), array( 'id' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		Upsidly_Agent_Agent_Store::purge();

		$this->assertNull( Upsidly_Agent_Agent_Store::get( $old ) );
		$this->assertSame( array(), Upsidly_Agent_Agent_Store::messages( $old ) );
		$this->assertNotNull( Upsidly_Agent_Agent_Store::get( $new ) );
	}

	/**
	 * Les données injectées dans la page Agent ne contiennent jamais la clé.
	 */
	public function test_agent_data_never_contains_key() {
		$json = wp_json_encode( Upsidly_Agent_Admin::agent_data() );
		$this->assertStringNotContainsString( str_repeat( 'c', 40 ), $json );
		$this->assertStringNotContainsString( 'upa_', $json );
	}

	/**
	 * Agent activé par défaut (option absente).
	 */
	public function test_agent_enabled_by_default() {
		delete_option( 'upsidly_agent_agent_enabled' );
		$this->assertTrue( Upsidly_Agent_Settings::agent_enabled() );
		update_option( 'upsidly_agent_agent_enabled', '0' );
		$this->assertFalse( Upsidly_Agent_Settings::agent_enabled() );
	}
}

