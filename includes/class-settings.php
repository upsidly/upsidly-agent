<?php
/**
 * Options, page Réglages et gestion de la clé de site.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Réglages du plugin.
 */
class Upsidly_Agent_Settings {

	const OPTION_KEY         = 'upsidly_agent_key';
	const OPTION_DESCRIPTION = 'upsidly_agent_site_description';
	const OPTION_VOICE       = 'upsidly_agent_brand_voice';
	const OPTION_CONNECTION  = 'upsidly_agent_connection';
	const OPTION_AGENT       = 'upsidly_agent_agent_enabled';
	const STATUS_TRANSIENT   = 'upsidly_agent_status';
	const GROUP              = 'upsidly_agent';
	const PAGE               = 'upsidly-agent-settings';
	const KEY_PATTERN        = '/^upa_[a-f0-9]{40}$/';
	const VOICE_MAX          = 2000;
	const DESCRIPTION_MAX    = 500;

	/**
	 * Évite de traiter deux fois la clé lors d'un même enregistrement.
	 *
	 * @var bool
	 */
	private static $key_processed = false;

	/**
	 * Branche les hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_upsidly_agent_test', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Crée les options (autoload désactivé) si elles n'existent pas.
	 */
	public static function add_options() {
		foreach ( array( self::OPTION_KEY, self::OPTION_DESCRIPTION, self::OPTION_VOICE, self::OPTION_CONNECTION, self::OPTION_AGENT ) as $option ) {
			if ( false === get_option( $option, false ) ) {
				add_option( $option, '', '', false );
			}
		}
	}

	/**
	 * Clé de site : la constante UPSIDLY_AGENT_KEY prend le pas sur l'option.
	 *
	 * @return string
	 */
	public static function get_key() {
		if ( self::key_is_constant() ) {
			return trim( (string) UPSIDLY_AGENT_KEY );
		}
		return trim( (string) get_option( self::OPTION_KEY, '' ) );
	}

	/**
	 * La clé est-elle imposée par wp-config.php ?
	 *
	 * @return bool
	 */
	public static function key_is_constant() {
		return defined( 'UPSIDLY_AGENT_KEY' ) && is_string( UPSIDLY_AGENT_KEY ) && '' !== trim( UPSIDLY_AGENT_KEY );
	}

	/**
	 * Indice masqué de la clé : « upa_3f9a…c21b ».
	 *
	 * @param string $key Clé.
	 * @return string
	 */
	public static function key_hint( $key ) {
		if ( strlen( $key ) < 12 ) {
			return '';
		}
		return substr( $key, 0, 8 ) . '…' . substr( $key, -4 );
	}

	/**
	 * Activité du site (contexte des prompts).
	 *
	 * @return string
	 */
	public static function get_site_description() {
		return (string) get_option( self::OPTION_DESCRIPTION, '' );
	}

	/**
	 * Ton de marque (contexte des prompts).
	 *
	 * @return string
	 */
	public static function get_brand_voice() {
		return (string) get_option( self::OPTION_VOICE, '' );
	}

	/**
	 * Agent activé sur ce site (réglage, désactivé par défaut) ?
	 *
	 * @return bool
	 */
	public static function agent_enabled() {
		return '1' === (string) get_option( self::OPTION_AGENT, '' );
	}

	/**
	 * Dernier état de connexion connu.
	 *
	 * @return array{state: string, message: string, date: int}|null
	 */
	public static function get_connection() {
		$value = get_option( self::OPTION_CONNECTION, '' );
		return is_array( $value ) && isset( $value['state'] ) ? $value : null;
	}

	/**
	 * Enregistre les réglages (Settings API).
	 */
	public static function register() {
		self::add_options();

		register_setting(
			self::GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_key_option' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION_DESCRIPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_description' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION_AGENT,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ) {
					return empty( $value ) ? '' : '1';
				},
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION_VOICE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_voice' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Nettoie l'activité du site.
	 *
	 * @param mixed $value Valeur soumise.
	 * @return string
	 */
	public static function sanitize_description( $value ) {
		delete_transient( self::STATUS_TRANSIENT );
		return mb_substr( sanitize_text_field( (string) $value ), 0, self::DESCRIPTION_MAX );
	}

	/**
	 * Nettoie le ton de marque.
	 *
	 * @param mixed $value Valeur soumise.
	 * @return string
	 */
	public static function sanitize_voice( $value ) {
		delete_transient( self::STATUS_TRANSIENT );
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, self::VOICE_MAX );
	}

