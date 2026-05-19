<?php
/**
 * Pagina di amministrazione del plugin PWA Core.
 *
 * Tab struttura:
 * - Generale: identità app, aspetto, icone
 * - Cache: versione, pagina offline, pulsante svuota
 * - Avanzate: precache URL, exclude pattern, limiti cache, toggle features
 * - Endpoint: info diagnostiche
 *
 * @package PWACore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PWA_Admin {

	private const PAGE_SLUG          = 'pwa-core';
	private const NONCE_ACTION_SAVE  = 'pwa_core_save_settings';
	private const NONCE_ACTION_CLEAR = 'pwa_core_clear_cache';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_post_pwa_core_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_pwa_core_clear_cache', [ self::class, 'handle_clear_cache' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_admin_assets' ] );
		add_filter( 'plugin_action_links_' . PWA_CORE_BASENAME, [ self::class, 'add_settings_link' ] );
		add_action( 'admin_notices', [ self::class, 'maybe_show_permalink_notice' ] );
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'PWA Core', 'pwa-core' ),
			__( 'PWA Core', 'pwa-core' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	public static function maybe_show_permalink_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$structure = get_option( 'permalink_structure', '' );
		if ( ! is_string( $structure ) || '' === $structure ) {
			$permalink_url = esc_url( admin_url( 'options-permalink.php' ) );
			echo '<div class="notice notice-error"><p>';
			echo '<strong>PWA Core:</strong> ';
			echo esc_html__( 'I permalink sono impostati su "Plain". Le rewrite rules necessarie al funzionamento non saranno applicate. ', 'pwa-core' );
			echo '<a href="' . $permalink_url . '">' . esc_html__( 'Cambia struttura permalink', 'pwa-core' ) . '</a>.';
			echo '</p></div>';
		}
	}

	/**
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public static function add_settings_link( array $links ): array {
		$url           = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Impostazioni', 'pwa-core' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
	}

	/**
	 * Estrae stringa pulita dal POST corrente.
	 * Rifiuta array (es. attaccante manda field[]=foo).
	 */
	private static function post_string( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}
		$val = $_POST[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! is_string( $val ) ) {
			return '';
		}
		return (string) wp_unslash( $val );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = PWA_Core_Plugin::get_options();
		$tab     = self::get_current_tab();

		self::render_admin_notices();

		?>
		<div class="wrap pwa-core-wrap">
			<h1><?php esc_html_e( 'PWA Core', 'pwa-core' ); ?></h1>
			<p><?php esc_html_e( 'Configura la Progressive Web App del tuo sito.', 'pwa-core' ); ?></p>

			<?php self::render_tabs( $tab ); ?>

			<?php
			switch ( $tab ) {
				case 'cache':
					self::render_tab_cache( $options );
					break;
				case 'advanced':
					self::render_tab_advanced( $options );
					break;
				case 'endpoints':
					self::render_tab_endpoints();
					break;
				case 'general':
				default:
					self::render_tab_general( $options );
					break;
			}
			?>
		</div>
		<?php
		self::render_media_picker_script();
	}

	private static function get_current_tab(): string {
		$tab = '';
		if ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$allowed = [ 'general', 'cache', 'advanced', 'endpoints' ];
		return in_array( $tab, $allowed, true ) ? $tab : 'general';
	}

	private static function render_admin_notices(): void {
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $raw ) && '1' === sanitize_text_field( wp_unslash( $raw ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Impostazioni salvate.', 'pwa-core' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = $_GET['cleared']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $raw ) && '1' === sanitize_text_field( wp_unslash( $raw ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cache invalidata. Tutti i client riceveranno una nuova cache al prossimo caricamento.', 'pwa-core' ) . '</p></div>';
			}
		}
	}

	private static function render_tabs( string $current ): void {
		$tabs = [
			'general'   => __( 'Generale', 'pwa-core' ),
			'cache'     => __( 'Cache', 'pwa-core' ),
			'advanced'  => __( 'Avanzate', 'pwa-core' ),
			'endpoints' => __( 'Endpoint', 'pwa-core' ),
		];
		$base_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url    = $slug === 'general' ? $base_url : add_query_arg( 'tab', $slug, $base_url );
			$active = $slug === $current ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
	}

	/**
	 * Apre il form principale (usato dai tab che salvano).
	 */
	private static function open_form( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pwa_core_save">';
		echo '<input type="hidden" name="pwa_core_active_tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( self::NONCE_ACTION_SAVE );
	}

	private static function close_form(): void {
		submit_button( __( 'Salva impostazioni', 'pwa-core' ) );
		echo '</form>';
	}

	/* ============================================================
	 * TAB: GENERALE
	 * ============================================================ */
	/**
	 * @param array<string, mixed> $options
	 */
	private static function render_tab_general( array $options ): void {
		self::open_form( 'general' );
		?>
		<h2><?php esc_html_e( 'Identità app', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="app_name"><?php esc_html_e( 'Nome app', 'pwa-core' ); ?></label></th>
				<td><input type="text" id="app_name" name="app_name" value="<?php echo esc_attr( (string) $options['app_name'] ); ?>" class="regular-text" required maxlength="100"></td>
			</tr>
			<tr>
				<th scope="row"><label for="short_name"><?php esc_html_e( 'Nome breve', 'pwa-core' ); ?></label></th>
				<td>
					<input type="text" id="short_name" name="short_name" value="<?php echo esc_attr( (string) $options['short_name'] ); ?>" class="regular-text" maxlength="12" required>
					<p class="description"><?php esc_html_e( 'Max 12 caratteri. Mostrato sotto l\'icona nella home screen.', 'pwa-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="description"><?php esc_html_e( 'Descrizione', 'pwa-core' ); ?></label></th>
				<td><textarea id="description" name="description" rows="2" class="large-text" maxlength="300"><?php echo esc_textarea( (string) $options['description'] ); ?></textarea></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Aspetto', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="theme_color"><?php esc_html_e( 'Colore tema', 'pwa-core' ); ?></label></th>
				<td><input type="color" id="theme_color" name="theme_color" value="<?php echo esc_attr( (string) $options['theme_color'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="background_color"><?php esc_html_e( 'Colore sfondo', 'pwa-core' ); ?></label></th>
				<td><input type="color" id="background_color" name="background_color" value="<?php echo esc_attr( (string) $options['background_color'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="display"><?php esc_html_e( 'Display mode', 'pwa-core' ); ?></label></th>
				<td>
					<?php
					self::render_select(
						'display',
						(string) $options['display'],
						[
							'standalone' => __( 'Standalone (consigliato)', 'pwa-core' ),
							'fullscreen' => __( 'Fullscreen', 'pwa-core' ),
							'minimal-ui' => __( 'Minimal UI', 'pwa-core' ),
							'browser'    => __( 'Browser', 'pwa-core' ),
						]
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="orientation"><?php esc_html_e( 'Orientamento', 'pwa-core' ); ?></label></th>
				<td>
					<?php
					self::render_select(
						'orientation',
						(string) $options['orientation'],
						[
							'any'               => __( 'Qualsiasi', 'pwa-core' ),
							'portrait-primary'  => __( 'Verticale', 'pwa-core' ),
							'landscape-primary' => __( 'Orizzontale', 'pwa-core' ),
						]
					);
					?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Icone', 'pwa-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Carica icone PNG quadrate dalla Libreria Media. Devono essere caricate sullo stesso dominio (le icone esterne vengono rifiutate per sicurezza).', 'pwa-core' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			$icon_fields = [
				'icon_192'      => __( 'Icona 192×192', 'pwa-core' ),
				'icon_512'      => __( 'Icona 512×512', 'pwa-core' ),
				'icon_maskable' => __( 'Icona maskable 512×512', 'pwa-core' ),
			];
			foreach ( $icon_fields as $field => $label ) :
				$value = (string) ( $options[ $field ] ?? '' );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input type="url" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text pwa-core-icon-url" placeholder="https://...">
						<button type="button" class="button pwa-core-icon-upload" data-target="<?php echo esc_attr( $field ); ?>"><?php esc_html_e( 'Scegli dalla libreria', 'pwa-core' ); ?></button>
						<?php if ( '' !== $value ) : ?>
							<div style="margin-top:8px;"><img src="<?php echo esc_url( $value ); ?>" alt="" style="max-width:96px;height:auto;border:1px solid #ddd;border-radius:8px;"></div>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Funzionalità frontend', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Indicatore online/offline', 'pwa-core' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enable_online_indicator" value="1" <?php checked( (bool) $options['enable_online_indicator'] ); ?>>
						<?php esc_html_e( 'Mostra un toast quando l\'utente perde o riprende la connessione', 'pwa-core' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Prompt di installazione', 'pwa-core' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enable_install_prompt" value="1" <?php checked( (bool) $options['enable_install_prompt'] ); ?>>
						<?php esc_html_e( 'Mostra un banner per invitare l\'utente a installare la PWA (richiede supporto browser)', 'pwa-core' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
		self::close_form();
	}

	/* ============================================================
	 * TAB: CACHE
	 * ============================================================ */
	/**
	 * @param array<string, mixed> $options
	 */
	private static function render_tab_cache( array $options ): void {
		$cache_ts      = PWA_Core_Plugin::get_cache_timestamp();
		$cache_version = PWA_Core_Plugin::get_cache_version_string();

		self::open_form( 'cache' );
		?>
		<h2><?php esc_html_e( 'Stato della cache', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Versione cache attuale', 'pwa-core' ); ?></th>
				<td>
					<code><?php echo esc_html( $cache_version ); ?></code>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: data e ora */
								__( 'Ultimo aggiornamento: %s', 'pwa-core' ),
								wp_date( get_option( 'date_format', 'Y-m-d' ) . ' H:i:s', $cache_ts )
							)
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Pagina offline', 'pwa-core' ); ?></th>
				<td>
					<?php
					$page_id = (int) $options['offline_page_id'];
					if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
						$edit_url = get_edit_post_link( $page_id );
						if ( $edit_url ) {
							echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Modifica la pagina offline', 'pwa-core' ) . '</a>';
						} else {
							esc_html_e( 'Pagina offline esistente.', 'pwa-core' );
						}
					} else {
						esc_html_e( 'Pagina offline non trovata. Disattiva e riattiva il plugin per ricrearla.', 'pwa-core' );
					}
					?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Invalidazione automatica', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-invalida la cache', 'pwa-core' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enable_auto_invalidate" value="1" <?php checked( (bool) $options['enable_auto_invalidate'] ); ?>>
						<?php esc_html_e( 'Invalida automaticamente la cache quando pubblichi o aggiorni un post', 'pwa-core' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Se disattivato dovrai svuotare manualmente la cache con il pulsante qui sotto.', 'pwa-core' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		self::close_form();

		// Form separato per "svuota cache" (azione diversa).
		?>
		<hr>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pwa_core_clear_cache">
			<?php wp_nonce_field( self::NONCE_ACTION_CLEAR ); ?>
			<button type="submit" class="button button-secondary">
				<?php esc_html_e( 'Svuota cache del Service Worker', 'pwa-core' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Forza tutti i client a ricaricare la cache al prossimo caricamento.', 'pwa-core' ); ?>
			</p>
		</form>
		<?php
	}

	/* ============================================================
	 * TAB: AVANZATE
	 * ============================================================ */
	/**
	 * @param array<string, mixed> $options
	 */
	private static function render_tab_advanced( array $options ): void {
		self::open_form( 'advanced' );
		?>
		<h2><?php esc_html_e( 'URL da pre-cacheare', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="precache_urls"><?php esc_html_e( 'URL aggiuntive', 'pwa-core' ); ?></label></th>
				<td>
					<textarea id="precache_urls" name="precache_urls" rows="6" class="large-text code" placeholder="<?php echo esc_attr( home_url( '/landing-pillar/' ) ); ?>"><?php echo esc_textarea( (string) $options['precache_urls'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Una URL per riga. Devono essere sullo stesso dominio del sito. Verranno scaricate e cacheate all\'install del Service Worker.', 'pwa-core' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Pattern da escludere dalla cache', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="extra_exclude_patterns"><?php esc_html_e( 'Pattern aggiuntivi', 'pwa-core' ); ?></label></th>
				<td>
					<textarea id="extra_exclude_patterns" name="extra_exclude_patterns" rows="6" class="large-text code" placeholder="/area-riservata/&#10;?private="><?php echo esc_textarea( (string) $options['extra_exclude_patterns'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Un pattern per riga. Vengono cercati come sottostringa nell\'URL della richiesta. Esempi: /area-riservata/, ?private=, /demo/.', 'pwa-core' ); ?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Pattern già esclusi automaticamente:', 'pwa-core' ); ?></strong>
						<code>/wp-admin/</code>,
						<code>/wp-login.php</code>,
						<code>/wp-json/</code>,
						<code>wc-ajax=</code>,
						<code>admin-ajax.php</code>,
						<code>/cart/</code>,
						<code>/checkout/</code>,
						<code>/my-account/</code>,
						<code>/carrello/</code>,
						<code>/cassa/</code>,
						<code>/mio-account/</code>,
						<code>add-to-cart=</code>,
						<code>/feed/</code>,
						<code>sitemap.xml</code>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Limiti dimensione cache', 'pwa-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Massimo numero di entry per ciascuna cache. Le entry più vecchie vengono rimosse quando si supera il limite.', 'pwa-core' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cache_pages_limit"><?php esc_html_e( 'Pagine HTML', 'pwa-core' ); ?></label></th>
				<td><input type="number" id="cache_pages_limit" name="cache_pages_limit" value="<?php echo esc_attr( (string) $options['cache_pages_limit'] ); ?>" min="10" max="500" step="1" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="cache_assets_limit"><?php esc_html_e( 'Asset (CSS/JS/font)', 'pwa-core' ); ?></label></th>
				<td><input type="number" id="cache_assets_limit" name="cache_assets_limit" value="<?php echo esc_attr( (string) $options['cache_assets_limit'] ); ?>" min="10" max="500" step="1" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="cache_images_limit"><?php esc_html_e( 'Immagini', 'pwa-core' ); ?></label></th>
				<td><input type="number" id="cache_images_limit" name="cache_images_limit" value="<?php echo esc_attr( (string) $options['cache_images_limit'] ); ?>" min="10" max="500" step="1" class="small-text"></td>
			</tr>
		</table>
		<?php
		self::close_form();
	}

	/* ============================================================
	 * TAB: ENDPOINTS / DIAGNOSTICA
	 * ============================================================ */
	private static function render_tab_endpoints(): void {
		?>
		<h2><?php esc_html_e( 'Shortcode disponibili', 'pwa-core' ); ?></h2>

		<p><?php esc_html_e( 'Inserisci questi shortcode in qualsiasi pagina o post per aggiungere pulsanti di installazione.', 'pwa-core' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row" style="white-space:nowrap;"><code>[pwa_install_button]</code></th>
				<td>
					<p><?php esc_html_e( 'Mostra un pulsante che avvia il prompt di installazione. Si nasconde automaticamente se la PWA è già installata.', 'pwa-core' ); ?></p>
					<p><strong><?php esc_html_e( 'Attributi opzionali:', 'pwa-core' ); ?></strong></p>
					<ul style="list-style:disc;padding-left:2rem;margin:.5rem 0">
						<li><code>label="Testo"</code> — <?php esc_html_e( 'testo del pulsante. Default: "Installa l\'app"', 'pwa-core' ); ?></li>
						<li><code>label_ios="Testo"</code> — <?php esc_html_e( 'testo alternativo su iOS Safari (install manuale). Default: "Aggiungi alla schermata Home"', 'pwa-core' ); ?></li>
						<li><code>installed_text="Testo"</code> — <?php esc_html_e( 'testo da mostrare se già installata. Se vuoto, il pulsante viene nascosto.', 'pwa-core' ); ?></li>
						<li><code>class="mia-classe"</code> — <?php esc_html_e( 'classi CSS aggiuntive per lo stile.', 'pwa-core' ); ?></li>
						<li><code>tag="a"</code> — <?php esc_html_e( 'usa un tag &lt;a&gt; invece di &lt;button&gt;.', 'pwa-core' ); ?></li>
					</ul>
					<p><strong><?php esc_html_e( 'Esempi:', 'pwa-core' ); ?></strong></p>
					<code style="display:block;margin:.25rem 0;padding:6px 10px;background:#f6f7f7;border-radius:4px">[pwa_install_button]</code>
					<code style="display:block;margin:.25rem 0;padding:6px 10px;background:#f6f7f7;border-radius:4px">[pwa_install_button label="Scarica l'app" class="btn btn-primary"]</code>
					<code style="display:block;margin:.25rem 0;padding:6px 10px;background:#f6f7f7;border-radius:4px">[pwa_install_button installed_text="App già installata ✓"]</code>
				</td>
			</tr>
			<tr>
				<th scope="row" style="white-space:nowrap;"><code>[pwa_install_status]</code></th>
				<td>
					<p><?php esc_html_e( 'Mostra un testo diverso a seconda che la PWA sia installata o meno.', 'pwa-core' ); ?></p>
					<p><strong><?php esc_html_e( 'Attributi opzionali:', 'pwa-core' ); ?></strong></p>
					<ul style="list-style:disc;padding-left:2rem;margin:.5rem 0">
						<li><code>installed="Testo"</code> — <?php esc_html_e( 'testo da mostrare se installata.', 'pwa-core' ); ?></li>
						<li><code>not_installed="Testo"</code> — <?php esc_html_e( 'testo da mostrare se NON installata (vuoto = niente).', 'pwa-core' ); ?></li>
						<li><code>class="mia-classe"</code> — <?php esc_html_e( 'classi CSS aggiuntive.', 'pwa-core' ); ?></li>
					</ul>
					<p><strong><?php esc_html_e( 'Esempio:', 'pwa-core' ); ?></strong></p>
					<code style="display:block;padding:6px 10px;background:#f6f7f7;border-radius:4px">[pwa_install_status installed="✓ App installata" not_installed="Installa l'app per accedere offline"]</code>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'API JavaScript (window.PWACore)', 'pwa-core' ); ?></h2>
		<p><?php esc_html_e( 'Puoi usare l\'API JS per integrare l\'installazione nel tuo tema o in altri script:', 'pwa-core' ); ?></p>
		<pre style="background:#f6f7f7;padding:12px;border-radius:4px;overflow-x:auto;font-size:13px"><?php echo esc_html(
'// Mostra il prompt di installazione
window.PWACore.triggerInstall();

// Controlla se già installata
if (window.PWACore.isInstalled()) { ... }

// Controlla se il prompt è disponibile
if (window.PWACore.canInstall()) { ... }

// Ascolta i cambiamenti di stato
window.PWACore.onStateChange(function(state) {
    console.log(state.isInstalled);  // true/false
    console.log(state.canInstall);   // true/false
    console.log(state.isIOS);        // true su Safari iOS
});'
		); ?></pre>

		<h2><?php esc_html_e( 'Endpoint disponibili', 'pwa-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Manifest', 'pwa-core' ); ?></th>
				<td>
					<code><?php echo esc_html( home_url( '/manifest.json' ) ); ?></code>
					<a href="<?php echo esc_url( home_url( '/manifest.json' ) ); ?>" target="_blank" rel="noopener" class="button button-small" style="margin-left:.5rem;"><?php esc_html_e( 'Apri', 'pwa-core' ); ?></a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Service Worker', 'pwa-core' ); ?></th>
				<td>
					<code><?php echo esc_html( home_url( '/sw.js' ) ); ?></code>
					<a href="<?php echo esc_url( home_url( '/sw.js' ) ); ?>" target="_blank" rel="noopener" class="button button-small" style="margin-left:.5rem;"><?php esc_html_e( 'Apri', 'pwa-core' ); ?></a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'REST API Manifest', 'pwa-core' ); ?></th>
				<td>
					<code><?php echo esc_html( rest_url( PWA_Manifest::REST_NAMESPACE . PWA_Manifest::REST_ROUTE ) ); ?></code>
					<a href="<?php echo esc_url( rest_url( PWA_Manifest::REST_NAMESPACE . PWA_Manifest::REST_ROUTE ) ); ?>" target="_blank" rel="noopener" class="button button-small" style="margin-left:.5rem;"><?php esc_html_e( 'Apri', 'pwa-core' ); ?></a>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Compatibilità WooCommerce', 'pwa-core' ); ?></h2>
		<p><?php esc_html_e( 'Il plugin esclude automaticamente dalla cache:', 'pwa-core' ); ?></p>
		<ul style="list-style:disc;padding-left:2rem;">
			<li><?php esc_html_e( 'Tutte le pagine per utenti loggati (il SW non viene registrato)', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Carrello, checkout, area utente WooCommerce (anche con slug italiani)', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Pagine con shortcode o blocchi Gutenberg WooCommerce', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Endpoint AJAX, REST API, admin, login', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Risposte con Set-Cookie, Vary: Cookie, Cache-Control: private', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Redirect, Range request (video/audio)', 'pwa-core' ); ?></li>
		</ul>

		<h2><?php esc_html_e( 'Test PWA', 'pwa-core' ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'Apri il sito in Chrome DevTools → Application → Manifest: deve mostrare il manifest senza errori', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Application → Service Workers: stato "activated and running"', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Lighthouse → PWA: punteggio "installabile"', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Disconnetti la rete e ricarica una pagina visitata: deve apparire dalla cache', 'pwa-core' ); ?></li>
			<li><?php esc_html_e( 'Naviga offline a una pagina mai visitata: appare la pagina offline custom', 'pwa-core' ); ?></li>
		</ol>
		<?php
	}

	/* ============================================================
	 * HELPERS
	 * ============================================================ */
	/**
	 * @param array<string, string> $options
	 */
	private static function render_select( string $name, string $current, array $options ): void {
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			$is_sel = selected( $current, $value, false );
			echo '<option value="' . esc_attr( $value ) . '"' . $is_sel . '>' . esc_html( $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</select>';
	}

	private static function render_media_picker_script(): void {
		?>
		<script>
		(function () {
			if (typeof wp === 'undefined' || !wp.media) { return; }
			document.querySelectorAll('.pwa-core-icon-upload').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					var target = btn.dataset.target;
					if (!target) return;
					var input = document.getElementById(target);
					if (!input) return;
					var frame = wp.media({
						title: <?php echo wp_json_encode( __( 'Scegli un\'icona', 'pwa-core' ) ); ?>,
						button: { text: <?php echo wp_json_encode( __( 'Usa questa icona', 'pwa-core' ) ); ?> },
						library: { type: 'image' },
						multiple: false
					});
					frame.on('select', function () {
						var attachment = frame.state().get('selection').first().toJSON();
						if (attachment && attachment.url) { input.value = attachment.url; }
					});
					frame.open();
				});
			});
		})();
		</script>
		<?php
	}

	/* ============================================================
	 * SAVE HANDLER
	 * ============================================================ */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'pwa-core' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION_SAVE );

		$current = PWA_Core_Plugin::get_options();
		$tab     = sanitize_key( self::post_string( 'pwa_core_active_tab' ) );
		if ( ! in_array( $tab, [ 'general', 'cache', 'advanced' ], true ) ) {
			$tab = 'general';
		}

		// Partiamo dai valori correnti e sovrascriviamo solo i campi del tab.
		$updated = $current;

		switch ( $tab ) {
			case 'general':
				$updated = self::save_general( $current );
				break;
			case 'cache':
				$updated = self::save_cache( $current );
				break;
			case 'advanced':
				$updated = self::save_advanced( $current );
				break;
		}

		PWA_Core_Plugin::update_options( $updated );

		$redirect_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		if ( 'general' !== $tab ) {
			$redirect_url = add_query_arg( 'tab', $tab, $redirect_url );
		}
		$redirect_url = add_query_arg( 'updated', '1', $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	private static function save_general( array $current ): array {
		$updated = $current;

		$app_name = sanitize_text_field( self::post_string( 'app_name' ) );
		$app_name = self::mb_truncate( $app_name, 100 );
		$updated['app_name'] = '' !== $app_name ? $app_name : (string) $current['app_name'];

		$short_name = sanitize_text_field( self::post_string( 'short_name' ) );
		$short_name = self::mb_truncate( $short_name, 12 );
		$updated['short_name'] = '' !== $short_name ? $short_name : (string) $current['short_name'];

		$description = sanitize_textarea_field( self::post_string( 'description' ) );
		$description = self::mb_truncate( $description, 300 );
		$updated['description'] = $description;

		$tc                     = sanitize_hex_color( self::post_string( 'theme_color' ) );
		$updated['theme_color'] = ( is_string( $tc ) && '' !== $tc ) ? $tc : '#2271b1';

		$bc                          = sanitize_hex_color( self::post_string( 'background_color' ) );
		$updated['background_color'] = ( is_string( $bc ) && '' !== $bc ) ? $bc : '#ffffff';

		$updated['display']     = self::sanitize_in_list( self::post_string( 'display' ), [ 'standalone', 'fullscreen', 'minimal-ui', 'browser' ], 'standalone' );
		$updated['orientation'] = self::sanitize_in_list( self::post_string( 'orientation' ), [ 'any', 'natural', 'landscape', 'portrait', 'portrait-primary', 'landscape-primary' ], 'portrait-primary' );

		$updated['icon_192']      = self::sanitize_icon_url( self::post_string( 'icon_192' ) );
		$updated['icon_512']      = self::sanitize_icon_url( self::post_string( 'icon_512' ) );
		$updated['icon_maskable'] = self::sanitize_icon_url( self::post_string( 'icon_maskable' ) );

		// Checkbox: presente nel POST se selezionato.
		$updated['enable_online_indicator'] = isset( $_POST['enable_online_indicator'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$updated['enable_install_prompt']   = isset( $_POST['enable_install_prompt'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return $updated;
	}

	/**
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	private static function save_cache( array $current ): array {
		$updated = $current;
		$updated['enable_auto_invalidate'] = isset( $_POST['enable_auto_invalidate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return $updated;
	}

	/**
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	private static function save_advanced( array $current ): array {
		$updated = $current;

		// Precache URL: textarea, una per riga. Validiamo same-host.
		$raw_precache              = self::post_string( 'precache_urls' );
		$updated['precache_urls'] = self::sanitize_url_lines( $raw_precache );

		// Exclude pattern: textarea, una per riga. Sanifichiamo come pattern.
		$raw_exclude                        = self::post_string( 'extra_exclude_patterns' );
		$updated['extra_exclude_patterns'] = self::sanitize_pattern_lines( $raw_exclude );

		// Limiti numerici.
		$updated['cache_pages_limit']  = self::sanitize_int( self::post_string( 'cache_pages_limit' ), 10, 500, 50 );
		$updated['cache_assets_limit'] = self::sanitize_int( self::post_string( 'cache_assets_limit' ), 10, 500, 60 );
		$updated['cache_images_limit'] = self::sanitize_int( self::post_string( 'cache_images_limit' ), 10, 500, 80 );

		return $updated;
	}

	public static function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'pwa-core' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION_CLEAR );

		PWA_Core_Plugin::bump_cache_timestamp();

		$redirect_url = add_query_arg(
			[
				'tab'     => 'cache',
				'cleared' => '1',
			],
			admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/* ============================================================
	 * SANITIZERS
	 * ============================================================ */
	/**
	 * @param array<int, string> $allowed
	 */
	private static function sanitize_in_list( string $value, array $allowed, string $default ): string {
		$value = sanitize_text_field( $value );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function sanitize_icon_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$clean = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $clean ) {
			return '';
		}
		$icon_host = wp_parse_url( $clean, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $icon_host !== $site_host ) {
			return '';
		}
		return $clean;
	}

	/**
	 * Sanifica una textarea di URL (una per riga): solo same-host, max 50 URL,
	 * input grezzo troncato a 50KB. Ritorna stringa con un URL per riga.
	 */
	private static function sanitize_url_lines( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		// Cap dimensione input a 50KB.
		if ( strlen( $raw ) > 51200 ) {
			$raw = substr( $raw, 0, 51200 );
		}
		$lines     = preg_split( '/\r\n|\r|\n/', $raw );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out       = [];
		$count     = 0;

		foreach ( (array) $lines as $line ) {
			if ( $count >= 50 ) {
				break;
			}
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$clean = esc_url_raw( $line, [ 'http', 'https' ] );
			if ( '' === $clean ) {
				continue;
			}
			$host = wp_parse_url( $clean, PHP_URL_HOST );
			if ( $host !== $site_host ) {
				continue;
			}
			if ( ! in_array( $clean, $out, true ) ) {
				$out[] = $clean;
				$count++;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Sanifica una textarea di pattern: max 50 pattern, max 200 caratteri per pattern.
	 * Rimuove solo caratteri di controllo, quote e backslash (che romperebbero il JSON output).
	 * I caratteri [ ] { } sono permessi: legittimi in URL tipo /api/{id}/.
	 */
	private static function sanitize_pattern_lines( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		// Cap dimensione input a 50KB per evitare elaborazione di payload enormi.
		if ( strlen( $raw ) > 51200 ) {
			$raw = substr( $raw, 0, 51200 );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = [];
		$count = 0;

		foreach ( (array) $lines as $line ) {
			if ( $count >= 50 ) {
				break;
			}
			$line = (string) $line;
			// Rimuove solo caratteri che romperebbero JSON/parsing JS.
			$line = preg_replace( '/[\x00-\x1F\x7F"\'\\\\]/', '', $line );
			$line = trim( (string) $line );
			if ( '' === $line || strlen( $line ) > 200 ) {
				continue;
			}
			if ( ! in_array( $line, $out, true ) ) {
				$out[] = $line;
				$count++;
			}
		}

		return implode( "\n", $out );
	}

	private static function sanitize_int( string $raw, int $min, int $max, int $default ): int {
		$raw = trim( $raw );
		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			return $default;
		}
		$n = (int) $raw;
		if ( $n < $min ) {
			return $min;
		}
		if ( $n > $max ) {
			return $max;
		}
		return $n;
	}

	/**
	 * Tronca una stringa a $max caratteri Unicode (non bytes).
	 * Usa mb_substr se disponibile, altrimenti substr come fallback sicuro.
	 * Garantisce output UTF-8 valido.
	 */
	private static function mb_truncate( string $value, int $max ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) <= $max ) {
				return $value;
			}
			return (string) mb_substr( $value, 0, $max, 'UTF-8' );
		}
		// Fallback: usa substr ma verifica UTF-8. Se spezziamo un multibyte,
		// wp_check_invalid_utf8 (WP 6.0+) lo corregge, altrimenti tronchiamo
		// ulteriormente fino al primo byte valido.
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		$truncated = substr( $value, 0, $max );
		// Assicura UTF-8 valido rimuovendo eventuali byte parziali in coda.
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			return wp_check_invalid_utf8( $truncated, true );
		}
		return $truncated;
	}
}
