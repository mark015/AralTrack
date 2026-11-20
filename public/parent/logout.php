<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (is_post()) {
    logout_parent();
    flash('success', 'You have signed out.');
}
redirect('parent/login.php');
