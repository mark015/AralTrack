<?php
// Offline attendance for a teacher's phone.
//   GET  ?action=pack   the teacher's classes this semester with their learners, saved on the phone so QR cards
//                       can be recognised without internet. Cards are stored only as SHA-256 fingerprints.
//   POST action=sync    attendance recorded on the phone while offline (scans, marks, finish), sent once the
//                       connection is back. Sending the same data twice is safe.
// Answers are JSON.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$reply = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (current_staff() === null) {
    $reply(['ok' => false, 'signin' => true, 'message' => 'You are signed out. Sign in again to send the attendance saved on this phone.'], 401);
}
$staff = require_staff();
if (!Access::can('attendance')) {
    $reply(['ok' => false, 'message' => 'Attendance is turned off' . (Access::personalBlock('attendance') !== null ? ' for your account.' : ' by the school.')], 403);
}

$action = is_post() ? post('action') : get_str('action');

// ---------------------------------------------------------------- the class pack
if ($action === 'pack') {
    $sy = active_school_year();
    $semester = (int) setting('current_semester');
    $classes = $sy ? db_all(
        'SELECT ca.id FROM class_assignments ca JOIN sections sec ON sec.id = ca.section_id
          WHERE ca.teacher_id = ? AND sec.school_year_id = ? AND ca.semester = ?
          ORDER BY sec.grade_level, sec.name',
        [$staff['id'], $sy['id'], $semester]
    ) : [];
    $today = $sy ? Schedule::onDay(Schedule::forTeacher($staff['id'], (int) $sy['id']), null, $semester) : [];

    $pack = [
        'version' => 1,
        'staffId' => $staff['id'],
        'staffName' => $staff['full_name'],
        'savedAt' => time(),
        'date' => date('Y-m-d'),
        'lateAfterMinutes' => (int) setting('late_after_minutes'),
        'csrf' => csrf_token(),
        'api' => url('teacher/offline-api.php'),
        'home' => url('teacher/index.php'),
        'classes' => [],
    ];
    foreach ($classes as $row) {
        $class = class_info((int) $row['id']);
        $learners = db_all(
            'SELECT st.id, st.last_name, st.first_name, st.middle_name, st.lrn, st.qr_token
               FROM enrollments e JOIN students st ON st.id = e.student_id
              WHERE e.section_id = ? ORDER BY st.last_name, st.first_name',
            [$class['section_id']]
        );
        $open = Attendance::openSessionFor((int) $class['id']);
        $pack['classes'][] = [
            'id' => (int) $class['id'],
            'subject' => $class['subject_name'],
            'code' => $class['subject_code'],
            'section' => section_label($class),
            'today' => array_values(array_map(
                static fn ($s) => ['starts' => substr($s['starts_at'], 0, 5), 'ends' => substr($s['ends_at'], 0, 5), 'room' => (string) $s['room']],
                array_filter($today, static fn ($s) => (int) $s['class_assignment_id'] === (int) $class['id'])
            )),
            'openSession' => $open ? ['id' => (int) $open['id'], 'startsAt' => strtotime($open['starts_at'])] : null,
            'learners' => array_map(static fn ($l) => [
                'id' => (int) $l['id'],
                'name' => student_name($l),
                'lrn' => $l['lrn'],
                // Only a fingerprint of the card code is kept on the phone, never the code itself.
                'hash' => $l['qr_token'] ? hash('sha256', $l['qr_token']) : null,
            ], $learners),
        ];
    }
    $reply(['ok' => true, 'pack' => $pack]);
}

// ---------------------------------------------------------------- sending what was saved offline
if ($action === 'sync' && is_post()) {
    $payload = json_decode(post('payload'), true);
    if (!is_array($payload) || !is_array($payload['sessions'] ?? null)) {
        $reply(['ok' => false, 'message' => 'Nothing to send.'], 400);
    }
    $now = time();
    $results = [];
    foreach (array_slice($payload['sessions'], 0, 50) as $local) {
        $key = mb_substr((string) ($local['key'] ?? ''), 0, 60);
        $result = ['key' => $key, 'ok' => false, 'saved' => 0, 'late' => 0, 'skipped' => 0, 'marked' => 0, 'finished' => false];
        try {
            $class = class_info((int) ($local['classId'] ?? 0));
            if (!$class || (int) $class['teacher_id'] !== $staff['id']) {
                throw new UserFacingException('You are not the teacher of this class.');
            }
            $clientUuid = (string) ($local['clientUuid'] ?? '');
            if (!preg_match('/^[A-Za-z0-9-]{8,40}$/', $clientUuid)) {
                throw new UserFacingException('This offline session has no valid id.');
            }
            $startsAt = (int) ($local['startsAt'] ?? 0);
            if ($startsAt < $now - 14 * 86400 || $startsAt > $now + 600) {
                throw new UserFacingException('The class start time is not within the last 14 days. Check the date and time on the phone.');
            }
            $session = Attendance::sessionForOffline(
                $class,
                isset($local['serverSessionId']) ? (int) $local['serverSessionId'] : null,
                $clientUuid,
                date('Y-m-d', $startsAt),
                date('H:i:s', $startsAt),
                $staff['id']
            );
            $sessionStart = strtotime($session['starts_at']);

            foreach (array_slice(is_array($local['events'] ?? null) ? $local['events'] : [], 0, 1000) as $event) {
                $studentId = (int) ($event['studentId'] ?? 0);
                // The phone's clock: keep it between 2 hours before the class started and now.
                $at = max($sessionStart - 7200, min($now, (int) ($event['at'] ?? $now)));
                if (($event['type'] ?? '') === 'scan') {
                    $outcome = Attendance::offlineScan($session, $studentId, $at, $staff['id']);
                    match ($outcome) {
                        'saved' => $result['saved']++,
                        'late' => $result['late']++,
                        default => $result['skipped']++,
                    };
                } elseif (($event['type'] ?? '') === 'mark') {
                    $done = Attendance::mark($session, $studentId, (string) ($event['status'] ?? ''), (string) ($event['note'] ?? ''), $staff['id']);
                    $done['accepted'] ? $result['marked']++ : $result['skipped']++;
                }
            }
            if (!empty($local['finishedAt']) && $session['finished_at'] === null) {
                $finishedAt = max($sessionStart, min($now, (int) $local['finishedAt']));
                Attendance::finish(Attendance::session((int) $session['id']), $staff['id'], date('Y-m-d H:i:s', $finishedAt));
                $result['finished'] = true;
            }
            $result['ok'] = true;
            $result['sessionId'] = (int) $session['id'];
            $result['class'] = $class['subject_name'] . ' · ' . section_label($class);
        } catch (UserFacingException $e) {
            $result['message'] = $e->getMessage();
            $result['rejected'] = true; // will never succeed: the phone can drop it
        } catch (Throwable $e) {
            error_log('Offline attendance sync failed: ' . $e);
            $result['message'] = 'Could not save this class yet. It will be tried again.';
        }
        $results[] = $result;
    }
    $reply(['ok' => true, 'results' => $results]);
}

$reply(['ok' => false, 'message' => 'Unknown action.'], 400);
