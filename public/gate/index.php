<?php
// Gate scanner: a phone or tablet at the school entrance scans learners' ID cards as they arrive and leave.
// Parents are told at once (portal notification, phone alert, and a text when the school turned SMS on).
// Works without internet too: scans are saved on the device and sent when the connection is back.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
Gate::requireAvailable();
if (!Gate::mayScan($staff)) {
    abort(403, 'Only the gate guard, the registrar, the principal and the Super Admin can use the gate scanner.');
}

$today = date('Y-m-d');
$counts = Gate::counts($today);
$recent = Gate::day($today, null, '', 8);
$lateAfter = Gate::lateAfter();
// The service worker keeps this page for opening without internet (deleted when signing out)
header('X-Gate-Page: 1');

$title = 'Gate scanner';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Gate scanner</h1>
        <p class="sub d-none d-sm-block">
            Scan each learner's ID card as they come in and go out. Parents are told at once.
            <?= $lateAfter ? 'Arriving after ' . e(date('g:i A', strtotime("{$today} {$lateAfter}:00"))) . ' is marked late.' : '' ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('gate/log.php')) ?>"><i class="bi bi-list-check me-1"></i>Gate log</a>
    </div>
</div>

<div id="gate-page" data-api="<?= e(url('gate/api.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-staff="<?= (int) $staff['id'] ?>"
     data-date="<?= e($today) ?>" data-roster="<?= e(json_encode(Gate::roster(), JSON_UNESCAPED_UNICODE)) ?>">
    <div id="gate-offline" class="alert alert-warning d-none" role="status"></div>

    <div class="gate-modes btn-group mb-3" role="group" aria-label="What the scan records">
        <?php foreach (['auto' => ['Auto', 'arrow-left-right', 'Arriving, or leaving if they already came in'], 'in' => ['Arriving', 'box-arrow-in-right', 'Every scan is an arrival'], 'out' => ['Leaving', 'box-arrow-right', 'Every scan is leaving']] as $mode => [$label, $icon, $help]): ?>
            <input type="radio" class="btn-check" name="gate-mode" id="mode-<?= $mode ?>" value="<?= $mode ?>" <?= $mode === 'auto' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="mode-<?= $mode ?>" title="<?= e($help) ?>"><i class="bi bi-<?= $icon ?> me-1" aria-hidden="true"></i><?= e($label) ?></label>
        <?php endforeach; ?>
    </div>

    <div class="gate-grid mb-4">
        <div class="g-camera">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div id="camera" class="camera-box mb-2"></div>
                    <p id="camera-note" class="small text-secondary mb-2" role="status">Press Start camera, then hold each card in front of it.</p>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-primary" id="camera-toggle"><i class="bi bi-camera-video me-1" aria-hidden="true"></i>Start camera</button>
                        <select id="camera-select" class="form-select form-select-sm w-auto" aria-label="Camera" hidden></select>
                    </div>
                    <hr>
                    <label for="scan-input" class="form-label small mb-1">USB scanner, or type a learner's 12-digit LRN and press Enter</label>
                    <input id="scan-input" class="form-control" autocomplete="off" inputmode="numeric">
                </div>
            </div>
        </div>
        <div class="g-result">
            <div id="gate-result" class="gate-result alert alert-secondary mb-0" role="status" aria-live="polite">
                <i class="bi bi-qr-code-scan" aria-hidden="true"></i><div>Ready. Scan a learner's ID card.</div>
            </div>
        </div>
        <div class="g-stats">
            <div class="row g-2 mb-3">
                <?php foreach ([['arrived', 'Arrived', 'green'], ['late', 'Late', 'gold'], ['left', 'Left', 'teal'], ['inside', 'Inside now', ''], ['missing', 'Not in yet', 'rose']] as [$key, $label, $tone]): ?>
                    <div class="col">
                        <div class="summary-tile gate-tile <?= $tone ?>">
                            <div class="label"><?= e($label) ?></div>
                            <div class="value" data-count="<?= $key ?>"><?= $key === 'missing' ? max(0, $counts['enrolled'] - $counts['arrived']) : (int) $counts[$key] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <h2 class="h6 text-secondary text-uppercase small fw-bold">Latest scans</h2>
            <ul class="list-group shadow-sm" id="gate-recent">
                <?php foreach ($recent as $r): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span><?= e(student_name($r)) ?><span class="d-block small text-secondary"><?= $r['section_name'] !== null ? e(section_label($r)) : '' ?></span></span>
                        <span class="text-end text-nowrap"><?= gate_badge($r['direction'], (bool) $r['late']) ?><span class="d-block small text-secondary"><?= e(format_time($r['scanned_at'])) ?></span></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$recent): ?><li class="list-group-item text-secondary small" data-empty>No scans yet today.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<script src="<?= e(asset('assets/vendor/html5-qrcode.min.js')) ?>"></script>
<script src="<?= e(asset('assets/gate.js')) ?>"></script>
<?php require APP_ROOT . '/templates/footer.php'; ?>
