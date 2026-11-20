<?php
/**
 * Senior High report card (like the SF9): grades per semester, then attendance per subject.
 * @var array  $card            from ReportCard::build()
 * @var string $yearFormAction  page the school-year dropdown submits to
 * @var array  $yearFormHidden  extra hidden fields for that form (name => value)
 */
$student = $card['student'];
$years = $card['years'];
$selected = $card['selected'];
$dash = '<span class="text-secondary">–</span>';
?>
<div class="card shadow-sm report-card">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div class="report-head">
                <?= brand_mark(52) ?>
                <div>
                    <p class="text-secondary small mb-1"><?= e(setting('school_name')) ?> · Report on Learning Progress and Achievement</p>
                    <h1 class="h4 mb-1"><?= e(student_name($student)) ?></h1>
                    <div class="text-secondary small">LRN <span class="lrn"><?= e($student['lrn']) ?></span></div>
                </div>
            </div>
            <?php if (count($years) > 1): ?>
                <form method="get" action="<?= e(url($yearFormAction)) ?>" class="d-flex gap-2 align-items-center d-print-none">
                    <?php foreach ($yearFormHidden as $name => $value): ?>
                        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
                    <?php endforeach; ?>
                    <label for="sy" class="form-label mb-0 small text-secondary">School year</label>
                    <select id="sy" name="sy" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                        <?php foreach ($years as $year): ?>
                            <option value="<?= (int) $year['id'] ?>" <?= $selected && $year['id'] === $selected['id'] ? 'selected' : '' ?>><?= e($year['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-sm btn-outline-primary">Show</button></noscript>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$selected): ?>
            <div class="alert alert-info mb-0">Not enrolled in a section yet. Please check with the school's registrar.</div>
        <?php else: ?>
            <dl class="row small mb-2">
                <dt class="col-sm-3 col-lg-2">School year</dt><dd class="col-sm-9 col-lg-10"><?= e($selected['label']) ?></dd>
                <dt class="col-sm-3 col-lg-2">Grade level</dt><dd class="col-sm-9 col-lg-10">Grade <?= (int) $selected['grade_level'] ?></dd>
                <?php if ($selected['strand'] !== ''): ?>
                    <dt class="col-sm-3 col-lg-2">Track / strand</dt><dd class="col-sm-9 col-lg-10"><?= e($selected['strand']) ?></dd>
                <?php endif; ?>
                <dt class="col-sm-3 col-lg-2">Section</dt><dd class="col-sm-9 col-lg-10"><?= e($selected['section_name']) ?></dd>
                <?php if ($selected['adviser']): ?>
                    <dt class="col-sm-3 col-lg-2">Adviser</dt><dd class="col-sm-9 col-lg-10"><?= e($selected['adviser']) ?></dd>
                <?php endif; ?>
            </dl>

            <?php
            $held = array_sum(array_column($card['attendance'], 'held'));
            $attended = array_sum(array_column($card['attendance'], 'present')) + array_sum(array_column($card['attendance'], 'late'));
            $attendanceRate = $held ? (int) round($attended / $held * 100) : null;
            ?>
            <div class="row g-3 mt-1 mb-2">
                <?php foreach ($card['semesters'] as $sem => $data): ?>
                    <div class="col-sm-6 col-lg-4">
                        <div class="summary-tile">
                            <div class="label"><?= e(semester_label($sem)) ?> · General average</div>
                            <div class="value mt-1"><?= $data['average'] ?? '–' ?></div>
                            <div class="note">
                                <?php if ($data['average'] !== null): ?>
                                    <i class="bi bi-award<?= $data['average'] >= 90 ? '-fill text-warning' : '' ?> me-1"></i><?= e(grade_descriptor($data['average'])) ?>
                                <?php else: ?>
                                    Shown once every subject's final grade is released
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="col-sm-6 col-lg-4">
                    <div class="summary-tile">
                        <div class="label">Class attendance</div>
                        <div class="value mt-1"><?= $attendanceRate === null ? '–' : $attendanceRate . '%' ?></div>
                        <div class="note">
                            <?= $held ? "Present or late in {$attended} of {$held} class meetings" : 'No class attendance recorded yet' ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php foreach ($card['semesters'] as $sem => $data): $quarters = semester_quarters($sem); ?>
                <h2 class="h6 mt-4 mb-2"><?= e(semester_label($sem)) ?></h2>
                <?php if (!$data['total']): ?>
                    <p class="small text-secondary">No subjects have been set up for this semester yet.</p>
                    <?php continue; ?>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-2">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Subjects</th>
                            <?php foreach ($quarters as $q): ?><th scope="col" class="q">Q<?= $q ?></th><?php endforeach; ?>
                            <th scope="col" class="q">Final grade</th>
                            <th scope="col" class="remarks">Remarks</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($data['groups'] as $group => $subjects): ?>
                            <tr><th scope="rowgroup" colspan="5" class="small text-secondary fw-semibold bg-body-tertiary"><?= e($group) ?></th></tr>
                            <?php foreach ($subjects as $s): ?>
                                <tr>
                                    <td>
                                        <?= e($s['subject']) ?>
                                        <div class="small text-secondary d-print-none"><?= e($s['teacher']) ?></div>
                                    </td>
                                    <?php foreach ($quarters as $q): ?>
                                        <td class="q"><?= isset($s['quarters'][$q]) ? e(format_grade($s['quarters'][$q])) : $dash ?></td>
                                    <?php endforeach; ?>
                                    <td class="q fw-semibold"><?= $s['final'] ?? $dash ?></td>
                                    <td class="<?= remarks($s['final']) === 'Failed' ? 'text-danger fw-semibold' : '' ?>"><?= e(remarks($s['final'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="table-light">
                            <th scope="row" colspan="3" class="text-end">General average for the semester</th>
                            <td class="q fw-bold"><?= $data['average'] ?? $dash ?></td>
                            <td><?= e($data['average'] !== null ? remarks($data['average']) : '') ?></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endforeach; ?>

            <p class="small text-secondary mt-3 mb-3">
                “–” means the grade has not been released yet. A subject's final grade is the average of its two quarters
                and appears once both are released.
            </p>

            <h2 class="h6 mt-4 mb-2">Attendance by subject</h2>
            <?php if (!array_sum(array_column($card['attendance'], 'held'))): ?>
                <p class="small text-secondary">No class attendance has been recorded yet this school year.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-2">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Subject</th><th scope="col">Semester</th>
                            <th scope="col" class="q">Classes</th><th scope="col" class="q">Present</th><th scope="col" class="q">Late</th>
                            <th scope="col" class="q">Absent</th><th scope="col" class="q">Excused</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($card['attendance'] as $a): ?>
                            <tr>
                                <td><?= e($a['subject']) ?></td>
                                <td class="small"><?= (int) $a['semester'] === 1 ? '1st' : '2nd' ?></td>
                                <td class="q"><?= (int) $a['held'] ?></td>
                                <td class="q"><?= (int) $a['present'] ?></td>
                                <td class="q"><?= (int) $a['late'] ?></td>
                                <td class="q <?= $a['absent'] >= 3 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $a['absent'] ?></td>
                                <td class="q"><?= (int) $a['excused'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="row small text-secondary mt-3">
                <div class="col-md-8">
                    <strong>Descriptors:</strong>
                    Outstanding 90–100 · Very Satisfactory 85–89 · Satisfactory 80–84 ·
                    Fairly Satisfactory 75–79 · Did Not Meet Expectations below 75
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0 d-print-none">
                    <button type="button" class="btn btn-outline-primary" onclick="window.print()">Print</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
