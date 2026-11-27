<?php
// Super Admin: gate attendance, phone alerts (free) and text messages (SMS, with the school's own SMS account).
// The SMS API key is saved here and never shown again; texts stay off until they are turned on.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('superadmin');
$solo = Workspace::isSolo();
$events = $solo ? array_diff_key(Sms::EVENTS, ['gate_in' => 1, 'gate_out' => 1]) : Sms::EVENTS;
$here = 'superadmin/alerts.php';
$testResult = null;
$balance = null;

$smsLabels = [
    'sms_enabled' => 'Text messages', 'sms_provider' => 'SMS provider', 'sms_sender' => 'Sender name', 'sms_label' => 'Name in texts',
    'sms_events' => 'Texted events', 'sms_to_contact' => 'Text the contact number on the learner record', 'sms_monthly_limit' => 'Monthly limit',
    'push_enabled' => 'Phone alerts', 'gate_late_after' => 'Late at the gate after',
];
/** Saves settings, writes the changes to the activity log (the API key only as "changed"). */
$save = static function (array $after, string $what) use ($smsLabels): void {
    $before = [];
    foreach (array_keys($after) as $key) {
        $before[$key] = (string) (settings_cache()[$key] ?? '');
    }
    $changes = Access::diff($before, $after);
    if (!$changes) {
        flash('info', 'Nothing was changed.');
        return;
    }
    save_settings($after);
    settings_cache(null);
    $words = [];
    $logged = [];
    foreach ($changes as $key => [$old, $new]) {
        if (in_array($key, Sms::SECRET_SETTINGS, true)) {
            $words[] = 'API key ' . ($new === '' ? 'removed' : 'changed');
            $logged[$key] = ['(hidden)', $new === '' ? '(none)' : '(hidden)'];
            continue;
        }
        $words[] = ($smsLabels[$key] ?? $key) . ': ' . match (true) {
            $new === '1' => 'ON',
            $new === '0' => 'OFF',
            $new === '' => '(none)',
            default => '"' . mb_substr($new, 0, 60) . '"',
        };
        $logged[$key] = [$old, $new];
    }
    Access::log('settings', $what . ' changed — ' . implode('; ', $words), 'settings', null, $logged);
    flash('success', $what . ' saved: ' . implode('; ', $words) . '.');
};

