<?php
declare(strict_types=1);

/** Learners and parents ask the registrar for school documents. */
final class DocumentRequests
{
    public const TYPES = [
        'enrollment' => 'Certificate of Enrollment',
        'good_moral' => 'Certificate of Good Moral Character',
        'sf9'        => 'Copy of report card (SF9)',
        'sf10'       => 'Permanent record (SF10 / Form 137)',
        'other'      => 'Other document',
    ];

    /** status => [label, badge colour] */
    public const STATUSES = [
        'pending'    => ['Received', 'warning'],
        'processing' => ['Being prepared', 'info'],
        'ready'      => ['Ready for pick-up', 'success'],
        'released'   => ['Released', 'secondary'],
        'rejected'   => ['Not approved', 'danger'],
    ];

    public static function pending(): int
    {
        return (int) db_value("SELECT COUNT(*) FROM document_requests WHERE status IN ('pending', 'processing') AND workspace_id = ?", [ws()]);
    }

    public static function badge(string $status): string
    {
        [$label, $tone] = self::STATUSES[$status] ?? [$status, 'secondary'];
        return '<span class="badge text-bg-' . $tone . '">' . e($label) . '</span>';
    }
}
