<?php
require dirname(__DIR__) . '/src/bootstrap.php';

if (is_post()) {
    logout_student();
    flash('success', 'You have signed out.');
}
redirect('index.php');
