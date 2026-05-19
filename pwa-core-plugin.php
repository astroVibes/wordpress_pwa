<?php
/**
 * Plugin Name: PWA Core
 * Plugin URI: https://example.com/pwa-core
 * Description: Progressive Web App support for WordPress with offline capabilities, install prompts, and manifest generation.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pwa-core
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PWA_CORE_VERSION', '1.0.0');
define('PWA_CORE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PWA_CORE_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
final class PWA_Core_Plugin {

    private static ?PWA_Core_Plugin $instance = null;

    private array $options = [];

    public static function get_instance(): PWA_Core_Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_options();
        $this->init_hooks();
    }

    private function load_options(): void {
        $this->options = get_option('pwa_core_options', []);
        if (!is_array($this->options)) {
            $this->options = [];
        }
    }

    /**
     * Safe string conversion that handles arrays and null values without warnings
     * 
     * @param mixed $value The value to convert
     * @param string $default Default value if conversion fails
     * @return string Safe string representation
     */
    public static function safe_str($value, string $default = ''): string {
        if (null === $value) {
            return $default;
        }
        if (is_array($value)) {
            return $default;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Safe integer conversion that handles arrays and invalid values
     * 
     * @param mixed $value The value to convert
     * @param int $default Default value if conversion fails
     * @return int Safe integer representation
     */
    public static function safe_int($value, int $default = 0): int {
        if (null === $value) {
            return $default;
        }
        if (is_array($value)) {
            return $default;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Safe boolean conversion
     * 
     * @param mixed $value The value to convert
     * @param bool $default Default value if conversion fails
     * @return bool Safe boolean representation
     */
    public static function safe_bool($value, bool $default = false): bool {
        if (null === $value) {
            return $default;
        }
        if (is_array($value)) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    private function init_hooks(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('wp_head', [$this, 'render_head_tags']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Add rewrite rules for offline page
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_offline_template']);

        // Load text domain
        add_action('plugins_loaded', [$this, 'load_textdomain']);
    }

    public function activate(): void {
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        
        $default_options = [
            'app_name' => get_bloginfo('name'),
            'app_short_name' => get_bloginfo('name'),
            'app_description' => get_bloginfo('description'),
            'theme_color' => '#ffffff',
            'background_color' => '#ffffff',
            'display' => 'standalone',
            'orientation' => 'any',
            'enable_offline' => true,
            'enable_install_prompt' => true,
            'enable_online_indicator' => false,
            'cache_pages_limit' => 50,
            'cache_size_limit_mb' => 100,
            'offline_page_id' => 0,
            'icon_192' => '',
            'icon_512' => '',
        ];
        
        if (false === get_option('pwa_core_options')) {
            update_option('pwa_core_options', $default_options);
        }
    }

    public function deactivate(): void {
        flush_rewrite_rules();
        delete_transient('pwa_core_manifest');
        delete_transient('pwa_core_sw_version');
    }

    public function init(): void {
        load_plugin_textdomain('pwa-core', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_rewrite_rules(): void {
        add_rewrite_rule('^offline/?$', 'index.php?pwa_core_offline=1', 'top');
        add_rewrite_rule('^sw\.js$', 'index.php?pwa_core_sw=1', 'top');
        add_rewrite_rule('^manifest\.json$', 'index.php?pwa_core_manifest=1', 'top');
    }

    public function add_query_vars(array $vars): array {
        $vars[] = 'pwa_core_offline';
        $vars[] = 'pwa_core_sw';
        $vars[] = 'pwa_core_manifest';
        return $vars;
    }

    public function handle_offline_template(): void {
        $query_var = get_query_var('pwa_core_offline');
        
        // Safe validation: check if it's exactly '1' or true
        if ('1' === $query_var || true === $query_var) {
            $this->load_template('offline');
            exit;
        }

        // Handle manifest request
        $manifest_var = get_query_var('pwa_core_manifest');
        if ('1' === $manifest_var || true === $manifest_var) {
            $this->serve_manifest();
            exit;
        }

        // Handle service worker request
        $sw_var = get_query_var('pwa_core_sw');
        if ('1' === $sw_var || true === $sw_var) {
            $this->serve_service_worker();
            exit;
        }
    }

    private function load_template(string $template): void {
        $template_file = PWA_CORE_PLUGIN_DIR . 'templates/' . $template . '.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            // Fallback inline template
            $this->render_fallback_template($template);
        }
    }

    private function render_fallback_template(string $template): void {
        if ('offline' === $template) {
            $options = $this->options;
            $offline_page_id = self::safe_int($options['offline_page_id'] ?? 0, 0);
            
            if ($offline_page_id > 0) {
                $post = get_post($offline_page_id);
                if ($post) {
                    echo apply_filters('the_content', $post->post_content);
                    return;
                }
            }
            
            ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Offline - PWA', 'pwa-core'); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; text-align: center; padding: 50px 20px; }
        h1 { color: #333; }
        p { color: #666; }
        button { background: #007cba; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; margin-top: 20px; }
        button:hover { background: #005a87; }
    </style>
</head>
<body>
    <h1><?php esc_html_e('You are offline', 'pwa-core'); ?></h1>
    <p><?php esc_html_e('Please check your internet connection and try again.', 'pwa-core'); ?></p>
    <button onclick="location.reload()"><?php esc_html_e('Retry', 'pwa-core'); ?></button>
</body>
</html>
            <?php
        }
    }

    public function enqueue_frontend_assets(): void {
        $options = $this->options;
        
        wp_enqueue_style(
            'pwa-core-style',
            PWA_CORE_PLUGIN_URL . 'assets/css/pwa-core.css',
            [],
            PWA_CORE_VERSION
        );

        wp_enqueue_script(
            'pwa-core-app',
            PWA_CORE_PLUGIN_URL . 'assets/js/app.js',
            [],
            PWA_CORE_VERSION,
            true
        );

        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('pwa-core/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'online' => __('Online', 'pwa-core'),
                'offline' => __('Offline', 'pwa-core'),
                'installing' => __('Installing...', 'pwa-core'),
                'installed' => __('Installed', 'pwa-core'),
                'install' => __('Install App', 'pwa-core'),
                'close' => __('Close', 'pwa-core'),
            ],
            'settings' => [
                'enableInstallPrompt' => self::safe_bool($options['enable_install_prompt'] ?? true, true),
                'enableOnlineIndicator' => self::safe_bool($options['enable_online_indicator'] ?? false, false),
            ],
            'version' => PWA_CORE_VERSION,
        ];

        wp_localize_script('pwa-core-app', 'pwaCoreConfig', $config);

        // Register service worker
        if (self::safe_bool($options['enable_offline'] ?? true, true)) {
            wp_register_script(
                'pwa-core-sw-register',
                PWA_CORE_PLUGIN_URL . 'assets/js/sw-register.js',
                ['pwa-core-app'],
                PWA_CORE_VERSION,
                true
            );
            wp_enqueue_script('pwa-core-sw-register');
        }
    }

    public function render_head_tags(): void {
        $options = $this->options;
        
        $app_name = self::safe_str($options['app_name'] ?? get_bloginfo('name'), get_bloginfo('name'));
        $theme_color = self::safe_str($options['theme_color'] ?? '#ffffff', '#ffffff');
        
        ?>
<link rel="manifest" href="<?php echo esc_url(rest_url('pwa-core/v1/manifest')); ?>">
<meta name="theme-color" content="<?php echo esc_attr($theme_color); ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr($app_name); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url(self::safe_str($options['icon_192'] ?? '', includes_url('images/w-logo-blue.png'))); ?>">
        <?php
    }

    public function add_admin_menu(): void {
        add_options_page(
            __('PWA Settings', 'pwa-core'),
            __('PWA', 'pwa-core'),
            'manage_options',
            'pwa-core',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings(): void {
        register_setting('pwa_core_general', 'pwa_core_options', [
            'sanitize_callback' => [$this, 'sanitize_options'],
        ]);
    }

    public function sanitize_options(array $input): array {
        $output = [];
        
        $output['app_name'] = sanitize_text_field($input['app_name'] ?? '');
        $output['app_short_name'] = sanitize_text_field($input['app_short_name'] ?? '');
        $output['app_description'] = sanitize_text_field($input['app_description'] ?? '');
        $output['theme_color'] = sanitize_hex_color($input['theme_color'] ?? '#ffffff');
        $output['background_color'] = sanitize_hex_color($input['background_color'] ?? '#ffffff');
        $output['display'] = in_array($input['display'] ?? 'standalone', ['standalone', 'fullscreen', 'minimal-ui', 'browser'], true) ? $input['display'] : 'standalone';
        $output['orientation'] = in_array($input['orientation'] ?? 'any', ['any', 'natural', 'portrait', 'landscape'], true) ? $input['orientation'] : 'any';
        $output['enable_offline'] = isset($input['enable_offline']) ? true : false;
        $output['enable_install_prompt'] = isset($input['enable_install_prompt']) ? true : false;
        $output['enable_online_indicator'] = isset($input['enable_online_indicator']) ? true : false;
        $output['cache_pages_limit'] = max(1, min(500, self::safe_int($input['cache_pages_limit'] ?? 50, 50)));
        $output['cache_size_limit_mb'] = max(10, min(1000, self::safe_int($input['cache_size_limit_mb'] ?? 100, 100)));
        $output['offline_page_id'] = self::safe_int($input['offline_page_id'] ?? 0, 0);
        $output['icon_192'] = esc_url_raw($input['icon_192'] ?? '');
        $output['icon_512'] = esc_url_raw($input['icon_512'] ?? '');
        
        return $output;
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $options = $this->options;
        
        // Include admin template
        include PWA_CORE_PLUGIN_DIR . 'admin/class-pwa-admin.php';
        $admin = new PWA_Admin();
        $admin->render($options);
    }

    public function register_rest_routes(): void {
        register_rest_route('pwa-core/v1', '/manifest', [
            'methods' => 'GET',
            'callback' => [$this, 'get_manifest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('pwa-core/v1', '/install-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_install_status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_manifest(): WP_REST_Response {
        $options = $this->options;
        
        $manifest = [
            'name' => self::safe_str($options['app_name'] ?? get_bloginfo('name'), get_bloginfo('name')),
            'short_name' => self::safe_str($options['app_short_name'] ?? get_bloginfo('name'), get_bloginfo('name')),
            'description' => self::safe_str($options['app_description'] ?? get_bloginfo('description'), get_bloginfo('description')),
            'start_url' => home_url('/'),
            'display' => self::safe_str($options['display'] ?? 'standalone', 'standalone'),
            'orientation' => self::safe_str($options['orientation'] ?? 'any', 'any'),
            'theme_color' => self::safe_str($options['theme_color'] ?? '#ffffff', '#ffffff'),
            'background_color' => self::safe_str($options['background_color'] ?? '#ffffff', '#ffffff'),
            'icons' => [],
        ];

        $icon_192 = self::safe_str($options['icon_192'] ?? '', '');
        $icon_512 = self::safe_str($options['icon_512'] ?? '', '');

        if (!empty($icon_192)) {
            $manifest['icons'][] = [
                'src' => esc_url_raw($icon_192),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        } else {
            // Default WordPress icon
            $manifest['icons'][] = [
                'src' => includes_url('images/w-logo-blue.png'),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        }

        if (!empty($icon_512)) {
            $manifest['icons'][] = [
                'src' => esc_url_raw($icon_512),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        } else {
            $manifest['icons'][] = [
                'src' => includes_url('images/w-logo-blue.png'),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        }

        // Cache manifest for 5 minutes to reduce server load
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Vary: Accept');

        return new WP_REST_Response($manifest, 200);
    }

    public function get_install_status(): WP_REST_Response {
        return new WP_REST_Response([
            'installed' => false,
            'installable' => true,
            'message' => __('App is ready to install', 'pwa-core'),
        ], 200);
    }

    private function serve_manifest(): void {
        $response = $this->get_manifest();
        
        // Set headers
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Vary: Accept');
        
        echo wp_json_encode($response->get_data());
        exit;
    }

    private function serve_service_worker(): void {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        $options = $this->options;
        $cache_pages_limit = self::safe_int($options['cache_pages_limit'] ?? 50, 50);
        $cache_size_limit_mb = self::safe_int($options['cache_size_limit_mb'] ?? 100, 100);
        
        include PWA_CORE_PLUGIN_DIR . 'includes/class-pwa-service-worker.php';
        $sw = new PWA_Service_Worker();
        $sw->serve($cache_pages_limit, $cache_size_limit_mb);
        exit;
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('pwa-core', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function get_options(): array {
        return $this->options;
    }

    public function update_option(string $key, $value): bool {
        $this->options[$key] = $value;
        return update_option('pwa_core_options', $this->options);
    }
}

// Initialize plugin
function pwa_core_init(): PWA_Core_Plugin {
    return PWA_Core_Plugin::get_instance();
}

// Start the plugin
pwa_core_init();
=======
 * Plugin Name:       PWA Core Plugin
 * Plugin URI:        https://github.com/example/pwa-core-plugin
 * Description:       Trasforma qualsiasi sito WordPress in una Progressive Web App completa, con manifest dinamico, service worker virtuale dalla root, caching offline intelligente e piena compatibilità con WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            PWA Core
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pwa-core
 *
 * @package PWACore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * COSTANTI
 * ============================================================ */
define( 'PWA_CORE_VERSION', '1.0.0' );
define( 'PWA_CORE_FILE', __FILE__ );
define( 'PWA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PWA_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'PWA_CORE_BASENAME', plugin_basename( __FILE__ ) );

/* ============================================================
 * REQUIREMENTS CHECK
 * ============================================================ */

/**
 * Verifica i requisiti minimi del plugin. Ritorna true se OK, altrimenti
 * stampa un admin notice e ritorna false.
 */
function pwa_core_check_requirements(): bool {
	$errors = [];

	if ( PHP_VERSION_ID < 80000 ) {
		$errors[] = sprintf(
			/* translators: %s: PHP version */
			__( 'PWA Core richiede PHP 8.0 o superiore. Versione attuale: %s', 'pwa-core' ),
			PHP_VERSION
		);
	}

	// HTTPS è richiesto dai browser per registrare un Service Worker.
	if ( ! is_ssl() && ! pwa_core_is_local_dev() ) {
		$errors[] = __( 'PWA Core richiede HTTPS attivo per funzionare. I Service Worker non si registrano su connessioni non sicure.', 'pwa-core' );
	}

	if ( empty( $errors ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () use ( $errors ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			foreach ( $errors as $err ) {
				echo '<div class="notice notice-error"><p><strong>PWA Core:</strong> ' . esc_html( $err ) . '</p></div>';
			}
		}
	);

	return false;
}

/**
 * Localhost / sviluppo: permettiamo HTTP per testing.
 */
function pwa_core_is_local_dev(): bool {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$host = strtolower( $host );
	return 'localhost' === $host
		|| '127.0.0.1' === $host
		|| str_ends_with( $host, '.local' )
		|| str_ends_with( $host, '.test' );
}

/* ============================================================
 * AUTOLOAD
 * ============================================================ */
require_once PWA_CORE_DIR . 'includes/class-pwa-core-plugin.php';
require_once PWA_CORE_DIR . 'includes/class-pwa-manifest.php';
require_once PWA_CORE_DIR . 'includes/class-pwa-service-worker.php';
require_once PWA_CORE_DIR . 'includes/class-pwa-admin.php';
require_once PWA_CORE_DIR . 'includes/class-pwa-offline.php';
require_once PWA_CORE_DIR . 'includes/class-pwa-shortcodes.php';

/* ============================================================
 * BOOT
 * ============================================================ */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! pwa_core_check_requirements() ) {
			return;
		}
		PWA_Core_Plugin::instance()->boot();
	}
);

/* ============================================================
 * ACTIVATION / DEACTIVATION
 * ============================================================ */
register_activation_hook(
	__FILE__,
	static function (): void {
		// Re-check requisiti al momento dell'attivazione.
		if ( PHP_VERSION_ID < 80000 ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'PWA Core richiede PHP 8.0 o superiore.', 'pwa-core' ),
				'',
				[ 'back_link' => true ]
			);
		}

		PWA_Offline::create_offline_page();
		PWA_Core_Plugin::instance()->register_rewrites();
		flush_rewrite_rules( false );
		update_option( PWA_Core_Plugin::REWRITE_OPT_KEY, PWA_Core_Plugin::REWRITE_VERSION );

		// Inizializza il timestamp di cache version se non presente.
		if ( false === get_option( PWA_Core_Plugin::CACHE_TIMESTAMP_KEY ) ) {
			update_option( PWA_Core_Plugin::CACHE_TIMESTAMP_KEY, time() );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules( false );
	}
);
