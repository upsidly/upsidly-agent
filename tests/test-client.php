<?php
/**
 * Tests de la traduction des erreurs du proxy.
 *
 * @package Upsidly_Agent
 */

/**
 * Upsidly_Agent_Client.
 */
class Test_Upsidly_Agent_Client extends WP_UnitTestCase {

	/**
	 * Codes du proxy → messages affichés.
	 */
	public function test_error_messages() {
		$cases = array(
			'uap_no_key'               => 'La clé de connexion est invalide. Vérifiez-la dans Réglages.',
			'uap_bad_key'              => 'La clé de connexion est invalide. Vérifiez-la dans Réglages.',
			'uap_site_disabled'        => 'L\'accès à l\'assistant est suspendu pour ce site. Contactez Upsidly.',
			'uap_site_mismatch'        => 'Cette clé est associée à un autre site.',
			'uap_rate_limited'         => 'Trop de demandes en peu de temps. Réessayez dans une minute.',
			'uap_global_cap'           => 'Le service est momentanément indisponible. Réessayez dans quelques minutes.',
			'uap_pricing_missing'      => 'Le service est momentanément indisponible. Réessayez dans quelques minutes.',
			'uap_upstream_unreachable' => 'Le service est momentanément indisponible. Réessayez dans quelques minutes.',
			'uap_upstream_error'       => 'Le service est momentanément indisponible. Réessayez dans quelques minutes.',
			'uap_incomplete'           => 'La génération n\'a pas abouti. Essayez avec une demande plus courte.',
			'upsidly_agent_network'    => 'Impossible de joindre le service. Réessayez dans quelques minutes.',
			'uap_internal'             => 'Une erreur inattendue s\'est produite.',
			'n_importe_quoi'           => 'Une erreur inattendue s\'est produite.',
		);

		foreach ( $cases as $code => $expected ) {
			$this->assertSame( $expected, Upsidly_Agent_Client::message_for( $code ), $code );
		}
	}

	/**
	 * Erreurs de saisie : message du proxy tel quel.
	 */
	public function test_input_errors_keep_proxy_message() {
		foreach ( array( 'uap_missing_input', 'uap_bad_input', 'uap_input_too_long' ) as $code ) {
			$this->assertSame( 'Champ requis manquant : « sujet ».', Upsidly_Agent_Client::message_for( $code, 'Champ requis manquant : « sujet ».' ) );
		}
	}

	/**
	 * Quota épuisé : date de renouvellement dans le message, credits et statut conservés.
	 */
	public function test_quota_error_normalized() {
		$error = Upsidly_Agent_Client::normalize(
			array(
				'status' => 402,
				'body'   => array(
					'error' => array(
						'code'    => 'uap_quota_exceeded',
						'message' => 'Crédits du mois épuisés.',
						'credits' => array(
							'limit'     => 1000,
							'used'      => 1003,
							'remaining' => 0,
							'resets_on' => '2026-12-01',
						),
					),
				),
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'uap_quota_exceeded', $error->get_error_code() );
		$this->assertStringStartsWith( 'Vos crédits du mois sont épuisés. Ils seront renouvelés le ', $error->get_error_message() );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( '2026-12-01 00:00:00 UTC' ), new DateTimeZone( 'UTC' ) ), $error->get_error_message() );
		$data = $error->get_error_data();
		$this->assertSame( 402, $data['status'] );
		$this->assertSame( 0, $data['credits']['remaining'] );
	}

	/**
	 * Réponse illisible : erreur inattendue ; succès : tableau décodé.
	 */
	public function test_normalize() {
		$this->assertSame( array( 'ok' => true ), Upsidly_Agent_Client::normalize( array( 'status' => 200, 'body' => array( 'ok' => true ) ) ) );

		$error = Upsidly_Agent_Client::normalize( array( 'status' => 400, 'body' => null ) );
		$this->assertSame( 'upsidly_agent_unexpected', $error->get_error_code() );
		$this->assertSame( 'Une erreur inattendue s\'est produite.', $error->get_error_message() );

		// Page HTML d'erreur Cloudflare (502) : message « service indisponible ».
		$gateway = Upsidly_Agent_Client::normalize( array( 'status' => 502, 'body' => null ) );
		$this->assertSame( 'uap_upstream_unreachable', $gateway->get_error_code() );
		$this->assertStringContainsString( 'momentanément indisponible', $gateway->get_error_message() );
	}

	/**
	 * Sans clé : aucune requête, erreur claire.
	 */
	public function test_no_key() {
		delete_option( 'upsidly_agent_key' );
		$result = ( new Upsidly_Agent_Client() )->status();

		$this->assertWPError( $result );
		$this->assertSame( 'upsidly_agent_no_key', $result->get_error_code() );
	}

	/**
	 * Les en-têtes attendus sont envoyés, la clé n'est envoyée qu'au proxy.
	 */
	public function test_request_headers() {
		update_option( 'upsidly_agent_key', 'upa_' . str_repeat( 'a', 40 ) );
		$captured = null;

		$filter = static function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array(
				'args' => $args,
				'url'  => $url,
			);
			return array(
				'headers'  => array(),
				'body'     => '{"ok":true}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		$result = ( new Upsidly_Agent_Client() )->disconnect();
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( UPSIDLY_AGENT_API_URL . '/v1/disconnect', $captured['url'] );
		$headers = $captured['args']['headers'];
		$this->assertSame( 'Bearer upa_' . str_repeat( 'a', 40 ), $headers['Authorization'] );
		$this->assertSame( 'upa_' . str_repeat( 'a', 40 ), $headers['X-Upsidly-Key'] );
		$this->assertSame( home_url(), $headers['X-Upsidly-Site'] );
		$this->assertSame( UPSIDLY_AGENT_VERSION, $headers['X-Upsidly-Plugin-Version'] );
		$this->assertSame( 10, $captured['args']['timeout'] );
	}

	/**
	 * Crédits de test épuisés : message dédié.
	 */
	public function test_quota_once_message() {
		$error = Upsidly_Agent_Client::normalize(
			array(
				'status' => 402,
				'body'   => array(
					'error' => array(
						'code'    => 'uap_quota_exceeded',
						'message' => 'Crédits de test épuisés.',
						'credits' => array(
							'limit'     => 50,
							'used'      => 50,
							'remaining' => 0,
							'resets_on' => null,
							'period'    => 'once',
						),
					),
				),
			)
		);
		$this->assertSame( 'Vos crédits de test sont épuisés. Contactez Upsidly pour continuer.', $error->get_error_message() );
	}
}

