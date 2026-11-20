// Light / dark mode switch. The choice is remembered on this device (the header applies it before the page paints).
(function () {
    'use strict';
    var root = document.documentElement;

    function setIcons() {
        var dark = root.getAttribute('data-bs-theme') === 'dark';
        document.querySelectorAll('[data-theme-toggle] .bi').forEach(function (icon) {
            icon.className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars');
        });
    }

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-bs-theme', next);
            try { localStorage.setItem('sgp-theme', next); } catch (e) { /* private mode */ }
            setIcons();
        });
    });
    setIcons();

    // Always print in light colours (report cards, reports), then switch back.
    var beforePrint = null;
    window.addEventListener('beforeprint', function () {
        beforePrint = root.getAttribute('data-bs-theme');
        root.setAttribute('data-bs-theme', 'light');
    });
    window.addEventListener('afterprint', function () {
        if (beforePrint) root.setAttribute('data-bs-theme', beforePrint);
        beforePrint = null;
    });
})();
