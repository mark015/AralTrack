<?php
declare(strict_types=1);

/**
 * The top menu for each kind of user. Each item is
 *   ['label', 'icon', 'path', 'also' => [other paths that highlight it], 'badge' => n]
 * or a dropdown: ['label', 'icon', 'children' => [items…]].
 * Items whose page doesn't exist, or with 'show' => false (a part turned off by the Super Admin), are left out.
 */
final class Navigation
{
    public static function items(?array $staff, ?array $parent, bool $learner): array
    {
        if ($staff) {
            return self::filter(Workspace::isSolo() ? self::solo($staff) : self::staff($staff));
        }
        if ($parent) {
            return self::filter([
                ['My children', 'house-heart', 'parent/index.php', 'also' => ['parent/child.php']],
                ['Notifications', 'bell', 'parent/notifications.php', 'badge' => Notifications::unreadCount($parent['id'])],
                ['Messages', 'chat-dots', 'messages.php', 'badge' => self::count(static fn () => Messages::unread('parent', $parent['id'])), 'show' => Access::can('messages')],
                ['Excuse letters', 'envelope-paper', 'parent/excuses.php', 'show' => Access::can('excuses')],
                ['Requests', 'file-earmark-text', 'requests.php', 'show' => Access::can('requests')],
                ['News', 'megaphone', 'news.php', 'show' => Access::can('news')],
            ]);
        }
        if ($learner) {
            return self::filter([
                ['Home', 'house', 'learner/index.php'],
                ['Report card', 'journal-text', 'grades.php', 'show' => Access::can('grades')],
                ['Schedule', 'calendar-week', 'learner/schedule.php'],
                ['Subjects', 'folder2-open', 'learner/subjects.php', 'also' => ['learner/scores.php'], 'show' => Access::can('materials') || Access::can('class_record')],
                ['Requests', 'file-earmark-text', 'requests.php', 'show' => Access::can('requests')],
                ['News', 'megaphone', 'news.php', 'show' => Access::can('news')],
            ]);
        }
        return [];
    }

    /** A teacher who signed up alone: their classes, their learners and parents, and their own setup. */
    private static function solo(array $staff): array
    {
        $excuses = self::count(static fn () => self::pendingExcuses($staff));
        $messages = self::count(static fn () => Messages::unread('staff', $staff['id']));
        $adviser = (bool) advised_section_ids($staff);
        return [
            ['My classes', 'easel2', 'teacher/index.php', 'also' => ['teacher/class.php', 'teacher/upload.php', 'teacher/attendance.php', 'teacher/session.php', 'teacher/posts.php', 'teacher/schedule-edit.php', 'teacher/class-record.php']],
            ['My schedule', 'calendar-week', 'teacher/schedule.php'],
            ['Learners', 'people', 'badge' => Access::can('excuses') ? $excuses : 0, 'children' => array_values(array_filter([
                ['Learners', 'people', 'admin/students.php', 'also' => ['admin/student-edit.php', 'admin/profiles.php']],
                ['Sections & QR cards', 'diagram-3', 'admin/sections.php', 'also' => ['admin/section.php', 'admin/timetable.php']],
                ['Parents', 'person-hearts', 'admin/parents.php', 'also' => ['admin/parent-edit.php']],
                $adviser ? ['My advisory', 'clipboard-heart', 'teacher/advisory.php', 'also' => ['teacher/values.php']] : null,
                ['Excuse letters', 'envelope-paper', 'teacher/excuses.php', 'badge' => $excuses, 'show' => Access::can('excuses')],
                ['At-risk learners', 'exclamation-diamond', 'teacher/at-risk.php'],
                ['DepEd forms', 'file-earmark-ruled', 'admin/forms.php'],
            ]))],
            ['Messages', 'chat-dots', 'messages.php', 'badge' => $messages, 'show' => Access::can('messages')],
            ['News', 'megaphone', 'admin/announcements.php', 'also' => ['news.php']],
            ['Setup', 'gear', 'children' => [
                ['Setup guide', 'signpost-split', 'teacher/setup.php'],
                ['Subjects', 'journal-bookmark', 'admin/subjects.php'],
                ['School years', 'calendar3', 'admin/school-years.php'],
                ['Settings', 'sliders', 'admin/settings.php'],
                ['Learner & parent sign-in', 'person-lock', 'superadmin/accounts.php'],
                ['Control panel', 'toggles', 'superadmin/index.php'],
            ]],
        ];
    }

