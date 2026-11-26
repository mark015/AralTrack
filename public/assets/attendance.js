// Attendance page: camera and USB scanning, and saving marks without reloading the page.
(function () {
    'use strict';

    var page = document.getElementById('attendance-page');
    if (!page) {
        return;
    }
    var api = page.getAttribute('data-api');
    var sessionId = page.getAttribute('data-session');
    var csrf = page.getAttribute('data-csrf');
    var resultBox = document.getElementById('scan-result');
    var input = document.getElementById('scan-input');

    // Same classes and icons as ATTENDANCE_STYLE in src/helpers.php
    var STATUS_STYLE = {
        present: ['text-bg-success', 'check-circle-fill'],
        late: ['text-bg-warning', 'clock-fill'],
        absent: ['text-bg-danger', 'x-circle-fill'],
        excused: ['text-bg-info', 'info-circle-fill']
    };
    var KIND_STYLE = {
        success: ['alert-success', 'check-circle-fill'],
        warning: ['alert-warning', 'clock-fill'],
        info: ['alert-info', 'info-circle-fill'],
        error: ['alert-danger', 'x-octagon-fill']
    };

    function icon(name) {
        var el = document.createElement('i');
        el.className = 'bi bi-' + name;
        el.setAttribute('aria-hidden', 'true');
        return el;
    }
    var lastCode = '';
    var lastTime = 0;
    var audio = null;

    function send(fields) {
        var body = new URLSearchParams(fields);
        body.set('_csrf', csrf);
        body.set('session_id', sessionId);
        body.set('ajax', '1');
        return fetch(api, {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) {
                return response.json().catch(function () {
                    return {kind: 'error', accepted: false, message: 'Could not save. Reload the page (you may have been signed out).'};
                });
            })
            .catch(function () {
                return {offline: true}; // no connection: saved on this phone instead (see below)
            });
    }

    // ---------------------------------------------------------------- without internet
    // When the server can't be reached, scans and marks are saved on this phone (assets/offline-attendance.js)
    // and sent when the connection is back. Cards are recognised from fingerprints of this class's cards.
    var Off = window.SGPOffline;
    var staffId = page.getAttribute('data-staff');
    var offlineKey = 'srv-' + sessionId;
    var roster = [];
    try { roster = JSON.parse(page.getAttribute('data-roster') || '[]'); } catch (e) { roster = []; }
    var lateAfter = parseInt(page.getAttribute('data-late-after'), 10) || 0;
    var banner = document.getElementById('offline-banner');

    function offlineSession() {
        Off = Off || window.SGPOffline;
        return Off ? Off.session(staffId, {
            key: offlineKey,
            classId: page.getAttribute('data-class'),
            serverSessionId: parseInt(sessionId, 10),
            startsAt: parseInt(page.getAttribute('data-starts'), 10)
        }) : null;
    }

    function clock(seconds) {
        return new Date(seconds * 1000).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
    }

    function showOfflineBanner() {
        if (!banner || !Off) {
            return;
        }
        var n = Off.pending(staffId);
        banner.classList.toggle('d-none', !n);
        banner.textContent = n
            ? 'No connection to the server. ' + n + ' record(s) are saved on this phone and will be sent automatically when the internet is back. Keep this page open, or open it again later.'
            : '';
    }

    function offlineScan(code) {
        if (!offlineSession()) {
            showResult({kind: 'error', message: 'No connection, and this browser cannot save attendance offline.'});
            beep(false);
            return;
        }
        Off.learnerForCode(roster, code).then(function (learner) {
            if (!learner) {
                showResult({kind: 'error', message: 'No connection, and this card is not in this class (or it is not a learner ID).'});
                beep(false);
                return;
            }
            var row = document.querySelector('[data-student-row="' + learner.id + '"]');
            var current = row ? row.getAttribute('data-status') : '';
            if (current === 'present' || current === 'late') {
                showResult({kind: 'info', message: learner.name + ' is already marked ' + current + '.'});
                beep(false);
                return;
            }
            var at = Math.floor(Date.now() / 1000);
            var status = lateAfter && at > lateAfter ? 'late' : 'present';
            Off.addEvent(staffId, offlineKey, {type: 'scan', studentId: learner.id, at: at}, status);
            updateRow({id: learner.id, name: learner.name, status: status, label: status === 'late' ? 'Late' : 'Present', time: clock(at) + ' · saved offline', note: ''});
            showResult({kind: status === 'late' ? 'warning' : 'success',
                message: learner.name + ' — ' + (status === 'late' ? 'Late' : 'Present') + ' at ' + clock(at) + '. Saved on this phone; it is sent when the internet is back.'});
            beep(true);
            showOfflineBanner();
            document.dispatchEvent(new CustomEvent('sgp-queued'));
        });
    }

    function offlineMark(studentId, status, note) {
        if (!offlineSession()) {
            showResult({kind: 'error', message: 'No connection. Try again when the internet is back.'});
            return;
        }
        var at = Math.floor(Date.now() / 1000);
        Off.addEvent(staffId, offlineKey, {type: 'mark', studentId: parseInt(studentId, 10), status: status, note: note, at: at}, status);
        var learner = roster.filter(function (l) { return String(l.id) === String(studentId); })[0];
        var labels = {present: 'Present', late: 'Late', absent: 'Absent', excused: 'Excused'};
        updateRow({id: studentId, name: learner ? learner.name : '', status: status, label: labels[status] || status, time: 'by teacher · saved offline', note: note});
        showResult({kind: 'info', message: (learner ? learner.name : 'Learner') + ' marked ' + (labels[status] || status).toLowerCase() + '. Saved on this phone; it is sent when the internet is back.'});
        showOfflineBanner();
        document.dispatchEvent(new CustomEvent('sgp-queued'));
    }

    // Finishing without internet: remembered on this phone and done when it is sent.
    var finishForm = document.getElementById('finish-form');
    if (finishForm) {
        finishForm.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return; // the teacher pressed Cancel
            }
            event.preventDefault();
            fetch(finishForm.action, {method: 'POST', body: new FormData(finishForm), credentials: 'same-origin', redirect: 'follow'})
                .then(function () { location.reload(); })
                .catch(function () {
                    if (!offlineSession()) {
                        return;
                    }
                    Off.finish(staffId, offlineKey);
                    showResult({kind: 'warning', message: 'Finished on this phone. Learners not marked will be marked absent when it is sent, once the internet is back.'});
                    finishForm.querySelector('button').disabled = true;
                    showOfflineBanner();
                    document.dispatchEvent(new CustomEvent('sgp-queued'));
                });
        });
    }

    // Once the saved records are sent, show the page as the server has it.
    document.addEventListener('sgp-synced', function (event) {
        var mine = (event.detail.results || []).some(function (r) { return r.key === offlineKey && r.ok; });
        if (mine) {
            location.reload();
        }
    });
    window.addEventListener('load', showOfflineBanner);

    // A short beep: high for a saved scan, low for a problem. Optional; ignored if the browser blocks sound.
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

    function showResult(data) {
        if (!resultBox) {
            return;
        }
        var style = KIND_STYLE[data.kind] || ['alert-secondary', 'qr-code-scan'];
        resultBox.className = 'scan-result alert mb-3 ' + style[0];
        resultBox.replaceChildren(icon(style[1]), document.createTextNode(data.message));
        if (data.student && data.student.photo) {
            var photo = document.createElement('img');
            photo.src = data.student.photo;
            photo.alt = 'Photo of ' + data.student.name;
            photo.className = 'scan-photo';
            resultBox.prepend(photo);
        }
    }

    function updateRow(student) {
        var row = document.querySelector('[data-student-row="' + student.id + '"]');
        if (!row) {
            return;
        }
        row.setAttribute('data-status', student.status || '');
        var cell = row.querySelector('.js-status');
        var style = STATUS_STYLE[student.status] || ['text-bg-light border', 'circle'];
        var badge = document.createElement('span');
        badge.className = 'badge ' + style[0];
        badge.append(icon(style[1]), document.createTextNode(student.label));
        cell.replaceChildren(badge);
        row.querySelector('.js-time').textContent = student.time || (student.status ? 'by teacher' : '');

        var select = row.querySelector('select[name="status"]');
        if (select && student.status) {
            var placeholder = select.querySelector('option[value=""]');
            if (placeholder) {
                placeholder.remove();
            }
            select.value = student.status;
        }
        var note = row.querySelector('input[name="note"]');
        if (note) {
            note.value = student.note || '';
        }
        row.classList.remove('row-flash');
        void row.offsetWidth; // restart the highlight animation
        row.classList.add('row-flash');
    }

    function updateCounts(counts) {
        if (!counts) {
            return;
        }
        Object.keys(counts).forEach(function (key) {
            var el = document.querySelector('[data-count="' + key + '"]');
            if (el) {
                el.textContent = counts[key];
            }
        });
    }

    function handle(data) {
        showResult(data);
        if (data.student) {
            updateRow(data.student);
        }
        updateCounts(data.counts);
    }

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
        send({action: 'scan', code: code}).then(function (data) {
            if (data.offline) {
                offlineScan(code);
                return;
            }
            handle(data);
            beep(Boolean(data.accepted));
        });
    }

    // USB scanners type the code and press Enter, like a keyboard. Focus the box on computers
    // only: on phones it would open the on-screen keyboard over the camera.
    if (input) {
        if (!window.matchMedia('(pointer: coarse)').matches) {
            input.focus();
        }
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                scan(input.value);
                input.value = '';
            }
        });
    }

    // Camera scanning. Browsers only allow the camera on HTTPS pages (or on localhost).
    var toggle = document.getElementById('camera-toggle');
    var note = document.getElementById('camera-note');
    if (toggle) {
        if (!window.isSecureContext || !navigator.mediaDevices || typeof Html5Qrcode === 'undefined') {
            toggle.disabled = true;
            var httpsUrl = 'https://' + location.host + location.pathname + location.search;
            var link = document.createElement('a');
            link.href = httpsUrl;
            link.textContent = 'open this page with https://';
            note.replaceChildren(
                document.createTextNode('The camera only works on a secure page: '),
                link,
                document.createTextNode(' (accept the certificate warning once), or use a USB QR scanner in the box below.')
            );
        } else {
            var scanner = null;
            var picker = document.getElementById('camera-select');

            // The scan square: 70% of the smaller side of the picture, so it fits small phones too.
            var config = {
                fps: 10,
                qrbox: function (width, height) {
                    var side = Math.max(120, Math.floor(Math.min(width, height) * 0.7));
                    return {width: side, height: side};
                }
            };

            // Turns the browser's error into advice a teacher can act on.
            var explain = function (error) {
                var text = String(error && (error.name || error.message) ? (error.name + ' ' + error.message) : error);
                if (/NotAllowed|Permission|denied/i.test(text)) {
                    return 'Camera access is blocked for this site. Tap the lock or camera icon next to the address, allow the camera, then press Start camera again.';
                }
                if (/NotFound|Requested device not found|no camera/i.test(text)) {
                    return 'No camera was found on this device. Use a phone or a laptop with a camera, or a USB QR scanner below.';
                }
                if (/NotReadable|Could not start video source|in use/i.test(text)) {
                    return 'The camera is being used by another app or browser tab. Close it (e.g. Zoom, Messenger, another tab) and press Start camera again.';
                }
                if (/Overconstrained/i.test(text)) {
                    return 'This camera could not be opened. Choose another camera in the list, or use a USB scanner.';
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
                        note.textContent = 'Point the camera at a learner\'s QR card. It reads the card by itself.';
                        // Offer the other cameras (front/back on phones, extra webcams on laptops).
                        if (picker && picker.options.length === 0) {
                            Html5Qrcode.getCameras().then(function (cameras) {
                                if (cameras.length < 2) {
                                    return;
                                }
                                cameras.forEach(function (cam, i) {
                                    picker.add(new Option(cam.label || ('Camera ' + (i + 1)), cam.id));
                                });
                                picker.hidden = false;
                            }).catch(function () { /* no list: keep the default camera */ });
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
                    stop().then(function () {
                        start({deviceId: {exact: picker.value}});
                    });
                });
            }
        }
    }

    // Marking by hand: save without reloading, so the camera keeps running.
    document.querySelectorAll('form.mark-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var data = new FormData(form);
            send({action: 'mark', student_id: data.get('student_id'), status: data.get('status') || '', note: data.get('note') || ''})
                .then(function (result) {
                    if (result.offline) {
                        offlineMark(data.get('student_id'), data.get('status') || '', data.get('note') || '');
                    } else {
                        handle(result);
                    }
                });
        });
    });
})();
