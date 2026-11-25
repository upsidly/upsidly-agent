<?php
/**
 * Journal local des générations.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table {prefix}upsidly_agent_log. Ni les entrées ni le contenu généré n'y sont stockés.
 */
class Upsidly_Agent_Log {

	/**
	 * Nom complet de la table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'upsidly_agent_log';
	}

	/**
	 * Crée ou met à jour la table (dbDelta).
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(20) NOT NULL,
  skill VARCHAR(64) NOT NULL,
  post_id BIGINT UNSIGNED NULL,
  source_page_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL,
  credits INT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at)
) {$charset};"
		);
	}

	/**
	 * Supprime la table (désinstallation).
	 */
	public static function drop_table() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- nom de table interne.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() );
	}

	/**
	 * Ajoute une entrée.
	 *
	 * @param array $entry user_id, action, skill, post_id, source_page_id, status, credits, error.
	 */
	public static function add( array $entry ) {
		global $wpdb;

		$error = isset( $entry['error'] ) && '' !== (string) $entry['error'] ? mb_substr( (string) $entry['error'], 0, 255 ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table propre au plugin.
		$wpdb->insert(
			self::table(),
			array(
				'user_id'        => (int) ( $entry['user_id'] ?? get_current_user_id() ),
				'action'         => mb_substr( (string) ( $entry['action'] ?? 'run' ), 0, 20 ),
				'skill'          => mb_substr( (string) ( $entry['skill'] ?? '' ), 0, 64 ),
				'post_id'        => empty( $entry['post_id'] ) ? null : (int) $entry['post_id'],
				'source_page_id' => empty( $entry['source_page_id'] ) ? null : (int) $entry['source_page_id'],
				'status'         => mb_substr( (string) ( $entry['status'] ?? 'ok' ), 0, 20 ),
				'credits'        => max( 0, (int) ( $entry['credits'] ?? 0 ) ),
				'error'          => $error,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Dernières entrées.
	 *
	 * @param int      $limit   Nombre d'entrées.
	 * @param int|null $user_id Filtrer sur un utilisateur (null : tous).
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( $limit = 50, $user_id = null ) {
		global $wpdb;
		$table = self::table();

		if ( null === $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Entrées du journal pour l'utilisateur courant (toutes s'il a edit_others_posts), enrichies pour l'affichage.
	 *
	 * @param int $limit Nombre d'entrées.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_current_user( $limit = 50 ) {
		$user_id = current_user_can( 'edit_others_posts' ) ? null : get_current_user_id();
		$rows    = self::recent( $limit, $user_id );

		return array_map(
			static function ( $row ) {
				$post_id = (int) $row['post_id'];
				$user    = get_userdata( (int) $row['user_id'] );
				$post    = $post_id ? get_post( $post_id ) : null;

				return array(
					'id'         => (int) $row['id'],
					'date'       => get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					'user'       => $user ? $user->display_name : '#' . (int) $row['user_id'],
					'action'     => (string) $row['action'],
					'skill'      => (string) $row['skill'],
					'post_id'    => $post_id,
					'post_title' => $post ? get_the_title( $post ) : '',
					'edit_url'   => ( $post && current_user_can( 'edit_post', $post_id ) ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
					'credits'    => (int) $row['credits'],
					'status'     => (string) $row['status'],
					'error'      => (string) $row['error'],
				);
			},
			$rows
		);
	}
}
