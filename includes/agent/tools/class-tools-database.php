<?php
/**
 * Outils base de données (lecture) : db_query_read.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Requêtes SQL en lecture seule.
 */
class Upsidly_Agent_Tools_Database {

	const MAX_ROWS = 200;

	/**
	 * Exécute une requête en lecture seule (transaction READ ONLY), lignes masquées.
	 *
	 * @param array $input sql.
	 * @return string|WP_Error
	 */
	public static function db_query_read( array $input ) {
		global $wpdb;

		$sql = Upsidly_Agent_Guard::read_only_sql( (string) ( $input['sql'] ?? '' ) );
		if ( is_wp_error( $sql ) ) {
			return $sql;
		}

		$show_errors = $wpdb->hide_errors();
		$suppress    = $wpdb->suppress_errors( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête validée en lecture seule par le garde-fou, exécutée dans une transaction READ ONLY.
		$wpdb->query( 'SET SESSION MAX_EXECUTION_TIME = 10000' );
		$wpdb->query( 'SET SESSION max_statement_time = 10' );
		/**
		 * Exécuter la lecture dans une transaction READ ONLY (désactivable pour les tests, déjà transactionnels).
		 *
		 * @param bool $use_transaction Vrai par défaut.
		 */
		$transaction = (bool) apply_filters( 'upsidly_agent_sql_read_only_transaction', true );
		if ( $transaction ) {
			$wpdb->query( 'START TRANSACTION READ ONLY' );
		}
		$started = microtime( true );
		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$error   = $wpdb->last_error;
		if ( $transaction ) {
			$wpdb->query( 'ROLLBACK' );
		}
		// phpcs:enable

		$wpdb->suppress_errors( $suppress );
		if ( $show_errors ) {
			$wpdb->show_errors();
		}

		if ( '' !== $error ) {
			return new WP_Error( 'upsidly_agent_sql', 'Erreur SQL : ' . $error );
		}

		$rows  = is_array( $rows ) ? $rows : array();
		$total = count( $rows );
		$rows  = array_slice( $rows, 0, self::MAX_ROWS );

		// Valeur d'une méta ou d'une option au nom sensible : masquée.
		foreach ( $rows as &$row ) {
			$name = $row['meta_key'] ?? $row['option_name'] ?? null;
			if ( is_string( $name ) && ( Upsidly_Agent_Guard::is_secret_key( $name ) || Upsidly_Agent_Guard::is_personal_key( $name ) ) ) {
				foreach ( array( 'meta_value', 'option_value' ) as $column ) {
					if ( isset( $row[ $column ] ) && '' !== $row[ $column ] ) {
						$row[ $column ] = Upsidly_Agent_Guard::MASK;
					}
				}
			}
			foreach ( $row as $column => $value ) {
				if ( is_string( $value ) && strlen( $value ) > 2000 ) {
					$row[ $column ] = mb_substr( $value, 0, 2000 ) . ' [… tronqué]';
				}
			}
		}
		unset( $row );

		return Upsidly_Agent_Guard::to_result(
			array(
				'requete'   => $sql,
				'lignes'    => $total,
				'affichees' => count( $rows ),
				'duree_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'resultat'  => $rows,
			)
		);
	}
}
