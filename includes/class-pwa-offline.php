<?php
/**
 * Gestione della pagina offline (fallback).
 *
 * @package PWACore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PWA_Offline {

	public static function register(): void {
		add_action( 'parse_request', [ self::class, 'maybe_render_fallback' ], 1 );
	}

	public static function create_offline_page(): void {
		$options = PWA_Core_Plugin::get_options();

		if ( ! empty( $options['offline_page_id'] ) ) {
			$existing = get_post( (int) $options['offline_page_id'] );
			if (
				$existing instanceof WP_Post
				&& 'page' === $existing->post_type
				&& 'publish' === $existing->post_status
			) {
				return;
			}
		}

		$author_id = get_current_user_id();
		if ( $author_id <= 0 || ! get_userdata( $author_id ) ) {
			$admins    = get_users(
				[
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				]
			);
			$author_id = ! empty( $admins ) ? (int) $admins[0] : 0;
		}

		$page_data = [
			'post_title'   => __( 'Sei offline', 'pwa-core' ),
			'post_name'    => 'pwa-core-offline',
			'post_content' => self::get_default_offline_content(),
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'meta_input'   => [
				'_pwa_core_offline_page' => '1',
			],
		];
		if ( $author_id > 0 ) {
			$page_data['post_author'] = $author_id;
		}

		$page_id = wp_insert_post( $page_data, true );
		if ( is_wp_error( $page_id ) || ! is_int( $page_id ) || $page_id <= 0 ) {
			return;
		}

		$options['offline_page_id'] = $page_id;
		PWA_Core_Plugin::update_options( $options );
	}

	private static function get_default_offline_content(): string {
		$home    = esc_url( home_url( '/' ) );
		$heading = esc_html__( 'Sei offline', 'pwa-core' );
		$par1    = esc_html__( 'Sembra che la connessione internet non sia disponibile in questo momento.', 'pwa-core' );
		$par2    = esc_html__( 'Puoi continuare a navigare tra le pagine che hai già visitato, oppure torna alla home quando torni online.', 'pwa-core' );
		$btn     = esc_html__( 'Torna alla home', 'pwa-core' );

		return '<!-- wp:heading {"level":1} --><h1>' . $heading . '</h1><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>' . $par1 . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>' . $par2 . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
			. '<div class="wp-block-button"><a class="wp-block-button__link" href="' . $home . '">' . $btn . '</a></div>'
			. '<!-- /wp:button --></div><!-- /wp:buttons -->';
	}

	/**
	 * @param WP $wp
	 */
	public static function maybe_render_fallback( $wp ): void {
		if ( ! ( $wp instanceof WP ) ) {
			return;
		}

		$flag = '';
		if ( isset( $wp->query_vars['pwa_core_offline'] ) ) {
			$flag = (string) $wp->query_vars['pwa_core_offline'];
		} elseif ( isset( $_GET['pwa_core_offline'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = $_GET['pwa_core_offline']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $raw ) ) {
				$flag = sanitize_text_field( wp_unslash( $raw ) );
			}
		}

		if ( '1' !== $flag ) {
			return;
		}

		$options = PWA_Core_Plugin::get_options();
		$page_id = (int) ( $options['offline_page_id'] ?? 0 );

		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				// Anti-loop: non redirigere verso una URL che conterrebbe
				// di nuovo il query param pwa_core_offline (loop 302 infinito).
				$dest_parsed = wp_parse_url( $url );
				$dest_query  = $dest_parsed['query'] ?? '';
				if ( strpos( $dest_query, 'pwa_core_offline' ) === false ) {
					wp_safe_redirect( $url );
					exit;
				}
			}
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );

		$locale_raw = (string) get_locale();
		$lang_full  = '' !== $locale_raw ? str_replace( '_', '-', $locale_raw ) : 'en';
		$lang_attr  = esc_attr( $lang_full );

		$home  = esc_url( home_url( '/' ) );
		$title = esc_html__( 'Sei offline', 'pwa-core' );
		$body  = esc_html__( 'Sembra che la connessione non sia disponibile. Quando torni online, ricarica la pagina.', 'pwa-core' );
		$retry = esc_html__( 'Torna alla home', 'pwa-core' );
		$dir   = is_rtl() ? 'rtl' : 'ltr';

		echo '<!DOCTYPE html>';
		echo '<html lang="' . $lang_attr . '" dir="' . esc_attr( $dir ) . '">';
		echo '<head>';
		echo '<meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<meta name="robots" content="noindex">';
		echo '<title>' . $title . '</title>';
		echo '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:600px;margin:4rem auto;padding:0 1rem;text-align:center;color:#333}h1{font-size:2rem}.btn{display:inline-block;margin-top:1rem;padding:.75rem 1.5rem;border-radius:6px;background:#2271b1;color:#fff;text-decoration:none}</style>';
		echo '</head>';
		echo '<body>';
		echo '<h1>' . $title . '</h1>';
		echo '<p>' . $body . '</p>';
		echo '<p><a class="btn" href="' . $home . '">' . $retry . '</a></p>';
		echo '</body></html>';
		exit;
	}
}
