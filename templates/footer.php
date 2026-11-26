</main>
<footer class="app-footer d-print-none">
    <div class="container py-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-2">
            <?= brand_mark(30) ?>
            <div class="lh-sm">
                <?php $footSchool = empty($navOwner) && Workspace::known(); // the platform console shows the system's name ?>
                <div class="fw-semibold text-body"><?= e($footSchool ? setting('school_name') : config('app_name')) ?></div>
                <div><?= $footSchool ? e(config('app_name')) . ' · Senior High School' : 'Grades, QR attendance and parent updates for schools' ?></div>
            </div>
        </div>
        <nav class="d-flex flex-wrap gap-3" aria-label="Footer">
            <a href="<?= e(url('index.php')) ?>"><i class="bi bi-house me-1"></i>Home</a>
            <a href="<?= e(url('learner/login.php')) ?>"><i class="bi bi-mortarboard me-1"></i>Learners</a>
            <a href="<?= e(url('parent/login.php')) ?>"><i class="bi bi-people me-1"></i>Parents</a>
            <a href="<?= e(url('staff/login.php')) ?>"><i class="bi bi-person-badge me-1"></i>Teachers &amp; staff</a>
            <a href="<?= e(url('privacy.php')) ?>"><i class="bi bi-shield-lock me-1"></i>Privacy notice</a>
            <a href="#" class="d-none" data-install-app><i class="bi bi-phone me-1"></i>Install app</a>
        </nav>
    </div>
</footer>
<script src="<?= e(asset('assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('assets/theme.js')) ?>"></script>
<?php if (!empty($offlineKey)): ?>
    <script src="<?= e(asset('assets/offline-pages.js')) ?>"></script>
<?php endif; ?>
<?php if (current_staff() || str_ends_with(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/staff/login.php')): ?>
    <script src="<?= e(asset('assets/offline-attendance.js')) ?>"></script>
<?php endif; ?>
<?php if (is_file(APP_ROOT . '/public/sw.js')): ?>
    <script>
        // Installable app: register the service worker, and show "Install app" when the browser offers it.
        if ('serviceWorker' in navigator) { navigator.serviceWorker.register('<?= e(url('sw.js')) ?>').catch(function () {}); }
        (function () {
            var prompt = null;
            var link = document.querySelector('[data-install-app]');
            window.addEventListener('beforeinstallprompt', function (event) {
                event.preventDefault();
                prompt = event;
                if (link) link.classList.remove('d-none');
            });
            if (link) link.addEventListener('click', function (event) {
                event.preventDefault();
                if (!prompt) return;
                prompt.prompt();
                prompt.userChoice.finally(function () { prompt = null; link.classList.add('d-none'); });
            });
            window.addEventListener('appinstalled', function () { if (link) link.classList.add('d-none'); });
        })();
    </script>
<?php endif; ?>
</body>
</html>
