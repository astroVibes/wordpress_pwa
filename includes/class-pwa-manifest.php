<?php
/**
 * Manifest generator for PWA Core Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PWA Manifest class
 */
class PWA_Manifest {

    private array $options;

    public function __construct() {
        $this->options = get_option('pwa_core_options', []);
        if (!is_array($this->options)) {
            $this->options = [];
        }
    }

    /**
     * Serve the manifest JSON
     */
    public function serve(): void {
        // Set headers with 5-minute cache for reduced server load
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Vary: Accept');

        echo wp_json_encode($this->get_manifest_data());
        exit;
    }

    /**
     * Get manifest data as array
     * 
     * @return array Manifest data
     */
    public function get_manifest_data(): array {
        $app_name = $this->safe_str($this->options['app_name'] ?? get_bloginfo('name'), get_bloginfo('name'));
        $app_short_name = $this->safe_str($this->options['app_short_name'] ?? get_bloginfo('name'), get_bloginfo('name'));
        $app_description = $this->safe_str($this->options['app_description'] ?? get_bloginfo('description'), get_bloginfo('description'));
        $theme_color = $this->safe_str($this->options['theme_color'] ?? '#ffffff', '#ffffff');
        $background_color = $this->safe_str($this->options['background_color'] ?? '#ffffff', '#ffffff');
        $display = $this->safe_str($this->options['display'] ?? 'standalone', 'standalone');
        $orientation = $this->safe_str($this->options['orientation'] ?? 'any', 'any');
        $icon_192 = $this->safe_str($this->options['icon_192'] ?? '', '');
        $icon_512 = $this->safe_str($this->options['icon_512'] ?? '', '');

        $manifest = [
            'name' => $app_name,
            'short_name' => $app_short_name,
            'description' => $app_description,
            'start_url' => home_url('/'),
            'scope' => home_url('/'),
            'display' => $display,
            'orientation' => $orientation,
            'theme_color' => $theme_color,
            'background_color' => $background_color,
            'icons' => [],
        ];

        // Add 192x192 icon
        if (!empty($icon_192)) {
            $manifest['icons'][] = [
                'src' => esc_url_raw($icon_192),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        } else {
            $manifest['icons'][] = [
                'src' => includes_url('images/w-logo-blue.png'),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        }

        // Add 512x512 icon
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

        return $manifest;
    }

    /**
     * Safe string conversion helper
     * 
     * @param mixed $value The value to convert
     * @param string $default Default value
     * @return string Safe string
     */
    private function safe_str($value, string $default = ''): string {
        if (null === $value || is_array($value)) {
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
     * Get the manifest URL
     * 
     * @return string Manifest URL
     */
    public function get_url(): string {
        return rest_url('pwa-core/v1/manifest');
    }
=======
 * Gestione del Web App Manifest.
 *
 * @package PWACore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PWA_Manifest {

	public const REST_NAMESPACE = 'pwa-core/v1';
	public const REST_ROUTE     = '/manifest';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_rest_route' ] );
	}

	public static function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'rest_callback' ],
				'permission_callback' => '__return_true',
				'args'                => [],
			]
		);
	}

