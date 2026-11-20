<?php
// Privacy notice (Data Privacy Act of 2012, RA 10173).
require dirname(__DIR__) . '/src/bootstrap.php';

$school = setting('school_name');
$contact = setting('dpo_contact') ?: 'the school\'s registrar or principal';
$title = 'Privacy notice';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-4 p-md-5">
                <div class="icon-bubble mb-3"><i class="bi bi-shield-lock"></i></div>
                <h1 class="h3">Privacy notice</h1>
                <p class="text-secondary"><?= e($school) ?> · Student Grade Portal</p>

                <p><?= e($school) ?> follows the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>.
                    This notice explains what this portal keeps about learners and their families, and why.</p>

                <h2 class="h5 mt-4">What we keep</h2>
                <ul>
                    <li><strong>Learners:</strong> LRN, name, sex, birthdate, section, grades, class attendance (including arrival times), excuse letters, and the details the registrar enters on the learner record.</li>
                    <li><strong>Parents and guardians:</strong> name, mobile number or email used to sign in, which learners are linked to the account, and messages sent through the portal.</li>
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

                <h2 class="h5 mt-4">How it is protected</h2>
                <p>Passwords are stored encrypted, sign-in is locked after repeated failed tries, every page checks who is allowed to see it, and uploaded files are kept outside the public website.</p>

                <h2 class="h5 mt-4">How long</h2>
                <p>Records are kept as long as DepEd requires school records to be kept. Parent accounts can be removed on request.</p>

                <h2 class="h5 mt-4">Your rights</h2>
                <p>You may ask to see, correct, or remove your information, and you may withdraw your consent for your parent account.
                    Contact <?= e($contact) ?>. You may also file a complaint with the National Privacy Commission.</p>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
