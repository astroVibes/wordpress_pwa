<?php
/**
 * Shortcode per il pulsante di installazione PWA.
 *
 * Shortcode disponibili:
 *   [pwa_install_button]
 *   [pwa_install_button label="Installa l'app" class="my-btn" show_if_installed="no"]
 *
 *   [pwa_install_status]  — mostra testo diverso in base allo stato (installata / non installata)
 *
 * Il rendering lato server emette solo l'HTML strutturale. Il JS in app.js
 * gestisce la visibilità reale dopo il check dello stato di installazione.
 *
 * @package PWACore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PWA_Shortcodes {

	public static function register(): void {
		add_shortcode( 'pwa_install_button', [ self::class, 'render_install_button' ] );
		add_shortcode( 'pwa_install_status', [ self::class, 'render_install_status' ] );
	}

	/**
	 * Shortcode [pwa_install_button]
	 *
	 * Attributi:
	 *   label          Testo del pulsante. Default: "Installa l'app"
	 *   label_ios      Testo alternativo su iOS Safari (no supporto prompt nativo).
	 *                  Default: "Aggiungi alla schermata Home"
	 *   class          Classi CSS aggiuntive da applicare al pulsante.
	 *   tag            Tag HTML del pulsante: "button" o "a". Default: "button"
	 *   installed_text Testo da mostrare se l'app è già installata (lasciare vuoto per nascondere).
	 *                  Default: "" (nasconde il pulsante se installata)
	 *
	 * Comportamento:
	 *   - Se la PWA è già installata: nasconde il pulsante (o mostra installed_text).
	 *   - Se il browser non supporta l'install prompt (es. Firefox desktop, Safari iOS < 16.4):
	 *     mostra il pulsante con istruzioni manuali per iOS.
	 *   - Cliccando il pulsante: richiama window.PWACore.triggerInstall().
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function render_install_button( $atts ): string {
		if ( ! is_array( $atts ) ) {
			$atts = [];
		}

		$options = PWA_Core_Plugin::get_options();
		if ( empty( $options['enable_install_prompt'] ) ) {
			return '';
		}

		$defaults = [
			'label'          => __( 'Installa l\'app', 'pwa-core' ),
			'label_ios'      => __( 'Aggiungi alla schermata Home', 'pwa-core' ),
			'class'          => '',
			'tag'            => 'button',
			'installed_text' => '',
		];
		$a = shortcode_atts( $defaults, $atts, 'pwa_install_button' );

		// Sanifica ogni attributo.
		$label          = wp_kses_post( (string) $a['label'] );
		$label_ios      = wp_kses_post( (string) $a['label_ios'] );
		$installed_text = wp_kses_post( (string) $a['installed_text'] );
		// Supporta più classi separate da spazio: sanifica ciascuna individualmente.
		$extra_class    = implode( ' ', array_filter( array_map(
			'sanitize_html_class',
			preg_split( '/\s+/', trim( (string) $a['class'] ) ) ?: []
		) ) );
		$tag            = in_array( (string) $a['tag'], [ 'button', 'a' ], true ) ? (string) $a['tag'] : 'button';

		$classes = 'pwa-core-install-trigger';
		if ( '' !== $extra_class ) {
			$classes .= ' ' . $extra_class;
		}

		/*
		 * Usiamo attributi data- per passare i testi al JS senza inline script.
		 * JS in app.js legge questi attributi e gestisce la visibilità.
		 *
		 * data-pwa-install="1"      → marcatore per il JS (selettore)
		 * data-label                → testo standard
		 * data-label-ios            → testo su iOS Safari
		 * data-installed-text       → testo se già installata ("" = nascondi)
		 */
		$data_attrs = sprintf(
			'data-pwa-install="1" data-label="%s" data-label-ios="%s" data-installed-text="%s"',
			esc_attr( $label ),
			esc_attr( $label_ios ),
			esc_attr( $installed_text )
		);

		ob_start();

		if ( 'a' === $tag ) {
			?>
			<a href="#pwa-install"
			   class="<?php echo esc_attr( $classes ); ?>"
			   role="button"
			   <?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<?php
		} else {
			?>
			<button type="button"
			        class="<?php echo esc_attr( $classes ); ?>"
			        <?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [pwa_install_status]
	 *
	 * Attributi:
	 *   installed     Testo da mostrare se l'app è installata.
	 *                 Default: "L'app è già installata sul tuo dispositivo."
	 *   not_installed Testo da mostrare se l'app NON è installata.
	 *                 Default: "" (non mostra nulla)
	 *   class         Classi CSS aggiuntive per il wrapper.
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function render_install_status( $atts ): string {
		if ( ! is_array( $atts ) ) {
			$atts = [];
		}

		$defaults = [
			'installed'     => __( 'L\'app è già installata sul tuo dispositivo.', 'pwa-core' ),
			'not_installed' => '',
			'class'         => '',
		];
		$a = shortcode_atts( $defaults, $atts, 'pwa_install_status' );

		$installed     = wp_kses_post( (string) $a['installed'] );
		$not_installed = wp_kses_post( (string) $a['not_installed'] );
		$extra_class   = implode( ' ', array_filter( array_map(
			'sanitize_html_class',
			preg_split( '/\s+/', trim( (string) $a['class'] ) ) ?: []
		) ) );

		$classes = 'pwa-core-install-status';
		if ( '' !== $extra_class ) {
			$classes .= ' ' . $extra_class;
		}

		/*
		 * Il wrapper viene renderizzato nascosto di default.
		 * JS aggiornerà il testo e rimuoverà la classe 'is-hidden'
		 * dopo aver verificato lo stato di installazione.
		 *
		 * data-pwa-status="1"       → marcatore per il JS
		 * data-installed            → testo se installata
		 * data-not-installed        → testo se non installata
		 */
		return sprintf(
			'<span class="%s is-hidden" data-pwa-status="1" data-installed="%s" data-not-installed="%s"></span>',
			esc_attr( $classes ),
			esc_attr( $installed ),
			esc_attr( $not_installed )
		);
	}
}
