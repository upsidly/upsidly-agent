<?php
/**
 * Outils sensibles (lot C) : écriture SQL, exécution de PHP, suppressions.
 *
 * Niveau d'agent « full » uniquement, double confirmation dans l'interface.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Même contrat que Upsidly_Agent_Tools_Write (preview_* / apply_*).
 */
class Upsidly_Agent_Tools_Dangerous {

	const MAX_ROWS = 5000;

	/**
	 * Fonctions interdites dans run_php.
	 */
	const FORBIDDEN_FUNCTIONS = array(
		'exec',
		'shell_exec',
		'system',
		'passthru',
		'proc_open',
		'popen',
		'pcntl_exec',
		'pcntl_fork',
		'dl',
		'putenv',
		'ini_set',
		'set_include_path',
		'assert',
		'create_function',
		'call_user_func',
		'call_user_func_array',
		'forward_static_call',
		'forward_static_call_array',
		'register_shutdown_function',
		'file_put_contents',
		'file_get_contents',
		'fopen',
		'fwrite',
		'fputs',
		'file',
		'readfile',
		'unlink',
		'rename',
		'copy',
		'mkdir',
		'rmdir',
		'chmod',
		'chown',
		'touch',
		'symlink',
		'link',
		'tempnam',
		'tmpfile',
		'move_uploaded_file',
		'highlight_file',
		'show_source',
		'parse_ini_file',
		'glob',
		'scandir',
		'opendir',
		'curl_init',
		'curl_exec',
		'curl_multi_exec',
		'fsockopen',
		'pfsockopen',
		'stream_socket_client',
		'stream_context_create',
		'mail',
		'wp_mail',
		'wp_remote_get',
		'wp_remote_post',
		'wp_remote_request',
		'wp_remote_head',
		'wp_safe_remote_get',
		'wp_safe_remote_post',
		'wp_safe_remote_request',
		'download_url',
		'wp_insert_user',
		'wp_create_user',
		'wp_update_user',
		'wp_delete_user',
		'wp_set_password',
		'wp_set_current_user',
		'wp_set_auth_cookie',
		'add_role',
		'remove_role',
		'add_cap',
		'set_role',
		'add_user_meta',
		'update_user_meta',
		'grant_super_admin',
		'eval',
		'base64_decode',
		'str_rot13',
		'gzinflate',
		'gzuncompress',
		'unserialize',
		'extract',
		'parse_str',
		'header',
		'setcookie',
		'exit',
		'die',
	);

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
	 * @param string $status    applied | failed.
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
	 * Contrôle de santé, restauration si le site tombe en erreur.
	 *
	 * @param int    $backup_id Sauvegarde (0 : non restaurable).
	 * @param string $done      Description.
	 * @return array
	 */
	private static function checked( $backup_id, $done ) {
		$health = Upsidly_Agent_Health_Check::run();
		if ( $health['ok'] ) {
			return self::result( 'applied', $done . ( $health['checked'] ? ' Le site répond normalement.' : ' Contrôle automatique impossible : demander à la personne de vérifier le site.' ), $backup_id );
		}
		if ( $backup_id ) {
			$restored = Upsidly_Agent_Backup::restore( $backup_id );
			return array(
				'status'    => 'reverted',
				'content'   => sprintf( 'Action annulée automatiquement : le site est tombé en erreur (%s). %s', $health['detail'], true === $restored ? 'État précédent restauré.' : 'ATTENTION : la restauration a échoué.' ),
				'is_error'  => true,
				'backup_id' => $backup_id,
			);
		}
		return self::result( 'failed', sprintf( 'ATTENTION : le site est en erreur après cette action (%s) et elle n\'est pas annulable automatiquement. Prévenir la personne immédiatement.', $health['detail'] ) );
	}

