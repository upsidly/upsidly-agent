<?php
/**
 * Outils de diagnostic (lecture) : site_overview, read_logs, fetch_page.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostic du site.
 */
class Upsidly_Agent_Tools_Diagnostics {

	/**
	 * Vue d'ensemble du site.
	 *
	 * @param array $input Aucune entrée.
	 * @return string
	 */
	public static function site_overview( array $input ) {
		global $wpdb;
		unset( $input );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates = get_site_transient( 'update_plugins' );
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'fichier'                => $file,
				'nom'                    => $data['Name'],
				'version'                => $data['Version'],
				'actif'                  => in_array( $file, $active, true ) || is_plugin_active_for_network( $file ),
				'mise_a_jour_disponible' => isset( $updates->response[ $file ]->new_version ) ? $updates->response[ $file ]->new_version : null,
			);
		}

		$mu = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			$mu[] = $file . ( $data['Version'] ? ' ' . $data['Version'] : '' );
		}

		$theme      = wp_get_theme();
		$theme_info = array(
			'nom'     => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'dossier' => 'themes/' . $theme->get_stylesheet(),
		);
		if ( $theme->parent() ) {
			$theme_info['parent'] = array(
				'nom'     => $theme->parent()->get( 'Name' ),
				'version' => $theme->parent()->get( 'Version' ),
				'dossier' => 'themes/' . $theme->get_template(),
			);
		}

		$overdue = 0;
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( (int) $timestamp < time() - 600 ) {
				$overdue += count( (array) $hooks );
			}
		}

		$health = get_transient( 'health-check-site-status-result' );

		return Upsidly_Agent_Guard::to_result(
			array(
				'site'            => array(
					'nom'        => get_bloginfo( 'name' ),
					'home'       => home_url(),
					'siteurl'    => site_url(),
					'langue'     => get_locale(),
					'fuseau'     => wp_timezone_string(),
					'multisite'  => is_multisite(),
					'permaliens' => (string) get_option( 'permalink_structure' ),
				),
				'versions'        => array(
					'wordpress'            => get_bloginfo( 'version' ),
					'php'                  => PHP_VERSION,
					'mysql'                => $wpdb->db_version(),
					'plugin_upsidly_agent' => UPSIDLY_AGENT_VERSION,
				),
				'base_de_donnees' => array( 'prefixe_tables' => $wpdb->prefix ),
				'constantes'      => array(
					'WP_DEBUG'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'WP_DEBUG_LOG'       => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
					'WP_DEBUG_DISPLAY'   => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null,
					'WP_MEMORY_LIMIT'    => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
					'DISALLOW_FILE_EDIT' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
					'DISALLOW_FILE_MODS' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
					'WP_CACHE'           => defined( 'WP_CACHE' ) && WP_CACHE,
					'DISABLE_WP_CRON'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				),
				'php'             => array(
					'memory_limit'        => ini_get( 'memory_limit' ),
					'max_execution_time'  => ini_get( 'max_execution_time' ),
					'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				),
				'theme'           => $theme_info,
				'extensions'      => $plugins,
				'mu_plugins'      => $mu,
				'cron_en_retard'  => $overdue,
				'sante_du_site'   => is_array( $health ) ? $health : 'non calculée (ouvrir Outils > Santé du site)',
			)
		);
	}

	/**
	 * Dernières lignes des journaux d'erreurs.
	 *
	 * @param array $input source, lines, filter.
	 * @return string
	 */
	public static function read_logs( array $input ) {
		$source = in_array( $input['source'] ?? 'all', array( 'debug', 'php', 'all' ), true ) ? ( $input['source'] ?? 'all' ) : 'all';
		$lines  = max( 1, min( 500, (int) ( $input['lines'] ?? 100 ) ) );
		$filter = (string) ( $input['filter'] ?? '' );

		$files = array();
		if ( 'php' !== $source ) {
			$debug              = ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
			$files['debug.log'] = $debug;
		}
		if ( 'debug' !== $source ) {
			$php = (string) ini_get( 'error_log' );
			if ( '' !== $php && ( ! isset( $files['debug.log'] ) || realpath( $php ) !== realpath( $files['debug.log'] ) ) ) {
				$files['journal PHP'] = $php;
			}
		}

		$out = array();
		foreach ( $files as $label => $path ) {
			if ( ! is_readable( $path ) || ! is_file( $path ) ) {
				$out[] = "== {$label} : introuvable ou illisible";
				continue;
			}
			$tail  = self::tail( $path, $lines, $filter );
			$out[] = sprintf( '== %s (%s, modifié le %s UTC) — %d ligne(s)', $label, size_format( (int) filesize( $path ) ), gmdate( 'Y-m-d H:i', (int) filemtime( $path ) ), count( $tail ) );
			$out[] = $tail ? implode( "\n", $tail ) : '(aucune ligne)';
		}
		if ( ! $files ) {
			$out[] = 'Aucun journal configuré.';
		}
		if ( ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			$out[] = 'Note : WP_DEBUG_LOG est désactivé, les erreurs WordPress récentes ne sont peut-être pas journalisées.';
		}

		return Upsidly_Agent_Guard::truncate( Upsidly_Agent_Guard::redact_text( implode( "\n", $out ) ) );
	}

	/**
	 * Dernières lignes d'un fichier (lecture des 512 derniers Ko au plus).
	 *
	 * @param string $path   Fichier.
	 * @param int    $count  Nombre de lignes.
	 * @param string $filter Filtre texte.
	 * @return string[]
	 */
	private static function tail( $path, $count, $filter ) {
		$size   = (int) filesize( $path );
		$offset = max( 0, $size - 524288 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- journal local, lecture bornée.
		$data  = (string) file_get_contents( $path, false, null, $offset );
		$lines = preg_split( '/\R/', $data );
		if ( $offset > 0 ) {
			array_shift( $lines );
		}
		$lines = array_values( array_filter( $lines, static fn( $line ) => '' !== trim( $line ) ) );
		if ( '' !== $filter ) {
			$lines = array_values( array_filter( $lines, static fn( $line ) => false !== stripos( $line, $filter ) ) );
		}
		return array_map( static fn( $line ) => mb_substr( $line, 0, 1000 ), array_slice( $lines, -$count ) );
	}

	/**
	 * Charge une page du site.
	 *
	 * @param array $input path, as_user.
	 * @return string|WP_Error
	 */
	public static function fetch_page( array $input ) {
		$path = (string) ( $input['path'] ?? '/' );
		if ( preg_match( '#^https?://#i', $path ) ) {
			$home = wp_parse_url( home_url() );
			$url  = wp_parse_url( $path );
			if ( ! $url || empty( $url['host'] ) || strtolower( $url['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
				return new WP_Error( 'upsidly_agent_host', __( 'Seules les pages du site lui-même peuvent être chargées.', 'upsidly-agent' ) );
			}
			$target = $path;
		} else {
			$target = home_url( '/' . ltrim( $path, '/' ) );
		}

		$args = array(
			'timeout'     => 20,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur de WordPress.
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		);
		if ( ! empty( $input['as_user'] ) ) {
			$cookies = array();
			foreach ( array( LOGGED_IN_COOKIE, AUTH_COOKIE, SECURE_AUTH_COOKIE ) as $name ) {
				if ( isset( $_COOKIE[ $name ] ) ) {
					$cookies[] = new WP_Http_Cookie(
						array(
							'name'  => $name,
							'value' => sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ),
						)
					);
				}
			}
			$args['cookies'] = $cookies;
		}

		$started  = microtime( true );
		$response = wp_remote_get( $target, $args );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return Upsidly_Agent_Guard::to_result(
				array(
					'url'      => $target,
					'erreur'   => $response->get_error_message(),
					'duree_ms' => $duration,
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		preg_match_all( '#(?:<b>)?(Fatal error|Parse error|Warning|Notice|Deprecated)(?:</b>)?:\s*(.{0,300}?)(?:<br|\n|$)#i', $body, $matches, PREG_SET_ORDER );
		$errors = array();
		foreach ( array_slice( $matches, 0, 10 ) as $match ) {
			$errors[] = $match[1] . ': ' . wp_strip_all_tags( $match[2] );
		}
		if ( false !== stripos( $body, 'There has been a critical error' ) || false !== stripos( $body, 'erreur critique' ) ) {
			$errors[] = 'Page « erreur critique » de WordPress affichée.';
		}

		$title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ) {
			$title = trim( wp_strip_all_tags( $m[1] ) );
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $body ) ) ) );

		return Upsidly_Agent_Guard::to_result(
			array(
				'url'              => $target,
				'code_http'        => (int) wp_remote_retrieve_response_code( $response ),
				'duree_ms'         => $duration,
				'type'             => wp_remote_retrieve_header( $response, 'content-type' ),
				'cache'            => wp_remote_retrieve_header( $response, 'x-cache' ) ? wp_remote_retrieve_header( $response, 'x-cache' ) : wp_remote_retrieve_header( $response, 'cf-cache-status' ),
				'taille_octets'    => strlen( $body ),
				'titre'            => $title,
				'erreurs_visibles' => $errors,
				'extrait_texte'    => mb_substr( $text, 0, 3000 ),
				'debut_html'       => mb_substr( $body, 0, 2000 ),
			)
		);
	}
}
