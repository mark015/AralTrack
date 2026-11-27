<?php
// Privacy & data (Super Admin, or a teacher on their own): the school's privacy notice and who accepted it,
// downloading all of the school's data, and asking to close the account and have the data deleted.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('superadmin');
$workspace = Workspace::row();
$me = db_one('SELECT * FROM users WHERE id = ?', [$staff['id']]);
$here = 'superadmin/privacy.php';
$error = null;
$passwordOk = static fn (): bool => password_verify(is_string($_POST['password'] ?? null) ? $_POST['password'] : '', (string) $me['password_hash']);

if (is_post()) {
    $action = post('action');
    if ($action === 'notice') {
        $values = [
            'dpo_name' => mb_substr(post('dpo_name'), 0, 150),
            'dpo_contact' => mb_substr(post('dpo_contact'), 0, 200),
            'privacy_extra' => mb_substr(trim((string) ($_POST['privacy_extra'] ?? '')), 0, 5000),
            'privacy_updated' => date('Y-m-d'),
        ];
        $again = post('ask_again') === '1';
        if ($again) {
            $values['privacy_version'] = (string) (Privacy::version() + 1);
        }
        save_settings($values);
        Access::log('privacy', 'Updated the privacy notice' . ($again ? ' and asked parents to accept it again (version ' . $values['privacy_version'] . ')' : ''), 'settings', null);
        flash('success', $again ? 'Saved. Parents will be asked to accept the updated notice the next time they sign in.' : 'Saved.');
        redirect($here);
    }
    if ($action === 'consents') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="privacy-consents-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Account type', 'Name', 'Sign-in', 'Notice version', 'Accepted at', 'IP address'], ',', '"', '');
        $rows = db_all(
            "SELECT c.account_type, COALESCE(p.full_name, u.full_name) AS name, COALESCE(p.login, u.email, u.username) AS login, c.version, c.accepted_at, c.ip
               FROM consents c
               LEFT JOIN parents p ON c.account_type = 'parent' AND p.id = c.account_id
               LEFT JOIN users u   ON c.account_type = 'staff' AND u.id = c.account_id
              WHERE c.workspace_id = ? ORDER BY c.accepted_at",
            [ws()]
        );
        foreach ($rows as $row) {
            fputcsv($out, array_values($row), ',', '"', '');
        }
        fclose($out);
        exit;
    }
    if ($action === 'export') {
        if (!$passwordOk()) {
            $error = 'Your password is not correct.';
        } else {
            Access::log('privacy', 'Downloaded all of the school\'s data', null, null);
            Privacy::sendExport(ws());
        }
    }
    if ($action === 'close' || $action === 'keep') {
        if ($action === 'close' && !$passwordOk()) {
            $error = 'Your password is not correct.';
        } else {
            db_exec('UPDATE workspaces SET deletion_requested_at = ? WHERE id = ?', [$action === 'close' ? date('Y-m-d H:i:s') : null, ws()]);
            Access::log('privacy', $action === 'close' ? 'Asked to close the account and delete the school\'s data' : 'Cancelled the request to close the account', null, null);
            platform_log('closing', $workspace['name'] . ($action === 'close' ? ' asked to close its account and delete its data' : ' cancelled its request to close its account'), ws());
            flash('success', $action === 'close'
                ? 'Your request was sent. We will contact you before anything is deleted. Download your data first if you want to keep a copy.'
                : 'Your request to close the account was cancelled.');
            redirect($here);
        }
    }
}

$stats = Privacy::consentStats();
$recent = db_all(
    "SELECT c.*, p.full_name FROM consents c JOIN parents p ON p.id = c.account_id WHERE c.workspace_id = ? AND c.account_type = 'parent' ORDER BY c.id DESC LIMIT 8",
    [ws()]
);
$title = 'Privacy & data';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Privacy &amp; data</h1>
        <p class="sub">Data Privacy Act of 2012 (RA 10173). <?= e(Accounts::label($workspace)) ?> is responsible for the personal data in this portal; <?= e(config('app_name')) ?> keeps it on the school's behalf.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">See the privacy notice</a>
