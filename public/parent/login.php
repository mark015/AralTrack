<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (current_parent()) {
    redirect('parent/index.php');
}

$error = null;
$typed = '';

if (is_post()) {
    $typed = post('login');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $login = normalize_parent_login($typed);
    $ip = client_ip();

    if ($login === null || $password === '') {
        $error = 'Enter your mobile number or email, and your password.';
    } elseif (RateLimiter::isLocked('parent', $login, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
        Access::logLogin('parent', null, $login, false, 'locked');
    } else {
        // The same mobile number or email can have a record at several schools (or teachers). Every record with
        // this password is opened together, and the parent switches between them (src/Accounts.php).
        $records = db_all('SELECT * FROM parents WHERE login = ? ORDER BY last_login_at IS NULL, last_login_at DESC, id', [$login]);
        $matched = array_values(array_filter($records, static fn (array $r): bool => password_verify($password, $r['password_hash'])));
        if (!$records) {
            password_verify($password, DUMMY_PASSWORD_HASH); // same time whether or not the account exists
        }
        $usable = [];
        $refusal = null;
        foreach ($matched as $record) {
            // Each school's own rules: paused, parent sign-in closed or maintenance, account turned off
            Workspace::switchTo((int) $record['workspace_id']);
            $reason = match (true) {
                Workspace::isSuspended() => [Workspace::suspendedMessage(), 'school paused'],
                !Access::loginOpen('parent') => [Access::closedMessage('parent'), Access::maintenance() ? 'maintenance' : 'sign-in off'],
                !$record['is_active'] => ['Your account was turned off by the school.' . ($record['blocked_reason'] ? ' Reason: ' . $record['blocked_reason'] . '.' : '') . ' Please contact the registrar.', 'turned off'],
                default => null,
            };
            if ($reason === null) {
                $usable[] = $record;
            } else {
                $refusal ??= $reason[0];
                Access::logLogin('parent', (int) $record['id'], $login, false, $reason[1]);
            }
        }
        if ($usable) {
            RateLimiter::clear('parent', $login);
            foreach ($usable as $record) {
                if (password_needs_rehash($record['password_hash'], PASSWORD_DEFAULT)) {
                    db_exec('UPDATE parents SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $record['id']]);
                }
            }
            $parent = $usable[0];
            login_parent($parent);
            Accounts::remember('parent', $usable);
            foreach ($usable as $record) {
                Access::logLogin('parent', (int) $record['id'], $login, true);
            }
            redirect($parent['must_change_password'] ? 'parent/password.php' : 'parent/index.php');
        } elseif ($refusal !== null) {
            $error = $refusal; // only after the right password, so the form can't show who has an account
        } else {
            RateLimiter::recordFailure('parent', $login, $ip);
            Access::logLogin('parent', $records ? (int) $records[0]['id'] : null, $login, false, 'wrong password');
            $error = 'Incorrect mobile number/email or password.';
        }
    }
}
$closedNotice = Access::loginOpen('parent') ? null : Access::closedMessage('parent');

$title = 'Parent sign-in';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon gold"><i class="bi bi-people-fill" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Parent sign-in</h1>
                <p class="text-secondary small mb-4">See your child's class attendance and grades.</p>
                <?php if ($closedNotice): ?>
                    <div class="alert alert-warning" role="status"><i class="bi bi-slash-circle" aria-hidden="true"></i><div><?= e($closedNotice) ?></div></div>
                <?php endif; ?>
                <?php if ($error && $error !== $closedNotice): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('parent/login.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="login" class="form-label">Mobile number or email</label>
                        <input class="form-control" id="login" name="login" value="<?= e($typed) ?>" autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary w-100">Sign in</button>
                </form>
                <p class="small text-secondary mt-3 mb-0">Forgot your password? Ask the school's registrar to reset it.</p>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">
            New here? <a href="<?= e(url('parent/register.php')) ?>">Create a parent account</a> with your child's LRN and link code.
        </p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
