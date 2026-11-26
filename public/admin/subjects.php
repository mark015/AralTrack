<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$errors = [];
$editing = null;
$form = ['id' => 0, 'code' => '', 'name' => '', 'type' => 'core', 'sort_order' => '0', 'ww' => '', 'pt' => '', 'qa' => ''];
$weightText = static fn ($v) => $v === null || $v === '' ? '' : format_grade($v);

if ($editId = get_int('edit')) {
    $editing = db_one('SELECT * FROM subjects WHERE id = ? AND workspace_id = ?', [$editId, ws()]);
    if ($editing) {
        $form = ['id' => (int) $editing['id'], 'code' => $editing['code'], 'name' => $editing['name'], 'type' => $editing['type'], 'sort_order' => (string) $editing['sort_order'],
            'ww' => $weightText($editing['ww_weight']), 'pt' => $weightText($editing['pt_weight']), 'qa' => $weightText($editing['qa_weight'])];
    }
}

if (is_post()) {
    if (post('action') === 'save') {
        $form = ['id' => post_int('id'), 'code' => strtoupper(post('code')), 'name' => post('name'), 'type' => post('type'), 'sort_order' => post('sort_order') ?: '0',
            'ww' => post('ww'), 'pt' => post('pt'), 'qa' => post('qa')];
        if (!isset(SUBJECT_TYPES[$form['type']])) {
            $errors[] = 'Choose whether the subject is core, applied or specialized.';
        }
        if (!preg_match('/^[A-Z0-9-]{1,20}$/', $form['code'])) {
            $errors[] = 'The code can use letters, numbers and dashes (up to 20).';
        }
        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            $errors[] = 'Enter a subject name (up to 100 characters).';
        }
        if (!preg_match('/^-?\d{1,4}$/', $form['sort_order'])) {
            $errors[] = 'The order must be a whole number.';
        }
        // Class record weights: all blank = DepEd default for the type; otherwise all three, adding up to 100
        $weights = [null, null, null];
        $typedWeights = array_filter([$form['ww'], $form['pt'], $form['qa']], static fn ($v) => $v !== '');
        if ($typedWeights) {
            $numeric = count($typedWeights) === 3 && count(array_filter($typedWeights, static fn ($v) => is_numeric($v) && (float) $v >= 0 && (float) $v <= 100)) === 3;
            if (!$numeric || abs(array_sum(array_map('floatval', $typedWeights)) - 100) > 0.001) {
                $errors[] = 'The Written Works, Performance Tasks and Quarterly Assessment weights must all be filled in and add up to 100 — or leave all three blank for the DepEd default.';
            } else {
                $weights = array_map(static fn ($v) => round((float) $v, 2), [$form['ww'], $form['pt'], $form['qa']]);
            }
        }
        if (!$errors) {
            try {
                if ($form['id']) {
                    db_exec('UPDATE subjects SET code = ?, name = ?, type = ?, sort_order = ?, ww_weight = ?, pt_weight = ?, qa_weight = ? WHERE id = ? AND workspace_id = ?', [$form['code'], $form['name'], $form['type'], (int) $form['sort_order'], ...$weights, $form['id'], ws()]);
                    flash('success', "{$form['name']} updated.");
                } else {
                    db_exec('INSERT INTO subjects (workspace_id, code, name, type, sort_order, ww_weight, pt_weight, qa_weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [ws(), $form['code'], $form['name'], $form['type'], (int) $form['sort_order'], ...$weights]);
                    flash('success', "{$form['name']} added.");
                }
                redirect('admin/subjects.php');
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                $errors[] = "Another subject already uses the code {$form['code']}.";
            }
        }
        $editing = $form['id'] ? $form : null;
    } elseif (post('action') === 'delete') {
        try {
            db_exec('DELETE FROM subjects WHERE id = ? AND workspace_id = ?', [post_int('id'), ws()]);
            flash('success', 'Subject deleted.');
        } catch (PDOException $e) {
            if (!is_foreign_key_error($e)) {
                throw $e;
            }
            flash('danger', 'This subject is assigned to sections, so it cannot be deleted. Remove it from those sections first.');
        }
        redirect('admin/subjects.php');
    }
}

