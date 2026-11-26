<?php
// "Forgot your password?" for staff accounts with a confirmed email (teachers who signed up). Always gives the
// same answer, so it can't be used to find out who has an account. School staff without an email ask their admin.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if ($staff = current_staff()) {
    redirect(home_for($staff));
}

$email = '';
if (is_post()) {
    $email = mb_strtolower(trim(post('email')));
    $ip = client_ip();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Enter the email address you signed up with.');
        redirect('staff/forgot.php');
    }
    $link = null;
    if (!RateLimiter::isLocked('reset', $email, $ip) && Mailer::ready()) {
        RateLimiter::recordFailure('reset', $email, $ip); // counts requests per address and connection
        try {
            $link = Signup::sendReset($email);
        } catch (RuntimeException $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
        }
    }
    flash('success', 'If an account uses ' . $email . ', we sent it a link to choose a new password. It works for '
        . Signup::RESET_MINUTES . ' minutes. Check the spam folder too.'
        . ($link ? " (Test mode: emails aren't sent from this computer. The link is {$link})" : ''));
    redirect('staff/forgot.php');
}

$title = 'Forgot your password?';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-key" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Forgot your password?</h1>
                <p class="text-secondary small mb-4">Enter the email you signed up with, and we'll send you a link to choose a new password.</p>
                <form method="post" action="<?= e(url('staff/forgot.php')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control mb-3" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
                    <button class="btn btn-primary w-100">Send the link</button>
                </form>
                <p class="small text-secondary mt-3 mb-0">Teacher or staff at a school, without an email on your account? Ask your school's admin or registrar to reset your password.</p>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3"><a href="<?= e(url('staff/login.php')) ?>">Back to sign-in</a></p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
