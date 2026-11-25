<?php
/**
 * Outils d'écriture (lot B) : aperçu avant validation, puis application avec sauvegarde.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chaque outil a une méthode preview_* (aperçu, sans rien modifier) et apply_* (exécutée après validation).
 *
 * Aperçu : array{ok: bool, title: string, preview: string (HTML échappé), error?: string}.
 * Application : array{content: string, is_error: bool, status: string (applied|failed|reverted), backup_id?: int}.
 */
class Upsidly_Agent_Tools_Write {

	const CREATE_EXTENSIONS = array( 'php', 'css', 'js', 'json', 'txt', 'html', 'htm', 'md', 'scss', 'xml' );

	/**
	 * Options jamais modifiables par l'agent.
	 */
	const PROTECTED_OPTIONS = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'cron', 'db_version', 'initial_db_version', 'recently_activated', 'uninstall_plugins', 'auto_updater.lock', 'core_updater.lock', 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt' );

	// ---------------------------------------------------------------------
	// Outils communs.
	// ---------------------------------------------------------------------

	/**
	 * Aperçu refusé.
	 *
	 * @param string $message Raison.
	 * @return array
	 */
	private static function refuse( $message ) {
		return array(
			'ok'      => false,
			'title'   => '',
			'preview' => '',
			'error'   => $message,
		);
	}

	/**
	 * Résultat d'application.
	 *
	 * @param string $status    applied | failed | reverted.
	 * @param string $content   Texte renvoyé à l'IA.
	 * @param int    $backup_id Sauvegarde.
	 * @return array
	 */
	private static function result( $status, $content, $backup_id = 0 ) {
		return array(
			'status'    => $status,
			'content'   => $content,
			'is_error'  => 'applied' !== $status,
			'backup_id' => (int) $backup_id,
		);
	}

	/**
	 * Contrôle de santé après modification ; restauration immédiate si le site tombe en erreur.
	 *
	 * @param int    $backup_id Sauvegarde à restaurer en cas d'erreur.
	 * @param string $done      Description de ce qui a été fait.
	 * @return array Résultat d'application.
	 */
	private static function checked( $backup_id, $done ) {
		$health = Upsidly_Agent_Health_Check::run();
		if ( ! $health['ok'] ) {
			$restored = Upsidly_Agent_Backup::restore( $backup_id );
			$log      = Upsidly_Agent_Tools_Diagnostics::read_logs(
				array(
					'source' => 'all',
					'lines'  => 15,
				)
			);
			return self::result(
				'reverted',
				sprintf(
					"Modification annulée automatiquement : le site est tombé en erreur après l'avoir appliquée (%s). %s\nDernières lignes des journaux :\n%s",
					$health['detail'],
					true === $restored ? 'L\'état précédent a été restauré.' : 'ATTENTION : la restauration automatique a échoué, prévenir la personne.',
					$log
				),
				$backup_id
			);
		}
		$note = $health['checked'] ? ' Le site répond normalement.' : ' Contrôle automatique impossible sur cet hébergement : demander à la personne de vérifier le site.';
		return self::result( 'applied', $done . $note, $backup_id );
	}

	/**
	 * Diff HTML (texte échappé par wp_text_diff()).
	 *
	 * @param string $left        Avant.
	 * @param string $right       Après.
	 * @param string $title_left  Titre gauche.
	 * @param string $title_right Titre droit.
	 * @return string
	 */
	private static function diff( $left, $right, $title_left = '', $title_right = '' ) {
		$html = wp_text_diff(
			(string) $left,
			(string) $right,
			array(
				'title_left'  => '' !== $title_left ? $title_left : __( 'Avant', 'upsidly-agent' ),
				'title_right' => '' !== $title_right ? $title_right : __( 'Après', 'upsidly-agent' ),
			)
		);
		return '' !== $html ? $html : '<p>' . esc_html__( 'Aucune différence.', 'upsidly-agent' ) . '</p>';
	}

	/**
	 * Fichier dont une modification doit être suivie d'un contrôle de santé (code exécuté par WordPress).
	 *
	 * @param string $path Chemin absolu.
	 * @return bool
	 */
	private static function is_code( $path ) {
		return (bool) preg_match( '/\.(php|phtml|inc)$/i', $path );
	}

