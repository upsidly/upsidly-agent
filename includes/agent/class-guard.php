<?php
/**
 * Garde-fous de l'agent : chemins autorisés, masquage des secrets et données personnelles, limites de taille.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tout ce qui sort du site vers l'IA passe par ici.
 */
class Upsidly_Agent_Guard {

	const MAX_RESULT_CHARS = 20000;
	const MAX_FILE_BYTES   = 2097152;
	const MASK             = '[masqué]';

	/**
	 * Fichiers jamais lus ni modifiés.
	 */
	const DENIED_BASENAMES = array( 'wp-config.php', '.env', '.htpasswd', 'auth.json', 'id_rsa', 'id_ed25519' );

	/**
	 * Colonnes ou clés de méta contenant des données personnelles.
	 */
	const PERSONAL_KEYS = array(
		'user_email',
		'user_pass',
		'user_activation_key',
		'comment_author_email',
		'comment_author_ip',
		'session_tokens',
		'billing_email',
		'billing_phone',
		'billing_address_1',
		'billing_address_2',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_phone',
		'_billing_email',
		'_billing_phone',
		'_billing_address_1',
		'_billing_address_2',
		'_shipping_address_1',
		'_shipping_address_2',
		'_shipping_phone',
		'_customer_ip_address',
		'customer_ip_address',
		'ip_address',
		'phone',
		'email',
	);

	/**
	 * Racine autorisée (wp-content, chemin réel).
	 *
	 * @return string
	 */
	public static function root() {
		$root = realpath( WP_CONTENT_DIR );
		return false === $root ? WP_CONTENT_DIR : $root;
	}

	/**
	 * Résout un chemin relatif à wp-content et vérifie qu'il est autorisé.
	 *
	 * @param string $relative Chemin relatif à wp-content.
	 * @param string $expect   'file', 'dir' ou 'any'.
	 * @return string|WP_Error Chemin absolu réel.
	 */
	public static function resolve( $relative, $expect = 'any' ) {
		$relative = str_replace( '\\', '/', trim( (string) $relative ) );
		if ( false !== strpos( $relative, "\0" ) ) {
			return new WP_Error( 'upsidly_agent_path', __( 'Chemin invalide.', 'upsidly-agent' ) );
		}

		// Tolère un chemin donné depuis la racine du site (« wp-content/themes/… ») ou absolu dans wp-content.
		$root = self::root();
		if ( 0 === strpos( $relative, $root . '/' ) ) {
			$relative = substr( $relative, strlen( $root ) + 1 );
		}
		$relative = preg_replace( '#^/?wp-content/#', '', $relative );
		$relative = ltrim( (string) $relative, '/' );

		$real = realpath( '' === $relative ? $root : $root . '/' . $relative );
		if ( false === $real ) {
			return new WP_Error( 'upsidly_agent_not_found', __( 'Fichier ou dossier introuvable dans wp-content.', 'upsidly-agent' ) );
		}

		if ( $real !== $root && 0 !== strpos( $real, $root . '/' ) ) {
			return new WP_Error( 'upsidly_agent_path', __( 'Accès refusé : seul le dossier wp-content est accessible.', 'upsidly-agent' ) );
		}

		$error = self::denied( $real );
		if ( $error ) {
			return $error;
		}

		if ( 'file' === $expect && ! is_file( $real ) ) {
			return new WP_Error( 'upsidly_agent_not_file', __( 'Ce chemin n\'est pas un fichier.', 'upsidly-agent' ) );
		}
		if ( 'dir' === $expect && ! is_dir( $real ) ) {
			return new WP_Error( 'upsidly_agent_not_dir', __( 'Ce chemin n\'est pas un dossier.', 'upsidly-agent' ) );
		}

		return $real;
	}

