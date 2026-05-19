/**
 * PWA Core - Service Worker
 *
 * Strategie:
 * - Network-First per HTML
 * - Stale-While-Revalidate per CSS/JS/font
 * - Cache-First per immagini
 * - Skip media (Range request)
 * - Bypass admin, login, REST, wc-ajax, risposte personalizzate
 *
 * Placeholder sostituiti server-side.
 */

'use strict';

const CACHE_VERSION = '__CACHE_VERSION__';
const SITE_HOST = '__SITE_HOST__';
const OFFLINE_URL = '__OFFLINE_URL__';
const PRECACHE_URLS = __PRECACHE_URLS__;
const EXCLUDE_PATTERNS = __EXCLUDE_PATTERNS__;

const CACHE_PAGES = `pwa-core-pages-${CACHE_VERSION}`;
const CACHE_ASSETS = `pwa-core-assets-${CACHE_VERSION}`;
const CACHE_IMAGES = `pwa-core-images-${CACHE_VERSION}`;

const ALL_CACHES = [CACHE_PAGES, CACHE_ASSETS, CACHE_IMAGES];

// Limiti configurabili dall'admin (sostituiti server-side).
const CACHE_LIMITS = {
	[CACHE_PAGES]: __CACHE_PAGES_LIMIT__,
	[CACHE_ASSETS]: __CACHE_ASSETS_LIMIT__,
	[CACHE_IMAGES]: __CACHE_IMAGES_LIMIT__
};

/* ============================================================ */
self.addEventListener('install', (event) => {
	event.waitUntil(
		(async () => {
			const cache = await caches.open(CACHE_PAGES);
			await Promise.all(
				(PRECACHE_URLS || []).map(async (url) => {
					try {
						const response = await fetch(url, {
							credentials: 'omit',
							cache: 'no-cache',
							redirect: 'follow'
						});
						if (response && response.ok && response.status === 200 && !response.redirected) {
							await cache.put(url, response);
						} else if (response && response.ok && response.redirected) {
							const finalResponse = await fetch(response.url, {
								credentials: 'omit',
								cache: 'no-cache'
							});
							if (finalResponse && finalResponse.ok) {
								await cache.put(url, finalResponse.clone());
								await cache.put(response.url, finalResponse);
							}
						}
					} catch (_) {}
				})
			);
			await self.skipWaiting();
		})()
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		(async () => {
			const keys = await caches.keys();
			await Promise.all(
				keys.map((key) => {
					if (!ALL_CACHES.includes(key) && key.startsWith('pwa-core-')) {
						return caches.delete(key);
					}
					return Promise.resolve();
				})
			);
			await self.clients.claim();
		})()
	);
});

self.addEventListener('fetch', (event) => {
	const request = event.request;
	if (request.method !== 'GET') return;
	if (!request.url.startsWith('http://') && !request.url.startsWith('https://')) return;

	let url;
	try {
		url = new URL(request.url);
	} catch (_) {
		return;
	}
	if (url.host !== SITE_HOST) return;
	if (request.destination === 'video' || request.destination === 'audio') return;
	if (request.headers.get('range')) return;
	if (isExcluded(url)) return;

	if (request.mode === 'navigate' || isHTMLRequest(request)) {
		event.respondWith(handlePageRequest(request));
		return;
	}
	if (isImageRequest(request, url)) {
		event.respondWith(handleImageRequest(request));
		return;
	}
	if (isStaticAsset(url)) {
		event.respondWith(handleAssetRequest(request));
		return;
	}
});

async function handlePageRequest(request) {
	const cache = await caches.open(CACHE_PAGES);
	try {
		const networkResponse = await fetch(request);
		if (
			networkResponse &&
			networkResponse.ok &&
			networkResponse.status === 200 &&
			networkResponse.type === 'basic' &&
			!networkResponse.redirected &&
			!isPersonalizedResponse(networkResponse)
		) {
			const clone = networkResponse.clone();
			cache.put(request, clone)
				.then(() => trimCache(CACHE_PAGES, CACHE_LIMITS[CACHE_PAGES]))
				.catch(() => {});
		}
		return networkResponse;
	} catch (_) {
		const cachedResponse = await cache.match(request);
		if (cachedResponse) return cachedResponse;
		const offlineResponse = await cache.match(OFFLINE_URL);
		if (offlineResponse) return offlineResponse;
		return new Response(
			'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title></head><body style="font-family:system-ui,sans-serif;padding:2rem;text-align:center"><h1>You are offline</h1><p>Please try again when you are back online.</p><a href="/" style="display:inline-block;margin-top:1rem;padding:.75rem 1.5rem;background:#2271b1;color:#fff;text-decoration:none;border-radius:6px">Home</a></body></html>',
			{
				status: 503,
				statusText: 'Service Unavailable',
				headers: { 'Content-Type': 'text/html; charset=utf-8' }
			}
		);
	}
}

