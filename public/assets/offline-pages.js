// Offline viewing: keeps copies of this person's pages on the device, so they can be read without internet.
// The server marks the pages that may be kept (X-Offline-Save header + sgp-offline-key) and lists the main
// pages to save after sign-in (sgp-offline-preload). The service worker (sw.js) saves pages as they are opened
// and shows the saved copy when there is no connection.
//
// On by default in the installed app; in a normal browser only after "Offline copy" is turned on in the menu.
// Another person signing in on the device, or turning it off, deletes the saved pages; signing out too (sw.js).
(function () {
    'use strict';
    var CACHE = 'sgp-pages-v1';
    var SETTINGS_NAME = '__offline-pages';
    var CHOICE = 'sgp-keep-pages';       // '1' on, '0' off, missing: on only in the installed app
    var PRELOAD_EVERY = 3 * 3600 * 1000;  // refresh the main pages at most every 3 hours

    if (!('caches' in window) || !('serviceWorker' in navigator)) return;
    var keyMeta = document.querySelector('meta[name="sgp-offline-key"]');
    if (!keyMeta) return;
    var key = keyMeta.content;
    var preload = [];
    try { preload = JSON.parse((document.querySelector('meta[name="sgp-offline-preload"]') || {}).content || '[]'); } catch (e) { preload = []; }

    var keepThisPage = !!document.querySelector('meta[name="sgp-offline-page"]');
    var forcePreload = false;
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    function choice() { try { return localStorage.getItem(CHOICE); } catch (e) { return null; } }
    function enabled() { var c = choice(); return c === '1' || (c === null && standalone); }

    /** A page as it is kept: the HTML plus when it was saved, its title and whose it is. */
    function stamp(html) {
        var title = (html.match(/<title>([\s\S]*?)<\/title>/i) || [])[1] || '';
        title = title.replace(/&amp;/g, '&').replace(/&#039;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/\s*·[^·]*$/, '').trim();
        return new Response(html, {headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'X-Offline-Save': key,
            'X-Saved-At': String(Date.now()),
            'X-Title': encodeURIComponent(title),
        }});
    }

    async function save(cache, url) {
        try {
            var response = await fetch(url, {credentials: 'same-origin'});
            // Only this person's own pages: a sign-in page or another account's page has no or another key.
            if (!response.ok || response.headers.get('X-Offline-Save') !== key) return;
            await cache.put(new URL(url, location.href).href, stamp(await response.text()));
        } catch (e) { /* offline or the server is busy: try again next time */ }
    }

    var busy = false;
    async function apply() {
        if (busy) return;
        busy = true;
        try {
            var registration = await navigator.serviceWorker.ready;
            var settingsUrl = registration.scope + SETTINGS_NAME;
            var has = await caches.has(CACHE);
            var cache = has ? await caches.open(CACHE) : null;
            var saved = cache ? await cache.match(settingsUrl) : null;
            var settings = saved ? await saved.json() : null;

            if (!enabled()) {
                if (has) await caches.delete(CACHE); // turned off: nothing stays on the device
                return;
            }
            if (settings && settings.key !== key) {
                await caches.delete(CACHE); // someone else signed in on this device
                settings = null;
            }
            cache = await caches.open(CACHE);
            settings = {key: key, on: true, preloadedAt: settings && !forcePreload ? settings.preloadedAt : 0};
            forcePreload = false;
            await cache.put(settingsUrl, new Response(JSON.stringify(settings), {headers: {'Content-Type': 'application/json'}}));

            if (!navigator.onLine) return;
            // This page, the first time (the service worker keeps it up to date on later visits)
            if (keepThisPage && !(await cache.match(location.href))) await save(cache, location.href);
            if (Date.now() - settings.preloadedAt > PRELOAD_EVERY) {
                for (var i = 0; i < preload.length; i++) await save(cache, preload[i]);
                settings.preloadedAt = Date.now();
                await cache.put(settingsUrl, new Response(JSON.stringify(settings), {headers: {'Content-Type': 'application/json'}}));
            }
        } catch (e) {
            /* storage full or blocked (private window): pages are simply not kept */
        } finally {
            busy = false;
            showState();
        }
    }

    function showState() {
        var on = enabled();
        document.querySelectorAll('[data-offline-state]').forEach(function (badge) {
            badge.textContent = on ? 'On' : 'Off';
            badge.className = 'badge rounded-pill ms-auto ' + (on ? 'text-bg-success' : 'text-bg-secondary');
        });
    }

    document.querySelectorAll('[data-offline-toggle]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation(); // keep the menu open to show the new state
            var turnOn = !enabled();
            try { localStorage.setItem(CHOICE, turnOn ? '1' : '0'); } catch (e) { /* private mode */ }
            forcePreload = turnOn; // save the main pages right away
            showState();
            apply();
        });
    });

    // Signing out deletes the saved pages (sw.js does it too, for sign-outs it sees)
    document.querySelectorAll('form[action$="logout.php"]').forEach(function (form) {
        form.addEventListener('submit', function () { caches.delete(CACHE); });
    });

    showState();
    if (document.readyState === 'complete') apply();
    else window.addEventListener('load', apply);
})();
