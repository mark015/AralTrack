// Gate scanner (gate/index.php): camera and USB scanning, the big result panel, today's counts, and scans saved
// on this device when there is no internet (sent by themselves when the connection is back).
(function () {
    'use strict';

    var page = document.getElementById('gate-page');
    if (!page) {
        return;
    }
    var api = page.getAttribute('data-api');
    var csrf = page.getAttribute('data-csrf');
    var staffId = page.getAttribute('data-staff');
    var resultBox = document.getElementById('gate-result');
    var input = document.getElementById('scan-input');
    var banner = document.getElementById('gate-offline');
    var recentList = document.getElementById('gate-recent');
    var roster = [];
    try { roster = JSON.parse(page.getAttribute('data-roster') || '[]'); } catch (e) { roster = []; }

    var KEY = 'sgp-gate:';
    function load(name, fallback) {
        try {
            var value = localStorage.getItem(KEY + name);
            return value ? JSON.parse(value) : fallback;
        } catch (e) {
            return fallback;
        }
    }
    function store(name, value) {
        try {
            localStorage.setItem(KEY + name, JSON.stringify(value));
            return true;
        } catch (e) {
            return false;
        }
    }
    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'g' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }
    function clock(seconds) {
        return new Date(seconds * 1000).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
    }
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }
    function icon(name) {
        var node = el('i', 'bi bi-' + name);
        node.setAttribute('aria-hidden', 'true');
        return node;
    }

    // ---------------------------------------------------------------- mode: Auto, Arriving, Leaving
    var modeInputs = document.querySelectorAll('input[name="gate-mode"]');
    var savedMode = load('mode', 'auto');
    modeInputs.forEach(function (radio) {
        radio.checked = radio.value === savedMode;
        radio.addEventListener('change', function () {
            store('mode', radio.value);
            if (input && !window.matchMedia('(pointer: coarse)').matches) {
                input.focus();
            }
        });
    });
    function mode() {
        var checked = document.querySelector('input[name="gate-mode"]:checked');
        return checked ? checked.value : 'auto';
    }

    // ---------------------------------------------------------------- showing results
    var KIND = {success: 'alert-success', warning: 'alert-warning', info: 'alert-info', error: 'alert-danger', offline: 'alert-warning'};

    function badge(direction, late) {
        var wrap = el('span');
        var main = el('span', 'badge ' + (direction === 'in' ? 'text-bg-success' : 'text-bg-secondary'));
        main.append(icon(direction === 'in' ? 'box-arrow-in-right' : 'box-arrow-right'), document.createTextNode(direction === 'in' ? 'In' : 'Out'));
        wrap.appendChild(main);
        if (late) {
            wrap.appendChild(document.createTextNode(' '));
            wrap.appendChild(el('span', 'badge text-bg-warning', 'Late'));
        }
        return wrap;
    }

    function showResult(data) {
        resultBox.className = 'gate-result alert mb-0 ' + (KIND[data.kind] || 'alert-secondary');
        var body = el('div', 'flex-grow-1');
        var student = data.student;
        if (student && student.name) {
            var head = el('div', 'gate-name', student.name);
            body.appendChild(head);
            if (student.section) {
                body.appendChild(el('div', 'small', student.section));
            }
        }
        if (data.accepted && student) {
            var line = el('div', 'gate-direction mt-1');
            line.appendChild(badge(data.direction || student.direction, student.late));
            line.appendChild(document.createTextNode(' ' + (student.time || '')));
            body.appendChild(line);
        }
        body.appendChild(el('div', 'small mt-1', data.message));
        var parts = [];
        if (student && student.photo) {
            var photo = el('img', 'scan-photo');
            photo.src = student.photo;
            photo.alt = 'Photo of ' + student.name;
            parts.push(photo);
        } else {
            parts.push(icon(data.accepted ? (data.direction === 'out' ? 'box-arrow-right' : 'box-arrow-in-right') : (data.kind === 'error' ? 'x-octagon-fill' : 'info-circle-fill')));
        }
        parts.push(body);
        resultBox.replaceChildren.apply(resultBox, parts);
    }

    function updateCounts(counts) {
        if (!counts) {
            return;
        }
        var values = {arrived: counts.arrived, late: counts.late, left: counts.left, inside: counts.inside, missing: Math.max(0, counts.enrolled - counts.arrived)};
        Object.keys(values).forEach(function (key) {
            var node = page.querySelector('[data-count="' + key + '"]');
            if (node) {
                node.textContent = values[key];
            }
        });
    }

    function renderRecent(list) {
        if (!list || !recentList) {
            return;
        }
        var items = list.map(function (row) {
            var li = el('li', 'list-group-item d-flex justify-content-between align-items-center gap-2');
            var who = el('span', '', row.name);
            if (row.section) {
                who.appendChild(el('span', 'd-block small text-secondary', row.section));
            }
            var when = el('span', 'text-end text-nowrap');
            when.appendChild(badge(row.direction, row.late));
            when.appendChild(el('span', 'd-block small text-secondary', row.time + (row.offline ? ' · saved offline' : '')));
            li.append(who, when);
            return li;
        });
        if (!items.length) {
            items = [el('li', 'list-group-item text-secondary small', 'No scans yet today.')];
        }
        recentList.replaceChildren.apply(recentList, items);
    }

    function addRecentOffline(row) {
        if (!recentList) {
            return;
        }
        var empty = recentList.querySelector('[data-empty]');
        if (empty) {
            empty.remove();
        }
        var li = el('li', 'list-group-item d-flex justify-content-between align-items-center gap-2');
        var who = el('span', '', row.name);
        who.appendChild(el('span', 'd-block small text-secondary', row.section || ''));
        var when = el('span', 'text-end text-nowrap');
        when.appendChild(badge(row.direction, false));
        when.appendChild(el('span', 'd-block small text-secondary', row.time + ' · saved offline'));
        li.append(who, when);
        recentList.prepend(li);
        while (recentList.children.length > 8) {
            recentList.lastElementChild.remove();
        }
    }

    // A short beep: high for a recorded scan, low for a problem. Ignored if the browser blocks sound.
    var audio = null;
    function beep(ok) {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            var osc = audio.createOscillator();
            var gain = audio.createGain();
            osc.type = 'sine';
            osc.frequency.value = ok ? 880 : 220;
            gain.gain.value = 0.15;
            osc.connect(gain);
            gain.connect(audio.destination);
            osc.start();
            osc.stop(audio.currentTime + (ok ? 0.12 : 0.35));
        } catch (e) { /* no sound */ }
    }

    // ---------------------------------------------------------------- talking to the server
    function post(fields) {
        var body = new URLSearchParams(fields);
        body.set('_csrf', csrf);
        return fetch(api, {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) {
                return response.json().then(function (data) {
                    data.status = response.status;
                    return data;
                }, function () {
                    return {expired: true, kind: 'error', message: 'This page has expired. It reloads by itself.'};
                });
            }, function () {
                return {offline: true};
            });
    }

    // ---------------------------------------------------------------- without internet
    // Scans are kept in this browser (with the learner's id, never the card code) and sent when the connection
    // is back. Cards are recognised from SHA-256 fingerprints of this school's cards (data-roster).
    var queueKey = 'queue:' + staffId;
    var signedOut = false;

    function queue() { return load(queueKey, []); }
    function seen() {
        var map = load('seen:' + staffId, null);
        return map && map.date === today() ? map : {date: today(), students: {}};
    }
    function remember(studentId, direction, at) {
        var map = seen();
        map.students[studentId] = {direction: direction, at: at};
        store('seen:' + staffId, map);
    }

    function showBanner(text) {
        var n = queue().length;
        if (!text && !n) {
            banner.classList.add('d-none');
            return;
        }
        banner.classList.remove('d-none');
        banner.textContent = text || (n + ' scan' + (n === 1 ? ' is' : 's are') + ' saved on this device and will be sent when the internet is back. Keep this page open.');
    }

    function tokenFromScan(code) {
        code = String(code || '').replace(/\s+/g, '').toLowerCase();
        if (code.indexOf('sgp') === 0) {
            code = code.substring(3);
        }
        return /^[0-9a-f]{32}$/.test(code) ? code : null;
    }
    function sha256(text) {
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buffer) {
            return Array.prototype.map.call(new Uint8Array(buffer), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        });
    }

    function offlineScan(code, reason) {
        var token = tokenFromScan(code);
        if (!token) {
            showResult({kind: 'error', message: reason + ' A typed LRN can only be checked online; scan the card instead.'});
            beep(false);
            return;
        }
        if (!window.crypto || !crypto.subtle) {
            showResult({kind: 'error', message: reason + ' This browser cannot save scans offline (open the page with https://).'});
            beep(false);
            return;
        }
        sha256(token).then(function (hash) {
            var learner = roster.filter(function (l) { return l.hash === hash; })[0];
            if (!learner) {
                showResult({kind: 'error', message: reason + ' This card is not one of this school\'s learners (or it was replaced).'});
                beep(false);
                return;
            }
            var at = Math.floor(Date.now() / 1000);
            var last = seen().students[learner.id];
            if (last && at - last.at < 120) {
                showResult({kind: 'info', student: learner, message: learner.name + ' already scanned at ' + clock(last.at) + '.'});
                beep(false);
                return;
            }
            var m = mode();
            var direction = m === 'auto' ? (last && last.direction === 'in' ? 'out' : 'in') : m;
            var list = queue();
            list.push({uuid: uuid(), studentId: learner.id, at: at, mode: m});
            if (!store(queueKey, list)) {
                showResult({kind: 'error', message: 'This device has no room to save more scans. Connect to the internet.'});
                beep(false);
                return;
            }
            remember(learner.id, direction, at);
            showResult({kind: 'offline', accepted: true, direction: direction,
                student: {name: learner.name, section: learner.section, time: clock(at), direction: direction},
                message: reason + ' Saved on this device; it is sent when the internet is back.'});
            addRecentOffline({name: learner.name, section: learner.section, direction: direction, time: clock(at)});
            beep(true);
            showBanner();
        });
    }

    var syncing = false;
    function sync() {
        var list = queue();
        if (syncing || !list.length || signedOut) {
            return;
        }
        syncing = true;
        post({action: 'sync', events: JSON.stringify(list)}).then(function (data) {
            syncing = false;
            if (data.offline) {
                showBanner();
                return;
            }
            if (data.signin || data.status === 401) {
                signedOut = true;
                showBanner('You were signed out. ' + list.length + ' scan(s) are saved on this device: sign in again on this device to send them.');
                return;
            }
            if (data.expired) {
                location.reload(); // a saved copy of the page: get a fresh one, then send
                return;
            }
            if (!data.ok || !data.results) {
                showBanner(data.message || 'The saved scans could not be sent yet.');
                return;
            }
            var done = {};
            data.results.forEach(function (r) { done[r.uuid] = true; });
            store(queueKey, queue().filter(function (e) { return !done[e.uuid]; }));
            updateCounts(data.counts);
            renderRecent(data.recent);
            showBanner(data.results.length === 1 ? '1 scan saved on this device was sent.' : data.results.length + ' scans saved on this device were sent.');
            setTimeout(function () { showBanner(); }, 5000);
        });
    }

    // ---------------------------------------------------------------- scanning
    var lastCode = '';
    var lastTime = 0;
    function scan(code) {
        code = (code || '').trim();
        if (!code) {
            return;
        }
        var now = Date.now();
        if (code === lastCode && now - lastTime < 4000) {
            return; // the camera reads the same card several times a second
        }
        lastCode = code;
        lastTime = now;
        if (signedOut) {
            offlineScan(code, 'You are signed out.');
            return;
        }
        post({action: 'scan', code: code, mode: mode()}).then(function (data) {
            if (data.offline) {
                offlineScan(code, 'No internet.');
                return;
            }
            if (data.signin || data.status === 401) {
                signedOut = true;
                offlineScan(code, 'You were signed out.');
                showBanner('You were signed out. Scans are saved on this device: sign in again on this device to send them.');
                return;
            }
            if (data.expired) {
                offlineScan(code, 'The page expired.');
                setTimeout(function () { location.reload(); }, 1500);
                return;
            }
            showResult(data);
            beep(Boolean(data.accepted));
            if (data.accepted && data.student) {
                remember(data.student.id, data.direction, Math.floor(Date.now() / 1000));
            }
            updateCounts(data.counts);
            if (data.recent) {
                renderRecent(data.recent);
            }
            sync(); // the internet is there: send anything saved earlier
        });
    }

    if (input) {
        if (!window.matchMedia('(pointer: coarse)').matches) {
            input.focus();
        }
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lastCode = ''; // typing the same LRN twice on purpose is allowed
                scan(input.value);
                input.value = '';
            }
        });
    }

    // ---------------------------------------------------------------- camera (HTTPS pages or localhost only)
    var toggle = document.getElementById('camera-toggle');
    var note = document.getElementById('camera-note');
    var wakeLock = null;
    function keepScreenOn(on) {
        try {
            if (on && navigator.wakeLock && !wakeLock) {
                navigator.wakeLock.request('screen').then(function (lock) { wakeLock = lock; }).catch(function () {});
            } else if (!on && wakeLock) {
                wakeLock.release().catch(function () {});
                wakeLock = null;
            }
        } catch (e) { /* no wake lock */ }
    }
    if (toggle) {
        if (!window.isSecureContext || !navigator.mediaDevices || typeof Html5Qrcode === 'undefined') {
            toggle.disabled = true;
            var link = el('a', '', 'open this page with https://');
            link.href = 'https://' + location.host + location.pathname + location.search;
            note.replaceChildren(document.createTextNode('The camera only works on a secure page: '), link,
                document.createTextNode(', or use a USB QR scanner in the box below.'));
        } else {
            var scanner = null;
            var picker = document.getElementById('camera-select');
            var config = {
                fps: 10,
                qrbox: function (width, height) {
                    var side = Math.max(140, Math.floor(Math.min(width, height) * 0.75));
                    return {width: side, height: side};
                }
            };
            var explain = function (error) {
                var text = String(error && (error.name || error.message) ? (error.name + ' ' + error.message) : error);
                if (/NotAllowed|Permission|denied/i.test(text)) {
                    return 'Camera access is blocked for this site. Tap the lock or camera icon next to the address, allow the camera, then press Start camera again.';
                }
                if (/NotFound|Requested device not found|no camera/i.test(text)) {
                    return 'No camera was found on this device. Use a phone or tablet with a camera, or a USB QR scanner below.';
                }
                if (/NotReadable|Could not start video source|in use/i.test(text)) {
                    return 'The camera is being used by another app or tab. Close it and press Start camera again.';
                }
                return 'Could not start the camera (' + text + '). Allow camera access for this site, or use a USB scanner below.';
            };
            var start = function (camera) {
                scanner = new Html5Qrcode('camera');
                toggle.disabled = true;
                note.textContent = 'Starting the camera…';
                return scanner.start(camera, config, scan, function () {})
                    .then(function () {
                        toggle.disabled = false;
                        toggle.textContent = 'Stop camera';
                        note.textContent = 'Hold each card in front of the camera. It reads the card by itself.';
                        keepScreenOn(true);
                        if (picker && picker.options.length === 0) {
                            Html5Qrcode.getCameras().then(function (cameras) {
                                if (cameras.length < 2) {
                                    return;
                                }
                                cameras.forEach(function (cam, i) {
                                    picker.add(new Option(cam.label || ('Camera ' + (i + 1)), cam.id));
                                });
                                picker.hidden = false;
                            }).catch(function () {});
                        }
                    })
                    .catch(function (error) {
                        toggle.disabled = false;
                        toggle.textContent = 'Start camera';
                        try { scanner.clear(); } catch (e) { /* nothing to clear */ }
                        scanner = null;
                        note.textContent = explain(error);
                    });
            };
            var stop = function () {
                if (!scanner) {
                    return Promise.resolve();
                }
                var running = scanner;
                scanner = null;
                keepScreenOn(false);
                return running.stop().catch(function () {}).then(function () {
                    try { running.clear(); } catch (e) { /* already cleared */ }
                    toggle.textContent = 'Start camera';
                });
            };
            toggle.addEventListener('click', function () {
                if (scanner) {
                    stop();
                } else {
                    start(picker && picker.value ? {deviceId: {exact: picker.value}} : {facingMode: 'environment'});
                }
            });
            if (picker) {
                picker.addEventListener('change', function () {
                    stop().then(function () { start({deviceId: {exact: picker.value}}); });
                });
            }
            // The screen lock is released when the page is hidden; take it again when the guard comes back
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible' && scanner) {
                    wakeLock = null;
                    keepScreenOn(true);
                }
            });
        }
    }

    // ---------------------------------------------------------------- keeping up to date
    // Other gates (a second phone) change the counts too: refresh them every minute.
    function refresh() {
        if (!navigator.onLine || signedOut) {
            return;
        }
        fetch(api + '?action=today', {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    updateCounts(data.counts);
                    renderRecent(data.recent);
                }
            })
            .catch(function () {});
    }
    showBanner();
    sync();
    window.addEventListener('online', sync);
    window.addEventListener('offline', function () { showBanner(); });
    setInterval(sync, 20000);
    setInterval(refresh, 60000);
})();
