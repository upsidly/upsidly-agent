<?php
/**
 * Connecteur : liaison du site à un compte Upsidly et exécution des demandes signées d'api.upsidly.app.
 *
 * Liaison : l'administrateur clique « Relier à Upsidly », approuve sur le dashboard, revient avec un code
 * à usage unique ; le plugin l'échange (serveur à serveur) contre un secret partagé et une clé de site.
 *
 * Demandes : POST admin-ajax.php?action=upsidly_connector, corps JSON { op, ... }, signé HMAC-SHA256
 * (horodatage + nonce, rejeu impossible). Exécutées au nom de l'administrateur qui a relié le site,
 * dans le contexte de l'administration (mises à jour des extensions premium visibles).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connecteur Upsidly.
 */
class Upsidly_Agent_Connector {

	const OPTION       = 'upsidly_agent_connector';
	const AJAX_ACTION  = 'upsidly_connector';
	const MAX_SKEW     = 300;
	const MAX_BODY     = 4194304;
	const STATE_PREFIX = 'upsidly_agent_link_state_';
	const NONCE_PREFIX = 'upsidly_agent_cn_';
	const OPS          = array( 'ping', 'tools.run', 'actions.list', 'actions.decide', 'actions.undo', 'actions.undo_all' );

	/**
	 * Administrateur au nom duquel s'exécute une demande signée valide (0 sinon).
	 *
	 * @var int
	 */
	private static $signed_user = 0;

	/**
	 * Corps décodé de la demande signée.
	 *
	 * @var array
	 */
	private static $payload = array();

	/**
	 * Erreur de vérification (code, message, statut HTTP).
	 *
	 * @var array{0: string, 1: string, 2: int}|null
	 */
	private static $error = null;

	/**
	 * Branche les hooks. Appelé au chargement du plugin, avant la détermination de l'utilisateur courant.
	 */
	public static function init() {
		add_action( 'admin_post_upsidly_agent_link', array( __CLASS__, 'start_link' ) );
		add_action( 'admin_post_upsidly_agent_link_return', array( __CLASS__, 'finish_link' ) );
		add_action( 'admin_post_upsidly_agent_unlink', array( __CLASS__, 'unlink' ) );

		if ( self::is_connector_request() ) {
			self::verify_request();
			add_filter( 'determine_current_user', array( __CLASS__, 'signed_user' ), 100 );
			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle' ) );
			add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle' ) );
		}
	}

	/**
	 * Adresse du dashboard Upsidly.
	 *
	 * @return string
	 */
	public static function dashboard_url() {
		return rtrim( defined( 'UPSIDLY_AGENT_DASHBOARD_URL' ) ? (string) UPSIDLY_AGENT_DASHBOARD_URL : 'https://www.upsidly.app/fr/dashboard', '/' );
	}

	/**
	 * État de la liaison.
	 *
	 * @return array{site_id: int, user_id: int, linked_at: int, secret: string}|null
	 */
	public static function get() {
		$value = get_option( self::OPTION, '' );
		if ( ! is_array( $value ) || empty( $value['secret'] ) || empty( $value['user_id'] ) ) {
			return null;
		}
		$secret = self::decrypt( (string) $value['secret'] );
		if ( '' === $secret ) {
			return null;
		}
		return array(
			'site_id'   => (int) ( $value['site_id'] ?? 0 ),
			'user_id'   => (int) $value['user_id'],
			'linked_at' => (int) ( $value['linked_at'] ?? 0 ),
			'secret'    => $secret,
		);
	}

	/**
	 * Le site est-il relié à un compte Upsidly ?
	 *
	 * @return bool
	 */
	public static function is_linked() {
		return null !== self::get();
	}

	// Liaison.

