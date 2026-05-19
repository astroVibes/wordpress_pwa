<?php
/**
 * Admin interface for PWA Core Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PWA Admin class
 */
class PWA_Admin {

    /**
     * Render admin page
     * 
     * @param array $options Plugin options
     */
    public function render(array $options): void {
        ?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('pwa_core_general'); ?>
        <?php do_settings_sections('pwa_core_general'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="pwa_core_app_name"><?php esc_html_e('App Name', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="pwa_core_app_name" 
                           name="pwa_core_options[app_name]" 
                           value="<?php echo esc_attr($this->safe_str($options['app_name'] ?? '')); ?>" 
                           class="regular-text" />
                    <p class="description"><?php esc_html_e('Full name of your PWA app', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_app_short_name"><?php esc_html_e('App Short Name', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="pwa_core_app_short_name" 
                           name="pwa_core_options[app_short_name]" 
                           value="<?php echo esc_attr($this->safe_str($options['app_short_name'] ?? '')); ?>" 
                           class="regular-text" />
                    <p class="description"><?php esc_html_e('Shortened name for home screen icons', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_app_description"><?php esc_html_e('App Description', 'pwa-core'); ?></label>
                </th>
                <td>
                    <textarea id="pwa_core_app_description" 
                              name="pwa_core_options[app_description]" 
                              rows="3" 
                              class="large-text"><?php echo esc_textarea($this->safe_str($options['app_description'] ?? '')); ?></textarea>
                    <p class="description"><?php esc_html_e('Description shown in app stores and install prompts', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_theme_color"><?php esc_html_e('Theme Color', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="color" 
                           id="pwa_core_theme_color" 
                           name="pwa_core_options[theme_color]" 
                           value="<?php echo esc_attr($this->safe_str($options['theme_color'] ?? '#ffffff')); ?>" 
                           class="pwa-color-picker" />
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_background_color"><?php esc_html_e('Background Color', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="color" 
                           id="pwa_core_background_color" 
                           name="pwa_core_options[background_color]" 
                           value="<?php echo esc_attr($this->safe_str($options['background_color'] ?? '#ffffff')); ?>" 
                           class="pwa-color-picker" />
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_display"><?php esc_html_e('Display Mode', 'pwa-core'); ?></label>
                </th>
                <td>
                    <select id="pwa_core_display" name="pwa_core_options[display]">
                        <option value="standalone" <?php selected($this->safe_str($options['display'] ?? ''), 'standalone'); ?>><?php esc_html_e('Standalone', 'pwa-core'); ?></option>
                        <option value="fullscreen" <?php selected($this->safe_str($options['display'] ?? ''), 'fullscreen'); ?>><?php esc_html_e('Fullscreen', 'pwa-core'); ?></option>
                        <option value="minimal-ui" <?php selected($this->safe_str($options['display'] ?? ''), 'minimal-ui'); ?>><?php esc_html_e('Minimal UI', 'pwa-core'); ?></option>
                        <option value="browser" <?php selected($this->safe_str($options['display'] ?? ''), 'browser'); ?>><?php esc_html_e('Browser', 'pwa-core'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_orientation"><?php esc_html_e('Orientation', 'pwa-core'); ?></label>
                </th>
                <td>
                    <select id="pwa_core_orientation" name="pwa_core_options[orientation]">
                        <option value="any" <?php selected($this->safe_str($options['orientation'] ?? ''), 'any'); ?>><?php esc_html_e('Any', 'pwa-core'); ?></option>
                        <option value="natural" <?php selected($this->safe_str($options['orientation'] ?? ''), 'natural'); ?>><?php esc_html_e('Natural', 'pwa-core'); ?></option>
                        <option value="portrait" <?php selected($this->safe_str($options['orientation'] ?? ''), 'portrait'); ?>><?php esc_html_e('Portrait', 'pwa-core'); ?></option>
                        <option value="landscape" <?php selected($this->safe_str($options['orientation'] ?? ''), 'landscape'); ?>><?php esc_html_e('Landscape', 'pwa-core'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_icon_192"><?php esc_html_e('Icon 192x192', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="url" 
                           id="pwa_core_icon_192" 
                           name="pwa_core_options[icon_192]" 
                           value="<?php echo esc_url($this->safe_str($options['icon_192'] ?? '')); ?>" 
                           class="large-text" />
                    <p class="description"><?php esc_html_e('URL to 192x192 PNG icon', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_icon_512"><?php esc_html_e('Icon 512x512', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="url" 
                           id="pwa_core_icon_512" 
                           name="pwa_core_options[icon_512]" 
                           value="<?php echo esc_url($this->safe_str($options['icon_512'] ?? '')); ?>" 
                           class="large-text" />
                    <p class="description"><?php esc_html_e('URL to 512x512 PNG icon', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_offline_page_id"><?php esc_html_e('Offline Page', 'pwa-core'); ?></label>
                </th>
                <td>
                    <?php
                    wp_dropdown_pages([
                        'name' => 'pwa_core_options[offline_page_id]',
                        'selected' => $this->safe_int($options['offline_page_id'] ?? 0, 0),
                        'show_option_none' => __('Default offline page', 'pwa-core'),
                    ]);
                    ?>
                    <p class="description"><?php esc_html_e('Custom page to show when offline', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_cache_pages_limit"><?php esc_html_e('Cache Pages Limit', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="number" 
                           id="pwa_core_cache_pages_limit" 
                           name="pwa_core_options[cache_pages_limit]" 
                           value="<?php echo esc_attr($this->safe_int($options['cache_pages_limit'] ?? 50, 50)); ?>" 
                           min="1" max="500" class="small-text" />
                    <p class="description"><?php esc_html_e('Maximum number of pages to cache (1-500)', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="pwa_core_cache_size_limit_mb"><?php esc_html_e('Cache Size Limit (MB)', 'pwa-core'); ?></label>
                </th>
                <td>
                    <input type="number" 
                           id="pwa_core_cache_size_limit_mb" 
                           name="pwa_core_options[cache_size_limit_mb]" 
                           value="<?php echo esc_attr($this->safe_int($options['cache_size_limit_mb'] ?? 100, 100)); ?>" 
                           min="10" max="1000" class="small-text" />
                    <p class="description"><?php esc_html_e('Maximum cache size in MB (10-1000)', 'pwa-core'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php esc_html_e('Features', 'pwa-core'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e('PWA Features', 'pwa-core'); ?></legend>
                        <label>
                            <input type="checkbox" 
                                   name="pwa_core_options[enable_offline]" 
                                   value="1" <?php checked($this->safe_bool($options['enable_offline'] ?? true, true)); ?> />
                            <?php esc_html_e('Enable offline support', 'pwa-core'); ?>
                        </label><br/>
                        <label>
                            <input type="checkbox" 
                                   name="pwa_core_options[enable_install_prompt]" 
                                   value="1" <?php checked($this->safe_bool($options['enable_install_prompt'] ?? true, true)); ?> />
                            <?php esc_html_e('Show install prompt', 'pwa-core'); ?>
                        </label><br/>
                        <label>
                            <input type="checkbox" 
                                   name="pwa_core_options[enable_online_indicator]" 
                                   value="1" <?php checked($this->safe_bool($options['enable_online_indicator'] ?? false, false)); ?> />
                            <?php esc_html_e('Show online/offline indicator', 'pwa-core'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
</div>
        <?php
    }

    /**
     * Safe string conversion helper for admin forms
     * 
     * @param mixed $value The value to convert
     * @param string $default Default value
     * @return string Safe string
     */
    private function safe_str($value, string $default = ''): string {
        return PWA_Core_Plugin::safe_str($value, $default);
    }

    /**
     * Safe integer conversion helper for admin forms
     * 
     * @param mixed $value The value to convert
     * @param int $default Default value
     * @return int Safe integer
     */
    private function safe_int($value, int $default = 0): int {
        return PWA_Core_Plugin::safe_int($value, $default);
    }

    /**
     * Safe boolean conversion helper for admin forms
     * 
     * @param mixed $value The value to convert
     * @param bool $default Default value
     * @return bool Safe boolean
     */
    private function safe_bool($value, bool $default = false): bool {
        return PWA_Core_Plugin::safe_bool($value, $default);
    }
}