async function handleAssetRequest(request) {
	const cache = await caches.open(CACHE_ASSETS);
	const cachedResponse = await cache.match(request);
	const networkPromise = fetch(request)
		.then((response) => {
			if (
				response &&
				response.ok &&
				response.status === 200 &&
				response.type === 'basic' &&
				!response.redirected
			) {
				const clone = response.clone();
				cache.put(request, clone)
					.then(() => trimCache(CACHE_ASSETS, CACHE_LIMITS[CACHE_ASSETS]))
					.catch(() => {});
			}
			return response;
		})
		.catch(() => null);

	if (cachedResponse) {
		networkPromise.catch(() => {});
		return cachedResponse;
	}
	const fromNetwork = await networkPromise;
	if (fromNetwork) return fromNetwork;
	return fetch(request).catch(() => Response.error());
}

async function handleImageRequest(request) {
	const cache = await caches.open(CACHE_IMAGES);
	const cachedResponse = await cache.match(request);
	if (cachedResponse) return cachedResponse;

	try {
		const networkResponse = await fetch(request);
		if (
			networkResponse &&
			networkResponse.ok &&
			networkResponse.status === 200 &&
			(networkResponse.type === 'basic' || networkResponse.type === 'cors') &&
			!networkResponse.redirected
		) {
			const clone = networkResponse.clone();
			cache.put(request, clone)
				.then(() => trimCache(CACHE_IMAGES, CACHE_LIMITS[CACHE_IMAGES]))
				.catch(() => {});
		}
		return networkResponse;
	} catch (_) {
		return new Response(
			'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>',
			{ headers: { 'Content-Type': 'image/svg+xml' } }
		);
	}
}

function isExcluded(url) {
	const fullPath = url.pathname + url.search;
	for (let i = 0; i < EXCLUDE_PATTERNS.length; i++) {
		if (fullPath.indexOf(EXCLUDE_PATTERNS[i]) !== -1) return true;
	}
	return false;
}

function isHTMLRequest(request) {
	const accept = request.headers.get('accept') || '';
	return accept.indexOf('text/html') !== -1;
}

function isImageRequest(request, url) {
	if (request.destination === 'image') return true;
	return /\.(?:png|jpe?g|gif|webp|avif|svg|ico|bmp)$/i.test(url.pathname);
}

function isStaticAsset(url) {
	return /\.(?:css|js|mjs|woff2?|ttf|otf|eot)$/i.test(url.pathname);
}

function isPersonalizedResponse(response) {
	const vary = (response.headers.get('vary') || '').toLowerCase();
	if (vary.indexOf('cookie') !== -1 || vary === '*') return true;
	if (response.headers.get('set-cookie')) return true;
	const cc = (response.headers.get('cache-control') || '').toLowerCase();
	if (cc.indexOf('private') !== -1 || cc.indexOf('no-store') !== -1) return true;
	if (response.headers.get('authorization')) return true;
	return false;
}

async function trimCache(cacheName, maxEntries) {
	try {
		const cache = await caches.open(cacheName);
		const keys = await cache.keys();
		if (keys.length <= maxEntries) return;
		const toRemove = keys.length - maxEntries;
		for (let i = 0; i < toRemove; i++) {
			await cache.delete(keys[i]);
		}
	} catch (_) {}
}

self.addEventListener('message', (event) => {
	if (!event.data || typeof event.data !== 'object') return;

	if (event.data.type === 'SKIP_WAITING') {
		self.skipWaiting();
		return;
	}

	if (event.data.type === 'CLEAR_CACHES') {
		event.waitUntil(
			(async () => {
				const keys = await caches.keys();
				await Promise.all(
					keys.filter((k) => k.startsWith('pwa-core-')).map((k) => caches.delete(k))
				);
				if (event.ports && event.ports[0]) {
					event.ports[0].postMessage({ ok: true });
				}
			})()
		);
		return;
	}

	if (event.data.type === 'USER_LOGGED_IN') {
		event.waitUntil(
			(async () => {
				const keys = await caches.keys();
				await Promise.all(
					keys.filter((k) => k.startsWith('pwa-core-')).map((k) => caches.delete(k))
				);
				await self.registration.unregister();
			})()
		);
	}
});