	/**
	 * Traite la clé soumise : retrait (disconnect), conservation si vide, validation, puis connect.
	 *
	 * @param mixed $value Valeur soumise.
	 * @return string Clé à enregistrer.
	 */
	public static function sanitize_key_option( $value ) {
		$old = (string) get_option( self::OPTION_KEY, '' );

		if ( self::$key_processed || self::key_is_constant() ) {
			return self::$key_processed ? (string) $value : $old;
		}
		self::$key_processed = true;
		delete_transient( self::STATUS_TRANSIENT );

		// Nonce vérifié par options.php (settings_fields).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$remove = ! empty( $_POST['upsidly_agent_remove_key'] );

		if ( $remove ) {
			if ( '' !== $old ) {
				( new Upsidly_Agent_Client() )->disconnect( $old );
			}
			delete_option( self::OPTION_CONNECTION );
			add_option( self::OPTION_CONNECTION, '', '', false );
			add_settings_error( self::OPTION_KEY, 'upsidly_agent_key_removed', __( 'La clé a été retirée.', 'upsidly-agent' ), 'success' );
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return $old;
		}

		if ( ! preg_match( self::KEY_PATTERN, $value ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'upsidly_agent_key_format',
				__( 'La clé doit commencer par « upa_ » suivi de 40 caractères (chiffres et lettres de a à f). Elle n\'a pas été enregistrée.', 'upsidly-agent' )
			);
			return $old;
		}

		// La clé est enregistrée même si la connexion échoue.
		$result = self::connect_now( $value );
		self::add_connection_notice( $result );

		return $value;
	}

	/**
	 * Appelle /v1/connect et mémorise l'état de connexion.
	 *
	 * @param string|null $key Clé à utiliser (par défaut : clé enregistrée).
	 * @return array|WP_Error
	 */
	public static function connect_now( $key = null ) {
		$result = ( new Upsidly_Agent_Client() )->connect( Upsidly_Agent_Environment::data(), $key );
		self::record_connection( $result );
		delete_transient( self::STATUS_TRANSIENT );
		return $result;
	}

	/**
	 * Mémorise le résultat d'une connexion.
	 *
	 * @param array|WP_Error $result Résultat de connect.
	 */
	public static function record_connection( $result ) {
		$connection = is_wp_error( $result )
			? array(
				'state'   => 'error',
				'message' => $result->get_error_message(),
				'date'    => time(),
			)
			: array(
				'state'   => 'connected',
				'message' => self::connected_message( $result ),
				'date'    => time(),
			);

		if ( false === get_option( self::OPTION_CONNECTION, false ) ) {
			add_option( self::OPTION_CONNECTION, $connection, '', false );
		} else {
			update_option( self::OPTION_CONNECTION, $connection );
		}
	}

	/**
	 * « Connecté — formule {plan}, {remaining} crédits restants ce mois-ci ».
	 *
	 * @param array $status Corps renvoyé par /v1/connect ou /v1/status.
	 * @return string
	 */
	public static function connected_message( $status ) {
		if ( 'once' === ( $status['credits']['period'] ?? '' ) ) {
			return sprintf(
				/* translators: 1: formule, 2: nombre de crédits restants. */
				__( 'Connecté — formule %1$s, %2$s crédits de test restants', 'upsidly-agent' ),
				isset( $status['site']['plan'] ) ? (string) $status['site']['plan'] : '?',
				number_format_i18n( isset( $status['credits']['remaining'] ) ? (int) $status['credits']['remaining'] : 0 )
			);
		}
		return sprintf(
			/* translators: 1: formule, 2: nombre de crédits restants. */
			__( 'Connecté — formule %1$s, %2$s crédits restants ce mois-ci', 'upsidly-agent' ),
			isset( $status['site']['plan'] ) ? (string) $status['site']['plan'] : '?',
			number_format_i18n( isset( $status['credits']['remaining'] ) ? (int) $status['credits']['remaining'] : 0 )
		);
	}

	/**
	 * Ajoute la notice de résultat de connexion (page Réglages).
	 *
	 * @param array|WP_Error $result Résultat de connect.
	 */
	private static function add_connection_notice( $result ) {
		if ( is_wp_error( $result ) ) {
			add_settings_error( self::OPTION_KEY, 'upsidly_agent_connect', $result->get_error_message(), 'error' );
		} else {
			add_settings_error( self::OPTION_KEY, 'upsidly_agent_connect', self::connected_message( $result ), 'success' );
		}
	}