	/**
	 * Chemin interdit ?
	 *
	 * @param string $real Chemin absolu réel.
	 * @return WP_Error|null
	 */
	public static function denied( $real ) {
		$base = strtolower( basename( $real ) );
		if ( in_array( $base, self::DENIED_BASENAMES, true ) || 0 === strpos( $base, '.env' ) ) {
			return new WP_Error( 'upsidly_agent_denied', __( 'Accès refusé : ce fichier contient des informations sensibles.', 'upsidly-agent' ) );
		}
		if ( false !== strpos( $real, '/upsidly-agent-backups' ) ) {
			return new WP_Error( 'upsidly_agent_denied', __( 'Accès refusé : dossier des sauvegardes de l\'agent.', 'upsidly-agent' ) );
		}
		return null;
	}

	/**
	 * Chemin relatif à wp-content (pour l'affichage).
	 *
	 * @param string $real Chemin absolu.
	 * @return string
	 */
	public static function relative( $real ) {
		$root = self::root();
		if ( $real === $root ) {
			return '';
		}
		return 0 === strpos( $real, $root . '/' ) ? substr( $real, strlen( $root ) + 1 ) : basename( $real );
	}

	/**
	 * Fichier binaire ?
	 *
	 * @param string $path Chemin.
	 * @return bool
	 */
	public static function is_binary( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lecture locale bornée.
		$head = (string) file_get_contents( $path, false, null, 0, 8000 );
		return false !== strpos( $head, "\0" );
	}

	/**
	 * Nom de clé sensible (secret) ?
	 *
	 * @param string $name Nom d'option, de méta, de constante ou de clé de tableau.
	 * @return bool
	 */
	public static function is_secret_key( $name ) {
		// Colonnes qui contiennent des noms de clés, pas des secrets.
		if ( in_array( strtolower( (string) $name ), array( 'meta_key', 'option_name', 'autoload', 'umeta_id', 'meta_id' ), true ) ) {
			return false;
		}
		return (bool) preg_match( '/(api[_-]?key|_key$|^key$|secret|token|passw|passwd|pwd|salt|licen[cs]e|credential|private|nonce|auth(?!or)|webhook|client_id|access)/i', (string) $name );
	}

	/**
	 * Clé de donnée personnelle ?
	 *
	 * @param string $name Nom de colonne ou de méta.
	 * @return bool
	 */
	public static function is_personal_key( $name ) {
		$name = strtolower( (string) $name );
		return in_array( $name, self::PERSONAL_KEYS, true ) || (bool) preg_match( '/(e-?mail|phone|t[eé]l[eé]phone|address|adresse|_ip$|^ip_)/', $name );
	}

	/**
	 * Masque secrets et données personnelles dans un texte libre.
	 *
	 * @param string $text           Texte.
	 * @param bool   $personal_data  Masquer aussi e-mails et téléphones.
	 * @return string
	 */
	public static function redact_text( $text, $personal_data = true ) {
		$text = (string) $text;

		$patterns = array(
			// Constantes sensibles : define( 'AUTH_KEY', '…' ).
			'/(define\s*\(\s*[\'"][A-Z0-9_]*(?:KEY|SALT|PASSWORD|SECRET|TOKEN|PASS)[A-Z0-9_]*[\'"]\s*,\s*)([\'"]).*?\2/i' => '$1$2' . self::MASK . '$2',
			// Clés privées.
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => self::MASK,
			// Clés d'API reconnaissables.
			'/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}\b/' => self::MASK,
			'/\bsk-[A-Za-z0-9_\-]{20,}\b/'         => self::MASK,
			'/\bupa_[a-f0-9]{40}\b/'               => self::MASK,
			'/\bAKIA[0-9A-Z]{16}\b/'               => self::MASK,
			'/\bgh[pousr]_[A-Za-z0-9]{30,}\b/'     => self::MASK,
			'/\bxox[abposr]-[A-Za-z0-9\-]{10,}\b/' => self::MASK,
			'/\bAIza[0-9A-Za-z_\-]{30,}\b/'        => self::MASK,
			'/\beyJ[A-Za-z0-9_\-]{10,}\.eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/' => self::MASK,
			// Affectations « password = … », « api_key: … ».
			'/((?:api[_-]?key|secret|token|password|passwd|pwd|client_secret)["\']?\s*(?:=>|=|:)\s*["\']?)([^"\'\s,;)]{6,})/i' => '$1' . self::MASK,
		);
		foreach ( $patterns as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}

		if ( $personal_data ) {
			$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', self::MASK, $text );
			$text = (string) preg_replace( '/(?<![\w.])(?:\+33\s?|0)[1-9](?:[\s.\-]?\d{2}){4}(?![\w.])/', self::MASK, $text );
		}

		return $text;
	}

