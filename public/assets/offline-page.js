// The offline attendance page (offline-attendance.html). Works without internet: the class lists come from
// what this phone saved while online, and the attendance is kept on the phone until it can be sent.
(function () {
    'use strict';

    var Off = window.SGPOffline;
    var app = document.getElementById('app');
    var net = document.getElementById('net');
    var who = Off.who();
    var pack = who ? Off.pack(who.staffId) : null;
    var staffId = who ? String(who.staffId) : null;
    var scanner = null;
    var lastCode = '';
    var lastTime = 0;
    var LABELS = {present: 'Present', late: 'Late', absent: 'Absent', excused: 'Excused'};
    var BADGE = {present: 'text-bg-success', late: 'text-bg-warning', absent: 'text-bg-danger', excused: 'text-bg-info'};

    function esc(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function now() { return Math.floor(Date.now() / 1000); }
    function clock(seconds) { return new Date(seconds * 1000).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}); }
    function hhmm(seconds) {
        var d = new Date(seconds * 1000);
        return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }
    function todayAt(hm) {
        var p = hm.split(':');
        var d = new Date();
        d.setHours(parseInt(p[0], 10), parseInt(p[1], 10), 0, 0);
        return Math.floor(d.getTime() / 1000);
    }
    function isToday(seconds) {
        var d = new Date(seconds * 1000);
        var t = new Date();
        return d.getFullYear() === t.getFullYear() && d.getMonth() === t.getMonth() && d.getDate() === t.getDate();
    }
    function beep(ok) {
        try {
            var ctx = beep.ctx = beep.ctx || new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.frequency.value = ok ? 880 : 220;
            gain.gain.value = 0.15;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + (ok ? 0.12 : 0.35));
        } catch (e) { /* no sound */ }
    }

    // ---------------------------------------------------------------- connection and sending
    function showNet() {
        var waiting = staffId ? Off.pending(staffId) : 0;
        net.className = 'net-pill ' + (navigator.onLine ? 'online' : 'offline');
        net.textContent = (navigator.onLine ? 'Online' : 'Offline') + (waiting ? ' · ' + waiting + ' to send' : '');
    }

    function send(manual) {
        if (!staffId || !pack || !Off.pending(staffId)) {
            showNet();
            return;
        }
        if (!navigator.onLine) {
            if (manual) {
                notice('warning', 'No internet yet. The attendance stays on this phone and is sent when you are online.');
            }
            showNet();
            return;
        }
        Off.sync(staffId, pack.csrf, pack.api).then(function (outcome) {
            showNet();
            if (outcome.signin) {
                notice('warning', 'To send the attendance, sign in again: <a href="staff/login.php">Sign in</a>. It is sent automatically after you sign in.', true);
            } else if (outcome.sent) {
                var problems = (outcome.results || []).filter(function (r) { return !r.ok && r.message; });
                var message = 'Sent ' + outcome.sent + ' attendance record(s) to the server.'
                    + (problems.length ? ' Not sent: ' + problems.map(function (r) { return esc(r.message); }).join(' ') : '');
                if (!/^#class-/.test(location.hash)) {
                    renderList(); // refresh the list ("in progress" badges), then say what was sent
                }
                notice(problems.length ? 'warning' : 'success', message, true);
            } else if (manual && outcome.error) {
                notice('warning', esc(outcome.error), true);
            }
        });
    }

    function notice(tone, html, isHtml) {
        var box = document.getElementById('notice');
        if (!box) {
            return;
        }
        box.className = 'alert alert-' + tone;
        box.innerHTML = isHtml ? html : esc(html);
    }

    window.addEventListener('online', function () { showNet(); send(false); });
    window.addEventListener('offline', showNet);
    setInterval(function () { send(false); }, 30000);

    // ---------------------------------------------------------------- the list of classes
    function localSessionFor(classId) {
        var sessions = Off.queue(staffId).sessions;
        var keys = Object.keys(sessions).filter(function (k) {
            var s = sessions[k];
            return String(s.classId) === String(classId) && !s.finishedAt && isToday(s.startsAt);
        });
        return keys.length ? sessions[keys[0]] : null;
    }

    function renderList() {
        var today = Off.today();
        var stale = pack.date !== today;
        var classes = (pack.classes || []).slice().sort(function (a, b) {
            var at = a.today.length ? a.today[0].starts : '99';
            var bt = b.today.length ? b.today[0].starts : '99';
            return at < bt ? -1 : at > bt ? 1 : 0;
        });
        var html = '<div id="notice" class="d-none"></div>'
            + '<div class="page-head"><div><h1 class="h3">Take attendance offline</h1>'
            + '<p class="sub">' + esc(pack.staffName) + ' · class lists saved ' + esc(new Date(pack.savedAt * 1000).toLocaleString([], {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'})) + '</p></div>'
            + '<button type="button" class="btn btn-outline-primary" id="send-now"><i class="bi bi-cloud-upload me-1"></i>Send now</button></div>';
        if (stale) {
            html += '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill"></i><div>These class lists were saved on '
                + esc(pack.date) + '. New learners or replaced ID cards since then are not included. Open <a href="teacher/index.php">My classes</a> while online to update them.</div></div>';
        }
        html += '<p class="small text-secondary">The attendance is kept on this phone and sent to the server automatically when there is internet. '
            + 'Parents get their notifications then.</p><div class="row g-3">';
        classes.forEach(function (c) {
            var local = localSessionFor(c.id);
            var online = c.openSession && isToday(c.openSession.startsAt) && pack.date === today;
            var when = c.today.length ? c.today.map(function (t) { return t.starts + '–' + t.ends + (t.room ? ' · Room ' + esc(t.room) : ''); }).join(', ') : 'Not scheduled today';
            html += '<div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">'
                + '<span class="subject-chip mb-2">' + esc(c.code) + '</span>'
                + '<h2 class="h6 mb-0">' + esc(c.subject) + '</h2>'
                + '<div class="small text-secondary mb-2">' + esc(c.section) + ' · ' + c.learners.length + ' learners</div>'
                + '<div class="small mb-3"><i class="bi bi-clock me-1"></i>' + when + '</div>'
                + (local ? '<span class="badge text-bg-warning mb-2"><i class="bi bi-phone"></i>In progress on this phone</span><br>' : '')
                + (!local && online ? '<span class="badge text-bg-info mb-2">Started online at ' + clock(c.openSession.startsAt) + '</span><br>' : '')
                + '<a class="btn btn-primary btn-sm" href="#class-' + c.id + '"><i class="bi bi-qr-code-scan me-1"></i>' + (local || online ? 'Continue attendance' : 'Take attendance') + '</a>'
                + '</div></div></div>';
        });
        html += '</div>';
        if (!classes.length) {
            html += '<p class="text-secondary">No classes this semester.</p>';
        }
        app.innerHTML = html;
        document.getElementById('send-now').addEventListener('click', function () { send(true); });
    }

    // ---------------------------------------------------------------- one class
    function renderStart(c) {
        var suggested = hhmm(now());
        c.today.forEach(function (t) {
            if (Math.abs(todayAt(t.starts) - now()) < 90 * 60) {
                suggested = t.starts;
            }
        });
        app.innerHTML = breadcrumb(c)
            + '<div class="card" style="max-width: 28rem"><div class="card-body">'
            + '<h1 class="h5">' + esc(c.subject) + ' · ' + esc(c.section) + '</h1>'
            + '<label class="form-label" for="starts">Time the class started</label>'
            + '<input class="form-control mb-2" type="time" id="starts" value="' + suggested + '">'
            + '<p class="small text-secondary">Learners scanned more than ' + pack.lateAfterMinutes + ' minutes after this are Late.</p>'
            + '<button class="btn btn-primary" id="start"><i class="bi bi-play-fill me-1"></i>Start attendance</button>'
            + '</div></div>';
        document.getElementById('start').addEventListener('click', function () {
            var startsAt = todayAt(document.getElementById('starts').value || hhmm(now()));
            var uuid = Off.uuid();
            Off.session(staffId, {key: 'off-' + uuid, clientUuid: uuid, classId: c.id, startsAt: startsAt});
            renderClass(c);
        });
    }

    function breadcrumb(c) {
        return '<nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="#">Offline attendance</a></li>'
            + '<li class="breadcrumb-item active" aria-current="page">' + esc(c.subject) + '</li></ol></nav>';
    }

    function renderClass(c) {
        var session = localSessionFor(c.id);
        if (!session && c.openSession && isToday(c.openSession.startsAt)) {
            session = Off.session(staffId, {key: 'srv-' + c.openSession.id, classId: c.id, serverSessionId: c.openSession.id, startsAt: c.openSession.startsAt});
        }
        if (!session) {
            renderStart(c);
            return;
        }
        var lateAfter = session.startsAt + pack.lateAfterMinutes * 60;
        var counts = {present: 0, late: 0, absent: 0, excused: 0};
        Object.keys(session.statuses).forEach(function (id) {
            counts[session.statuses[id].status] = (counts[session.statuses[id].status] || 0) + 1;
        });
        var notYet = c.learners.length - Object.keys(session.statuses).length;

        var rows = c.learners.map(function (l, i) {
            var mark = session.statuses[l.id];
            var badge = mark ? '<span class="badge ' + BADGE[mark.status] + '">' + LABELS[mark.status] + '</span>' : '<span class="badge text-bg-light border">Not yet</span>';
            var options = Object.keys(LABELS).map(function (k) {
                return '<option value="' + k + '"' + (mark && mark.status === k ? ' selected' : '') + '>' + LABELS[k] + '</option>';
            }).join('');
            return '<tr data-row="' + l.id + '"><td class="text-secondary small">' + (i + 1) + '</td>'
                + '<td>' + esc(l.name) + '<div class="lrn small text-secondary">' + esc(l.lrn) + '</div></td>'
                + '<td>' + badge + '</td><td class="small text-secondary">' + (mark ? clock(mark.at) : '') + '</td>'
                + '<td><div class="d-flex gap-1"><select class="form-select form-select-sm w-auto js-mark" data-id="' + l.id + '" aria-label="Status for ' + esc(l.name) + '">'
                + (mark ? '' : '<option value="" selected disabled>Choose…</option>') + options + '</select></div></td></tr>';
        }).join('');

        app.innerHTML = breadcrumb(c)
            + '<div id="notice" class="d-none"></div>'
            + '<h1 class="h4 mb-0">' + esc(c.subject) + ' · ' + esc(c.section) + '</h1>'
            + '<p class="text-secondary">Started ' + clock(session.startsAt) + ' · Late after ' + clock(lateAfter)
            + ' · <span class="text-warning-emphasis"><i class="bi bi-phone"></i> saved on this phone</span></p>'
            + '<div class="row g-3 mb-4"><div class="col-lg-5"><div class="card"><div class="card-body">'
            + '<div id="camera" class="camera-box mb-2"></div>'
            + '<p id="camera-note" class="small text-secondary mb-2">Press Start camera, then point it at a learner\'s QR card.</p>'
            + '<div class="d-flex flex-wrap gap-2 align-items-center"><button type="button" class="btn btn-primary btn-sm" id="camera-toggle"><i class="bi bi-camera-video me-1"></i>Start camera</button>'
            + '<select id="camera-select" class="form-select form-select-sm w-auto" aria-label="Camera" hidden></select></div>'
            + '<hr><label for="scan-input" class="form-label small mb-1">USB scanner (or type the card code and press Enter)</label>'
            + '<input id="scan-input" class="form-control" autocomplete="off"></div></div></div>'
            + '<div class="col-lg-7"><div id="scan-result" class="scan-result alert alert-secondary mb-3" role="status" aria-live="polite"><i class="bi bi-qr-code-scan"></i>Ready. Scan a learner\'s QR card.</div>'
            + '<div class="d-flex flex-wrap gap-2 mb-3">'
            + ['present', 'late', 'absent', 'excused'].map(function (k) { return '<span class="badge ' + BADGE[k] + '">' + LABELS[k] + ' ' + counts[k] + '</span>'; }).join('')
            + '<span class="badge text-bg-light border">Not yet ' + notYet + '</span></div>'
            + '<button class="btn btn-danger" id="finish">Finish attendance</button></div></div>'
            + '<h2 class="h5">Learners</h2><div class="table-responsive"><table class="table table-sm align-middle shadow-sm">'
            + '<thead class="table-light"><tr><th>#</th><th>Learner</th><th>Status</th><th>Time</th><th>Mark by hand</th></tr></thead><tbody>' + rows + '</tbody></table></div>';

        wireClass(c, session, lateAfter);
    }

    function result(kind, message) {
        var box = document.getElementById('scan-result');
        var tones = {success: 'alert-success', warning: 'alert-warning', info: 'alert-info', error: 'alert-danger'};
        box.className = 'scan-result alert mb-3 ' + (tones[kind] || 'alert-secondary');
        box.textContent = message;
    }

    function wireClass(c, session, lateAfter) {
        var key = session.key;
        var input = document.getElementById('scan-input');

        function handleCode(code) {
            code = (code || '').trim();
            if (!code) {
                return;
            }
            var t = Date.now();
            if (code === lastCode && t - lastTime < 4000) {
                return;
            }
            lastCode = code;
            lastTime = t;
            Off.learnerForCode(c.learners, code).then(function (learner) {
                if (!learner) {
                    result('error', 'This card is not in this class, or it is not a learner ID.');
                    beep(false);
                    return;
                }
                var current = Off.queue(staffId).sessions[key];
                var mark = current && current.statuses[learner.id];
                if (mark && (mark.status === 'present' || mark.status === 'late')) {
                    result('info', learner.name + ' is already marked ' + LABELS[mark.status].toLowerCase() + ' (' + clock(mark.at) + ').');
                    beep(false);
                    return;
                }
                var at = now();
                var status = at > lateAfter ? 'late' : 'present';
                Off.addEvent(staffId, key, {type: 'scan', studentId: learner.id, at: at}, status);
                beep(true);
                renderClassKeepCamera(c, learner.name + ' — ' + LABELS[status] + ' at ' + clock(at), status === 'late' ? 'warning' : 'success');
                send(false);
            });
        }

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                handleCode(input.value);
                input.value = '';
            }
        });
        if (!window.matchMedia('(pointer: coarse)').matches) {
            input.focus();
        }

        document.querySelectorAll('.js-mark').forEach(function (select) {
            select.addEventListener('change', function () {
                var id = parseInt(select.getAttribute('data-id'), 10);
                Off.addEvent(staffId, key, {type: 'mark', studentId: id, status: select.value, note: '', at: now()}, select.value);
                var learner = c.learners.filter(function (l) { return l.id === id; })[0];
                renderClassKeepCamera(c, (learner ? learner.name : 'Learner') + ' marked ' + LABELS[select.value].toLowerCase() + '.', 'info');
                send(false);
            });
        });

        document.getElementById('finish').addEventListener('click', function () {
            if (!confirm('Finish attendance? Learners not marked yet will be marked absent when it is sent, and their parents will be notified then.')) {
                return;
            }
            stopCamera().then(function () {
                Off.finish(staffId, key);
                location.hash = '';
                send(false);
                setTimeout(function () {
                    notice('success', 'Attendance for ' + c.subject + ' is finished on this phone. It is sent when there is internet.');
                }, 50);
            });
        });

        wireCamera(handleCode);
        showNet();
    }

    // Re-draw the class after a scan or mark, keeping the camera running.
    var keepMessage = null;
    function renderClassKeepCamera(c, message, kind) {
        var running = scanner;
        if (running) {
            // Update the table and counts in place instead of rebuilding (the camera keeps going).
            var session = Off.queue(staffId).sessions[localSessionFor(c.id) ? localSessionFor(c.id).key : ('srv-' + (c.openSession ? c.openSession.id : ''))];
            if (session) {
                c.learners.forEach(function (l) {
                    var mark = session.statuses[l.id];
                    var row = document.querySelector('[data-row="' + l.id + '"]');
                    if (row && mark) {
                        row.children[2].innerHTML = '<span class="badge ' + BADGE[mark.status] + '">' + LABELS[mark.status] + '</span>';
                        row.children[3].textContent = clock(mark.at);
                    }
                });
            }
            result(kind, message);
            showNet();
            return;
        }
        keepMessage = [kind, message];
        renderClass(c);
        if (keepMessage) {
            result(keepMessage[0], keepMessage[1]);
            keepMessage = null;
        }
    }

    // ---------------------------------------------------------------- camera
    function stopCamera() {
        if (!scanner) {
            return Promise.resolve();
        }
        var running = scanner;
        scanner = null;
        return running.stop().catch(function () {}).then(function () {
            try { running.clear(); } catch (e) { /* cleared */ }
        });
    }

    function wireCamera(onCode) {
        var toggle = document.getElementById('camera-toggle');
        var note = document.getElementById('camera-note');
        var picker = document.getElementById('camera-select');
        if (!window.isSecureContext || !navigator.mediaDevices || typeof Html5Qrcode === 'undefined') {
            toggle.disabled = true;
            note.textContent = 'The camera only works on a secure (https) page. Use a USB QR scanner in the box below.';
            return;
        }
        var config = {fps: 10, qrbox: function (w, h) { var s = Math.max(120, Math.floor(Math.min(w, h) * 0.7)); return {width: s, height: s}; }};
        var start = function (camera) {
            scanner = new Html5Qrcode('camera');
            toggle.disabled = true;
            note.textContent = 'Starting the camera…';
            scanner.start(camera, config, onCode, function () {}).then(function () {
                toggle.disabled = false;
                toggle.textContent = 'Stop camera';
                note.textContent = 'Point the camera at a learner\'s QR card. It reads the card by itself.';
                if (picker.options.length === 0) {
                    Html5Qrcode.getCameras().then(function (cams) {
                        if (cams.length > 1) {
                            cams.forEach(function (cam, i) { picker.add(new Option(cam.label || ('Camera ' + (i + 1)), cam.id)); });
                            picker.hidden = false;
                        }
                    }).catch(function () {});
                }
            }).catch(function (error) {
                toggle.disabled = false;
                try { scanner.clear(); } catch (e) { /* nothing */ }
                scanner = null;
                note.textContent = /NotAllowed|Permission|denied/i.test(String(error))
                    ? 'Camera access is blocked. Tap the lock or camera icon next to the address, allow the camera, then press Start camera again.'
                    : 'Could not start the camera (' + error + '). Use a USB scanner below.';
            });
        };
        toggle.addEventListener('click', function () {
            if (scanner) {
                stopCamera().then(function () { toggle.textContent = 'Start camera'; });
            } else {
                start(picker.value ? {deviceId: {exact: picker.value}} : {facingMode: 'environment'});
            }
        });
        picker.addEventListener('change', function () {
            stopCamera().then(function () { start({deviceId: {exact: picker.value}}); });
        });
    }

    // ---------------------------------------------------------------- pages
    function render() {
        showNet();
        if (!pack) {
            app.innerHTML = '<div class="card mx-auto" style="max-width: 34rem"><div class="card-body text-center py-5">'
                + '<div class="icon-bubble gold mx-auto mb-3"><i class="bi bi-cloud-download"></i></div>'
                + '<h1 class="h5">No class lists on this phone yet</h1>'
                + '<p class="text-secondary">While you have internet, sign in and open <strong>My classes</strong>. Your class lists are then saved on this phone, '
                + 'and you can take attendance here even without internet.</p>'
                + '<a class="btn btn-primary" href="teacher/index.php">Open My classes</a></div></div>';
            return;
        }
        var match = /^#class-(\d+)$/.exec(location.hash);
        var c = match ? Off.classOf(pack, match[1]) : null;
        stopCamera().then(function () {
            if (c) {
                renderClass(c);
            } else {
                renderList();
            }
            send(false);
        });
    }

    // Opened from a class's attendance page while offline (sw.js sends ?class=… or ?session=…): open that class
    (function () {
        var params = new URLSearchParams(location.search);
        if (!params.has('class') && !params.has('session')) return;
        var target = null;
        if (pack && params.get('class')) {
            target = Off.classOf(pack, params.get('class'));
        } else if (pack && params.get('session')) {
            target = (pack.classes || []).filter(function (c) { return c.openSession && String(c.openSession.id) === params.get('session'); })[0] || null;
        }
        history.replaceState(null, '', location.pathname + (target ? '#class-' + target.id : ''));
    })();

    window.addEventListener('hashchange', render);
    render();
})();
