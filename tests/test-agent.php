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

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		add_filter( 'upsidly_agent_sql_read_only_transaction', '__return_false' );
	}

	/**
	 * Nettoyage.
	 */
	public function tear_down() {
		remove_all_filters( 'upsidly_agent_sql_read_only_transaction' );
		parent::tear_down();
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

		update_option( 'upsidly_agent_connector', array( 'secret' => 's1:secret-chiffre-zzz' ) );
		$options = Upsidly_Agent_Tools_Settings::get_options( array( 'names' => array( 'blogname', 'upsidly_agent_connector', 'admin_email' ) ) );
		$this->assertStringNotContainsString( 'secret-chiffre-zzz', $options );
		$stored = Upsidly_Agent_Connector::encrypt( 'secret' );
		$this->assertStringNotContainsString( substr( $stored, 3, 20 ), Upsidly_Agent_Guard::redact_text( 'option_value: a:1:{s:6:"secret";s:60:"' . $stored . '";}' ) );
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
	 * Le secret du connecteur n'apparaît jamais dans les données renvoyées à Upsidly.
	 */
	public function test_ping_never_contains_secret() {
		update_option(
			'upsidly_agent_connector',
			array(
				'site_id'   => 1,
				'user_id'   => $this->admin,
				'linked_at' => time(),
				'secret'    => Upsidly_Agent_Connector::encrypt( 'secret-tres-confidentiel-123' ),
			)
		);
		$json = wp_json_encode( Upsidly_Agent_Connector::dispatch( array( 'op' => 'ping' ) ) );
		$this->assertStringNotContainsString( 'secret-tres-confidentiel', $json );
		$this->assertStringNotContainsString( 'upsidly_agent_connector', $json );
	}
}

