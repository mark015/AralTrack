<?php
// Sign-in for teachers and the registrar/admin.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if ($staff = current_staff()) {
    redirect(home_for($staff));
}

$error = null;
$username = '';

if (is_post()) {
    $username = post('username');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = client_ip();
    $key = mb_strtolower($username);

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } elseif (RateLimiter::isLocked('staff', $key, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
        Access::logLogin('staff', null, $username, false, 'locked');
    } else {
        // Sign in with the username, or with the email address (teachers who signed up use their email)
        $user = db_one('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, mb_strtolower($username)]);
        $passwordOk = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH);
        if ($user && $passwordOk) {
            Workspace::switchTo((int) $user['workspace_id']); // the account's school: its sign-in switches
        }
        if ($user && $passwordOk && Workspace::isSuspended()) {
            $error = Workspace::suspendedMessage();
            Access::logLogin('staff', (int) $user['id'], $username, false, 'school paused');
        } elseif ($user && $passwordOk && $user['email'] !== null && $user['email_verified_at'] === null && $user['is_active']) {
            // Signed up but the email address isn't confirmed yet: send a code first
            RateLimiter::clear('staff', $key);
            $_SESSION['verify_user'] = (int) $user['id'];
            try {
                $code = Signup::sendVerifyCode($user);
                flash('info', 'Please confirm your email address first. We sent a new code to ' . $user['email'] . '.'
                    . (Mailer::isTestMode() ? " (Test mode: emails aren't sent from this computer. The code is {$code}.)" : ''));
            } catch (UserFacingException $e) {
                flash('info', 'Please confirm your email address first, with the code we sent to ' . $user['email'] . '.');
            } catch (RuntimeException $e) {
                error_log('Sign-up code email failed: ' . $e->getMessage());
                flash('warning', 'Please confirm your email address first. The email could not be sent just now; try "Send a new code" in a minute.');
            }
            redirect('staff/verify.php');
        } elseif ($user && $passwordOk && !$user['is_active']) {
            // Only after the right password, so the form can't be used to find out which accounts exist.
            $error = 'Your account was turned off by the school.' . ($user['blocked_reason'] ? ' Reason: ' . $user['blocked_reason'] . '.' : '') . ' Please contact the Super Admin.';
            Access::logLogin('staff', (int) $user['id'], $username, false, 'turned off');
        } elseif ($user && $passwordOk && $user['role'] !== 'superadmin' && !Access::loginOpen('staff')) {
            $error = Access::closedMessage('staff');
            Access::logLogin('staff', (int) $user['id'], $username, false, Access::maintenance() ? 'maintenance' : 'sign-in off');
        } elseif ($user && $passwordOk) {
            RateLimiter::clear('staff', $key);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            login_staff($user);
            Access::logLogin('staff', (int) $user['id'], $username, true);
            redirect($user['must_change_password'] ? 'staff/password.php' : home_for(current_staff()));
        } else {
            RateLimiter::recordFailure('staff', $key, $ip);
            Access::logLogin('staff', $user ? (int) $user['id'] : null, $username, false, 'wrong password');
            $error = 'Incorrect username or password.';
        }
    }
}
$closedNotice = Access::loginOpen('staff') ? null : Access::closedMessage('staff') . ' Only the Super Admin can sign in.';

$title = 'Staff sign-in';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Staff sign-in</h1>
                <p class="text-secondary small mb-4">For teachers, advisers and the registrar.</p>
                <?php if ($closedNotice): ?>
                    <div class="alert alert-warning" role="status"><i class="bi bi-slash-circle" aria-hidden="true"></i><div><?= e($closedNotice) ?></div></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('staff/login.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username or email</label>
                        <input type="text" class="form-control" id="username" name="username" value="<?= e($username) ?>"
                               autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary w-100">Sign in</button>
                </form>
                <p class="small text-secondary mt-3 mb-0"><a href="<?= e(url('staff/forgot.php')) ?>">Forgot your password?</a></p>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">
            <?php if (Signup::open()): ?>Teacher without an account? <a href="<?= e(url('signup.php')) ?>">Create one free</a>.<br><?php endif; ?>
            Learners: <a href="<?= e(url('learner/login.php')) ?>">check your grades here</a>.
        </p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
