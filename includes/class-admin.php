<?php
/**
 * Menus, pages, barre d'admin et notices.
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface d'administration.
 */
class Upsidly_Agent_Admin {

	const SLUG         = 'upsidly-agent';
	const AGENT_SLUG   = 'upsidly-agent-chat';
	const HISTORY_SLUG = 'upsidly-agent-history';

	/**
	 * Suffixe de hook de la page Agent.
	 *
	 * @var string
	 */
	private static $agent_hook = '';

	/**
	 * Suffixe de hook de la page Assistant.
	 *
	 * @var string
	 */
	private static $assistant_hook = '';

	/**
	 * Branche les hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menus' ) );
		add_action( 'admin_menu', array( __CLASS__, 'order_submenu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'draft_notice' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_notice' ) );
	}

	/**
	 * URL de l'Assistant.
	 *
	 * @param array $args Paramètres supplémentaires.
	 * @return string
	 */
	public static function assistant_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Menu « Upsidly Agent ».
	 */
	public static function menus() {
		self::$assistant_hook = (string) add_menu_page(
			__( 'Upsidly Agent', 'upsidly-agent' ),
			__( 'Upsidly Agent', 'upsidly-agent' ),
			'edit_posts',
			self::SLUG,
			array( __CLASS__, 'render_assistant' ),
			'dashicons-superhero-alt',
			58
		);

		add_submenu_page( self::SLUG, __( 'Rédaction rapide', 'upsidly-agent' ), __( 'Rédaction rapide', 'upsidly-agent' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render_assistant' ) );
		self::$agent_hook = (string) add_submenu_page( self::SLUG, __( 'Agent', 'upsidly-agent' ), __( 'Agent', 'upsidly-agent' ), Upsidly_Agent_Agent_Rest::CAPABILITY, self::AGENT_SLUG, array( __CLASS__, 'render_agent' ) );
		add_submenu_page( self::SLUG, __( 'Historique', 'upsidly-agent' ), __( 'Historique', 'upsidly-agent' ), 'edit_posts', self::HISTORY_SLUG, array( __CLASS__, 'render_history' ) );
		add_submenu_page( self::SLUG, __( 'Réglages', 'upsidly-agent' ), __( 'Réglages', 'upsidly-agent' ), 'manage_options', Upsidly_Agent_Settings::PAGE, array( 'Upsidly_Agent_Settings', 'render_page' ) );
	}

	/**
	 * Place « Agent » en tête du sous-menu.
	 */
	public static function order_submenu() {
		global $submenu;
		if ( empty( $submenu[ self::SLUG ] ) ) {
			return;
		}
		$items = $submenu[ self::SLUG ];
		usort(
			$items,
			static function ( $a, $b ) {
				return ( self::AGENT_SLUG === $b[2] ) <=> ( self::AGENT_SLUG === $a[2] );
			}
		);
		$submenu[ self::SLUG ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- réordonnancement du sous-menu du plugin.
	}

	/**
	 * URL de la page Agent.
	 *
	 * @return string
	 */
	public static function agent_url() {
		return add_query_arg( array( 'page' => self::AGENT_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Scripts de l'Assistant (uniquement sur sa page).
	 *
	 * @param string $hook Suffixe de la page courante.
	 */
	public static function enqueue( $hook ) {
		if ( $hook === self::$agent_hook ) {
			self::enqueue_agent();
			return;
		}
		if ( $hook !== self::$assistant_hook || '' === Upsidly_Agent_Settings::get_key() ) {
			return;
		}

		$asset_file = UPSIDLY_AGENT_DIR . 'build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'upsidly-agent-app',
			UPSIDLY_AGENT_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script( 'upsidly-agent-app', 'window.upsidlyAgent = ' . wp_json_encode( self::app_data() ) . ';', 'before' );
		wp_set_script_translations( 'upsidly-agent-app', 'upsidly-agent', UPSIDLY_AGENT_DIR . 'languages' );

		wp_enqueue_style( 'wp-components' );
		if ( is_readable( UPSIDLY_AGENT_DIR . 'build/style-index.css' ) ) {
			wp_enqueue_style( 'upsidly-agent-app', UPSIDLY_AGENT_URL . 'build/style-index.css', array( 'wp-components' ), $asset['version'] );
		}
	}

	/**
	 * Scripts de la page Agent.
	 */
	private static function enqueue_agent() {
		if ( ! Upsidly_Agent_Agent_Rest::can_use() ) {
			return;
		}
		$asset_file = UPSIDLY_AGENT_DIR . 'build/agent.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'upsidly-agent-chat', UPSIDLY_AGENT_URL . 'build/agent.js', $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script( 'upsidly-agent-chat', 'window.upsidlyAgentChat = ' . wp_json_encode( self::agent_data() ) . ';', 'before' );
		wp_set_script_translations( 'upsidly-agent-chat', 'upsidly-agent', UPSIDLY_AGENT_DIR . 'languages' );

		wp_enqueue_style( 'wp-components' );
		if ( is_readable( UPSIDLY_AGENT_DIR . 'build/agent.css' ) ) {
			wp_enqueue_style( 'upsidly-agent-chat', UPSIDLY_AGENT_URL . 'build/agent.css', array( 'wp-components' ), $asset['version'] );
		}
	}

	/**
	 * Données injectées dans la page Agent. Jamais la clé.
	 *
	 * @return array
	 */
	public static function agent_data() {
		$user = wp_get_current_user();
		return array(
			'siteName'    => get_bloginfo( 'name' ),
			'userName'    => $user->display_name,
			'ack'         => (bool) get_user_meta( $user->ID, Upsidly_Agent_Agent_Rest::ACK_META, true ),
			'settingsUrl' => current_user_can( 'manage_options' ) ? Upsidly_Agent_Settings::page_url() : '',
			'historyUrl'  => add_query_arg( array( 'page' => self::HISTORY_SLUG ), admin_url( 'admin.php' ) ),
		);
	}

	/**
	 * Page Agent.
	 */
	public static function render_agent() {
		if ( ! current_user_can( Upsidly_Agent_Agent_Rest::CAPABILITY ) ) {
			return;
		}
		$message = '';
		if ( '' === Upsidly_Agent_Settings::get_key() ) {
			$message = __( 'L\'assistant n\'est pas encore configuré : renseignez la clé dans Réglages.', 'upsidly-agent' );
		} elseif ( ! Upsidly_Agent_Settings::agent_enabled() ) {
			$message = __( 'L\'agent est désactivé sur ce site. Vous pouvez l\'activer dans Réglages.', 'upsidly-agent' );
		} elseif ( ! is_readable( UPSIDLY_AGENT_DIR . 'build/agent.asset.php' ) ) {
			$message = __( 'Les fichiers de l\'interface sont absents. Installez le plugin à partir du fichier ZIP de la release.', 'upsidly-agent' );
		}
		?>
		<div class="wrap upsidly-agent-chat-wrap">
			<?php if ( '' !== $message ) : ?>
				<h1><?php esc_html_e( 'Agent', 'upsidly-agent' ); ?></h1>
				<div class="notice notice-warning inline">
					<p>
						<?php echo esc_html( $message ); ?>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<a href="<?php echo esc_url( Upsidly_Agent_Settings::page_url() ); ?>"><?php esc_html_e( 'Ouvrir les réglages', 'upsidly-agent' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			<?php else : ?>
				<div id="upsidly-agent-chat"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Données injectées dans l'Assistant. Jamais la clé.
	 *
	 * @return array
	 */
	public static function app_data() {
		$categories = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 200,
			)
		);

		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- 200 pages au plus, imposé par la spécification.
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- liste limitée à 200 pages, page d'admin uniquement.
				'meta_query'       => array(
					array(
						'key'     => Upsidly_Agent_Drafts::META_SOURCE_PAGE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array(
			'restUrl'     => esc_url_raw( rest_url( Upsidly_Agent_Rest::NAMESPACE_V1 . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'settingsUrl' => current_user_can( 'manage_options' ) ? Upsidly_Agent_Settings::page_url() : '',
			'caps'        => array(
				'edit_pages'    => current_user_can( 'edit_pages' ),
				'publish_pages' => current_user_can( 'publish_pages' ),
			),
			'categories'  => is_wp_error( $categories ) ? array() : array_map(
				static function ( $term ) {
					return array(
						'id'   => (int) $term->term_id,
						'name' => $term->name,
					);
				},
				$categories
			),
			'pages'       => array_map(
				static function ( $page ) {
					return array(
						'id'           => (int) $page->ID,
						'title'        => '' !== $page->post_title ? $page->post_title : __( '(sans titre)', 'upsidly-agent' ),
						'status'       => $page->post_status,
						'is_elementor' => Upsidly_Agent_Drafts::is_elementor( $page->ID ),
						'can_edit'     => current_user_can( 'edit_post', $page->ID ),
					);
				},
				$pages
			),
		);
	}

	/**
	 * Page Assistant.
	 */
	public static function render_assistant() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="wrap upsidly-agent-wrap">
			<h1><?php esc_html_e( 'Assistant IA', 'upsidly-agent' ); ?></h1>
			<?php if ( '' === Upsidly_Agent_Settings::get_key() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<?php esc_html_e( 'L\'assistant n\'est pas encore configuré.', 'upsidly-agent' ); ?>
							<a href="<?php echo esc_url( Upsidly_Agent_Settings::page_url() ); ?>"><?php esc_html_e( 'Renseigner la clé dans Réglages', 'upsidly-agent' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Demandez à un administrateur de configurer l\'assistant.', 'upsidly-agent' ); ?>
						<?php endif; ?>
					</p>
				</div>
			<?php elseif ( ! is_readable( UPSIDLY_AGENT_DIR . 'build/index.asset.php' ) ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'Les fichiers de l\'interface sont absents. Installez le plugin à partir du fichier ZIP de la release.', 'upsidly-agent' ); ?></p>
				</div>
			<?php else : ?>
				<div id="upsidly-agent-app"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Page Historique.
	 */
	public static function render_history() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$rows = Upsidly_Agent_Log::for_current_user( 50 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Historique de l\'assistant', 'upsidly-agent' ); ?></h1>
			<?php if ( ! current_user_can( 'edit_others_posts' ) ) : ?>
				<p class="description"><?php esc_html_e( 'Seules vos propres demandes sont affichées.', 'upsidly-agent' ); ?></p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'upsidly-agent' ); ?></th>
						<th><?php esc_html_e( 'Utilisateur', 'upsidly-agent' ); ?></th>
						<th><?php esc_html_e( 'Skill', 'upsidly-agent' ); ?></th>
						<th><?php esc_html_e( 'Action', 'upsidly-agent' ); ?></th>
						<th><?php esc_html_e( 'Contenu', 'upsidly-agent' ); ?></th>
						<th><?php esc_html_e( 'Crédits', 'upsidly-agent' ); ?></th>
						<th><?php esc_html_e( 'Statut', 'upsidly-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Aucune demande pour le moment.', 'upsidly-agent' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['date'] ); ?></td>
							<td><?php echo esc_html( $row['user'] ); ?></td>
							<td><?php echo esc_html( $row['skill'] ); ?></td>
							<td><?php echo esc_html( 'apply' === $row['action'] ? __( 'Application', 'upsidly-agent' ) : __( 'Génération', 'upsidly-agent' ) ); ?></td>
							<td>
								<?php if ( '' !== $row['edit_url'] ) : ?>
									<a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( '' !== $row['post_title'] ? $row['post_title'] : '#' . $row['post_id'] ); ?></a>
								<?php elseif ( $row['post_id'] ) : ?>
									<?php echo esc_html( '' !== $row['post_title'] ? $row['post_title'] : '#' . $row['post_id'] ); ?>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $row['credits'] ) ); ?></td>
							<td>
								<?php if ( 'ok' === $row['status'] ) : ?>
									<?php esc_html_e( 'OK', 'upsidly-agent' ); ?>
								<?php else : ?>
									<span class="upsidly-agent-error"><?php echo esc_html( '' !== $row['error'] ? $row['error'] : __( 'Erreur', 'upsidly-agent' ) ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Lien « Assistant IA » dans la barre d'admin.
	 *
	 * @param WP_Admin_Bar $bar Barre d'admin.
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'upsidly-agent',
				'title' => __( 'Assistant IA', 'upsidly-agent' ),
				'href'  => Upsidly_Agent_Agent_Rest::can_use() ? self::agent_url() : self::assistant_url(),
			)
		);
	}

	/**
	 * Redirection unique vers Réglages après l'activation, si aucune clé n'est configurée.
	 */
	public static function activation_redirect() {
		if ( ! get_transient( 'upsidly_agent_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'upsidly_agent_activation_redirect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture du contexte d'activation.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '' !== Upsidly_Agent_Settings::get_key() ) {
			return;
		}

		wp_safe_redirect( Upsidly_Agent_Settings::page_url() );
		exit;
	}

	/**
	 * Texte suggéré pour la politique de confidentialité.
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Upsidly Agent', 'upsidly-agent' ),
			wp_kses_post(
				'<p>' . __( 'Lorsqu\'un membre de l\'équipe du site utilise l\'assistant de rédaction, les consignes saisies et, le cas échéant, le contenu de la page à modifier sont transmis à Upsidly et à son fournisseur d\'intelligence artificielle (Anthropic) afin de générer une proposition de texte. Lorsque l\'agent de maintenance est utilisé, des extraits techniques du site (fichiers des thèmes et extensions, journaux d\'erreurs, réglages, contenus, résultats de requêtes) peuvent aussi être transmis pour diagnostiquer un problème ; les données personnelles (adresses e-mail, téléphones, adresses, adresses IP) et les secrets (mots de passe, clés) sont masqués avant tout envoi. Aucune donnée n\'est transmise lors des visites du site.', 'upsidly-agent' ) . '</p>'
			)
		);
	}

	/**
	 * Brouillon généré par l'assistant ?
	 *
	 * @param int $post_id Contenu.
	 * @return bool
	 */
	private static function is_generated( $post_id ) {
		return $post_id > 0 && '1' === (string) get_post_meta( $post_id, Upsidly_Agent_Drafts::META_GENERATED, true );
	}

	/**
	 * Texte de la notice d'édition (notes, méta-description de repli, lien de comparaison).
	 *
	 * @param int $post_id Brouillon.
	 * @return array{notes: string, meta: string, diff_url: string}
	 */
	private static function notice_parts( $post_id ) {
		$source = (int) get_post_meta( $post_id, Upsidly_Agent_Drafts::META_SOURCE_PAGE, true );
		return array(
			'notes'    => (string) get_post_meta( $post_id, Upsidly_Agent_Drafts::META_NOTES, true ),
			'meta'     => (string) get_post_meta( $post_id, Upsidly_Agent_Seo::FALLBACK_KEY, true ),
			'diff_url' => $source ? self::assistant_url( array( 'diff' => $post_id ) ) : '',
		);
	}

	/**
	 * Notice sur l'écran d'édition classique d'un brouillon généré.
	 */
	public static function draft_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) ) {
			return;
		}
		$post_id = get_the_ID();
		if ( ! $post_id || ! self::is_generated( (int) $post_id ) ) {
			return;
		}

		$parts = self::notice_parts( (int) $post_id );
		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Brouillon généré par l\'assistant IA — à vérifier avant publication', 'upsidly-agent' ); ?></strong></p>
			<?php if ( '' !== $parts['notes'] ) : ?>
				<p><?php echo nl2br( esc_html( $parts['notes'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $parts['meta'] ) : ?>
				<p><?php echo esc_html( __( 'Méta-description proposée :', 'upsidly-agent' ) . ' ' . $parts['meta'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $parts['diff_url'] ) : ?>
				<p><a href="<?php echo esc_url( $parts['diff_url'] ); ?>"><?php esc_html_e( 'Comparer avec la page d\'origine', 'upsidly-agent' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Notice dans l'éditeur de blocs d'un brouillon généré (seul script chargé hors des pages du plugin).
	 */
	public static function editor_notice() {
		$post_id = get_the_ID();
		if ( ! $post_id || ! self::is_generated( (int) $post_id ) ) {
			return;
		}

		$parts   = self::notice_parts( (int) $post_id );
		$message = __( 'Brouillon généré par l\'assistant IA — à vérifier avant publication.', 'upsidly-agent' );
		if ( '' !== $parts['notes'] ) {
			$message .= ' ' . __( 'À vérifier :', 'upsidly-agent' ) . ' ' . $parts['notes'];
		}
		if ( '' !== $parts['meta'] ) {
			$message .= ' ' . __( 'Méta-description proposée :', 'upsidly-agent' ) . ' ' . $parts['meta'];
		}

		$actions = array();
		if ( '' !== $parts['diff_url'] ) {
			$actions[] = array(
				'label' => __( 'Comparer avec la page d\'origine', 'upsidly-agent' ),
				'url'   => $parts['diff_url'],
			);
		}

		wp_register_script( 'upsidly-agent-editor-notice', false, array( 'wp-data', 'wp-notices', 'wp-dom-ready' ), UPSIDLY_AGENT_VERSION, true );
		wp_enqueue_script( 'upsidly-agent-editor-notice' );
		wp_add_inline_script(
			'upsidly-agent-editor-notice',
			sprintf(
				'wp.domReady(function(){wp.data.dispatch("core/notices").createNotice("warning",%s,{id:"upsidly-agent-notes",isDismissible:true,actions:%s});});',
				wp_json_encode( $message ),
				wp_json_encode( $actions )
			)
		);
	}
}
