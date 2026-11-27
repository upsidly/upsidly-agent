<?php
/**
 * Connecteur : liaison du site à un compte Upsidly et exécution des demandes signées d'api.upsidly.app.
 *
 * Liaison : l'administrateur clique « Relier à Upsidly » (ou suit le lien ouvert par l'assistant du dashboard,
 * ou est redirigé juste après l'activation), approuve sur le dashboard, revient avec un code à usage unique ;
 * le plugin l'échange (serveur à serveur) contre un secret partagé et une clé de site.
 *
 * Détection : admin-ajax.php?action=upsidly_agent_hello (public) indique la présence de l'extension, sa version
 * et si le site est relié, pour que l'assistant du dashboard guide la personne.
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

	const OPTION        = 'upsidly_agent_connector';
	const AJAX_ACTION   = 'upsidly_connector';
	const MAX_SKEW      = 300;
	const MAX_BODY      = 4194304;
	const STATE_PREFIX  = 'upsidly_agent_link_state_';
	const NONCE_PREFIX  = 'upsidly_agent_cn_';
	const OPS           = array( 'ping', 'tools.run', 'actions.list', 'actions.decide', 'actions.undo', 'actions.undo_all', 'actions.stop', 'content.catalog', 'content.prepare', 'content.save', 'site.disconnect' );
	const NOTICE_PREFIX = 'upsidly_agent_notice_';
	const HELLO_ACTION  = 'upsidly_agent_hello';
	const PUSH_ACTION   = 'upsidly_agent_link_push';
	const PUSH_PREFIX   = 'upsidly_agent_link_push_';
	const REDIRECT_KEY  = 'upsidly_agent_activation_redirect_';

	/**
	 * Logo Upsidly (SVG, lettres en couleur du texte).
	 */
	const LOGO = '<svg viewBox="0 0 221 66" width="67" height="20" role="img" aria-label="Upsidly" style="vertical-align:-4px;margin-right:6px"><path d="M16.7928 49.7589C6.74465 49.7589 0 43.9778 0 32.622V14.8658H6.53818V32.622C6.53818 39.9861 10.5987 43.7025 16.7928 43.7025C22.9869 43.7025 27.0474 39.9861 27.0474 32.622V14.8658H33.5856V32.622C33.5856 43.909 26.8409 49.7589 16.7928 49.7589Z" fill="currentColor"/><path d="M204.208 49.7588C194.159 49.7588 187.415 43.9777 187.415 32.6219V14.8657H193.953V32.6219C193.953 39.986 198.014 43.7024 204.208 43.7024C210.402 43.7024 214.462 39.986 214.462 32.6219V14.8657H221V32.6219C221 43.9088 214.256 49.7588 204.208 49.7588Z" fill="#2D22AD"/><path d="M204.208 66.0012C194.159 66.0012 187.415 60.2201 187.415 48.8643L193.953 48.8643C193.953 56.2283 198.014 59.9448 204.208 59.9448C210.402 59.9448 214.462 56.2284 214.462 48.8643H221C221 60.1512 214.256 66.0012 204.208 66.0012Z" fill="#2D22AD"/><path d="M39.5658 63.3857V32.1402C39.5658 21.0598 47.1363 13.971 57.6662 13.971C68.4714 13.971 76.042 21.4039 76.042 32.0714C76.042 43.3583 67.6456 49.7588 58.7674 49.7588C53.2616 49.7588 48.5816 47.2812 46.104 42.8766V63.3857H39.5658ZM57.7351 43.7024C64.755 43.7024 69.435 38.7472 69.435 31.8649C69.435 24.9826 64.755 20.0274 57.7351 20.0274C50.7839 20.0274 46.104 24.9826 46.104 31.8649C46.104 38.7472 50.7839 43.7024 57.7351 43.7024Z" fill="currentColor"/><path d="M94.3075 49.7588C85.7734 49.7588 80.4052 45.2165 80.1987 39.0225H86.8058C86.9434 41.8442 89.5587 43.8401 94.3075 43.8401C99.2627 43.8401 101.809 41.7066 101.809 39.0225C101.809 35.7878 98.2304 35.3749 93.9633 34.8243C88.0446 34.0672 80.887 32.9661 80.887 25.1891C80.887 18.5133 86.1175 13.971 94.2386 13.971C102.36 13.971 107.384 18.5821 107.521 24.4321H101.052C100.846 21.8168 98.4368 19.8898 94.3075 19.8898C89.9716 19.8898 87.494 21.9544 87.494 24.6385C87.494 27.8732 91.0728 28.2173 95.271 28.7679C101.19 29.5249 108.416 30.6261 108.416 38.4031C108.416 45.2853 102.841 49.7588 94.3075 49.7588Z" fill="currentColor"/><path d="M118.373 9.49754C115.758 9.49754 113.831 7.50168 113.831 4.88641C113.831 2.27115 115.758 0.344113 118.373 0.344113C120.989 0.344113 122.916 2.27115 122.916 4.88641C122.916 7.50168 120.989 9.49754 118.373 9.49754ZM115.139 48.8641V14.8657H121.677V48.8641H115.139Z" fill="currentColor"/><path d="M146.559 49.7588C135.754 49.7588 128.183 42.326 128.183 31.6585C128.183 20.3715 136.58 13.971 145.458 13.971C150.964 13.971 155.644 16.4486 158.121 20.8533V0H164.66V31.5896C164.66 42.6701 157.089 49.7588 146.559 49.7588ZM146.49 43.7024C153.441 43.7024 158.121 38.7472 158.121 31.8649C158.121 24.9826 153.441 20.0274 146.49 20.0274C139.47 20.0274 134.79 24.9826 134.79 31.8649C134.79 38.7472 139.47 43.7024 146.49 43.7024Z" fill="currentColor"/><path d="M172.67 48.8641V0H179.209V48.8641H172.67Z" fill="currentColor"/></svg>';

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
	 * Branche les hooks. Appelé au chargement du plugin ; la vérification de signature attend plugins_loaded.
	 */
	public static function init() {
		add_action( 'admin_post_upsidly_agent_link', array( __CLASS__, 'start_link' ) );
		add_action( 'admin_post_upsidly_agent_link_return', array( __CLASS__, 'finish_link' ) );
		add_action( 'admin_post_upsidly_agent_unlink', array( __CLASS__, 'unlink' ) );
		add_action( 'admin_post_upsidly_agent_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_nopriv_upsidly_agent_connect', 'auth_redirect' );
		add_action( 'wp_ajax_' . self::HELLO_ACTION, array( __CLASS__, 'hello' ) );
		add_action( 'wp_ajax_nopriv_' . self::HELLO_ACTION, array( __CLASS__, 'hello' ) );
		add_action( 'wp_ajax_' . self::PUSH_ACTION, array( __CLASS__, 'link_push' ) );
		add_action( 'wp_ajax_nopriv_' . self::PUSH_ACTION, array( __CLASS__, 'link_push' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_after_activation' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( UPSIDLY_AGENT_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );

		if ( self::is_connector_request() ) {
			// Après le chargement des extensions : wp_salt(), get_userdata() (pluggable.php) sont alors définies,
			// et l'utilisateur courant n'est pas encore déterminé.
			add_action( 'plugins_loaded', array( __CLASS__, 'verify_request' ), 0 );
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
		self::go_to_dashboard();
	}

	/**
	 * Lien « Relier » ouvert depuis l'assistant du dashboard ou après l'activation (connexion WordPress demandée
	 * si besoin). Sans jeton : il ne fait que lancer la liaison, que la personne approuve ensuite sur le dashboard
	 * avec son propre compte Upsidly. Un site déjà relié n'est jamais relié de nouveau par ce lien.
	 */
	public static function connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Seul un administrateur du site peut le relier à Upsidly.', 'upsidly-agent' ), 403 );
		}
		if ( self::is_linked() ) {
			self::notice( 'success', __( 'Ce site est déjà relié à Upsidly. Pour le relier à un autre compte, déliez-le d\'abord depuis la page Extensions.', 'upsidly-agent' ) );
		}
		self::go_to_dashboard();
	}

	/**
	 * Juste après l'activation (par un administrateur, site non relié) : la liaison démarre seule.
	 */
	public static function redirect_after_activation() {
		$key = self::REDIRECT_KEY . get_current_user_id();
		if ( ! get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture : activation groupée.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) || self::is_linked() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin-post.php?action=upsidly_agent_connect' ) );
		exit;
	}

	/**
	 * Mémorise la redirection vers la liaison (appelé à l'activation de l'extension).
	 */
	public static function schedule_activation_redirect() {
		if ( get_current_user_id() > 0 && ! self::is_linked() ) {
			set_transient( self::REDIRECT_KEY . get_current_user_id(), 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Détection publique pour l'assistant du dashboard : extension présente, version, site relié ou non.
	 */
	public static function hello() {
		nocache_headers();
		wp_send_json(
			array(
				'plugin'  => 'upsidly-agent',
				'version' => UPSIDLY_AGENT_VERSION,
				'linked'  => self::is_linked(),
			)
		);
	}

	/**
	 * Liaison validée dans l'onglet du dashboard : Upsidly transmet le code (serveur à serveur), le site l'échange
	 * comme au retour du dashboard. Seul l'état à usage unique, connu de la personne qui a lancé la liaison depuis
	 * WordPress, permet cet envoi ; la liaison est faite au nom de l'administrateur qui l'a lancée.
	 */
	public static function link_push() {
		$body  = json_decode( (string) file_get_contents( 'php://input', false, null, 0, 4096 ), true );
		$code  = is_array( $body ) && is_string( $body['code'] ?? null ) ? $body['code'] : '';
		$state = is_array( $body ) && is_string( $body['state'] ?? null ) ? $body['state'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $code ) || ! preg_match( '/^[A-Za-z0-9]{20,128}$/', $state ) ) {
			wp_send_json( array( 'error' => array( 'code' => 'bad_request', 'message' => 'Demande de liaison incomplète.' ) ), 400 );
		}
		$key     = self::PUSH_PREFIX . hash( 'sha256', $state );
		$user_id = (int) get_transient( $key );
		delete_transient( $key );
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			wp_send_json( array( 'error' => array( 'code' => 'link_expired', 'message' => 'Liaison expirée : relancez-la depuis WordPress.' ) ), 403 );
		}
		delete_transient( self::STATE_PREFIX . $user_id );
		wp_set_current_user( $user_id );
		$result = self::claim( $code, $state );
		if ( is_wp_error( $result ) ) {
			wp_send_json( array( 'error' => array( 'code' => 'claim_failed', 'message' => $result->get_error_message() ) ), 409 );
		}
		wp_send_json( array( 'linked' => true ) );
	}

	/**
	 * Redirection vers le dashboard avec un état anti-falsification (valable 20 minutes).
	 */
	private static function go_to_dashboard() {
		$state = wp_generate_password( 40, false, false );
		set_transient( self::STATE_PREFIX . get_current_user_id(), $state, 20 * MINUTE_IN_SECONDS );
		set_transient( self::PUSH_PREFIX . hash( 'sha256', $state ), get_current_user_id(), 20 * MINUTE_IN_SECONDS );

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
		if ( '' !== $state ) {
			delete_transient( self::PUSH_PREFIX . hash( 'sha256', $state ) );
		}

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
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) || ! is_string( $body['secret'] ?? null ) || strlen( $body['secret'] ) < 32 ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'Liaison refusée par Upsidly.', 'upsidly-agent' );
			return new WP_Error( 'upsidly_agent_link', $message );
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
		return true;
	}

	/**
	 * Délier le site (le secret est oublié).
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
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	// Interface minimale : liens de l'extension et notices (aucune page de réglages).

	/**
	 * Formulaire « Relier à Upsidly » ou « Délier » (un bouton, POST + nonce).
	 *
	 * @param bool $primary Bouton principal.
	 * @return string HTML.
	 */
	private static function link_form( $primary = true ) {
		$linked = self::is_linked();
		$action = $linked ? 'upsidly_agent_unlink' : 'upsidly_agent_link';
		$label  = $linked ? __( 'Délier', 'upsidly-agent' ) : __( 'Relier à Upsidly', 'upsidly-agent' );
		$class  = $linked ? 'button-link button-link-delete' : ( $primary ? 'button button-primary' : 'button-link' );
		$extra  = $linked ? ' onsubmit="return confirm(\'' . esc_js( __( 'Délier ce site ? Upsidly ne pourra plus agir dessus depuis le tableau de bord.', 'upsidly-agent' ) ) . '\');"' : '';

		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . $extra . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Liens de l'extension (page Extensions) : tableau de bord, relier ou délier.
	 *
	 * @param array $links Liens.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		if ( self::is_linked() ) {
			array_unshift( $links, '<a href="' . esc_url( self::dashboard_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Tableau de bord', 'upsidly-agent' ) . '</a>' );
		}
		$links[] = self::link_form( false );
		return $links;
	}

	/**
	 * État de la liaison sous la description de l'extension.
	 *
	 * @param array  $meta Métadonnées.
	 * @param string $file Extension.
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( UPSIDLY_AGENT_FILE ) !== $file || ! current_user_can( 'manage_options' ) ) {
			return $meta;
		}
		$link = self::get();
		if ( null === $link ) {
			$meta[] = '<strong>' . esc_html__( 'Site non relié à Upsidly', 'upsidly-agent' ) . '</strong>';
		} else {
			$user   = get_userdata( $link['user_id'] );
			$meta[] = esc_html(
				sprintf(
					/* translators: 1: date, 2: identifiant de l'administrateur. */
					__( 'Relié le %1$s par %2$s', 'upsidly-agent' ),
					wp_date( get_option( 'date_format' ), $link['linked_at'] ),
					$user ? $user->user_login : '?'
				)
			);
		}
		return $meta;
	}

	/**
	 * Notices : résultat d'une liaison ; invitation à relier le site (pages Extensions et Tableau de bord).
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( self::NOTICE_PREFIX . get_current_user_id() );
		if ( is_array( $notice ) && isset( $notice['message'] ) ) {
			delete_transient( self::NOTICE_PREFIX . get_current_user_id() );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'success' === $notice['type'] ? 'success' : 'error',
				esc_html( $notice['message'] )
			);
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::is_linked() || ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- logo statique et formulaire échappé dans link_form().
		echo '<div class="notice notice-info"><p>' . self::LOGO . '<strong>' . esc_html__( 'Upsidly Agent', 'upsidly-agent' ) . '</strong> — '
			. esc_html__( 'Reliez ce site à votre compte Upsidly pour piloter l\'agent IA et la rédaction depuis votre tableau de bord upsidly.app. Aucune clé à copier.', 'upsidly-agent' )
			. '</p><p>' . self::link_form() . '</p></div>';
		// phpcs:enable
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
	public static function verify_request() {
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
			case 'site.disconnect':
				// Site retiré du compte depuis le tableau de bord : la liaison est oubliée, le site peut être relié à nouveau.
				delete_option( self::OPTION );
				return array( 'disconnected' => true );

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
				Upsidly_Agent_Actions::register_actions( $id, $uses );
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
				return Upsidly_Agent_Actions::decide( absint( $payload['action_id'] ?? 0 ), $decision, (string) ( $payload['comment'] ?? '' ) );

			case 'actions.undo':
				return Upsidly_Agent_Actions::undo( absint( $payload['action_id'] ?? 0 ) );

			case 'content.catalog':
				return Upsidly_Agent_Writer::catalog();

			case 'content.prepare':
				$prepared = Upsidly_Agent_Writer::prepare( (array) ( $payload['inputs'] ?? array() ), (array) ( $payload['terms'] ?? array() ), (string) ( $payload['status'] ?? 'draft' ) );
				return is_wp_error( $prepared ) ? $prepared : $prepared + array( 'context' => Upsidly_Agent_Writer::context() );

			case 'content.save':
				return Upsidly_Agent_Writer::save( (array) ( $payload['inputs'] ?? array() ), (array) ( $payload['terms'] ?? array() ), (string) ( $payload['status'] ?? 'draft' ), (array) ( $payload['result'] ?? array() ) );

			case 'actions.stop':
				$id = self::conversation_id( $payload );
				return is_wp_error( $id ) ? $id : Upsidly_Agent_Actions::stop( $id, (string) ( $payload['reason'] ?? '' ) );

			case 'actions.undo_all':
				$id = self::conversation_id( $payload );
				return is_wp_error( $id ) ? $id : Upsidly_Agent_Actions::undo_all( $id );
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
		$pending = Upsidly_Agent_Actions::has_pending( $id );
		return array(
			'actions' => Upsidly_Agent_Actions::actions_payload( $id ),
			'pending' => $pending,
			'results' => $pending ? array() : Upsidly_Agent_Actions::results_for( $id, self::as_tool_uses( $uses ) ),
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
