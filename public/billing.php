<?php
// Billing for a school or a teacher on their own: the plan, paying online (PayMongo: GCash, Maya, card, QR Ph)
// or by bank transfer, and past payments. Only the Super Admin and admins pay; other staff see the status.
require dirname(__DIR__) . '/src/bootstrap.php';

$staff = require_staff();
$workspace = Workspace::row();
$canPay = is_admin($staff) && empty($_SESSION['acting_owner']);
$here = 'billing.php';

// Back from PayMongo: confirm the payment with PayMongo (the webhook may not have arrived yet)
if ($returned = get_int('return')) {
    $payment = db_one("SELECT * FROM payments WHERE id = ? AND workspace_id = ? AND provider = 'paymongo'", [$returned, ws()]);
    if ($payment && $payment['status'] === 'pending' && $payment['provider_ref']) {
        try {
            $method = PayMongo::paidMethod((string) $payment['provider_ref']);
            if ($method !== null) {
                Billing::markPaid((int) $payment['id'], $method);
            }
        } catch (RuntimeException $e) {
            error_log('PayMongo check failed: ' . $e->getMessage());
        }
        $payment = db_one('SELECT * FROM payments WHERE id = ?', [$payment['id']]);
    }
    if ($payment && $payment['status'] === 'paid') {
        flash('success', 'Thank you! Your payment of ' . Billing::pesos((int) $payment['amount']) . ' was received.');
    } elseif ($payment) {
        flash('info', 'Your payment is being confirmed. This page will show it within a few minutes; if it doesn\'t, contact us.');
    }
    redirect($here);
}
if (get_int('cancelled')) {
    db_exec("UPDATE payments SET status = 'cancelled' WHERE id = ? AND workspace_id = ? AND status = 'pending'", [get_int('cancelled'), ws()]);
    flash('info', 'The payment was cancelled. Nothing was charged.');
    redirect($here);
}

if (is_post()) {
    $cycle = post('cycle') === 'yearly' ? 'yearly' : 'monthly';
    if (!$canPay) {
        flash('danger', 'Only the Super Admin or an admin can pay for the school.');
        redirect($here);
    }
    $quote = Billing::quote($workspace, $cycle);
    if (post('action') === 'test' && config('debug') && !PayMongo::ready()) {
        // On a test computer without PayMongo keys: pretend it was paid, to try the rest
        $payment = Billing::createPayment($workspace, $quote, 'test', $staff['full_name']);
        Billing::markPaid((int) $payment['id'], 'test');
        flash('success', 'Test payment recorded (only on a test computer with debug on).');
        redirect($here);
    }
    if (post('action') === 'pay' && PayMongo::ready()) {
        $payment = Billing::createPayment($workspace, $quote, 'paymongo', $staff['full_name']);
        try {
            $email = (string) (db_value('SELECT email FROM users WHERE id = ?', [$staff['id']]) ?? '');
            [$checkoutId, $checkoutUrl] = PayMongo::createCheckout($payment, $email);
            db_exec('UPDATE payments SET provider_ref = ? WHERE id = ?', [$checkoutId, $payment['id']]);
            Access::log('billing', 'Opened a payment of ' . Billing::pesos((int) $payment['amount']) . " ({$payment['description']})", null, null);
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        } catch (RuntimeException $e) {
            error_log('PayMongo checkout failed: ' . $e->getMessage());
            db_exec("UPDATE payments SET status = 'cancelled', note = 'Could not open the checkout' WHERE id = ?", [$payment['id']]);
            flash('danger', 'The payment page could not be opened just now. Please try again in a few minutes, or pay by bank transfer.');
            redirect($here);
        }
    }
    redirect($here);
}

$state = Billing::state($workspace);
$solo = $workspace['type'] === 'teacher';
$monthly = Billing::quote($workspace, 'monthly');
$yearly = Billing::quote($workspace, 'yearly');
$payments = db_all('SELECT * FROM payments WHERE workspace_id = ? AND status <> \'cancelled\' ORDER BY id DESC LIMIT 30', [ws()]);
$bank = trim(Billing::setting('bank_instructions'));
$tone = match ($state['code']) {
    'expired' => 'danger',
    'grace' => 'warning',
    'trial' => 'info',
    default => 'success',
};

$title = 'Billing';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Billing</h1>
        <p class="sub"><?= e(Accounts::label($workspace)) ?></p>
    </div>
</div>