if (is_post()) {
    $action = post('action');
    if ($action === 'gate' && !$solo) {
        $late = trim(post('gate_late_after'));
        if ($late !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $late) !== 1) {
            flash('danger', 'Enter the time as hours and minutes, for example 07:30.');
        } else {
            $save(['gate_late_after' => $late], 'Gate attendance');
        }
        redirect($here);
    }
    if ($action === 'push') {
        $save(['push_enabled' => isset($_POST['push_enabled']) ? '1' : '0'], 'Phone alerts');
        redirect($here);
    }
    if ($action === 'sms') {
        $provider = isset(Sms::PROVIDERS[post('sms_provider')]) ? post('sms_provider') : 'test';
        $chosen = array_values(array_intersect(array_keys($events), (array) ($_POST['sms_events'] ?? [])));
        if ($solo) {
            $chosen = array_merge($chosen, array_intersect(['gate_in', 'gate_out'], Sms::events())); // not shown to a teacher on their own: kept
        }
        $sender = trim(post('sms_sender'));
        $limit = trim(post('sms_monthly_limit'));
        $after = [
            'sms_enabled' => isset($_POST['sms_enabled']) ? '1' : '0',
            'sms_provider' => $provider,
            'sms_sender' => mb_substr($sender, 0, 11),
            'sms_label' => mb_substr(trim(post('sms_label')), 0, 30),
            'sms_events' => implode(',', $chosen),
            'sms_to_contact' => isset($_POST['sms_to_contact']) ? '1' : '0',
            'sms_monthly_limit' => $limit === '' ? '' : (string) max(0, (int) $limit),
        ];
        $key = trim(post('sms_api_key'));
        if (post('remove_key') === '1') {
            $after['sms_api_key'] = '';
        } elseif ($key !== '') {
            $after['sms_api_key'] = mb_substr($key, 0, 200);
        }
        $errors = [];
        if ($sender !== '' && preg_match('/^[A-Za-z0-9 ]{1,11}$/', $sender) !== 1) {
            $errors[] = 'The sender name can have up to 11 letters, numbers and spaces (it must be registered with your SMS provider).';
        }
        if ($limit !== '' && !ctype_digit($limit)) {
            $errors[] = 'The monthly limit must be a whole number (leave it empty for no limit).';
        }
        if ($after['sms_enabled'] === '1' && $provider !== 'test' && ($after['sms_api_key'] ?? Sms::apiKey()) === '') {
            $errors[] = 'Paste your API key to turn on text messages (or choose Test mode first).';
        }
        if ($errors) {
            flash('danger', implode(' ', $errors));
        } else {
            $save($after, 'Text messages');
        }
        redirect($here);
    }
    if ($action === 'test_sms') {
        $number = Sms::normalize(post('number'));
        if ($number === null) {
            flash('danger', 'Enter a Philippine mobile number, for example 0917 123 4567.');
            redirect($here);
        }
        $message = Sms::compose('This is a sample text from the school portal. Parents will get texts like this one.');
        $result = Sms::sendNow($number, $message);
        // Kept in the list of texts (written as already sent, so the queue never sends it again)
        db_exec(
            "INSERT INTO sms_messages (workspace_id, number, message, event, status, test, attempts, provider_ref, error, sent_at)
             VALUES (?, ?, ?, 'sample', ?, ?, 1, ?, ?, ?)",
            [ws(), $number, $message, $result['ok'] ? 'sent' : 'failed', Sms::testMode() ? 1 : 0, $result['ref'], $result['error'], $result['ok'] ? date('Y-m-d H:i:s') : null]
        );
        Access::log('settings', 'Sent a sample text message to ' . Sms::display($number) . ($result['ok'] ? '' : ' (failed)'), 'settings', null);
        flash($result['ok'] ? 'success' : 'danger', $result['ok']
            ? (Sms::testMode() ? 'Test mode: the sample text is listed below, but nothing was sent. Choose Semaphore to really send texts.' : 'The sample text was sent to ' . Sms::display($number) . '.')
            : 'The sample text was not sent: ' . $result['error']);
        redirect($here);
    }
    if ($action === 'balance') {
        $balance = Sms::balance();
        flash($balance['ok'] ? 'success' : 'danger', $balance['ok']
            ? 'Your SMS account' . ($balance['account'] ? ' (' . $balance['account'] . ')' : '') . ' has ' . rtrim(rtrim(number_format((float) $balance['credits'], 2), '0'), '.') . ' credits. One credit is one text of up to 160 characters.'
            : 'Could not check the balance: ' . $balance['error']);
        redirect($here);
    }
    redirect($here);
}

$smsOn = Sms::on();
$provider = Sms::provider();
$savedKey = Sms::apiKey();
$chosen = Sms::events();
$texts = db_all(
    'SELECT m.*, st.last_name, st.first_name, st.middle_name FROM sms_messages m LEFT JOIN students st ON st.id = m.student_id
      WHERE m.workspace_id = ? ORDER BY m.id DESC LIMIT 50',
    [ws()]
);
$month = db_one(
    "SELECT SUM(status = 'sent' AND test = 0) AS sent, SUM(status = 'sent' AND test = 1) AS tested, SUM(status = 'failed') AS failed,
            SUM(status IN ('queued', 'sending')) AS waiting
       FROM sms_messages WHERE workspace_id = ? AND created_at >= ?",
    [ws(), date('Y-m-01 00:00:00')]
);
$devices = (int) db_value('SELECT COUNT(DISTINCT parent_id) FROM push_subscriptions WHERE workspace_id = ?', [ws()]);
$parents = (int) db_value('SELECT COUNT(*) FROM parents WHERE workspace_id = ? AND is_active = 1', [ws()]);
$guards = $solo ? 0 : (int) db_value("SELECT COUNT(*) FROM users WHERE workspace_id = ? AND role = 'guard' AND is_active = 1", [ws()]);
$sample = Sms::compose('Ana Santos arrived at school at 7:12 AM, ' . date('M j') . '.');

$switch = static function (string $name, bool $checked, string $label, string $help): string {
    $id = 'sw-' . $name;
    return '<div class="switch-row"><div class="flex-grow-1"><label class="fw-semibold" for="' . e($id) . '">' . e($label) . '</label>'
        . '<div class="small text-secondary">' . e($help) . '</div></div>'
        . '<div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '></div></div>';
};
$statusBadge = static fn (array $m): string => match ($m['status']) {
    'sent' => (int) $m['test'] ? '<span class="badge text-bg-light border">Test · not sent</span>' : '<span class="badge text-bg-success">Sent</span>',
    'failed' => '<span class="badge text-bg-danger">Failed</span>',
    'skipped' => '<span class="badge text-bg-secondary">Not sent</span>',
    default => '<span class="badge text-bg-warning">Waiting</span>',
};

