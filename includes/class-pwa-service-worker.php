<?php
/**
 * Gestione del Service Worker.
 *
 * @package PWACore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PWA_Service_Worker {

	public static function register(): void {}

	public static function serve(): void {
		$options = PWA_Core_Plugin::get_options();

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		$cache_version = sanitize_key( PWA_Core_Plugin::get_cache_version_string() );
		if ( '' === $cache_version ) {
			$cache_version = 'v1';
		}

		$site_host_raw = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site_port     = wp_parse_url( home_url(), PHP_URL_PORT );
		if ( ! empty( $site_port ) ) {
			$site_host_raw .= ':' . (int) $site_port;
		}
		$site_host = (string) preg_replace( '/[^a-zA-Z0-9.\-:]/', '', $site_host_raw );
		if ( '' === $site_host ) {
			$site_host = 'localhost';
		}

		$offline_url = self::get_offline_url();

		$precache_urls = [
			$offline_url,
			home_url( '/' ),
		];
		$precache_urls = apply_filters( 'pwa_core_precache_urls', $precache_urls );
		$precache_urls = self::sanitize_url_list( (array) $precache_urls );

		$exclude_patterns = self::get_exclude_patterns();
		$exclude_patterns = apply_filters( 'pwa_core_exclude_patterns', $exclude_patterns );
		$exclude_patterns = self::sanitize_pattern_list( (array) $exclude_patterns );

		// Limiti cache dalle opzioni (con boundaries).
		$pages_limit  = self::clamp_int( (int) ( $options['cache_pages_limit'] ?? 50 ), 10, 500 );
		$assets_limit = self::clamp_int( (int) ( $options['cache_assets_limit'] ?? 60 ), 10, 500 );
		$images_limit = self::clamp_int( (int) ( $options['cache_images_limit'] ?? 80 ), 10, 500 );

		$sw_template = self::get_sw_template();
		if ( '' === $sw_template ) {
			status_header( 500 );
			echo "// PWA Core: service worker template missing\n";
			exit;
		}

		$precache_json = wp_json_encode( array_values( $precache_urls ), JSON_UNESCAPED_SLASHES );
		if ( false === $precache_json ) {
			$precache_json = '[]';
		}
		$exclude_json = wp_json_encode( array_values( $exclude_patterns ), JSON_UNESCAPED_SLASHES );
		if ( false === $exclude_json ) {
			$exclude_json = '[]';
		}

		$replacements = [
			'__CACHE_VERSION__'      => $cache_version,
			'__SITE_HOST__'          => $site_host,
			'__OFFLINE_URL__'        => esc_url_raw( $offline_url ),
			'__PRECACHE_URLS__'      => $precache_json,
			'__EXCLUDE_PATTERNS__'   => $exclude_json,
			'__CACHE_PAGES_LIMIT__'  => (string) $pages_limit,
			'__CACHE_ASSETS_LIMIT__' => (string) $assets_limit,
			'__CACHE_IMAGES_LIMIT__' => (string) $images_limit,
		];

		$sw_code = strtr( $sw_template, $replacements );

		echo $sw_code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function clamp_int( int $value, int $min, int $max ): int {
		if ( $value < $min ) {
			return $min;
		}
		if ( $value > $max ) {
			return $max;
		}
		return $value;
	}

	/**
	 * Pattern di URL da NON cacheare mai.
	 *
	 * isExcluded() nel SW usa String.indexOf() su pathname+search, quindi ogni
	 * voce è una sottostringa. Regole di design:
	 *   - Specifici abbastanza da evitare falsi positivi (/cart/ non matcha /cartolina/)
	 *   - Generici abbastanza da coprire varianti (con/senza trailing slash, query string)
	 *
	 * Per wp-admin usiamo '/wp-admin' SENZA trailing slash:
	 *   matcha /wp-admin, /wp-admin/, /wp-admin/options-general.php ecc.
	 *
	 * @return array<int, string>
	 */
	private static function get_exclude_patterns(): array {
		return [
			// WordPress core — admin, login, API
			'/wp-admin',        // copre /wp-admin, /wp-admin/ e /wp-admin/*
			'/wp-login.php',    // copre /wp-login.php e varianti con query string
			'/wp-json/',        // REST API: sempre fresh
			'/xmlrpc.php',
			'admin-ajax.php',

			// Dashboard utente (WooCommerce, LMS, membership, plugin vari)
			'/dashboard/',      // copre /dashboard/ e /dashboard/*
			'/dashboard?',      // copre /dashboard?param= (senza trailing slash)

			// WooCommerce — pagine sensibili
			'wc-ajax=',
			'/cart/',
			'/checkout/',
			'/my-account/',
			'/order-received/',
			'/order-pay/',

			// WooCommerce — slug italiani
			'/carrello/',
			'/cassa/',
			'/mio-account/',
			'/ordine-ricevuto/',

			// Azioni carrello via query string
			'add-to-cart=',
			'add_to_wishlist',
			'remove_item=',

			// WordPress varie
			'preview=true',
			'/feed/',
			'/comments/feed/',

			// SEO / sitemap
			'sitemap.xml',
			'sitemap_index.xml',
			'robots.txt',
		];
	}

	private static function get_offline_url(): string {
		$options = PWA_Core_Plugin::get_options();
		$page_id = (int) ( $options['offline_page_id'] ?? 0 );

		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/?pwa_core_offline=1' );
	}

	private static function get_sw_template(): string {
		$path = PWA_CORE_DIR . 'assets/sw.js';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$content = file_get_contents( $path );
		return ( false === $content || '' === $content ) ? '' : $content;
	}

	/**
	 * @param array<int|string, mixed> $urls
	 * @return array<int, string>
	 */
	private static function sanitize_url_list( array $urls ): array {
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$out       = [];
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$clean = esc_url_raw( $url, [ 'http', 'https' ] );
			if ( '' === $clean ) {
				continue;
			}
			$host = wp_parse_url( $clean, PHP_URL_HOST );
			if ( $host !== $site_host ) {
				continue;
			}
			$out[] = $clean;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanifica una lista di pattern di esclusione.
	 *
	 * Caratteri rimossi (e perché):
	 *   - Controlli (\x00-\x1F, \x7F): possono rompere lo string parsing in JS
	 *   - Quote (' "): possono rompere la sintassi JSON quando incluse nel SW
	 *   - Backslash (\): può creare escape sequences imprevisti nel JSON output
	 *
	 * Caratteri PERMESSI (precedentemente rimossi per eccesso di cautela):
	 *   - [ ] { } : sono caratteri legittimi nelle URL (es. /api/{id}/users/[type])
	 *   - Il SW usa String.indexOf() non regex, quindi non hanno significato speciale
	 *
	 * @param array<int|string, mixed> $patterns
	 * @return array<int, string>
	 */
	private static function sanitize_pattern_list( array $patterns ): array {
		$out = [];
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			// Rimuove solo caratteri di controllo e quote/backslash che romperebbero il JSON.
			$clean = (string) preg_replace( '/[\x00-\x1F\x7F"\'\\\\]/', '', $pattern );
			$clean = trim( $clean );
			if ( '' === $clean || strlen( $clean ) > 200 ) {
				continue;
			}
			$out[] = $clean;
		}
		return array_values( array_unique( $out ) );
	}
}
