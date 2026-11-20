<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (is_post()) {
    logout_staff();
    flash('success', 'You have signed out.');
}
redirect('staff/login.php');
