<?php
// Downloads SF1, SF2 (one month) or SF5 (one semester) for a section as an Excel file.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$section = Forms::sectionOrAbort(get_int('section'), $staff);
$roster = Forms::roster((int) $section['id']);
$slug = sprintf('G%d-%s-%s', $section['grade_level'], $section['strand'] ?: 'SHS', $section['section_name']);

switch (get_str('form')) {
    case 'sf1':
        Templates::download(FormSheets::sf1($section, $roster), "SF1_{$slug}_{$section['sy_label']}.xlsx");
        // no break (download exits)
    case 'sf2':
        $month = get_str('month');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            abort(404, 'Choose a month.');
        }
        [$dates, $status] = Forms::dailyStatus((int) $section['id'], $month . '-01', date('Y-m-t', strtotime($month . '-01')));
        Templates::download(FormSheets::sf2($section, $month, $dates, $status, $roster), "SF2_{$slug}_{$month}.xlsx");
        // no break
    case 'sf5':
        $semester = get_int('semester') === 2 ? 2 : 1;
        $rows = [];
        foreach ($roster as $st) {
            $rows[] = ['student' => $st, 'result' => Forms::semesterResults((int) $st['id'], (int) $section['id'])[$semester]];
        }
        Templates::download(FormSheets::sf5($section, $semester, $rows), "SF5_{$slug}_sem{$semester}.xlsx");
}
abort(404, 'Choose SF1, SF2 or SF5.');
