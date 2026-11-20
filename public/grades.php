<?php
// Learner report card: published grades by semester, and attendance totals by subject
// (no arrival times, since anyone who knows an LRN can open this page).
require dirname(__DIR__) . '/src/bootstrap.php';

$studentId = current_student_id();
if ($studentId === null) {
    redirect('index.php');
}
$card = ReportCard::build($studentId, get_int('sy'));
if (!$card) {
    logout_student();
    redirect('index.php');
}

$title = 'My grades';
require APP_ROOT . '/templates/header.php';
$yearFormAction = 'grades.php';
$yearFormHidden = [];
require APP_ROOT . '/templates/report-card.php';
require APP_ROOT . '/templates/footer.php';
