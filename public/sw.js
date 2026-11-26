// Service worker: lets the portal be installed as an app, shows a friendly page when offline, keeps the offline
// attendance page (offline-attendance.html) working without internet, and shows saved copies of pages
// ("Offline copy", see assets/offline-pages.js).
//
// Pages are only kept when the server marks them as this person's own (X-Offline-Save header) and the device has
// offline copies turned on. Another person signing in, turning it off, or signing out deletes them.
'use strict';
const VERSION = 'sgp-static-v2';
const SHELL = 'sgp-offline-shell-v1';
const PAGES = 'sgp-pages-v1';
const scope = self.registration.scope;
const OFFLINE = new URL('offline.html', scope).href;
const ATTENDANCE = new URL('offline-attendance.html', scope).href;
const SETTINGS = new URL('__offline-pages', scope).href;
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
            .then((keys) => Promise.all(keys.filter((key) => ![VERSION, SHELL, PAGES].includes(key)).map((key) => caches.delete(key))))
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

/** Offline copies on this device: {key, on} of the signed-in person, or null when turned off. */
async function pageSettings() {
    if (!(await caches.has(PAGES))) return null;
    const saved = await (await caches.open(PAGES)).match(SETTINGS);
    const settings = saved ? await saved.json() : null;
    return settings && settings.on ? settings : null;
}

/** Saves a page just loaded from the server, if it is this person's and offline copies are on. */
async function keepPage(url, response) {
    const settings = await pageSettings();
    if (!settings || !response.ok || response.headers.get('X-Offline-Save') !== settings.key) return;
    const html = await response.text();
    const title = ((html.match(/<title>([\s\S]*?)<\/title>/i) || [])[1] || '')
        .replace(/&amp;/g, '&').replace(/&#039;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/\s*·[^·]*$/, '').trim();
    await (await caches.open(PAGES)).put(url, new Response(html, {headers: {
        'Content-Type': 'text/html; charset=utf-8',
        'X-Offline-Save': settings.key,
        'X-Saved-At': String(Date.now()),
        'X-Title': encodeURIComponent(title),
    }}));
}

/** The saved copy of a page, read-only, with a bar saying it is a saved copy. Null when there is none. */
async function savedCopy(url) {
    const settings = await pageSettings();
    if (!settings) return null;
    const saved = await (await caches.open(PAGES)).match(url);
    if (!saved || saved.headers.get('X-Offline-Save') !== settings.key) return null;
    const when = new Date(Number(saved.headers.get('X-Saved-At')) || Date.now())
        .toLocaleString([], {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'});
    const bar = '<div role="status" style="position:sticky;top:0;z-index:1090;background:#fff4d6;color:#714a00;'
        + 'border-bottom:1px solid #f6dea0;padding:8px 16px;font:600 14px/1.45 system-ui,-apple-system,sans-serif;text-align:center">'
        + 'You\'re offline · this is your saved copy from ' + when + '. Changes can\'t be saved until you\'re back online.</div>'
        + '<script>document.addEventListener("DOMContentLoaded",function(){'
        + 'document.querySelectorAll("form").forEach(function(f){if(String(f.method).toLowerCase()==="post"&&!/logout\\.php/.test(f.action)){'
        + 'f.querySelectorAll("button,input[type=submit]").forEach(function(b){b.disabled=true;});}});});'
        + 'window.addEventListener("online",function(){location.reload();});</script>';
    const html = (await saved.text()).replace(/<body[^>]*>/i, (tag) => tag + bar);
    return new Response(html, {headers: {'Content-Type': 'text/html; charset=utf-8'}});
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Signing out: delete the saved pages (the sign-out itself goes to the server as usual).
    if (request.method === 'POST' && url.pathname.endsWith('logout.php')) {
        event.waitUntil(caches.delete(PAGES));
        return;
    }
    if (request.method !== 'GET') return;

    // Pages always come from the server. Without a connection: the offline attendance page, the saved copy of
    // the page (when offline copies are on), or the "you're offline" page.
    if (request.mode === 'navigate') {
        const isAttendance = url.pathname === new URL(ATTENDANCE).pathname;
        event.respondWith(
            fetch(request)
                .then(async (response) => {
                    if (isAttendance) await refreshShell(request, response);
                    else if (response.headers.get('X-Offline-Save')) event.waitUntil(keepPage(request.url, response.clone()).catch(() => {}));
                    return response;
                })
                .catch(async () => (isAttendance ? await caches.match(ATTENDANCE, {ignoreSearch: true}) : null)
                    || (await savedCopy(request.url).catch(() => null))
                    || caches.match(OFFLINE))
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
