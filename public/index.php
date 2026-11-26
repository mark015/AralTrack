<?php
// Public home page: what the system does, for schools and teachers looking at it, a "request a demo" form
// (read by the platform owner under platform/leads.php) and the sign-in links for learners, parents and staff.
require dirname(__DIR__) . '/src/bootstrap.php';

// Someone already signed in (also the installed app, which opens here) goes straight to their portal.
if ($staff = current_staff()) {
    redirect(home_for($staff));
}
if (current_parent()) {
    redirect('parent/index.php');
}
if (current_student_id() !== null) {
    redirect('learner/index.php');
}

const LEAD_SIZES = ['' => 'Choose…', 'under 300' => 'Under 300', '300–1,000' => '300 to 1,000', '1,000–3,000' => '1,000 to 3,000', 'over 3,000' => 'Over 3,000'];
$lead = ['name' => '', 'kind' => 'school', 'school_name' => '', 'email' => '', 'phone' => '', 'learners' => '', 'message' => ''];
$leadErrors = [];

if (is_post() && post('action') === 'lead') {
    foreach (array_keys($lead) as $field) {
        $lead[$field] = mb_substr(post($field), 0, $field === 'message' ? 2000 : 150);
    }
    $ip = client_ip();
    if (post('website') !== '') {
        redirect('index.php'); // the hidden box is only filled in by spam robots
    }
    if ($lead['name'] === '') {
        $leadErrors[] = 'Enter your name.';
    }
    if (!in_array($lead['kind'], ['school', 'teacher', 'other'], true)) {
        $lead['kind'] = 'other';
    }
    if ($lead['email'] === '' && $lead['phone'] === '') {
        $leadErrors[] = 'Enter an email address or a mobile number so we can reach you.';
    } elseif ($lead['email'] !== '' && !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        $leadErrors[] = 'The email address doesn\'t look right.';
    }
    if (!isset(LEAD_SIZES[$lead['learners']])) {
        $lead['learners'] = '';
    }
    if (!$leadErrors && RateLimiter::isLocked('lead', $ip, $ip)) {
        $leadErrors[] = 'We already received several requests from this connection. Please try again later.';
    }
    if (!$leadErrors) {
        db_exec(
            'INSERT INTO platform_leads (name, kind, school_name, email, phone, learners, message, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$lead['name'], $lead['kind'], $lead['school_name'] ?: null, $lead['email'] ?: null, mb_substr($lead['phone'], 0, 30) ?: null,
             $lead['learners'] ?: null, $lead['message'] ?: null, $ip]
        );
        RateLimiter::recordFailure('lead', $ip, $ip); // counts requests per connection
        flash('success', 'Thank you! We received your request and will contact you within two working days.');
        redirect('index.php#start');
    }
}

$features = [
    ['qr-code-scan', '', 'QR attendance per subject', 'Teachers scan learners\' ID cards with a phone camera or a USB scanner. Late and absent are marked automatically, and parents are told.'],
    ['wifi-off', 'violet', 'Works without internet', 'No signal in the classroom? Attendance is saved on the teacher\'s phone and sent by itself when the connection is back.'],
    ['journal-check', 'green', 'Class record and grades', 'Written work, performance tasks and quarterly assessment with DepEd weights and transmutation. Or upload an Excel grade sheet.'],
    ['people', 'gold', 'Parent portal', 'Parents see attendance in every class and the report card, send excuse letters and message teachers. No app to install.'],
    ['file-earmark-spreadsheet', 'teal', 'DepEd forms in one click', 'SF1, SF2 and SF5 as Excel files, SF9 report cards and SF10 permanent records ready to print.'],
    ['exclamation-diamond', 'rose', 'At-risk learners', 'Learners with failing grades or many absences show up early, with notes from advisers and guidance.'],
    ['envelope-paper', 'violet', 'Requests and messages', 'Document requests, excuse letters, announcements and a school calendar in the same place.'],
    ['shield-lock', 'green', 'You stay in control', 'Turn parts of the portal on or off, limit one person\'s access, see every sign-in and change.'],
];
$faqs = [
    ['Do learners and parents need to install an app?', 'No. The portal opens in any phone browser. It can also be added to the home screen like an app, and teachers can then take attendance even with no internet.'],
    ['What does a school need to start?', 'An internet connection and the class lists. You add sections and subjects, import learners from an Excel file, print the QR ID cards, and teachers can start the same day.'],
    ['Can other schools see our data?', 'No. Every school has its own separate space. Staff, learners and parents only ever see their own school, and only what their role allows.'],
    ['Is it made for the Philippines?', 'Yes: LRN sign-in, two semesters with Q1–Q4, the DepEd grading weights and transmutation table, and the SF forms schools already use.'],
    ['I\'m a teacher, not a whole school. Can I use it?', 'A plan for teachers on their own is coming: your own classes, QR attendance and class record, and you can bring your records along if your school joins later. Send a request below and we\'ll tell you when it opens.'],
    ['How much does it cost?', 'It depends on the size of the school. Request a demo and we\'ll send you a quote. You can try it with your own school before paying.'],
];

