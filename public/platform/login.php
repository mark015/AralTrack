<?php
// Sign-in for the platform owner (the company running the system for many schools).
// The first owner account is created here by the Super Admin of the first school, once.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (current_owner()) {
    redirect('platform/index.php');
}

$hasOwner = (int) db_value('SELECT COUNT(*) FROM platform_owners') > 0;
// Before any owner exists, only the first school's Super Admin may create one (the person who installed the system).
$staff = !$hasOwner && current_staff() ? require_staff() : null;
$canSetUp = $staff && $staff['role'] === 'superadmin' && ws() === 1;

$error = null;
$username = '';
$fullName = $staff['full_name'] ?? '';

if (is_post() && post('action') === 'setup') {
    $username = post('username');
    $fullName = post('full_name');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    if (!$canSetUp) {
        $error = 'Only the Super Admin of the first school can create the owner account, and only once.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $error = 'The username needs 3–50 letters, numbers, dots, dashes or underscores.';
    } elseif ($fullName === '' || mb_strlen($fullName) > 150) {
        $error = 'Enter your name.';
    } elseif (mb_strlen($password) < 10) {
        $error = 'Choose a password of at least 10 characters. This account can open every school.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        db_exec('INSERT INTO platform_owners (username, password_hash, full_name) VALUES (?, ?, ?)', [$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
        $owner = db_one('SELECT * FROM platform_owners WHERE id = ?', [(int) db()->lastInsertId()]);
        login_owner($owner);
        platform_log('owner', "Created the platform owner account {$username}");
        flash('success', 'Owner account created. Keep this password safe: it can open every school.');
        redirect('platform/index.php');
    }
} elseif (is_post()) {
    $username = post('username');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = client_ip();
    $key = mb_strtolower($username);
    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } elseif (RateLimiter::isLocked('owner', $key, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
    } else {
        $owner = db_one('SELECT * FROM platform_owners WHERE username = ?', [$username]);
        if ($owner && $owner['is_active'] && password_verify($password, $owner['password_hash'])) {
            RateLimiter::clear('owner', $key);
            login_owner($owner);
            platform_log('sign-in', 'Signed in to the platform console');
            redirect('platform/index.php');
        }
        password_verify($password, DUMMY_PASSWORD_HASH); // same time whether or not the account exists
        RateLimiter::recordFailure('owner', $key, $ip);
        $error = 'Incorrect username or password.';
    }
}

$title = 'Platform console';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-7 col-lg-5">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-buildings" aria-hidden="true"></i></div>
                <?php if ($hasOwner): ?>
                    <h1 class="h4 mb-1">Platform console</h1>
                    <p class="text-secondary small mb-4">For the team running <?= e(config('app_name')) ?>: schools, demo requests and backups.</p>
                    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                    <form method="post" action="<?= e(url('platform/login.php')) ?>">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input class="form-control" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label">Password</label>
                            <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary w-100">Sign in</button>
                    </form>
                <?php elseif ($canSetUp): ?>
                    <h1 class="h4 mb-1">Create the owner account</h1>
                    <p class="text-secondary small mb-4">
                        This account runs the whole system: it adds schools, pauses them and makes backups.
                        It is separate from your Super Admin account, and only one person should have it.
                    </p>
                    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                    <form method="post" action="<?= e(url('platform/login.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="setup">
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Your name</label>
                            <input class="form-control" id="full_name" name="full_name" value="<?= e($fullName) ?>" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input class="form-control" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required pattern="[A-Za-z0-9._\-]{3,50}">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password <span class="text-secondary small">(at least 10 characters)</span></label>
                            <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" minlength="10" required>
                        </div>
                        <div class="mb-4">
                            <label for="confirm" class="form-label">Password again</label>
                            <input class="form-control" type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="10" required>
                        </div>
                        <button class="btn btn-primary w-100">Create the owner account</button>
                    </form>
                <?php else: ?>
                    <h1 class="h4 mb-1">Platform console</h1>
                    <p class="text-secondary mb-0">
                        The owner account isn't set up yet. Sign in as the Super Admin of the first school
                        (<a href="<?= e(url('staff/login.php')) ?>">staff sign-in</a>), then open this page again to create it.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
