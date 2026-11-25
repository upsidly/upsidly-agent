<?php
/**
 * Tests du connecteur : signature, chiffrement du secret, opérations, liaison.
 *
 * @package Upsidly_Agent
 */

/**
 * Connecteur Upsidly.
 */
class Test_Upsidly_Agent_Connector extends WP_UnitTestCase {

	/**
	 * Administrateur relié.
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
		add_filter(
			'upsidly_agent_health_check',
			static function () {
				return array(
					'ok'      => true,
					'checked' => true,
				);
			}
		);
	}

	/**
	 * Nettoyage.
	 */
	public function tear_down() {
		Upsidly_Agent_Tool_Registry::set_level( null );
		parent::tear_down();
	}

	/**
	 * Signature valide acceptée une seule fois ; fausse, expirée, absente : refusées.
	 */
	public function test_signature_checks() {
		$secret = 'secret-de-test-0123456789abcdef';
		$body   = '{"op":"ping"}';
		$now    = 1800000000;
		$nonce  = 'nonce_abcdefghijklmnop';
		$sig    = Upsidly_Agent_Connector::sign( $secret, $body, (string) $now, $nonce );

		$this->assertNull( Upsidly_Agent_Connector::check_signature( $secret, $body, (string) $now, $nonce, $sig, $now + 10 ) );
		$this->assertSame( 'replay', Upsidly_Agent_Connector::check_signature( $secret, $body, (string) $now, $nonce, $sig, $now + 10 )[0] );

		$nonce2 = 'nonce_qrstuvwxyz012345';
		$sig2   = Upsidly_Agent_Connector::sign( $secret, $body, (string) $now, $nonce2 );
		$this->assertSame( 'bad_signature', Upsidly_Agent_Connector::check_signature( 'autre-secret', $body, (string) $now, $nonce2, $sig2, $now )[0] );
		$this->assertSame( 'bad_signature', Upsidly_Agent_Connector::check_signature( $secret, '{"op":"tools.run"}', (string) $now, $nonce2, $sig2, $now )[0] );
		$this->assertSame( 'clock_skew', Upsidly_Agent_Connector::check_signature( $secret, $body, (string) $now, $nonce2, $sig2, $now + 600 )[0] );
		$this->assertSame( 'bad_signature', Upsidly_Agent_Connector::check_signature( $secret, $body, null, $nonce2, $sig2, $now )[0] );
		$this->assertSame( 'bad_signature', Upsidly_Agent_Connector::check_signature( $secret, $body, (string) $now, 'court', $sig2, $now )[0] );
	}

	/**
	 * Le secret est chiffré au repos et relu correctement.
	 */
	public function test_secret_encrypted_at_rest() {
		$stored = Upsidly_Agent_Connector::encrypt( 'mon-secret' );
		$this->assertStringNotContainsString( 'mon-secret', $stored );
		$this->assertSame( 'mon-secret', Upsidly_Agent_Connector::decrypt( $stored ) );
		$this->assertSame( '', Upsidly_Agent_Connector::decrypt( 's1:abc' ) );
		$this->assertSame( '', Upsidly_Agent_Connector::decrypt( 'nimporte' ) );
	}