$title = 'School portal for grades, QR attendance and parents';
$fullWidth = true;
require APP_ROOT . '/templates/header.php';
?>
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="eyebrow mb-3"><i class="bi bi-mortarboard-fill" aria-hidden="true"></i> For Senior High Schools in the Philippines</span>
                <h1 class="display-5 mb-3">Grades, QR attendance and parent updates, <span class="highlight">in one simple portal.</span></h1>
                <p class="lead mb-4">
                    <?= e(config('app_name')) ?> gives your school one place for class records, report cards and attendance in every subject,
                    and keeps parents informed by itself. It works on any phone or laptop, even when the internet drops.
                </p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a class="btn btn-accent btn-lg" href="#start"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Request a free demo</a>
                    <a class="btn btn-outline-light btn-lg" href="#signin">Sign in</a>
                </div>
                <div class="hero-proof small"><i class="bi bi-check2-circle" aria-hidden="true"></i> Each school's data kept separate
                    <i class="bi bi-check2-circle ms-3" aria-hidden="true"></i> No app to install</div>
            </div>
            <div class="col-lg-5 offset-lg-1">
                <div class="mock-card" aria-hidden="true">
                    <div class="mock-head">
                        <div>
                            <div class="mock-title">General Chemistry 1</div>
                            <div class="mock-sub">Grade 11 STEM · Rizal · 7:30 AM</div>
                        </div>
                        <span class="mock-live"><span class="dot"></span>Scanning</span>
                    </div>
                    <div class="mock-counts">
                        <div><strong>31</strong><span>Present</span></div>
                        <div><strong>3</strong><span>Late</span></div>
                        <div><strong>2</strong><span>Absent</span></div>
                    </div>
                    <?php foreach ([['Ana Reyes', 'present', '7:28'], ['Carlo Mendoza', 'present', '7:29'], ['Bea Santos', 'late', '7:47'], ['Dino Cruz', 'absent', '']] as [$name, $status, $time]): ?>
                        <div class="mock-row">
                            <?= avatar($name) ?>
                            <span class="flex-grow-1"><?= e($name) ?></span>
                            <span class="mock-time"><?= e($time) ?></span>
                            <span class="mock-badge <?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="mock-foot"><i class="bi bi-phone" aria-hidden="true"></i> Saved on this phone · sends when back online</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container chip-strip" aria-label="Made for DepEd Senior High School">
    <?php foreach (['LRN sign-in', '2 semesters · Q1–Q4', 'WW · PT · QA class record', 'SF1 · SF2 · SF5 · SF9 · SF10', 'Data Privacy Act–ready'] as $chip): ?>
        <span><i class="bi bi-check-lg" aria-hidden="true"></i><?= e($chip) ?></span>
    <?php endforeach; ?>
</section>