	/**
	 * Bouton « Tester la connexion ».
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les droits suffisants.', 'upsidly-agent' ), 403 );
		}
		check_admin_referer( 'upsidly_agent_test' );

		if ( '' === self::get_key() ) {
			$notice = array(
				'type'    => 'error',
				'message' => __( 'Aucune clé n\'est configurée.', 'upsidly-agent' ),
			);
		} else {
			$result = self::connect_now();
			$notice = is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => self::connected_message( $result ),
				);
		}

		set_transient( 'upsidly_agent_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * URL de la page Réglages.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Affiche la page Réglages.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key        = self::get_key();
		$constant   = self::key_is_constant();
		$connection = self::get_connection();
		$notice     = get_transient( 'upsidly_agent_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'upsidly_agent_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap upsidly-agent-settings">
			<h1><?php esc_html_e( 'Upsidly Agent — Réglages', 'upsidly-agent' ); ?></h1>

			<?php settings_errors( self::OPTION_KEY ); ?>
			<?php settings_errors( 'general' ); ?>

			<?php if ( is_array( $notice ) && isset( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'État de la connexion', 'upsidly-agent' ); ?></h2>
			<p>
				<?php
				if ( '' === $key ) {
					esc_html_e( 'Aucune clé configurée : l\'assistant est inactif.', 'upsidly-agent' );
				} elseif ( null === $connection ) {
					esc_html_e( 'Clé enregistrée, connexion pas encore testée.', 'upsidly-agent' );
				} else {
					printf(
						'<strong class="%1$s">%2$s</strong> <span class="description">(%3$s)</span>',
						esc_attr( 'connected' === $connection['state'] ? 'upsidly-agent-ok' : 'upsidly-agent-error' ),
						esc_html( $connection['message'] ),
						esc_html(
							sprintf(
								/* translators: %s: date et heure. */
								__( 'vérifié le %s', 'upsidly-agent' ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $connection['date'] )
							)
						)
					);
				}
				?>
			</p>

			<?php if ( '' !== $key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="upsidly_agent_test" />
					<?php wp_nonce_field( 'upsidly_agent_test' ); ?>
					<?php submit_button( __( 'Tester la connexion', 'upsidly-agent' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="upsidly_agent_key"><?php esc_html_e( 'Clé de connexion', 'upsidly-agent' ); ?></label></th>
						<td>
							<?php if ( $constant ) : ?>
								<code><?php echo esc_html( self::key_hint( $key ) ); ?></code>
								<p class="description"><?php esc_html_e( 'La clé est définie dans wp-config.php (UPSIDLY_AGENT_KEY) et ne peut pas être modifiée ici.', 'upsidly-agent' ); ?></p>
							<?php else : ?>
								<input type="password" id="upsidly_agent_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $key ? self::key_hint( $key ) : 'upa_…' ); ?>" />
								<p class="description">
									<?php
									if ( '' !== $key ) {
										echo esc_html(
											sprintf(
												/* translators: %s: indice masqué de la clé. */
												__( 'Clé actuelle : %s. Laissez le champ vide pour la conserver.', 'upsidly-agent' ),
												self::key_hint( $key )
											)
										);
									} else {
										esc_html_e( 'Collez la clé fournie par Upsidly (elle commence par « upa_ »).', 'upsidly-agent' );
									}
									?>
								</p>
								<?php if ( '' !== $key ) : ?>
									<p>
										<label>
											<input type="checkbox" name="upsidly_agent_remove_key" value="1" />
											<?php esc_html_e( 'Retirer la clé (l\'assistant sera déconnecté)', 'upsidly-agent' ); ?>
										</label>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="upsidly_agent_site_description"><?php esc_html_e( 'Activité du site', 'upsidly-agent' ); ?></label></th>
						<td>
							<input type="text" id="upsidly_agent_site_description" name="<?php echo esc_attr( self::OPTION_DESCRIPTION ); ?>" value="<?php echo esc_attr( self::get_site_description() ); ?>" class="large-text" maxlength="<?php echo esc_attr( (string) self::DESCRIPTION_MAX ); ?>" />
							<p class="description"><?php esc_html_e( 'Une phrase, par exemple : « Menuiserie aluminium à Caen ».', 'upsidly-agent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="upsidly_agent_brand_voice"><?php esc_html_e( 'Ton de marque', 'upsidly-agent' ); ?></label></th>
						<td>
							<textarea id="upsidly_agent_brand_voice" name="<?php echo esc_attr( self::OPTION_VOICE ); ?>" rows="5" class="large-text" maxlength="<?php echo esc_attr( (string) self::VOICE_MAX ); ?>"><?php echo esc_textarea( self::get_brand_voice() ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Par exemple : « Professionnel, chaleureux, vouvoiement ». 2000 caractères maximum.', 'upsidly-agent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Agent', 'upsidly-agent' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_AGENT ); ?>" value="" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_AGENT ); ?>" value="1" <?php checked( self::agent_enabled() ); ?> />
								<?php esc_html_e( 'Activer l\'agent sur ce site', 'upsidly-agent' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'L\'agent peut lire les fichiers des thèmes et des extensions, les journaux d\'erreurs, les réglages, les contenus et la base de données pour diagnostiquer un problème, et proposer des modifications (fichiers, extensions, réglages, contenus) que vous validez une par une. Chaque modification est sauvegardée et peut être annulée. Il est réservé aux administrateurs et doit aussi être activé pour ce site par Upsidly.', 'upsidly-agent' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Données', 'upsidly-agent' ); ?></h2>
			<p><?php esc_html_e( 'Les contenus que vous soumettez à l\'assistant sont transmis à Upsidly et à son fournisseur d\'IA (Anthropic) pour générer la réponse.', 'upsidly-agent' ); ?></p>
		</div>
		<?php
	}
}
