// Phone alerts for parents (web push): the "Alerts on this phone" card on the Notifications page, and the hint
// on the parent home page. The server side is parent/push.php; the alerts are shown by sw.js.
(function () {
    'use strict';

    var card = document.getElementById('push-card');
    var hint = document.getElementById('push-hint');
    var source = card || hint;
    if (!source) {
        return;
    }
    var api = source.getAttribute('data-api');
    var csrf = source.getAttribute('data-csrf');
    var publicKey = source.getAttribute('data-key');
    var swUrl = source.getAttribute('data-sw');

    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
    var ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

    function keyBytes(base64url) {
        var padded = (base64url + '===='.slice((base64url.length % 4) || 4)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(padded);
        var bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            bytes[i] = raw.charCodeAt(i);
        }
        return bytes;
    }

    function send(action, subscription) {
        var body = new URLSearchParams({_csrf: csrf, action: action});
        if (subscription) {
            var json = subscription.toJSON();
            body.set('endpoint', json.endpoint || '');
            body.set('p256dh', (json.keys && json.keys.p256dh) || '');
            body.set('auth', (json.keys && json.keys.auth) || '');
        }
        return fetch(api, {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (r) { return r.json(); })
            .catch(function () { return {ok: false, message: 'No connection. Try again when you are online.'}; });
    }

    function registration() {
        return navigator.serviceWorker.getRegistration().then(function (reg) {
            return reg || navigator.serviceWorker.register(swUrl);
        }).then(function () {
            return navigator.serviceWorker.ready;
        });
    }

    function current() {
        if (!supported) {
            return Promise.resolve(null);
        }
        return registration().then(function (reg) { return reg.pushManager.getSubscription(); }).catch(function () { return null; });
    }

    // ---------------------------------------------------------------- the card
    var statusText = card ? card.querySelector('[data-push-status]') : null;
    var buttons = card ? {
        on: card.querySelector('[data-push-on]'),
        off: card.querySelector('[data-push-off]'),
        test: card.querySelector('[data-push-test]')
    } : {};
    var note = card ? card.querySelector('[data-push-note]') : null;

    function show(state, message) {
        if (!card) {
            return;
        }
        card.hidden = false;
        var texts = {
            on: 'On for this phone. You get a pop-up when your child arrives at or leaves school, is late or absent, and for new grades and messages.',
            off: 'Get a pop-up on this phone when your child arrives at or leaves school, is late or absent, and for new grades and messages. Free; no load needed.',
            blocked: 'Alerts are blocked for this site in your browser. Allow notifications for this site in the browser\'s settings, then reload this page.',
            ios: 'On iPhone and iPad: tap the Share button, then "Add to Home Screen". Open the portal from your home screen, then turn on alerts here.',
            unsupported: 'This browser can\'t show alerts. Use Chrome, Edge, Firefox or Safari (on iPhone: from the home screen).',
            busy: 'One moment…'
        };
        statusText.textContent = texts[state] || '';
        buttons.on.hidden = state !== 'off';
        buttons.off.hidden = state !== 'on';
        buttons.test.hidden = state !== 'on';
        [buttons.on, buttons.off, buttons.test].forEach(function (b) { b.disabled = state === 'busy'; });
        note.textContent = message || '';
        card.setAttribute('data-state', state);
    }

    function refresh(message) {
        if (!supported) {
            show(ios && !standalone ? 'ios' : 'unsupported');
            return Promise.resolve();
        }
        if (Notification.permission === 'denied') {
            show('blocked');
            return Promise.resolve();
        }
        return current().then(function (subscription) {
            show(subscription ? 'on' : 'off', message);
            return subscription;
        });
    }

    function turnOn() {
        show('busy');
        Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                return refresh(permission === 'denied' ? '' : 'Tap Allow when the browser asks, to get alerts.');
            }
            return registration().then(function (reg) {
                var options = {userVisibleOnly: true, applicationServerKey: keyBytes(publicKey)};
                return reg.pushManager.subscribe(options).catch(function () {
                    // Subscribed earlier with another key: start again
                    return reg.pushManager.getSubscription().then(function (old) {
                        return (old ? old.unsubscribe() : Promise.resolve()).then(function () { return reg.pushManager.subscribe(options); });
                    });
                });
            }).then(function (subscription) {
                return send('subscribe', subscription).then(function (data) {
                    if (!data.ok) {
                        return subscription.unsubscribe().then(function () { return refresh(data.message); });
                    }
                    return refresh(data.message);
                });
            });
        }).catch(function () {
            refresh('Alerts could not be turned on in this browser. Try again, or use another browser.');
        });
    }

    function turnOff() {
        show('busy');
        current().then(function (subscription) {
            if (!subscription) {
                return refresh();
            }
            return send('unsubscribe', subscription).then(function () {
                return subscription.unsubscribe();
            }).then(function () { return refresh('Alerts are off for this phone.'); });
        }).catch(function () { refresh(); });
    }

    function test() {
        current().then(function (subscription) {
            if (!subscription) {
                return refresh();
            }
            note.textContent = 'Sending…';
            return send('test', subscription).then(function (data) { note.textContent = data.message || ''; });
        });
    }

    if (card) {
        buttons.on.addEventListener('click', turnOn);
        buttons.off.addEventListener('click', turnOff);
        buttons.test.addEventListener('click', test);
        refresh().then(function (subscription) {
            // Already on in this browser: tell the server again (e.g. after signing in to another school)
            if (subscription) {
                send('subscribe', subscription);
            }
        });
    }

    // ---------------------------------------------------------------- the hint on the home page
    if (hint) {
        var dismissed = false;
        try { dismissed = localStorage.getItem('sgp-push-hint') === 'no'; } catch (e) { /* ignore */ }
        if (dismissed) {
            return;
        }
        var close = hint.querySelector('[data-push-dismiss]');
        if (close) {
            close.addEventListener('click', function () {
                hint.hidden = true;
                try { localStorage.setItem('sgp-push-hint', 'no'); } catch (e) { /* ignore */ }
            });
        }
        if (!supported) {
            if (ios && !standalone) {
                hint.hidden = false; // explains adding to the home screen
            }
            return;
        }
        if (Notification.permission === 'denied') {
            return;
        }
        current().then(function (subscription) {
            if (subscription) {
                send('subscribe', subscription);
            } else {
                hint.hidden = false;
            }
        });
    }
})();
