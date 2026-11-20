<?php
/**
 * Live status counts on the attendance page (updated by assets/attendance.js).
 * @var array $counts from Attendance::counts()
 */
?>
<div class="d-flex flex-wrap gap-2 fs-6">
    <span class="badge text-bg-success">Present <span data-count="present"><?= (int) $counts['present'] ?></span></span>
    <span class="badge text-bg-warning">Late <span data-count="late"><?= (int) $counts['late'] ?></span></span>
    <span class="badge text-bg-danger">Absent <span data-count="absent"><?= (int) $counts['absent'] ?></span></span>
    <span class="badge text-bg-info">Excused <span data-count="excused"><?= (int) $counts['excused'] ?></span></span>
    <span class="badge text-bg-light border">Not yet <span data-count="pending"><?= (int) $counts['pending'] ?></span></span>
    <span class="small text-secondary align-self-center">of <span data-count="total"><?= (int) $counts['total'] ?></span> learners</span>
</div>
