<?php
// Teachers create their own account: a space of their own for their classes, learners and parents.
// The email address is confirmed with a code (staff/verify.php) before the first sign-in.
require dirname(__DIR__) . '/src/bootstrap.php';

if ($staff = current_staff()) {
    redirect(home_for($staff));
}

$open = Signup::open();
$form = ['full_name' => '', 'email' => '', 'school_name' => ''];
$errors = [];

if (is_post() && $open) {
    foreach (array_keys($form) as $field) {
        $form[$field] = post($field);
    }
    $email = mb_strtolower(trim($form['email']));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    $ip = client_ip();

    if (post('website') !== '') {
        redirect('signup.php'); // the hidden box is only filled in by spam robots
    }
    if (mb_strlen($form['full_name']) < 2 || mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Enter your full name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors[] = 'Enter a valid email address. We send a code to it.';
    } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
        $errors[] = 'An account with this email already exists. Sign in, or use "Forgot your password?" on the sign-in page.';
    }
    if (mb_strlen($form['school_name']) < 2 || mb_strlen($form['school_name']) > 150) {
        $errors[] = 'Enter the school where you teach. It is printed on QR cards and report cards.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Choose a password of at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if (post('agree') !== '1') {
        $errors[] = 'Please confirm that you may keep your learners\' records here.';
    }
    if (!$errors && RateLimiter::isLocked('signup', $ip, $ip)) {
        $errors[] = 'Several accounts were just created from this connection. Please try again later.';
    }

    if (!$errors) {
        try {
            $user = Signup::createTeacher($form['full_name'], $email, $password, $form['school_name']);
        } catch (PDOException $e) {
            if (!is_duplicate_error($e)) {
                throw $e;
            }
            $user = null;
            $errors[] = 'An account with this email already exists. Sign in, or use "Forgot your password?" on the sign-in page.';
        }
        if ($user) {
            RateLimiter::recordFailure('signup', $ip, $ip); // counts sign-ups per connection
            session_regenerate_id(true);
            $_SESSION['verify_user'] = (int) $user['id'];
            try {
                $code = Signup::sendVerifyCode($user);
                if (Mailer::isTestMode()) {
                    flash('info', "Test mode: emails aren't sent from this computer (they are saved in storage/mail). Your code is {$code}.");
                }
            } catch (RuntimeException $e) {
                error_log('Sign-up code email failed: ' . $e->getMessage());
                flash('warning', 'Your account was created, but the email could not be sent just now. Press "Send a new code" in a minute.');
            }
            redirect('staff/verify.php');
        }
    }
}

$title = 'Create a teacher account';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center align-items-center g-5 py-lg-4">
    <div class="col-lg-5">
        <span class="section-title">For teachers</span>
        <h1 class="h2 mb-3">Your classes, attendance and grades, in one place</h1>
        <p class="text-secondary">Even if your school isn't on <?= e(config('app_name')) ?> yet, you can use it for your own classes.</p>
        <ul class="check-list">
            <li>QR attendance in every class, also without internet</li>
            <li>A DepEd class record that computes the quarterly grades</li>
            <li>Learners check their grades with their LRN; parents get notices and can message you</li>
            <li>QR ID cards and parent slips ready to print</li>
            <li>If your school joins later, your classes and records come with you</li>
        </ul>
    </div>
    <div class="col-md-8 col-lg-5">
        <div class="card auth-card">
            <div class="card-body">
                <?php if (!$open): ?>
                    <div class="auth-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
                    <h2 class="h4">Teacher sign-up opens soon</h2>
                    <p class="text-secondary mb-3">Leave your details and we'll tell you when you can create your account.</p>
                    <a class="btn btn-primary" href="<?= e(url('index.php#start')) ?>">Tell me when it opens</a>
                <?php else: ?>
                    <h2 class="h4 mb-1">Create your free account</h2>
                    <p class="text-secondary small mb-4">It takes a minute. We'll send a code to your email.</p>
                    <?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
                    <form method="post" action="<?= e(url('signup.php')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="visually-hidden" aria-hidden="true">
                            <label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="full_name">Your full name</label>
                            <input class="form-control" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" autocomplete="name" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="150" autocomplete="email" required>
                            <div class="form-text">You sign in with it, and get a code to confirm it.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="school_name">School where you teach</label>
                            <input class="form-control" id="school_name" name="school_name" value="<?= e($form['school_name']) ?>" maxlength="150" autocomplete="organization" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="password">Password</label>
                                <input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="confirm">Password again</label>
                                <input class="form-control" type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="agree" name="agree" value="1" required>
                            <label class="form-check-label small" for="agree">
                                I may keep my learners' records here (for example, my school agrees), and I have read the
                                <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">privacy notice</a>.
                            </label>
                        </div>
                        <button class="btn btn-primary btn-lg w-100">Create my account</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">Already have an account? <a href="<?= e(url('staff/login.php')) ?>">Sign in</a></p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