	/**
	 * Bloc de code affiché dans l'aperçu.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function code_block( $code ) {
		return '<pre class="upsidly-agent-code">' . esc_html( $code ) . '</pre>';
	}

	// ---------------------------------------------------------------------
	// db_query_write.
	// ---------------------------------------------------------------------

	/**
	 * Analyse une requête d'écriture.
	 *
	 * @param string $sql Requête.
	 * @return array{type?: string, table?: string, where?: string, sql?: string, pk?: string, count?: int, error?: string}
	 */
	private static function parse_write_sql( $sql ) {
		global $wpdb;

		$sql = trim( (string) preg_replace( '#^(\s*(/\*.*?\*/|--[^\n]*\n))+#s', '', (string) $sql ) );
		$sql = rtrim( $sql, "; \t\n\r" );
		if ( '' === $sql || false !== strpos( $sql, ';' ) ) {
			return array( 'error' => __( 'Une seule instruction SQL est autorisée.', 'upsidly-agent' ) );
		}
		if ( preg_match( '/\b(DROP|ALTER|TRUNCATE|CREATE|RENAME|GRANT|REVOKE|LOCK|UNLOCK|SET\s+GLOBAL|INTO\s+(OUT|DUMP)FILE|LOAD_FILE|LOAD\s+DATA|SLEEP\s*\(|BENCHMARK\s*\()/i', $sql ) ) {
			return array( 'error' => __( 'Instruction SQL interdite (modification de structure, fichiers ou administration).', 'upsidly-agent' ) );
		}

		if ( preg_match( '/^UPDATE\s+`?(\w+)`?\s+SET\s+.+?\s+WHERE\s+(.+)$/is', $sql, $m ) ) {
			$type  = 'update';
			$table = $m[1];
			$where = $m[2];
		} elseif ( preg_match( '/^DELETE\s+FROM\s+`?(\w+)`?\s+WHERE\s+(.+)$/is', $sql, $m ) ) {
			$type  = 'delete';
			$table = $m[1];
			$where = $m[2];
		} elseif ( preg_match( '/^INSERT\s+INTO\s+`?(\w+)`?\s*\(/is', $sql, $m ) ) {
			$type  = 'insert';
			$table = $m[1];
			$where = '';
		} else {
			return array( 'error' => __( 'Forme non prise en charge : UPDATE … SET … WHERE …, DELETE FROM … WHERE … ou INSERT INTO table (…) VALUES (…). Une seule table, WHERE obligatoire.', 'upsidly-agent' ) );
		}

		if ( 0 !== strpos( $table, $wpdb->prefix ) ) {
			return array( 'error' => __( 'Seules les tables de ce site (avec son préfixe) peuvent être modifiées.', 'upsidly-agent' ) );
		}
		foreach ( array( $wpdb->users, $wpdb->usermeta, $wpdb->base_prefix . 'users', $wpdb->base_prefix . 'usermeta' ) as $protected ) {
			if ( strtolower( $table ) === strtolower( $protected ) ) {
				return array( 'error' => __( 'Les tables des utilisateurs ne peuvent pas être modifiées par l\'agent.', 'upsidly-agent' ) );
			}
		}
		if ( 0 === strpos( strtolower( $table ), strtolower( $wpdb->prefix . 'upsidly_agent' ) ) ) {
			return array( 'error' => __( 'Les tables de l\'agent ne peuvent pas être modifiées.', 'upsidly-agent' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- vérification de la table.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array( 'error' => __( 'Table introuvable.', 'upsidly-agent' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table validé.
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( count( (array) $keys ) !== 1 ) {
			return array( 'error' => __( 'Table sans clé primaire simple : sauvegarde impossible, requête refusée.', 'upsidly-agent' ) );
		}
		$pk = (string) $keys[0]['Column_name'];

		$count = 1;
		if ( 'insert' !== $type ) {
			$show = $wpdb->hide_errors();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- WHERE fourni par l'IA, validé ci-dessus, exécuté en lecture pour compter.
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" );
			$error = $wpdb->last_error;
			if ( $show ) {
				$wpdb->show_errors();
			}
			if ( '' !== $error || null === $count ) {
				return array( 'error' => 'Erreur SQL : ' . $error );
			}
			$count = (int) $count;
			if ( $count > self::MAX_ROWS ) {
				/* translators: 1: nombre de lignes, 2: maximum. */
				return array( 'error' => sprintf( __( 'Requête trop large : %1$d lignes concernées (maximum %2$d).', 'upsidly-agent' ), $count, self::MAX_ROWS ) );
			}
		}

		return array(
			'type'  => $type,
			'table' => $table,
			'where' => $where,
			'sql'   => $sql,
			'pk'    => $pk,
			'count' => $count,
		);
	}

	/**
	 * Aperçu de db_query_write.
	 *
	 * @param array $input sql.
	 * @return array
	 */
	public static function preview_db_query_write( array $input ) {
		$parsed = self::parse_write_sql( (string) ( $input['sql'] ?? '' ) );
		if ( isset( $parsed['error'] ) ) {
			return self::refuse( $parsed['error'] );
		}
		$verbs = array(
			'update' => __( 'Modifier', 'upsidly-agent' ),
			'delete' => __( 'Supprimer', 'upsidly-agent' ),
			'insert' => __( 'Ajouter', 'upsidly-agent' ),
		);
		return array(
			'ok'      => true,
			/* translators: 1: verbe, 2: nombre de lignes, 3: table. */
			'title'   => sprintf( __( '%1$s %2$d ligne(s) dans la table %3$s', 'upsidly-agent' ), $verbs[ $parsed['type'] ], $parsed['count'], $parsed['table'] ),
			'preview' => self::code_block( $parsed['sql'] ) . '<p>' . esc_html__( 'Les lignes concernées sont sauvegardées pour pouvoir annuler.', 'upsidly-agent' ) . '</p>',
		);
	}

	/**
	 * Applique db_query_write.
	 *
	 * @param array $input     sql.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_db_query_write( array $input, $action_id ) {
		global $wpdb;
		$parsed = self::parse_write_sql( (string) ( $input['sql'] ?? '' ) );
		if ( isset( $parsed['error'] ) ) {
			return self::result( 'failed', $parsed['error'] );
		}
		$table = $parsed['table'];

		$rows = array();
		if ( 'insert' !== $parsed['type'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- sauvegarde des lignes concernées (WHERE validé).
			$rows = (array) $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$parsed['where']}", ARRAY_A );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête validée et validée par la personne.
		$affected = $wpdb->query( $parsed['sql'] );
		if ( false === $affected ) {
			return self::result( 'failed', 'Erreur SQL : ' . $wpdb->last_error . '. Rien n\'a été modifié.' );
		}

		$inserted = array();
		if ( 'insert' === $parsed['type'] && $wpdb->insert_id ) {
			for ( $i = 0; $i < (int) $affected; $i++ ) {
				$inserted[] = (int) $wpdb->insert_id + $i;
			}
		}

		$backup = Upsidly_Agent_Backup::create(
			$action_id,
			'rows',
			$table,
			array(
				'pk'       => $parsed['pk'],
				'type'     => $parsed['type'],
				'rows'     => $rows,
				'inserted' => $inserted,
			)
		);
		wp_cache_flush();

		return self::checked( $backup, sprintf( 'Requête exécutée sur %s : %d ligne(s) concernée(s).', $table, (int) $affected ) );
	}

	// ---------------------------------------------------------------------
	// run_php.
	// ---------------------------------------------------------------------

	/**
	 * Vérifie un extrait de code PHP.
	 *
	 * @param string $code Code (sans balise d'ouverture).
	 * @return string|WP_Error Code normalisé.
	 */
	public static function check_php( $code ) {
		$code = trim( (string) $code );
		$code = (string) preg_replace( '/^<\?(php)?\s*/i', '', $code );
		$code = (string) preg_replace( '/\?>\s*$/', '', $code );
		if ( '' === trim( $code ) ) {
			return new WP_Error( 'upsidly_agent_php', __( 'Code vide.', 'upsidly-agent' ) );
		}
		if ( strlen( $code ) > 20000 ) {
			return new WP_Error( 'upsidly_agent_php', __( 'Code trop long (20 000 caractères maximum).', 'upsidly-agent' ) );
		}

		$syntax = Upsidly_Agent_Guard::php_syntax( "<?php\n" . $code );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}

		$tokens   = token_get_all( "<?php\n" . $code );
		$count    = count( $tokens );
		$found    = array();
		$previous = null;
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( '`' === $token ) {
				$found[] = 'commande système (`…`)';
			}
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EXIT ), true ) ) {
					$found[] = $token[1];
				}
				if ( T_STRING === $token[0] && in_array( strtolower( $token[1] ), self::FORBIDDEN_FUNCTIONS, true ) ) {
					$found[] = $token[1] . '()';
				}
				if ( T_VARIABLE === $token[0] && '$GLOBALS' === $token[1] ) {
					$found[] = '$GLOBALS';
				}
			}
			// Appel dynamique : $fonction( … ) ou ( … )( … ).
			if ( '(' === $token && is_array( $previous ) && T_VARIABLE === $previous[0] ) {
				$found[] = 'appel dynamique ' . $previous[1] . '()';
			}
			if ( '(' === $token && ( ')' === $previous || ']' === $previous ) ) {
				$found[] = 'appel dynamique';
			}
			$previous = $token;
		}

