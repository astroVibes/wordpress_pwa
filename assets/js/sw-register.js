/**
 * Service Worker Registration Script
 */

(function() {
    'use strict';

    // Get config from global
    const config = window.pwaCoreConfig || {};

    /**
     * Register the service worker
     */
    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            console.log('[PWA Core] Service Worker not supported');
            return;
        }

        const swUrl = config.swUrl || '/wp-json/pwa-core/v1/sw/';

        try {
            const registration = await navigator.serviceWorker.register(swUrl, {
                scope: '/'
            });

            console.log('[PWA Core] Service Worker registered:', registration.scope);

            // Handle updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;

                if (newWorker) {
                    console.log('[PWA Core] New Service Worker found, installing...');

                    newWorker.addEventListener('statechange', () => {
                        switch (newWorker.state) {
                            case 'installed':
                                if (navigator.serviceWorker.controller) {
                                    // New version available
                                    console.log('[PWA Core] New content available, please refresh.');
                                    window.dispatchEvent(new CustomEvent('pwa-update-available', {
                                        detail: {
                                            registration
                                        }
                                    }));
                                } else {
                                    console.log('[PWA Core] Content cached for offline use.');
                                }
                                break;

                            case 'redundant':
                                console.error('[PWA Core] The installing service worker became redundant.');
                                break;
                        }
                    });
                }
            });

            // Handle messages from SW
            navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data && event.data.type === 'CACHE_UPDATED') {
                    console.log('[PWA Core] Cache updated:', event.data.payload);
                }
            });

        } catch (error) {
            console.error('[PWA Core] Service Worker registration failed:', error);
        }
    }

    /**
     * Update the service worker
     */
    async function updateServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            await registration.update();
            console.log('[PWA Core] Service Worker update check completed');
        } catch (error) {
            console.error('[PWA Core] Service Worker update failed:', error);
        }
    }

    /**
     * Skip waiting and activate immediately
     */
    async function skipWaiting() {
        if (!navigator.serviceWorker.controller) {
            return;
        }

        try {
            navigator.serviceWorker.getRegistration().then((registration) => {
                if (registration && registration.waiting) {
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                }
            });
        } catch (error) {
            console.error('[PWA Core] Skip waiting failed:', error);
        }
    }

    /**
     * Clear all caches
     */
    async function clearCaches() {
        if (!('caches' in window)) {
            return;
        }

        try {
            const cacheNames = await caches.keys();
            await Promise.all(cacheNames.map(name => caches.delete(name)));
            console.log('[PWA Core] All caches cleared');

            // Notify SW to clear its cache too
            if (navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_CACHE' });
            }
        } catch (error) {
            console.error('[PWA Core] Clear caches failed:', error);
        }
    }

    // Expose API
    window.PWACoreSW = {
        register: registerServiceWorker,
        update: updateServiceWorker,
        skipWaiting,
        clearCaches
    };

    // Auto-register when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerServiceWorker);
    } else {
        registerServiceWorker();
    }

})();
