<?php
/**
 * Sauvegardes avant écriture et restauration.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chaque action d'écriture est précédée d'une sauvegarde qui permet de l'annuler.
 *
 * Types : file (contenu précédent), created_file (fichier créé), plugins (extensions actives),
 * theme (thème actif), dir_zip (copie d'un dossier d'extension ou de thème), installed (extension installée),
 * option (valeur précédente), post (contenu précédent et métas).
 */
class Upsidly_Agent_Backup {

	const DIR_NAME = 'upsidly-agent-backups';

	/**
	 * Enregistre une sauvegarde.
	 *
	 * @param int    $action_id Action.
	 * @param string $type      Type.
	 * @param string $target    Cible (chemin, option, id…).
	 * @param array  $payload   Données nécessaires à la restauration.
	 * @return int Identifiant de la sauvegarde.
	 */
	public static function create( $action_id, $type, $target, array $payload ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table propre au plugin.
		$wpdb->insert(
			Upsidly_Agent_Agent_Store::table( 'backups' ),
			array(
				'action_id'  => (int) $action_id,
				'type'       => $type,
				'target'     => mb_substr( (string) $target, 0, 255 ),
				'payload'    => (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Sauvegarde par identifiant.
	 *
	 * @param int $backup_id Sauvegarde.
	 * @return array|null
	 */
	public static function get( $backup_id ) {
		global $wpdb;
		$table = Upsidly_Agent_Agent_Store::table( 'backups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $backup_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['payload'] = json_decode( (string) $row['payload'], true );
		return $row;
	}

	/**
	 * Restaure une sauvegarde.
	 *
	 * @param int $backup_id Sauvegarde.
	 * @return true|WP_Error
	 */
	public static function restore( $backup_id ) {
		global $wpdb;
		$backup = self::get( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'upsidly_agent_backup', __( 'Sauvegarde introuvable.', 'upsidly-agent' ) );
		}
		if ( ! empty( $backup['restored_at'] ) ) {
			return new WP_Error( 'upsidly_agent_backup', __( 'Cette modification a déjà été annulée.', 'upsidly-agent' ) );
		}
		$payload = is_array( $backup['payload'] ) ? $backup['payload'] : array();

		switch ( $backup['type'] ) {
			case 'file':
				$result = self::write_file( $backup['target'], (string) $payload['content'] );
				break;

			case 'created_file':
				$result = ( is_file( $backup['target'] ) && wp_delete_file_from_directory( $backup['target'], dirname( $backup['target'] ) ) ) || ! file_exists( $backup['target'] )
					? true
					: new WP_Error( 'upsidly_agent_backup', __( 'Le fichier créé n\'a pas pu être supprimé.', 'upsidly-agent' ) );
				break;

			case 'plugins':
				update_option( 'active_plugins', (array) $payload['active'] );
				$result = true;
				break;

			case 'theme':
				switch_theme( (string) $payload['stylesheet'] );
				$result = true;
				break;

			case 'dir_zip':
				$result = self::restore_zip( (string) $payload['zip'], $backup['target'] );
				break;

			case 'installed':
				$result = self::remove_installed( (string) $payload['plugin'] );
				break;

			case 'option':
				if ( empty( $payload['existed'] ) ) {
					delete_option( $backup['target'] );
				} else {
					update_option( $backup['target'], maybe_unserialize( (string) $payload['value'] ) );
				}
				$result = true;
				break;

			case 'post':
				$result = self::restore_post( (int) $backup['target'], $payload );
				break;

			default:
				$result = new WP_Error( 'upsidly_agent_backup', __( 'Type de sauvegarde inconnu.', 'upsidly-agent' ) );
		}

		if ( true === $result ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table propre au plugin.
			$wpdb->update( Upsidly_Agent_Agent_Store::table( 'backups' ), array( 'restored_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $backup_id ) );
		}
		return $result;
	}

	/**
	 * Écrit un fichier (restauration).
	 *
	 * @param string $path    Chemin absolu.
	 * @param string $content Contenu.
	 * @return true|WP_Error
	 */
	public static function write_file( $path, $content ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- écriture locale validée par le garde-fou.
		if ( false === file_put_contents( $path, $content, LOCK_EX ) ) {
			return new WP_Error( 'upsidly_agent_write', __( 'Écriture du fichier impossible (droits insuffisants ?).', 'upsidly-agent' ) );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
		return true;
	}

	/**
	 * Dossier des sauvegardes (protégé), créé au besoin.
	 *
	 * @return string|WP_Error
	 */
	public static function directory() {
		$token = get_option( 'upsidly_agent_backup_token' );
		if ( ! $token ) {
			$token = wp_generate_password( 24, false );
			add_option( 'upsidly_agent_backup_token', $token, '', false );
		}
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME . '/' . $token;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'upsidly_agent_backup_dir', __( 'Dossier de sauvegarde impossible à créer.', 'upsidly-agent' ) );
		}
		$root = dirname( $dir );
		if ( ! file_exists( $root . '/index.php' ) ) {
			self::write_file( $root . '/index.php', "<?php\n// Silence.\n" );
			self::write_file( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	/**
	 * Copie ZIP d'un dossier (extension ou thème) avant mise à jour.
	 *
	 * @param string $source Dossier.
	 * @return string|WP_Error Chemin de l'archive.
	 */
	public static function zip_directory( $source ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'upsidly_agent_zip', __( 'Copie impossible : l\'extension PHP ZipArchive est absente.', 'upsidly-agent' ) );
		}
		$dir = self::directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$zip_path = $dir . '/' . sanitize_file_name( basename( $source ) ) . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false ) . '.zip';
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'upsidly_agent_zip', __( 'Création de la copie impossible.', 'upsidly-agent' ) );
		}
		$base     = dirname( $source );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $file ) {
			$local = substr( $file->getPathname(), strlen( $base ) + 1 );
			if ( $file->isDir() ) {
				$zip->addEmptyDir( $local );
			} else {
				$zip->addFile( $file->getPathname(), $local );
			}
		}
		$zip->close();
		return $zip_path;
	}

	/**
	 * Restaure un dossier depuis sa copie ZIP.
	 *
	 * @param string $zip_path Archive.
	 * @param string $target   Dossier à remplacer.
	 * @return true|WP_Error
	 */
	private static function restore_zip( $zip_path, $target ) {
		if ( ! is_file( $zip_path ) || ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'upsidly_agent_zip', __( 'Copie de l\'ancienne version introuvable.', 'upsidly-agent' ) );
		}
		self::delete_directory( $target );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) || ! $zip->extractTo( dirname( $target ) ) ) {
			return new WP_Error( 'upsidly_agent_zip', __( 'Restauration de l\'ancienne version impossible.', 'upsidly-agent' ) );
		}
		$zip->close();
		return true;
	}

	/**
	 * Supprime une extension installée par l'agent (annulation d'une installation).
	 *
	 * @param string $plugin Fichier principal.
	 * @return true|WP_Error
	 */
	private static function remove_installed( $plugin ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		deactivate_plugins( $plugin, true );
		$deleted = delete_plugins( array( $plugin ) );
		return true === $deleted ? true : new WP_Error( 'upsidly_agent_delete', __( 'L\'extension installée n\'a pas pu être supprimée.', 'upsidly-agent' ) );
	}

	/**
	 * Restaure un contenu (ou met à la corbeille un contenu créé).
	 *
	 * @param int   $post_id Contenu.
	 * @param array $payload Données.
	 * @return true|WP_Error
	 */
	private static function restore_post( $post_id, array $payload ) {
		if ( ! empty( $payload['created'] ) ) {
			return wp_trash_post( $post_id ) ? true : new WP_Error( 'upsidly_agent_post', __( 'Le contenu créé n\'a pas pu être mis à la corbeille.', 'upsidly-agent' ) );
		}
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_title'   => (string) $payload['title'],
					'post_content' => (string) $payload['content'],
					'post_excerpt' => (string) $payload['excerpt'],
					'post_status'  => (string) $payload['status'],
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( (array) ( $payload['meta'] ?? array() ) as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( maybe_unserialize( (string) $value ) ) );
			}
		}
		return true;
	}

	/**
	 * Supprime un dossier récursivement (restauration d'une extension ou d'un thème uniquement).
	 *
	 * @param string $dir Dossier.
	 */
	private static function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) || 0 !== strpos( realpath( $dir ), Upsidly_Agent_Guard::root() . '/' ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- restauration d'une copie validée.
			} else {
				wp_delete_file( $file->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- restauration d'une copie validée.
	}
}
