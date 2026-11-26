<?php
// Learner sign-in. The LRN tells which school the learner is in, and that school decides what else is asked
// (Setup → Settings): nothing (LRN only), the birthdate, or a password.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (current_student_id() !== null) {
    redirect('learner/index.php');
}

$error = null;
$needsActivation = false;
$lrn = normalize_lrn(get_str('lrn'));
$birthdate = '';
$ask = null; // 'birthdate' or 'password' once the LRN is known and the school asks for it

if (is_post()) {
    $lrn = normalize_lrn(post('lrn'));
    $birthdate = post('birthdate');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = client_ip();

    if (!is_valid_lrn($lrn)) {
        $error = 'Enter your 12-digit LRN.';
    } elseif (RateLimiter::isLocked('student', $lrn, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
        Access::logLogin('learner', null, $lrn, false, 'locked');
    } else {
        $student = learner_for_lrn($lrn);
        if ($student) {
            Workspace::switchTo((int) $student['workspace_id']); // the learner's school: its sign-in rules
        }
        $mode = $student ? (string) setting('learner_login') : 'lrn';
        $ask = match ($mode) {
            'lrn_birthdate' => 'birthdate',
            'password' => 'password',
            default => null,
        };
        $typed = match ($ask) {
            'birthdate' => $birthdate !== '',
            'password' => $password !== '',
            default => true,
        };

        if ($student && Workspace::isSuspended()) {
            $error = Workspace::suspendedMessage();
            Access::logLogin('learner', (int) $student['id'], $lrn, false, 'school paused');
        } elseif ($student && !Access::loginOpen('learner')) {
            $error = Access::closedMessage('learner');
            Access::logLogin('learner', (int) $student['id'], $lrn, false, Access::maintenance() ? 'maintenance' : 'sign-in off');
        } elseif ($ask === 'password' && $student['password_hash'] === null) {
            $needsActivation = true;
        } elseif ($typed) {
            $matches = match ($mode) {
                'password' => password_verify($password, $student['password_hash'] ?? DUMMY_PASSWORD_HASH),
                'lrn_birthdate' => valid_ymd($birthdate) && hash_equals((string) $student['birthdate'], $birthdate),
                default => $student !== null,
            };
            if ($matches && $student['login_blocked']) {
                $error = 'Your access to the portal was turned off by the school.' . ($student['blocked_reason'] ? ' Reason: ' . $student['blocked_reason'] . '.' : '') . ' Please talk to your adviser.';
                Access::logLogin('learner', (int) $student['id'], $lrn, false, 'turned off');
            } elseif ($matches) {
                RateLimiter::clear('student', $lrn);
                login_student((int) $student['id']);
                Access::logLogin('learner', (int) $student['id'], $lrn, true);
                redirect('learner/index.php');
            } else {
                RateLimiter::recordFailure('student', $lrn, $ip);
                Access::logLogin('learner', $student ? (int) $student['id'] : null, $lrn, false, 'no match');
                $error = match ($mode) {
                    'password' => 'The LRN and password do not match.',
                    'lrn_birthdate' => 'The LRN and birthdate do not match our records.',
                    default => 'No learner with this LRN was found. Check the number on your report card, or ask your adviser.',
                };
            }
        }
        // Otherwise the school asks for the birthdate or the password: the form shows that box next.
    }
}

$title = 'Learner sign-in';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Check your grades</h1>
                <p class="text-secondary small mb-4">Learners sign in with their LRN.</p>
                <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <?php if ($needsActivation): ?>
                    <div class="alert alert-info" role="alert">
                        Your account isn't activated yet. <a class="fw-semibold" href="<?= e(url('learner/activate.php?lrn=' . $lrn)) ?>">Activate it with your birthdate</a> and choose a password.
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('learner/login.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="lrn" class="form-label">Learner Reference Number (LRN)</label>
                        <input type="text" class="form-control form-control-lg lrn" id="lrn" name="lrn"
                               value="<?= e($lrn) ?>" inputmode="numeric" autocomplete="off" maxlength="16"
                               placeholder="12 digits" required <?= $ask ? '' : 'autofocus' ?> aria-describedby="lrnHelp">
                        <div id="lrnHelp" class="form-text">It's on your report card and school ID.</div>
                    </div>
                    <?php if ($ask === 'birthdate'): ?>
                        <div class="mb-3">
                            <label for="birthdate" class="form-label">Birthdate</label>
                            <input type="date" class="form-control form-control-lg" id="birthdate" name="birthdate"
                                   value="<?= e($birthdate) ?>" max="<?= e(date('Y-m-d')) ?>" required autofocus>
                            <div class="form-text">Your school asks for your birthdate too.</div>
                        </div>
                    <?php elseif ($ask === 'password' && !$needsActivation): ?>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control form-control-lg" id="password" name="password" autocomplete="current-password" required autofocus>
                            <div class="form-text">First time here? <a href="<?= e(url('learner/activate.php?lrn=' . $lrn)) ?>">Activate your account</a>.</div>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-lg w-100 mt-2">
                        <?= $ask ? 'View my grades' : 'Continue' ?> <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </button>
                </form>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">
            Parent or guardian? <a href="<?= e(url('parent/login.php')) ?>">Parent sign-in</a> ·
            Teacher or staff? <a href="<?= e(url('staff/login.php')) ?>">Staff sign-in</a>
        </p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