    private static function staff(array $staff): array
    {
        $role = $staff['role'];
        $adviser = (bool) advised_section_ids($staff);
        $teaches = (bool) db_value('SELECT 1 FROM class_assignments WHERE teacher_id = ? LIMIT 1', [$staff['id']]);
        $all = sees_all_learners($staff);
        $excuses = ($all || $adviser) ? self::count(static fn () => self::pendingExcuses($staff)) : 0;
        $messages = self::count(static fn () => Messages::unread('staff', $staff['id']));
        $changes = in_array($role, ['superadmin', 'admin', 'principal'], true) ? self::count(static fn () => Grading::pendingRequests()) : 0;
        $requests = in_array($role, ['superadmin', 'admin', 'principal'], true) ? self::count(static fn () => DocumentRequests::pending()) : 0;
        $excusesOn = Access::can('excuses');
        $messagesOn = Access::can('messages');

        $teaching = [
            ['My classes', 'easel2', 'teacher/index.php', 'also' => ['teacher/class.php', 'teacher/upload.php', 'teacher/attendance.php', 'teacher/session.php', 'teacher/posts.php', 'teacher/schedule-edit.php', 'teacher/class-record.php']],
            ['My schedule', 'calendar-week', 'teacher/schedule.php'],
            $adviser ? ['My advisory', 'clipboard-heart', 'teacher/advisory.php', 'also' => ['teacher/values.php']] : null,
            ($adviser || $all) ? ['Excuse letters', 'envelope-paper', 'teacher/excuses.php', 'badge' => $excuses, 'show' => $excusesOn] : null,
            ['At-risk learners', 'exclamation-diamond', 'teacher/at-risk.php'],
            ['Messages', 'chat-dots', 'messages.php', 'badge' => $messages, 'show' => $messagesOn],
        ];
        $messagesItem = ['Messages', 'chat-dots', 'messages.php', 'badge' => $messages, 'show' => $messagesOn];
        $teachingMenu = ($teaches || $adviser)
            ? ['Teaching', 'easel2', 'children' => array_values(array_filter($teaching)), 'badge' => $messagesOn ? $messages : 0]
            : $messagesItem;
        $setup = [
            ['Subjects', 'journal-bookmark', 'admin/subjects.php'],
            ['School years', 'calendar3', 'admin/school-years.php'],
            ['Staff accounts', 'person-badge', 'admin/users.php'],
            ['Settings', 'sliders', 'admin/settings.php'],
        ];
        $schoolMenu = [
            ['Dashboard', 'speedometer2', 'admin/index.php'],
            ['Learners', 'people', 'admin/students.php', 'also' => ['admin/student-edit.php', 'admin/profiles.php']],
            ['Sections', 'diagram-3', 'admin/sections.php', 'also' => ['admin/section.php', 'admin/timetable.php']],
            ['Parents', 'person-hearts', 'admin/parents.php', 'also' => ['admin/parent-edit.php']],
        ];
        $records = [
            ['Grading periods & changes', 'calendar-check', 'admin/grading.php', 'badge' => $changes],
            ['Grade history', 'clock-history', 'admin/grade-history.php'],
            ['Honor roll', 'award', 'admin/honors.php'],
            ['DepEd forms', 'file-earmark-ruled', 'admin/forms.php'],
            is_admin($staff) ? ['Year-end rollover', 'arrow-repeat', 'admin/rollover.php'] : null,
            ['Document requests', 'file-earmark-text', 'admin/requests.php', 'badge' => $requests],
            ['Reports', 'bar-chart', 'admin/reports.php'],
        ];

        return match ($role) {
            // The Super Admin's "Control" menu holds the control panel, accounts and activity log, plus Setup.
            'superadmin' => [
                ...$schoolMenu,
                ['Records', 'folder2', 'children' => $records, 'badge' => $changes + $requests],
                ['News', 'megaphone', 'admin/announcements.php', 'also' => ['news.php']],
                ['Control', 'shield-lock', 'children' => [
                    ['Control panel', 'toggles', 'superadmin/index.php'],
                    ['Accounts & sign-in', 'person-lock', 'superadmin/accounts.php'],
                    ['Activity log', 'journal-text', 'superadmin/activity.php'],
                    ...$setup,
                ]],
                $teachingMenu,
            ],
            'admin' => [
                ...$schoolMenu,
                ['Records', 'folder2', 'children' => $records, 'badge' => $changes + $requests],
                ['News', 'megaphone', 'admin/announcements.php', 'also' => ['news.php']],
                ['Setup', 'gear', 'children' => $setup],
                $teachingMenu,
            ],
            'principal' => [
                ['Reports', 'bar-chart', 'admin/reports.php'],
                ['Records', 'folder2', 'children' => $records, 'badge' => $changes + $requests],
                ['Learners', 'people', 'admin/profiles.php'],
                ['At-risk learners', 'exclamation-diamond', 'teacher/at-risk.php'],
                ['Excuse letters', 'envelope-paper', 'teacher/excuses.php', 'badge' => $excuses, 'show' => $excusesOn],
                ['News', 'megaphone', 'admin/announcements.php', 'also' => ['news.php']],
                $teaches ? $teachingMenu : $messagesItem,
            ],
            'guidance' => [
                ['At-risk learners', 'exclamation-diamond', 'teacher/at-risk.php'],
                ['Excuse letters', 'envelope-paper', 'teacher/excuses.php', 'badge' => $excuses, 'show' => $excusesOn],
                ['Learners', 'people', 'admin/profiles.php'],
                $messagesItem,
                ['News', 'megaphone', 'news.php', 'show' => Access::can('news')],
            ],
            default => [...$teaching, ['News', 'megaphone', 'news.php', 'show' => Access::can('news')]],
        };
    }

    /** Menu of the platform owner's console (platform/). */
    public static function owner(): array
    {
        $leads = self::count(static fn () => (int) db_value("SELECT COUNT(*) FROM platform_leads WHERE status = 'new'"));
        return [
            ['Schools', 'buildings', 'platform/index.php', 'also' => ['platform/workspace.php']],
            ['Demo requests', 'inbox', 'platform/leads.php', 'badge' => $leads],
            ['Backups', 'database-down', 'platform/backup.php'],
            ['Home page', 'globe2', 'index.php'],
        ];
    }

    public static function pendingExcuses(array $staff): int
    {
        $scope = sees_all_learners($staff) ? '' : ' AND e.section_id IN (' . (implode(',', advised_section_ids($staff)) ?: '0') . ')';
        return (int) db_value(
            "SELECT COUNT(*) FROM excuse_letters el
               JOIN enrollments e   ON e.student_id = el.student_id
               JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
              WHERE el.status = 'pending' AND el.workspace_id = ?{$scope}",
            [ws()]
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
            if ($item === null || ($item['show'] ?? true) === false) {
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
