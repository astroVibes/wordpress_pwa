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