	/**
	 * Liaison : le code est échangé contre le secret, enregistré (chiffré) pour l'administrateur courant.
	 */
	public function test_claim_stores_secret_and_key() {
		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				if ( str_ends_with( $url, '/v1/connector/claim' ) ) {
					$captured = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'site_id' => 7,
								'secret'  => 'secret-partage-0123456789abcdef0123456789',
								'key'     => 'upa_' . str_repeat( 'd', 40 ),
							)
						),
					);
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'site' => array( 'plan' => 'test' ), 'credits' => array( 'remaining' => 50 ) ) ),
				);
			},
			10,
			3
		);

		$this->assertTrue( Upsidly_Agent_Connector::claim( str_repeat( 'a', 64 ), 'etat' ) );
		$this->assertSame( str_repeat( 'a', 64 ), $captured['code'] );
		$this->assertStringEndsWith( '/wp-admin/admin-ajax.php', $captured['connector_url'] );

		$link = Upsidly_Agent_Connector::get();
		$this->assertSame( 7, $link['site_id'] );
		$this->assertSame( $this->admin, $link['user_id'] );
		$this->assertSame( 'secret-partage-0123456789abcdef0123456789', $link['secret'] );
		$this->assertStringNotContainsString( 'secret-partage', wp_json_encode( get_option( 'upsidly_agent_connector' ) ) );
	}

	/**
	 * Liaison refusée par Upsidly : message transmis, rien d'enregistré.
	 */
	public function test_claim_refused() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 422 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Code expiré.' ) ) ),
				);
			}
		);
		$result = Upsidly_Agent_Connector::claim( str_repeat( 'b', 64 ), 'etat' );
		$this->assertWPError( $result );
		$this->assertSame( 'Code expiré.', $result->get_error_message() );
		$this->assertNull( Upsidly_Agent_Connector::get() );
	}

	/**
	 * Ping : version et environnement.
	 */
	public function test_ping() {
		$result = Upsidly_Agent_Connector::dispatch( array( 'op' => 'ping' ) );
		$this->assertSame( UPSIDLY_AGENT_VERSION, $result['plugin_version'] );
		$this->assertArrayHasKey( 'wp_version', $result['environment'] );
	}

	/**
	 * Lecture exécutée tout de suite ; écriture en attente, puis appliquée après validation.
	 */
	public function test_tools_run_then_decide() {
		$id   = str_repeat( 'k', 26 );
		$post = self::factory()->post->create( array( 'post_title' => 'Avant' ) );

		$read = Upsidly_Agent_Connector::dispatch(
			array(
				'op'              => 'tools.run',
				'level'           => 'write',
				'conversation_id' => $id,
				'uses'            => array(
					array(
						'id'    => 'toolu_read',
						'name'  => 'get_content',
						'input' => array( 'id' => $post ),
					),
				),
			)
		);
		$this->assertFalse( $read['pending'] );
		$this->assertSame( 'toolu_read', $read['results'][0]['tool_use_id'] );
		$this->assertStringContainsString( 'Avant', $read['results'][0]['content'] );

		$uses  = array(
			array(
				'id'    => 'toolu_write',
				'name'  => 'save_content',
				'input' => array(
					'id'     => $post,
					'title'  => 'Après',
					'raison' => 'Test',
				),
			),
		);
		$write = Upsidly_Agent_Connector::dispatch(
			array(
				'op'              => 'tools.run',
				'level'           => 'write',
				'conversation_id' => $id,
				'uses'            => $uses,
			)
		);
		$this->assertTrue( $write['pending'] );
		$action = end( $write['actions'] );
		$this->assertSame( 'pending', $action['status'] );
		$this->assertSame( 'Avant', get_post( $post )->post_title );

		$decided = Upsidly_Agent_Connector::dispatch(
			array(
				'op'        => 'actions.decide',
				'level'     => 'write',
				'action_id' => $action['id'],
				'decision'  => 'approve',
			)
		);
		$this->assertSame( 'Après', get_post( $post )->post_title );
		$this->assertSame( 'applied', end( $decided['actions'] )['status'] );

		$list = Upsidly_Agent_Connector::dispatch(
			array(
				'op'              => 'actions.list',
				'conversation_id' => $id,
				'uses'            => $uses,
			)
		);
		$this->assertFalse( $list['pending'] );
		$this->assertSame( 'toolu_write', $list['results'][0]['tool_use_id'] );

		Upsidly_Agent_Connector::dispatch(
			array(
				'op'        => 'actions.undo',
				'action_id' => $action['id'],
			)
		);
		$this->assertSame( 'Avant', get_post( $post )->post_title );
	}

	/**
	 * Le niveau transmis par Upsidly s'applique : en lecture seule, l'écriture est refusée.
	 */
	public function test_level_from_upsidly_is_enforced() {
		$post   = self::factory()->post->create( array( 'post_title' => 'Intact' ) );
		$result = Upsidly_Agent_Connector::dispatch(
			array(
				'op'              => 'tools.run',
				'level'           => 'read',
				'conversation_id' => str_repeat( 'r', 26 ),
				'uses'            => array(
					array(
						'id'    => 'toolu_w',
						'name'  => 'save_content',
						'input' => array(
							'id'    => $post,
							'title' => 'Modifié',
						),
					),
				),
			)
		);
		$this->assertFalse( $result['pending'] );
		$this->assertTrue( $result['results'][0]['is_error'] );
		$this->assertSame( 'Intact', get_post( $post )->post_title );
	}

	/**
	 * Conversation invalide et opération inconnue : erreurs.
	 */
	public function test_bad_requests() {
		$this->assertWPError( Upsidly_Agent_Connector::dispatch( array( 'op' => 'tools.run', 'conversation_id' => '../x' ) ) );
		$this->assertWPError( Upsidly_Agent_Connector::dispatch( array( 'op' => 'inconnu' ) ) );
		$this->assertWPError( Upsidly_Agent_Connector::dispatch( array( 'op' => 'actions.decide', 'action_id' => 1, 'decision' => 'peut-etre' ) ) );
	}
}
