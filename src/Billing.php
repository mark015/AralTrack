<?php
declare(strict_types=1);

/**
 * Plans and payments.
 *   free  : a school the platform owner gives the system for free (no limits); for a teacher on their own, the free
 *           plan (up to a few classes)
 *   trial : everything, until trial_ends_at
 *   paid  : everything, until paid_until (plus a few grace days)
 * When a school's trial or paid time is over, its portal is read-only: everyone can still sign in and see
 * everything, but staff can't change anything until it is renewed. A teacher on their own falls back to the free
 * plan instead. Prices and trial length are set in the platform console (platform/billing.php).
 */
final class Billing
{
    /** Platform settings with their starting values (pesos). */
    public const DEFAULTS = [
        'school_price_month'   => '99',     // per staff account, per month
        'school_price_year'    => '990',    // per staff account, per year
        'teacher_price_month'  => '149',
        'teacher_price_year'   => '1490',
        'trial_days'           => '30',
        'grace_days'           => '7',
        'free_teacher_classes' => '2',
        'bank_instructions'    => '',
    ];

    /** While read-only, staff may still do these (sign out, change their password, pay). */
    private const ALWAYS_ALLOWED = ['billing.php', 'staff/logout.php', 'staff/password.php'];

    private static ?array $settings = null;

    public static function setting(string $name): string
    {
        if (self::$settings === null) {
            self::$settings = [];
            try {
                foreach (db_all('SELECT name, value FROM platform_settings') as $row) {
                    self::$settings[$row['name']] = (string) $row['value'];
                }
            } catch (PDOException) {
                // table not there yet
            }
        }
        return self::$settings[$name] ?? (self::DEFAULTS[$name] ?? '');
    }

