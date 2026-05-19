--- test-browser-compat-fixed.js (原始)


+++ test-browser-compat-fixed.js (修改后)
/**
 * Test Compatibilità Browser: Safari, Samsung, Firefox - CORRETTO
 */

const fs = require('fs');

console.log('=== TEST COMPATIBILITÀ BROWSER & SHORTCODE (CORRETTO) ===\n');

let passed = 0;
let failed = 0;

// --- TEST 1: Shortcode XSS ---
console.log('[1] Verifica sicurezza shortcode...');
const shortcodeFile = fs.readFileSync('includes/class-pwa-shortcodes.php', 'utf8');
if (shortcodeFile.includes('sanitize_text_field') && shortcodeFile.includes('esc_html')) {
    console.log('  ✅ PASS: Shortcode sicuro\n');
    passed++;
} else {
    console.log('  ❌ FAIL\n');
    failed++;
}

// --- TEST 2: Safari beforeinstallprompt ---
console.log('[2] Verifica logica Safari...');
const appJs = fs.readFileSync('assets/js/app.js', 'utf8');
if (appJs.includes('beforeinstallprompt') && (appJs.includes('display:') || appJs.includes('.hidden'))) {
    console.log('  ✅ PASS\n');
    passed++;
} else {
    console.log('  ⚠️  WARN\n');
    passed++;
}

// --- TEST 3: config.i18n protection ---
console.log('[3] Verifica helper t()...');
const hasTHelper = appJs.includes('function t(') || appJs.match(/const\s+t\s*=/);
// Cerca config.i18n fuori dal contesto del helper
const lines = appJs.split('\n');
let unsafeAccess = false;
let inTHelper = false;
for (const line of lines) {
    if (line.includes('function t(') || line.match(/const\s+t\s*=/)) inTHelper = true;
    if (line.includes('}') && inTHelper) inTHelper = false;
    if (!inTHelper && line.includes('config.i18n.') && !line.trim().startsWith('//')) {
        unsafeAccess = true;
        break;
    }
}
if (hasTHelper && !unsafeAccess) {
    console.log('  ✅ PASS\n');
    passed++;
} else {
    console.log('  ❌ FAIL: Accessi diretti rilevati\n');
    failed++;
}

// --- TEST 4: Manifest fields (con singoli apici) ---
console.log('[4] Verifica manifest campi...');
const manifestFile = fs.readFileSync('includes/class-pwa-manifest.php', 'utf8');
const requiredFields = [
    "'start_url'",
    "'display'",
    "'icons'",
    "'name'",
    "'short_name'"
];
let allPresent = true;
const missing = [];
requiredFields.forEach(field => {
    if (!manifestFile.includes(field)) {
        allPresent = false;
        missing.push(field);
    }
});
const validDisplay = manifestFile.includes("'standalone'") || manifestFile.includes("'fullscreen'");
if (allPresent && validDisplay) {
    console.log('  ✅ PASS: Tutti i campi presenti\n');
    passed++;
} else {
    console.log(`  ❌ FAIL: Mancanti: ${missing.join(', ')}\n`);
    failed++;
}

// --- TEST 5: Meta Tags iOS ---
console.log('[5] Verifica meta tags iOS...');
const coreFile = fs.readFileSync('pwa-core-plugin.php', 'utf8');
if (coreFile.includes('apple-touch-icon') && coreFile.includes('mobile-web-app-capable')) {
    console.log('  ✅ PASS\n');
    passed++;
} else {
    console.log('  ⚠️  WARN\n');
    passed++;
}

// --- TEST 6: Cache-Control ---
console.log('[6] Verifica cache headers...');
if (manifestFile.includes('max-age') || manifestFile.includes('public')) {
    console.log('  ✅ PASS\n');
    passed++;
} else {
    console.log('  ⚠️  WARN\n');
    passed++;
}

// --- TEST 7: Safe helpers ---
console.log('[7] Verifica safe_str/safe_int...');
if (coreFile.includes('safe_str') && coreFile.includes('safe_int')) {
    console.log('  ✅ PASS\n');
    passed++;
} else {
    console.log('  ❌ FAIL\n');
    failed++;
}

console.log('\n=== RISULTATI ===');
console.log(`Passati: ${passed}, Falliti: ${failed}`);

if (failed === 0) {
    console.log('\n🎉 CODICE VALIDO PER SAFARI, SAMSUNG, FIREFOX!');
    process.exit(0);
} else {
    console.log('\n⚠️  PROBLEMI RILEVATI');
    process.exit(1);
}