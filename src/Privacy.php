<?php
declare(strict_types=1);

/**
 * Privacy tools for the Data Privacy Act (RA 10173).
 *  - Consent: every acceptance of a school's privacy notice is recorded with the notice's version. When a school
 *    changes its notice and asks again, parents accept the new version at their next sign-in.
 *  - A school downloads all of its data (one ZIP of CSV files plus its uploaded files). Passwords, link codes and
 *    QR card codes are left out.
 *  - The platform owner permanently deletes a school's data when it leaves (payments stay, for accounting).
 *  - A parent downloads their own data and can delete their account.
 */
final class Privacy
{
    /** Never in an export: passwords, card and link codes, session counters, codes in emails. */
    private const SECRET_COLUMNS = ['password_hash', 'qr_token', 'parent_code', 'session_version', 'token_hash', 'code_hash'];

    // ------------------------------------------------------------ consent

    /** The current version of this school's privacy notice. */
    public static function version(): int
    {
        return max(1, (int) setting('privacy_version'));
    }

    public static function record(string $type, int $accountId, int $workspaceId, ?int $version = null): void
    {
        db_exec(
            "INSERT INTO consents (workspace_id, account_type, account_id, document, version, ip) VALUES (?, ?, ?, 'privacy', ?, ?)",
            [$workspaceId, $type, $accountId, $version ?? self::version(), PHP_SAPI === 'cli' ? null : client_ip()]
        );
    }

    /** True when the parent hasn't accepted the current version of the school's notice yet. */
    public static function parentMustAccept(int $parentId): bool
    {
        $accepted = (int) (db_value("SELECT MAX(version) FROM consents WHERE account_type = 'parent' AND account_id = ? AND document = 'privacy'", [$parentId]) ?? 0);
        return $accepted < self::version();
    }

    /** @return array{parents: int, accepted: int} active parents of this school, and how many accepted the current version */
    public static function consentStats(): array
    {
        return [
            'parents' => (int) db_value('SELECT COUNT(*) FROM parents WHERE workspace_id = ? AND is_active = 1', [ws()]),
            'accepted' => (int) db_value(
                "SELECT COUNT(DISTINCT p.id) FROM parents p JOIN consents c ON c.account_type = 'parent' AND c.account_id = p.id AND c.document = 'privacy' AND c.version >= ?
                  WHERE p.workspace_id = ? AND p.is_active = 1",
                [self::version(), ws()]
            ),
        ];
    }

    // ------------------------------------------------------------ a school's data

    /**
     * Every table of a school: file name => [table, condition on "t", extra columns left out].
     * Tables without a workspace column are found through the records they belong to.
     */
    private static function tables(int $w): array
    {
        $students = "SELECT id FROM students WHERE workspace_id = {$w}";
        $classes = "SELECT id FROM class_assignments WHERE workspace_id = {$w}";
        $sessions = "SELECT id FROM class_sessions WHERE workspace_id = {$w}";
        $parents = "SELECT id FROM parents WHERE workspace_id = {$w}";
        $years = "SELECT id FROM school_years WHERE workspace_id = {$w}";
        $own = "t.workspace_id = {$w}";
        return [
            'staff'                    => ['users', $own, []],
            'learners'                 => ['students', $own, []],
            'parents'                  => ['parents', $own, []],
            'parent_links'             => ['parent_students', "t.parent_id IN ({$parents})", []],
            'school_years'             => ['school_years', $own, []],
            'grading_deadlines'        => ['grade_deadlines', "t.school_year_id IN ({$years})", []],
            'subjects'                 => ['subjects', $own, []],
            'sections'                 => ['sections', $own, []],
            'enrollments'              => ['enrollments', "t.student_id IN ({$students})", []],
            'classes'                  => ['class_assignments', $own, []],
            'class_schedules'          => ['class_schedules', "t.class_assignment_id IN ({$classes})", []],
            'grades'                   => ['grades', "t.class_assignment_id IN ({$classes})", []],
            'grade_publications'       => ['grade_publications', "t.class_assignment_id IN ({$classes})", []],
            'grade_history'            => ['grade_history', $own, []],
            'grade_change_requests'    => ['grade_change_requests', $own, []],
            'grade_uploads'            => ['upload_log', "t.class_assignment_id IN ({$classes})", []],
            'class_record_scores'      => ['assessment_scores', "t.assessment_id IN (SELECT id FROM assessments WHERE class_assignment_id IN ({$classes}))", []],
            'class_record_items'       => ['assessments', "t.class_assignment_id IN ({$classes})", []],
            'attendance_meetings'      => ['class_sessions', $own, []],
            'attendance'               => ['session_attendance', "t.session_id IN ({$sessions})", []],
            'attendance_scans'         => ['scan_log', "t.session_id IN ({$sessions})", ['code']],
            'observed_values'          => ['observed_values', "t.student_id IN ({$students})", []],
            'report_card_receipts'     => ['report_acknowledgements', "t.student_id IN ({$students})", []],
            'interventions'            => ['interventions', $own, []],
            'excuse_letters'           => ['excuse_letters', $own, []],
            'document_requests'        => ['document_requests', $own, []],
            'message_threads'          => ['message_threads', $own, []],
            'messages'                 => ['messages', "t.thread_id IN (SELECT id FROM message_threads WHERE workspace_id = {$w})", []],
            'parent_notifications'     => ['parent_notifications', "t.parent_id IN ({$parents})", []],
            'class_materials'          => ['class_posts', $own, []],
            'announcements'            => ['announcements', $own, []],
            'calendar'                 => ['calendar_events', $own, []],
            'privacy_consents'         => ['consents', $own, []],
            'settings'                 => ['settings', $own, []],
            'access_limits'            => ['access_blocks', $own, []],
            'staff_invitations'        => ['staff_invites', $own, []],
            'activity_log'             => ['activity_log', $own, []],
            'sign_ins'                 => ['login_events', $own, []],
            'payments'                 => ['payments', $own, []],
        ];
    }

