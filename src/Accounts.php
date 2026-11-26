<?php
declare(strict_types=1);

/**
 * One person, several schools. Each school keeps its own record of a parent or learner; the records are joined
 * when the person signs in. Every record whose own check passes (for parents: the same password; for learners:
 * each school's own sign-in rule) is remembered in the session, and the person switches between their schools.
 * Each page still works inside one school only, so no school ever sees another school's records.
 *
 * A school's registrar can reset only their own school's record. That record then stops joining the others until
 * the person gives it the same password again, so a reset never opens another school's records.
 */
final class Accounts
{
    /** type => [table, session key] */
    private const TYPES = ['parent' => ['parents', 'parent_rows'], 'learner' => ['students', 'student_rows']];

    /** Remembers the records that passed sign-in, with their session version at that moment. */
    public static function remember(string $type, array $rows): void
    {
        $set = [];
        foreach ($rows as $row) {
            $set[(int) $row['id']] = (int) ($row['session_version'] ?? 0);
        }
        $_SESSION[self::TYPES[$type][1]] = $set;
    }

    /** Adds a record (e.g. a parent record made at another school by "Add a child"). */
    public static function add(string $type, array $row): void
    {
        $_SESSION[self::TYPES[$type][1]][(int) $row['id']] = (int) ($row['session_version'] ?? 0);
    }

    public static function forget(string $type): void
    {
        unset($_SESSION[self::TYPES[$type][1]]);
    }

    /** Ids of the remembered records (the current one included). @return list<int> */
    public static function ids(string $type): array
    {
        return array_map('intval', array_keys($_SESSION[self::TYPES[$type][1]] ?? []));
    }

    /**
     * The places this person can switch between: one per remembered record that can still be opened (not signed
     * out by its school, school not paused). Empty when there is only one.
     * @return list<array{id: int, workspace_id: int, label: string, current: bool}>
     */
    public static function places(string $type, int $currentId): array
    {
        [$table, $key] = self::TYPES[$type];
        $set = $_SESSION[$key] ?? [];
        if (count($set) < 2) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_keys($set)));
        $rows = db_all(
            "SELECT r.id, r.workspace_id, r.session_version, w.name, w.type, w.status, w.contact_name
               FROM {$table} r JOIN workspaces w ON w.id = r.workspace_id
              WHERE r.id IN ({$ids}) ORDER BY w.name"
        );
        $places = [];
        foreach ($rows as $row) {
            if ((int) $row['session_version'] !== (int) $set[(int) $row['id']] || $row['status'] !== 'active') {
                continue;
            }
            $places[] = [
                'id' => (int) $row['id'],
                'workspace_id' => (int) $row['workspace_id'],
                'label' => self::label($row),
                'current' => (int) $row['id'] === $currentId,
            ];
        }
        return count($places) > 1 ? $places : [];
    }

    /** How a school or teacher is named to parents and learners. Needs name, type and contact_name. */
    public static function label(array $workspace): string
    {
        return $workspace['type'] === 'teacher' && trim((string) ($workspace['contact_name'] ?? '')) !== ''
            ? $workspace['contact_name'] . "'s classes · " . $workspace['name']
            : (string) $workspace['name'];
    }

    /** Opens another remembered record (another school) in this browser. False when it can't be opened now. */
    public static function switchTo(string $type, int $id): bool
    {
        [$table, $key] = self::TYPES[$type];
        $set = $_SESSION[$key] ?? [];
        if (!isset($set[$id])) {
            return false;
        }
        $row = db_one("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if (!$row || (int) $row['session_version'] !== (int) $set[$id]) {
            return false; // signed out by that school since
        }
        $allowed = Workspace::within((int) $row['workspace_id'], static function () use ($type, $row): bool {
            if (Workspace::isSuspended() || !Access::loginOpen($type)) {
                return false;
            }
            return $type === 'parent' ? (bool) $row['is_active'] : !$row['login_blocked'];
        });
        if (!$allowed) {
            return false;
        }
        Workspace::enter((int) $row['workspace_id']);
        $_SESSION[$key] = $set;
        if ($type === 'parent') {
            $_SESSION['parent'] = ['id' => (int) $row['id'], 'full_name' => $row['full_name']];
        } else {
            $_SESSION['student_id'] = (int) $row['id'];
        }
        Access::stampSession($type, (int) $row['session_version']);
        return true;
    }
}
