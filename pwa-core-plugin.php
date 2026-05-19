<?php
/**
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
