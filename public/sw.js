// Service worker: lets the portal be installed as an app, shows a friendly page when offline, and keeps
// the offline attendance page (offline-attendance.html) working without internet.
// Only static files are cached. Pages with grades, names or attendance are never stored by the service worker;
// the offline attendance page reads the class lists the teacher's phone saved itself.
'use strict';
const VERSION = 'sgp-static-v2';
const SHELL = 'sgp-offline-shell-v1';
const scope = self.registration.scope;
const OFFLINE = new URL('offline.html', scope).href;
const ATTENDANCE = new URL('offline-attendance.html', scope).href;
const ASSETS = new URL('assets/', scope).pathname;

// Everything the offline attendance page needs, saved when the app is installed or first opened.
const SHELL_FILES = [
    'offline.html',
    'offline-attendance.html',
    'manifest.webmanifest',
    'assets/vendor/bootstrap.min.css',
    'assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    'assets/vendor/fonts/plus-jakarta-sans-latin-400-normal.woff2',
    'assets/vendor/fonts/plus-jakarta-sans-latin-600-normal.woff2',
    'assets/vendor/fonts/plus-jakarta-sans-latin-700-normal.woff2',
    'assets/vendor/fonts/plus-jakarta-sans-latin-800-normal.woff2',
    'assets/app.css',
    'assets/vendor/html5-qrcode.min.js',
    'assets/offline-attendance.js',
    'assets/offline-page.js',
    'assets/icons/icon-192.png',
].map((path) => new URL(path, scope).href);

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll(SHELL_FILES)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION && key !== SHELL).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

/** Keeps the saved copy of an offline-shell file up to date when it is fetched online. */
async function refreshShell(request, response) {
    const plain = new URL(request.url);
    plain.search = '';
    if (response.ok && SHELL_FILES.includes(plain.href)) {
        const cache = await caches.open(SHELL);
        await cache.put(plain.href, response.clone());
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Pages always come from the server. Without a connection: the offline attendance page from the saved
    // copy, or the "you're offline" page for anything else.
    if (request.mode === 'navigate') {
        const isAttendance = url.pathname === new URL(ATTENDANCE).pathname;
        event.respondWith(
            fetch(request)
                .then(async (response) => {
                    if (isAttendance) await refreshShell(request, response);
                    return response;
                })
                .catch(async () => (isAttendance ? await caches.match(ATTENDANCE, {ignoreSearch: true}) : null) || caches.match(OFFLINE))
        );
        return;
    }

    // Static files: answer from the cache at once, refresh it in the background.
    if (url.pathname.startsWith(ASSETS) || url.pathname.endsWith('.webmanifest')) {
        event.respondWith(caches.open(VERSION).then(async (cache) => {
            const cached = (await cache.match(request)) || (await caches.match(request, {cacheName: SHELL, ignoreSearch: true}));
            const network = fetch(request).then(async (response) => {
                if (response.ok) {
                    // keep only the newest version (?v=…) of each file
                    for (const key of await cache.keys()) {
                        const old = new URL(key.url);
                        if (old.pathname === url.pathname && old.search !== url.search) await cache.delete(key);
                    }
                    await cache.put(request, response.clone());
                    await refreshShell(request, response);
                }
                return response;
            }).catch(() => cached || Response.error());
            return cached || network;
        }));
    }
});
