// Class record: recompute totals, percentage and weighted scores, the initial grade and the transmuted
// quarterly grade for a learner's row as the teacher types. The server recomputes everything on save.
(function () {
    'use strict';
    var table = document.querySelector('.table-record');
    if (!table) return;
    var weights = JSON.parse(table.getAttribute('data-weights') || '{}');
    var components = ['ww', 'pt', 'qa'];

    function transmute(initial) {
        initial = Math.max(0, Math.min(100, Math.round(initial * 100) / 100));
        if (initial >= 60) return Math.min(100, 75 + Math.floor((initial - 60) / 1.6 + 1e-9));
        return 60 + Math.floor(initial / 4 + 1e-9);
    }

    function fmt(n) {
        return (Math.round(n * 100) / 100).toFixed(2);
    }

    function trim(n) {
        return String(Math.round(n * 100) / 100);
    }

    function setOut(row, key, text) {
        var cell = row.querySelector('[data-out="' + key + '"]');
        if (cell) cell.textContent = text;
    }

    // Items nobody has a score in yet (e.g. an exam not given yet) don't count, like on the server.
    function givenItems() {
        var given = {};
        table.querySelectorAll('.score-input').forEach(function (input) {
            if (input.value.trim() !== '') given[input.getAttribute('data-a')] = true;
        });
        return given;
    }

    function recompute(row, given) {
        var raw = { ww: 0, pt: 0, qa: 0 };
        var max = { ww: 0, pt: 0, qa: 0 };
        var any = false;
        var bad = false;
        row.querySelectorAll('.score-input').forEach(function (input) {
            var c = input.getAttribute('data-c');
            var hps = parseFloat(input.getAttribute('data-max'));
            var value = input.value.trim();
            if (!given[input.getAttribute('data-a')]) return;
            max[c] += hps;
            input.classList.remove('is-invalid');
            if (value === '') return;
            var n = Number(value);
            if (!isFinite(n) || n < 0 || n > hps) {
                input.classList.add('is-invalid');
                bad = true;
                return;
            }
            raw[c] += n;
            any = true;
        });

        var weighted = 0;
        var used = 0;
        components.forEach(function (c) {
            if (max[c] <= 0) {
                setOut(row, c + '-total', '–');
                setOut(row, c + '-ps', '–');
                setOut(row, c + '-ws', '–');
                return;
            }
            var ps = raw[c] / max[c] * 100;
            setOut(row, c + '-total', any ? trim(raw[c]) : '–');
            setOut(row, c + '-ps', any ? fmt(ps) : '–');
            setOut(row, c + '-ws', any ? fmt(ps * weights[c] / 100) : '–');
            weighted += Math.round(ps * 100) / 100 * weights[c] / 100;
            used += weights[c];
        });

        if (!any || used <= 0 || bad) {
            setOut(row, 'initial', bad ? '?' : '–');
            setOut(row, 'grade', bad ? '?' : '–');
            return;
        }
        var initial = Math.round(weighted * 100 / used * 100) / 100;
        var grade = transmute(initial);
        setOut(row, 'initial', fmt(initial));
        setOut(row, 'grade', String(grade));
        var gradeCell = row.querySelector('[data-out="grade"]');
        if (gradeCell) gradeCell.classList.toggle('text-danger', grade < 75);
    }

    table.addEventListener('input', function (event) {
        if (!event.target.classList.contains('score-input')) return;
        // A first score in a new item changes every row, so recompute them all (cheap for a class).
        var given = givenItems();
        table.querySelectorAll('[data-row]').forEach(function (row) { recompute(row, given); });
    });

    // Enter moves down the column, like a spreadsheet; Shift+Enter moves up.
    table.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || !event.target.classList.contains('score-input')) return;
        event.preventDefault();
        var cell = event.target.closest('td');
        var index = Array.prototype.indexOf.call(cell.parentNode.children, cell);
        var row = cell.parentNode[event.shiftKey ? 'previousElementSibling' : 'nextElementSibling'];
        var next = row && row.children[index] && row.children[index].querySelector('.score-input');
        if (next) {
            next.focus();
            next.select();
        }
    });

    var dirty = false;
    var form = document.getElementById('record-form');
    table.addEventListener('change', function () { dirty = true; });
    if (form) form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (event) {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
