// Offline attendance: what a teacher's phone keeps when there is no internet, as JSON in the browser.
//   pack   the teacher's class lists (names + a SHA-256 fingerprint of each QR card, never the card code)
//   queue  scans, hand marks and "finish" recorded offline, sent to the server when the connection is back
// The class lists are deleted when the teacher signs out; records not yet sent are kept until they are sent.
(function () {
    'use strict';

    var PREFIX = 'sgp-offline:';
    var REFRESH_MS = 10 * 60 * 1000; // refresh the class lists at most every 10 minutes

    function load(key, fallback) {
        try {
            var value = localStorage.getItem(PREFIX + key);
            return value ? JSON.parse(value) : fallback;
        } catch (e) {
            return fallback;
        }
    }
    function store(key, value) {
        try {
            localStorage.setItem(PREFIX + key, JSON.stringify(value));
            return true;
        } catch (e) {
            return false;
        }
    }
    function drop(key) {
        try { localStorage.removeItem(PREFIX + key); } catch (e) { /* ignore */ }
    }
    function meta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : null;
    }
    function now() {
        return Math.floor(Date.now() / 1000);
    }

    var Off = {
        // ---- class lists
        pack: function (staffId) { return load('pack:' + staffId, null); },
        savePack: function (pack) {
            store('pack:' + pack.staffId, pack);
            store('who', {staffId: pack.staffId, name: pack.staffName});
        },
        dropPack: function (staffId) { drop('pack:' + staffId); },
        who: function () { return load('who', null); },
        classOf: function (pack, classId) {
            return pack ? (pack.classes || []).filter(function (c) { return String(c.id) === String(classId); })[0] || null : null;
        },

        // ---- records waiting to be sent
        queue: function (staffId) { return load('queue:' + staffId, {sessions: {}}); },
        saveQueue: function (staffId, queue) { return store('queue:' + staffId, queue); },
        pending: function (staffId) {
            var sessions = this.queue(staffId).sessions;
            var n = 0;
            Object.keys(sessions).forEach(function (key) {
                n += sessions[key].events.length + (sessions[key].finishedAt && !sessions[key].finishSent ? 1 : 0);
            });
            return n;
        },
        allPending: function () {
            var total = 0;
            try {
                for (var i = 0; i < localStorage.length; i++) {
                    var key = localStorage.key(i);
                    if (key && key.indexOf(PREFIX + 'queue:') === 0) {
                        total += this.pending(key.substring((PREFIX + 'queue:').length));
                    }
                }
            } catch (e) { /* ignore */ }
            return total;
        },
        /** Today's date on this phone as YYYY-MM-DD (local time, like the server's date). */
        today: function () {
            var d = new Date();
            return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        },
        uuid: function () {
            if (window.crypto && crypto.randomUUID) {
                return crypto.randomUUID();
            }
            return 'x' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
        },

        /** A session in the queue (created when missing). info: {key, classId, serverSessionId?, startsAt} */
        session: function (staffId, info) {
            var queue = this.queue(staffId);
            if (!queue.sessions[info.key]) {
                queue.sessions[info.key] = {
                    key: info.key,
                    clientUuid: info.clientUuid || this.uuid(),
                    classId: info.classId,
                    serverSessionId: info.serverSessionId || null,
                    startsAt: info.startsAt,
                    events: [],
                    finishedAt: null,
                    statuses: {} // studentId => {status, at}: what the phone shows
                };
                this.saveQueue(staffId, queue);
            }
            return queue.sessions[info.key];
        },
        addEvent: function (staffId, key, event, status) {
            var queue = this.queue(staffId);
            var session = queue.sessions[key];
            if (!session) {
                return false;
            }
            session.events.push(event);
            if (status) {
                session.statuses[event.studentId] = {status: status, at: event.at};
            }
            return this.saveQueue(staffId, queue);
        },
        finish: function (staffId, key) {
            var queue = this.queue(staffId);
            if (queue.sessions[key]) {
                queue.sessions[key].finishedAt = now();
                this.saveQueue(staffId, queue);
            }
        },
        dropSession: function (staffId, key) {
            var queue = this.queue(staffId);
            delete queue.sessions[key];
            this.saveQueue(staffId, queue);
        },

        // ---- QR cards
        tokenFromScan: function (code) {
            code = String(code || '').replace(/\s+/g, '').toLowerCase();
            if (code.indexOf('sgp') === 0) {
                code = code.substring(3);
            }
            return /^[0-9a-f]{32}$/.test(code) ? code : null;
        },
        sha256: function (text) {
            return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buffer) {
                return Array.prototype.map.call(new Uint8Array(buffer), function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
            });
        },
        /** The learner whose card this is, from a list of {id, name, hash}; resolves null when unknown. */
        learnerForCode: function (learners, code) {
            var token = this.tokenFromScan(code);
            if (!token || !window.crypto || !crypto.subtle) {
                return Promise.resolve(null);
            }
            return this.sha256(token).then(function (hash) {
                return (learners || []).filter(function (l) { return l.hash === hash; })[0] || null;
            });
        },

        // ---- talking to the server
        refreshPack: function (api, force) {
            var who = meta('sgp-staff-id');
            var current = who ? this.pack(who) : null;
            var today = this.today();
            if (!force && current && current.date === today && Date.now() - current.savedAt * 1000 < REFRESH_MS) {
                return Promise.resolve(current);
            }
            var self = this;
            return fetch(api + '?action=pack', {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.ok && data.pack) {
                        self.savePack(data.pack);
                        document.dispatchEvent(new CustomEvent('sgp-pack', {detail: data.pack}));
                        return data.pack;
                    }
                    return current;
                })
                .catch(function () { return current; });
        },

        /**
         * Sends what this staff member recorded offline. Resolves {sent, results, signin, error}.
         * Only one sync runs at a time.
         */
        sync: function (staffId, csrf, api) {
            var self = this;
            if (self._syncing) {
                return self._syncing;
            }
            var queue = self.queue(staffId);
            var keys = Object.keys(queue.sessions).filter(function (k) {
                var s = queue.sessions[k];
                return s.events.length || (s.finishedAt && !s.finishSent);
            });
            if (!keys.length || !navigator.onLine || !csrf || !api) {
                return Promise.resolve({sent: 0, results: []});
            }
            // What is sent now: anything recorded while sending stays for the next round.
            var snapshot = keys.map(function (k) {
                var s = queue.sessions[k];
                return {key: s.key, clientUuid: s.clientUuid, classId: s.classId, serverSessionId: s.serverSessionId,
                    startsAt: s.startsAt, events: s.events.slice(), finishedAt: s.finishedAt};
            });
            var body = new URLSearchParams();
            body.set('_csrf', csrf);
            body.set('action', 'sync');
            body.set('payload', JSON.stringify({sessions: snapshot}));

            self._syncing = fetch(api, {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) {
                    return r.json().catch(function () {
                        return {ok: false, signin: r.status === 400 || r.status === 401, message: 'The server did not accept the data. Sign in again, then it is sent.'};
                    }).then(function (data) { data.status = r.status; return data; });
                })
                .then(function (data) {
                    if (!data.ok) {
                        return {sent: 0, results: [], signin: Boolean(data.signin), error: data.message};
                    }
                    var fresh = self.queue(staffId);
                    var sent = 0;
                    data.results.forEach(function (result) {
                        var s = fresh.sessions[result.key];
                        var sentPart = snapshot.filter(function (x) { return x.key === result.key; })[0];
                        if (!s || !sentPart) {
                            return;
                        }
                        if (result.ok) {
                            s.events.splice(0, sentPart.events.length);
                            s.serverSessionId = result.sessionId;
                            sent += sentPart.events.length + (sentPart.finishedAt ? 1 : 0);
                            if (sentPart.finishedAt) {
                                s.finishSent = true;
                            }
                            // Done with it: finished and sent, or a past day's class with nothing left to send.
                            var day = new Date(s.startsAt * 1000);
                            var pastDay = day.getFullYear() + '-' + ('0' + (day.getMonth() + 1)).slice(-2) + '-' + ('0' + day.getDate()).slice(-2) !== self.today();
                            if (!s.events.length && (s.finishSent || pastDay)) {
                                delete fresh.sessions[result.key];
                            }
                        } else if (result.rejected) {
                            delete fresh.sessions[result.key];
                        }
                    });
                    self.saveQueue(staffId, fresh);
                    var outcome = {sent: sent, results: data.results};
                    document.dispatchEvent(new CustomEvent('sgp-synced', {detail: outcome}));
                    return outcome;
                })
                .catch(function () {
                    return {sent: 0, results: [], error: 'No connection yet.'};
                })
                .then(function (outcome) {
                    self._syncing = null;
                    return outcome;
                });
            return self._syncing;
        }
    };
    window.SGPOffline = Off;

    // ---------------------------------------------------------------- on every staff page
    var staffId = meta('sgp-staff-id');
    var csrf = meta('csrf-token');
    var api = meta('sgp-offline-api');

    function pill(text, tone, action) {
        var el = document.getElementById('offline-pill');
        if (!text) {
            if (el) {
                el.remove();
            }
            return;
        }
        if (!el) {
            el = document.createElement('div');
            el.id = 'offline-pill';
            el.className = 'offline-pill d-print-none';
            el.setAttribute('role', 'status');
            document.body.appendChild(el);
        }
        el.className = 'offline-pill d-print-none ' + (tone || '');
        el.replaceChildren(document.createTextNode(text));
        if (action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = action.label;
            button.addEventListener('click', action.run);
            el.appendChild(button);
        }
    }

    function showPending() {
        var n = staffId ? Off.pending(staffId) : 0;
        if (!n) {
            pill(null);
        } else if (!navigator.onLine) {
            pill('Offline · ' + n + ' attendance record' + (n === 1 ? '' : 's') + ' saved on this phone', 'offline');
        } else {
            pill(n + ' attendance record' + (n === 1 ? '' : 's') + ' waiting to be sent', 'waiting', {label: 'Send now', run: trySync});
        }
    }

    function trySync() {
        if (!staffId || !Off.pending(staffId) || !navigator.onLine) {
            showPending();
            return;
        }
        pill('Sending attendance saved on this phone…', 'waiting');
        Off.sync(staffId, csrf, api).then(function (outcome) {
            if (outcome.sent) {
                pill('Attendance saved on this phone was sent (' + outcome.sent + ').', 'done');
                setTimeout(showPending, 4000);
            } else {
                showPending();
            }
        });
    }

    if (staffId && api) {
        if (location.pathname.indexOf('/teacher/') !== -1 && navigator.onLine) {
            Off.refreshPack(api, false);
        }
        trySync();
        window.addEventListener('online', trySync);
        window.addEventListener('offline', showPending);
        document.addEventListener('sgp-queued', showPending);
        setInterval(trySync, 30000);

        // Signing out deletes the class lists (learner names) from this phone, but keeps unsent records.
        document.querySelectorAll('form[action$="staff/logout.php"]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var n = Off.pending(staffId);
                if (n && !confirm(n + ' attendance record(s) saved on this phone have not been sent yet. They stay on this phone and are sent the next time you sign in here. Sign out?')) {
                    event.preventDefault();
                    return;
                }
                Off.dropPack(staffId);
            });
        });
    } else if (/staff\/login\.php$/.test(location.pathname)) {
        var waiting = Off.allPending();
        var form = document.querySelector('form[action$="staff/login.php"]');
        if (waiting && form) {
            var who = Off.who();
            var note = document.createElement('div');
            note.className = 'alert alert-warning';
            note.textContent = 'This device has ' + waiting + ' attendance record(s) not yet sent' + (who ? ' by ' + who.name : '') + '. Sign in' + (who ? ' as ' + who.name : '') + ' to send them.';
            form.parentNode.insertBefore(note, form);
        }
    }
})();
