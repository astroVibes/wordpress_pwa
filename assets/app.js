/**
 * PWA Core - Frontend
 *
 * Espone window.PWACore come API pubblica per shortcode e temi:
 *
 *   window.PWACore.triggerInstall()   — mostra il prompt nativo del browser
 *   window.PWACore.isInstalled()      — true se la PWA è già installata
 *   window.PWACore.canInstall()       — true se il prompt nativo è disponibile
 *   window.PWACore.isIOS()            — true su Safari iOS (install manuale)
 *   window.PWACore.onStateChange(fn)  — callback quando lo stato cambia
 *
 * Vanilla JS, zero dipendenze.
 */

(function () {
	'use strict';

	if (typeof window.PWACoreConfig !== 'object' || window.PWACoreConfig === null) {
		return;
	}
	var config = window.PWACoreConfig;

	/* ============================================================
	 * Costanti
	 * ============================================================ */
	// Durata in millisecondi del "dismiss" del banner prima che venga riproposto.
	// 7 giorni: l'utente ha chiuso consapevolmente, non vogliamo essere invadenti,
	// ma dopo una settimana potrebbe aver cambiato idea.
	var DISMISS_TTL_MS = 7 * 24 * 60 * 60 * 1000;
	var STORAGE_KEY_DISMISS = 'pwa-core-install-dismissed-until';
	var STORAGE_KEY_INSTALLED = 'pwa-core-installed';

	/* ============================================================
	 * Stato interno
	 * ============================================================ */
	var deferredPrompt = null;       // evento beforeinstallprompt salvato
	var isInstalledState = false;    // se la PWA risulta installata
	var stateListeners = [];         // callback registrate con onStateChange()

	/* ============================================================
	 * Detects
	 * ============================================================ */

	/**
	 * Rileva se siamo su iOS Safari, dove beforeinstallprompt non esiste
	 * e l'installazione è manuale (Share → Aggiungi a schermata Home).
	 */
	function detectIOS() {
		var ua = window.navigator.userAgent.toLowerCase();
		var isIOS = /iphone|ipad|ipod/.test(ua);
		// Chrome su iOS NON supporta l'install prompt (usa WebKit sotto).
		// Brave, Firefox su iOS idem.
		var isSafariEngine = !window.MSStream; // esclude IE
		return isIOS && isSafariEngine;
	}

	/**
	 * Rileva se la PWA è già installata.
	 *
	 * Metodi (dal più affidabile al meno):
	 *  1. display-mode: standalone/fullscreen via matchMedia — il browser conferma
	 *     che sta girando come app installata in questa sessione.
	 *  2. navigator.standalone — Safari iOS, proprietario Apple.
	 *  3. localStorage flag — lo scriviamo noi su 'appinstalled'; persiste tra sessioni.
	 *
	 * Non usiamo solo localStorage perché l'utente potrebbe aver disinstallato l'app,
	 * in quel caso matchMedia ci darebbe false e resettiamo il flag.
	 */
	function detectInstalled() {
		// Metodo 1: display-mode (più affidabile, solo se la pagina gira come app).
		var standaloneMedia = window.matchMedia
			? window.matchMedia('(display-mode: standalone)')
			: null;
		var fullscreenMedia = window.matchMedia
			? window.matchMedia('(display-mode: fullscreen)')
			: null;
		var minimalUiMedia = window.matchMedia
			? window.matchMedia('(display-mode: minimal-ui)')
			: null;

		if (
			(standaloneMedia && standaloneMedia.matches) ||
			(fullscreenMedia && fullscreenMedia.matches) ||
			(minimalUiMedia && minimalUiMedia.matches)
		) {
			// Aggiorna il flag localStorage in modo che persista.
			try { localStorage.setItem(STORAGE_KEY_INSTALLED, '1'); } catch (_) {}
			return true;
		}

		// Metodo 2: Safari iOS.
		if (window.navigator.standalone === true) {
			try { localStorage.setItem(STORAGE_KEY_INSTALLED, '1'); } catch (_) {}
			return true;
		}

		// Metodo 3: flag localStorage (app installata in sessione precedente).
		// Se arriviamo qui, display-mode è 'browser' → l'utente ha disinstallato.
		// Resettiamo il flag per non mostrare "già installata" su un browser normale.
		try {
			if (localStorage.getItem(STORAGE_KEY_INSTALLED) === '1') {
				// Siamo in browser mode: l'app potrebbe essere stata disinstallata.
				// Resettiamo per permettere un nuovo install.
				localStorage.removeItem(STORAGE_KEY_INSTALLED);
			}
		} catch (_) {}

		return false;
	}

	/**
	 * Controlla se il banner è stato dismissato e il TTL non è scaduto.
	 */
	function isBannerDismissed() {
		try {
			var until = parseInt(localStorage.getItem(STORAGE_KEY_DISMISS) || '0', 10);
			return Date.now() < until;
		} catch (_) {
			return false;
		}
	}

	function dismissBannerFor(ms) {
		try {
			localStorage.setItem(STORAGE_KEY_DISMISS, String(Date.now() + ms));
		} catch (_) {}
	}

	/* ============================================================
	 * Notifica i listener quando lo stato cambia
	 * ============================================================ */
	function notifyListeners() {
		var state = {
			isInstalled: isInstalledState,
			canInstall: !!deferredPrompt,
			isIOS: detectIOS()
		};
		for (var i = 0; i < stateListeners.length; i++) {
			try { stateListeners[i](state); } catch (_) {}
		}
		// Aggiorna anche tutti i pulsanti shortcode nel DOM.
		updateShortcodeElements();
	}

	/* ============================================================
	 * API PUBBLICA — window.PWACore
	 * ============================================================ */
	window.PWACore = {

		/**
		 * Mostra il prompt nativo del browser per installare la PWA.
		 * Se non disponibile (iOS, browser non supportato, già installata), no-op.
		 * Ritorna una Promise che si risolve con l'esito ('accepted'/'dismissed'/null).
		 */
		triggerInstall: function () {
			if (!deferredPrompt) {
				return Promise.resolve(null);
			}
			var p = deferredPrompt;
			deferredPrompt = null; // Consuma il prompt (può essere usato una sola volta).
			notifyListeners();

			try {
				p.prompt();
				return p.userChoice.then(function (choice) {
					if (choice && choice.outcome === 'accepted') {
						isInstalledState = true;
						try { localStorage.setItem(STORAGE_KEY_INSTALLED, '1'); } catch (_) {}
					}
					notifyListeners();
					return choice ? choice.outcome : null;
				}).catch(function () {
					notifyListeners();
					return null;
				});
			} catch (_) {
				notifyListeners();
				return Promise.resolve(null);
			}
		},

		/** true se la PWA è già installata sul dispositivo. */
		isInstalled: function () {
			return isInstalledState;
		},

		/** true se il prompt nativo è disponibile (non ancora mostrato, non installata). */
		canInstall: function () {
			return !!deferredPrompt && !isInstalledState;
		},

		/** true se siamo su iOS Safari (install solo manuale). */
		isIOS: detectIOS,

		/**
		 * Registra una callback chiamata ogni volta che lo stato di installazione cambia.
		 * Viene chiamata immediatamente con lo stato corrente al momento della registrazione.
		 *
		 * @param {function({isInstalled: boolean, canInstall: boolean, isIOS: boolean})} fn
		 */
		onStateChange: function (fn) {
			if (typeof fn !== 'function') return;
			stateListeners.push(fn);
			// Chiama subito con lo stato corrente.
			try {
				fn({
					isInstalled: isInstalledState,
					canInstall: !!deferredPrompt,
					isIOS: detectIOS()
				});
			} catch (_) {}
		}
	};

	/* ============================================================
	 * Aggiorna gli elementi shortcode nel DOM
	 * ============================================================ */
	function updateShortcodeElements() {
		// Pulsanti [pwa_install_button].
		var buttons = document.querySelectorAll('[data-pwa-install="1"]');
		for (var i = 0; i < buttons.length; i++) {
			updateInstallButton(buttons[i]);
		}

		// Widget stato [pwa_install_status].
		var statusEls = document.querySelectorAll('[data-pwa-status="1"]');
		for (var j = 0; j < statusEls.length; j++) {
			updateStatusWidget(statusEls[j]);
		}
	}

	function updateInstallButton(el) {
		var installedText = el.getAttribute('data-installed-text') || '';
		var labelIOS = el.getAttribute('data-label-ios') || el.getAttribute('data-label') || '';
		var labelDefault = el.getAttribute('data-label') || '';

		if (isInstalledState) {
			// App già installata.
			if (installedText === '') {
				// Nessun testo configurato → nascondi completamente.
				el.style.display = 'none';
				el.setAttribute('aria-hidden', 'true');
			} else {
				// Mostra testo "già installata" disabilitando il click.
				el.textContent = installedText;
				el.setAttribute('disabled', 'disabled');
				el.classList.add('pwa-core-trigger--installed');
				el.removeAttribute('aria-hidden');
				el.style.display = '';
			}
			return;
		}

		// App non installata: mostra il pulsante.
		el.removeAttribute('disabled');
		el.classList.remove('pwa-core-trigger--installed');
		el.removeAttribute('aria-hidden');
		el.style.display = '';

		if (detectIOS()) {
			el.textContent = labelIOS;
			el.setAttribute('data-pwa-ios', '1');
		} else {
			el.textContent = labelDefault;
			el.removeAttribute('data-pwa-ios');
		}

		// Se il browser non supporta il prompt e non è iOS, nascondi
		// (non c'è niente che possiamo fare per l'install programmatico).
		if (!deferredPrompt && !detectIOS()) {
			el.style.display = 'none';
			el.setAttribute('aria-hidden', 'true');
		}
	}

	function updateStatusWidget(el) {
		var installed = el.getAttribute('data-installed') || '';
		var notInstalled = el.getAttribute('data-not-installed') || '';

		el.classList.remove('is-hidden');
		el.removeAttribute('aria-hidden');

		if (isInstalledState) {
			el.textContent = installed;
			el.style.display = installed ? '' : 'none';
		} else {
			el.textContent = notInstalled;
			el.style.display = notInstalled ? '' : 'none';
		}
	}

	/* ============================================================
	 * Attach click listener ai pulsanti shortcode
	 * ============================================================ */
	function bindShortcodeButtons() {
		// Usiamo event delegation sul document per supportare elementi
		// aggiunti dinamicamente (es. page builder, AJAX).
		document.addEventListener('click', function (e) {
			var el = e.target;
			// Risale fino al massimo 3 livelli per gestire contenuto dentro il button.
			for (var i = 0; i < 3; i++) {
				if (!el || el === document) break;
				if (el.getAttribute && el.getAttribute('data-pwa-install') === '1') {
					handleInstallButtonClick(e, el);
					return;
				}
				el = el.parentNode;
			}
		});
	}

	function handleInstallButtonClick(e, el) {
		e.preventDefault();

		// iOS: non possiamo triggerare il prompt, mostriamo le istruzioni.
		if (detectIOS()) {
			showIOSInstructions();
			return;
		}

		// Browser senza supporto prompt e non iOS: pulsante non dovrebbe
		// essere visibile, ma se per qualche ragione lo è, no-op.
		if (!deferredPrompt) {
			return;
		}

		// Trigger del prompt nativo.
		window.PWACore.triggerInstall().then(function () {
			// hideBanner se il banner automatico è ancora visibile.
			hideBanner();
		});
	}

	/* ============================================================
	 * Istruzioni iOS (modale inline senza dipendenze)
	 * ============================================================ */
	function showIOSInstructions() {
		var existing = document.getElementById('pwa-core-ios-modal');
		if (existing) {
			existing.classList.add('is-visible');
			return;
		}

		var modal = document.createElement('div');
		modal.id = 'pwa-core-ios-modal';
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-label', config.i18n.installPrompt || '');

		// Struttura con innerHTML SOLO per tag di layout fissi (nessun dato utente).
		// Il testo dinamico viene inserito via textContent per sicurezza.
		modal.innerHTML =
			'<div class="pwa-core-ios-modal-inner">' +
				'<p class="pwa-core-ios-modal-text"></p>' +
				'<button type="button" class="pwa-core-ios-modal-close"></button>' +
			'</div>' +
			'<div class="pwa-core-ios-modal-backdrop"></div>';

		// Inserisce il testo via textContent: nessun XSS possibile.
		var instrText = config.i18n.iosInstructions ||
			'Per installare: tocca il pulsante Condividi in Safari, poi seleziona Aggiungi alla schermata Home.';
		modal.querySelector('.pwa-core-ios-modal-text').textContent = instrText;
		modal.querySelector('.pwa-core-ios-modal-close').textContent =
			config.i18n.installDismiss || 'Chiudi';

		document.body.appendChild(modal);

		function closeModal() {
			modal.classList.remove('is-visible');
		}

		modal.querySelector('.pwa-core-ios-modal-close').addEventListener('click', closeModal);
		modal.querySelector('.pwa-core-ios-modal-backdrop').addEventListener('click', closeModal);

		// Piccolo delay per la transizione CSS.
		setTimeout(function () { modal.classList.add('is-visible'); }, 10);
	}

	/* ============================================================
	 * Service Worker registration
	 * ============================================================ */
	function unregisterAllForOrigin() {
		if (!('serviceWorker' in navigator)) {
			return Promise.resolve();
		}
		return navigator.serviceWorker.getRegistrations()
			.then(function (regs) {
				return Promise.all(regs.map(function (reg) {
					try {
						if (reg.scope && reg.scope.indexOf(window.location.origin) === 0) {
							return reg.unregister();
						}
					} catch (_) {}
					return Promise.resolve();
				}));
			})
			.catch(function () {});
	}

	function registerServiceWorker() {
		if (!('serviceWorker' in navigator)) {
			return;
		}
		if (config.isUserLoggedIn) {
			unregisterAllForOrigin();
			return;
		}
		if (
			window.location.protocol !== 'https:' &&
			window.location.hostname !== 'localhost' &&
			window.location.hostname !== '127.0.0.1'
		) {
			return;
		}

		var hadController = !!navigator.serviceWorker.controller;
		var refreshing = false;

		navigator.serviceWorker.addEventListener('controllerchange', function () {
			if (!hadController) { hadController = true; return; }
			if (refreshing) return;
			refreshing = true;
			window.location.reload();
		});

		var doRegister = function () {
			navigator.serviceWorker
				.register(config.swUrl, { scope: config.scope })
				.then(function (registration) {
					try { registration.update(); } catch (_) {}
					registration.addEventListener('updatefound', function () {
						var nw = registration.installing;
						if (!nw) return;
						nw.addEventListener('statechange', function () {
							if (nw.state === 'installed' && navigator.serviceWorker.controller) {
								try { nw.postMessage({ type: 'SKIP_WAITING' }); } catch (_) {}
							}
						});
					});
					var iv = setInterval(function () {
						try { registration.update(); } catch (_) {}
					}, 60 * 60 * 1000);
					window.addEventListener('beforeunload', function () { clearInterval(iv); });
				})
				.catch(function () {});
		};

		if (document.readyState === 'complete') {
			doRegister();
		} else {
			window.addEventListener('load', doRegister, { once: true });
		}
	}

	/* ============================================================
	 * Indicatore online/offline
	 * ============================================================ */
	function setupConnectionIndicator() {
		if (!config.enableOnlineIndicator) return;

		var indicator = null;
		var hideTimer = null;

		function ensureIndicator() {
			if (indicator) return indicator;
			if (!document.body) return null;
			indicator = document.createElement('div');
			indicator.id = 'pwa-core-indicator';
			indicator.setAttribute('role', 'status');
			indicator.setAttribute('aria-live', 'polite');
			document.body.appendChild(indicator);
			return indicator;
		}

		function showIndicator(message, status) {
			var el = ensureIndicator();
			if (!el) return;
			if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
			el.textContent = message;
			el.dataset.status = status;
			el.classList.add('is-visible');
			if (status === 'online') {
				hideTimer = setTimeout(function () {
					el.classList.remove('is-visible');
					hideTimer = null;
				}, 3000);
			}
		}

		window.addEventListener('online', function () { showIndicator(config.i18n.online, 'online'); });
		window.addEventListener('offline', function () { showIndicator(config.i18n.offline, 'offline'); });

		function checkInitialState() {
			if (!navigator.onLine) showIndicator(config.i18n.offline, 'offline');
		}
		if (document.body) {
			checkInitialState();
		} else {
			document.addEventListener('DOMContentLoaded', checkInitialState, { once: true });
		}
	}

	/* ============================================================
	 * Banner automatico di installazione
	 * ============================================================ */
	var banner = null;

	function buildBanner() {
		if (banner) return banner;
		if (!document.body) return null;

		banner = document.createElement('div');
		banner.id = 'pwa-core-install-banner';
		banner.setAttribute('role', 'dialog');
		banner.setAttribute('aria-live', 'polite');

		// Struttura con innerHTML SOLO per i tag di layout (nessun dato dinamico).
		// I testi dinamici da config.i18n vengono inseriti via textContent per sicurezza.
		// Vedi anche showIOSInstructions() che applica lo stesso pattern.
		banner.innerHTML =
			'<span class="pwa-core-install-text"></span>' +
			'<button type="button" class="pwa-core-install-btn"></button>' +
			'<button type="button" class="pwa-core-install-dismiss" aria-label="Chiudi">&times;</button>';

		var textEl = banner.querySelector('.pwa-core-install-text');
		var btnEl = banner.querySelector('.pwa-core-install-btn');
		var dismissEl = banner.querySelector('.pwa-core-install-dismiss');

		textEl.textContent = (config.i18n && config.i18n.installPrompt) || '';
		btnEl.textContent = (config.i18n && config.i18n.installButton) || 'Installa';

		btnEl.addEventListener('click', function () {
			window.PWACore.triggerInstall().then(hideBanner);
		});

		dismissEl.addEventListener('click', function () {
			dismissBannerFor(DISMISS_TTL_MS);
			hideBanner();
		});

		document.body.appendChild(banner);
		return banner;
	}

	function showBanner() {
		// Non mostrare se già installata.
		if (isInstalledState) return;
		// Non mostrare se l'utente ha dismissato di recente.
		if (isBannerDismissed()) return;

		var el = buildBanner();
		if (!el) return;
		el.classList.add('is-visible');
	}

	function hideBanner() {
		if (banner) banner.classList.remove('is-visible');
	}

	function setupInstallPrompt() {
		if (!config.enableInstallPrompt) return;

		window.addEventListener('beforeinstallprompt', function (e) {
			e.preventDefault();
			deferredPrompt = e;
			notifyListeners();    // aggiorna shortcode buttons
			showBanner();         // mostra banner automatico (se non dismissed)
		});

		window.addEventListener('appinstalled', function () {
			deferredPrompt = null;
			isInstalledState = true;
			try { localStorage.setItem(STORAGE_KEY_INSTALLED, '1'); } catch (_) {}
			hideBanner();
			notifyListeners();    // aggiorna shortcode buttons → li nasconde o mostra "installata"
		});
	}

	/* ============================================================
	 * Inizializzazione
	 * ============================================================ */
	function init() {
		// 1. Controlla subito se l'app è installata.
		isInstalledState = detectInstalled();

		// 2. Aggiorna subito i pulsanti shortcode (potrebbe essere installata già).
		updateShortcodeElements();

		// 3. Registra il click handler per i pulsanti shortcode.
		bindShortcodeButtons();

		// 4. Ascolta i cambi di display-mode (utente installa/disinstalla mentre la pagina è aperta).
		if (window.matchMedia) {
			var mq = window.matchMedia('(display-mode: standalone)');
			if (mq.addEventListener) {
				mq.addEventListener('change', function (e) {
					isInstalledState = e.matches;
					if (!e.matches) {
						// Disinstallata: reset localStorage.
						try { localStorage.removeItem(STORAGE_KEY_INSTALLED); } catch (_) {}
					}
					notifyListeners();
				});
			}
		}
	}

	// Esegui init appena il DOM è pronto.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}

	try { registerServiceWorker(); } catch (_) {}
	try { setupConnectionIndicator(); } catch (_) {}
	try { setupInstallPrompt(); } catch (_) {}
})();