	public static function rest_callback( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$response = new WP_REST_Response( self::build(), 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	public static function serve(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		$manifest = self::build();

		$json = wp_json_encode(
			$manifest,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);

		if ( false === $json ) {
			$json = '{"name":"PWA","start_url":"/","display":"standalone"}';
		}

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Costruisce l'array del manifest con DEFENSE-IN-DEPTH:
	 * tutti i valori sono ri-validati anche se provengono dal DB.
	 * Questo protegge in caso di:
	 * - dati corrotti nel DB
	 * - update_option() chiamato da altro codice malevolo
	 * - filter hook procida_pwa_manifest che inietta valori non sicuri
	 *
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$options = PWA_Core_Plugin::get_options();

		// Helper inline: estrae stringa sicura da opzione, ritorna '' se non è stringa.
		// Evita warning "Array to string conversion" su DB corrotto.
		$opt_str = static function ( $key, $default = '' ) use ( $options ) {
			$v = $options[ $key ] ?? $default;
			return is_string( $v ) ? $v : $default;
		};

		// === ICONE: SAME-HOST OBBLIGATORIO ===
		// Difesa-in-profondità: anche se nel DB ci sono URL esterne, le filtriamo qui.
		$icons = [];
		self::maybe_add_icon( $icons, $opt_str( 'icon_192' ), '192x192', 'any' );
		self::maybe_add_icon( $icons, $opt_str( 'icon_512' ), '512x512', 'any' );
		self::maybe_add_icon( $icons, $opt_str( 'icon_maskable' ), '512x512', 'maskable' );

		if ( empty( $icons ) ) {
			$site_icon = get_site_icon_url( 512 );
			if ( is_string( $site_icon ) && '' !== $site_icon ) {
				self::maybe_add_icon( $icons, $site_icon, '512x512', 'any' );
			}
		}

		// === COLORI ===
		$theme_color      = sanitize_hex_color( $opt_str( 'theme_color' ) );
		$background_color = sanitize_hex_color( $opt_str( 'background_color' ) );

		// === START URL: solo path relativo che inizia con / ===
		$start_url_opt = $opt_str( 'start_url', '/' );
		if ( '' === $start_url_opt || '/' !== $start_url_opt[0] ) {
			$start_url_opt = '/';
		}
		// Rifiuta start_url con caratteri sospetti (newline, null byte).
		if ( preg_match( '/[\x00-\x1F]/', $start_url_opt ) ) {
			$start_url_opt = '/';
		}

		// === DISPLAY E ORIENTATION: whitelist ===
		$allowed_displays = [ 'standalone', 'fullscreen', 'minimal-ui', 'browser' ];
		$display          = $opt_str( 'display', 'standalone' );
		if ( ! in_array( $display, $allowed_displays, true ) ) {
			$display = 'standalone';
		}

		$allowed_orientations = [ 'any', 'natural', 'landscape', 'portrait', 'portrait-primary', 'landscape-primary' ];
		$orientation          = $opt_str( 'orientation', 'portrait-primary' );
		if ( ! in_array( $orientation, $allowed_orientations, true ) ) {
			$orientation = 'portrait-primary';
		}

		// === LANG: BCP47, solo caratteri permessi ===
		$locale = get_locale();
		$locale = is_string( $locale ) ? $locale : 'en';
		$lang   = '' !== $locale ? str_replace( '_', '-', $locale ) : 'en';
		// Solo lettere/cifre/trattini per il lang tag (BCP47 grammar semplificata).
		$lang = preg_replace( '/[^a-zA-Z0-9\-]/', '', $lang );
		if ( '' === $lang ) {
			$lang = 'en';
		}

		// === TESTI: sanificati anche al build ===
		$app_name    = self::clean_text( $opt_str( 'app_name' ), 100 );
		$short_name  = self::clean_text( $opt_str( 'short_name' ), 30 );
		$description = self::clean_text( $opt_str( 'description' ), 300 );

		$manifest = [
			'id'               => $start_url_opt,
			'name'             => $app_name,
			'short_name'       => $short_name,
			'description'      => $description,
			'start_url'        => $start_url_opt,
			'scope'            => '/',
			'display'          => $display,
			'orientation'      => $orientation,
			'theme_color'      => is_string( $theme_color ) && '' !== $theme_color ? $theme_color : '#2271b1',
			'background_color' => is_string( $background_color ) && '' !== $background_color ? $background_color : '#ffffff',
			'lang'             => $lang,
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
			'icons'            => $icons,
		];

		foreach ( [ 'description', 'name', 'short_name' ] as $key ) {
			if ( '' === $manifest[ $key ] ) {
				unset( $manifest[ $key ] );
			}
		}

		/**
		 * Filter del manifest finale.
		 *
		 * @param array<string, mixed> $manifest
		 */
		$result = apply_filters( 'pwa_core_manifest', $manifest );
		return is_array( $result ) ? $result : $manifest;
	}

	/**
	 * @param array<int, array<string, string>> $icons
	 */
	private static function maybe_add_icon( array &$icons, string $url, string $sizes, string $purpose ): void {
		if ( '' === $url ) {
			return;
		}
		$clean = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $clean ) {
			return;
		}

		// DEFENSE-IN-DEPTH: rifiuta icone non same-host anche al build.
		$icon_host = wp_parse_url( $clean, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $icon_host !== $site_host ) {
			return;
		}

		$path = (string) wp_parse_url( $clean, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		$type = 'image/png';
		if ( in_array( $ext, [ 'jpg', 'jpeg' ], true ) ) {
			$type = 'image/jpeg';
		} elseif ( 'webp' === $ext ) {
			$type = 'image/webp';
		} elseif ( 'svg' === $ext ) {
			$type = 'image/svg+xml';
		}

		$icons[] = [
			'src'     => $clean,
			'sizes'   => $sizes,
			'type'    => $type,
			'purpose' => $purpose,
		];
	}

	/**
	 * Sanifica un campo testuale del manifest:
	 * - Garantisce UTF-8 valido (rimuove byte corrotti)
	 * - Rimuove tag HTML
	 * - Rimuove caratteri di controllo
	 * - Tronca a $max caratteri (Unicode-safe)
	 */
	private static function clean_text( string $value, int $max ): string {
		if ( '' === $value ) {
			return '';
		}

		// Passo 1: forza UTF-8 valido prima di qualsiasi operazione regex con /u.
		// wp_check_invalid_utf8() è disponibile da WP 2.8 — sempre presente.
		// Sostituisce sequenze di byte non valide con stringa vuota.
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value );
		} else {
			// Fallback: iconv con //IGNORE scarta i byte non convertibili.
			$converted = @iconv( 'UTF-8', 'UTF-8//IGNORE', $value );
			if ( is_string( $converted ) ) {
				$value = $converted;
			}
		}

		// Passo 2: rimuove tag HTML.
		$value = wp_strip_all_tags( $value );

		// Passo 3: rimuove caratteri di controllo (eccetto whitespace normale: TAB, LF, CR).
		// Ora il /u è sicuro perché l'input è garantito UTF-8 valido.
		$clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		// preg_replace ritorna null solo su errore regex; con input UTF-8 valido non succede.
		// Il controllo è comunque difensivo.
		$value = is_string( $clean ) ? $clean : $value;
		$value = trim( $value );

		// Passo 4: tronca a $max caratteri (Unicode-safe).
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) <= $max ) {
				return $value;
			}
			return rtrim( mb_substr( $value, 0, $max, 'UTF-8' ) );
		}
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return rtrim( substr( $value, 0, $max ) );
	}
}
