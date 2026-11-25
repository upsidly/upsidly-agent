<?php
/**
 * Conversations, messages, actions et sauvegardes de l'agent (tables locales).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stockage local : le contenu des conversations ne quitte le site que pour l'appel à l'IA.
 */
class Upsidly_Agent_Agent_Store {

	const RETENTION_DAYS         = 90;
	const ACTIONS_RETENTION_DAYS = 120;

	/**
	 * Nom complet d'une table.
	 *
	 * @param string $name conversations | messages | actions | backups.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'upsidly_agent_' . $name;
	}

	/**
	 * Crée ou met à jour les tables (dbDelta).
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$conversations = self::table( 'conversations' );
		$messages      = self::table( 'messages' );
		$actions       = self::table( 'actions' );
		$backups       = self::table( 'backups' );

		dbDelta(
			"CREATE TABLE {$conversations} (
  id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'idle',
  credits INT UNSIGNED NOT NULL DEFAULT 0,
  steps INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_updated (user_id,updated_at)
) {$charset};
CREATE TABLE {$messages} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id CHAR(26) NOT NULL,
  role VARCHAR(20) NOT NULL,
  content LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation (conversation_id,id)
) {$charset};
CREATE TABLE {$actions} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id CHAR(26) NOT NULL,
  tool VARCHAR(64) NOT NULL,
  risk VARCHAR(10) NOT NULL,
  input LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL,
  backup_id BIGINT UNSIGNED NULL,
  result_summary VARCHAR(255) NULL,
  decided_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY conversation (conversation_id)
) {$charset};
CREATE TABLE {$backups} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL,
  target VARCHAR(255) NOT NULL,
  payload LONGTEXT NOT NULL,
  restored_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY action (action_id)
) {$charset};"
		);
	}

	/**
	 * Supprime les tables (désinstallation).
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( array( 'conversations', 'messages', 'actions', 'backups' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- nom de table interne.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) );
		}
	}

	/**
	 * Nouvel identifiant de conversation (26 caractères [a-z0-9]).
	 *
	 * @return string
	 */
	public static function new_id() {
		return bin2hex( random_bytes( 13 ) );
	}

	/**
	 * Crée une conversation.
	 *
	 * @param int    $user_id Utilisateur.
	 * @param string $title   Titre.
	 * @return string Identifiant.
	 */
	public static function create( $user_id, $title ) {
		global $wpdb;
		$id  = self::new_id();
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table propre au plugin.
		$wpdb->insert(
			self::table( 'conversations' ),
			array(
				'id'         => $id,
				'user_id'    => (int) $user_id,
				'title'      => mb_substr( $title, 0, 190 ),
				'status'     => 'idle',
				'credits'    => 0,
				'steps'      => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		return $id;
	}

	/**
	 * Conversation par identifiant.
	 *
	 * @param string $id Identifiant.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table( 'conversations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", (string) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Met à jour une conversation.
	 *
	 * @param string $id     Identifiant.
	 * @param array  $fields Colonnes (status, credits, steps, title).
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$fields               = array_intersect_key( $fields, array_flip( array( 'status', 'credits', 'steps', 'title' ) ) );
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table propre au plugin.
		$wpdb->update( self::table( 'conversations' ), $fields, array( 'id' => (string) $id ) );
	}

	/**
	 * Conversations d'un utilisateur, les plus récentes d'abord.
	 *
	 * @param int $user_id Utilisateur.
	 * @param int $limit   Nombre maximal.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( $user_id, $limit = 50 ) {
		global $wpdb;
		$table = self::table( 'conversations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, status, credits, steps, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Ajoute un message (format API Messages).
	 *
	 * @param string       $conversation_id Conversation.
	 * @param string       $role            user | assistant.
	 * @param string|array $content         Texte ou blocs.
	 * @return int Identifiant du message.
	 */
	public static function add_message( $conversation_id, $role, $content ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table propre au plugin.
		$wpdb->insert(
			self::table( 'messages' ),
			array(
				'conversation_id' => (string) $conversation_id,
				'role'            => 'assistant' === $role ? 'assistant' : 'user',
				'content'         => (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Remplace le contenu d'un message (fusion de messages utilisateur consécutifs).
	 *
	 * @param int          $message_id Message.
	 * @param string|array $content    Nouveau contenu.
	 */
	public static function replace_message( $message_id, $content ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table propre au plugin.
		$wpdb->update(
			self::table( 'messages' ),
			array( 'content' => (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) ),
			array( 'id' => (int) $message_id )
		);
	}

	/**
	 * Messages d'une conversation, dans l'ordre.
	 *
	 * @param string $conversation_id Conversation.
	 * @param int    $after_id        Seulement les messages d'identifiant supérieur.
	 * @return array<int, array{id: int, role: string, content: mixed, created_at: string}>
	 */
	public static function messages( $conversation_id, $after_id = 0 ) {
		global $wpdb;
		$table = self::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, role, content, created_at FROM {$table} WHERE conversation_id = %s AND id > %d ORDER BY id ASC", (string) $conversation_id, (int) $after_id ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				return array(
					'id'         => (int) $row['id'],
					'role'       => (string) $row['role'],
					'content'    => json_decode( (string) $row['content'], true ),
					'created_at' => (string) $row['created_at'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Purge des conversations anciennes (cron quotidien).
	 */
	public static function purge() {
		global $wpdb;
		$conversations = self::table( 'conversations' );
		$messages      = self::table( 'messages' );
		$actions       = self::table( 'actions' );
		$backups       = self::table( 'backups' );
		$limit         = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$actions_limit = gmdate( 'Y-m-d H:i:s', time() - self::ACTIONS_RETENTION_DAYS * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables propres au plugin.
		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$messages} m INNER JOIN {$conversations} c ON c.id = m.conversation_id WHERE c.updated_at < %s", $limit ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$conversations} WHERE updated_at < %s", $limit ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$backups} WHERE created_at < %s", $actions_limit ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$actions} WHERE created_at < %s", $actions_limit ) );
		// phpcs:enable
	}
}
