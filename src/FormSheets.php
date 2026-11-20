<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Excel versions of DepEd school forms SF1, SF2 and SF5 for one section. */
final class FormSheets
{
    /** SF1 — School Register */
    public static function sf1(array $section, array $roster): Spreadsheet
    {
        [$book, $sheet] = self::start('SF1 School Register', 'School Form 1 (SF1) School Register', $section);
        $headers = ['No.', 'LRN', 'Last name', 'First name', 'Middle name', 'Sex', 'Birthdate', 'Age', 'Mother tongue', 'Religion',
            'Address', "Father's name", "Mother's maiden name", 'Guardian', 'Relationship', 'Contact number', 'Remarks'];
        $row = self::tableHeader($sheet, 6, $headers);
        [$start] = array_map('intval', explode('-', $section['sy_label']));
        $counts = ['M' => 0, 'F' => 0];
        foreach ($roster as $i => $st) {
            $row++;
            $counts[$st['sex'] ?? 'M'] = ($counts[$st['sex'] ?? 'M'] ?? 0) + 1;
            $remarks = $st['status'] !== 'active' ? ucwords(str_replace('_', ' ', $st['status'])) . ($st['status_note'] ? ': ' . $st['status_note'] : '') : '';
            $values = [$i + 1, null, $st['last_name'], $st['first_name'], $st['middle_name'], $st['sex'], $st['birthdate'],
                Forms::age($st['birthdate'], "{$start}-06-01"), $st['mother_tongue'], $st['religion'], $st['address'], $st['father_name'],
                $st['mother_name'], $st['guardian_name'], $st['guardian_relationship'], $st['contact_number'], $remarks];
            $sheet->fromArray($values, null, 'A' . $row);
            $sheet->setCellValueExplicit('B' . $row, $st['lrn'], DataType::TYPE_STRING);
        }
        self::grid($sheet, 6, $row, count($headers));
        $row += 2;
        $sheet->setCellValue('A' . $row, sprintf('Male: %d    Female: %d    Total: %d', $counts['M'], $counts['F'], count($roster)));
        self::widths($sheet, [5, 15, 16, 16, 14, 5, 11, 5, 12, 12, 30, 22, 22, 22, 12, 14, 18]);
        return $book;
    }

