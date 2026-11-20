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
            .then(function (response) { return response.json(); })
            .catch(function () {
                return {kind: 'error', accepted: false, message: 'Could not save. Check the connection, or reload the page (you may have been signed out).'};
            });
    }

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
            toggle.addEventListener('click', function () {
                if (scanner) {
                    scanner.stop().then(function () {
                        scanner.clear();
                        scanner = null;
                        toggle.textContent = 'Start camera';
                    });
                    return;
                }
                scanner = new Html5Qrcode('camera');
                toggle.disabled = true;
                scanner.start({facingMode: 'environment'}, {fps: 10, qrbox: {width: 220, height: 220}}, scan, function () {})
                    .then(function () {
                        toggle.disabled = false;
                        toggle.textContent = 'Stop camera';
                    })
                    .catch(function (error) {
                        toggle.disabled = false;
                        scanner = null;
                        note.textContent = 'Could not start the camera (' + error + '). Allow camera access for this site, or use a USB scanner.';
                    });
            });
        }
    }

    // Marking by hand: save without reloading, so the camera keeps running.
    document.querySelectorAll('form.mark-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var data = new FormData(form);
            send({action: 'mark', student_id: data.get('student_id'), status: data.get('status') || '', note: data.get('note') || ''})
                .then(handle);
        });
    });
})();
