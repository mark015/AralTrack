<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$student = db_one('SELECT * FROM students WHERE id = ?', [get_int('id')]);
if (!$student) {
    abort(404, 'That learner does not exist.');
}
$studentId = (int) $student['id'];
$here = 'admin/student-edit.php?id=' . $studentId;

$sy = active_school_year();
$sectionOpts = $sy ? section_options((int) $sy['id']) : [];
$sectionIds = array_map('intval', array_column($sectionOpts, 'id'));
$currentSection = $sy
    ? (int) db_value('SELECT section_id FROM enrollments WHERE student_id = ? AND school_year_id = ?', [$studentId, $sy['id']])
    : 0;

$form = [
    'lrn'         => $student['lrn'],
    'last_name'   => $student['last_name'],
    'first_name'  => $student['first_name'],
    'middle_name' => (string) $student['middle_name'],
    'sex'         => (string) $student['sex'],
    'birthdate'   => $student['birthdate'],
    'section_id'  => $currentSection,
];
$errors = [];

if (is_post() && post('action') === 'reissue_qr') {
    db_exec('UPDATE students SET qr_token = ? WHERE id = ?', [new_qr_token(), $studentId]);
    Access::log('account', 'Reissued the QR card of ' . student_name($student), 'learner', $studentId);
    flash('success', 'A new QR card code was made. The old card no longer works. Print the new card below.');
    redirect($here);
}
if (is_post() && post('action') === 'new_parent_code') {
    db_exec('UPDATE students SET parent_code = ? WHERE id = ?', [new_parent_code(), $studentId]);
    Access::log('account', 'Made a new parent link code for ' . student_name($student), 'learner', $studentId);
    flash('success', 'A new parent link code was made. The old code no longer works; parents already linked stay linked.');
    redirect($here);
}
if (is_post() && post('action') === 'reset_password') {
    db_exec('UPDATE students SET password_hash = NULL WHERE id = ?', [$studentId]);
    db_exec("DELETE FROM login_attempts WHERE scope = 'student' AND identifier = ?", [$student['lrn']]);
    Access::signOut('learner', $studentId);
    Access::log('account', 'Cleared the password of ' . student_name($student), 'learner', $studentId);
    flash('success', 'Password cleared. The learner activates the account again with their LRN and birthdate.');
    redirect($here);
}

const PROFILE_FIELDS = [
    'address' => ['Home address', 255], 'mother_tongue' => ['Mother tongue', 60], 'religion' => ['Religion', 60],
    'father_name' => ["Father's name", 150], 'mother_name' => ["Mother's maiden name", 150],
    'guardian_name' => ['Guardian', 150], 'guardian_relationship' => ['Relationship to learner', 40], 'contact_number' => ['Contact number', 40],
];
const LEARNER_STATUSES = ['active' => 'Active', 'graduated' => 'Graduated', 'transferred_out' => 'Transferred out', 'dropped' => 'Dropped'];

if (is_post() && post('action') === 'profile') {
    $values = [];
    foreach (PROFILE_FIELDS as $field => [, $max]) {
        $values[$field] = mb_substr(post($field), 0, $max) ?: null;
    }
    $status = post('status');
    $values['status'] = isset(LEARNER_STATUSES[$status]) ? $status : 'active';
    $values['status_note'] = mb_substr(post('status_note'), 0, 255) ?: null;
    try {
        if (post('remove_photo') === '1') {
            Files::delete($student['photo_path']);
            $values['photo_path'] = null;
        }
        if ($photo = Files::store($_FILES['photo'] ?? null, 'photo')) {
            Files::delete($student['photo_path']);
            $values['photo_path'] = $photo['path'];
        }
        $sets = implode(', ', array_map(static fn ($k) => "{$k} = ?", array_keys($values)));
        db_exec("UPDATE students SET {$sets} WHERE id = ?", [...array_values($values), $studentId]);
        flash('success', 'Profile saved.');
    } catch (UserFacingException $e) {
        flash('danger', $e->getMessage());
    }
    redirect($here);
}

