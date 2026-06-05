<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\InspectionFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Task 5 — Notify production managers and QC inspectors when an inspection
 * fails so that NCR follow-up can begin immediately.
 *
 * Queued (ShouldQueue) so that the notification fan-out does not block the
 * HTTP response that triggered InspectionService::complete().
 */
class NotifyOnInspectionFailed implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(InspectionFailed $event): void
    {
        try {
            $inspection = $event->inspection;

            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['production_manager', 'qc_inspector']))
                ->where('is_active', true)
                ->get();

            $ref = "Inspection {$inspection->inspection_number}";

            $this->notifications->send($audience, 'quality.inspection_failed', [
                'title'       => "QC Failure — {$ref}",
                'message'     => "{$ref} failed. NCR may be required.",
                'link_to'     => "/quality/inspections/{$inspection->hash_id}",
                'entity_type' => 'inspection',
                'entity_id'   => $inspection->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnInspectionFailed failed', ['error' => $e->getMessage()]);
        }
    }
}
