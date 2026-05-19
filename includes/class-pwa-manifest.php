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
}
