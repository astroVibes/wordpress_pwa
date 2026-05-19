<?php
/**
 * Shortcodes for PWA Core Plugin
 * 
 * Provides shortcodes for install buttons and status indicators.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PWA Shortcodes class
 */
class PWA_Shortcodes {

    public function __construct() {
        add_shortcode('pwa_install_button', [$this, 'render_install_button']);
        add_shortcode('pwa_install_status', [$this, 'render_install_status']);
    }

    /**
     * Render install button shortcode
     * 
     * Usage: [pwa_install_button label="Install App" class="my-button"]
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_install_button(array $atts): string {
        $atts = shortcode_atts([
            'label' => __('Install App', 'pwa-core'),
            'class' => '',
            'style' => '',
        ], $atts, 'pwa_install_button');

        // SECURITY FIX: Use sanitize_text_field instead of wp_kses_post
        // wp_kses_post allows closing tags like </button> which can break DOM
        // and enable XSS attacks. Since button content should be plain text,
        // we use sanitize_text_field + esc_html for maximum safety.
        $label = sanitize_text_field($atts['label']);
        $class = sanitize_text_field($atts['class']);
        $style = sanitize_text_field($atts['style']);

        $output = '<button type="button" class="pwa-install-button ' . esc_attr($class) . '"';
        
        if (!empty($style)) {
            $output .= ' style="' . esc_attr($style) . '"';
        }
        
        $output .= ' data-action="install"';
        $output .= ' aria-label="' . esc_attr($label) . '"';
        $output .= '>';
        
        // SECURITY FIX: Use esc_html instead of echo to prevent any HTML injection
        // Even though sanitize_text_field cleans the input, esc_html provides defense in depth
        $output .= esc_html($label);
        
        $output .= '</button>';

        return $output;
    }

    /**
     * Render install status indicator shortcode
     * 
     * Usage: [pwa_install_status show_label="true"]
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_install_status(array $atts): string {
        $atts = shortcode_atts([
            'show_label' => 'true',
            'class' => '',
        ], $atts, 'pwa_install_status');

        $show_label = filter_var($atts['show_label'], FILTER_VALIDATE_BOOLEAN);
        $class = sanitize_text_field($atts['class']);

        $output = '<div class="pwa-install-status ' . esc_attr($class) . '"';
        $output .= ' data-component="install-status"';
        $output .= '>';
        
        if ($show_label) {
            // SECURITY FIX: Use esc_html for the status label text
            $output .= '<span class="pwa-install-status__label">';
            $output .= esc_html__('Installation status will appear here', 'pwa-core');
            $output .= '</span>';
        }
        
        $output .= '<span class="pwa-install-status__indicator"></span>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render online/offline indicator shortcode
     * 
     * Usage: [pwa_connection_indicator online_text="Online" offline_text="Offline"]
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_connection_indicator(array $atts): string {
        $atts = shortcode_atts([
            'online_text' => __('Online', 'pwa-core'),
            'offline_text' => __('Offline', 'pwa-core'),
            'class' => '',
            'show_text' => 'true',
        ], $atts, 'pwa_connection_indicator');

        // SECURITY FIX: Use sanitize_text_field for all text attributes
        $online_text = sanitize_text_field($atts['online_text']);
        $offline_text = sanitize_text_field($atts['offline_text']);
        $class = sanitize_text_field($atts['class']);
        $show_text = filter_var($atts['show_text'], FILTER_VALIDATE_BOOLEAN);

        $output = '<div class="pwa-connection-indicator ' . esc_attr($class) . '"';
        $output .= ' data-component="connection-indicator"';
        $output .= ' data-online="' . esc_attr($online_text) . '"';
        $output .= ' data-offline="' . esc_attr($offline_text) . '"';
        $output .= '>';
        
        if ($show_text) {
            $output .= '<span class="pwa-connection-indicator__text">';
            // SECURITY FIX: Use esc_html for output
            $output .= esc_html($online_text);
            $output .= '</span>';
        }
        
        $output .= '<span class="pwa-connection-indicator__dot"></span>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Register all shortcodes
     */
    public static function register(): void {
        $shortcodes = new self();
    }
}

// Initialize shortcodes
add_action('init', [PWA_Shortcodes::class, 'register']);
=======
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
