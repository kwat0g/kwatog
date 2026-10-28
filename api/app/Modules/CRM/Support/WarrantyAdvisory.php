<?php

declare(strict_types=1);

namespace App\Modules\CRM\Support;

use Carbon\CarbonImmutable;

final class WarrantyAdvisory
{
    /** @return array{status: string, start_basis: ?string, start_date: ?string, report_by: ?string, warranty_through: ?string, duration_days: ?int, report_window_days: ?int}|null */
    public static function from(?array $terms, ?string $deliveryDate, ?string $invoiceDate): ?array
    {
        if ($terms === null) {
            return null;
        }

        $basis = $terms['warranty_start_basis'] ?? null;
        $startDate = match ($basis) {
            'delivery' => $deliveryDate,
            'invoice' => $invoiceDate,
            default => null,
        };
        $durationDays = isset($terms['warranty_duration_days']) ? (int) $terms['warranty_duration_days'] : null;
        $reportWindowDays = isset($terms['warranty_report_window_days']) ? (int) $terms['warranty_report_window_days'] : null;

        return [
            'status' => $startDate === null || $durationDays === null || $reportWindowDays === null ? 'needs_review' : 'advisory',
            'start_basis' => $basis,
            'start_date' => $startDate,
            'report_by' => $startDate !== null && $reportWindowDays !== null
                ? CarbonImmutable::parse($startDate)->addDays($reportWindowDays)->toDateString()
                : null,
            'warranty_through' => $startDate !== null && $durationDays !== null
                ? CarbonImmutable::parse($startDate)->addDays($durationDays)->toDateString()
                : null,
            'duration_days' => $durationDays,
            'report_window_days' => $reportWindowDays,
        ];
    }
}
