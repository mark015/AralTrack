<?php
// Backups (platform owner only): download the database (.sql) or the uploaded files (.zip), keep copies on the
// server, and restore the database from a backup. A backup holds every school's data, so schools can't open
// this page. Restoring asks for the owner's password and saves a copy first.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$owner = require_owner();
$error = null;

if (is_post()) {
    $action = post('action');
    set_time_limit(0);

    if ($action === 'download') {
        platform_log('backup', 'Downloaded a database backup');
        $name = 'portal-backup-' . date('Ymd-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'wb');
        Backup::write($out);
        fclose($out);
        exit;
    }
    if ($action === 'files') {
        Backup::sendFilesZip();
    }
    if ($action === 'save') {
        $name = Backup::saveCopy('manual');
        platform_log('backup', "Saved a backup on the server ({$name})");
        flash('success', "Backup saved on the server as {$name}.");
        redirect('platform/backup.php');
    }
    if ($action === 'get' || $action === 'delete') {
        $path = Backup::copyPath(post('name'));
        if (!$path) {
            flash('danger', 'That backup does not exist.');
            redirect('platform/backup.php');
        }
        if ($action === 'get') {
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        unlink($path);
        platform_log('backup', 'Deleted the backup copy ' . basename($path));
        flash('success', basename($path) . ' was deleted.');
        redirect('platform/backup.php');
    }
    if ($action === 'restore') {
        $user = db_one('SELECT password_hash FROM platform_owners WHERE id = ?', [$owner['id']]);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $fromServer = post('name') !== '' ? Backup::copyPath(post('name')) : null;
        $upload = $_FILES['file'] ?? null;
        if (post('confirm') !== 'RESTORE') {
            $error = 'Type RESTORE in capital letters to confirm.';
        } elseif (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Your password is not correct.';
        } elseif (!$fromServer && (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']))) {
            $error = 'Choose a backup file (.sql) to restore, or pick one of the copies saved on the server.';
        } elseif (!$fromServer && strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) !== 'sql') {
            $error = 'The backup must be a .sql file made by this portal.';
        } else {
            $source = $fromServer ?? $upload['tmp_name'];
            $check = fopen($source, 'rb');
            $first = $check ? (string) fgets($check) : '';
            if ($check) {
                fclose($check);
            }
            if (!str_starts_with($first, Backup::HEADER)) {
                $error = 'This is not a backup made by the portal (Platform console → Backups). Nothing was changed.';
            } else {
                $safety = Backup::saveCopy('before-restore');
                try {
                    $count = Backup::restore($source);
                    platform_log('backup', 'Restored the database from ' . ($fromServer ? basename($fromServer) : 'an uploaded file') . " (safety copy: {$safety})");
                    db_exec('DELETE FROM login_attempts');
                    flash('success', "Database restored ({$count} statements). A copy of the database from just before the restore was saved as {$safety}. If the backup came from an older version, run php database/migrate.php.");
                } catch (UserFacingException $e) {
                    flash('danger', $e->getMessage());
                }
                redirect('platform/backup.php');
            }
        }
    }
}

$copies = Backup::copies();
$stats = db_one(
    'SELECT COUNT(*) AS tables, SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()'
);
$fileCount = 0;
$fileBytes = 0;
foreach (['excuses', 'materials', 'photos'] as $folder) {
    foreach (glob(APP_ROOT . '/storage/' . $folder . '/*') ?: [] as $f) {
        if (is_file($f)) {
            $fileCount++;
            $fileBytes += filesize($f);
        }
    }
}
$size = static fn (int|float $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, $bytes / 1024)) . ' KB';
$lastCopy = $copies[0]['time'] ?? null;

$title = 'Backups';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Backup</h1>
        <p class="sub">Keep a copy of the portal's data somewhere safe (a USB drive or cloud storage), at least once a week and before every year-end rollover.</p>
    </div>
</div>
<?php if ($lastCopy === null || $lastCopy < strtotime('-7 days')): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill"></i><div><?= $lastCopy === null ? 'No backup has been saved on the server yet.' : 'The last backup on the server is from ' . e(date('M j, Y', $lastCopy)) . '.' ?> Download one now.</div></div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex gap-3 align-items-start">
                    <div class="icon-bubble"><i class="bi bi-database-down"></i></div>
                    <div class="flex-grow-1">
                        <h2 class="h6 mb-1">Database</h2>
                        <p class="small text-secondary">Learners, grades, attendance, accounts, messages and settings · <?= (int) $stats['tables'] ?> tables, about <?= e($size((int) $stats['bytes'])) ?>.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" action="<?= e(url('platform/backup.php')) ?>" class="m-0">
                                <?= csrf_field() ?><input type="hidden" name="action" value="download">
                                <button class="btn btn-primary"><i class="bi bi-download me-1"></i>Download (.sql)</button>
                            </form>
                            <form method="post" action="<?= e(url('platform/backup.php')) ?>" class="m-0">
                                <?= csrf_field() ?><input type="hidden" name="action" value="save">
                                <button class="btn btn-outline-primary"><i class="bi bi-hdd me-1"></i>Save a copy on the server</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex gap-3 align-items-start">
                    <div class="icon-bubble teal"><i class="bi bi-file-earmark-zip"></i></div>
                    <div class="flex-grow-1">
                        <h2 class="h6 mb-1">Uploaded files</h2>
                        <p class="small text-secondary">Excuse letter attachments, class materials and learner photos · <?= $fileCount ?> files, <?= e($size($fileBytes)) ?>.</p>
                        <form method="post" action="<?= e(url('platform/backup.php')) ?>" class="m-0">
                            <?= csrf_field() ?><input type="hidden" name="action" value="files">
                            <button class="btn btn-outline-primary" <?= $fileCount ? '' : 'disabled' ?>><i class="bi bi-download me-1"></i>Download (.zip)</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<h2 class="h5">Copies on the server</h2>
<p class="small text-secondary">Saved in <code>storage/backups</code>. A copy is also made automatically before every restore. These are lost if the computer breaks, so download them too.</p>
<?php if (!$copies): ?>
    <p class="text-secondary">None yet.</p>
<?php else: ?>
    <div class="table-responsive mb-4">
        <table class="table align-middle shadow-sm">
            <thead class="table-light"><tr><th>File</th><th>Saved</th><th>Size</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($copies as $c): ?>
                <tr>
                    <td class="small"><code><?= e($c['name']) ?></code></td>
                    <td class="small"><?= e(when_label(date('Y-m-d H:i:s', $c['time']))) ?></td>
                    <td class="small"><?= e($size($c['size'])) ?></td>
                    <td class="text-end text-nowrap">
                        <form method="post" action="<?= e(url('platform/backup.php')) ?>" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="get"><input type="hidden" name="name" value="<?= e($c['name']) ?>">
                            <button class="btn btn-sm btn-outline-primary" aria-label="Download <?= e($c['name']) ?>"><i class="bi bi-download"></i></button>
                        </form>
                        <form method="post" action="<?= e(url('platform/backup.php')) ?>" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($c['name']) ?>">
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this backup copy?')" aria-label="Delete <?= e($c['name']) ?>"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="card border-danger-subtle" id="restore">
    <div class="card-body">
        <h2 class="h6 text-danger"><i class="bi bi-arrow-counterclockwise me-2"></i>Restore the database</h2>
        <p class="small mb-3">
            <strong>This replaces everything in the database</strong> with the backup — grades, attendance and accounts entered since then are lost.
            A copy of the current database is saved on the server first. Uploaded files are not changed.
        </p>
        <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e($error) ?></div></div><?php endif; ?>
        <form method="post" action="<?= e(url('platform/backup.php')) ?>#restore" enctype="multipart/form-data" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore">
            <div class="col-md-6">
                <label class="form-label small" for="r-file">Backup file (.sql)</label>
                <input class="form-control" type="file" id="r-file" name="file" accept=".sql">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="r-name">…or a copy on the server</label>
                <select class="form-select" id="r-name" name="name">
                    <option value="">—</option>
                    <?php foreach ($copies as $c): ?><option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="r-pass">Your password</label>
                <input class="form-control" type="password" id="r-pass" name="password" autocomplete="current-password" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="r-confirm">Type <strong>RESTORE</strong> to confirm</label>
                <input class="form-control" id="r-confirm" name="confirm" autocomplete="off" required pattern="RESTORE">
            </div>
            <div class="col-12">
                <button class="btn btn-danger"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore database</button>
            </div>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
