<?php
/**
 * Service Worker for PWA Core Plugin
 * 
 * Handles caching strategies and offline functionality.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PWA Service Worker class
 */
class PWA_Service_Worker {

    private int $cache_pages_limit;
    private int $cache_size_limit_mb;
    private string $cache_name;
    private string $site_url;

    public function __construct() {
        $this->cache_name = 'pwa-core-v' . PWA_CORE_VERSION;
        $this->site_url = home_url('/');
    }

    /**
     * Serve the service worker JavaScript
     * 
     * @param int $cache_pages_limit Maximum pages to cache
     * @param int $cache_size_limit_mb Maximum cache size in MB
     */
    public function serve(int $cache_pages_limit, int $cache_size_limit_mb): void {
        $this->cache_pages_limit = $cache_pages_limit;
        $this->cache_size_limit_mb = $cache_size_limit_mb;
        
        // Get site host and port safely
        $parsed_url = wp_parse_url(home_url());
        $host = '';
        $port = '';
        
        if (is_array($parsed_url)) {
            $host = isset($parsed_url['host']) && is_string($parsed_url['host']) ? $parsed_url['host'] : '';
            $port = isset($parsed_url['port']) && is_int($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        }
        
        // Fallback if parsing failed
        if (empty($host)) {
            $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        }
        
        $scope = $this->site_url;
        
        ?>
// PWA Core Service Worker
// Version: <?php echo esc_js(PWA_CORE_VERSION); ?>

const CACHE_NAME = '<?php echo esc_js($this->cache_name); ?>';
const CACHE_PAGES_LIMIT = <?php echo (int) $this->cache_pages_limit; ?>;
const CACHE_SIZE_LIMIT_MB = <?php echo (int) $this->cache_size_limit_mb; ?>;
const SCOPE = '<?php echo esc_js($scope); ?>';
const SITE_HOST = '<?php echo esc_js($host . $port); ?>';

// Assets to precache
const PRECACHE_ASSETS = [
    SCOPE,
];

// Install event - precache assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS);
        }).then(() => {
            return self.skipWaiting();
        })
    );
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            return self.clients.claim();
        })
    );
});

// Fetch event - network first, fallback to cache
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    
    // Only handle same-origin requests
    if (url.host !== SITE_HOST) {
        return;
    }
    
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }
    
    // Skip admin and API requests
    if (url.pathname.startsWith('/wp-admin') || 
        url.pathname.startsWith('/wp-json') ||
        url.pathname.includes('wp-login.php')) {
        return;
    }
    
    // Network-first strategy for HTML pages
    if (event.request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    // Cache successful responses
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            this.pruneCache(cache, CACHE_PAGES_LIMIT);
                            cache.put(event.request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // Fallback to cache on network failure
                    return caches.match(event.request).then((cachedResponse) => {
                        if (cachedResponse) {
                            return cachedResponse;
                        }
                        // Show offline page
                        return caches.match(SCOPE + 'offline/');
                    });
                })
        );
        return;
    }
    
    // Cache-first strategy for static assets
    if (event.request.url.match(/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)$/)) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }
                return fetch(event.request).then((response) => {
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseClone);
                        });
                    }
                    return response;
                });
            })
        );
        return;
    }
    
    // Default: network first
    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request);
        })
    );
});

// Prune cache to stay within limits
PWA_Service_Worker.prototype.pruneCache = async function(cache, limit) {
    const keys = await cache.keys();
    
    if (keys.length > limit) {
        // Delete oldest entries
        const toDelete = keys.slice(0, keys.length - limit);
        await Promise.all(toDelete.map(key => cache.delete(key)));
    }
    
    // Check size limit
    const cacheStorage = await navigator.storage.estimate();
    const usageMB = (cacheStorage.usage || 0) / (1024 * 1024);
    
    if (usageMB > CACHE_SIZE_LIMIT_MB) {
        // Delete oldest entry to make room
        if (keys.length > 0) {
            await cache.delete(keys[0]);
        }
    }
};

// Message handler for cache management
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        caches.open(CACHE_NAME).then((cache) => {
            return cache.keys();
        }).then((keys) => {
            return Promise.all(keys.map(key => caches.open(CACHE_NAME).then(cache => cache.delete(key))));
        });
    }
    
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// Helper to add prototype method
PWA_Service_Worker.prototype.pruneCache = async function(cache, limit) {
    try {
        const keys = await cache.keys();
        
        if (keys.length > limit) {
            const toDelete = keys.slice(0, keys.length - limit);
            await Promise.all(toDelete.map(key => cache.delete(key)));
        }
        
        // Check size limit if storage manager is available
        if (navigator.storage && navigator.storage.estimate) {
            const cacheStorage = await navigator.storage.estimate();
            const usageMB = (cacheStorage.usage || 0) / (1024 * 1024);
            
            if (usageMB > CACHE_SIZE_LIMIT_MB && keys.length > 0) {
                const cacheKeys = await cache.keys();
                if (cacheKeys.length > 0) {
                    await cache.delete(cacheKeys[0]);
                }
            }
        }
    } catch (error) {
        console.warn('Cache prune error:', error);
    }
};
        <?php
    }

    /**
     * Get the service worker URL
     * 
     * @return string Service worker URL
     */
    public function get_url(): string {
        return rest_url('pwa-core/v1/sw/');
    }

    /**
     * Get the current version hash
     * 
     * @return string Version hash
     */
    public function get_version(): string {
        return md5(PWA_CORE_VERSION . '|' . $this->cache_pages_limit . '|' . $this->cache_size_limit_mb);
    }
}
