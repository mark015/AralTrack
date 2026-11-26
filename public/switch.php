<?php
// A parent or learner with records at several schools (or teachers) opens another one (src/Accounts.php).
require dirname(__DIR__) . '/src/bootstrap.php';

if (!is_post()) {
    redirect('index.php');
}
$type = post('type') === 'parent' ? 'parent' : 'learner';
if ($type === 'parent') {
    require_parent();
} else {
    require_student();
}
if (!Accounts::switchTo($type, post_int('to'))) {
    flash('warning', 'That school can\'t be opened right now. It may be closed to sign-in, or you were signed out there. Sign in again to see it.');
    redirect($type === 'parent' ? 'parent/index.php' : 'learner/index.php');
}
// Where to go in the other school: a child's page, or the home page
$child = post_int('child');
if ($type === 'parent' && $child > 0) {
    redirect('parent/child.php?id=' . $child);
}
redirect($type === 'parent' ? 'parent/index.php' : 'learner/index.php');
