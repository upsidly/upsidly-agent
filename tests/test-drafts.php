<?php
/**
 * Tests des brouillons et des métadonnées SEO.
 *
 * Upsidly_Agent_Drafts + Upsidly_Agent_Seo.
 *
 * @package Upsidly_Agent
 */

/**
 * Brouillons et SEO.
 */
class Test_Upsidly_Agent_Drafts extends WP_UnitTestCase {

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
}
