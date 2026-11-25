<?php
/**
 * Outils réglages (lecture) : get_options.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Options WordPress.
 */
class Upsidly_Agent_Tools_Settings {

	/**
	 * Options jamais renvoyées, même masquées.
	 */
	const HIDDEN_OPTIONS = array( 'upsidly_agent_key', 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt' );

	/**
	 * Lit des options par nom ou par recherche.
	 *
	 * @param array $input names, search.
	 * @return string|WP_Error
	 */
	public static function get_options( array $input ) {
		global $wpdb;

		$names = array();
		if ( ! empty( $input['names'] ) && is_array( $input['names'] ) ) {
			$names = array_slice( array_map( 'strval', $input['names'] ), 0, 50 );
		} elseif ( ! empty( $input['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $input['search'] ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recherche ponctuelle demandée par l'agent.
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT 50",
					$like,
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_' ) . '%'
				)
			);
		} else {
			return new WP_Error( 'upsidly_agent_options', __( 'Indiquez des noms d\'options ou un texte à chercher.', 'upsidly-agent' ) );
		}

		$result = array();
		foreach ( $names as $name ) {
			if ( in_array( strtolower( $name ), self::HIDDEN_OPTIONS, true ) ) {
				$result[ $name ] = Upsidly_Agent_Guard::MASK;
				continue;
			}
			$value = get_option( $name, null );
			if ( null === $value ) {
				$result[ $name ] = '(option inexistante)';
				continue;
			}
			if ( Upsidly_Agent_Guard::is_secret_key( $name ) ) {
				$result[ $name ] = Upsidly_Agent_Guard::MASK;
				continue;
			}
			$encoded = wp_json_encode( Upsidly_Agent_Guard::redact_value( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false !== $encoded && strlen( $encoded ) > 4000 ) {
				$result[ $name ] = mb_substr( $encoded, 0, 4000 ) . ' [… tronqué]';
			} else {
				$result[ $name ] = Upsidly_Agent_Guard::redact_value( $value );
			}
		}

		return Upsidly_Agent_Guard::to_result( $result );
	}
}
