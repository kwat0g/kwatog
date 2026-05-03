<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprint 7 — Task 61. NCR lifecycle service.
 *
 * Lifecycle:
 *   create()                 — opens NCR (auto-called from inspection failure)
 *   addAction()              — append containment/corrective/preventive entry
 *   setDisposition()         — finalises material disposition
 *   close()                  — closes; on disposition=scrap from outgoing QC
 *                              auto-creates a replacement WorkOrder; on
 *                              disposition=return_to_supplier notifies Purchasing
 *   cancel()                 — voids before closure
 */
class NcrService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly NotificationService $notifications,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = NonConformanceReport::query()->with([
            'product:id,part_number,name',
            'inspection:id,inspection_number,stage,status',
            'creator:id,name',
            'assignee:id,name',
        ]);

        foreach (['source', 'severity', 'status', 'disposition'] as $f) {
            if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        }
        if (! empty($filters['product_id']))    $q->where('product_id', $filters['product_id']);
        if (! empty($filters['inspection_id'])) $q->where('inspection_id', $filters['inspection_id']);
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('ncr_number', 'like', $term)
                ->orWhere('defect_description', 'like', $term));
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(NonConformanceReport $ncr): NonConformanceReport
    {
        return $ncr->load([
            'product:id,part_number,name',
            'inspection:id,inspection_number,stage,status,product_id',
            'creator:id,name',
            'assignee:id,name',
            'closer:id,name',
            'replacementWorkOrder:id,wo_number,status,quantity_target',
            'actions' => fn ($q) => $q->with('performer:id,name')->orderBy('performed_at'),
        ]);
    }

    /**
     * @param array{
     *   source: string, severity: string, product_id?: int|null,
     *   inspection_id?: int|null, complaint_id?: int|null,
     *   defect_description: string, affected_quantity?: int,
     *   assigned_to?: int|null
     * } $data
     */
    public function create(array $data, User $by): NonConformanceReport
    {
        return DB::transaction(function () use ($data, $by) {
            $ncr = NonConformanceReport::create([
                'ncr_number'        => $this->sequences->generate('ncr'),
                'source'            => NcrSource::from((string) $data['source'])->value,
                'severity'          => NcrSeverity::from((string) $data['severity'])->value,
                'status'            => NcrStatus::Open->value,
                'product_id'        => $data['product_id'] ?? null,
                'inspection_id'     => $data['inspection_id'] ?? null,
                'complaint_id'      => $data['complaint_id'] ?? null,
                'defect_description'=> $data['defect_description'],
                'affected_quantity' => (int) ($data['affected_quantity'] ?? 0),
                'created_by'        => $by->id,
                'assigned_to'       => $data['assigned_to'] ?? null,
            ]);
            return $this->show($ncr);
        });
    }

    /**
     * Auto-open an NCR from a failed inspection. Idempotent: returns the
     * existing NCR if one is already linked. Severity is derived from
     * critical-fail count and Ac overflow.
     */
    public function openFromInspectionFailure(Inspection $inspection, User $by): NonConformanceReport
    {
        $existing = NonConformanceReport::query()
            ->where('inspection_id', $inspection->id)
            ->first();
        if ($existing) return $this->show($existing);

        $criticalFail = $inspection->measurements?->contains(
            fn ($m) => $m->is_critical && $m->is_pass === false
        ) ?? false;
        $severity = $criticalFail
            ? NcrSeverity::Critical->value
            : ($inspection->defect_count > $inspection->accept_count
                ? NcrSeverity::High->value
                : NcrSeverity::Medium->value);

        return $this->create([
            'source'             => NcrSource::InspectionFail->value,
            'severity'           => $severity,
            'product_id'         => $inspection->product_id,
            'inspection_id'      => $inspection->id,
            'defect_description' => 'Inspection '.$inspection->inspection_number.' failed: '
                                   .$inspection->defect_count.' defect(s) on '
                                   .$inspection->stage->value.' stage'
                                   .($criticalFail ? ' (critical parameter failure)' : ''),
            'affected_quantity'  => $inspection->batch_quantity,
        ], $by);
    }

    public function addAction(NonConformanceReport $ncr, array $data, User $by): NcrAction
    {
        if ($ncr->status->isTerminal()) {
            throw new RuntimeException('Cannot add actions to a closed NCR.');
        }
        return DB::transaction(function () use ($ncr, $data, $by) {
            $action = NcrAction::create([
                'ncr_id'       => $ncr->id,
                'action_type'  => NcrActionType::from((string) $data['action_type'])->value,
                'description'  => $data['description'],
                'performed_by' => $by->id,
                'performed_at' => $data['performed_at'] ?? now(),
            ]);
            // Bump status to in_progress on first action.
            if ($ncr->status === NcrStatus::Open) {
                $ncr->forceFill(['status' => NcrStatus::InProgress->value])->save();
            }
            return $action->load('performer:id,name');
        });
    }

    public function setDisposition(NonConformanceReport $ncr, string $disposition, ?string $rootCause, ?string $correctiveAction): NonConformanceReport
    {
        if ($ncr->status->isTerminal()) {
            throw new RuntimeException('NCR is already closed.');
        }
        $ncr->forceFill([
            'disposition'       => NcrDisposition::from($disposition)->value,
            'root_cause'        => $rootCause ?: $ncr->root_cause,
            'corrective_action' => $correctiveAction ?: $ncr->corrective_action,
            'status'            => NcrStatus::InProgress->value,
        ])->save();
        return $this->show($ncr);
    }

    /**
     * Close the NCR. Triggers downstream effects based on disposition:
     *   - scrap on outgoing-QC inspection → auto-create replacement WO
     *   - return_to_supplier              → notify Purchasing role
     */
    public function close(NonConformanceReport $ncr, User $by): NonConformanceReport
    {
        if ($ncr->status->isTerminal()) {
            throw new RuntimeException('NCR is already closed.');
        }
        if (! $ncr->disposition) {
            throw new RuntimeException('Cannot close NCR without a disposition.');
        }

        return DB::transaction(function () use ($ncr, $by) {
            $ncr->forceFill([
                'status'    => NcrStatus::Closed->value,
                'closed_by' => $by->id,
                'closed_at' => now(),
            ])->save();

            // Replacement WO: outgoing-QC scrap → re-create the lost output.
            if ($ncr->disposition === NcrDisposition::Scrap
                && $ncr->inspection_id
                && $ncr->product_id
                && $ncr->affected_quantity > 0) {
                $insp = Inspection::find($ncr->inspection_id);
                if ($insp && $insp->stage === InspectionStage::Outgoing) {
                    $wo = $this->workOrderService()?->createDraft([
                        'product_id'      => $ncr->product_id,
                        'quantity_target' => $ncr->affected_quantity,
                        'planned_start'   => now()->addDay()->toDateString(),
                        'planned_end'     => now()->addDays(7)->toDateString(),
                        'priority'        => 5, // bumped above default
                        'parent_ncr_id'   => $ncr->id,
                        'created_by'     => $by->id,
                    ]);
                    if ($wo) {
                        $ncr->forceFill(['replacement_work_order_id' => $wo->id])->save();
                    }
                }
            }

            // Return to supplier → notify Purchasing officers.
            if ($ncr->disposition === NcrDisposition::ReturnToSupplier) {
                $this->notifyPurchasing($ncr);
            }

            return $this->show($ncr);
        });
    }

    public function cancel(NonConformanceReport $ncr, ?string $reason, User $by): NonConformanceReport
    {
        if ($ncr->status->isTerminal()) {
            throw new RuntimeException('NCR is already closed.');
        }
        $ncr->forceFill([
            'status'           => NcrStatus::Cancelled->value,
            'closed_by'        => $by->id,
            'closed_at'        => now(),
            'corrective_action'=> trim(($ncr->corrective_action ? $ncr->corrective_action."\n" : '').'[cancelled] '.($reason ?: 'no reason given')),
        ])->save();
        return $this->show($ncr);
    }

    /** Lazy resolve to keep the Quality module bootable without Production. */
    private function workOrderService(): ?\App\Modules\Production\Services\WorkOrderService
    {
        try {
            return app(\App\Modules\Production\Services\WorkOrderService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function notifyPurchasing(NonConformanceReport $ncr): void
    {
        // Find Purchasing officers (role slug exists from Sprint 5 seeder).
        $role = Role::query()->where('slug', 'purchasing_officer')->first();
        if (! $role) return;
        $recipients = User::query()->where('role_id', $role->id)->get();
        if ($recipients->isEmpty()) return;

        $payload = [
            'subject'   => "Return-to-supplier required: NCR {$ncr->ncr_number}",
            'body'      => "NCR {$ncr->ncr_number} closed with disposition return_to_supplier. Quantity: {$ncr->affected_quantity}.",
            'ncr_id'    => $ncr->hash_id,
            'severity'  => $ncr->severity->value,
        ];

        // Custom anonymous notification — DatabaseNotification fallback.
        $notification = new class($payload) extends BaseNotification {
            public function __construct(public readonly array $payload) {}
            public function via($notifiable): array { return ['database']; }
            public function toDatabase($notifiable): array { return $this->payload; }
        };

        $this->notifications->notify($recipients, $notification, 'ncr.return_to_supplier');
    }
}
