<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Builds the Excel files that teachers and the registrar download, fill in and upload back. */
final class Templates
{
    private const HEADER_ROW = 5;

    /**
     * A grade sheet for one class and quarter, pre-filled with every enrolled learner.
     * LRN and name cells are locked, so only the Grade column can be typed in.
     */
    public static function gradeSheet(array $class, int $quarter, array $roster, array $existing): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Quarter ' . $quarter);

        $sheet->setCellValue('A1', $class['subject_name'] . ' — ' . section_label($class));
        $sheet->setCellValue('A2', 'Quarter ' . $quarter . '  ·  ' . semester_label((int) $class['semester']) . '  ·  S.Y. ' . $class['sy_label'] . '  ·  Teacher: ' . $class['teacher_name']);
        $sheet->setCellValue('A3', 'Type grades (' . config('grade_min') . '–' . config('grade_max') . ') in the Grade column only. Leave a cell blank if there is no grade yet.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A3')->getFont()->setItalic(true);

        $h = self::HEADER_ROW;
        $sheet->fromArray(['No.', 'LRN', 'Learner Name', 'Grade'], null, "A{$h}");
        $sheet->getStyle("A{$h}:D{$h}")->getFont()->setBold(true);
        $sheet->getStyle("A{$h}:D{$h}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDE4F0');

        $row = $h;
        foreach ($roster as $i => $student) {
            $row++;
            $sheet->setCellValue("A{$row}", $i + 1);
            // Explicit text so Excel never turns the LRN into 1.23457E+11
            $sheet->setCellValueExplicit("B{$row}", $student['lrn'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", student_name($student));
            $grade = $existing[(int) $student['id']] ?? null;
            if ($grade !== null) {
                $sheet->setCellValue("D{$row}", $grade);
            }
        }
        $first = $h + 1;
        $last = max($row, $first);

        $sheet->getStyle("B{$first}:B{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle("D{$first}:D{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF9DB');

        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_DECIMAL);
        $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
        $validation->setFormula1((string) config('grade_min'));
        $validation->setFormula2((string) config('grade_max'));
        $validation->setAllowBlank(true);
        $validation->setShowErrorMessage(true);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setErrorTitle('Grade out of range');
        $validation->setError('Enter a grade from ' . config('grade_min') . ' to ' . config('grade_max') . '.');
        $sheet->setDataValidation("D{$first}:D{$last}", $validation);

        // Lock everything except the Grade cells (no password; this only prevents accidents).
        $sheet->getProtection()->setSheet(true);
        $sheet->getStyle("D{$first}:D{$last}")->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(17);
        $sheet->getColumnDimension('C')->setWidth(38);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->freezePane('A' . $first);

        return $book;
    }

    /** Blank learner list for the registrar's bulk import, with an Instructions sheet. */
    public static function studentSheet(): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Learners');
        $headers = ['LRN', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Birthdate', 'Grade Level', 'Section'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDE4F0');
        $sheet->getStyle('A2:A2000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('F2:F2000')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        foreach (['A' => 17, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 6, 'F' => 13, 'G' => 12, 'H' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A2');

        $help = $book->createSheet();
        $help->setTitle('Instructions');
        $lines = [
            'How to fill in the Learners sheet',
            '',
            'LRN — 12 digits. The column is already formatted as Text; keep it that way.',
            'Last Name, First Name — required. Middle Name — optional.',
            'Sex — M or F (optional).',
            'Birthdate — required. Type it as YYYY-MM-DD, e.g. 2013-03-14.',
            'Grade Level and Section — optional. When filled in, the learner is enrolled in that section for the active school year.',
            '   The section must already exist (Admin → Sections). Grade Level is 11 or 12.',
            '',
            'If an LRN is already in the system, that learner\'s name, sex and birthdate are updated from this file.',
            'If any row has a problem, nothing is imported — fix the rows listed on screen and upload again.',
        ];
        foreach ($lines as $i => $line) {
            $help->setCellValue('A' . ($i + 1), $line);
        }
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $help->getColumnDimension('A')->setWidth(110);

        $book->setActiveSheetIndex(0);
        return $book;
    }

    /**
     * One month of attendance for a class: learners × class meetings, like DepEd SF2.
     * @param list<array> $sessions class_sessions rows, oldest first
     * @param array<int, array<int, string>> $marks session id => [student id => status]
     */
    public static function attendanceSheet(array $class, string $month, array $sessions, array $roster, array $marks): Spreadsheet
    {
        $letters = ['present' => 'P', 'late' => 'L', 'absent' => 'A', 'excused' => 'E'];
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(date('M Y', strtotime($month . '-01')));

        $sheet->setCellValue('A1', 'Attendance — ' . $class['subject_name'] . ' — ' . section_label($class));
        $sheet->setCellValue('A2', date('F Y', strtotime($month . '-01')) . '  ·  S.Y. ' . $class['sy_label'] . '  ·  Teacher: ' . $class['teacher_name']);
        $sheet->setCellValue('A3', 'P = Present, L = Late, A = Absent, E = Excused');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headerRow = 5;
        $headers = ['No.', 'LRN', 'Learner'];
        foreach ($sessions as $s) {
            $headers[] = date('M j', strtotime($s['session_date'])) . "\n" . format_time($s['starts_at']);
        }
        array_push($headers, 'P', 'L', 'A', 'E');
        $sheet->fromArray($headers, null, 'A' . $headerRow);
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $header = $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}");
        $header->getFont()->setBold(true);
        $header->getAlignment()->setWrapText(true)->setHorizontal('center');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDE4F0');

        $row = $headerRow;
        foreach ($roster as $i => $student) {
            $row++;
            $sid = (int) $student['id'];
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValueExplicit("B{$row}", $student['lrn'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", student_name($student));
            $count = array_fill_keys(array_keys($letters), 0);
            foreach ($sessions as $j => $s) {
                $status = $marks[(int) $s['id']][$sid] ?? null;
                if ($status === null) {
                    continue;
                }
                $count[$status]++;
                $cell = Coordinate::stringFromColumnIndex(4 + $j) . $row;
                $sheet->setCellValue($cell, $letters[$status]);
                $color = ['absent' => 'F8D7DA', 'late' => 'FFF3CD', 'excused' => 'CFF4FC'][$status] ?? null;
                if ($color) {
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
                }
            }
            foreach (array_values($count) as $k => $n) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(4 + count($sessions) + $k) . $row, $n);
            }
        }

        $sheet->getStyle('D' . ($headerRow + 1) . ":{$lastCol}" . max($row, $headerRow + 1))->getAlignment()->setHorizontal('center');
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(32);
        for ($c = 4; $c <= count($headers); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(9);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(32);
        $sheet->freezePane('D' . ($headerRow + 1));
        return $book;
    }

    public static function download(Spreadsheet $book, string $filename): never
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        (new Xlsx($book))->save('php://output');
        exit;
    }
}