	/**
	 * Fonctions d'administration nécessaires aux extensions et thèmes.
	 */
	private static function load_admin() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * Système de fichiers direct disponible ?
	 *
	 * @return true|WP_Error
	 */
	private static function filesystem() {
		self::load_admin();
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return new WP_Error( 'upsidly_agent_fs', __( 'Installation impossible : WordPress n\'a pas d\'accès direct aux fichiers sur cet hébergement (identifiants FTP requis).', 'upsidly-agent' ) );
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// edit_file.
	// ---------------------------------------------------------------------

	/**
	 * Prépare la modification d'un fichier.
	 *
	 * @param array $input path, old_text, new_text.
	 * @return array{file?: string, before?: string, after?: string, error?: string}
	 */
	private static function prepare_edit( array $input ) {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return array( 'error' => __( 'La modification de fichiers est désactivée sur ce site (DISALLOW_FILE_EDIT).', 'upsidly-agent' ) );
		}
		$file = Upsidly_Agent_Guard::resolve( (string) ( $input['path'] ?? '' ), 'file' );
		if ( is_wp_error( $file ) ) {
			return array( 'error' => $file->get_error_message() );
		}
		if ( filesize( $file ) > Upsidly_Agent_Guard::MAX_FILE_BYTES || Upsidly_Agent_Guard::is_binary( $file ) ) {
			return array( 'error' => __( 'Fichier binaire ou trop volumineux : modification impossible.', 'upsidly-agent' ) );
		}
		if ( ! wp_is_writable( $file ) ) {
			return array( 'error' => __( 'Fichier non modifiable (droits d\'écriture).', 'upsidly-agent' ) );
		}

		$old = (string) ( $input['old_text'] ?? '' );
		$new = (string) ( $input['new_text'] ?? '' );
		if ( '' === $old ) {
			return array( 'error' => __( 'Le passage à remplacer (old_text) est vide.', 'upsidly-agent' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local validé par le garde-fou.
		$before = (string) file_get_contents( $file );
		$count  = substr_count( $before, $old );
		if ( 1 !== $count ) {
			return array(
				'error' => 0 === $count
					? __( 'Le passage à remplacer est introuvable dans le fichier (relire le fichier : il a peut-être changé).', 'upsidly-agent' )
					/* translators: %d: nombre d'occurrences. */
					: sprintf( __( 'Le passage à remplacer apparaît %d fois : ajouter du contexte pour qu\'il soit unique.', 'upsidly-agent' ), $count ),
			);
		}
		$after = str_replace( $old, $new, $before );
		if ( self::is_code( $file ) ) {
			$syntax = Upsidly_Agent_Guard::php_syntax( $after );
			if ( is_wp_error( $syntax ) ) {
				return array( 'error' => $syntax->get_error_message() );
			}
		}

		return array(
			'file'   => $file,
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Aperçu de edit_file.
	 *
	 * @param array $input Entrées.
	 * @return array
	 */
	public static function preview_edit_file( array $input ) {
		$edit = self::prepare_edit( $input );
		if ( isset( $edit['error'] ) ) {
			return self::refuse( $edit['error'] );
		}
		$relative = Upsidly_Agent_Guard::relative( $edit['file'] );
		return array(
			'ok'      => true,
			/* translators: %s: fichier. */
			'title'   => sprintf( __( 'Modifier le fichier %s', 'upsidly-agent' ), $relative ),
			'preview' => self::diff( (string) $input['old_text'], (string) $input['new_text'], $relative . ' — ' . __( 'avant', 'upsidly-agent' ), $relative . ' — ' . __( 'après', 'upsidly-agent' ) ),
		);
	}

	/**
	 * Applique edit_file.
	 *
	 * @param array $input     Entrées.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_edit_file( array $input, $action_id ) {
		$edit = self::prepare_edit( $input );
		if ( isset( $edit['error'] ) ) {
			return self::result( 'failed', $edit['error'] );
		}
		$backup = Upsidly_Agent_Backup::create( $action_id, 'file', $edit['file'], array( 'content' => $edit['before'] ) );
		$write  = Upsidly_Agent_Backup::write_file( $edit['file'], $edit['after'] );
		if ( is_wp_error( $write ) ) {
			return self::result( 'failed', $write->get_error_message(), $backup );
		}
		$done = sprintf( 'Fichier wp-content/%s modifié.', Upsidly_Agent_Guard::relative( $edit['file'] ) );
		return self::is_code( $edit['file'] ) ? self::checked( $backup, $done ) : self::result( 'applied', $done, $backup );
	}

	// ---------------------------------------------------------------------
	// create_file.
	// ---------------------------------------------------------------------

	/**
	 * Prépare la création d'un fichier.
	 *
	 * @param array $input path, content.
	 * @return array{target?: string, error?: string}
	 */
	private static function prepare_create( array $input ) {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return array( 'error' => __( 'La modification de fichiers est désactivée sur ce site (DISALLOW_FILE_EDIT).', 'upsidly-agent' ) );
		}
		$target = Upsidly_Agent_Guard::resolve_new( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $target ) ) {
			return array( 'error' => $target->get_error_message() );
		}
		if ( file_exists( $target ) ) {
			return array( 'error' => __( 'Ce fichier existe déjà : utiliser edit_file.', 'upsidly-agent' ) );
		}
		$extension = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::CREATE_EXTENSIONS, true ) ) {
			return array( 'error' => __( 'Type de fichier non autorisé.', 'upsidly-agent' ) );
		}
		$content = (string) ( $input['content'] ?? '' );
		if ( strlen( $content ) > Upsidly_Agent_Guard::MAX_FILE_BYTES ) {
			return array( 'error' => __( 'Contenu trop volumineux.', 'upsidly-agent' ) );
		}
		if ( 'php' === $extension ) {
			$syntax = Upsidly_Agent_Guard::php_syntax( $content );
			if ( is_wp_error( $syntax ) ) {
				return array( 'error' => $syntax->get_error_message() );
			}
		}
		return array( 'target' => $target );
	}

	/**
	 * Aperçu de create_file.
	 *
	 * @param array $input Entrées.
	 * @return array
	 */
	public static function preview_create_file( array $input ) {
		$create = self::prepare_create( $input );
		if ( isset( $create['error'] ) ) {
			return self::refuse( $create['error'] );
		}
		$relative = Upsidly_Agent_Guard::relative( $create['target'] );
		return array(
			'ok'      => true,
			/* translators: %s: fichier. */
			'title'   => sprintf( __( 'Créer le fichier %s', 'upsidly-agent' ), $relative ),
			'preview' => self::diff( '', (string) $input['content'], '', 'wp-content/' . $relative ),
		);
	}

	/**
	 * Applique create_file.
	 *
	 * @param array $input     Entrées.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_create_file( array $input, $action_id ) {
		$create = self::prepare_create( $input );
		if ( isset( $create['error'] ) ) {
			return self::result( 'failed', $create['error'] );
		}
		if ( ! wp_mkdir_p( dirname( $create['target'] ) ) ) {
			return self::result( 'failed', __( 'Dossier impossible à créer.', 'upsidly-agent' ) );
		}
		$backup = Upsidly_Agent_Backup::create( $action_id, 'created_file', $create['target'], array() );
		$write  = Upsidly_Agent_Backup::write_file( $create['target'], (string) $input['content'] );
		if ( is_wp_error( $write ) ) {
			return self::result( 'failed', $write->get_error_message(), $backup );
		}
		$done = sprintf( 'Fichier wp-content/%s créé.', Upsidly_Agent_Guard::relative( $create['target'] ) );
		return self::is_code( $create['target'] ) ? self::checked( $backup, $done ) : self::result( 'applied', $done, $backup );
	}

	// ---------------------------------------------------------------------
	// Extensions et thèmes.
	// ---------------------------------------------------------------------

	/**
	 * Extension installée ?
	 *
	 * @param string $plugin Fichier principal.
	 * @return array|null Données de l'extension.
	 */
	private static function plugin_data( $plugin ) {
		self::load_admin();
		$plugins = get_plugins();
		return isset( $plugins[ $plugin ] ) ? $plugins[ $plugin ] : null;
	}

	/**
	 * Aperçu de plugin_activate.
	 *
	 * @param array $input plugin.
	 * @return array
	 */
	public static function preview_plugin_activate( array $input ) {
		$plugin = (string) ( $input['plugin'] ?? '' );
		$data   = self::plugin_data( $plugin );
		if ( ! $data ) {
			return self::refuse( __( 'Extension introuvable.', 'upsidly-agent' ) );
		}
		if ( is_plugin_active( $plugin ) ) {
			return self::refuse( __( 'Cette extension est déjà active.', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: %s: extension. */
			'title'   => sprintf( __( 'Activer l\'extension %s', 'upsidly-agent' ), $data['Name'] ),
			'preview' => '<p>' . esc_html( $data['Name'] . ' ' . $data['Version'] ) . '</p>',
		);
	}

	/**
	 * Applique plugin_activate.
	 *
	 * @param array $input     plugin.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_plugin_activate( array $input, $action_id ) {
		$plugin  = (string) ( $input['plugin'] ?? '' );
		$preview = self::preview_plugin_activate( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		$backup = Upsidly_Agent_Backup::create( $action_id, 'plugins', 'active_plugins', array( 'active' => (array) get_option( 'active_plugins', array() ) ) );
		$result = activate_plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			return self::result( 'failed', $result->get_error_message(), $backup );
		}
		return self::checked( $backup, sprintf( 'Extension %s activée.', $plugin ) );
	}

	/**
	 * Aperçu de plugin_deactivate.
	 *
	 * @param array $input plugin.
	 * @return array
	 */
	public static function preview_plugin_deactivate( array $input ) {
		$plugin = (string) ( $input['plugin'] ?? '' );
		$data   = self::plugin_data( $plugin );
		if ( ! $data ) {
			return self::refuse( __( 'Extension introuvable.', 'upsidly-agent' ) );
		}
		if ( plugin_basename( UPSIDLY_AGENT_FILE ) === $plugin ) {
			return self::refuse( __( 'L\'agent ne peut pas se désactiver lui-même.', 'upsidly-agent' ) );
		}
		if ( ! is_plugin_active( $plugin ) ) {
			return self::refuse( __( 'Cette extension n\'est pas active.', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: %s: extension. */
			'title'   => sprintf( __( 'Désactiver l\'extension %s', 'upsidly-agent' ), $data['Name'] ),
			'preview' => '<p>' . esc_html( $data['Name'] . ' ' . $data['Version'] ) . '</p>',
		);
	}

	/**
	 * Applique plugin_deactivate.
	 *
	 * @param array $input     plugin.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_plugin_deactivate( array $input, $action_id ) {
		$preview = self::preview_plugin_deactivate( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		$plugin = (string) $input['plugin'];
		$backup = Upsidly_Agent_Backup::create( $action_id, 'plugins', 'active_plugins', array( 'active' => (array) get_option( 'active_plugins', array() ) ) );
		deactivate_plugins( $plugin, false );
		return self::checked( $backup, sprintf( 'Extension %s désactivée.', $plugin ) );
	}

	/**
	 * Aperçu de theme_activate.
	 *
	 * @param array $input stylesheet.
	 * @return array
	 */
	public static function preview_theme_activate( array $input ) {
		$theme = wp_get_theme( (string) ( $input['stylesheet'] ?? '' ) );
		if ( ! $theme->exists() ) {
			return self::refuse( __( 'Thème introuvable.', 'upsidly-agent' ) );
		}
		if ( get_stylesheet() === $theme->get_stylesheet() ) {
			return self::refuse( __( 'Ce thème est déjà actif.', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: 1: thème actuel, 2: nouveau thème. */
			'title'   => sprintf( __( 'Remplacer le thème %1$s par %2$s', 'upsidly-agent' ), wp_get_theme()->get( 'Name' ), $theme->get( 'Name' ) ),
			'preview' => '<p>' . esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ) . '</p>',
		);
	}

	/**
	 * Applique theme_activate.
	 *
	 * @param array $input     stylesheet.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_theme_activate( array $input, $action_id ) {
		$preview = self::preview_theme_activate( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		$backup = Upsidly_Agent_Backup::create( $action_id, 'theme', 'stylesheet', array( 'stylesheet' => get_stylesheet() ) );
		switch_theme( (string) $input['stylesheet'] );
		return self::checked( $backup, sprintf( 'Thème %s activé.', (string) $input['stylesheet'] ) );
	}

	/**
	 * Aperçu de plugin_update.
	 *
	 * @param array $input plugin.
	 * @return array
	 */
	public static function preview_plugin_update( array $input ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return self::refuse( __( 'Les mises à jour sont désactivées sur ce site (DISALLOW_FILE_MODS).', 'upsidly-agent' ) );
		}
		$plugin = (string) ( $input['plugin'] ?? '' );
		$data   = self::plugin_data( $plugin );
		if ( ! $data ) {
			return self::refuse( __( 'Extension introuvable.', 'upsidly-agent' ) );
		}
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( empty( $updates->response[ $plugin ]->new_version ) ) {
			return self::refuse( __( 'Aucune mise à jour connue pour cette extension.', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: 1: extension, 2: version actuelle, 3: nouvelle version. */
			'title'   => sprintf( __( 'Mettre à jour %1$s (%2$s → %3$s)', 'upsidly-agent' ), $data['Name'], $data['Version'], $updates->response[ $plugin ]->new_version ),
			'preview' => '<p>' . esc_html__( 'Une copie de la version actuelle est conservée pour revenir en arrière.', 'upsidly-agent' ) . '</p>',
		);
	}

	/**
	 * Applique plugin_update.
	 *
	 * @param array $input     plugin.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_plugin_update( array $input, $action_id ) {
		$preview = self::preview_plugin_update( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return self::result( 'failed', $fs->get_error_message() );
		}
		$plugin = (string) $input['plugin'];
		$dir    = WP_PLUGIN_DIR . '/' . dirname( $plugin );
		$zip    = Upsidly_Agent_Backup::zip_directory( $dir );
		if ( is_wp_error( $zip ) ) {
			return self::result( 'failed', $zip->get_error_message() );
		}
		$was_active = is_plugin_active( $plugin );
		$backup     = Upsidly_Agent_Backup::create( $action_id, 'dir_zip', $dir, array( 'zip' => $zip ) );

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin );
		if ( true !== $result ) {
			Upsidly_Agent_Backup::restore( $backup );
			return self::result( 'failed', 'Mise à jour échouée : ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'erreur inconnue' ) . '. Ancienne version restaurée.', $backup );
		}
		if ( $was_active && ! is_plugin_active( $plugin ) ) {
			activate_plugin( $plugin );
		}
		$data = self::plugin_data( $plugin );
		return self::checked( $backup, sprintf( 'Extension %s mise à jour (version %s).', $plugin, $data ? $data['Version'] : '?' ) );
	}

	/**
	 * Aperçu de theme_update.
	 *
	 * @param array $input stylesheet.
	 * @return array
	 */
	public static function preview_theme_update( array $input ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return self::refuse( __( 'Les mises à jour sont désactivées sur ce site (DISALLOW_FILE_MODS).', 'upsidly-agent' ) );
		}
		$stylesheet = (string) ( $input['stylesheet'] ?? '' );
		$theme      = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return self::refuse( __( 'Thème introuvable.', 'upsidly-agent' ) );
		}
		self::load_admin();
		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		if ( empty( $updates->response[ $stylesheet ]['new_version'] ) ) {
			return self::refuse( __( 'Aucune mise à jour connue pour ce thème.', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: 1: thème, 2: version actuelle, 3: nouvelle version. */
			'title'   => sprintf( __( 'Mettre à jour le thème %1$s (%2$s → %3$s)', 'upsidly-agent' ), $theme->get( 'Name' ), $theme->get( 'Version' ), $updates->response[ $stylesheet ]['new_version'] ),
			'preview' => '<p>' . esc_html__( 'Une copie de la version actuelle est conservée pour revenir en arrière.', 'upsidly-agent' ) . '</p>',
		);
	}

	/**
	 * Applique theme_update.
	 *
	 * @param array $input     stylesheet.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_theme_update( array $input, $action_id ) {
		$preview = self::preview_theme_update( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return self::result( 'failed', $fs->get_error_message() );
		}
		$stylesheet = (string) $input['stylesheet'];
		$dir        = get_theme_root( $stylesheet ) . '/' . $stylesheet;
		$zip        = Upsidly_Agent_Backup::zip_directory( $dir );
		if ( is_wp_error( $zip ) ) {
			return self::result( 'failed', $zip->get_error_message() );
		}
		$backup   = Upsidly_Agent_Backup::create( $action_id, 'dir_zip', $dir, array( 'zip' => $zip ) );
		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $stylesheet );
		if ( true !== $result ) {
			Upsidly_Agent_Backup::restore( $backup );
			return self::result( 'failed', 'Mise à jour échouée : ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'erreur inconnue' ) . '. Ancienne version restaurée.', $backup );
		}
		return self::checked( $backup, sprintf( 'Thème %s mis à jour (version %s).', $stylesheet, wp_get_theme( $stylesheet )->get( 'Version' ) ) );
	}

	/**
	 * Aperçu de plugin_install.
	 *
	 * @param array $input slug, activate.
	 * @return array
	 */
	public static function preview_plugin_install( array $input ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return self::refuse( __( 'L\'installation d\'extensions est désactivée sur ce site (DISALLOW_FILE_MODS).', 'upsidly-agent' ) );
		}
		$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return self::refuse( __( 'Identifiant d\'extension manquant.', 'upsidly-agent' ) );
		}
		self::load_admin();
		if ( is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
			return self::refuse( __( 'Cette extension est déjà installée.', 'upsidly-agent' ) );
		}
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'          => false,
					'short_description' => true,
				),
			)
		);
		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			return self::refuse( __( 'Extension introuvable sur wordpress.org.', 'upsidly-agent' ) );
		}
		$details = sprintf(
			'%s %s — %s (%s installations actives, note %s/100, testée jusqu\'à WordPress %s)',
			$api->name,
			$api->version,
			wp_strip_all_tags( (string) ( $api->short_description ?? '' ) ),
			number_format_i18n( (int) ( $api->active_installs ?? 0 ) ),
			(int) ( $api->rating ?? 0 ),
			(string) ( $api->tested ?? '?' )
		);
		return array(
			'ok'      => true,
			/* translators: %s: extension. */
			'title'   => sprintf( empty( $input['activate'] ) ? __( 'Installer l\'extension %s', 'upsidly-agent' ) : __( 'Installer et activer l\'extension %s', 'upsidly-agent' ), wp_strip_all_tags( $api->name ) ),
			'preview' => '<p>' . esc_html( $details ) . '</p>',
			'api'     => $api,
		);
	}

	/**
	 * Applique plugin_install.
	 *
	 * @param array $input     slug, activate.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_plugin_install( array $input, $action_id ) {
		$preview = self::preview_plugin_install( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return self::result( 'failed', $fs->get_error_message() );
		}
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $preview['api']->download_link );
		if ( true !== $result ) {
			return self::result( 'failed', 'Installation échouée : ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'erreur inconnue' ) . '.' );
		}
		$plugin = (string) $upgrader->plugin_info();
		$backup = Upsidly_Agent_Backup::create( $action_id, 'installed', $plugin, array( 'plugin' => $plugin ) );
		if ( empty( $input['activate'] ) ) {
			return self::result( 'applied', sprintf( 'Extension %s installée (non activée).', $plugin ), $backup );
		}
		$activated = activate_plugin( $plugin );
		if ( is_wp_error( $activated ) ) {
			return self::result( 'applied', sprintf( 'Extension %s installée, mais son activation a échoué : %s', $plugin, $activated->get_error_message() ), $backup );
		}
		return self::checked( $backup, sprintf( 'Extension %s installée et activée.', $plugin ) );
	}

	// ---------------------------------------------------------------------
	// update_option.
	// ---------------------------------------------------------------------

	/**
	 * Prépare la modification d'une option.
	 *
	 * @param array $input name, value.
	 * @return array{name?: string, existed?: bool, before?: mixed, after?: mixed, error?: string}
	 */
	private static function prepare_option( array $input ) {
		global $wpdb;
		$name = (string) ( $input['name'] ?? '' );
		if ( '' === $name || strlen( $name ) > 191 ) {
			return array( 'error' => __( 'Nom d\'option invalide.', 'upsidly-agent' ) );
		}
		$lower = strtolower( $name );
		if ( in_array( $lower, self::PROTECTED_OPTIONS, true ) || 0 === strpos( $lower, 'upsidly_agent' ) || $wpdb->prefix . 'user_roles' === $lower || 0 === strpos( $lower, '_transient' ) || 0 === strpos( $lower, '_site_transient' ) || Upsidly_Agent_Guard::is_secret_key( $name ) ) {
			return array( 'error' => __( 'Cette option est protégée : l\'agent ne peut pas la modifier.', 'upsidly-agent' ) );
		}

		$sentinel = '__upsidly_agent_absent__';
		$before   = get_option( $name, $sentinel );
		$existed  = $sentinel !== $before;
		$raw      = (string) ( $input['value'] ?? '' );
		$after    = $raw;

		if ( $existed && ( is_array( $before ) || is_object( $before ) ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array( 'error' => __( 'Cette option est un tableau : donner la valeur complète en JSON.', 'upsidly-agent' ) );
			}
			$after = $decoded;
		} elseif ( ! $existed ) {
			$decoded = json_decode( $raw, true );
			$after   = is_array( $decoded ) ? $decoded : $raw;
		}

		return array(
			'name'    => $name,
			'existed' => $existed,
			'before'  => $existed ? $before : null,
			'after'   => $after,
		);
	}

	/**
	 * Valeur lisible pour l'aperçu (masquée).
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	private static function readable( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( Upsidly_Agent_Guard::redact_value( $value ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return Upsidly_Agent_Guard::redact_text( (string) $value );
	}

	/**
	 * Aperçu de update_option.
	 *
	 * @param array $input Entrées.
	 * @return array
	 */
	public static function preview_update_option( array $input ) {
		$option = self::prepare_option( $input );
		if ( isset( $option['error'] ) ) {
			return self::refuse( $option['error'] );
		}
		return array(
			'ok'      => true,
			/* translators: %s: option. */
			'title'   => sprintf( $option['existed'] ? __( 'Modifier le réglage %s', 'upsidly-agent' ) : __( 'Créer le réglage %s', 'upsidly-agent' ), $option['name'] ),
			'preview' => self::diff( $option['existed'] ? self::readable( $option['before'] ) : '', self::readable( $option['after'] ) ),
		);
	}

	/**
	 * Applique update_option.
	 *
	 * @param array $input     Entrées.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_update_option( array $input, $action_id ) {
		$option = self::prepare_option( $input );
		if ( isset( $option['error'] ) ) {
			return self::result( 'failed', $option['error'] );
		}
		$backup = Upsidly_Agent_Backup::create(
			$action_id,
			'option',
			$option['name'],
			array(
				'existed' => $option['existed'],
				'value'   => $option['existed'] ? maybe_serialize( $option['before'] ) : '',
			)
		);
		update_option( $option['name'], $option['after'] );
		return self::checked( $backup, sprintf( 'Réglage %s enregistré.', $option['name'] ) );
	}

	// ---------------------------------------------------------------------
	// save_content.
	// ---------------------------------------------------------------------

	/**
	 * Métas jamais modifiables.
	 *
	 * @param string $key Clé.
	 * @return bool
	 */
	private static function protected_meta( $key ) {
		return (bool) preg_match( '/^(_elementor|_wp_|_edit_|_upsidly_agent_source)/', $key ) || Upsidly_Agent_Guard::is_secret_key( $key ) || Upsidly_Agent_Guard::is_personal_key( $key );
	}

	/**
	 * Prépare la création ou la modification d'un contenu.
	 *
	 * @param array $input Entrées.
	 * @return array{post?: WP_Post|null, postarr?: array, meta?: array, error?: string}
	 */
	private static function prepare_content( array $input ) {
		$id   = (int) ( $input['id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;

		if ( $id && ! $post ) {
			return array( 'error' => __( 'Contenu introuvable.', 'upsidly-agent' ) );
		}
		if ( $post && ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => __( 'Vous n\'avez pas le droit de modifier ce contenu.', 'upsidly-agent' ) );
		}

		$type   = $post ? $post->post_type : sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		$object = get_post_type_object( $type );
		if ( ! $object || in_array( $type, array( 'attachment', 'revision', 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_global_styles', 'customize_changeset', 'user_request' ), true ) ) {
			return array( 'error' => __( 'Type de contenu non pris en charge.', 'upsidly-agent' ) );
		}
		if ( ! $post && ! current_user_can( $object->cap->create_posts ) ) {
			return array( 'error' => __( 'Vous n\'avez pas le droit de créer ce type de contenu.', 'upsidly-agent' ) );
		}
		if ( $post && Upsidly_Agent_Drafts::is_elementor( $post->ID ) && ( isset( $input['content'] ) || isset( $input['title'] ) ) ) {
			return array( 'error' => __( 'Ce contenu est construit avec Elementor : son texte ne peut pas être modifié par l\'agent.', 'upsidly-agent' ) );
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : ( $post ? $post->post_status : 'draft' );
		if ( ! in_array( $status, array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ) {
			$status = 'draft';
		}
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ( ! $post || $post->post_status !== $status ) && ! current_user_can( $object->cap->publish_posts ) ) {
			return array( 'error' => __( 'Vous n\'avez pas le droit de publier ce type de contenu.', 'upsidly-agent' ) );
		}

		$postarr = array(
			'post_type'   => $type,
			'post_status' => $status,
		);
		if ( $post ) {
			$postarr['ID'] = $post->ID;
		} else {
			$postarr['post_author'] = get_current_user_id();
		}
		if ( isset( $input['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( (string) $input['content'] );
		}
		if ( isset( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
		}
		if ( ! $post && empty( $postarr['post_title'] ) ) {
			return array( 'error' => __( 'Titre manquant pour la création.', 'upsidly-agent' ) );
		}

		$meta = array();
		foreach ( (array) ( $input['meta'] ?? array() ) as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}
			if ( self::protected_meta( $key ) ) {
				/* translators: %s: clé de méta. */
				return array( 'error' => sprintf( __( 'La métadonnée %s est protégée.', 'upsidly-agent' ), $key ) );
			}
			$meta[ $key ] = wp_kses_post( (string) $value );
		}

		$terms = array();
		foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $names ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			$object   = get_taxonomy( $taxonomy );
			if ( ! $object || ! is_object_in_taxonomy( $type, $taxonomy ) ) {
				/* translators: %s: taxonomie. */
				return array( 'error' => sprintf( __( 'La taxonomie %s n\'existe pas pour ce type de contenu.', 'upsidly-agent' ), $taxonomy ) );
			}
			if ( ! current_user_can( $object->cap->assign_terms ) ) {
				/* translators: %s: taxonomie. */
				return array( 'error' => sprintf( __( 'Vous n\'avez pas le droit d\'assigner des termes de %s.', 'upsidly-agent' ), $object->labels->name ) );
			}
			$clean = array();
			foreach ( (array) $names as $name ) {
				$name = sanitize_text_field( (string) $name );
				if ( '' === $name ) {
					continue;
				}
				if ( ! get_term_by( 'name', $name, $taxonomy ) && ! current_user_can( $object->cap->manage_terms ) ) {
					/* translators: 1: terme, 2: taxonomie. */
					return array( 'error' => sprintf( __( 'Le terme « %1$s » n\'existe pas dans %2$s et vous n\'avez pas le droit de le créer.', 'upsidly-agent' ), $name, $object->labels->name ) );
				}
				$clean[] = $name;
			}
			$terms[ $taxonomy ] = array_values( array_unique( $clean ) );
		}

		return array(
			'post'    => $post,
			'postarr' => $postarr,
			'meta'    => $meta,
			'terms'   => $terms,
		);
	}

	/**
	 * Identifiants des termes (existants ou créés).
	 *
	 * @param string   $taxonomy Taxonomie.
	 * @param string[] $names    Noms.
	 * @return int[]
	 */
	private static function term_ids( $taxonomy, array $names ) {
		$ids = array();
		foreach ( $names as $name ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$created = wp_insert_term( $name, $taxonomy );
			if ( ! is_wp_error( $created ) ) {
				$ids[] = (int) $created['term_id'];
			}
		}
		return $ids;
	}

	/**
	 * Aperçu de save_content.
	 *
	 * @param array $input Entrées.
	 * @return array
	 */
	public static function preview_save_content( array $input ) {
		$content = self::prepare_content( $input );
		if ( isset( $content['error'] ) ) {
			return self::refuse( $content['error'] );
		}
		$post    = $content['post'];
		$postarr = $content['postarr'];

		$before = $post ? sprintf( "Titre : %s\nStatut : %s\nExtrait : %s\n\n%s", $post->post_title, $post->post_status, $post->post_excerpt, Upsidly_Agent_Drafts::to_text( $post->post_content ) ) : '';
		$after  = sprintf(
			"Titre : %s\nStatut : %s\nExtrait : %s\n\n%s",
			$postarr['post_title'] ?? ( $post ? $post->post_title : '' ),
			$postarr['post_status'],
			$postarr['post_excerpt'] ?? ( $post ? $post->post_excerpt : '' ),
			Upsidly_Agent_Drafts::to_text( $postarr['post_content'] ?? ( $post ? $post->post_content : '' ) )
		);
		foreach ( $content['meta'] as $key => $value ) {
			$before .= $post ? "\nMéta {$key} : " . (string) get_post_meta( $post->ID, $key, true ) : '';
			$after  .= "\nMéta {$key} : {$value}";
		}
		foreach ( $content['terms'] as $taxonomy => $names ) {
			$label   = get_taxonomy( $taxonomy )->labels->name;
			$current = $post ? wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) ) : array();
			$before .= $post ? "\n{$label} : " . implode( ', ', is_wp_error( $current ) ? array() : $current ) : '';
			$after  .= "\n{$label} : " . implode( ', ', $names );
		}

		return array(
			'ok'      => true,
			'title'   => $post
				/* translators: 1: type de contenu, 2: titre. */
				? sprintf( __( 'Modifier %1$s « %2$s »', 'upsidly-agent' ), strtolower( get_post_type_object( $post->post_type )->labels->singular_name ), $post->post_title )
				/* translators: 1: type de contenu, 2: titre. */
				: sprintf( __( 'Créer %1$s « %2$s »', 'upsidly-agent' ), strtolower( get_post_type_object( $postarr['post_type'] )->labels->singular_name ), $postarr['post_title'] ),
			'preview' => self::diff( $before, $after ),
		);
	}

	/**
	 * Applique save_content.
	 *
	 * @param array $input     Entrées.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_save_content( array $input, $action_id ) {
		$content = self::prepare_content( $input );
		if ( isset( $content['error'] ) ) {
			return self::result( 'failed', $content['error'] );
		}
		$post = $content['post'];

		if ( $post ) {
			$previous_meta = array();
			foreach ( array_keys( $content['meta'] ) as $key ) {
				$previous_meta[ $key ] = metadata_exists( 'post', $post->ID, $key ) ? maybe_serialize( get_post_meta( $post->ID, $key, true ) ) : null;
			}
			$previous_terms = array();
			foreach ( array_keys( $content['terms'] ) as $taxonomy ) {
				$ids                         = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				$previous_terms[ $taxonomy ] = is_wp_error( $ids ) ? array() : $ids;
			}
			$backup = Upsidly_Agent_Backup::create(
				$action_id,
				'post',
				(string) $post->ID,
				array(
					'title'   => $post->post_title,
					'content' => $post->post_content,
					'excerpt' => $post->post_excerpt,
					'status'  => $post->post_status,
					'meta'    => $previous_meta,
					'terms'   => $previous_terms,
				)
			);
			$result = wp_update_post( wp_slash( $content['postarr'] ), true );
		} else {
			$result = wp_insert_post( wp_slash( $content['postarr'] ), true );
			$backup = is_wp_error( $result ) ? 0 : Upsidly_Agent_Backup::create( $action_id, 'post', (string) $result, array( 'created' => true ) );
		}
		if ( is_wp_error( $result ) ) {
			return self::result( 'failed', $result->get_error_message(), $backup );
		}

		foreach ( $content['meta'] as $key => $value ) {
			update_post_meta( (int) $result, $key, wp_slash( $value ) );
		}
		foreach ( $content['terms'] as $taxonomy => $names ) {
			wp_set_object_terms( (int) $result, self::term_ids( $taxonomy, $names ), $taxonomy, false );
		}

		return self::result(
			'applied',
			sprintf( '%s (id %d, statut %s) : %s', $post ? 'Contenu modifié' : 'Contenu créé', (int) $result, get_post_status( (int) $result ), get_permalink( (int) $result ) ),
			$backup
		);
	}

	// ---------------------------------------------------------------------
	// save_term.
	// ---------------------------------------------------------------------

	/**
	 * Prépare la création ou la modification d'un terme.
	 *
	 * @param array $input taxonomy, id, name, slug, description, parent.
	 * @return array{taxonomy?: WP_Taxonomy, term?: WP_Term|null, args?: array, name?: string, error?: string}
	 */
	private static function prepare_term( array $input ) {
		$taxonomy = get_taxonomy( sanitize_key( (string) ( $input['taxonomy'] ?? '' ) ) );
		if ( ! $taxonomy || empty( $taxonomy->show_ui ) ) {
			return array( 'error' => __( 'Taxonomie introuvable.', 'upsidly-agent' ) );
		}
		if ( ! current_user_can( $taxonomy->cap->manage_terms ) ) {
			/* translators: %s: taxonomie. */
			return array( 'error' => sprintf( __( 'Vous n\'avez pas le droit de gérer les %s.', 'upsidly-agent' ), strtolower( $taxonomy->labels->name ) ) );
		}

		$id   = (int) ( $input['id'] ?? 0 );
		$term = $id ? get_term( $id, $taxonomy->name ) : null;
		if ( $id && ( ! $term || is_wp_error( $term ) ) ) {
			return array( 'error' => __( 'Terme introuvable.', 'upsidly-agent' ) );
		}

		$args = array();
		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( isset( $input['parent'] ) && '' !== (string) $input['parent'] ) {
			if ( ! $taxonomy->hierarchical ) {
				return array( 'error' => __( 'Cette taxonomie n\'a pas de termes parents.', 'upsidly-agent' ) );
			}
			$parent = is_numeric( $input['parent'] ) ? get_term( (int) $input['parent'], $taxonomy->name ) : get_term_by( 'name', (string) $input['parent'], $taxonomy->name );
			if ( ! $parent || is_wp_error( $parent ) ) {
				return array( 'error' => __( 'Terme parent introuvable.', 'upsidly-agent' ) );
			}
			$args['parent'] = (int) $parent->term_id;
		}

		$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : ( $term ? $term->name : '' );
		if ( '' === $name ) {
			return array( 'error' => __( 'Nom du terme manquant.', 'upsidly-agent' ) );
		}
		if ( ! $term && term_exists( $name, $taxonomy->name, $args['parent'] ?? 0 ) ) {
			return array( 'error' => __( 'Ce terme existe déjà.', 'upsidly-agent' ) );
		}

		return array(
			'taxonomy' => $taxonomy,
			'term'     => $term,
			'args'     => $args,
			'name'     => $name,
		);
	}

	/**
	 * Aperçu de save_term.
	 *
	 * @param array $input Entrées.
	 * @return array
	 */
	public static function preview_save_term( array $input ) {
		$prepared = self::prepare_term( $input );
		if ( isset( $prepared['error'] ) ) {
			return self::refuse( $prepared['error'] );
		}
		$term   = $prepared['term'];
		$label  = $prepared['taxonomy']->labels->singular_name;
		$parent = isset( $prepared['args']['parent'] ) ? get_term( $prepared['args']['parent'] )->name : '';
		$after  = sprintf( "Nom : %s\nSlug : %s\nParent : %s\nDescription : %s", $prepared['name'], $prepared['args']['slug'] ?? ( $term ? $term->slug : sanitize_title( $prepared['name'] ) ), $parent, $prepared['args']['description'] ?? ( $term ? $term->description : '' ) );
		$before = $term ? sprintf( "Nom : %s\nSlug : %s\nParent : %s\nDescription : %s", $term->name, $term->slug, $term->parent ? get_term( $term->parent )->name : '', $term->description ) : '';

		return array(
			'ok'      => true,
			'title'   => $term
				/* translators: 1: type de terme, 2: nom. */
				? sprintf( __( 'Modifier %1$s « %2$s »', 'upsidly-agent' ), strtolower( $label ), $term->name )
				/* translators: 1: type de terme, 2: nom. */
				: sprintf( __( 'Créer %1$s « %2$s »', 'upsidly-agent' ), strtolower( $label ), $prepared['name'] ),
			'preview' => self::diff( $before, $after ),
		);
	}

	/**
	 * Applique save_term.
	 *
	 * @param array $input     Entrées.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_save_term( array $input, $action_id ) {
		$prepared = self::prepare_term( $input );
		if ( isset( $prepared['error'] ) ) {
			return self::result( 'failed', $prepared['error'] );
		}
		$taxonomy = $prepared['taxonomy']->name;
		$term     = $prepared['term'];

		if ( $term ) {
			$backup = Upsidly_Agent_Backup::create(
				$action_id,
				'term',
				(string) $term->term_id,
				array(
					'taxonomy'    => $taxonomy,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => $term->parent,
				)
			);
			$result = wp_update_term( $term->term_id, $taxonomy, array( 'name' => $prepared['name'] ) + $prepared['args'] );
		} else {
			$result = wp_insert_term( $prepared['name'], $taxonomy, $prepared['args'] );
			$backup = is_wp_error( $result ) ? 0 : Upsidly_Agent_Backup::create(
				$action_id,
				'term',
				(string) $result['term_id'],
				array(
					'taxonomy' => $taxonomy,
					'created'  => true,
				)
			);
		}
		if ( is_wp_error( $result ) ) {
			return self::result( 'failed', $result->get_error_message(), $backup );
		}

		return self::result(
			'applied',
			sprintf( '%s « %s » %s (id %d, taxonomie %s).', $prepared['taxonomy']->labels->singular_name, $prepared['name'], $term ? 'modifié' : 'créé', (int) $result['term_id'], $taxonomy ),
			$backup
		);
	}
}
