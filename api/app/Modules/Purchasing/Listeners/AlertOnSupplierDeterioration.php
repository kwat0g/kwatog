<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\SupplierPerformanceComputed;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * T3.3.C — When a vendor's overall_score drops by 20+ points vs the prior
 * month's snapshot, notify all active users with role 'purchasing_officer'.
 *
 * Queued; swallows all \Throwable so a failure here never blocks the
 * SupplierPerformanceService::compute() workflow that dispatched the event.
 */
class AlertOnSupplierDeterioration implements ShouldQueue
{
    public const DROP_THRESHOLD = 20.0;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SupplierPerformanceComputed $event): void
    {
        try {
            $current = $event->snapshot;
            if ($current->overall_score === null) return;

            $prior = $this->priorMonthSnapshot($current);
            if ($prior === null || $prior->overall_score === null) return;

            $drop = (float) $prior->overall_score - (float) $current->overall_score;
            if ($drop < self::DROP_THRESHOLD) return;

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get();

            if ($audience->isEmpty()) return;

            $current->loadMissing('vendor:id,name');
            $vendorName = $current->vendor?->name ?? "Vendor #{$current->vendor_id}";
            $period     = sprintf('%04d-%02d', $current->period_year, $current->period_month);

            $this->notifications->send($audience, 'purchasing.supplier_deterioration', [
                'title'       => "Supplier deterioration — {$vendorName}",
                'message'     => sprintf(
                    '%s overall score dropped %.1f → %.1f (-%.1f) for %s.',
                    $vendorName,
                    (float) $prior->overall_score,
                    (float) $current->overall_score,
                    $drop,
                    $period,
                ),
                'link_to'     => $current->vendor
                    ? "/purchasing/vendors/{$current->vendor->hash_id}/performance"
                    : null,
                'entity_type' => 'supplier_performance_snapshot',
                'entity_id'   => $current->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AlertOnSupplierDeterioration failed', [
                'snapshot_id' => $event->snapshot->id ?? null,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function priorMonthSnapshot(SupplierPerformanceSnapshot $current): ?SupplierPerformanceSnapshot
    {
        $year  = $current->period_year;
        $month = $current->period_month - 1;
        if ($month < 1) {
            $month = 12;
            $year  = $year - 1;
        }

        return SupplierPerformanceSnapshot::query()
            ->where('vendor_id',    $current->vendor_id)
            ->where('period_year',  $year)
            ->where('period_month', $month)
            ->first();
    }
}