$title = $solo ? 'Phone & SMS alerts' : 'Gate & SMS alerts';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3"><?= e($title) ?></h1>
        <p class="sub">How parents are told<?= $solo ? ' about lates and absences' : ' when their child arrives at and leaves school, and about lates and absences' ?>: in the portal, on their phone (free), and by text message (with your own SMS account).</p>
    </div>
    <?php if (!$solo && Access::on('gate')): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary" href="<?= e(url('gate/index.php')) ?>"><i class="bi bi-qr-code-scan me-1"></i>Gate scanner</a>
            <a class="btn btn-outline-primary" href="<?= e(url('gate/log.php')) ?>"><i class="bi bi-door-open me-1"></i>Gate log</a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" action="<?= e(url($here)) ?>" class="card mb-4" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sms">
            <div class="card-body">
                <h2 class="h6 mb-1"><i class="bi bi-chat-text me-2"></i>Text messages (SMS)</h2>
                <p class="small text-secondary">Texts are sent with your school's own SMS account, so you pay your provider per text (usually one credit per text of up to 160 characters). Off until you turn it on. Use Test mode to see the texts first without sending anything.</p>
                <div class="switch-list mb-3">
                    <?= $switch('sms_enabled', $smsOn, 'Send text messages', $smsOn ? 'On: parents get the texts ticked below.' : 'Off: no texts are sent.') ?>
                </div>

                <label class="form-label fw-semibold small" for="sms-provider">SMS provider</label>
                <select class="form-select mb-3" id="sms-provider" name="sms_provider">
                    <?php foreach (Sms::PROVIDERS as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $provider === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold small" for="sms-key">API key</label>
                        <input type="password" class="form-control" id="sms-key" name="sms_api_key" autocomplete="new-password" spellcheck="false"
                               placeholder="<?= $savedKey !== '' ? 'Saved (…' . e(mb_substr($savedKey, -4)) . '). Empty = keep it' : 'Paste the API key from your provider' ?>">
                        <div class="form-text">Semaphore: sign in at semaphore.co, then Account → API. The key is kept on the server and never shown again.</div>
                        <?php if ($savedKey !== ''): ?>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="remove_key" value="1" id="remove-key">
                                <label class="form-check-label small" for="remove-key">Remove the saved key</label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small" for="sms-sender">Sender name <span class="text-secondary fw-normal">(optional)</span></label>
                        <input class="form-control" id="sms-sender" name="sms_sender" maxlength="11" value="<?= e((string) setting('sms_sender')) ?>" placeholder="e.g. MYSCHOOL">
                        <div class="form-text">What parents see as the sender. It must be registered and approved by your provider; empty uses your account's default.</div>
                    </div>
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label fw-semibold small mb-1">Send a text when a learner…</legend>
                    <?php foreach ($events as $value => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="sms_events[]" value="<?= e($value) ?>" id="ev-<?= e($value) ?>" <?= in_array($value, $chosen, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ev-<?= e($value) ?>"><?= e($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Each event is texted once per number<?= $solo ? '' : ' (arriving: once a day)' ?>. Absences can mean one text per class, so they are off unless you tick them.</div>
                </fieldset>

                <div class="switch-list mb-3">
                    <?= $switch('sms_to_contact', (string) setting('sms_to_contact') !== '0', 'Also text the contact number on the learner record', 'Parents with a portal account get texts at the mobile number they sign in with. This also reaches families without an account.') ?>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small" for="sms-label">School name in texts</label>
                        <input class="form-control" id="sms-label" name="sms_label" maxlength="30" value="<?= e((string) setting('sms_label')) ?>" placeholder="<?= e(mb_substr((string) setting('school_name'), 0, 30)) ?>">
                        <div class="form-text">Short, so the text fits in one message.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small" for="sms-limit">Monthly limit <span class="text-secondary fw-normal">(optional)</span></label>
                        <input class="form-control" id="sms-limit" name="sms_monthly_limit" inputmode="numeric" value="<?= e((string) (settings_cache()['sms_monthly_limit'] ?? '')) ?>" placeholder="No limit">
                        <div class="form-text">Texts stop for the month after this many, to protect your credits.</div>
                    </div>
                </div>

                <div class="mt-3 p-3 rounded border bg-body-tertiary small">
                    <div class="fw-semibold mb-1">A text looks like this (<?= strlen($sample) ?> of 160 characters):</div>
                    <div class="font-monospace"><?= e($sample) ?></div>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-end">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save text messages</button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="bi bi-send me-2"></i>Try it</h2>
                <form method="post" action="<?= e(url($here)) ?>" class="d-flex gap-2 mb-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="test_sms">
                    <label class="visually-hidden" for="test-number">Mobile number</label>
                    <input class="form-control" id="test-number" name="number" inputmode="tel" placeholder="Your mobile, e.g. 0917 123 4567" required>
                    <button class="btn btn-outline-primary text-nowrap">Send a sample</button>
                </form>
                <p class="small text-secondary mb-2"><?= $provider === 'test' ? 'Test mode: nothing is really sent; the sample appears in the list below.' : 'Sends one real text (uses one credit).' ?></p>
                <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                    <?= csrf_field() ?><input type="hidden" name="action" value="balance">
                    <button class="btn btn-sm btn-outline-secondary" <?= $provider === 'test' || $savedKey === '' ? 'disabled' : '' ?>><i class="bi bi-wallet2 me-1"></i>Check my SMS credits</button>
                </form>
                <hr>
                <div class="row text-center g-2 small">
                    <div class="col"><div class="fw-bold fs-5"><?= (int) $month['sent'] ?></div>sent this month</div>
                    <div class="col"><div class="fw-bold fs-5"><?= (int) $month['failed'] ?></div>failed</div>
                    <div class="col"><div class="fw-bold fs-5"><?= (int) $month['waiting'] ?></div>waiting</div>
                    <?php if ((int) $month['tested']): ?><div class="col"><div class="fw-bold fs-5"><?= (int) $month['tested'] ?></div>test only</div><?php endif; ?>
                </div>
            </div>
        </div>

        <form method="post" action="<?= e(url($here)) ?>" class="card mb-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="push">
            <div class="card-body">
                <h2 class="h6 mb-1"><i class="bi bi-phone-vibrate me-2"></i>Phone alerts (free)</h2>
                <p class="small text-secondary">Every parent notification also pops up on the parent's phone, like a message from an app. Parents turn it on themselves: <em>Notifications → Turn on alerts on this phone</em>. On iPhone they first add the portal to the home screen.</p>
                <div class="switch-list">
                    <?= $switch('push_enabled', Alerts::pushOn(), 'Send phone alerts', $devices . ' of ' . $parents . ' parents have turned them on.') ?>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-end">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save phone alerts</button>
            </div>
        </form>

        <?php if (!$solo): ?>
            <form method="post" action="<?= e(url($here)) ?>" class="card mb-4">
                <?= csrf_field() ?><input type="hidden" name="action" value="gate">
                <div class="card-body">
                    <h2 class="h6 mb-1"><i class="bi bi-door-open me-2"></i>Gate attendance</h2>
                    <p class="small text-secondary">
                        <?= Access::on('gate') ? 'On.' : 'Off (turn it on under Control panel → Parts of the system).' ?>
                        Learners scan their ID card at the gate; parents are told when they arrive and leave.
                        Make a <strong>Gate guard</strong> account under Staff accounts for the phone at the gate (it can only scan and see the gate log, and isn't charged as a staff account).
                        <?= $guards ? $guards . ' gate guard account' . ($guards === 1 ? '' : 's') . '.' : '' ?>
                    </p>
                    <label class="form-label fw-semibold small" for="gate-late">Arriving after this time is late</label>
                    <input type="time" class="form-control w-auto" id="gate-late" name="gate_late_after" value="<?= e((string) Gate::lateAfter()) ?>">
                    <div class="form-text">Leave empty to never mark arrivals late.</div>
                </div>
                <div class="card-footer bg-transparent d-flex justify-content-end">
                    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save gate</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<h2 class="h5 mt-2">Latest texts</h2>
<?php if (!$texts): ?>
    <p class="text-secondary">No texts yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle bg-white shadow-sm">
            <thead class="table-light"><tr><th scope="col">When</th><th scope="col">To</th><th scope="col">Learner</th><th scope="col">Text</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($texts as $m): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(date('M j, g:i A', strtotime($m['created_at']))) ?></td>
                    <td class="small text-nowrap"><?= e(Sms::display($m['number'])) ?></td>
                    <td class="small"><?= $m['last_name'] !== null ? e(student_name($m)) : '<span class="text-secondary">' . ($m['event'] === 'sample' ? 'Sample' : '—') . '</span>' ?></td>
                    <td class="small"><?= e($m['message']) ?></td>
                    <td class="small"><?= $statusBadge($m) ?><?= $m['error'] ? '<div class="text-secondary">' . e($m['error']) . '</div>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
