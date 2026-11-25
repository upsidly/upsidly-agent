<?php
/**
 * Tests du nettoyage du contenu.
 *
 * @package Upsidly_Agent
 */

/**
 * Upsidly_Agent_Sanitizer.
 */
class Test_Upsidly_Agent_Sanitizer extends WP_UnitTestCase {

	/**
	 * Blocs autorisés conservés.
	 */
	public function test_allowed_blocks_are_kept() {
		$content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Titre</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Texte <strong>gras</strong>.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Un</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n"
			. "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

		$result = Upsidly_Agent_Sanitizer::content( $content );

		$this->assertSame( 0, $result['removed'] );
		$names = wp_list_pluck( parse_blocks( $result['content'] ), 'blockName' );
		$this->assertSame( array( 'core/heading', 'core/paragraph', 'core/list', 'core/separator' ), array_values( array_filter( $names ) ) );
		$this->assertStringContainsString( '<strong>gras</strong>', $result['content'] );
		$this->assertStringContainsString( '<li>Un</li>', $result['content'] );
	}

	/**
	 * Blocs core/html et core/embed retirés et comptés.
	 */
	public function test_html_and_embed_blocks_are_removed() {
		$content = "<!-- wp:paragraph -->\n<p>Gardé</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:html -->\n<div>Brut</div>\n<!-- /wp:html -->\n\n"
			. "<!-- wp:embed {\"url\":\"https://example.com/video\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">https://example.com/video</div></figure>\n<!-- /wp:embed -->";

		$result = Upsidly_Agent_Sanitizer::content( $content );

		$this->assertSame( 2, $result['removed'] );
		$this->assertStringContainsString( 'Gardé', $result['content'] );
		$this->assertStringNotContainsString( 'wp:html', $result['content'] );
		$this->assertStringNotContainsString( 'wp:embed', $result['content'] );
		$this->assertStringNotContainsString( 'Brut', $result['content'] );
	}

	/**
	 * Script et attributs on* supprimés.
	 */
	public function test_script_and_event_attributes_are_stripped() {
		$content = "<!-- wp:paragraph -->\n<p onclick=\"alert(1)\">Texte<script>alert('x')</script> <a href=\"https://example.com\" onmouseover=\"steal()\">lien</a></p>\n<!-- /wp:paragraph -->";

		$result = Upsidly_Agent_Sanitizer::content( $content );

		$this->assertStringNotContainsString( '<script', $result['content'] );
		$this->assertStringNotContainsString( 'onclick', $result['content'] );
		$this->assertStringNotContainsString( 'onmouseover', $result['content'] );
		$this->assertStringContainsString( 'href="https://example.com"', $result['content'] );
	}

	/**
	 * Contenu sans blocs converti en paragraphes (jamais en core/html).
	 */
	public function test_content_without_blocks_becomes_paragraphs() {
		$result = Upsidly_Agent_Sanitizer::content( "Premier paragraphe.\n\nSecond <em>paragraphe</em>.<script>x()</script>" );

		$blocks = array_values( array_filter( parse_blocks( $result['content'] ), static fn( $b ) => null !== $b['blockName'] ) );
		$this->assertCount( 2, $blocks );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertStringContainsString( 'Premier paragraphe.', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<em>paragraphe</em>', $blocks[1]['innerHTML'] );
		$this->assertStringNotContainsString( 'wp:html', $result['content'] );
		$this->assertStringNotContainsString( 'script', $result['content'] );

		$html = Upsidly_Agent_Sanitizer::content( '<p>Un</p><p>Deux</p>' );
		$this->assertSame( 2, substr_count( $html['content'], '<!-- wp:paragraph -->' ) );
	}

	/**
	 * Le contenu nettoyé reste un contenu en blocs valides (aucun avertissement de l'éditeur attendu).
	 */
	public function test_serialized_blocks_round_trip() {
		$content = "<!-- wp:paragraph -->\n<p>Texte</p>\n<!-- /wp:paragraph -->";
		$result  = Upsidly_Agent_Sanitizer::content( $content );

		$this->assertSame( $content, $result['content'] );
	}

	/**
	 * Champs : texte brut, slug, longueurs bornées, 6 étiquettes maximum.
	 */
	public function test_fields() {
		$fields = Upsidly_Agent_Sanitizer::fields(
			array(
				'titre'            => '<b>Titre</b> ' . str_repeat( 'a', 300 ),
				'slug'             => 'Mon Article Été',
				'meta_description' => str_repeat( 'm', 400 ),
				'etiquettes'       => array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'a', array( 'x' ) ),
				'notes'            => '<script>x</script>Vérifier le prix',
			)
		);

		$this->assertSame( 200, mb_strlen( $fields['titre'] ) );
		$this->assertStringStartsWith( 'Titre ', $fields['titre'] );
		$this->assertSame( 'mon-article-ete', $fields['slug'] );
		$this->assertSame( 320, mb_strlen( $fields['meta_description'] ) );
		$this->assertSame( array( 'a', 'b', 'c', 'd', 'e', 'f' ), $fields['etiquettes'] );
		$this->assertSame( 'Vérifier le prix', $fields['notes'] );
	}
}
