<?php
/**
 * Classe principale del plugin PWA Core.
 *
 * @package PWACore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PWA_Core_Plugin {

	private static ?self $instance = null;

	public const OPTION_KEY          = 'pwa_core_settings';
	public const CACHE_TIMESTAMP_KEY = 'pwa_core_cache_timestamp';
	public const REWRITE_VERSION     = '1';
	public const REWRITE_OPT_KEY     = 'pwa_core_rewrite_version';

	private static ?bool $sensitive_memo = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_options(): array {
		$path      = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$start_url = is_string( $path ) && '' !== $path ? $path : '/';

		$defaults = [
			// Identità app
			'app_name'              => get_bloginfo( 'name' ),
			'short_name'            => substr( (string) get_bloginfo( 'name' ), 0, 12 ),
			'description'           => get_bloginfo( 'description' ),
			// Aspetto
			'theme_color'           => '#2271b1',
			'background_color'      => '#ffffff',
			'display'               => 'standalone',
			'start_url'             => $start_url,
			'orientation'           => 'portrait-primary',
			// Icone
			'icon_192'              => '',
			'icon_512'              => '',
			'icon_maskable'         => '',
			// Pagina offline
			'offline_page_id'       => 0,
			// Feature toggles
			'enable_online_indicator' => true,
			'enable_install_prompt'   => true,
			'enable_auto_invalidate'  => true,
			// Customizzazione cache
			'precache_urls'         => '',     // textarea, una URL per riga
			'extra_exclude_patterns' => '',    // textarea, un pattern per riga
			'cache_pages_limit'     => 50,
			'cache_assets_limit'    => 60,
			'cache_images_limit'    => 80,
		];

		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return array_merge( $defaults, $saved );
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public static function update_options( array $options ): bool {
		self::$sensitive_memo = null;
		self::bump_cache_timestamp();
		return update_option( self::OPTION_KEY, $options );
	}

	public static function get_cache_timestamp(): int {
		$ts = (int) get_option( self::CACHE_TIMESTAMP_KEY, 0 );
		if ( $ts <= 0 ) {
			$ts = time();
			update_option( self::CACHE_TIMESTAMP_KEY, $ts );
		}
		return $ts;
	}

	public static function bump_cache_timestamp(): void {
		update_option( self::CACHE_TIMESTAMP_KEY, time() );
	}

	public static function get_cache_version_string(): string {
		return 'v' . self::get_cache_timestamp();
	}

	/**
	 * Estrae da una textarea (una entry per riga) un array di righe non vuote.
	 * Cap: max 100 righe restituite, input grezzo troncato a 50KB.
	 *
	 * @return array<int, string>
	 */
	public static function parse_lines_option( string $key ): array {
		$options = self::get_options();
		$raw     = (string) ( $options[ $key ] ?? '' );
		if ( '' === $raw ) {
			return [];
		}
		// Tronca a 50KB per evitare elaborazione di input enormi.
		if ( strlen( $raw ) > 51200 ) {
			$raw = substr( $raw, 0, 51200 );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) ) {
			return [];
		}
		$out   = [];
		$count = 0;
		foreach ( $lines as $line ) {
			if ( $count >= 100 ) {
				break;
			}
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
				$count++;
			}
		}
		return $out;
	}

	public function boot(): void {
		add_action( 'init', [ $this, 'register_rewrites' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 20 );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'parse_request', [ $this, 'handle_virtual_endpoints' ], 1 );

		PWA_Manifest::register();
		PWA_Service_Worker::register();
		PWA_Offline::register();
		PWA_Shortcodes::register();

		if ( is_admin() ) {
			PWA_Admin::register();
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'wp_head', [ $this, 'render_head_tags' ], 1 );

		$options = self::get_options();
		if ( ! empty( $options['enable_auto_invalidate'] ) ) {
			add_action( 'save_post', [ $this, 'on_post_saved' ], 10, 2 );
			add_action( 'deleted_post', [ self::class, 'bump_cache_timestamp' ] );
			add_action( 'switch_theme', [ self::class, 'bump_cache_timestamp' ] );
		}

		// Hook per estendere precache via filter (in aggiunta al textarea admin).
		add_filter( 'pwa_core_precache_urls', [ $this, 'merge_admin_precache_urls' ] );
		add_filter( 'pwa_core_exclude_patterns', [ $this, 'merge_admin_exclude_patterns' ] );
	}

	/**
	 * @param array<int, string> $urls
	 * @return array<int, string>
	 */
	public function merge_admin_precache_urls( array $urls ): array {
		return array_merge( $urls, self::parse_lines_option( 'precache_urls' ) );
	}

	/**
	 * @param array<int, string> $patterns
	 * @return array<int, string>
	 */
	public function merge_admin_exclude_patterns( array $patterns ): array {
		return array_merge( $patterns, self::parse_lines_option( 'extra_exclude_patterns' ) );
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function on_post_saved( int $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		self::bump_cache_timestamp();
	}

	public function register_rewrites(): void {
		add_rewrite_rule( '^manifest\.json$', 'index.php?pwa_core_endpoint=manifest', 'top' );
		add_rewrite_rule( '^sw\.js$', 'index.php?pwa_core_endpoint=sw', 'top' );
	}

	public function maybe_flush_rewrites(): void {
		$current = get_option( self::REWRITE_OPT_KEY, '' );
		if ( $current !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPT_KEY, self::REWRITE_VERSION );
		}
	}

	/**
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'pwa_core_endpoint';
		$vars[] = 'pwa_core_offline';
		return $vars;
	}

	/**
	 * @param WP $wp
	 */
	public function handle_virtual_endpoints( $wp ): void {
		if ( ! ( $wp instanceof WP ) ) {
			return;
		}
		if ( ! isset( $wp->query_vars['pwa_core_endpoint'] ) ) {
			return;
		}
		$endpoint = (string) $wp->query_vars['pwa_core_endpoint'];
		switch ( $endpoint ) {
			case 'manifest':
				PWA_Manifest::serve();
				break;
			case 'sw':
				PWA_Service_Worker::serve();
				break;
		}
	}

	public function enqueue_frontend_assets(): void {
		if ( self::is_sensitive_page() ) {
			return;
		}
		if ( is_feed() || is_embed() || is_404() ) {
			return;
		}

		wp_enqueue_script(
			'pwa-core-app',
			PWA_CORE_URL . 'assets/app.js',
			[],
			PWA_CORE_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_enqueue_style(
			'pwa-core-app',
			PWA_CORE_URL . 'assets/app.css',
			[],
			PWA_CORE_VERSION
		);

		$options = self::get_options();

		wp_localize_script(
			'pwa-core-app',
			'PWACoreConfig',
			[
				'swUrl'                 => esc_url_raw( home_url( '/sw.js' ) ),
				'scope'                 => '/',
				'isUserLoggedIn'        => is_user_logged_in(),
				'enableOnlineIndicator' => (bool) $options['enable_online_indicator'],
				'enableInstallPrompt'   => (bool) $options['enable_install_prompt'],
				'i18n'                  => [
					'online'          => __( 'Sei di nuovo online', 'pwa-core' ),
					'offline'         => __( 'Sei offline – stai visualizzando la versione in cache', 'pwa-core' ),
					'installPrompt'   => __( 'Installa l\'app sul tuo dispositivo', 'pwa-core' ),
					'installButton'   => __( 'Installa', 'pwa-core' ),
					'installDismiss'  => __( 'Più tardi', 'pwa-core' ),
					// Testo puro, senza HTML: wp_localize_script inserisce le stringhe
					// dentro <script>, e HTML non escapato in quel contesto è XSS.
					// L'HTML di formattazione (es. <strong>) viene aggiunto in app.js.
					'iosInstructions' => __( 'Per installare: tocca il pulsante Condividi in Safari, poi seleziona Aggiungi alla schermata Home.', 'pwa-core' ),
				],
			]
		);
	}

	public function render_head_tags(): void {
		if ( is_feed() || is_embed() ) {
			return;
		}

		$options = self::get_options();

		$theme_color = sanitize_hex_color( (string) $options['theme_color'] );
		if ( ! is_string( $theme_color ) || '' === $theme_color ) {
			$theme_color = '#2271b1';
		}
		$app_name     = (string) $options['short_name'];
		$manifest_url = home_url( '/manifest.json' );
		$icon_192     = ! empty( $options['icon_192'] ) ? (string) $options['icon_192'] : '';

		echo "\n<!-- PWA Core -->\n";
		echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $theme_color ) . '">' . "\n";
		echo '<meta name="application-name" content="' . esc_attr( $app_name ) . '">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $app_name ) . '">' . "\n";

		if ( '' !== $icon_192 ) {
			echo '<link rel="apple-touch-icon" href="' . esc_url( $icon_192 ) . '">' . "\n";
		}

		echo "<!-- /PWA Core -->\n";
	}

	public static function is_sensitive_page(): bool {
		if ( null !== self::$sensitive_memo ) {
			return self::$sensitive_memo;
		}
		self::$sensitive_memo = self::compute_sensitive();
		return self::$sensitive_memo;
	}

	private static function compute_sensitive(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return true;
		}

		$post = get_post();
		if ( $post instanceof WP_Post && is_string( $post->post_content ) ) {
			$sensitive_shortcodes = [ 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account' ];
			foreach ( $sensitive_shortcodes as $sc ) {
				if ( has_shortcode( $post->post_content, $sc ) ) {
					return true;
				}
			}
			if ( function_exists( 'has_block' ) ) {
				$sensitive_blocks = [
					'woocommerce/cart',
					'woocommerce/checkout',
					'woocommerce/customer-account',
					'woocommerce/mini-cart',
				];
				foreach ( $sensitive_blocks as $block ) {
					if ( has_block( $block, $post ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
