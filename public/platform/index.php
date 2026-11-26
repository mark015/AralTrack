<?php
// Platform console: every school and solo teacher on the system, and adding a new one.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$owner = require_owner();

$form = ['type' => 'school', 'name' => '', 'contact_name' => '', 'contact_email' => '', 'contact_phone' => '', 'plan' => 'trial', 'username' => '', 'full_name' => ''];
$errors = [];

if (is_post() && post('action') === 'add') {
    foreach (array_keys($form) as $field) {
        $form[$field] = post($field);
    }
    if (!isset(Workspace::TYPES[$form['type']])) {
        $errors[] = 'Choose school or solo teacher.';
    }
    if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
        $errors[] = 'Enter the school name (or the teacher\'s name for a solo teacher).';
    }
    if (!isset(Workspace::PLANS[$form['plan']])) {
        $errors[] = 'Choose a plan.';
    }
    if ($form['contact_email'] !== '' && !filter_var($form['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The contact email doesn\'t look right.';
    }
    if (mb_strlen($form['contact_name']) > 150 || mb_strlen($form['contact_phone']) > 30) {
        $errors[] = 'The contact name or phone is too long.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $form['username'])) {
        $errors[] = 'The first account\'s username needs 3–50 letters, numbers, dots, dashes or underscores.';
    } elseif (db_value('SELECT id FROM users WHERE username = ?', [$form['username']])) {
        $errors[] = 'That username is already used (usernames are unique across all schools). Try adding the school\'s initials.';
    }
    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Enter the name of the person who gets the first account.';
    }

    if (!$errors) {
        $password = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                'INSERT INTO workspaces (type, name, plan, trial_ends_at, contact_name, contact_email, contact_phone) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$form['type'], $form['name'], $form['plan'], $form['plan'] === 'trial' ? Billing::trialEnd() : null, $form['contact_name'] ?: null, $form['contact_email'] ?: null, $form['contact_phone'] ?: null]
            );
            $workspaceId = (int) $pdo->lastInsertId();
            db_exec(
                "INSERT INTO users (workspace_id, username, password_hash, full_name, role, must_change_password) VALUES (?, ?, ?, ?, 'superadmin', 1)",
                [$workspaceId, $form['username'], password_hash($password, PASSWORD_DEFAULT), $form['full_name']]
            );
            db_exec("INSERT INTO settings (workspace_id, name, value) VALUES (?, 'school_name', ?)", [$workspaceId, $form['name']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        platform_log('workspace', "Added {$form['name']} (" . Workspace::TYPES[$form['type']] . ") with the Super Admin account {$form['username']}", $workspaceId);
        flash('success', "{$form['name']} was added. Give {$form['full_name']} the username \"{$form['username']}\" and the temporary password \"{$password}\" "
            . '(shown only now). They sign in at Staff sign-in and must choose a new password.');
        redirect('platform/workspace.php?id=' . $workspaceId);
    }
}

$workspaces = db_all(
    "SELECT w.*,
            (SELECT COUNT(*) FROM users u WHERE u.workspace_id = w.id AND u.is_active = 1) AS staff,
            (SELECT COUNT(*) FROM students s WHERE s.workspace_id = w.id) AS learners,
            (SELECT COUNT(*) FROM parents p WHERE p.workspace_id = w.id) AS parents,
            (SELECT MAX(le.created_at) FROM login_events le WHERE le.workspace_id = w.id AND le.success = 1) AS last_sign_in
       FROM workspaces w
      ORDER BY w.status = 'active' DESC, w.type, w.name"
);
$totals = [
    'schools'  => count(array_filter($workspaces, static fn ($w) => $w['type'] === 'school' && $w['status'] === 'active')),
    'teachers' => count(array_filter($workspaces, static fn ($w) => $w['type'] === 'teacher' && $w['status'] === 'active')),
    'learners' => array_sum(array_map(static fn ($w) => (int) $w['learners'], $workspaces)),
    'leads'    => (int) db_value("SELECT COUNT(*) FROM platform_leads WHERE status = 'new'"),
];
$log = db_all(
    'SELECT l.*, w.name AS workspace_name FROM platform_log l LEFT JOIN workspaces w ON w.id = l.workspace_id ORDER BY l.id DESC LIMIT 12'
);

$title = 'Schools';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Schools</h1>
        <p class="sub">Every school and solo teacher on <?= e(config('app_name')) ?>. Each one only ever sees its own data.</p>
    </div>
    <a class="btn btn-primary" href="#add"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add a school</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Schools', $totals['schools'], 'buildings', ''],
        ['Solo teachers', $totals['teachers'], 'person-workspace', 'violet'],
        ['Learners', $totals['learners'], 'people', 'green'],
        ['New demo requests', $totals['leads'], 'inbox', 'gold'],
    ] as [$label, $value, $icon, $tone]): ?>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon-bubble <?= e($tone) ?>"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                    <div><div class="h4 mb-0"><?= number_format((int) $value) ?></div><div class="small text-secondary"><?= e($label) ?></div></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="table-responsive mb-4">
    <table class="table bg-white shadow-sm align-middle">
        <thead class="table-light">
        <tr>
            <th scope="col">Name</th><th scope="col">Type</th><th scope="col">Plan</th>
            <th scope="col" class="text-center">Staff</th><th scope="col" class="text-center">Learners</th><th scope="col" class="text-center">Parents</th>
            <th scope="col">Last sign-in</th><th scope="col"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($workspaces as $w): ?>
            <tr>
                <td>
                    <a class="fw-semibold" href="<?= e(url('platform/workspace.php?id=' . (int) $w['id'])) ?>"><?= e($w['name']) ?></a>
                    <?php if ($w['status'] !== 'active'): ?><span class="badge text-bg-danger ms-1">Paused</span><?php endif; ?>
                    <?php if ($w['contact_email'] || $w['contact_name']): ?>
                        <div class="small text-secondary"><?= e(trim(($w['contact_name'] ?? '') . ' ' . ($w['contact_email'] ?? ''))) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e(Workspace::TYPES[$w['type']] ?? $w['type']) ?></td>
                <?php $bill = Billing::state($w); ?>
                <td class="small"><?= e($bill['label']) ?><?= $bill['ends'] ? '<div class="text-secondary">until ' . e(date('M j', strtotime($bill['ends']))) . '</div>' : '' ?><?= $bill['read_only'] ? '<div class="text-danger">read-only</div>' : '' ?></td>
                <td class="text-center"><?= (int) $w['staff'] ?></td>
                <td class="text-center"><?= (int) $w['learners'] ?></td>
                <td class="text-center"><?= (int) $w['parents'] ?></td>
                <td class="small text-secondary"><?= $w['last_sign_in'] ? e(when_label($w['last_sign_in'])) : 'Never' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('platform/workspace.php?id=' . (int) $w['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="row g-4">
    <div class="col-lg-7" id="add">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Add a school or solo teacher</h2>
                <p class="small text-secondary">Creates an empty workspace and its first account (a Super Admin with a temporary password). They set up school years, sections, subjects and learners themselves.</p>
                <?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('platform/index.php')) ?>#add" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="col-sm-5">
                        <label class="form-label small" for="type">Type</label>
                        <select class="form-select" id="type" name="type">
                            <?php foreach (Workspace::TYPES as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $form['type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-7">
                        <label class="form-label small" for="name">School name (or teacher's name)</label>
                        <input class="form-control" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="150" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="contact_name">Contact person</label>
                        <input class="form-control" id="contact_name" name="contact_name" value="<?= e($form['contact_name']) ?>" maxlength="150">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="contact_email">Contact email</label>
                        <input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= e($form['contact_email']) ?>" maxlength="150">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="contact_phone">Contact mobile</label>
                        <input class="form-control" id="contact_phone" name="contact_phone" value="<?= e($form['contact_phone']) ?>" maxlength="30">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="plan">Plan</label>
                        <select class="form-select" id="plan" name="plan">
                            <?php foreach (Workspace::PLANS as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $form['plan'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="full_name">First account: name</label>
                        <input class="form-control" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="username">First account: username</label>
                        <input class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" maxlength="50" required pattern="[A-Za-z0-9._\-]{3,50}">
                    </div>
                    <div class="col-12"><button class="btn btn-primary">Add</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Recent console activity</h2>
                <?php if (!$log): ?><p class="text-secondary small mb-0">Nothing yet.</p><?php endif; ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($log as $row): ?>
                        <li class="py-2 border-bottom">
                            <div><?= e($row['summary']) ?></div>
                            <div class="text-secondary"><?= e(when_label($row['created_at'])) ?> · <?= e($row['owner_name']) ?><?= $row['workspace_name'] ? ' · ' . e($row['workspace_name']) : '' ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
