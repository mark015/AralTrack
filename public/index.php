<?php
// Landing page and learner sign-in: LRN only, or LRN + birthdate when 'require_birthdate' is on in config/config.php.
require dirname(__DIR__) . '/src/bootstrap.php';

if (current_student_id() !== null) {
    redirect('learner/index.php');
}

// How learners sign in (Setup → Settings): LRN only, LRN + birthdate, or LRN + password.
$mode = setting('learner_login');
$askBirthdate = $mode === 'lrn_birthdate';
$askPassword = $mode === 'password';
$error = null;
$needsActivation = false;
$lrn = '';
$birthdate = '';

if (is_post()) {
    $lrn = normalize_lrn(post('lrn'));
    $birthdate = post('birthdate');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = client_ip();

    if (!Access::loginOpen('learner')) {
        $error = Access::closedMessage('learner');
    } elseif (!is_valid_lrn($lrn)) {
        $error = 'Enter your 12-digit LRN.';
    } elseif ($askBirthdate && !valid_ymd($birthdate)) {
        $error = 'Enter your birthdate.';
    } elseif ($askPassword && $password === '') {
        $error = 'Enter your password.';
    } elseif (RateLimiter::isLocked('student', $lrn, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
        Access::logLogin('learner', null, $lrn, false, 'locked');
    } else {
        $student = db_one('SELECT id, birthdate, password_hash, login_blocked, blocked_reason FROM students WHERE lrn = ?', [$lrn]);
        if ($askPassword && $student && $student['password_hash'] === null) {
            $needsActivation = true;
        } else {
            $matches = match ($mode) {
                'password' => password_verify($password, $student['password_hash'] ?? DUMMY_PASSWORD_HASH) && $student !== null,
                'lrn_birthdate' => $student !== null && hash_equals($student['birthdate'], $birthdate),
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
            }
        }
        if (!$error && !$needsActivation) {
            RateLimiter::recordFailure('student', $lrn, $ip);
            Access::logLogin('learner', $student ? (int) $student['id'] : null, $lrn, false, 'no match');
            // With a birthdate or password, the same message whether or not the LRN exists, so the form can't discover LRNs.
            $error = match ($mode) {
                'password' => 'The LRN and password do not match.',
                'lrn_birthdate' => 'The LRN and birthdate do not match our records.',
                default => 'No learner with this LRN was found. Check the number on your report card, or ask your adviser.',
            };
        }
    }
}
$learnersClosed = !Access::loginOpen('learner');

$sy = active_school_year();
$title = 'Check your grades';
$fullWidth = true;
require APP_ROOT . '/templates/header.php';
?>
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="eyebrow mb-3">
                    <i class="bi bi-mortarboard-fill" aria-hidden="true"></i>
                    Senior High School<?= $sy ? ' · S.Y. ' . e($sy['label']) : '' ?>
                </span>
                <h1 class="display-5 mb-3">Grades and class attendance, <span class="highlight">all in one place.</span></h1>
                <p class="lead mb-4">
                    See your quarterly grades, semester averages and attendance in every subject.
                    All you need is your LRN.
                </p>
                <div class="d-grid gap-3">
                    <div class="hero-feature">
                        <div class="ico"><i class="bi bi-journal-check" aria-hidden="true"></i></div>
                        <div><strong>Quarterly grades</strong><span>Released by each subject teacher, with semester averages</span></div>
                    </div>
                    <div class="hero-feature">
                        <div class="ico"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></div>
                        <div><strong>QR class attendance</strong><span>Your ID card is scanned at the start of every class</span></div>
                    </div>
                    <div class="hero-feature">
                        <div class="ico"><i class="bi bi-bell" aria-hidden="true"></i></div>
                        <div><strong>Parent notifications</strong><span>Absences, lates and new grades appear in the parent portal</span></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 offset-lg-1">
                <div class="card login-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="icon-bubble"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
                            <div>
                                <h2 class="h4 mb-0">Check your grades</h2>
                                <div class="small text-secondary">Learners sign in with their LRN<?= $askBirthdate ? ' and birthdate' : ($askPassword ? ' and password' : '') ?></div>
                            </div>
                        </div>

                        <?php if ($learnersClosed): ?>
                            <div class="alert alert-warning" role="status"><i class="bi bi-slash-circle" aria-hidden="true"></i><div><?= e(Access::closedMessage('learner')) ?></div></div>
                        <?php elseif ($error): ?>
                            <div class="alert alert-danger" role="alert"><i class="bi bi-x-octagon-fill" aria-hidden="true"></i><div><?= e($error) ?></div></div>
                        <?php endif; ?>
                        <?php if ($needsActivation): ?>
                            <div class="alert alert-info" role="alert"><i class="bi bi-key-fill" aria-hidden="true"></i>
                                <div>Your account isn't activated yet. <a class="fw-semibold" href="<?= e(url('learner/activate.php?lrn=' . $lrn)) ?>">Activate it with your birthdate</a> and choose a password.</div></div>
                        <?php endif; ?>

                        <?php if (!$learnersClosed): ?>
                        <form method="post" action="<?= e(url('index.php')) ?>">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label for="lrn" class="form-label">Learner Reference Number (LRN)</label>
                                <input type="text" class="form-control form-control-lg lrn" id="lrn" name="lrn"
                                       value="<?= e($lrn) ?>" inputmode="numeric" autocomplete="off" maxlength="16"
                                       placeholder="12 digits" required autofocus aria-describedby="lrnHelp">
                                <div id="lrnHelp" class="form-text">It's on your report card and school ID.</div>
                            </div>
                            <?php if ($askBirthdate): ?>
                                <div class="mb-3">
                                    <label for="birthdate" class="form-label">Birthdate</label>
                                    <input type="date" class="form-control form-control-lg" id="birthdate" name="birthdate"
                                           value="<?= e($birthdate) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                                </div>
                            <?php endif; ?>
                            <?php if ($askPassword): ?>
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control form-control-lg" id="password" name="password" autocomplete="current-password" required>
                                    <div class="form-text">First time here? <a href="<?= e(url('learner/activate.php')) ?>">Activate your account</a>.</div>
                                </div>
                            <?php endif; ?>
                            <button class="btn btn-primary btn-lg w-100 mt-2">
                                View my grades <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container role-strip pb-5">
    <div class="row g-3">
        <div class="col-md-4">
            <a class="card lift role-card h-100 text-decoration-none text-body" href="<?= e(url('parent/login.php')) ?>">
                <div class="card-body p-4">
                    <div class="icon-bubble gold"><i class="bi bi-people-fill" aria-hidden="true"></i></div>
                    <h3 class="h6 mb-1">Parents and guardians</h3>
                    <p class="small text-secondary mb-2">Follow your child's attendance in every class, get notified of absences, and see their report card.</p>
                    <span class="small fw-semibold text-primary">Sign in <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card lift role-card h-100 text-decoration-none text-body" href="<?= e(url('parent/register.php')) ?>">
                <div class="card-body p-4">
                    <div class="icon-bubble green"><i class="bi bi-person-plus-fill" aria-hidden="true"></i></div>
                    <h3 class="h6 mb-1">New parent account</h3>
                    <p class="small text-secondary mb-2">Use your child's LRN and the link code on the slip from the school to create your account.</p>
                    <span class="small fw-semibold text-primary">Create an account <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card lift role-card h-100 text-decoration-none text-body" href="<?= e(url('staff/login.php')) ?>">
                <div class="card-body p-4">
                    <div class="icon-bubble violet"><i class="bi bi-person-badge-fill" aria-hidden="true"></i></div>
                    <h3 class="h6 mb-1">Teachers and staff</h3>
                    <p class="small text-secondary mb-2">Upload grades, take class attendance with QR cards, and manage sections and learners.</p>
                    <span class="small fw-semibold text-primary">Staff sign-in <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                </div>
            </a>
        </div>
    </div>
</section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
