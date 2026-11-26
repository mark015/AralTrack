<?php
// Platform console → Billing: prices and trial, bank transfer instructions, online payment status, every payment,
// and recording a payment received by bank transfer (or cash).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$owner = require_owner();
$here = 'platform/billing.php';
$errors = [];

if (is_post() && post('action') === 'prices') {
    $values = [];
    foreach (['school_price_month', 'school_price_year', 'teacher_price_month', 'teacher_price_year'] as $name) {
        $value = str_replace(',', '', post($name));
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1000000) {
            $errors[] = 'Prices must be amounts in pesos, like 99 or 1490.';
            break;
        }
        $values[$name] = (string) round((float) $value, 2);
    }
    foreach (['trial_days' => [1, 365], 'grace_days' => [0, 60], 'free_teacher_classes' => [1, 100]] as $name => [$min, $max]) {
        $n = post_int($name);
        if ($n < $min || $n > $max) {
            $errors[] = "Check the number for {$name} ({$min}–{$max}).";
            break;
        }
        $values[$name] = (string) $n;
    }
    $values['bank_instructions'] = mb_substr(trim((string) ($_POST['bank_instructions'] ?? '')), 0, 2000);
    if (!$errors) {
        Billing::saveSettings($values);
        platform_log('billing', 'Changed prices and billing settings');
        flash('success', 'Saved. New prices apply to the next payments.');
        redirect($here);
    }
}

if (is_post() && post('action') === 'manual') {
    $workspace = Workspace::find(post_int('workspace_id'));
    $months = post_int('months');
    $pesos = str_replace(',', '', post('amount'));
    if (!$workspace) {
        $errors[] = 'Choose a school.';
    } elseif ($months < 1 || $months > 36) {
        $errors[] = 'Enter 1 to 36 months.';
    } elseif (!is_numeric($pesos) || (float) $pesos <= 0) {
        $errors[] = 'Enter the amount received in pesos.';
    } else {
        $quote = [
            'amount' => (int) round((float) $pesos * 100), 'months' => $months,
            'seats' => $workspace['type'] === 'teacher' ? null : Billing::seats((int) $workspace['id']),
            'description' => config('app_name') . ' for ' . $workspace['name'] . ", {$months} month" . ($months === 1 ? '' : 's') . ' (bank transfer)',
        ];
        $payment = Billing::createPayment($workspace, $quote, 'manual', $owner['full_name'], post('note'));
        Billing::markPaid((int) $payment['id'], post('method') ?: 'bank');
        flash('success', 'Payment recorded. ' . $workspace['name'] . ' is paid until ' . date('F j, Y', strtotime((string) db_value('SELECT paid_until FROM workspaces WHERE id = ?', [$workspace['id']]))) . '.');
        redirect($here);
    }
}

$payments = db_all(
    'SELECT p.*, w.name AS workspace_name, w.type AS workspace_type FROM payments p JOIN workspaces w ON w.id = p.workspace_id
      WHERE p.status <> \'cancelled\' ORDER BY p.id DESC LIMIT 100'
);
$received = (int) db_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid' AND provider <> 'test' AND paid_at >= ?", [date('Y-m-01')]);
$workspaces = db_all('SELECT id, name, type FROM workspaces ORDER BY name');
$s = static fn (string $name): string => Billing::setting($name);

$title = 'Billing';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Billing</h1>
        <p class="sub">Prices, trial and payments. Received this month: <strong><?= e(Billing::pesos($received)) ?></strong></p>
    </div>
