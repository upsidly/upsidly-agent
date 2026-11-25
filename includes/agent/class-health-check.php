<?php
/**
 * Vérification du site après une modification (erreur fatale → restauration).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Requêtes de bouclage vers l'accueil et l'administration.
 */
class Upsidly_Agent_Health_Check {

	/**
	 * Vérifie que le site répond sans erreur fatale.
	 *
	 * @return array{ok: bool, checked: bool, detail: string}
	 */
	public static function run() {
		/**
		 * Permet de remplacer le contrôle (tests, hébergeurs sans bouclage).
		 *
		 * @param array|null $result Résultat forcé ou null.
		 */
		$forced = apply_filters( 'upsidly_agent_health_check', null );
		if ( is_array( $forced ) ) {
			return $forced + array(
				'ok'      => true,
				'checked' => true,
				'detail'  => '',
			);
		}

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

		$checked = false;
		foreach ( array(
			home_url( '/' )          => array(),
			admin_url( 'index.php' ) => $cookies,
		) as $url => $jar ) {
			$response = wp_remote_get(
				add_query_arg( 'upsidly_health', time(), $url ),
				array(
					'timeout'     => 15,
					'redirection' => 2,
					'cookies'     => $jar,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur de WordPress.
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$checked = true;
			$code    = (int) wp_remote_retrieve_response_code( $response );
			$body    = (string) wp_remote_retrieve_body( $response );

			if ( $code >= 500 || preg_match( '/(Fatal error|Parse error|There has been a critical error|erreur critique)/i', $body ) ) {
				preg_match( '#(?:<b>)?(Fatal error|Parse error)(?:</b>)?:\s*(.{0,400}?)(?:<br|\n|$)#i', $body, $match );
				return array(
					'ok'      => false,
					'checked' => true,
					'detail'  => sprintf(
						'%s répond %d%s',
						$url,
						$code,
						$match ? ' — ' . wp_strip_all_tags( $match[1] . ': ' . $match[2] ) : ''
					),
				);
			}
		}

		return array(
			'ok'      => true,
			'checked' => $checked,
			'detail'  => $checked ? '' : 'contrôle impossible (le site ne peut pas s\'appeler lui-même)',
		);
	}
}