</div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Privacy notice</h2>
                <p class="small text-secondary">The notice explains what the portal keeps and why. Add who parents contact about their data, and anything specific to your school.
                    Version <?= Privacy::version() ?><?= setting('privacy_updated') ? ', updated ' . e(date('F j, Y', strtotime((string) setting('privacy_updated')))) : '' ?>.</p>
                <form method="post" action="<?= e(url($here)) ?>" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="notice">
                    <div class="col-sm-6"><label class="form-label small" for="dpo_name">Data Protection Officer</label><input class="form-control" id="dpo_name" name="dpo_name" value="<?= e(setting('dpo_name')) ?>" maxlength="150" placeholder="Name and position"></div>
                    <div class="col-sm-6"><label class="form-label small" for="dpo_contact">How to contact them</label><input class="form-control" id="dpo_contact" name="dpo_contact" value="<?= e(setting('dpo_contact')) ?>" maxlength="200" placeholder="Email, mobile number or office"></div>
                    <div class="col-12">
                        <label class="form-label small" for="privacy_extra">More information for parents (optional)</label>
                        <textarea class="form-control" id="privacy_extra" name="privacy_extra" rows="4" maxlength="5000" placeholder="e.g. how long your school keeps records, or other purposes"><?= e(setting('privacy_extra')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="ask_again" name="ask_again" value="1">
                            <label class="form-check-label small" for="ask_again">This is an important change: ask every parent to accept the notice again at their next sign-in</label>
                        </div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary">Save</button></div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Who accepted it</h2>
                <p class="mb-2"><strong><?= (int) $stats['accepted'] ?></strong> of <?= (int) $stats['parents'] ?> active parent accounts accepted the current version.
                    <span class="small text-secondary">The others are asked at their next sign-in.</span></p>
                <?php if ($recent): ?>
                    <ul class="list-unstyled small mb-3">
                        <?php foreach ($recent as $c): ?>
                            <li class="py-1 border-bottom"><?= e($c['full_name']) ?> · version <?= (int) $c['version'] ?> · <?= e(when_label($c['accepted_at'])) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="consents">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1" aria-hidden="true"></i>Download all consent records (CSV)</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Download all our data</h2>
                <p class="small text-secondary">One ZIP file: every record as a spreadsheet (CSV) and every uploaded file. Passwords, parent link codes and QR card codes are left out. Keep it safe: it holds personal data.</p>
                <form method="post" action="<?= e(url($here)) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export">
                    <div class="col-sm-7"><label class="form-label small" for="export-password">Your password</label><input class="form-control" type="password" id="export-password" name="password" autocomplete="current-password" required></div>
                    <div class="col-sm-5"><button class="btn btn-primary w-100">Download</button></div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-danger-subtle">
            <div class="card-body">
                <h2 class="h5">Close our account</h2>
                <?php if ($workspace['deletion_requested_at']): ?>
                    <p class="small">You asked to close the account on <?= e(date('F j, Y', strtotime($workspace['deletion_requested_at']))) ?>. We will contact you before anything is deleted.</p>
                    <form method="post" action="<?= e(url($here)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="keep">
                        <button class="btn btn-outline-secondary btn-sm">Cancel the request</button>
                    </form>
                <?php else: ?>
                    <p class="small text-secondary">When your school stops using <?= e(config('app_name')) ?>, ask us to delete all of its data. Download it first if you need to keep it (DepEd requires schools to keep learner records).</p>
                    <form method="post" action="<?= e(url($here)) ?>" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="close">
                        <div class="col-sm-7"><label class="form-label small" for="close-password">Your password</label><input class="form-control" type="password" id="close-password" name="password" autocomplete="current-password" required></div>
                        <div class="col-sm-5"><button class="btn btn-outline-danger w-100">Ask to close</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
