<?php
// Class materials and reminders: the subject teacher posts a note, handout or module (optional file).
// Learners in the section see them under Subjects; linked parents can open the files too.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = teacher_class_or_abort(get_int('id'), $staff);
Access::requireModule('materials');
$classId = (int) $class['id'];
$here = 'teacher/posts.php?id=' . $classId;
$error = null;
$form = ['title' => '', 'body' => ''];

if (is_post()) {
    $action = post('action');
    if ($action === 'post') {
        $form = ['title' => mb_substr(trim(post('title')), 0, 200), 'body' => mb_substr(trim(post('body')), 0, 5000)];
        if ($form['title'] === '') {
            $error = 'Give the post a title, e.g. "Module 3: Vectors" or "Bring a calculator on Monday".';
        } else {
            try {
                $file = Files::store($_FILES['file'] ?? null, 'material');
                db_exec(
                    'INSERT INTO class_posts (class_assignment_id, title, body, file_path, file_name, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                    [$classId, $form['title'], $form['body'] !== '' ? $form['body'] : null, $file['path'] ?? null, $file['name'] ?? null, $staff['id']]
                );
                flash('success', 'Posted. Learners in ' . section_label($class) . ' can see it now under Subjects.');
                redirect($here);
            } catch (UserFacingException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $post = db_one('SELECT * FROM class_posts WHERE id = ? AND class_assignment_id = ?', [post_int('post_id'), $classId]);
        if ($post) {
            db_exec('DELETE FROM class_posts WHERE id = ?', [$post['id']]);
            Files::delete($post['file_path']);
            flash('success', '"' . $post['title'] . '" was deleted.');
        }
        redirect($here);
    }
}

$posts = db_all(
    'SELECT cp.*, u.full_name AS author FROM class_posts cp LEFT JOIN users u ON u.id = cp.created_by
      WHERE cp.class_assignment_id = ? ORDER BY cp.id DESC',
    [$classId]
);

$title = 'Materials · ' . $class['subject_name'];
require APP_ROOT . '/templates/header.php';
$activeTab = 'posts';
$learnerCount = (int) db_value('SELECT COUNT(*) FROM enrollments WHERE section_id = ?', [$class['section_id']]);
require APP_ROOT . '/templates/class-tabs.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-megaphone me-2"></i>New post</h2>
                <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e($error) ?></div></div><?php endif; ?>
                <form method="post" action="<?= e(url($here)) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="post">
                    <label class="form-label small" for="p-title">Title</label>
                    <input class="form-control mb-2" id="p-title" name="title" maxlength="200" required value="<?= e($form['title']) ?>" placeholder="e.g. Module 3: Vectors">
                    <label class="form-label small" for="p-body">Message <span class="text-secondary">(optional)</span></label>
                    <textarea class="form-control mb-2" id="p-body" name="body" rows="4" maxlength="5000" placeholder="Instructions, reminders, due dates…"><?= e($form['body']) ?></textarea>
                    <label class="form-label small" for="p-file">File <span class="text-secondary">(optional: PDF, Word, PowerPoint, Excel, image; up to <?= e(Files::limitLabel('material')) ?>)</span></label>
                    <input class="form-control mb-3" type="file" id="p-file" name="file" accept="<?= e(Files::accept('material')) ?>">
                    <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Post to the class</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if (!$posts): ?>
            <div class="card"><div class="card-body text-center py-5">
                <div class="icon-bubble mx-auto mb-3"><i class="bi bi-folder2-open"></i></div>
                <p class="fw-semibold mb-1">Nothing posted yet.</p>
                <p class="small text-secondary mb-0">Share modules, handouts, reviewers and reminders with the class.</p>
            </div></div>
        <?php endif; ?>
        <?php foreach ($posts as $p): ?>
            <div class="card post-card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <div>
                            <h3 class="h6 mb-0"><?= e($p['title']) ?></h3>
                            <div class="small text-secondary"><?= e(when_label($p['created_at'])) ?><?= $p['author'] ? ' · ' . e($p['author']) : '' ?></div>
                        </div>
                        <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this post<?= $p['file_path'] ? ' and its file' : '' ?>?')" aria-label="Delete <?= e($p['title']) ?>"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                    <?php if ($p['body']): ?><p class="body small mt-2 mb-0"><?= e($p['body']) ?></p><?php endif; ?>
                    <?php if ($p['file_path']): ?>
                        <a class="file-chip mt-2" href="<?= e(url('file.php?kind=material&id=' . $p['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i><?= e($p['file_name']) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
