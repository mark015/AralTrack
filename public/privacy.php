<?php
// Privacy notice (Data Privacy Act of 2012, RA 10173).
require dirname(__DIR__) . '/src/bootstrap.php';

// Before sign-in the school isn't known yet: each school using the system is responsible for its own learners' data.
$school = Workspace::known() ? setting('school_name') : 'Your school';
$contact = setting('dpo_contact') ?: 'the school\'s registrar or principal';
$dpo = trim((string) setting('dpo_name'));
$extra = Workspace::known() ? trim((string) setting('privacy_extra')) : '';
$title = 'Privacy notice';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-4 p-md-5">
                <div class="icon-bubble mb-3"><i class="bi bi-shield-lock"></i></div>
                <h1 class="h3">Privacy notice</h1>
                <p class="text-secondary"><?= Workspace::known() ? e($school) . ' · ' : '' ?><?= e(config('app_name')) ?></p>

                <p><?= e($school) ?> follows the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>.
                    This notice explains what this portal keeps about learners and their families, and why.</p>

                <h2 class="h5 mt-4">What we keep</h2>
                <ul>
                    <li><strong>Learners:</strong> LRN, name, sex, birthdate, section, grades, class attendance (including arrival times), the times they enter and leave through the school gate (when the school scans ID cards at the gate), excuse letters, and the details the registrar enters on the learner record.</li>
                    <li><strong>Parents and guardians:</strong> name, mobile number or email used to sign in, which learners are linked to the account, messages sent through the portal, the phones where they turned on alerts, and the text messages the school sent them.</li>
                    <li><strong>Staff:</strong> name, username, and the changes they make (for example, every grade change is recorded).</li>
                </ul>

                <h2 class="h5 mt-4">Why</h2>
                <p>To record and report learners' grades and attendance as DepEd requires, to let learners and their parents follow their progress, and to let teachers and advisers follow up with learners who need help.</p>

                <h2 class="h5 mt-4">Who can see it</h2>
                <ul>
                    <li>A learner sees their own grades and attendance totals.</li>
                    <li>A parent or guardian sees only the learners linked to their account, using the link code given by the school.</li>
                    <li>Teachers see the learners in their classes; advisers see their advisory section; the registrar, principal and guidance counselor see the school's records.</li>
                </ul>
                <p>The school does not sell or share this information with outside parties, except when required by DepEd or by law.</p>
                <p>To tell parents about attendance quickly, alerts pass through the phone's alert service (Google, Apple, Mozilla or Microsoft, encrypted so they can't read it)
                    and, when the school sends text messages, through its SMS provider, which receives the parent's mobile number and the text.
                    A parent can turn alerts off on their phone at any time (Notifications).</p>

                <h2 class="h5 mt-4">How it is protected</h2>
                <p>Passwords are stored encrypted, sign-in is locked after repeated failed tries, every page checks who is allowed to see it, and uploaded files are kept outside the public website.</p>

                <h2 class="h5 mt-4">Who keeps it</h2>
                <p><?= e($school) ?> decides what is kept and why. <?= e(config('app_name')) ?> runs this portal for the school and keeps the data on the
                    school's behalf. It does not use it for anything else, and each school's data is kept apart from every other school's.</p>

                <h2 class="h5 mt-4">How long</h2>
                <p>Records are kept as long as DepEd requires school records to be kept. A parent can delete their account at any time
                    (My data, in the menu under their name). When a school stops using the portal, its data is deleted at its request.</p>

                <h2 class="h5 mt-4">Your rights</h2>
                <p>You may ask to see, correct, or remove your information, and you may withdraw your consent for your parent account.
                    Parents can download their own data under My data.
                    Contact <?= $dpo !== '' ? 'the Data Protection Officer, ' . e($dpo) . ', at ' : '' ?><?= e($contact) ?>. You may also file a complaint with the National Privacy Commission.</p>
                <?php if ($extra !== ''): ?>
                    <h2 class="h5 mt-4">More from <?= e($school) ?></h2>
                    <p style="white-space: pre-line"><?= e($extra) ?></p>
                <?php endif; ?>
                <?php if (Workspace::known()): ?>
                    <p class="small text-secondary mt-4 mb-0">Version <?= Privacy::version() ?><?= setting('privacy_updated') ? ' · updated ' . e(date('F j, Y', strtotime((string) setting('privacy_updated')))) : '' ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
