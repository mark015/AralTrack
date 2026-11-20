<?php
declare(strict_types=1);

/**
 * The top menu for each kind of user. Each item is
 *   ['label', 'icon', 'path', 'also' => [other paths that highlight it], 'badge' => n]
 * or a dropdown: ['label', 'icon', 'children' => [items…]].
 * Items whose page doesn't exist are left out.
 */
final class Navigation
{
    public static function items(?array $staff, ?array $parent, bool $learner): array
    {
        if ($staff) {
            return self::filter(self::staff($staff));
        }
        if ($parent) {
            return self::filter([
                ['My children', 'house-heart', 'parent/index.php', 'also' => ['parent/child.php']],
                ['Notifications', 'bell', 'parent/notifications.php', 'badge' => Notifications::unreadCount($parent['id'])],
                ['Messages', 'chat-dots', 'messages.php', 'badge' => self::count(static fn () => Messages::unread('parent', $parent['id']))],
                ['Excuse letters', 'envelope-paper', 'parent/excuses.php'],
                ['Requests', 'file-earmark-text', 'requests.php'],
                ['News', 'megaphone', 'news.php'],
            ]);
        }
        if ($learner) {
            return self::filter([
                ['Home', 'house', 'learner/index.php'],
                ['Report card', 'journal-text', 'grades.php'],
                ['Schedule', 'calendar-week', 'learner/schedule.php'],
                ['Subjects', 'folder2-open', 'learner/subjects.php', 'also' => ['learner/scores.php']],
                ['Requests', 'file-earmark-text', 'requests.php'],
                ['News', 'megaphone', 'news.php'],
            ]);
        }
        return [];
    }

    private static function staff(array $staff): array
    {
        $role = $staff['role'];
        $adviser = (bool) advised_section_ids($staff);
        $teaches = (bool) db_value('SELECT 1 FROM class_assignments WHERE teacher_id = ? LIMIT 1', [$staff['id']]);
        $all = sees_all_learners($staff);
        $excuses = ($all || $adviser) ? self::count(static fn () => self::pendingExcuses($staff)) : 0;
        $messages = self::count(static fn () => Messages::unread('staff', $staff['id']));
        $changes = in_array($role, ['admin', 'principal'], true) ? self::count(static fn () => Grading::pendingRequests()) : 0;
        $requests = in_array($role, ['admin', 'principal'], true) ? self::count(static fn () => DocumentRequests::pending()) : 0;

        $teaching = [
            ['My classes', 'easel2', 'teacher/index.php', 'also' => ['teacher/class.php', 'teacher/upload.php', 'teacher/attendance.php', 'teacher/session.php', 'teacher/posts.php', 'teacher/schedule-edit.php', 'teacher/class-record.php']],
            ['My schedule', 'calendar-week', 'teacher/schedule.php'],
            $adviser ? ['My advisory', 'clipboard-heart', 'teacher/advisory.php', 'also' => ['teacher/values.php']] : null,
            ($adviser || $all) ? ['Excuse letters', 'envelope-paper', 'teacher/excuses.php', 'badge' => $excuses] : null,
            ['At-risk learners', 'exclamation-diamond', 'teacher/at-risk.php'],
            ['Messages', 'chat-dots', 'messages.php', 'badge' => $messages],
        ];
        $records = [
            ['Grading periods & changes', 'calendar-check', 'admin/grading.php', 'badge' => $changes],
            ['Grade history', 'clock-history', 'admin/grade-history.php'],
            ['Honor roll', 'award', 'admin/honors.php'],
            ['DepEd forms', 'file-earmark-ruled', 'admin/forms.php'],
            $role === 'admin' ? ['Year-end rollover', 'arrow-repeat', 'admin/rollover.php'] : null,
            ['Document requests', 'file-earmark-text', 'admin/requests.php', 'badge' => $requests],
            ['Reports', 'bar-chart', 'admin/reports.php'],
        ];

        return match ($role) {
            'admin' => [
                ['Dashboard', 'speedometer2', 'admin/index.php'],
                ['Learners', 'people', 'admin/students.php', 'also' => ['admin/student-edit.php']],
                ['Sections', 'diagram-3', 'admin/sections.php', 'also' => ['admin/section.php', 'admin/timetable.php']],
                ['Parents', 'person-hearts', 'admin/parents.php', 'also' => ['admin/parent-edit.php']],
                ['Records', 'folder2', 'children' => $records, 'badge' => $changes + $requests],
                ['News', 'megaphone', 'admin/announcements.php', 'also' => ['news.php']],
                ['Setup', 'gear', 'children' => [
                    ['Subjects', 'journal-bookmark', 'admin/subjects.php'],
                    ['School years', 'calendar3', 'admin/school-years.php'],
                    ['Staff accounts', 'person-badge', 'admin/users.php'],
                    ['Settings', 'sliders', 'admin/settings.php'],
                    ['Backup', 'database-down', 'admin/backup.php'],
                ]],
                ($teaches || $adviser) ? ['Teaching', 'easel2', 'children' => array_values(array_filter($teaching)), 'badge' => $messages] : ['Messages', 'chat-dots', 'messages.php', 'badge' => $messages],
            ],
            'principal' => [
                ['Reports', 'bar-chart', 'admin/reports.php'],
                ['Records', 'folder2', 'children' => $records, 'badge' => $changes + $requests],
                ['Learners', 'people', 'admin/profiles.php'],
                ['At-risk learners', 'exclamation-diamond', 'teacher/at-risk.php'],
                ['Excuse letters', 'envelope-paper', 'teacher/excuses.php', 'badge' => $excuses],
                ['News', 'megaphone', 'admin/announcements.php', 'also' => ['news.php']],
                $teaches ? ['Teaching', 'easel2', 'children' => array_values(array_filter($teaching)), 'badge' => $messages] : ['Messages', 'chat-dots', 'messages.php', 'badge' => $messages],
            ],
            'guidance' => [
                ['At-risk learners', 'exclamation-diamond', 'teacher/at-risk.php'],
                ['Excuse letters', 'envelope-paper', 'teacher/excuses.php', 'badge' => $excuses],
                ['Learners', 'people', 'admin/profiles.php'],
                ['Messages', 'chat-dots', 'messages.php', 'badge' => $messages],
                ['News', 'megaphone', 'news.php'],
            ],
            default => [...$teaching, ['News', 'megaphone', 'news.php']],
        };
    }

    public static function pendingExcuses(array $staff): int
    {
        $scope = sees_all_learners($staff) ? '' : ' AND e.section_id IN (' . (implode(',', advised_section_ids($staff)) ?: '0') . ')';
        return (int) db_value(
            "SELECT COUNT(*) FROM excuse_letters el
               JOIN enrollments e   ON e.student_id = el.student_id
               JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
              WHERE el.status = 'pending'{$scope}"
        );
    }

    /** Runs a counter, returning 0 if its table isn't there yet. */
    private static function count(callable $counter): int
    {
        try {
            return (int) $counter();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function filter(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            if (isset($item['children'])) {
                $item['children'] = self::filter($item['children']);
                if ($item['children']) {
                    $out[] = $item;
                }
            } elseif (is_file(APP_ROOT . '/public/' . $item[2])) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
