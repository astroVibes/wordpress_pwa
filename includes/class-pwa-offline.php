<?php
/**
 * Offline page handler for PWA Core Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PWA Offline class
 */
class PWA_Offline {

    /**
     * Initialize offline functionality
     */
    public static function init(): void {
        add_action('init', [self::class, 'register_offline_endpoint']);
        add_filter('query_vars', [self::class, 'add_offline_query_var']);
        add_action('template_redirect', [self::class, 'handle_offline_request']);
    }

    /**
     * Register offline rewrite rule
     */
    public static function register_offline_endpoint(): void {
        add_rewrite_rule('^offline/?$', 'index.php?pwa_core_offline=1', 'top');
    }

    /**
     * Add offline query var
     * 
     * @param array $vars Existing query vars
     * @return array Modified query vars
     */
    public static function add_offline_query_var(array $vars): array {
        $vars[] = 'pwa_core_offline';
        return $vars;
    }

    /**
     * Handle offline request
     */
    public static function handle_offline_request(): void {
        $query_var = get_query_var('pwa_core_offline');
        
        // SECURITY FIX: Strict validation - only accept exactly '1' or boolean true
        // This prevents any array or unexpected values from being processed
        if ('1' !== $query_var && true !== $query_var) {
            return;
        }

        // Get options safely
        $options = get_option('pwa_core_options', []);
        if (!is_array($options)) {
            $options = [];
        }

        // SECURITY FIX: Use safe_int to handle potentially corrupted DB values
        $offline_page_id = self::safe_int($options['offline_page_id'] ?? 0, 0);

        // Try to load custom offline page
        if ($offline_page_id > 0) {
            $post = get_post($offline_page_id);
            if ($post && 'publish' === $post->post_status) {
                // Load theme template
                include get_locate_template(['page.php', 'index.php']);
                exit;
            }
        }

        // Render default offline page
        self::render_default_offline();
        exit;
    }

    /**
     * Render default offline page
     */
    private static function render_default_offline(): void {
        // Sanitize locale for BCP47 compliance
        $locale = self::get_safe_locale();
        $lang_attr = esc_attr($locale);
        
        ?>
<!DOCTYPE html>
<html <?php echo 'lang="' . $lang_attr . '"'; ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php esc_html_e('Offline - PWA', 'pwa-core'); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #f5f5f5;
            color: #333;
            text-align: center;
            padding: 20px;
        }
        .container { max-width: 400px; }
        h1 { font-size: 24px; margin-bottom: 16px; color: #1a1a1a; }
        p { font-size: 16px; line-height: 1.6; color: #666; margin-bottom: 24px; }
        .icon { font-size: 64px; margin-bottom: 24px; }
        button {
            background: #007cba;
            color: white;
            border: none;
            padding: 12px 32px;
            font-size: 16px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover { background: #005a87; }
        button:focus { outline: 2px solid #007cba; outline-offset: 2px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">📡</div>
        <h1><?php esc_html_e('You are offline', 'pwa-core'); ?></h1>
        <p><?php esc_html_e('Please check your internet connection and try again.', 'pwa-core'); ?></p>
        <button type="button" onclick="location.reload()">
            <?php esc_html_e('Retry', 'pwa-core'); ?>
        </button>
    </div>
</body>
</html>
        <?php
    }

    /**
     * Get sanitized locale string (BCP47 compliant)
     * 
     * @return string Safe locale string
     */
    private static function get_safe_locale(): string {
        $locale = get_locale();
        
        // SECURITY FIX: Validate locale is a string before processing
        if (!is_string($locale)) {
            return 'en_US';
        }
        
        // Normalize to BCP47 format (e.g., en_US -> en-US)
        $locale = str_replace('_', '-', $locale);
        
        // Only allow alphanumeric characters and hyphens
        $locale = preg_replace('/[^a-zA-Z0-9-]/', '', $locale);
        
        // Ensure it's not empty after sanitization
        if (empty($locale)) {
            return 'en_US';
        }
        
        return $locale;
    }

    /**
     * Safe integer conversion helper
     * 
     * @param mixed $value The value to convert
     * @param int $default Default value
     * @return int Safe integer
     */
    private static function safe_int($value, int $default = 0): int {
        if (null === $value || is_array($value)) {
            return $default;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Get the offline URL
     * 
     * @return string Offline page URL
     */
    public static function get_url(): string {
        return home_url('/offline/');
    }
}

// Initialize offline functionality
PWA_Offline::init();