if (is_post() && in_array(post('action'), ['', 'save'], true)) {
    [$data, $errors] = validate_student_form($studentId);
    $sectionId = post_int('section_id');
    if ($sectionId && !in_array($sectionId, $sectionIds, true)) {
        $errors[] = 'Choose a section from the list.';
    }
    $form = $data + ['section_id' => $sectionId];

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                'UPDATE students SET lrn = ?, last_name = ?, first_name = ?, middle_name = ?, sex = ?, birthdate = ? WHERE id = ?',
                [$data['lrn'], $data['last_name'], $data['first_name'], $data['middle_name'] ?: null, $data['sex'] ?: null, $data['birthdate'], $studentId]
            );
            if ($sy && $sectionId !== $currentSection) {
                if ($sectionId) {
                    enroll_student($studentId, $sectionId);
                } else {
                    db_exec('DELETE FROM enrollments WHERE student_id = ? AND school_year_id = ?', [$studentId, $sy['id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', 'Saved ' . student_name($data) . '.');
        redirect($here);
    }
}

$linkedParents = db_all(
    'SELECT p.*, ps.relationship FROM parent_students ps JOIN parents p ON p.id = ps.parent_id WHERE ps.student_id = ? ORDER BY p.full_name',
    [$studentId]
);

$history = db_all(
    'SELECT sy.label, s.id AS section_id, s.grade_level, s.strand, s.name AS section_name
       FROM enrollments e
       JOIN sections s      ON s.id = e.section_id
       JOIN school_years sy ON sy.id = e.school_year_id
      WHERE e.student_id = ?
      ORDER BY sy.label DESC',
    [$studentId]
);

$title = student_name($student);
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/students.php')) ?>">Learners</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(student_name($student)) ?></li>
    </ol>
</nav>
<h1 class="h3"><?= e(student_name($student)) ?></h1>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <?php require APP_ROOT . '/templates/student-fields.php'; ?>
                    <p class="small text-secondary mt-3">
                        The learner signs in with their LRN<?= config('require_birthdate') ? ' and birthdate, so changing either one' : ', so changing it' ?> changes how they sign in.
                        Moving a learner to another section hides the grades entered in the old section.
                    </p>
                    <button class="btn btn-primary">Save changes</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h6 mb-3"><i class="bi bi-person-vcard me-2"></i>Profile (for SF1, SF10 and the attendance scanner)</h2>
                <form method="post" action="<?= e(url($here)) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="d-flex gap-3 align-items-center mb-3">
                        <?php if ($student['photo_path']): ?>
                            <img src="<?= e(url('file.php?kind=photo&id=' . $studentId)) ?>" alt="Learner photo" width="84" height="84" class="rounded-3 object-fit-cover border">
                        <?php else: ?>
                            <?= avatar($student['first_name'] . ' ' . $student['last_name'], 'avatar-lg') ?>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <label class="form-label small" for="photo">Photo <span class="text-secondary">(shown to teachers when the QR card is scanned)</span></label>
                            <input class="form-control form-control-sm" type="file" id="photo" name="photo" accept="<?= e(Files::accept('photo')) ?>">
                            <?php if ($student['photo_path']): ?>
                                <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="rm"><label class="form-check-label small" for="rm">Remove photo</label></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row g-2">
                        <?php foreach (PROFILE_FIELDS as $field => [$label, $max]): ?>
                            <div class="<?= $field === 'address' ? 'col-12' : 'col-sm-6' ?>">
                                <label class="form-label small" for="p-<?= e($field) ?>"><?= e($label) ?></label>
                                <input class="form-control form-control-sm" id="p-<?= e($field) ?>" name="<?= e($field) ?>" maxlength="<?= $max ?>" value="<?= e($student[$field] ?? '') ?>">
                            </div>
                        <?php endforeach; ?>
                        <div class="col-sm-6">
                            <label class="form-label small" for="p-status">Status</label>
                            <select class="form-select form-select-sm" id="p-status" name="status">
                                <?php foreach (LEARNER_STATUSES as $value => $label): ?><option value="<?= e($value) ?>" <?= $student['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small" for="p-note">Status note</label>
                            <input class="form-control form-control-sm" id="p-note" name="status_note" maxlength="255" value="<?= e($student['status_note'] ?? '') ?>" placeholder="e.g. Transferred to … on …">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm mt-3">Save profile</button>
                    <a class="btn btn-outline-primary btn-sm mt-3" href="<?= e(url('print/sf10.php?student=' . $studentId)) ?>" target="_blank"><i class="bi bi-printer me-1"></i>Print SF10</a>
                    <div class="btn-group mt-3">
                        <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-award me-1"></i>Certificate</button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= e(url('print/certificate.php?type=enrollment&student=' . $studentId)) ?>" target="_blank">Certificate of Enrollment</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('print/certificate.php?type=good_moral&student=' . $studentId)) ?>" target="_blank">Good Moral Character</a></li>
                        </ul>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">QR attendance card</h2>
                <div class="d-flex gap-3 align-items-start">
                    <img src="<?= e(qr_image_uri(qr_payload($student['qr_token']))) ?>" alt="QR code on this learner's card" width="110" height="110">
                    <div class="small">
                        <p class="mb-2">Subject teachers scan this card to take class attendance.</p>
                        <a class="btn btn-sm btn-outline-primary mb-2" href="<?= e(url('admin/print.php?type=cards&student=' . $studentId)) ?>" target="_blank">Print card</a>
                        <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reissue_qr">
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Make a new QR card? The current card will stop working.')">Card lost? Reissue</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Parents</h2>
                <p class="small mb-1">Parent link code: <span class="lrn fw-semibold"><?= e(format_parent_code($student['parent_code'])) ?></span></p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/print.php?type=slips&student=' . $studentId)) ?>" target="_blank">Print link slip</a>
                    <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="new_parent_code">
                        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Make a new link code? The current code will stop working for new sign-ups.')">New code</button>
                    </form>
                </div>
                <?php if (!$linkedParents): ?>
                    <p class="small text-secondary mb-0">No parent account is linked yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($linkedParents as $p): ?>
                            <li><a href="<?= e(url('admin/parent-edit.php?id=' . $p['id'])) ?>"><?= e($p['full_name']) ?></a>
                                (<?= e(RELATIONSHIPS[$p['relationship']]) ?>) · <?= e($p['login']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <?php if (setting('learner_login') === 'password'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                        <div class="small">
                            <i class="bi bi-person-lock me-1"></i>Learner account:
                            <?= $student['password_hash'] ? '<span class="badge text-bg-success">Activated</span>' : '<span class="badge text-bg-secondary">Not activated yet</span>' ?>
                        </div>
                        <?php if ($student['password_hash']): ?>
                            <form method="post" action="<?= e(url($here)) ?>" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Clear this learner\'s password? They will activate the account again with their birthdate.')">Reset password</button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <h2 class="h6">Enrollment history</h2>
                <?php if (!$history): ?>
                    <p class="text-secondary small mb-0">Not enrolled in any section yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($history as $h): ?>
                            <li>S.Y. <?= e($h['label']) ?> — <a href="<?= e(url('admin/section.php?id=' . $h['section_id'])) ?>"><?= e(section_label($h)) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