    /** Uploaded files of a school: excuse letter attachments, class materials, learner photos (paths under storage/). */
    private static function files(int $w): array
    {
        return array_values(array_filter(array_merge(
            array_column(db_all('SELECT file_path AS p FROM excuse_letters WHERE workspace_id = ? AND file_path IS NOT NULL', [$w]), 'p'),
            array_column(db_all('SELECT file_path AS p FROM class_posts WHERE workspace_id = ? AND file_path IS NOT NULL', [$w]), 'p'),
            array_column(db_all('SELECT photo_path AS p FROM students WHERE workspace_id = ? AND photo_path IS NOT NULL', [$w]), 'p'),
        )));
    }

    /** Sends one ZIP with all of a school's data and files, then stops. */
    public static function sendExport(int $workspaceId): never
    {
        set_time_limit(0);
        $workspace = Workspace::find($workspaceId) ?? throw new RuntimeException('No such school.');
        $zipPath = tempnam(sys_get_temp_dir(), 'atx');
        $zip = new ZipArchive();
        if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip file.');
        }
        $temp = [];
        $counts = [];
        $reader = db();
        foreach (self::tables($workspaceId) as $name => [$table, $where, $skip]) {
            $file = tempnam(sys_get_temp_dir(), 'atc');
            $out = fopen($file, 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the names and accents right
            $statement = $reader->query("SELECT t.* FROM {$table} t WHERE {$where}");
            $columns = null;
            $rows = 0;
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                foreach (array_merge(self::SECRET_COLUMNS, $skip) as $secret) {
                    unset($row[$secret]);
                }
                if ($columns === null) {
                    $columns = array_keys($row);
                    fputcsv($out, $columns, ',', '"', '');
                }
                fputcsv($out, array_values($row), ',', '"', '');
                $rows++;
            }
            fclose($out);
            $counts[$name] = $rows;
            $zip->addFile($file, "data/{$name}.csv");
            $temp[] = $file;
        }
        $fileCount = 0;
        foreach (self::files($workspaceId) as $path) {
            $full = realpath(APP_ROOT . '/storage/' . $path);
            if ($full !== false && str_starts_with($full, realpath(APP_ROOT . '/storage')) && is_file($full)) {
                $zip->addFile($full, 'files/' . str_replace('\\', '/', $path));
                $fileCount++;
            }
        }
        $lines = [];
        foreach ($counts as $name => $n) {
            $lines[] = str_pad($name . '.csv', 30) . $n . ' row' . ($n === 1 ? '' : 's');
        }
        $zip->addFromString('README.txt', config('app_name') . " — all data of {$workspace['name']}\r\nMade " . date('F j, Y g:i A') . "\r\n\r\n"
            . "data/   one CSV file per kind of record (open them in Excel or Google Sheets). The id columns link the files:\r\n"
            . "        e.g. grades.csv student_id is the id in learners.csv, class_assignment_id the id in classes.csv.\r\n"
            . "files/  uploaded files ({$fileCount}): excuse letter attachments, class materials, learner photos.\r\n\r\n"
            . "Left out on purpose: passwords, parent link codes and QR card codes (print new cards to use them again).\r\n\r\n"
            . implode("\r\n", $lines) . "\r\n");
        $zip->close();
        foreach ($temp as $file) {
            @unlink($file);
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $workspace['name'])), '-') ?: 'school';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . '-data-' . date('Ymd') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    /**
     * Deletes all of a school's records and files for good. Only a paused school, never the first one. The school's
     * row (name, contact) and its payments stay, for accounting. Returns the number of records deleted.
     */
    public static function deleteWorkspace(int $workspaceId): int
    {
        $workspace = Workspace::find($workspaceId);
        if (!$workspace || $workspaceId === 1) {
            throw new UserFacingException('This school can\'t be deleted here.');
        }
        if ($workspace['status'] !== 'suspended') {
            throw new UserFacingException('Pause the school first, so nobody is using it while its data is deleted.');
        }
        $files = self::files($workspaceId);
        $tables = self::tables($workspaceId);
        unset($tables['payments']);
        // Records that belong to other records first, the school's own tables last (users after everything that points to them)
        $order = array_merge(
            array_diff(array_keys($tables), ['staff', 'learners', 'parents', 'school_years', 'subjects', 'sections', 'classes', 'attendance_meetings', 'message_threads']),
            ['message_threads', 'attendance_meetings', 'classes', 'sections', 'subjects', 'school_years', 'parents', 'learners', 'staff']
        );
        $pdo = db();
        $deleted = 0;
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // Child tables are found through their parents, so they go before them (the order above)
            foreach ($order as $name) {
                [$table, $where] = $tables[$name];
                $deleted += db_exec("DELETE t FROM {$table} t WHERE {$where}");
            }
            db_exec('DELETE FROM email_codes WHERE user_id NOT IN (SELECT id FROM users)');
            db_exec(
                "UPDATE workspaces SET status = 'deleted', deleted_at = ?, deletion_requested_at = NULL, suspended_reason = 'Data deleted' WHERE id = ?",
                [date('Y-m-d H:i:s'), $workspaceId]
            );
            $left = 0;
            foreach (['users', 'students', 'parents', 'class_assignments', 'sections', 'school_years', 'settings', 'consents'] as $table) {
                $left += (int) db_value("SELECT COUNT(*) FROM {$table} WHERE workspace_id = ?", [$workspaceId]);
            }
            if ($left > 0) {
                throw new RuntimeException("{$left} records were not deleted. Nothing was changed.");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $e;
        }
        foreach ($files as $path) {
            Files::delete($path);
        }
        return $deleted;
    }

    // ------------------------------------------------------------ a parent's own data

    /** A parent's own data at this school, as JSON (account, children, notifications, messages, letters, requests). */
    public static function parentData(int $parentId): array
    {
        $parent = db_one('SELECT * FROM parents WHERE id = ?', [$parentId]);
        foreach (self::SECRET_COLUMNS as $secret) {
            unset($parent[$secret]);
        }
        return [
            'school' => setting('school_name'),
            'made' => date('c'),
            'account' => $parent,
            'privacy_consents' => db_all("SELECT version, accepted_at FROM consents WHERE account_type = 'parent' AND account_id = ?", [$parentId]),
            'children' => db_all(
                'SELECT st.lrn, st.last_name, st.first_name, st.middle_name, ps.relationship FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = ?',
                [$parentId]
            ),
            'notifications' => db_all('SELECT type, message, created_at, read_at FROM parent_notifications WHERE parent_id = ? ORDER BY id', [$parentId]),
            'messages' => db_all(
                'SELECT t.subject, m.sender_type, m.body, m.created_at FROM message_threads t JOIN messages m ON m.thread_id = t.id WHERE t.parent_id = ? ORDER BY m.id',
                [$parentId]
            ),
            'excuse_letters' => db_all('SELECT date_from, date_to, reason, status, review_note, created_at FROM excuse_letters WHERE parent_id = ? ORDER BY id', [$parentId]),
            'document_requests' => db_all(
                "SELECT doc_type, purpose, copies, status, created_at FROM document_requests WHERE requested_by_type = 'parent' AND requested_by_id = ? ORDER BY id",
                [$parentId]
            ),
            'sign_ins' => db_all("SELECT success, ip, device, created_at FROM login_events WHERE account_type = 'parent' AND account_id = ? ORDER BY id DESC LIMIT 200", [$parentId]),
        ];
    }

    /**
     * Deletes a parent's account at this school: name, sign-in and links are removed; messages and letters the
     * school must keep stay, under "Deleted parent". Accounts at other schools are not touched.
     */
    public static function deleteParent(int $parentId): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                "UPDATE parents SET full_name = 'Deleted parent', login = ?, password_hash = ?, is_active = 0, must_change_password = 0,
                        blocked_reason = 'Deleted by the parent', last_login_at = NULL, last_login_ip = NULL, last_login_device = NULL,
                        session_version = session_version + 1
                  WHERE id = ?",
                ['deleted-' . $parentId . '-' . bin2hex(random_bytes(4)), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $parentId]
            );
            db_exec('DELETE FROM parent_students WHERE parent_id = ?', [$parentId]);
            db_exec('DELETE FROM parent_notifications WHERE parent_id = ?', [$parentId]);
            db_exec("DELETE FROM access_blocks WHERE account_type = 'parent' AND account_id = ?", [$parentId]);
            db_exec("DELETE FROM login_events WHERE account_type = 'parent' AND account_id = ?", [$parentId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
