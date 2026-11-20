<?php
declare(strict_types=1);

/** School announcements and the school calendar. */
final class News
{
    /** type => [label, badge colour, icon] */
    public const EVENT_TYPES = [
        'event'      => ['Event', 'primary', 'calendar-event'],
        'exam'       => ['Exams', 'warning', 'pencil-square'],
        'meeting'    => ['Meeting', 'info', 'people'],
        'deadline'   => ['Deadline', 'warning', 'alarm'],
        'holiday'    => ['Holiday', 'success', 'sun'],
        'suspension' => ['Class suspension', 'danger', 'cloud-lightning-rain'],
    ];

    public const AUDIENCES = [
        'everyone' => 'Everyone (staff, learners and parents)',
        'families' => 'Learners and parents',
        'staff'    => 'Staff only',
    ];

    /** @param string $viewer 'staff' or 'families' */
    public static function announcements(string $viewer, int $limit = 30): array
    {
        $audiences = $viewer === 'staff' ? ['everyone', 'staff'] : ['everyone', 'families'];
        return db_all(
            'SELECT a.*, u.full_name AS author FROM announcements a LEFT JOIN users u ON u.id = a.posted_by
              WHERE a.audience IN (?, ?) ORDER BY a.pinned DESC, a.id DESC LIMIT ' . max(1, $limit),
            $audiences
        );
    }

    public static function upcoming(int $limit = 8): array
    {
        return db_all('SELECT * FROM calendar_events WHERE ends_on >= ? ORDER BY starts_on, id LIMIT ' . max(1, $limit), [date('Y-m-d')]);
    }

    public static function between(string $from, string $to): array
    {
        return db_all('SELECT * FROM calendar_events WHERE starts_on <= ? AND ends_on >= ? ORDER BY starts_on, id', [$to, $from]);
    }

    /** A holiday or class suspension covering $date, if any. */
    public static function noClassEvent(string $date): ?array
    {
        return db_one(
            "SELECT * FROM calendar_events WHERE type IN ('holiday', 'suspension') AND ? BETWEEN starts_on AND ends_on ORDER BY id DESC LIMIT 1",
            [$date]
        );
    }

    /** Posts an announcement; parents are notified when it is for them. Returns the new id. */
    public static function post(string $title, string $body, string $audience, bool $pinned, int $userId): int
    {
        db_exec(
            'INSERT INTO announcements (title, body, audience, pinned, posted_by) VALUES (?, ?, ?, ?, ?)',
            [$title, $body, $audience, $pinned ? 1 : 0, $userId]
        );
        $id = (int) db()->lastInsertId();
        if ($audience !== 'staff') {
            db_exec(
                "INSERT INTO parent_notifications (parent_id, student_id, type, message, link)
                 SELECT id, NULL, 'announcement', ?, ? FROM parents WHERE is_active = 1",
                ['New announcement: ' . mb_substr($title, 0, 400), 'news.php#a' . $id]
            );
        }
        return $id;
    }

    public static function dateRange(array $event): string
    {
        $from = strtotime($event['starts_on']);
        $to = strtotime($event['ends_on']);
        if ($from === $to) {
            return date('D, M j', $from);
        }
        return date('M j', $from) . ' – ' . date(date('m', $from) === date('m', $to) ? 'j' : 'M j', $to);
    }
}