    /**
     * SF2 — Daily Attendance Report of Learners, one month.
     * Blank = present, x = absent, T = tardy (late), E = excused.
     */
    public static function sf2(array $section, string $month, array $dates, array $status, array $roster): Spreadsheet
    {
        [$book, $sheet] = self::start('SF2 ' . date('M Y', strtotime($month . '-01')), 'School Form 2 (SF2) Daily Attendance Report of Learners', $section,
            'Month: ' . date('F Y', strtotime($month . '-01')));
        $headers = ['No.', 'Learner'];
        foreach ($dates as $d) {
            $headers[] = date('j', strtotime($d)) . "\n" . substr(date('D', strtotime($d)), 0, 2);
        }
        array_push($headers, 'Absent', 'Tardy');
        $row = self::tableHeader($sheet, 6, $headers);
        $sheet->getRowDimension(6)->setRowHeight(30);
        $dailyPresent = array_fill_keys($dates, 0);
        $currentSex = null;
        $n = 0;
        foreach ($roster as $st) {
            if (($st['sex'] ?? '') !== $currentSex) {
                $currentSex = $st['sex'] ?? '';
                $row++;
                $sheet->setCellValue('B' . $row, $currentSex === 'F' ? 'FEMALE' : 'MALE');
                $sheet->getStyle('B' . $row)->getFont()->setBold(true);
            }
            $row++;
            $n++;
            $sheet->setCellValue('A' . $row, $n);
            $sheet->setCellValue('B' . $row, student_name($st));
            $absent = $tardy = 0;
            foreach ($dates as $j => $d) {
                $s = $status[(int) $st['id']][$d] ?? null;
                $mark = match ($s) { 'absent' => 'x', 'late' => 'T', 'excused' => 'E', default => '' };
                if ($s === 'absent') {
                    $absent++;
                } elseif ($s === 'late') {
                    $tardy++;
                }
                if ($s === 'present' || $s === 'late') {
                    $dailyPresent[$d]++;
                }
                if ($mark !== '') {
                    $cell = Coordinate::stringFromColumnIndex(3 + $j) . $row;
                    $sheet->setCellValue($cell, $mark);
                    $color = ['x' => 'F8D7DA', 'T' => 'FFF3CD', 'E' => 'CFF4FC'][$mark];
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
                }
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(3 + count($dates)) . $row, $absent);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(4 + count($dates)) . $row, $tardy);
        }
        $row++;
        $sheet->setCellValue('B' . $row, 'Present (daily total)');
        $sheet->getStyle('B' . $row)->getFont()->setBold(true);
        foreach ($dates as $j => $d) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(3 + $j) . $row, $dailyPresent[$d]);
        }
        self::grid($sheet, 6, $row, count($headers));
        $sheet->getStyle('C7:' . Coordinate::stringFromColumnIndex(count($headers)) . $row)->getAlignment()->setHorizontal('center');
        $sheet->setCellValue('A' . ($row + 2), 'Blank = present · x = absent · T = tardy · E = excused. A day is a school day when at least one class met; a learner is present when present or late in at least one class that day.');
        self::widths($sheet, array_merge([5, 30], array_fill(0, count($dates), 4.5), [8, 7]));
        return $book;
    }

    /** SF5 — Report on Promotion and Learning Progress & Achievement, one semester. */
    public static function sf5(array $section, int $semester, array $rows): Spreadsheet
    {
        [$book, $sheet] = self::start('SF5 ' . ($semester === 1 ? '1st' : '2nd') . ' Sem', 'School Form 5 (SF5) Report on Promotion and Learning Progress & Achievement', $section, semester_label($semester));
        $headers = ['No.', 'LRN', 'Learner', 'Sex', 'General average', 'Back subjects (not passed)', 'Action taken', 'Award'];
        $row = self::tableHeader($sheet, 6, $headers);
        $summary = ['Completed' => 0, 'Incomplete' => 0, 'No grades yet' => 0];
        foreach ($rows as $i => $r) {
            $row++;
            $action = $r['result']['average'] === null ? 'No grades yet' : ($r['result']['complete'] ? 'Completed' : 'Incomplete');
            $summary[$action]++;
            $sheet->fromArray([$i + 1, null, student_name($r['student']), $r['student']['sex'], $r['result']['average'],
                implode(', ', $r['result']['failed']), $action, Forms::honor($r['result']['average'], $r['result']['complete'])], null, 'A' . $row);
            $sheet->setCellValueExplicit('B' . $row, $r['student']['lrn'], DataType::TYPE_STRING);
        }
        self::grid($sheet, 6, $row, count($headers));
        $row += 2;
        foreach ($summary as $label => $count) {
            $sheet->setCellValue('A' . $row, "{$label}: {$count}");
            $row++;
        }
        $sheet->setCellValue('A' . ($row + 1), 'Prepared by: ' . ($section['adviser'] ?? '') . ' (Adviser)            Certified correct: ' . setting('principal_name') . ' (School Head)');
        self::widths($sheet, [5, 15, 32, 5, 10, 40, 14, 20]);
        return $book;
    }

    /** @return array{0: Spreadsheet, 1: Worksheet} */
    private static function start(string $sheetTitle, string $formTitle, array $section, string $extra = ''): array
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        $sheet->setCellValue('A1', 'Republic of the Philippines · Department of Education');
        $sheet->setCellValue('A2', $formTitle);
        $sheet->setCellValue('A3', sprintf('School: %s    School ID: %s    Division: %s    Region: %s',
            setting('school_name'), setting('school_id'), setting('school_division'), setting('school_region')));
        $sheet->setCellValue('A4', 'School year: ' . $section['sy_label'] . '    Grade and section: ' . section_label($section)
            . ($section['adviser'] ?? '' ? '    Adviser: ' . $section['adviser'] : '') . ($extra !== '' ? '    ' . $extra : ''));
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13);
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        return [$book, $sheet];
    }

    private static function tableHeader(Worksheet $sheet, int $row, array $headers): int
    {
        $sheet->fromArray($headers, null, 'A' . $row);
        $range = 'A' . $row . ':' . Coordinate::stringFromColumnIndex(count($headers)) . $row;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDE4F0');
        $sheet->freezePane('A' . ($row + 1));
        return $row;
    }

    private static function grid(Worksheet $sheet, int $from, int $to, int $columns): void
    {
        $sheet->getStyle('A' . $from . ':' . Coordinate::stringFromColumnIndex($columns) . max($from, $to))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B8C0D0');
    }

    private static function widths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $i => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }
    }
}