	/**
	 * Masque récursivement une valeur structurée (options, métas, lignes SQL).
	 *
	 * @param mixed  $value Valeur.
	 * @param string $key   Nom de la clé portant la valeur.
	 * @return mixed
	 */
	public static function redact_value( $value, $key = '' ) {
		if ( '' !== $key && ( self::is_secret_key( $key ) || self::is_personal_key( $key ) ) ) {
			return ( null === $value || '' === $value ) ? $value : self::MASK;
		}
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $sub_key => $sub_value ) {
				$clean[ $sub_key ] = self::redact_value( $sub_value, is_string( $sub_key ) ? $sub_key : '' );
			}
			return $clean;
		}
		if ( is_object( $value ) ) {
			return self::redact_value( get_object_vars( $value ), $key );
		}
		if ( is_string( $value ) ) {
			return self::redact_text( $value );
		}
		return $value;
	}

	/**
	 * Tronque un résultat d'outil.
	 *
	 * @param string $text Texte.
	 * @param int    $max  Taille maximale.
	 * @return string
	 */
	public static function truncate( $text, $max = self::MAX_RESULT_CHARS ) {
		$text = (string) $text;
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return mb_substr( $text, 0, $max ) . "\n\n[… résultat tronqué à " . $max . ' caractères. Affinez la demande, par exemple avec read_file et une plage de lignes.]';
	}

	/**
	 * Encode un résultat structuré pour l'IA (masqué, tronqué).
	 *
	 * @param mixed $data Données.
	 * @return string
	 */
	public static function to_result( $data ) {
		$json = wp_json_encode( self::redact_value( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return self::truncate( false === $json ? '' : $json );
	}

	/**
	 * Valide une requête SQL en lecture seule (une instruction SELECT, SHOW, DESCRIBE ou EXPLAIN).
	 *
	 * @param string $sql Requête.
	 * @return string|WP_Error Requête normalisée.
	 */
	public static function read_only_sql( $sql ) {
		$sql = trim( (string) $sql );
		$sql = (string) preg_replace( '#^(\s*(/\*.*?\*/|--[^\n]*\n|\#[^\n]*\n))+#s', '', $sql );
		$sql = rtrim( trim( $sql ), "; \t\n\r" );

		if ( '' === $sql ) {
			return new WP_Error( 'upsidly_agent_sql', __( 'Requête vide.', 'upsidly-agent' ) );
		}
		if ( false !== strpos( $sql, ';' ) ) {
			return new WP_Error( 'upsidly_agent_sql', __( 'Une seule instruction SQL est autorisée.', 'upsidly-agent' ) );
		}
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql ) ) {
			return new WP_Error( 'upsidly_agent_sql', __( 'Seules les requêtes en lecture (SELECT, SHOW, DESCRIBE, EXPLAIN) sont autorisées.', 'upsidly-agent' ) );
		}
		if ( preg_match( '/\b(INTO\s+(OUT|DUMP)FILE|LOAD_FILE|SLEEP\s*\(|BENCHMARK\s*\(|FOR\s+UPDATE|LOCK\s+IN\s+SHARE|GET_LOCK\s*\()/i', $sql ) ) {
			return new WP_Error( 'upsidly_agent_sql', __( 'Cette requête utilise une instruction interdite.', 'upsidly-agent' ) );
		}
		if ( preg_match( '/^SELECT\b/i', $sql ) && ! preg_match( '/\bLIMIT\s+\d+(\s*,\s*\d+)?(\s+OFFSET\s+\d+)?\s*$/i', $sql ) ) {
			$sql .= ' LIMIT 200';
		}

		return $sql;
	}
}