<div class="card shadow-sm mb-4 border-<?= e($tone) ?>">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="icon-bubble <?= $tone === 'danger' ? 'rose' : ($tone === 'warning' ? 'gold' : ($tone === 'info' ? '' : 'green')) ?>"><i class="bi bi-credit-card" aria-hidden="true"></i></div>
        <div class="flex-grow-1">
            <div class="h5 mb-1"><?= e($state['label']) ?></div>
            <div class="text-secondary small">
                <?php
                echo e(match ($state['code']) {
                    'trial' => $state['ends'] ? 'Everything is included until ' . date('F j, Y', strtotime($state['ends'])) . " ({$state['days_left']} day" . ($state['days_left'] === 1 ? '' : 's') . ' left).' : 'Everything is included.',
                    'paid' => 'Paid until ' . date('F j, Y', strtotime($state['ends'])) . '.',
                    'grace' => 'Your paid time has ended. Renew by ' . date('F j, Y', strtotime($state['ends'])) . ' to keep making changes.',
                    'expired' => 'The portal is read-only: everyone can still sign in and see everything, but nothing can be changed. Renew to continue.',
                    'teacher_free' => 'Up to ' . (int) Billing::setting('free_teacher_classes') . ' classes. Teacher Pro has no limit.',
                    default => 'Provided free of charge.',
                });
                ?>
            </div>
        </div>
    </div>
</div>

<?php if ($state['code'] !== 'free'): ?>
<div class="row g-4 mb-4">
    <?php foreach (['monthly' => [$monthly, 'Monthly'], 'yearly' => [$yearly, 'Yearly']] as $cycle => [$quote, $name]): ?>
        <div class="col-md-6">
            <div class="card plan-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h2 class="h5 mb-0"><?= e($solo ? 'Teacher Pro · ' . $name : $name) ?></h2>
                        <?php if ($cycle === 'yearly' && $yearly['amount'] < $monthly['amount'] * 12): ?>
                            <span class="badge text-bg-success">Save <?= e(Billing::pesos($monthly['amount'] * 12 - $yearly['amount'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="display-6 fw-bold mb-1"><?= e(Billing::pesos($quote['amount'])) ?></div>
                    <p class="small text-secondary">
                        <?= $cycle === 'yearly' ? 'for 12 months' : 'for 1 month' ?>
                        <?php if ($quote['seats'] !== null): ?>
                            · <?= (int) $quote['seats'] ?> staff account<?= $quote['seats'] === 1 ? '' : 's' ?> ×
                            <?= e(Billing::pesos((int) round((float) Billing::setting($cycle === 'yearly' ? 'school_price_year' : 'school_price_month') * 100))) ?>
                        <?php endif; ?>
                    </p>
                    <?php if (!$canPay): ?>
                        <p class="small text-secondary mb-0">Your school's Super Admin or admin can pay here.</p>
                    <?php elseif (PayMongo::ready()): ?>
                        <form method="post" action="<?= e(url($here)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="pay">
                            <input type="hidden" name="cycle" value="<?= e($cycle) ?>">
                            <button class="btn <?= $cycle === 'yearly' ? 'btn-primary' : 'btn-outline-primary' ?> w-100">Pay with GCash, Maya or card</button>
                        </form>
                        <?php if (PayMongo::testMode()): ?><p class="small text-secondary mt-2 mb-0">Test mode: nothing is really charged.</p><?php endif; ?>
                    <?php elseif (config('debug')): ?>
                        <form method="post" action="<?= e(url($here)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test">
                            <input type="hidden" name="cycle" value="<?= e($cycle) ?>">
                            <button class="btn btn-outline-secondary w-100">Record a test payment</button>
                        </form>
                        <p class="small text-secondary mt-2 mb-0">Online payment isn't set up on this computer (no PayMongo key). This button only appears with debug on.</p>
                    <?php else: ?>
                        <p class="small text-secondary mb-0">Online payment opens soon. Pay by bank transfer below.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($bank !== '' && $state['code'] !== 'free'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6"><i class="bi bi-bank me-1" aria-hidden="true"></i>Pay by bank transfer</h2>
            <p class="small mb-0" style="white-space: pre-line"><?= e($bank) ?></p>
        </div>
    </div>
<?php endif; ?>

<h2 class="section-title">Payments</h2>
<div class="table-responsive">
    <table class="table bg-white shadow-sm align-middle">
        <thead class="table-light"><tr><th scope="col">Date</th><th scope="col">For</th><th scope="col" class="text-end">Amount</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td class="small text-nowrap"><?= e(date('M j, Y', strtotime($p['paid_at'] ?? $p['created_at']))) ?></td>
                <td class="small"><?= e($p['description']) ?><?= $p['method'] ? ' <span class="text-secondary">· ' . e(strtoupper($p['method']) === 'PAYMAYA' ? 'Maya' : ucfirst($p['method'])) . '</span>' : '' ?></td>
                <td class="text-end"><?= e(Billing::pesos((int) $p['amount'])) ?></td>
                <td><?= $p['status'] === 'paid' ? '<span class="badge text-bg-success">Paid</span>' : '<span class="badge text-bg-secondary">Waiting</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="4" class="text-secondary small">No payments yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