		if ( $found ) {
			return new WP_Error(
				'upsidly_agent_php',
				sprintf(
					/* translators: %s: éléments interdits. */
					__( 'Code refusé, il utilise des éléments interdits : %s. Utiliser les outils dédiés (edit_file, update_option, save_content…).', 'upsidly-agent' ),
					implode( ', ', array_unique( $found ) )
				)
			);
		}
		return $code;
	}

	/**
	 * Aperçu de run_php.
	 *
	 * @param array $input code.
	 * @return array
	 */
	public static function preview_run_php( array $input ) {
		$code = self::check_php( (string) ( $input['code'] ?? '' ) );
		if ( is_wp_error( $code ) ) {
			return self::refuse( $code->get_error_message() );
		}
		return array(
			'ok'      => true,
			'title'   => __( 'Exécuter du code PHP sur le site', 'upsidly-agent' ),
			'preview' => self::code_block( $code ) . '<p><strong>' . esc_html__( 'Attention : cette action n\'est pas annulable.', 'upsidly-agent' ) . '</strong></p>',
		);
	}

	/**
	 * Applique run_php.
	 *
	 * @param array $input     code.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_run_php( array $input, $action_id ) {
		unset( $action_id );
		$code = self::check_php( (string) ( $input['code'] ?? '' ) );
		if ( is_wp_error( $code ) ) {
			return self::result( 'failed', $code->get_error_message() );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- peut être désactivée par l'hébergeur.
		}
		ob_start();
		try {
			$value  = ( static function () use ( $code ) {
				return eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- code analysé, interdits refusés, validé par la personne (niveau full).
			} )();
			$output = (string) ob_get_clean();
		} catch ( Throwable $e ) {
			$output = (string) ob_get_clean();
			return self::result( 'failed', 'Erreur pendant l\'exécution : ' . Upsidly_Agent_Guard::redact_text( $e->getMessage() ) . ( '' !== $output ? "\nSortie : " . Upsidly_Agent_Guard::truncate( Upsidly_Agent_Guard::redact_text( $output ), 4000 ) : '' ) );
		}

		$text = 'Code exécuté.';
		if ( '' !== $output ) {
			$text .= "\nSortie :\n" . Upsidly_Agent_Guard::truncate( Upsidly_Agent_Guard::redact_text( $output ), 8000 );
		}
		if ( null !== $value ) {
			$text .= "\nValeur retournée :\n" . Upsidly_Agent_Guard::to_result( $value );
		}
		wp_cache_flush();
		return self::checked( 0, $text );
	}

	// ---------------------------------------------------------------------
	// Suppressions.
	// ---------------------------------------------------------------------

	/**
	 * Copie un fichier dans le dossier de sauvegarde.
	 *
	 * @param string $path Fichier.
	 * @return string|WP_Error Chemin de la copie.
	 */
	private static function copy_to_backup( $path ) {
		$dir = Upsidly_Agent_Backup::directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$copy = $dir . '/' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false ) . '-' . sanitize_file_name( basename( $path ) ) . '.bak';
		if ( ! copy( $path, $copy ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- copie de sauvegarde locale.
			return new WP_Error( 'upsidly_agent_copy', __( 'Copie de sauvegarde impossible.', 'upsidly-agent' ) );
		}
		return $copy;
	}

	/**
	 * Aperçu de delete_file.
	 *
	 * @param array $input path.
	 * @return array
	 */
	public static function preview_delete_file( array $input ) {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return self::refuse( __( 'La modification de fichiers est désactivée sur ce site (DISALLOW_FILE_EDIT).', 'upsidly-agent' ) );
		}
		$file = Upsidly_Agent_Guard::resolve( (string) ( $input['path'] ?? '' ), 'file' );
		if ( is_wp_error( $file ) ) {
			return self::refuse( $file->get_error_message() );
		}
		if ( 0 === strpos( $file, realpath( UPSIDLY_AGENT_DIR ) . '/' ) ) {
			return self::refuse( __( 'L\'agent ne peut pas supprimer ses propres fichiers.', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: %s: fichier. */
			'title'   => sprintf( __( 'Supprimer le fichier %s', 'upsidly-agent' ), Upsidly_Agent_Guard::relative( $file ) ),
			'preview' => '<p>' . esc_html( sprintf( '%s (%s)', Upsidly_Agent_Guard::relative( $file ), size_format( (int) filesize( $file ) ) ) ) . '</p>',
		);
	}

	/**
	 * Applique delete_file.
	 *
	 * @param array $input     path.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_delete_file( array $input, $action_id ) {
		$preview = self::preview_delete_file( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		$file = Upsidly_Agent_Guard::resolve( (string) $input['path'], 'file' );
		$copy = self::copy_to_backup( $file );
		if ( is_wp_error( $copy ) ) {
			return self::result( 'failed', $copy->get_error_message() );
		}
		$backup = Upsidly_Agent_Backup::create( $action_id, 'file_copy', $file, array( 'copy' => $copy ) );
		wp_delete_file( $file );
		if ( file_exists( $file ) ) {
			return self::result( 'failed', __( 'Suppression impossible (droits).', 'upsidly-agent' ), $backup );
		}
		$done = sprintf( 'Fichier wp-content/%s supprimé (copie conservée).', Upsidly_Agent_Guard::relative( $file ) );
		return preg_match( '/\.php$/i', $file ) ? self::checked( $backup, $done ) : self::result( 'applied', $done, $backup );
	}

	/**
	 * Aperçu de plugin_delete.
	 *
	 * @param array $input plugin.
	 * @return array
	 */
	public static function preview_plugin_delete( array $input ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return self::refuse( __( 'La suppression d\'extensions est désactivée sur ce site (DISALLOW_FILE_MODS).', 'upsidly-agent' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin  = (string) ( $input['plugin'] ?? '' );
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return self::refuse( __( 'Extension introuvable.', 'upsidly-agent' ) );
		}
		if ( plugin_basename( UPSIDLY_AGENT_FILE ) === $plugin ) {
			return self::refuse( __( 'L\'agent ne peut pas se supprimer lui-même.', 'upsidly-agent' ) );
		}
		if ( is_plugin_active( $plugin ) || is_plugin_active_for_network( $plugin ) ) {
			return self::refuse( __( 'Désactiver d\'abord l\'extension.', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: %s: extension. */
			'title'   => sprintf( __( 'Supprimer l\'extension %s', 'upsidly-agent' ), $plugins[ $plugin ]['Name'] ),
			'preview' => '<p>' . esc_html( $plugins[ $plugin ]['Name'] . ' ' . $plugins[ $plugin ]['Version'] ) . ' — ' . esc_html__( 'une copie est conservée pour pouvoir la restaurer.', 'upsidly-agent' ) . '</p>',
		);
	}

	/**
	 * Applique plugin_delete.
	 *
	 * @param array $input     plugin.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_plugin_delete( array $input, $action_id ) {
		$preview = self::preview_plugin_delete( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$plugin = (string) $input['plugin'];

		if ( '.' === dirname( $plugin ) ) {
			$file = WP_PLUGIN_DIR . '/' . $plugin;
			$copy = self::copy_to_backup( $file );
			if ( is_wp_error( $copy ) ) {
				return self::result( 'failed', $copy->get_error_message() );
			}
			$backup = Upsidly_Agent_Backup::create( $action_id, 'file_copy', $file, array( 'copy' => $copy ) );
		} else {
			$dir = WP_PLUGIN_DIR . '/' . dirname( $plugin );
			$zip = Upsidly_Agent_Backup::zip_directory( $dir );
			if ( is_wp_error( $zip ) ) {
				return self::result( 'failed', $zip->get_error_message() );
			}
			$backup = Upsidly_Agent_Backup::create( $action_id, 'dir_zip', $dir, array( 'zip' => $zip ) );
		}

		$deleted = delete_plugins( array( $plugin ) );
		if ( true !== $deleted ) {
			return self::result( 'failed', 'Suppression échouée : ' . ( is_wp_error( $deleted ) ? $deleted->get_error_message() : 'erreur inconnue' ), $backup );
		}
		return self::result( 'applied', sprintf( 'Extension %s supprimée (copie conservée).', $plugin ), $backup );
	}

	/**
	 * Aperçu de theme_delete.
	 *
	 * @param array $input stylesheet.
	 * @return array
	 */
	public static function preview_theme_delete( array $input ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return self::refuse( __( 'La suppression de thèmes est désactivée sur ce site (DISALLOW_FILE_MODS).', 'upsidly-agent' ) );
		}
		$stylesheet = (string) ( $input['stylesheet'] ?? '' );
		$theme      = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return self::refuse( __( 'Thème introuvable.', 'upsidly-agent' ) );
		}
		if ( in_array( $stylesheet, array( get_stylesheet(), get_template() ), true ) ) {
			return self::refuse( __( 'Ce thème est utilisé par le site (actif ou parent du thème actif).', 'upsidly-agent' ) );
		}
		return array(
			'ok'      => true,
			/* translators: %s: thème. */
			'title'   => sprintf( __( 'Supprimer le thème %s', 'upsidly-agent' ), $theme->get( 'Name' ) ),
			'preview' => '<p>' . esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ) . ' — ' . esc_html__( 'une copie est conservée pour pouvoir le restaurer.', 'upsidly-agent' ) . '</p>',
		);
	}

	/**
	 * Applique theme_delete.
	 *
	 * @param array $input     stylesheet.
	 * @param int   $action_id Action.
	 * @return array
	 */
	public static function apply_theme_delete( array $input, $action_id ) {
		$preview = self::preview_theme_delete( $input );
		if ( ! $preview['ok'] ) {
			return self::result( 'failed', $preview['error'] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$stylesheet = (string) $input['stylesheet'];
		$dir        = get_theme_root( $stylesheet ) . '/' . $stylesheet;
		$zip        = Upsidly_Agent_Backup::zip_directory( $dir );
		if ( is_wp_error( $zip ) ) {
			return self::result( 'failed', $zip->get_error_message() );
		}
		$backup  = Upsidly_Agent_Backup::create( $action_id, 'dir_zip', $dir, array( 'zip' => $zip ) );
		$deleted = delete_theme( $stylesheet );
		if ( true !== $deleted ) {
			return self::result( 'failed', 'Suppression échouée : ' . ( is_wp_error( $deleted ) ? $deleted->get_error_message() : 'erreur inconnue' ), $backup );
		}
		return self::result( 'applied', sprintf( 'Thème %s supprimé (copie conservée).', $stylesheet ), $backup );
	}
}
