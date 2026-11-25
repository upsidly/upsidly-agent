<?php
/**
 * Tests des outils de thème : compréhension du thème, modification propre d'une partie de modèle (thème bloc).
 *
 * @package Upsidly_Agent
 */

/**
 * Outils de thème.
 */
class Test_Upsidly_Agent_Agent_Theme extends WP_UnitTestCase {

	use Upsidly_Agent_Test_Connector_Actions;

	/**
	 * Thème actif avant le test.
	 *
	 * @var string
	 */
	private $previous_theme;

	/**
	 * Préparation : un thème bloc par défaut de WordPress.
	 */
	public function set_up() {
		parent::set_up();
		Upsidly_Agent_Agent_Store::create_tables();
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
		$this->previous_theme = get_stylesheet();
		foreach ( array( 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree' ) as $slug ) {
			if ( wp_get_theme( $slug )->exists() ) {
				switch_theme( $slug );
				return;
			}
		}
		$this->markTestSkipped( 'Aucun thème bloc par défaut installé.' );
	}

	/**
	 * Nettoyage.
	 */
	public function tear_down() {
		switch_theme( $this->previous_theme );
		remove_all_filters( 'upsidly_agent_health_check' );
		Upsidly_Agent_Tool_Registry::set_level( null );
		parent::tear_down();
	}

	/**
	 * theme_info : thème bloc, partie « footer », méthode native indiquée, performance.
	 */
	public function test_theme_info_explains_how_to_change_the_footer() {
		$info = Upsidly_Agent_Tools_Theme::theme_info( array() );
		$this->assertStringContainsString( 'thème bloc', $info );
		$this->assertStringContainsString( '"slug": "footer"', $info );
		$this->assertStringContainsString( 'save_template', $info );
		$this->assertStringContainsString( 'extensions_cache_optimisation', $info );

		$footer = Upsidly_Agent_Tools_Theme::get_template(
			array(
				'type' => 'wp_template_part',
				'slug' => 'footer',
			)
		);
		$this->assertStringContainsString( 'fichier du thème', $footer );
	}

	/**
	 * save_template : pied de page vidé proprement (personnalisation en base), puis annulation.
	 */
	public function test_save_template_empties_footer_then_undo() {
		$proposal = $this->propose(
			'save_template',
			array(
				'type'    => 'wp_template_part',
				'slug'    => 'footer',
				'content' => '',
			)
		);
		$this->assertTrue( $proposal['step']['pending'] );
		$this->assertStringContainsString( 'footer', $proposal['action']['title'] );
		$this->assertSame( 'theme', get_block_template( get_stylesheet() . '//footer', 'wp_template_part' )->source, 'Rien n\'est modifié avant validation' );

		$decision = $this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/decide", array( 'decision' => 'approve' ) )->get_data();
		$this->assertSame( 'applied', end( $decision['actions'] )['status'] );
		$footer = get_block_template( get_stylesheet() . '//footer', 'wp_template_part' );
		$this->assertSame( 'custom', $footer->source );
		$this->assertSame( '', trim( $footer->content ) );
		$this->assertSame( 'footer', $footer->area );

		$this->rest( 'POST', "/agent/actions/{$proposal['action']['id']}/undo" );
		$this->assertSame( 'theme', get_block_template( get_stylesheet() . '//footer', 'wp_template_part' )->source, 'Retour au fichier du thème' );
	}

	/**
	 * Refus : script, PHP, modèle vide, aucun changement, slug inconnu.
	 */
	public function test_save_template_refusals() {
		foreach (
			array(
				array( 'wp_template_part', 'footer', '<script>alert(1)</script>' ),
				array( 'wp_template_part', 'footer', '<?php echo 1; ?>' ),
				array( 'wp_template', 'index', '' ),
				array( 'wp_template_part', 'inexistant', 'x' ),
			) as $case
		) {
			$preview = Upsidly_Agent_Tool_Registry::preview(
				'save_template',
				array(
					'type'    => $case[0],
					'slug'    => $case[1],
					'content' => $case[2],
				)
			);
			$this->assertFalse( $preview['ok'], wp_json_encode( $case ) );
		}
		$current = get_block_template( get_stylesheet() . '//footer', 'wp_template_part' )->content;
		$this->assertFalse(
			Upsidly_Agent_Tool_Registry::preview(
				'save_template',
				array(
					'type'    => 'wp_template_part',
					'slug'    => 'footer',
					'content' => $current,
				)
			)['ok']
		);
	}
}