	/**
	 * « Relier à Upsidly » : redirection vers le dashboard avec un état anti-falsification.
	 */
	public static function start_link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les droits suffisants.', 'upsidly-agent' ), 403 );
		}
		check_admin_referer( 'upsidly_agent_link' );

		$state = wp_generate_password( 40, false, false );
		set_transient( self::STATE_PREFIX . get_current_user_id(), $state, 20 * MINUTE_IN_SECONDS );

		$url = self::dashboard_url() . '/relier?' . http_build_query(
			array(
				'site'   => home_url( '/' ),
				'retour' => admin_url( 'admin-post.php?action=upsidly_agent_link_return' ),
				'etat'   => $state,
			)
		);
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirection voulue vers le dashboard Upsidly.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Retour du dashboard : vérifie l'état puis échange le code contre le secret (serveur à serveur).
	 */
	public static function finish_link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les droits suffisants.', 'upsidly-agent' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- protégé par l'état à usage unique ci-dessous.
		$code  = sanitize_text_field( wp_unslash( (string) ( $_GET['upsidly_code'] ?? '' ) ) );
		$state = sanitize_text_field( wp_unslash( (string) ( $_GET['etat'] ?? '' ) ) );
		// phpcs:enable

		$expected = (string) get_transient( self::STATE_PREFIX . get_current_user_id() );
		delete_transient( self::STATE_PREFIX . get_current_user_id() );

		if ( '' === $expected || '' === $state || ! hash_equals( $expected, $state ) || ! preg_match( '/^[a-f0-9]{64}$/', $code ) ) {
			self::notice( 'error', __( 'Liaison interrompue ou expirée : recommencez depuis « Relier à Upsidly ».', 'upsidly-agent' ) );
		}

		$result = self::claim( $code, $state );
		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );
		}
		self::notice( 'success', __( 'Site relié à votre compte Upsidly. Vous pouvez maintenant tout piloter depuis votre tableau de bord.', 'upsidly-agent' ) );
	}

	/**
	 * Échange le code de liaison contre le secret du connecteur et la clé du site.
	 *
	 * @param string $code  Code à usage unique.
	 * @param string $state État de la liaison.
	 * @return true|WP_Error
	 */
	public static function claim( $code, $state ) {
		$response = wp_remote_post(
			rtrim( UPSIDLY_AGENT_API_URL, '/' ) . '/v1/connector/claim',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'code'           => $code,
						'state'          => $state,
						'site_url'       => home_url( '/' ),
						'connector_url'  => admin_url( 'admin-ajax.php' ),
						'plugin_version' => UPSIDLY_AGENT_VERSION,
						'environment'    => Upsidly_Agent_Environment::data(),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'upsidly_agent_link', __( 'Impossible de joindre Upsidly. Réessayez dans un instant.', 'upsidly-agent' ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) || empty( $body['secret'] ) || empty( $body['key'] ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'Liaison refusée par Upsidly.', 'upsidly-agent' );
			return new WP_Error( 'upsidly_agent_link', $message );
		}
		if ( ! preg_match( Upsidly_Agent_Settings::KEY_PATTERN, (string) $body['key'] ) ) {
			return new WP_Error( 'upsidly_agent_link', __( 'Réponse d\'Upsidly inattendue.', 'upsidly-agent' ) );
		}

		update_option(
			self::OPTION,
			array(
				'site_id'   => (int) ( $body['site_id'] ?? 0 ),
				'user_id'   => get_current_user_id(),
				'linked_at' => time(),
				'secret'    => self::encrypt( (string) $body['secret'] ),
			),
			false
		);
		if ( ! Upsidly_Agent_Settings::key_is_constant() ) {
			update_option( Upsidly_Agent_Settings::OPTION_KEY, (string) $body['key'], false );
		}
		delete_transient( Upsidly_Agent_Settings::STATUS_TRANSIENT );
		Upsidly_Agent_Settings::connect_now();

		return true;
	}

	/**
	 * Délier le site (le secret est oublié ; la clé reste).
	 */
	public static function unlink() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les droits suffisants.', 'upsidly-agent' ), 403 );
		}
		check_admin_referer( 'upsidly_agent_unlink' );
		delete_option( self::OPTION );
		self::notice( 'success', __( 'Site délié : Upsidly ne peut plus agir sur ce site depuis le tableau de bord.', 'upsidly-agent' ) );
	}

	/**
	 * Message sur la page Réglages, puis redirection.
	 *
	 * @param string $type    success | error.
	 * @param string $message Message.
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'upsidly_agent_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( Upsidly_Agent_Settings::page_url() );
		exit;
	}

	// Demandes signées.

	/**
	 * La requête courante vise-t-elle le connecteur ?
	 *
	 * @return bool
	 */
	private static function is_connector_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authentifiée par signature.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		return wp_doing_ajax() && isset( $_GET['action'] ) && self::AJAX_ACTION === $_GET['action'] && 'post' === $method; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authentifiée par signature.
	}

	/**
	 * Vérifie la signature et prépare l'utilisateur d'exécution.
	 */
	private static function verify_request() {
		$link = self::get();
		if ( null === $link ) {
			self::$error = array( 'not_linked', 'Ce site n\'est pas relié à Upsidly.', 409 );
			return;
		}

		$body = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 );
		if ( strlen( $body ) > self::MAX_BODY ) {
			self::$error = array( 'too_large', 'Demande trop volumineuse.', 413 );
			return;
		}

		$error = self::check_signature( $link['secret'], $body, self::header( 'X-Upsidly-Timestamp' ), self::header( 'X-Upsidly-Nonce' ), self::header( 'X-Upsidly-Signature' ), time() );
		if ( null !== $error ) {
			self::$error = $error;
			return;
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || ! in_array( $payload['op'] ?? '', self::OPS, true ) ) {
			self::$error = array( 'bad_request', 'Opération inconnue.', 400 );
			return;
		}

		$user = get_userdata( $link['user_id'] );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			self::$error = array( 'user_missing', 'L\'administrateur qui a relié le site n\'existe plus ou n\'est plus administrateur : reliez de nouveau le site.', 409 );
			return;
		}

		self::$payload     = $payload;
		self::$signed_user = (int) $user->ID;
	}

	/**
	 * Contrôle d'une signature (null si valide, sinon [code, message, statut]).
	 *
	 * Signature : hex(HMAC-SHA256(secret, "POST\n{timestamp}\n{nonce}\n" . sha256(corps))).
	 *
	 * @param string      $secret    Secret partagé.
	 * @param string      $body      Corps brut.
	 * @param string|null $timestamp Horodatage Unix.
	 * @param string|null $nonce     Nonce (16 à 64 caractères).
	 * @param string|null $signature Signature hexadécimale.
	 * @param int         $now       Heure courante.
	 * @return array{0: string, 1: string, 2: int}|null
	 */
	public static function check_signature( $secret, $body, $timestamp, $nonce, $signature, $now ) {
		if ( null === $timestamp || null === $nonce || null === $signature || ! ctype_digit( $timestamp ) || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return array( 'bad_signature', 'Signature absente.', 401 );
		}
		if ( abs( $now - (int) $timestamp ) > self::MAX_SKEW ) {
			return array( 'clock_skew', 'Demande expirée (horloge du serveur décalée ?).', 401 );
		}
		$expected = self::sign( $secret, $body, $timestamp, $nonce );
		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return array( 'bad_signature', 'Signature invalide.', 401 );
		}
		if ( false !== get_transient( self::NONCE_PREFIX . $nonce ) ) {
			return array( 'replay', 'Demande déjà reçue.', 401 );
		}
		set_transient( self::NONCE_PREFIX . $nonce, 1, 2 * self::MAX_SKEW );
		return null;
	}

	/**
	 * Signature d'un corps.
	 *
	 * @param string $secret    Secret.
	 * @param string $body      Corps.
	 * @param string $timestamp Horodatage.
	 * @param string $nonce     Nonce.
	 * @return string
	 */
	public static function sign( $secret, $body, $timestamp, $nonce ) {
		return hash_hmac( 'sha256', "POST\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body ), $secret );
	}

	/**
	 * Utilisateur courant pour une demande signée valide.
	 *
	 * @param int|false $user_id Utilisateur déterminé par WordPress.
	 * @return int|false
	 */
	public static function signed_user( $user_id ) {
		return self::$signed_user > 0 ? self::$signed_user : $user_id;
	}

	/**
	 * Traite la demande (hook admin-ajax).
	 */
	public static function handle() {
		if ( null !== self::$error || 0 === self::$signed_user || get_current_user_id() !== self::$signed_user ) {
			$error = self::$error ?? array( 'forbidden', 'Accès refusé.', 403 );
			self::respond(
				array(
					'error' => array(
						'code'    => $error[0],
						'message' => $error[1],
					),
				),
				$error[2]
			);
		}
		$result = self::dispatch( self::$payload );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			self::respond(
				array(
					'error' => array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					),
				),
				(int) ( $data['status'] ?? 400 )
			);
		}
		self::respond( array( 'data' => $result ), 200 );
	}

	/**
	 * Exécute une opération.
	 *
	 * @param array $payload Corps décodé (op, paramètres).
	 * @return array|WP_Error
	 */
	public static function dispatch( array $payload ) {
		if ( isset( $payload['level'] ) && is_string( $payload['level'] ) ) {
			Upsidly_Agent_Tool_Registry::set_level( $payload['level'] );
		}

		switch ( (string) $payload['op'] ) {
			case 'ping':
				return array(
					'plugin_version' => UPSIDLY_AGENT_VERSION,
					'environment'    => Upsidly_Agent_Environment::data(),
					'agent'          => Upsidly_Agent_Environment::agent_data(),
					'user'           => wp_get_current_user()->user_login,
					'time'           => time(),
				);

			case 'tools.run':
				$id = self::conversation_id( $payload );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$uses = array();
				foreach ( (array) ( $payload['uses'] ?? array() ) as $use ) {
					if ( is_array( $use ) && is_string( $use['id'] ?? null ) && is_string( $use['name'] ?? null ) ) {
						$uses[] = array(
							'id'    => $use['id'],
							'name'  => $use['name'],
							'input' => is_array( $use['input'] ?? null ) ? $use['input'] : array(),
						);
					}
				}
				Upsidly_Agent_Agent_Store::ensure( $id, get_current_user_id(), (string) ( $payload['title'] ?? '' ) );
				Upsidly_Agent_Agent_Loop::register_actions( $id, $uses );
				return self::state( $id, $uses );

			case 'actions.list':
				$id = self::conversation_id( $payload );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return self::state( $id, (array) ( $payload['uses'] ?? array() ) );

			case 'actions.decide':
				$decision = (string) ( $payload['decision'] ?? '' );
				if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
					return new WP_Error( 'bad_request', 'Décision invalide.', array( 'status' => 400 ) );
				}
				return Upsidly_Agent_Agent_Loop::decide( absint( $payload['action_id'] ?? 0 ), $decision, (string) ( $payload['comment'] ?? '' ) );

			case 'actions.undo':
				return Upsidly_Agent_Agent_Loop::undo( absint( $payload['action_id'] ?? 0 ) );

			case 'actions.undo_all':
				$id = self::conversation_id( $payload );
				return is_wp_error( $id ) ? $id : Upsidly_Agent_Agent_Loop::undo_all( $id );
		}

		return new WP_Error( 'bad_request', 'Opération inconnue.', array( 'status' => 400 ) );
	}

	/**
	 * Actions d'une conversation et résultats prêts pour l'IA.
	 *
	 * @param string $id   Conversation.
	 * @param array  $uses Demandes d'outils de la dernière étape.
	 * @return array{actions: array, pending: bool, results: array}
	 */
	private static function state( $id, array $uses ) {
		$pending = Upsidly_Agent_Agent_Loop::has_pending( $id );
		return array(
			'actions' => Upsidly_Agent_Agent_Loop::actions_payload( $id ),
			'pending' => $pending,
			'results' => $pending ? array() : Upsidly_Agent_Agent_Loop::results_for( $id, self::as_tool_uses( $uses ) ),
		);
	}

	/**
	 * Demandes au format bloc tool_use.
	 *
	 * @param array $uses Demandes.
	 * @return array
	 */
	private static function as_tool_uses( array $uses ) {
		$blocks = array();
		foreach ( $uses as $use ) {
			if ( is_array( $use ) && isset( $use['id'], $use['name'] ) ) {
				$blocks[] = array(
					'type'  => 'tool_use',
					'id'    => (string) $use['id'],
					'name'  => (string) $use['name'],
					'input' => is_array( $use['input'] ?? null ) ? $use['input'] : array(),
				);
			}
		}
		return $blocks;
	}

	/**
	 * Identifiant de conversation (26 caractères [a-z0-9], comme côté Upsidly).
	 *
	 * @param array $payload Corps.
	 * @return string|WP_Error
	 */
	private static function conversation_id( array $payload ) {
		$id = strtolower( (string) ( $payload['conversation_id'] ?? '' ) );
		return preg_match( '/^[a-z0-9]{26}$/', $id ) ? $id : new WP_Error( 'bad_request', 'Conversation invalide.', array( 'status' => 400 ) );
	}

	/**
	 * Réponse JSON puis arrêt.
	 *
	 * @param array $data   Corps.
	 * @param int   $status Statut HTTP.
	 */
	private static function respond( array $data, $status ) {
		nocache_headers();
		wp_send_json( $data, $status );
	}

	/**
	 * En-tête HTTP de la requête.
	 *
	 * @param string $name Nom.
	 * @return string|null
	 */
	private static function header( $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] ) ? trim( (string) wp_unslash( $_SERVER[ $key ] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé à une signature.
	}

	// Chiffrement du secret au repos (clés de WordPress).

	/**
	 * Clé de chiffrement dérivée des clés secrètes de WordPress.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', 'upsidly-connector|' . wp_salt( 'auth' ), true );
	}

	/**
	 * Chiffre une valeur (libsodium si disponible).
	 *
	 * @param string $value Valeur.
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stockage binaire.
		}
		return 'p0:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- repli sans libsodium.
	}

	/**
	 * Déchiffre une valeur ('' si illisible, par exemple après changement des clés de WordPress).
	 *
	 * @param string $stored Valeur stockée.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( str_starts_with( $stored, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- stockage binaire.
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
			return false === $plain ? '' : $plain;
		}
		if ( str_starts_with( $stored, 'p0:' ) ) {
			$plain = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- repli sans libsodium.
			return false === $plain ? '' : $plain;
		}
		return '';
	}
}
