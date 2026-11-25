<?php
/**
 * Outils fichiers (lecture) : list_files, read_file, search_files.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fichiers de wp-content.
 */
class Upsidly_Agent_Tools_Files {

	const MAX_LIST_ENTRIES = 500;
	const MAX_READ_LINES   = 400;
	const MAX_SEARCH_FILES = 5000;
	const MAX_SEARCH_BYTES = 1048576;
	const SKIPPED_DIRS     = array( '.git', 'node_modules', '.svn', 'cache', 'upgrade', 'upsidly-agent-backups' );

	/**
	 * Liste un dossier.
	 *
	 * @param array $input path, depth.
	 * @return string|WP_Error
	 */
	public static function list_files( array $input ) {
		$dir = Upsidly_Agent_Guard::resolve( (string) ( $input['path'] ?? '' ), 'dir' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$depth = max( 1, min( 3, (int) ( $input['depth'] ?? 1 ) ) );

		$lines = array();
		self::walk( $dir, $depth, $lines );

		$header = 'Dossier wp-content/' . Upsidly_Agent_Guard::relative( $dir ) . ' :';
		if ( count( $lines ) >= self::MAX_LIST_ENTRIES ) {
			$lines[] = '[… liste limitée à ' . self::MAX_LIST_ENTRIES . ' entrées]';
		}
		return Upsidly_Agent_Guard::truncate( $header . "\n" . implode( "\n", $lines ) );
	}

	/**
	 * Parcours récursif borné.
	 *
	 * @param string $dir   Dossier.
	 * @param int    $depth Profondeur restante.
	 * @param array  $lines Lignes (par référence).
	 */
	private static function walk( $dir, $depth, array &$lines ) {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || count( $lines ) >= self::MAX_LIST_ENTRIES ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( Upsidly_Agent_Guard::denied( $path ) && ! is_dir( $path ) ) {
				continue;
			}
			$relative = Upsidly_Agent_Guard::relative( $path );
			if ( is_dir( $path ) ) {
				if ( in_array( $entry, array( 'upsidly-agent-backups' ), true ) ) {
					continue;
				}
				$lines[] = 'd ' . $relative . '/';
				if ( $depth > 1 && ! in_array( $entry, self::SKIPPED_DIRS, true ) && ! is_link( $path ) ) {
					self::walk( $path, $depth - 1, $lines );
				}
			} else {
				$lines[] = sprintf( 'f %s (%s, %s)', $relative, size_format( (int) filesize( $path ) ), gmdate( 'Y-m-d H:i', (int) filemtime( $path ) ) );
			}
		}
	}

