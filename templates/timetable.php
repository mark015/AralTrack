<?php
/**
 * Weekly timetable: one column per day.
 * @var list<array> $slots      from Schedule::for…()
 * @var bool        $showSection show the section on each slot
 * @var bool        $showTeacher show the teacher on each slot
 */
$week = Schedule::byDay($slots);
$todayNumber = (int) date('N');
?>
<div class="row g-3">
    <?php foreach ($week as $day => $daySlots): ?>
        <div class="col-md-6 col-lg">
            <div class="card h-100<?= $day === $todayNumber ? ' border-primary' : '' ?>">
                <div class="card-header fw-semibold d-flex justify-content-between">
                    <span><?= e(Schedule::DAYS[$day]) ?></span>
                    <?php if ($day === $todayNumber): ?><span class="badge text-bg-primary">Today</span><?php endif; ?>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($daySlots as $s): ?>
                        <div class="list-group-item" style="border-left: 4px solid hsl(<?= color_hue($s['subject_code']) ?> 62% 52%)">
                            <div class="small text-secondary"><?= e(Schedule::time($s['starts_at'])) ?> – <?= e(Schedule::time($s['ends_at'])) ?></div>
                            <div class="fw-semibold small"><?= e($s['subject_name']) ?></div>
                            <div class="small text-secondary">
                                <?= $showSection ? e(section_label($s)) : '' ?>
                                <?= $showTeacher ? e($s['teacher_name']) : '' ?>
                                <?= $s['room'] ? ' · Room ' . e($s['room']) : '' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$daySlots): ?><div class="list-group-item small text-secondary">No classes</div><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