</div>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Prices and trial</h2>
                <p class="small text-secondary">In pesos. A school pays per staff account (teachers, admins, principal, guidance); a teacher on their own pays one price.</p>
                <form method="post" action="<?= e(url($here)) ?>" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="prices">
                    <div class="col-sm-6"><label class="form-label small" for="spm">School: per staff account, per month</label><input class="form-control" id="spm" name="school_price_month" value="<?= e($s('school_price_month')) ?>" inputmode="decimal"></div>
                    <div class="col-sm-6"><label class="form-label small" for="spy">School: per staff account, per year</label><input class="form-control" id="spy" name="school_price_year" value="<?= e($s('school_price_year')) ?>" inputmode="decimal"></div>
                    <div class="col-sm-6"><label class="form-label small" for="tpm">Teacher Pro: per month</label><input class="form-control" id="tpm" name="teacher_price_month" value="<?= e($s('teacher_price_month')) ?>" inputmode="decimal"></div>
                    <div class="col-sm-6"><label class="form-label small" for="tpy">Teacher Pro: per year</label><input class="form-control" id="tpy" name="teacher_price_year" value="<?= e($s('teacher_price_year')) ?>" inputmode="decimal"></div>
                    <div class="col-sm-4"><label class="form-label small" for="trial">Free trial (days)</label><input class="form-control" type="number" id="trial" name="trial_days" min="1" max="365" value="<?= e($s('trial_days')) ?>"></div>
                    <div class="col-sm-4"><label class="form-label small" for="grace">Days to renew after it ends</label><input class="form-control" type="number" id="grace" name="grace_days" min="0" max="60" value="<?= e($s('grace_days')) ?>"></div>
                    <div class="col-sm-4"><label class="form-label small" for="free">Classes on the free teacher plan</label><input class="form-control" type="number" id="free" name="free_teacher_classes" min="1" max="100" value="<?= e($s('free_teacher_classes')) ?>"></div>
                    <div class="col-12">
                        <label class="form-label small" for="bank">Bank transfer instructions (shown on the schools' Billing page)</label>
                        <textarea class="form-control" id="bank" name="bank_instructions" rows="3" maxlength="2000" placeholder="e.g. BPI account 1234 5678 90, name: … Send the receipt to billing@… with your school's name."><?= e($s('bank_instructions')) ?></textarea>
                    </div>
                    <div class="col-12"><button class="btn btn-primary">Save</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Online payments (PayMongo)</h2>
                <?php if (!PayMongo::ready()): ?>
                    <p class="small mb-2"><span class="badge text-bg-secondary">Not set up</span></p>
                    <p class="small text-secondary mb-0">Add your PayMongo secret key and the site address (<code>app_url</code>) to <code>config/config.local.php</code> on the server. Until then schools see the bank transfer instructions only.</p>
                <?php else: ?>
                    <p class="small mb-2"><?= PayMongo::testMode() ? '<span class="badge text-bg-warning">Test mode</span> nothing is really charged' : '<span class="badge text-bg-success">Live</span> real payments' ?></p>
                    <p class="small text-secondary mb-1">In PayMongo → Developers → Webhooks, add this address with the event <code>checkout_session.payment.paid</code>, and put its secret in <code>webhook_secret</code>:</p>
                    <p class="small"><code><?= e((string) PayMongo::webhookUrl()) ?></code></p>
                    <p class="small mb-0"><?= ((array) config('paymongo'))['webhook_secret'] ?? '' ? '<span class="badge text-bg-success">Webhook secret set</span>' : '<span class="badge text-bg-warning">Webhook secret missing</span> payments are still confirmed when the payer returns to the site' ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Record a bank transfer</h2>
                <form method="post" action="<?= e(url($here)) ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="manual">
                    <div class="col-12">
                        <label class="form-label small" for="ws">School or teacher</label>
                        <select class="form-select" id="ws" name="workspace_id" required>
                            <option value="">Choose…</option>
                            <?php foreach ($workspaces as $w): ?>
                                <option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?><?= $w['type'] === 'teacher' ? ' (teacher)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4"><label class="form-label small" for="months">Months</label><input class="form-control" type="number" id="months" name="months" min="1" max="36" value="12" required></div>
                    <div class="col-4"><label class="form-label small" for="amount">Amount (₱)</label><input class="form-control" id="amount" name="amount" inputmode="decimal" required></div>
                    <div class="col-4"><label class="form-label small" for="method">Paid by</label>
                        <select class="form-select" id="method" name="method"><option value="bank">Bank</option><option value="gcash">GCash</option><option value="cash">Cash</option><option value="check">Check</option></select></div>
                    <div class="col-12"><label class="form-label small" for="note">Note (e.g. reference number)</label><input class="form-control" id="note" name="note" maxlength="255"></div>
                    <div class="col-12"><button class="btn btn-outline-primary">Record payment</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<h2 class="section-title">Payments</h2>
<div class="table-responsive">
    <table class="table bg-white shadow-sm align-middle">
        <thead class="table-light"><tr><th scope="col">Date</th><th scope="col">School</th><th scope="col">For</th><th scope="col" class="text-end">Amount</th><th scope="col">How</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td class="small text-nowrap"><?= e(date('M j, Y', strtotime($p['paid_at'] ?? $p['created_at']))) ?></td>
                <td class="small"><a href="<?= e(url('platform/workspace.php?id=' . (int) $p['workspace_id'])) ?>"><?= e($p['workspace_name']) ?></a></td>
                <td class="small"><?= e($p['description']) ?><?= $p['note'] ? '<div class="text-secondary">' . e($p['note']) . '</div>' : '' ?></td>
                <td class="text-end"><?= e(Billing::pesos((int) $p['amount'])) ?></td>
                <td class="small"><?= e(['paymongo' => 'Online', 'manual' => 'Recorded', 'test' => 'Test'][$p['provider']] ?? $p['provider']) ?><?= $p['method'] ? ' · ' . e($p['method']) : '' ?></td>
                <td><?= $p['status'] === 'paid' ? '<span class="badge text-bg-success">Paid</span>' : '<span class="badge text-bg-secondary">Waiting</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="6" class="text-secondary small">No payments yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