	/**
	 * Lit un fichier (plage de lignes numérotées).
	 *
	 * @param array $input path, start_line, end_line.
	 * @return string|WP_Error
	 */
	public static function read_file( array $input ) {
		$file = Upsidly_Agent_Guard::resolve( (string) ( $input['path'] ?? '' ), 'file' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( filesize( $file ) > Upsidly_Agent_Guard::MAX_FILE_BYTES ) {
			return new WP_Error( 'upsidly_agent_too_big', __( 'Fichier trop volumineux (plus de 2 Mo).', 'upsidly-agent' ) );
		}
		if ( Upsidly_Agent_Guard::is_binary( $file ) ) {
			return new WP_Error( 'upsidly_agent_binary', __( 'Fichier binaire : lecture impossible.', 'upsidly-agent' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local validé par le garde-fou.
		$lines = preg_split( '/\R/', (string) file_get_contents( $file ) );
		$total = count( $lines );
		$start = max( 1, (int) ( $input['start_line'] ?? 1 ) );
		$end   = (int) ( $input['end_line'] ?? 0 );
		$end   = $end > 0 ? min( $end, $total, $start + self::MAX_READ_LINES - 1 ) : min( $total, $start + self::MAX_READ_LINES - 1 );

		$out = array();
		for ( $i = $start; $i <= $end; $i++ ) {
			$out[] = str_pad( (string) $i, 5, ' ', STR_PAD_LEFT ) . ' | ' . $lines[ $i - 1 ];
		}

		$header = sprintf( 'wp-content/%s — lignes %d à %d sur %d', Upsidly_Agent_Guard::relative( $file ), $start, $end, $total );
		if ( $end < $total ) {
			$header .= sprintf( ' (suite : start_line = %d)', $end + 1 );
		}

		// Masque les secrets ; les e-mails d'un fichier de code restent lisibles.
		return Upsidly_Agent_Guard::truncate( Upsidly_Agent_Guard::redact_text( $header . "\n" . implode( "\n", $out ), false ) );
	}

	/**
	 * Recherche dans les fichiers.
	 *
	 * @param array $input query, path, regex, file_glob, max_results.
	 * @return string|WP_Error
	 */
	public static function search_files( array $input ) {
		$query = (string) ( $input['query'] ?? '' );
		if ( '' === $query ) {
			return new WP_Error( 'upsidly_agent_query', __( 'Texte à chercher manquant.', 'upsidly-agent' ) );
		}
		$regex = ! empty( $input['regex'] );
		if ( $regex ) {
			$pattern = '~' . str_replace( '~', '\~', $query ) . '~u';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validation d'une expression fournie par l'IA.
			if ( false === @preg_match( $pattern, '' ) ) {
				return new WP_Error( 'upsidly_agent_regex', __( 'Expression régulière invalide.', 'upsidly-agent' ) );
			}
		}
		$glob = (string) ( $input['file_glob'] ?? '' );
		$max  = max( 1, min( 200, (int) ( $input['max_results'] ?? 50 ) ) );

		if ( ! empty( $input['path'] ) ) {
			$dir = Upsidly_Agent_Guard::resolve( (string) $input['path'], 'dir' );
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			$roots = array( $dir );
		} else {
			$roots = self::default_roots();
		}

		$results = array();
		$scanned = 0;
		$started = microtime( true );

		foreach ( $roots as $root ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					static function ( $current ) {
						return ! ( $current->isDir() && in_array( $current->getFilename(), self::SKIPPED_DIRS, true ) );
					}
				)
			);
			foreach ( $iterator as $file ) {
				if ( count( $results ) >= $max || $scanned >= self::MAX_SEARCH_FILES || microtime( true ) - $started > 15 ) {
					break 2;
				}
				if ( ! $file->isFile() || $file->getSize() > self::MAX_SEARCH_BYTES ) {
					continue;
				}
				$path = $file->getPathname();
				if ( '' !== $glob && ! fnmatch( $glob, $file->getFilename() ) ) {
					continue;
				}
				if ( Upsidly_Agent_Guard::denied( $path ) || Upsidly_Agent_Guard::is_binary( $path ) ) {
					continue;
				}
				++$scanned;

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local, taille bornée.
				$lines = preg_split( '/\R/', (string) file_get_contents( $path ) );
				foreach ( $lines as $number => $line ) {
					$found = $regex ? preg_match( $pattern, $line ) : false !== stripos( $line, $query );
					if ( $found ) {
						$results[] = sprintf( '%s:%d: %s', Upsidly_Agent_Guard::relative( $path ), $number + 1, mb_substr( trim( $line ), 0, 300 ) );
						if ( count( $results ) >= $max ) {
							break;
						}
					}
				}
			}
		}

		$summary = sprintf( '%d résultat(s), %d fichier(s) parcouru(s).', count( $results ), $scanned );
		if ( $scanned >= self::MAX_SEARCH_FILES || count( $results ) >= $max ) {
			$summary .= ' Recherche interrompue à la limite : précisez le dossier ou le filtre.';
		}

		return Upsidly_Agent_Guard::truncate( Upsidly_Agent_Guard::redact_text( $summary . "\n" . implode( "\n", $results ), false ) );
	}

	/**
	 * Dossiers par défaut : thème actif (et parent) et extensions actives.
	 *
	 * @return string[]
	 */
	private static function default_roots() {
		$roots = array( get_stylesheet_directory() );
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$roots[] = get_template_directory();
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$dir = WP_PLUGIN_DIR . '/' . dirname( (string) $plugin );
			if ( '.' !== dirname( (string) $plugin ) && is_dir( $dir ) ) {
				$roots[] = $dir;
			}
		}
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$roots[] = WPMU_PLUGIN_DIR;
		}

		return array_values(
			array_filter(
				array_unique( array_map( 'realpath', $roots ) ),
				static function ( $dir ) {
					return is_string( $dir ) && 0 === strpos( $dir, Upsidly_Agent_Guard::root() );
				}
			)
		);
	}
}
