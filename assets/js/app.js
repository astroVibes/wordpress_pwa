/**
 * PWA Core App JavaScript
 * 
 * Handles install prompts, connection status, and offline indicators.
 */

(function() {
    'use strict';

    // Configuration from WordPress
    const config = window.pwaCoreConfig || {};

    /**
     * Safe translation helper for i18n strings
     * Prevents TypeError if config.i18n is undefined
     * 
     * @param {string} key - Translation key
     * @returns {string} Translated string or English fallback
     */
    function t(key) {
        const defaults = {
            online: 'Online',
            offline: 'Offline',
            installing: 'Installing...',
            installed: 'Installed',
            install: 'Install App',
            close: 'Close'
        };
        
        if (!config.i18n || typeof config.i18n !== 'object') {
            return defaults[key] || key;
        }
        
        return config.i18n[key] || defaults[key] || key;
    }

    // State variables
    let deferredPrompt = null;
    let isInstalled = false;
    let installStatus = 'idle';

    /**
     * Ensure the indicator element exists in the DOM
     * 
     * @returns {HTMLElement|null} The indicator element or null
     */
    function ensureIndicator() {
        if (!document.body) {
            return null;
        }

        let indicator = document.getElementById('pwa-connection-indicator');
        
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'pwa-connection-indicator';
            indicator.className = 'pwa-connection-indicator';
            indicator.setAttribute('aria-live', 'polite');
            indicator.setAttribute('role', 'status');
            document.body.appendChild(indicator);
        }

        return indicator;
    }

    /**
     * Show the connection status indicator
     * 
     * @param {string} message - Status message to display
     * @param {boolean} isOnline - Whether the connection is online
     */
    function showIndicator(message, isOnline) {
        const indicator = ensureIndicator();
        if (!indicator) return;

        indicator.className = 'pwa-connection-indicator ' + (isOnline ? 'is-online' : 'is-offline');
        indicator.textContent = message;
        
        // Auto-hide after 3 seconds
        setTimeout(() => {
            if (indicator && indicator.parentNode) {
                indicator.classList.add('is-hidden');
            }
        }, 3000);
    }

    /**
     * Setup connection status listeners
     */
    function setupConnectionIndicator() {
        // Only if enabled in settings
        if (!config.settings || !config.settings.enableOnlineIndicator) {
            return;
        }

        // Initial status
        updateConnectionStatus();

        // Listen for online/offline events
        window.addEventListener('online', () => {
            updateConnectionStatus();
            showIndicator(t('online'), true);
        });

        window.addEventListener('offline', () => {
            updateConnectionStatus();
            showIndicator(t('offline'), false);
        });
    }

    /**
     * Update connection status in DOM elements
     */
    function updateConnectionStatus() {
        const isOnline = navigator.onLine;
        
        // Update any connection indicator components
        document.querySelectorAll('[data-component="connection-indicator"]').forEach(el => {
            const dot = el.querySelector('.pwa-connection-indicator__dot');
            const text = el.querySelector('.pwa-connection-indicator__text');
            
            if (dot) {
                dot.className = 'pwa-connection-indicator__dot ' + (isOnline ? 'is-online' : 'is-offline');
            }
            
            if (text) {
                text.textContent = isOnline ? el.dataset.online : el.dataset.offline;
            }
            
            el.classList.toggle('is-online', isOnline);
            el.classList.toggle('is-offline', !isOnline);
        });
    }

    /**
     * Setup install prompt handling
     */
    function setupInstallPrompt() {
        // Only if enabled in settings
        if (!config.settings || !config.settings.enableInstallPrompt) {
            return;
        }

        // Listen for beforeinstallprompt event
        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferredPrompt = event;
            installStatus = 'promptable';
            
            // Update UI to show install button
            updateInstallUI();
            
            // Dispatch custom event for other scripts
            window.dispatchEvent(new CustomEvent('pwa-install-prompt-ready', { detail: { canInstall: true } }));
        });

        // Listen for app installed event
        window.addEventListener('appinstalled', () => {
            installStatus = 'installed';
            isInstalled = true;
            deferredPrompt = null;
            updateInstallUI();
            
            // Dispatch custom event
            window.dispatchEvent(new CustomEvent('pwa-app-installed'));
        });
    }

    /**
     * Update install button UI based on current state
     */
    function updateInstallUI() {
        document.querySelectorAll('[data-action="install"]').forEach(button => {
            switch (installStatus) {
                case 'promptable':
                    button.style.display = '';
                    button.disabled = false;
                    button.textContent = t('install');
                    break;
                case 'installing':
                    button.disabled = true;
                    button.textContent = t('installing');
                    break;
                case 'installed':
                    button.style.display = 'none';
                    break;
                default:
                    button.style.display = 'none';
                    break;
            }
        });

        // Update status indicators
        document.querySelectorAll('[data-component="install-status"]').forEach(el => {
            const label = el.querySelector('.pwa-install-status__label');
            const indicator = el.querySelector('.pwa-install-status__indicator');
            
            if (label) {
                switch (installStatus) {
                    case 'promptable':
                        label.textContent = t('install');
                        break;
                    case 'installing':
                        label.textContent = t('installing');
                        break;
                    case 'installed':
                        label.textContent = t('installed');
                        break;
                    default:
                        label.textContent = '';
                }
            }
            
            if (indicator) {
                indicator.className = 'pwa-install-status__indicator status-' + installStatus;
            }
        });
    }

    /**
     * Trigger the install prompt
     * 
     * @returns {Promise<boolean>} Whether installation was initiated
     */
    async function triggerInstall() {
        if (!deferredPrompt) {
            console.warn('Install prompt not available');
            return false;
        }

        installStatus = 'installing';
        updateInstallUI();

        try {
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            
            console.log('Install prompt outcome:', outcome);
            
            if (outcome === 'accepted') {
                installStatus = 'installed';
                isInstalled = true;
            }
            
            deferredPrompt = null;
            updateInstallUI();
            
            return outcome === 'accepted';
        } catch (error) {
            console.error('Install prompt error:', error);
            installStatus = 'promptable';
            updateInstallUI();
            return false;
        }
    }

    /**
     * Check if app is already installed
     * 
     * @returns {boolean} Whether app is installed
     */
    function checkInstallStatus() {
        // Check if running in standalone mode (installed)
        if (window.matchMedia('(display-mode: standalone)').matches) {
            isInstalled = true;
            installStatus = 'installed';
            return true;
        }
        
        // Check if iOS with web-app-capable
        if (window.navigator.standalone === true) {
            isInstalled = true;
            installStatus = 'installed';
            return true;
        }
        
        return false;
    }

    /**
     * Register service worker
     */
    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            console.log('Service Worker not supported');
            return;
        }

        try {
            const registration = await navigator.serviceWorker.register(
                config.swUrl || '/wp-json/pwa-core/v1/sw/',
                { scope: '/' }
            );
            
            console.log('Service Worker registered:', registration.scope);
            
            // Check for updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                
                if (newWorker) {
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // New version available
                            window.dispatchEvent(new CustomEvent('pwa-update-available'));
                        }
                    });
                }
            });
        } catch (error) {
            console.error('Service Worker registration failed:', error);
        }
    }

    /**
     * Initialize the PWA app
     */
    function init() {
        // Check if already installed
        checkInstallStatus();
        
        // Setup features
        setupConnectionIndicator();
        setupInstallPrompt();
        
        // Add click handlers for install buttons
        document.addEventListener('click', (event) => {
            const installButton = event.target.closest('[data-action="install"]');
            if (installButton) {
                event.preventDefault();
                triggerInstall();
            }
        });
        
        // Register service worker
        if (config.settings && config.settings.enableOffline !== false) {
            registerServiceWorker();
        }
        
        // Dispatch ready event
        window.dispatchEvent(new CustomEvent('pwa-ready', { 
            detail: { 
                isInstalled, 
                installStatus,
                canInstall: !!deferredPrompt 
            } 
        }));
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose API for external use
    window.PWACore = {
        triggerInstall,
        checkInstallStatus,
        isInstalled: () => isInstalled,
        getInstallStatus: () => installStatus,
        showIndicator,
        t
    };

})();