<section class="container landing-section" id="features">
    <div class="landing-head">
        <span class="section-title">Everything in one place</span>
        <h2>Less paperwork for teachers. Fewer surprises for parents.</h2>
    </div>
    <div class="row g-3">
        <?php foreach ($features as [$icon, $tone, $heading, $text]): ?>
            <div class="col-sm-6 col-lg-3">
                <div class="card feature-card h-100">
                    <div class="card-body">
                        <div class="icon-bubble <?= e($tone) ?>"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                        <h3 class="h6 mt-3 mb-1"><?= e($heading) ?></h3>
                        <p class="small text-secondary mb-0"><?= e($text) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="landing-band">
    <div class="container landing-section">
        <div class="landing-head">
            <span class="section-title">Who it's for</span>
            <h2>For whole schools, and soon for teachers on their own</h2>
        </div>
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card plan-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="icon-bubble"><i class="bi bi-buildings" aria-hidden="true"></i></div>
                            <div><h3 class="h5 mb-0">Schools</h3><div class="small text-secondary">The whole portal for everyone in the school</div></div>
                        </div>
                        <ul class="check-list">
                            <li>Registrar, principal, guidance, advisers and every teacher, each with the right screens</li>
                            <li>Learners check grades with their LRN; parents follow every class</li>
                            <li>A Super Admin controls sign-in, features and each person's access</li>
                            <li>Your school's records stay yours, separate from every other school</li>
                        </ul>
                        <a class="btn btn-primary" href="#start">Request a demo for your school</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card plan-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="icon-bubble violet"><i class="bi bi-person-workspace" aria-hidden="true"></i></div>
                            <div><h3 class="h5 mb-0">Teachers <span class="badge text-bg-warning align-middle">Coming soon</span></h3><div class="small text-secondary">For a teacher whose school isn't on <?= e(config('app_name')) ?> yet</div></div>
                        </div>
                        <ul class="check-list">
                            <li>Your own classes, QR attendance and class record</li>
                            <li>Parents of your learners get notices and can see your subject</li>
                            <li>If your school joins later, your classes and records move with you</li>
                        </ul>
                        <a class="btn btn-outline-primary" href="#start">Tell me when it opens</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container landing-section">
    <div class="landing-head">
        <span class="section-title">How it works</span>
        <h2>Ready in a day</h2>
    </div>
    <div class="row g-4">
        <?php foreach ([
            ['Set up your school', 'Add school years, sections and subjects, then import learners from an Excel file. Teachers get their accounts.'],
            ['Print the QR ID cards', 'Every learner gets a card with a QR code, and every parent a slip to create their own account.'],
            ['Teach as usual', 'Scan cards at the start of class, enter scores in the class record and release grades when they\'re ready.'],
        ] as $i => [$heading, $text]): ?>
            <div class="col-md-4">
                <div class="step">
                    <span class="step-num"><?= $i + 1 ?></span>
                    <h3 class="h6 mb-1"><?= e($heading) ?></h3>
                    <p class="small text-secondary mb-0"><?= e($text) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="landing-band">
    <div class="container landing-section">
        <div class="row g-5 align-items-start">
            <div class="col-lg-5">
                <span class="section-title">Privacy and security</span>
                <h2 class="mb-3">Learner records are personal. We treat them that way.</h2>
                <ul class="check-list">
                    <li>Every school's data is kept in its own separate space</li>
                    <li>People only see what their role allows: a parent sees their own child, a teacher their own classes</li>
                    <li>Every sign-in and every account or settings change is logged</li>
                    <li>Parents agree to the school's privacy notice before they get access</li>
                    <li>Secure connection (HTTPS); passwords are never stored in readable form</li>
                </ul>
            </div>
            <div class="col-lg-7">
                <span class="section-title">Questions</span>
                <div class="accordion faq" id="faq">
                    <?php foreach ($faqs as $i => [$question, $answer]): ?>
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>" aria-expanded="false" aria-controls="faq<?= $i ?>"><?= e($question) ?></button>
                            </h3>
                            <div id="faq<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faq">
                                <div class="accordion-body small"><?= e($answer) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container landing-section" id="start">
    <div class="row g-5 align-items-center">
        <div class="col-lg-5">
            <span class="section-title">Get started</span>
            <h2 class="mb-3">See it with your own school</h2>
            <p class="text-secondary">Tell us a little about your school. We'll show you the portal, answer your questions and set up a trial with your own sections and learners.</p>
            <ul class="check-list small">
                <li>A free demo, online or at your school</li>
                <li>Help importing your class lists</li>
                <li>A quote that fits the size of your school</li>
            </ul>
        </div>
        <div class="col-lg-7">
            <div class="card auth-card">
                <div class="card-body">
                    <?php if ($leadErrors): ?><div class="alert alert-danger" role="alert"><?= e(implode(' ', $leadErrors)) ?></div><?php endif; ?>
                    <form method="post" action="<?= e(url('index.php')) ?>#start" class="row g-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="lead">
                        <div class="visually-hidden" aria-hidden="true">
                            <label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small" for="lead-name">Your name</label>
                            <input class="form-control" id="lead-name" name="name" value="<?= e($lead['name']) ?>" maxlength="150" required autocomplete="name">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small" for="lead-kind">I am</label>
                            <select class="form-select" id="lead-kind" name="kind">
                                <?php foreach (['school' => 'From a school (head, principal, registrar, IT)', 'teacher' => 'A teacher', 'other' => 'Something else'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $lead['kind'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label small" for="lead-school">School</label>
                            <input class="form-control" id="lead-school" name="school_name" value="<?= e($lead['school_name']) ?>" maxlength="150" autocomplete="organization">
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small" for="lead-learners">Number of learners</label>
                            <select class="form-select" id="lead-learners" name="learners">
                                <?php foreach (LEAD_SIZES as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $lead['learners'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small" for="lead-email">Email</label>
                            <input class="form-control" type="email" id="lead-email" name="email" value="<?= e($lead['email']) ?>" maxlength="150" autocomplete="email">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small" for="lead-phone">Mobile number</label>
                            <input class="form-control" id="lead-phone" name="phone" value="<?= e($lead['phone']) ?>" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="0917 123 4567">
                        </div>
                        <div class="col-12">
                            <label class="form-label small" for="lead-message">Anything we should know? <span class="text-secondary">(optional)</span></label>
                            <textarea class="form-control" id="lead-message" name="message" rows="3" maxlength="2000"><?= e($lead['message']) ?></textarea>
                        </div>
                        <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                            <button class="btn btn-primary btn-lg">Request a demo</button>
                            <span class="small text-secondary">We only use these details to reply to you.</span>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="landing-band" id="signin">
    <div class="container landing-section">
        <div class="landing-head">
            <span class="section-title">Already using <?= e(config('app_name')) ?>?</span>
            <h2>Sign in</h2>
        </div>
        <div class="row g-3">
            <?php foreach ([
                ['learner/login.php', 'person-vcard', '', 'Learners', 'Check your grades and class attendance with your LRN.', 'Learner sign-in'],
                ['parent/login.php', 'people-fill', 'gold', 'Parents and guardians', 'Follow your child\'s attendance and grades. New here? Use the slip from the school.', 'Parent sign-in'],
                ['staff/login.php', 'person-badge-fill', 'violet', 'Teachers and staff', 'Take attendance, keep your class record and run your school\'s portal.', 'Staff sign-in'],
            ] as [$href, $icon, $tone, $heading, $text, $cta]): ?>
                <div class="col-md-4">
                    <a class="card lift role-card h-100 text-decoration-none text-body" href="<?= e(url($href)) ?>">
                        <div class="card-body p-4">
                            <div class="icon-bubble <?= e($tone) ?>"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                            <h3 class="h6 mb-1"><?= e($heading) ?></h3>
                            <p class="small text-secondary mb-2"><?= e($text) ?></p>
                            <span class="small fw-semibold text-primary"><?= e($cta) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-secondary mt-3 mb-0">New parent account? <a href="<?= e(url('parent/register.php')) ?>">Create one</a> with your child's LRN and the link code from the school.</p>
    </div>
</section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
