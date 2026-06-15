<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderOutputService;
use Illuminate\Validation\ValidationException;

/**
 * T2.2 — Edge → WO output ingest.
 *
 * Resolves the active WO running on the PLC device's bound machine and
 * delegates to WorkOrderOutputService::record() so idempotency, mold shot
 * tracking, scrap rate, and dashboard events all work identically to the
 * manual SPA path.
 *
 * System-user resolution + guard impersonation is handled by
 * {@see EdgeSystemUserResolver} (shared with T2.3 and T2.4).
 */
class EdgeOutputIngestService
{
    public function __construct(
        private readonly WorkOrderOutputService $outputs,
        private readonly EdgeSystemUserResolver $systemUser,
    ) {}

    public function ingest(EdgeDevice $device, array $payload, ?string $idemKey = null): WorkOrderOutput
    {
        if (! $device->machine_id) {
            throw ValidationException::withMessages([
                'device' => ['device_not_bound_to_machine'],
            ]);
        }

        $wo = WorkOrder::query()
            ->where('machine_id', $device->machine_id)
            ->where('status', WorkOrderStatus::InProgress->value)
            ->orderByDesc('actual_start')
            ->first();

        if (! $wo) {
            throw ValidationException::withMessages([
                'machine' => ['no_active_work_order'],
            ]);
        }

        // The request is authenticated against the `edge_device` guard. The
        // Authenticate middleware made that guard the default, so subsequent
        // calls to Auth::id() (e.g. inside the HasAuditLog observer when
        // WorkOrderOutputService updates the WorkOrder) would resolve to the
        // EdgeDevice's PK — which violates audit_logs.user_id → users(id) FK.
        // Pin the default guard to `web` and impersonate the edge-system user
        // for the duration of the ingest so audit trails are FK-clean.
        return $this->systemUser->impersonate(
            fn () => $this->outputs->record($wo, $payload, $this->systemUser->id(), $idemKey),
        );
    }
}
