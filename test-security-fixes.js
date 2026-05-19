/**
 * Test script to verify security fixes in the PWA Core plugin code
 */

const fs = require('fs');
const path = require('path');

let passed = 0;
let failed = 0;

function test(name, condition, message) {
    if (condition) {
        console.log(`✓ ${name}`);
        passed++;
    } else {
        console.log(`✗ ${name}: ${message}`);
        failed++;
    }
}

// Read files
const shortcodesContent = fs.readFileSync(path.join(__dirname, 'includes/class-pwa-shortcodes.php'), 'utf8');
const appJsContent = fs.readFileSync(path.join(__dirname, 'assets/js/app.js'), 'utf8');
const mainPluginContent = fs.readFileSync(path.join(__dirname, 'pwa-core-plugin.php'), 'utf8');
const swContent = fs.readFileSync(path.join(__dirname, 'includes/class-pwa-service-worker.php'), 'utf8');
const offlineContent = fs.readFileSync(path.join(__dirname, 'includes/class-pwa-offline.php'), 'utf8');

console.log('\n=== Security Fix Verification Tests ===\n');

// Test 1: Shortcode uses sanitize_text_field instead of wp_kses_post for label
test(
    'Shortcode: sanitize_text_field used for label',
    shortcodesContent.includes('sanitize_text_field($atts[\'label\'])') && 
    !shortcodesContent.includes('wp_kses_post($atts[\'label\'])'),
    'Label should use sanitize_text_field, not wp_kses_post'
);

// Test 2: Shortcode uses esc_html for button content
test(
    'Shortcode: esc_html used for button output',
    shortcodesContent.includes('esc_html($label)'),
    'Button content should be escaped with esc_html'
);

// Test 3: app.js has t() helper function
test(
    'app.js: t() helper function exists',
    appJsContent.includes('function t(key)'),
    't() helper should exist for safe i18n access'
);

// Test 4: app.js t() function has defaults fallback
test(
    'app.js: t() has default fallbacks',
    appJsContent.includes('const defaults = {') && appJsContent.includes('online: \'Online\''),
    't() should have English fallbacks'
);

// Test 5: app.js uses t() for online/offline strings
test(
    'app.js: t() used for connection status',
    appJsContent.includes("t('online')") && appJsContent.includes("t('offline')"),
    'Connection status should use t() helper'
);

// Test 6: Main plugin has safe_str() method
test(
    'Main plugin: safe_str() static method exists',
    mainPluginContent.includes('public static function safe_str('),
    'safe_str() helper should exist'
);

// Test 7: Main plugin has safe_int() method
test(
    'Main plugin: safe_int() static method exists',
    mainPluginContent.includes('public static function safe_int('),
    'safe_int() helper should exist'
);

// Test 8: safe_str checks for arrays
test(
    'safe_str: array check present',
    mainPluginContent.includes('is_array($value)'),
    'safe_str should check for arrays'
);

// Test 9: safe_int checks for arrays
test(
    'safe_int: array check present',
    mainPluginContent.includes('is_array($value)'),
    'safe_int should check for arrays'
);

// Test 10: Service Worker validates wp_parse_url result
test(
    'Service Worker: validates wp_parse_url result',
    swContent.includes('is_array($parsed_url)') && swContent.includes('is_string($parsed_url[\'host\'])'),
    'SW should validate wp_parse_url result'
);

// Test 11: Service Worker validates port as int
test(
    'Service Worker: validates port as int',
    swContent.includes('is_int($parsed_url[\'port\'])'),
    'SW should check port is int before using'
);

// Test 12: Offline class validates query_var strictly
test(
    'Offline: strict query_var validation',
    offlineContent.includes('\'1\' !== $query_var && true !== $query_var'),
    'Offline should strictly validate query_var'
);

// Test 13: Offline class sanitizes locale
test(
    'Offline: locale sanitization',
    offlineContent.includes('get_safe_locale') && offlineContent.includes('is_string($locale)'),
    'Offline should sanitize and validate locale'
);

// Test 14: Manifest has Cache-Control header
test(
    'Manifest: Cache-Control header set',
    mainPluginContent.includes('Cache-Control: public, max-age=300') || 
    fs.readFileSync(path.join(__dirname, 'includes/class-pwa-manifest.php'), 'utf8').includes('Cache-Control: public, max-age=300'),
    'Manifest should have 5-minute cache header'
);

// Test 15: No direct config.i18n.X access without protection
const unprotectedI18n = appJsContent.match(/config\.i18n\.[a-zA-Z]+(?!\s*\|\||\s*&&)/g) || [];
const protectedCount = appJsContent.match(/config\.i18n\[/g)?.length || 0;
test(
    'app.js: No unprotected config.i18n access',
    unprotectedI18n.length <= 1, // Allow 1 for the check inside t()
    `Found ${unprotectedI18n.length} unprotected accesses: ${unprotectedI18n.join(', ')}`
);

console.log('\n=== Summary ===');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
console.log(`Total: ${passed + failed}`);

if (failed > 0) {
    process.exit(1);
} else {
    console.log('\n✓ All security tests passed!');
    process.exit(0);
}
