<?php
/**
 * Amorçage des tests d'intégration WordPress.
 *
 * @package Upsidly_Agent
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Suite de tests WordPress introuvable dans {$_tests_dir}. Lancez bin/install-wp-tests.sh." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Chemin des polyfills PHPUnit pour la suite de tests WordPress.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Extensions tierces installées par la CI (WooCommerce, ACF) pour les tests de « Rédiger un contenu ».
		if ( getenv( 'UPSIDLY_TEST_PLUGINS' ) ) {
			foreach ( array( 'woocommerce/woocommerce.php', 'advanced-custom-fields/acf.php' ) as $plugin ) {
				if ( file_exists( WP_CONTENT_DIR . '/plugins/' . $plugin ) ) {
					require WP_CONTENT_DIR . '/plugins/' . $plugin;
				}
			}
		}
		require dirname( __DIR__ ) . '/upsidly-agent.php';
	}
);

// Installation de WooCommerce (tables, rôles) avant le chargement du thème, comme dans ses propres tests.
tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = new WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
);

require "{$_tests_dir}/includes/bootstrap.php";

require __DIR__ . '/trait-connector-actions.php';


// Table du journal (créée normalement à l'activation).
Upsidly_Agent_Log::create_table();
Upsidly_Agent_Agent_Store::create_tables();