$subjects = db_all(
    'SELECT s.*, (SELECT COUNT(*) FROM class_assignments ca WHERE ca.subject_id = s.id) AS classes
       FROM subjects s WHERE s.workspace_id = ? ORDER BY s.type = \'core\' DESC, s.sort_order, s.name',
    [ws()]
);

$title = 'Subjects';
require APP_ROOT . '/templates/header.php';
?>
<h1 class="h3">Subjects</h1>
<p class="text-secondary">
    Report cards list core subjects first, then applied and specialized subjects, each in the order shown here.
    Which semester a subject is taught in is set per section (Sections → Manage).
</p>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="table-responsive">
            <table class="table bg-white shadow-sm align-middle">
                <thead class="table-light"><tr><th scope="col">Order</th><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Type</th><th scope="col" class="text-nowrap" title="Class record weights: Written Works / Performance Tasks / Quarterly Assessment">WW/PT/QA</th><th scope="col" class="text-center">Sections</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($subjects as $s): ?>
                    <tr>
                        <td class="text-secondary"><?= (int) $s['sort_order'] ?></td>
                        <td class="fw-semibold"><?= e($s['code']) ?></td>
                        <td><?= e($s['name']) ?></td>
                        <td class="small"><?= e(SUBJECT_TYPES[$s['type']] ?? $s['type']) ?></td>
                        <td class="small text-nowrap"><?php $w = ClassRecord::weights((int) $s['id']); ?><?= format_grade($w['ww']) ?>/<?= format_grade($w['pt']) ?>/<?= format_grade($w['qa']) ?><?= $s['ww_weight'] === null ? '' : ' <span class="badge text-bg-light border" title="Set for this subject">custom</span>' ?></td>
                        <td class="text-center"><?= (int) $s['classes'] ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/subjects.php?edit=' . $s['id'])) ?>">Edit</a>
                            <?php if (!$s['classes']): ?>
                                <form method="post" action="<?= e(url('admin/subjects.php')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this subject?')">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$subjects): ?><tr><td colspan="7" class="text-secondary">No subjects yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5"><?= $editing ? 'Edit subject' : 'Add a subject' ?></h2>
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post" action="<?= e(url('admin/subjects.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label" for="code">Code</label>
                            <input class="form-control text-uppercase" id="code" name="code" value="<?= e($form['code']) ?>" maxlength="20" placeholder="GENMATH" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label" for="sort_order">Order</label>
                            <input class="form-control" id="sort_order" name="sort_order" value="<?= e($form['sort_order']) ?>" inputmode="numeric">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="name">Name</label>
                            <input class="form-control" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="100" placeholder="General Mathematics" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="type">Type</label>
                            <select class="form-select" id="type" name="type">
                                <?php foreach (SUBJECT_TYPES as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $form['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <span class="form-label d-block">Class record weights (%)</span>
                            <div class="row g-2">
                                <div class="col-4"><input class="form-control" name="ww" value="<?= e($form['ww']) ?>" inputmode="decimal" placeholder="WW" aria-label="Written Works weight"></div>
                                <div class="col-4"><input class="form-control" name="pt" value="<?= e($form['pt']) ?>" inputmode="decimal" placeholder="PT" aria-label="Performance Tasks weight"></div>
                                <div class="col-4"><input class="form-control" name="qa" value="<?= e($form['qa']) ?>" inputmode="decimal" placeholder="QA" aria-label="Quarterly Assessment weight"></div>
                            </div>
                            <div class="form-text">
                                Written Works / Performance Tasks / Quarterly Assessment. Leave blank for the DepEd default:
                                core 25/50/25; applied and academic-track specialized 25/45/30. Use 20/60/20 for TVL, Sports, and Arts and Design specialized subjects.
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add subject' ?></button>
                        <?php if ($editing): ?><a class="btn btn-link" href="<?= e(url('admin/subjects.php')) ?>">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