    public static function saveSettings(array $pairs): void
    {
        foreach ($pairs as $name => $value) {
            db_exec('INSERT INTO platform_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, (string) $value]);
        }
        self::$settings = null;
    }

    /** The trial end date for a workspace made today. */
    public static function trialEnd(): string
    {
        return date('Y-m-d', strtotime('+' . max(1, (int) self::setting('trial_days')) . ' days'));
    }

    /**
     * Where a workspace stands.
     * @return array{code: string, label: string, read_only: bool, limited: bool, days_left: ?int, ends: ?string}
     *   code: free | teacher_free | trial | paid | grace | expired
     */
    public static function state(?array $workspace = null): array
    {
        $w = $workspace ?? Workspace::row();
        $teacher = $w['type'] === 'teacher';
        $today = date('Y-m-d');
        $make = static fn (string $code, string $label, ?string $ends = null, bool $readOnly = false): array => [
            'code' => $code, 'label' => $label, 'read_only' => $readOnly, 'limited' => $code === 'teacher_free',
            'ends' => $ends, 'days_left' => $ends ? (int) floor((strtotime($ends) - strtotime($today)) / 86400) : null,
        ];
        $over = $teacher
            ? $make('teacher_free', 'Free plan')
            : $make('expired', 'Subscription ended', null, true);

        if ($w['plan'] === 'free') {
            return $teacher ? $make('teacher_free', 'Free plan') : $make('free', 'Free');
        }
        if ($w['plan'] === 'trial') {
            if ($w['trial_ends_at'] === null || $today <= $w['trial_ends_at']) {
                return $make('trial', 'Free trial', $w['trial_ends_at']);
            }
            return $over + ['ended' => $w['trial_ends_at']];
        }
        if ($w['plan'] === 'paid' && $w['paid_until'] !== null) {
            if ($today <= $w['paid_until']) {
                return $make('paid', $teacher ? 'Teacher Pro' : 'Paid', $w['paid_until']);
            }
            $graceEnd = date('Y-m-d', strtotime($w['paid_until'] . ' +' . max(0, (int) self::setting('grace_days')) . ' days'));
            if ($today <= $graceEnd) {
                return $make('grace', 'Renewal due', $graceEnd);
            }
            return $over + ['ended' => $w['paid_until']];
        }
        return $over;
    }

    /** True when this request's school can only be viewed (not while the platform owner is helping it). */
    public static function readOnly(): bool
    {
        return Workspace::known() && empty($_SESSION['acting_owner']) && self::state()['read_only'];
    }

    /** Stops a change (a POST) when the school is read-only. Called by require_staff(). */
    public static function guardWrite(string $page): void
    {
        if (!is_post() || in_array($page, self::ALWAYS_ALLOWED, true) || !self::readOnly()) {
            return;
        }
        $message = 'Your subscription has ended, so the portal is read-only: nothing can be changed. Renew it under Billing.';
        $json = post('ajax') === '1' || str_ends_with($page, '-api.php') || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($json) {
            http_response_code(402);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'kind' => 'error', 'accepted' => false, 'message' => $message]);
            exit;
        }
        flash('warning', $message);
        redirect('billing.php');
    }

    /** Staff accounts a school pays for (active accounts, at least 1). */
    public static function seats(int $workspaceId): int
    {
        return max(1, (int) db_value('SELECT COUNT(*) FROM users WHERE workspace_id = ? AND is_active = 1', [$workspaceId]));
    }

    /**
     * What a workspace pays now for a month or a year.
     * @return array{amount: int, months: int, seats: ?int, description: string}  amount in centavos
     */
    public static function quote(array $workspace, string $cycle): array
    {
        $yearly = $cycle === 'yearly';
        $months = $yearly ? 12 : 1;
        if ($workspace['type'] === 'teacher') {
            $pesos = (float) self::setting($yearly ? 'teacher_price_year' : 'teacher_price_month');
            return ['amount' => (int) round($pesos * 100), 'months' => $months, 'seats' => null,
                'description' => config('app_name') . ' Teacher Pro, ' . ($yearly ? '1 year' : '1 month')];
        }
        $seats = self::seats((int) $workspace['id']);
        $pesos = (float) self::setting($yearly ? 'school_price_year' : 'school_price_month');
        return ['amount' => (int) round($pesos * 100) * $seats, 'months' => $months, 'seats' => $seats,
            'description' => config('app_name') . ' for ' . $workspace['name'] . ', ' . $seats . ' staff account' . ($seats === 1 ? '' : 's') . ', ' . ($yearly ? '1 year' : '1 month')];
    }

    /** A payment waiting to be paid (online) or already received (bank transfer recorded by the owner). */
    public static function createPayment(array $workspace, array $quote, string $provider, string $createdBy, string $note = ''): array
    {
        db_exec(
            'INSERT INTO payments (workspace_id, provider, description, amount, months, seats, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspace['id'], $provider, mb_substr($quote['description'], 0, 255), $quote['amount'], $quote['months'], $quote['seats'], $note !== '' ? mb_substr($note, 0, 255) : null, mb_substr($createdBy, 0, 150)]
        );
        return db_one('SELECT * FROM payments WHERE id = ?', [(int) db()->lastInsertId()]);
    }

    /**
     * Marks a payment as paid and adds its months to the workspace (from today, or from the end of the time
     * already paid). Safe to call twice: only the first call counts. Returns true when it was applied now.
     */
    public static function markPaid(int $paymentId, ?string $method = null): bool
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $payment = db_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment || $payment['status'] === 'paid') {
                $pdo->commit();
                return false;
            }
            $w = db_one('SELECT * FROM workspaces WHERE id = ? FOR UPDATE', [$payment['workspace_id']]);
            $today = date('Y-m-d');
            $from = $w['plan'] === 'paid' && $w['paid_until'] !== null && $w['paid_until'] >= $today ? $w['paid_until'] : $today;
            $until = date('Y-m-d', strtotime($from . ' +' . (int) $payment['months'] . ' months'));
            db_exec("UPDATE payments SET status = 'paid', paid_at = ?, method = COALESCE(?, method) WHERE id = ?", [date('Y-m-d H:i:s'), $method, $paymentId]);
            db_exec(
                "UPDATE workspaces SET plan = 'paid', paid_until = ?, billing_cycle = ?, seats = ? WHERE id = ?",
                [$until, (int) $payment['months'] >= 12 ? 'yearly' : 'monthly', $payment['seats'], $w['id']]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        platform_log('payment', "Payment received from {$w['name']}: " . self::pesos((int) $payment['amount']) . " ({$payment['description']}); paid until {$until}", (int) $w['id']);
        return true;
    }

    /** For a teacher on the free plan: why another class can't be added, or null when it can. */
    public static function classLimitMessage(): ?string
    {
        if (!Workspace::known() || !self::state()['limited']) {
            return null;
        }
        $limit = max(1, (int) self::setting('free_teacher_classes'));
        $sy = active_school_year();
        $count = $sy ? (int) db_value(
            'SELECT COUNT(*) FROM class_assignments ca JOIN sections s ON s.id = ca.section_id WHERE ca.workspace_id = ? AND s.school_year_id = ?',
            [ws(), $sy['id']]
        ) : 0;
        return $count >= $limit
            ? "The free plan has up to {$limit} classes. Upgrade to Teacher Pro under Billing to add more."
            : null;
    }

    public static function pesos(int $centavos): string
    {
        return '₱' . number_format($centavos / 100, $centavos % 100 === 0 ? 0 : 2);
    }
}
