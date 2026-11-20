<?php
// Learner: subjects this school year with their teachers and the materials and reminders they posted.
//   learner/subjects.php           all subjects
//   learner/subjects.php?class=ID  posts for one subject
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$student = require_student();
$materialsOn = Access::can('materials');
$scoresOn = Access::can('class_record');
if (!$materialsOn && !$scoresOn) {
    Access::requireModule('materials');
}
$studentId = (int) $student['id'];
$section = current_section_of($studentId);
$semester = (int) setting('current_semester');

$classes = $section ? db_all(
    "SELECT ca.id, ca.semester, sub.code AS subject_code, sub.name AS subject_name, sub.type AS subject_type, u.full_name AS teacher_name,
            (SELECT COUNT(*) FROM class_posts cp WHERE cp.class_assignment_id = ca.id) AS posts,
            (SELECT MAX(cp.created_at) FROM class_posts cp WHERE cp.class_assignment_id = ca.id) AS last_post
       FROM class_assignments ca
       JOIN subjects sub ON sub.id = ca.subject_id
       JOIN users u      ON u.id = ca.teacher_id
      WHERE ca.section_id = ?
      ORDER BY ca.semester <> ?, ca.semester, sub.type = 'core' DESC, sub.sort_order, sub.name",
    [$section['id'], $semester]
) : [];
$byId = array_column($classes, null, 'id');

$selected = null;
$posts = [];
if ($classId = get_int('class')) {
    $selected = $byId[$classId] ?? abort(404, 'That subject is not in your class this school year.');
    $posts = $materialsOn ? db_all('SELECT * FROM class_posts WHERE class_assignment_id = ? ORDER BY id DESC', [$classId]) : [];
}

$title = $selected ? $selected['subject_name'] : 'Subjects';
require APP_ROOT . '/templates/header.php';
?>
<?php if ($selected): ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('learner/subjects.php')) ?>">Subjects</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($selected['subject_name']) ?></li>
        </ol>
    </nav>
    <div class="page-head">
        <div>
            <span class="subject-chip mb-1" style="--hue: <?= color_hue($selected['subject_code']) ?>"><?= e($selected['subject_code']) ?></span>
            <h1 class="h3"><?= e($selected['subject_name']) ?></h1>
            <p class="sub"><?= e($selected['teacher_name']) ?> · <?= e(semester_label((int) $selected['semester'])) ?></p>
        </div>
        <?php if ($scoresOn): ?><a class="btn btn-outline-primary" href="<?= e(url('learner/scores.php?class=' . $selected['id'])) ?>"><i class="bi bi-list-check me-1"></i>My scores</a><?php endif; ?>
    </div>
    <?php if (!$materialsOn): ?>
        <p class="text-secondary">Class materials are turned off by the school right now.</p>
    <?php elseif (!$posts): ?>
        <div class="card"><div class="card-body text-center py-5">
            <div class="icon-bubble mx-auto mb-3"><i class="bi bi-folder2-open"></i></div>
            <p class="fw-semibold mb-1">No materials yet.</p>
            <p class="small text-secondary mb-0">Handouts, modules and reminders from your teacher will appear here.</p>
        </div></div>
    <?php endif; ?>
    <?php foreach ($posts as $p): ?>
        <div class="card post-card mb-3">
            <div class="card-body">
                <h2 class="h6 mb-0"><?= e($p['title']) ?></h2>
                <div class="small text-secondary"><?= e(when_label($p['created_at'])) ?></div>
                <?php if ($p['body']): ?><p class="body small mt-2 mb-0"><?= e($p['body']) ?></p><?php endif; ?>
                <?php if ($p['file_path']): ?>
                    <a class="file-chip mt-2" href="<?= e(url('file.php?kind=material&id=' . $p['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i><?= e($p['file_name']) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="page-head">
        <div>
            <h1 class="h3">Subjects</h1>
            <p class="sub"><?= $section ? e(section_label($section)) . ' · S.Y. ' . e($section['sy_label']) : 'You are not enrolled this school year.' ?></p>
        </div>
    </div>
    <?php $lastSemester = null; foreach ($classes as $c):
        if ($c['semester'] !== $lastSemester):
            if ($lastSemester !== null): ?></div><?php endif;
            $lastSemester = $c['semester']; ?>
            <h2 class="h6 text-secondary text-uppercase small mt-4 mb-3"><?= e(semester_label((int) $c['semester'])) ?><?= (int) $c['semester'] === $semester ? ' · now' : '' ?></h2>
            <div class="row g-3">
        <?php endif; ?>
        <div class="col-sm-6 col-lg-4">
            <a class="card class-card h-100 text-decoration-none" href="<?= e(url('learner/subjects.php?class=' . $c['id'])) ?>" style="--hue: <?= color_hue($c['subject_code']) ?>">
                <div class="card-body">
                    <span class="subject-chip mb-2" style="--hue: <?= color_hue($c['subject_code']) ?>"><?= e($c['subject_code']) ?></span>
                    <h3 class="h6 mb-1 text-body"><?= e($c['subject_name']) ?></h3>
                    <div class="small text-secondary"><?= e($c['teacher_name']) ?></div>
                    <div class="small mt-2 <?= $c['posts'] ? 'text-primary' : 'text-secondary' ?><?= $materialsOn ? '' : ' d-none' ?>">
                        <i class="bi bi-folder2-open me-1"></i><?= (int) $c['posts'] ?> post<?= (int) $c['posts'] === 1 ? '' : 's' ?>
                        <?php if ($c['last_post']): ?> · latest <?= e(when_label($c['last_post'])) ?><?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
    <?php if ($lastSemester !== null): ?></div><?php endif; ?>
    <?php if (!$classes && $section): ?><p class="text-secondary">No subjects have been set up for your section yet.</p><?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
