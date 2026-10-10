<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderReworkService;
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
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(InspectionFailed $event): void
    {
        try {
            $inspection = $event->inspection;

            $roles = array_values(array_filter((array) $this->settings->get('quality.inspection_failed.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $roles = match ($inspection->stage->value) {
                'incoming', 'supplier_return' => ['qc_inspector', 'warehouse_staff', 'purchasing_officer'],
                default => ['qc_inspector', 'production_manager', 'ppc_head'],
            };
            $audience = $audience->merge(User::where('is_active', true)->whereHas('role', fn ($query) => $query->whereIn('slug', $roles))->get());
            if ($inspection->entity_type?->value === 'work_order') {
                $wo = WorkOrder::find($inspection->entity_id);
                if ($wo) {
                    app(WorkOrderReworkService::class)->notifyProduction($wo, $inspection->ncr, 'qc_failed');
                }
            }
            $audience = $audience->unique('id')->filter(fn (User $user) => $user->hasPermission('quality.inspections.view'));

            $ref = "Inspection {$inspection->inspection_number}";

            $this->notifications->sendInApp($audience, 'quality.inspection_failed', [
                'title' => "QC Failure — {$ref}",
                'message' => "{$ref} failed. Review the linked defect and affected stock before continuing.",
                'link_to' => "/quality/inspections/{$inspection->hash_id}",
                'entity_type' => 'inspection',
                'entity_id' => $inspection->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnInspectionFailed failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
