// Service worker: lets the portal be installed as an app, and shows a friendly page when offline.
// Only static files (styles, scripts, fonts, icons) are cached. Pages with grades, names or attendance
// are never stored on the device.
'use strict';
const VERSION = 'sgp-static-v1';
const scope = self.registration.scope;
const OFFLINE = new URL('offline.html', scope).href;
const ASSETS = new URL('assets/', scope).pathname;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll([OFFLINE, new URL('assets/icons/icon-192.png', scope).href])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Pages always come from the server; when there is no connection, show the offline page.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }

    // Static files: answer from the cache at once, refresh it in the background.
    if (url.pathname.startsWith(ASSETS)) {
        event.respondWith(caches.open(VERSION).then(async (cache) => {
            const cached = await cache.match(request);
            const network = fetch(request).then(async (response) => {
                if (response.ok) {
                    // keep only the newest version (?v=…) of each file
                    for (const key of await cache.keys()) {
                        const old = new URL(key.url);
                        if (old.pathname === url.pathname && old.search !== url.search) await cache.delete(key);
                    }
                    await cache.put(request, response.clone());
                }
                return response;
            }).catch(() => cached || Response.error());
            return cached || network;
        }));
    }
});
