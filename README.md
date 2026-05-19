# PWA Core Plugin

Plugin WordPress per trasformare il sito in Progressive Web App. Manifest dinamico, Service Worker dalla root, caching offline a strategie multiple, REST API, piena compatibilità WooCommerce, pannello admin con customizzazione completa.

**Versione**: 1.0.0 — testato runtime con 20 test passati.

## Requisiti

- WordPress 6.0+
- PHP 8.0+
- HTTPS attivo (richiesto dai browser per i Service Worker — eccezione per `localhost`, `127.0.0.1`, `.local`, `.test`)
- Permalink ≠ "Plain"

## Pannello admin — 4 tab

### Tab GENERALE
- **Identità app**: nome app, nome breve (max 12 caratteri), descrizione
- **Aspetto**: colore tema, colore sfondo, display mode (standalone/fullscreen/minimal-ui/browser), orientamento
- **Icone**: 192×192, 512×512, maskable — caricate dalla Libreria Media, validate same-host
- **Funzionalità frontend**: toggle indicatore online/offline, toggle prompt di installazione PWA

### Tab CACHE
- Versione cache corrente (timestamp leggibile)
- Pagina offline (link diretto alla modifica)
- Toggle invalidazione automatica su `save_post`
- Pulsante "Svuota cache del Service Worker"

### Tab AVANZATE
- **URL da pre-cacheare**: textarea, una URL per riga, validate same-host (max 50)
- **Pattern da escludere**: textarea aggiuntiva ai default già attivi, max 50, sanificati
- **Limiti dimensione cache**: 3 input numerici (pagine, asset, immagini) con range 10–500

### Tab ENDPOINT (diagnostica)
- Link diretti agli endpoint `/manifest.json`, `/sw.js`, `/wp-json/pwa-core/v1/manifest`
- Elenco delle esclusioni automatiche WooCommerce
- Checklist test PWA

## Endpoint pubblici

| Endpoint | Tipo | Scopo |
|---|---|---|
| `/manifest.json` | Rewrite virtuale | Web App Manifest standard, riconosciuto dai browser per l'install |
| `/sw.js` | Rewrite virtuale | Service Worker servito dalla root, scope completo |
| `/wp-json/pwa-core/v1/manifest` | REST API | Stesso manifest, consumabile da JS |

## File del plugin

```
pwa-core-plugin/
├── pwa-core-plugin.php       # File principale: costanti, requirements check, boot
├── uninstall.php             # Pulizia completa al disinstall (multisite-aware)
├── README.md
├── includes/
│   ├── class-pwa-core-plugin.php    # Bootstrap, enqueue, cache timestamp, sensitive page check
│   ├── class-pwa-manifest.php       # Manifest dinamico (endpoint rewrite + REST API)
│   ├── class-pwa-service-worker.php # Rewrite virtuale /sw.js + serve
│   ├── class-pwa-offline.php        # Pagina offline (creazione + fallback inline)
│   └── class-pwa-admin.php          # 4-tab settings + svuota cache + media picker
└── assets/
    ├── sw.js     # Service Worker (template con placeholder)
    ├── app.js    # Frontend: SW registration + indicator + install prompt
    └── app.css   # Stili
```

## Sicurezza — Triplice difesa WooCommerce/utenti loggati

1. **Server**: lo script frontend non viene caricato per utenti loggati o su pagine carrello/checkout/account (anche con slug italiani). Riconosciuti blocchi Gutenberg e shortcode WooCommerce.
2. **Client**: lo script non registra il SW se l'utente è loggato; se trova un SW attivo, lo deregistra.
3. **Service Worker**: pattern di esclusione per admin/REST/login/wc-ajax + rifiuto risposte con `Set-Cookie`, `Vary: Cookie`, `Authorization`, `Cache-Control: private/no-store`.

## Caratteristiche tecniche

- **Cache invalidation automatica** via timestamp aggiornato su `save_post`, `deleted_post`, `switch_theme`, `update_options` (toggleable dall'admin)
- **Strategie cache differenziate**: Network-First per HTML, Stale-While-Revalidate per CSS/JS/font, Cache-First per immagini
- **Skip media**: video/audio (Range request) gestiti dal browser, mai cacheati
- **Anti-loop offline**: se la pagina offline pre-cacheata manca, risponde con HTML inline (NO refetch ricorsivo)
- **Defense-in-depth manifest**: validazione anche al `build()`, non solo al save (protegge da DB compromesso)
- **No-store su SW/manifest/offline**: previene caching da Cloudflare/WP Rocket/LiteSpeed
- **Priority `parse_request=1`**: intercettiamo prima dei plugin di caching aggressivi
- **HTTPS-only registration**: localhost permesso per dev

## Hook per sviluppatori

```php
// URL custom da pre-cacheare (oltre a quelle inserite via admin)
add_filter( 'pwa_core_precache_urls', function( $urls ) {
    $urls[] = home_url( '/pagina-importante/' );
    return $urls;
} );

// Pattern di esclusione custom (oltre a quelli admin)
add_filter( 'pwa_core_exclude_patterns', function( $patterns ) {
    $patterns[] = '/private-area/';
    return $patterns;
} );

// Modificare il manifest finale
add_filter( 'pwa_core_manifest', function( $manifest ) {
    $manifest['categories'] = [ 'business' ];
    return $manifest;
} );
```

## Note Nginx

Se la rewrite WordPress non funziona per `/sw.js` e `/manifest.json`:

```nginx
location = /sw.js          { try_files $uri /index.php?$args; }
location = /manifest.json  { try_files $uri /index.php?$args; }
```

## Test runtime eseguiti

Il plugin è stato testato con 20 test runtime PHP che coprono:

- Defense-in-depth manifest (icone host esterno rifiutate al build, tag HTML rimossi, caratteri di controllo, start_url maligno, display invalido, lang BCP47)
- Parsing textarea opzioni (multi-line, CRLF/CR/LF, stringhe vuote)
- Sanitizer admin (URL same-host, pattern caratteri pericolosi, int min/max, icon URL)
- Service Worker (clamp_int limiti, pattern list rifiuta non-stringhe)
- Cache invalidation (timestamp bump, save_post solo su publish, ignora autosave/null)
- Memoization is_sensitive_page
- JSON encoding Unicode (emoji, accenti, truncate multi-byte safe)

## Test PWA browser

1. DevTools → Application → Manifest: nessun errore
2. Application → Service Workers: `activated and running`
3. Lighthouse → PWA: punteggio "installabile"
4. Disconnetti rete → ricarica pagina visitata → appare dalla cache
5. Naviga offline a pagina mai visitata → appare pagina offline custom
6. Pubblica nuovo post → ricarica → nuova versione cache (timestamp aggiornato)
7. Toggle install prompt: deve apparire banner di installazione (browser supportato)
