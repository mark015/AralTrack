<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (is_post()) {
    logout_owner();
    flash('success', 'You have signed out of the platform console.');
}
redirect('platform/login.php');
