<?php
// Confirming a new teacher's email address with the 6-digit code we emailed. Reached right after sign-up, or after
// signing in to an account whose email isn't confirmed yet (both put the account in the session first).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$userId = (int) ($_SESSION['verify_user'] ?? 0);
$user = $userId ? db_one('SELECT * FROM users WHERE id = ?', [$userId]) : null;
if (!$user || $user['email'] === null) {
    unset($_SESSION['verify_user']);
    redirect('staff/login.php');
}
if ($user['email_verified_at'] !== null) {
    unset($_SESSION['verify_user']);
    flash('success', 'Your email is already confirmed. Please sign in.');
    redirect('staff/login.php');
}

$error = null;
if (is_post()) {
    if (post('action') === 'resend') {
        try {
            $code = Signup::sendVerifyCode($user);
            flash('success', 'We sent a new code to ' . $user['email'] . '.'
                . (Mailer::isTestMode() ? " (Test mode: emails aren't sent from this computer. The code is {$code}.)" : ''));
        } catch (UserFacingException $e) {
            flash('warning', $e->getMessage());
        } catch (RuntimeException $e) {
            error_log('Sign-up code email failed: ' . $e->getMessage());
            flash('danger', 'The email could not be sent just now. Please try again in a few minutes.');
        }
        redirect('staff/verify.php');
    }
    if (Signup::confirm((int) $user['id'], post('code'))) {
        unset($_SESSION['verify_user']);
        $user = db_one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        login_staff($user);
        Access::logLogin('staff', (int) $user['id'], (string) $user['email'], true, 'email confirmed');
        flash('success', 'Your email is confirmed. Welcome! Let\'s set up your classes.');
        redirect(home_for(current_staff()));
    }
    $error = 'That code is not right, or it has expired. Use the code in the newest email, or send a new one.';
}

$title = 'Confirm your email';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-envelope-check" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Check your email</h1>
                <p class="text-secondary small mb-4">We sent a 6-digit code to <strong><?= e($user['email']) ?></strong>. It works for <?= Signup::CODE_MINUTES ?> minutes. Check the spam folder too.</p>
                <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('staff/verify.php')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control form-control-lg text-center mb-3" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="7" pattern="[0-9 ]{6,7}" placeholder="123456" required autofocus style="letter-spacing: .3em">
                    <button class="btn btn-primary w-100">Confirm</button>
                </form>
                <form method="post" action="<?= e(url('staff/verify.php')) ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend">
                    <button class="btn btn-link btn-sm p-0">Didn't get it? Send a new code</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
