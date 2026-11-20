<?php
// Link another child (e.g. a sibling) to this parent account.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$parent = require_parent();
$form = ['lrn' => '', 'code' => '', 'relationship' => 'mother'];
$error = null;

if (is_post()) {
    $form = ['lrn' => post('lrn'), 'code' => post('code'), 'relationship' => post('relationship')];
    if (!isset(RELATIONSHIPS[$form['relationship']])) {
        $error = 'Choose your relationship to the learner.';
    } else {
        [$student, $error] = check_parent_link_code($form['lrn'], $form['code']);
        if ($student) {
            $added = db_exec(
                'INSERT IGNORE INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)',
                [$parent['id'], $student['id'], $form['relationship']]
            );
            flash($added ? 'success' : 'info', $added
                ? "{$student['first_name']} is now linked to your account."
                : "{$student['first_name']} is already linked to your account.");
            redirect('parent/index.php');
        }
    }
}

$title = 'Add a child';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon gold"><i class="bi bi-person-plus" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Add a child</h1>
                <p class="text-secondary">Enter the learner's LRN and the link code on the slip from the school.</p>
                <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('parent/link.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="lrn">Learner's LRN</label>
                        <input class="form-control lrn" id="lrn" name="lrn" value="<?= e($form['lrn']) ?>" inputmode="numeric" maxlength="16" autocomplete="off" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="code">Link code</label>
                        <input class="form-control lrn text-uppercase" id="code" name="code" value="<?= e($form['code']) ?>" maxlength="9" placeholder="ABCD-EFGH" autocomplete="off" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="relationship">You are the learner's</label>
                        <select class="form-select" id="relationship" name="relationship">
                            <?php foreach (RELATIONSHIPS as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $form['relationship'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary">Add child</button>
                    <a class="btn btn-link" href="<?= e(url('parent/index.php')) ?>">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
