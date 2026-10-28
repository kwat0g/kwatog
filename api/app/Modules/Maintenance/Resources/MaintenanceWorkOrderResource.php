<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Resources;

use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceAssigneeSuggestionService;
use App\Modules\Maintenance\Services\MaintenanceReadinessService;
use Illuminate\Http\Request;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceWorkOrder
 */
class MaintenanceWorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof MaintenanceWorkOrderStatus
            ? $this->status
            : MaintenanceWorkOrderStatus::tryFrom((string) $this->status);

        $target = $this->maintainable_type === MaintainableType::Machine
            ? ($this->relationLoaded('machineTarget') ? $this->machineTarget : null)
            : ($this->relationLoaded('moldTarget') ? $this->moldTarget : null);
        $elapsedEnd = $this->completed_at ?? $this->repair_submitted_at ?? now();

        return [
            'id'                => $this->hash_id,
            'mwo_number'        => $this->mwo_number,
            'maintainable_type' => $this->maintainable_type instanceof \BackedEnum ? $this->maintainable_type->value : $this->maintainable_type,
            'maintainable'      => $target ? [
                'id'   => $target->hash_id,
                'code' => $target->machine_code ?? $target->mold_code ?? null,
                'name' => $target->name,
            ] : null,
            'schedule'          => $this->whenLoaded('schedule', fn () => $this->schedule ? [
                'id'             => $this->schedule->hash_id,
                'description'    => $this->schedule->description,
                'interval_type'  => $this->schedule->interval_type instanceof \BackedEnum ? $this->schedule->interval_type->value : $this->schedule->interval_type,
                'interval_value' => (int) $this->schedule->interval_value,
            ] : null),
            'type'              => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'type_label'        => $this->type?->label(),
            'priority'          => $this->priority instanceof \BackedEnum ? $this->priority->value : $this->priority,
            'priority_label'    => $this->priority?->label(),
            'description'       => $this->description,
            'status'            => $status?->value ?? $this->status,
            'status_label'      => $status?->label() ?? (string) $this->status,
            'verification_required' => (bool) $this->verification_required,
            'repair_evidence' => $this->verification_required ? [
                'diagnosis' => $this->diagnosis,
                'repair_action' => $this->repair_action,
                'functional_test_result' => $this->functional_test_result,
            ] : null,
            'repair_submitted_at' => optional($this->repair_submitted_at)?->toISOString(),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy ? [
                'id' => $this->submittedBy->hash_id,
                'name' => $this->submittedBy->name,
            ] : null),
            'verified_at' => optional($this->verified_at)?->toISOString(),
            'verifier' => $this->whenLoaded('verifier', fn () => $this->verifier ? [
                'id' => $this->verifier->hash_id,
                'name' => $this->verifier->name,
            ] : null),
            'open_downtime_started_at' => $this->whenLoaded('machineDowntimes', fn () =>
                $this->machineDowntimes->whereNull('end_time')->sortBy('start_time')->first()?->start_time?->toISOString()),
            'server_time' => $this->when($this->relationLoaded('logs'), fn () => now()->toISOString()),
            'response_minutes' => $this->started_at && $this->created_at && $this->started_at->gte($this->created_at)
                ? (int) $this->created_at->diffInMinutes($this->started_at, true) : null,
            'technician_repair_minutes' => $this->repair_submitted_at && $this->started_at && $this->repair_submitted_at->gte($this->started_at)
                ? (int) $this->started_at->diffInMinutes($this->repair_submitted_at, true) : null,
            'maintenance_elapsed_minutes' => $this->started_at && $elapsedEnd->gte($this->started_at)
                ? (int) $this->started_at->diffInMinutes($elapsedEnd, true) : null,
            'maintenance_elapsed_provisional' => $this->started_at !== null && $this->completed_at === null && $this->repair_submitted_at === null,
            'can_record_work' => MaintenanceWorkAccess::canRecord($this->resource, $request->user()),
            'available_actions' => $this->availableActions($status, $request),
            'closure_readiness' => $this->when($this->relationLoaded('logs'), fn () => $this->closureReadiness()),
            'parts_reconciled_at' => optional($this->parts_reconciled_at)?->toISOString(),
            'parts_reconciled_by' => $this->whenLoaded('partsReconciledBy', fn () => $this->partsReconciledBy ? [
                'id' => $this->partsReconciledBy->hash_id,
                'name' => $this->partsReconciledBy->name,
            ] : null),
            'part_requests' => $this->whenLoaded('partRequests', fn () => $this->partRequests->map(fn ($part) => [
                'id' => $part->hash_id, 'status' => $part->status, 'quantity' => $part->quantity,
                'item_code' => $part->item?->code, 'item_name' => $part->item?->name, 'unit' => $part->item?->unit_of_measure,
                'location' => $part->location?->full_code, 'requested_at' => $part->created_at?->toISOString(),
                'handed_over_at' => $part->handed_over_at?->toISOString(),
            ])),
            'parts_handover' => $this->when($this->relationLoaded('partRequests'), function () {
                $pending = app(\App\Modules\Maintenance\Services\MaintenancePartRequestService::class)->pending($this->resource);
                return ['pending' => $pending, 'blocker' => $pending > 0 ? \App\Modules\Maintenance\Services\MaintenancePartRequestService::PENDING_MESSAGE : null];
            }),
            'parts_readiness' => $this->when($this->relationLoaded('spareParts'), fn () => $this->partsReadiness($request)),
            'readiness' => $this->when($this->relationLoaded('logs'), fn () =>
                app(MaintenanceReadinessService::class)->forOrder($this->resource, $request->user())),
            'started_at'        => optional($this->started_at)?->toISOString(),
            'completed_at'      => optional($this->completed_at)?->toISOString(),
            'downtime_minutes'  => (int) $this->downtime_minutes,
            'cost'              => (string) $this->cost,
            'remarks'           => $this->remarks,
            'assignee'          => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id'          => $this->assignee->hash_id,
                'employee_no' => $this->assignee->employee_no,
                'name'        => trim(($this->assignee->first_name ?? '').' '.($this->assignee->last_name ?? '')),
            ] : null),
            'creator'           => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'logs'              => $this->whenLoaded('logs', fn () => $this->logs->map(fn ($l) => [
                'id'          => $l->hash_id,
                'description' => $l->description,
                'finding' => $l->finding,
                'action_taken' => $l->action_taken,
                'result' => $l->result,
                'logger'      => $l->logger ? ['id' => $l->logger->hash_id, 'name' => $l->logger->name] : null,
                'created_at'  => optional($l->created_at)?->toISOString(),
            ])),
            'spare_parts'       => $this->whenLoaded('spareParts', fn () => $this->spareParts->map(fn ($s) => [
                'id'         => $s->hash_id,
                'item'       => $s->item ? [
                    'id'   => $s->item->hash_id,
                    'code' => $s->item->code,
                    'name' => $s->item->name,
                    'unit' => $s->item->unit_of_measure,
                ] : null,
                'quantity'   => (string) $s->quantity,
                'lot_number' => $s->stockMovement?->lot_number,
                'stock_movement_id' => $s->stockMovement?->hash_id,
                'handover' => $s->stockMovement?->handover ? ['recipient_name' => $s->stockMovement->handover->recipient_name,
                    'handed_over_at' => $s->stockMovement->handover->handed_over_at->toISOString()] : null,
                'work_record' => $s->workLog ? [
                    'id' => $s->workLog->hash_id,
                    'finding' => $s->workLog->finding,
                    'action_taken' => $s->workLog->action_taken,
                ] : null,
                'issuer' => $s->issuer ? ['id' => $s->issuer->hash_id, 'name' => $s->issuer->name] : null,
                'unit_cost'  => (string) $s->unit_cost,
                'total_cost' => (string) $s->total_cost,
                'returned_quantity' => (string) $s->returned_quantity,
                'returned_cost' => (string) $s->returned_cost,
                'created_at' => optional($s->created_at)?->toISOString(),
            ])),
            'created_at'        => optional($this->created_at)?->toISOString(),
            'updated_at'        => optional($this->updated_at)?->toISOString(),
        ];
    }

    private function closureReadiness(): array
    {
        $latest = $this->logs->filter(fn ($log) => $log->finding !== null && $log->action_taken !== null)
            ->sortByDesc('id')->first();
        $result = $latest?->result;
        $action = $this->verification_required ? 'repair submission' : 'completion';

        return [
            'latest_work_result' => $result,
            'can_close_work' => $result === 'passed',
            'blocker' => $result === 'passed' ? null : ($result === null
                ? 'Record a passing work result before '.$action.'.'
                : 'Latest work result is '.str_replace('_', ' ', $result).'. Record a passing result before '.$action.'.'),
        ];
    }

    private function partsReadiness(Request $request): array
    {
        $outstanding = $this->spareParts->map(function ($part): ?array {
            $quantity = bcsub((string) $part->quantity, (string) $part->returned_quantity, 3);
            if (bccomp($quantity, '0', 3) <= 0) {
                return null;
            }

            return [
                'usage_id' => $part->hash_id,
                'item_code' => $part->item?->code,
                'item_name' => $part->item?->name,
                'lot_number' => $part->stockMovement?->lot_number,
                'quantity' => $quantity,
            ];
        })->filter()->values()->all();
        $attester = $this->relationLoaded('partsReconciledBy') ? $this->partsReconciledBy : null;
        $supervisorOnly = $attester?->role?->slug === 'maintenance_supervisor';
        $technicianReady = in_array($attester?->role?->slug, ['maintenance_tech', 'employee'], true)
            && $attester->employee_id !== null && (int) $attester->employee_id === (int) $this->assigned_to;
        $handoverPending = app(\App\Modules\Maintenance\Services\MaintenancePartRequestService::class)->pending($this->resource) > 0;
        $ready = ! $handoverPending && ($outstanding === [] || ($this->parts_reconciled_at !== null
            && ($technicianReady || ($supervisorOnly && $request->user()?->id === $attester->id))));

        return [
            'outstanding' => $outstanding,
            'can_close_parts' => $ready,
            'blocker' => $ready ? null : ($handoverPending ? \App\Modules\Maintenance\Services\MaintenancePartRequestService::PENDING_MESSAGE : ($supervisorOnly
                ? 'Supervisor inspection covers cancellation only. The assigned technician must reconcile parts before completion or repair submission.'
                : 'Return unused parts or confirm the remaining parts consumed before closing or submitting this work order.')), 
        ];
    }

    /** @return list<string> */
    private function availableActions(?MaintenanceWorkOrderStatus $status, Request $request): array
    {
        $actor = $request->user();
        $supervisorMayManage = MaintenanceWorkAccess::supervisorMayManage($this->resource, $actor);
        $mayExecute = $supervisorMayManage || $actor?->can('maintenance.wo.complete') && (
            $actor->role?->slug === 'system_admin'
            || ($actor->employee_id !== null && (int) $this->assigned_to === (int) $actor->employee_id)
            || ($this->assigned_to === null && ! $this->verification_required
                && $status === MaintenanceWorkOrderStatus::Open && $actor->employee_id !== null)
        );
        $actions = $mayExecute
            ? match ($status) {
                MaintenanceWorkOrderStatus::Open,
                MaintenanceWorkOrderStatus::Assigned => ['start', 'cancel'],
                MaintenanceWorkOrderStatus::InProgress => $this->verification_required
                    ? ($this->repair_submitted_at ? [] : ($supervisorMayManage || (int) $actor?->employee_id === (int) $this->assigned_to
                        ? ['submit_repair', 'cancel'] : ['cancel']))
                    : ['complete', 'cancel'],
                default => [],
            }
            : [];

        if ($this->assigned_to === null && $actor?->role?->slug !== 'system_admin'
            && $this->created_by !== $actor?->id) {
            $actions = array_values(array_diff($actions, ['cancel']));
        }

        if ($this->relationLoaded('logs') && $actor?->role?->slug === 'maintenance_supervisor'
            && $actor->can('maintenance.wo.assign') && ! $this->repair_submitted_at
            && in_array($status, [MaintenanceWorkOrderStatus::Open, MaintenanceWorkOrderStatus::Assigned,
                MaintenanceWorkOrderStatus::InProgress], true)) {
            $employee = $this->assigned_to ? Employee::query()->with('department', 'user.role')->find($this->assigned_to) : null;
            $candidate = $employee ? app(MaintenanceAssigneeSuggestionService::class)
                ->candidate($this->resource, $employee, now(), now()->addMinute()) : null;
            if (! $candidate || (! $candidate['can_assign'] && ! $candidate['can_attest_presence'])) {
                $actions[] = 'cancel';
            }
        }

        if ($status === MaintenanceWorkOrderStatus::InProgress && $this->verification_required
            && $this->repair_submitted_at && $actor?->can('maintenance.wo.verify')
            && (int) $this->repair_submitted_by !== (int) $actor->id
            && (int) $actor->employee_id !== (int) $this->assigned_to
            && $actor->role?->slug === ($this->type?->value === 'corrective'
                && $this->maintainable_type === MaintainableType::Machine ? 'production_manager' : 'maintenance_supervisor')) {
            $actions[] = 'verify_repair';
        }

        if (in_array($status, [MaintenanceWorkOrderStatus::Open, MaintenanceWorkOrderStatus::Assigned], true)
            && ! $this->repair_submitted_at
            && $actor?->can('maintenance.wo.assign')) {
            array_unshift($actions, 'assign');
        }

        return $actions;
    }
}
