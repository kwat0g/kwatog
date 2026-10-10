<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceScheduleInterval;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Listeners\NotifyMaintenanceWorkOrder;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Support\MaintenanceWorkOrderStateMachine;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldEventType;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MoldHistory;
use App\Modules\MRP\Services\MachineService;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Services\ProductionDashboardService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sprint 8 — Task 69. */
class MaintenanceWorkOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly MaintenanceScheduleService $schedules,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
        private readonly MaintenanceWorkOrderStateMachine $stateMachine,
        private readonly MachineService $machines,
        private readonly MachineHoursService $machineHours,
        private readonly MaintenanceAssigneeSuggestionService $suggestions,
    ) {}

    public function list(array $filters, ?User $assignedActor = null): LengthAwarePaginator
    {
        $q = MaintenanceWorkOrder::query()->with([
            'schedule:id,description,interval_type,interval_value',
            'machineTarget:id,machine_code,name',
            'moldTarget:id,mold_code,name',
            'assignee:id,first_name,last_name,employee_no',
            'assignee.user:id,employee_id,role_id',
            'assignee.user.role:id,slug',
            'creator:id,name',
        ]);

        if ($assignedActor !== null) {
            MaintenanceWorkAccess::scopeReadable($q, $assignedActor);
            unset($filters['assigned_to']);
        }

        foreach (['maintainable_type', 'type', 'priority', 'status'] as $f) {
            if (! empty($filters[$f])) {
                $val = (string) $filters[$f];
                // Support comma-separated multi-value filter (e.g. "open,assigned,in_progress")
                if (str_contains($val, ',')) {
                    $q->whereIn($f, array_map('trim', explode(',', $val)));
                } else {
                    $q->where($f, $val);
                }
            }
        }
        if (in_array($filters['queue'] ?? '', ['unassigned', 'critical'], true)) {
            $q->whereNotIn('status', ['completed', 'cancelled']);
            if ($filters['queue'] === 'unassigned') {
                $q->whereNull('assigned_to');
            } else {
                $q->where('priority', 'critical');
            }
        }
        if (($filters['queue'] ?? '') === 'awaiting_verification') {
            $q->where('status', 'in_progress')->where('verification_required', true)
                ->whereNotNull('repair_submitted_at');
        }
        if (($filters['queue'] ?? '') === 'in_progress') {
            $q->where('status', 'in_progress')->whereNull('repair_submitted_at');
        }
        if (($filters['queue'] ?? '') === 'overdue_preventive') {
            $q->where('type', 'preventive')->whereNotIn('status', ['completed', 'cancelled'])
                ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('next_due_at', '<', now()));
        }
        if (($filters['queue'] ?? '') === 'blocked_by_production') {
            $now = now();
            $q->whereNotIn('status', ['completed', 'cancelled'])->where(function (Builder $blocked) use ($now): void {
                $blocked->where(function (Builder $machine) use ($now): void {
                    $machine->where('maintainable_type', 'machine')->where(function (Builder $source) use ($now): void {
                        $source->whereHas('machineTarget', fn (Builder $target) => $target
                            ->whereNotNull('current_work_order_id')->orWhere('status', 'running'))
                            ->orWhereExists(fn ($production) => $production->selectRaw('1')->from('work_orders')
                                ->whereColumn('machine_id', 'maintenance_work_orders.maintainable_id')->where('status', 'in_progress'))
                            ->orWhereExists(fn ($reservation) => $reservation->selectRaw('1')->from('production_schedules')
                                ->whereColumn('machine_id', 'maintenance_work_orders.maintainable_id')
                                ->where('reserves_capacity', true)->whereIn('status', ['pending', 'confirmed', 'executed'])
                                ->where('scheduled_start', '<=', $now)->where('scheduled_end', '>', $now));
                    });
                })->orWhere(function (Builder $mold) use ($now): void {
                    $mold->where('maintainable_type', 'mold')->where(function (Builder $source) use ($now): void {
                        $source->whereHas('moldTarget', fn (Builder $target) => $target
                            ->whereNotNull('current_work_order_id')->orWhere('status', 'in_use'))
                            ->orWhereExists(fn ($production) => $production->selectRaw('1')->from('work_orders')
                                ->whereColumn('mold_id', 'maintenance_work_orders.maintainable_id')->where('status', 'in_progress'))
                            ->orWhereExists(fn ($reservation) => $reservation->selectRaw('1')->from('production_schedules')
                                ->whereColumn('mold_id', 'maintenance_work_orders.maintainable_id')
                                ->where('reserves_capacity', true)->whereIn('status', ['pending', 'confirmed', 'executed'])
                                ->where('scheduled_start', '<=', $now)->where('scheduled_end', '>', $now));
                    });
                });
            });
        }
        if (! empty($filters['assigned_to'])) {
            // SPA sends the employee HashID; comparing it raw against a bigint
            // column raises Postgres 22P02 and 500s the whole list page.
            $q->where('assigned_to', HashIdFilter::decode($filters['assigned_to'], Employee::class) ?? 0);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('mwo_number', SearchOperator::like(), $term)
                ->orWhere('description', SearchOperator::like(), $term));
        }

        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(MaintenanceWorkOrder $wo): MaintenanceWorkOrder
    {
        return $wo->load([
            'schedule',
            'machineTarget:id,machine_code,name',
            'moldTarget:id,mold_code,name',
            'assignee:id,first_name,last_name,employee_no,position_id',
            'assignee.user:id,employee_id,role_id',
            'assignee.user.role:id,slug',
            'creator:id,name',
            'submittedBy:id,name',
            'partsReconciledBy:id,name,role_id,employee_id',
            'partsReconciledBy.role:id,slug',
            'verifier:id,name',
            'machineDowntimes',
            'logs.logger:id,name',
            'spareParts.item:id,code,name,unit_of_measure',
            'spareParts.stockMovement',
            'spareParts.stockMovement.handover.recorder',
            'partRequests.item',
            'partRequests.location',
            'spareParts.workLog:id,finding,action_taken',
            'spareParts.issuer:id,name',
        ]);
    }

    public function selectableTargets(MaintainableType $type): Builder
    {
        $machine = $type === MaintainableType::Machine;
        $table = $machine ? 'machines' : 'molds';
        $query = $machine
            ? Machine::query()->whereNotIn('status', [MachineStatus::Running->value, MachineStatus::Maintenance->value])
            : Mold::query()->where('status', MoldStatus::Available->value);

        return $query->whereNull('current_work_order_id')
            ->whereNotExists(fn ($production) => $production->selectRaw('1')->from('work_orders')
                ->whereColumn($machine ? 'machine_id' : 'mold_id', $table.'.id')
                ->where('status', 'in_progress'))
            ->whereNotExists(fn ($reserved) => $reserved->selectRaw('1')->from('production_schedules')
                ->whereColumn($machine ? 'machine_id' : 'mold_id', $table.'.id')
                ->where('reserves_capacity', true)->whereIn('status', ['pending', 'confirmed', 'executed'])
                ->where('scheduled_start', '<=', now())->where('scheduled_end', '>', now()))
            ->whereNotExists(fn ($maintenance) => $maintenance->selectRaw('1')->from('maintenance_work_orders')
                ->whereColumn('maintainable_id', $table.'.id')
                ->where('maintainable_type', $type->value)->where('status', MaintenanceWorkOrderStatus::InProgress->value));
    }

    public function create(array $data, User $by, ?MaintenanceSchedule $fromSchedule = null, bool $manual = false): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($data, $by, $fromSchedule, $manual) {
            if ($fromSchedule !== null) {
                // The scheduler query is only a hint. Lock the schedule and
                // re-check its open WO inside the write transaction so a
                // duplicate queue delivery cannot materialise two preventive
                // WOs for the same due schedule.
                $fromSchedule = MaintenanceSchedule::query()
                    ->lockForUpdate()
                    ->findOrFail($fromSchedule->id);

                $existing = MaintenanceWorkOrder::query()
                    ->where('schedule_id', $fromSchedule->id)
                    ->whereNotIn('status', [
                        MaintenanceWorkOrderStatus::Completed->value,
                        MaintenanceWorkOrderStatus::Cancelled->value,
                    ])
                    ->orderByDesc('id')
                    ->first();
                if ($existing) {
                    return $this->show($existing);
                }
            }

            $type = MaintainableType::from((string) ($fromSchedule?->maintainable_type?->value ?? $data['maintainable_type']));
            $maintainableId = $fromSchedule?->maintainable_id ?? (int) $data['maintainable_id'];

            // Lock the polymorphic target as part of creation: target removal
            // cannot race the existence check and leave a new orphaned MWO.
            $target = match ($type) {
                MaintainableType::Machine => Machine::query()->lockForUpdate()->find($maintainableId),
                MaintainableType::Mold => Mold::query()->lockForUpdate()->find($maintainableId),
            };
            if (! $target) {
                throw ValidationException::withMessages([
                    'maintainable_id' => ["Target {$type->value}#{$maintainableId} not found."],
                ]);
            }
            if ($manual && ! $this->selectableTargets($type)->whereKey($maintainableId)->exists()) {
                throw ValidationException::withMessages([
                    'maintainable_id' => ['Equipment is currently in use or reserved. Choose an available target after Production releases it.'],
                ]);
            }
            if ($fromSchedule !== null) {
                $due = match ($fromSchedule->interval_type) {
                    MaintenanceScheduleInterval::Days => $fromSchedule->next_due_at?->lte(now()) ?? false,
                    MaintenanceScheduleInterval::Hours => $target instanceof Machine
                        && $fromSchedule->running_hours_baseline !== null
                        && bccomp((string) $target->running_hours_total,
                            bcadd((string) $fromSchedule->running_hours_baseline, (string) $fromSchedule->interval_value, 2), 2) >= 0,
                    MaintenanceScheduleInterval::Shots => $target instanceof Mold
                        && (int) $target->current_shot_count >= (int) round(
                            $fromSchedule->interval_value * $this->settings->requiredFloat('maintenance.mold_schedule.trigger_threshold_pct', 0, 100) / 100,
                        ),
                };
                if (! $fromSchedule->is_active || ! $due) {
                    throw ValidationException::withMessages(['schedule_id' => ['Schedule is inactive or no longer due.']]);
                }
            }

            $wo = MaintenanceWorkOrder::create([
                'mwo_number' => $this->sequences->generate('maintenance_wo'),
                'maintainable_type' => $type->value,
                'maintainable_id' => $maintainableId,
                'schedule_id' => $fromSchedule?->id,
                'type' => $fromSchedule
                    ? MaintenanceWorkOrderType::Preventive->value
                    : MaintenanceWorkOrderType::from((string) ($data['type'] ?? $this->settings->get('maintenance.work_order.default_type', '')))->value,
                'priority' => MaintenancePriority::from((string) ($data['priority'] ?? $this->settings->get('maintenance.work_order.default_priority', '')))->value,
                'description' => $fromSchedule?->description ?? $data['description'],
                'status' => MaintenanceWorkOrderStatus::Open->value,
                'created_by' => $by->id,
                'verification_required' => $this->settings->get('maintenance.closed_loop.enabled', false) === true,
            ]);

            $fresh = $this->show($wo);

            // Sprint 8 — Task 78: durable publication for the maintenance
            // dashboard event; the broadcast is still emitted only after the
            // creation transaction commits.
            app(OutboxService::class)->recordForChain(
                new MaintenanceWorkOrderCreated($fresh),
                $fresh,
                'maintenance',
                'maintenance_work_order',
                'created',
            );

            return $fresh;
        });
    }

    public function assign(MaintenanceWorkOrder $wo, int $employeeId, User $by, array $choice = []): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $employeeId, $by, $choice) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            $this->assertRepairEditable($locked);
            $employee = $this->eligibleAssignees()->with('user')->whereKey($employeeId)->first();
            if (! $employee) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Select an active Maintenance employee or technician with an active account.'],
                ]);
            }

            $window = MaintenanceWindow::confirmed()->where('work_order_id', $locked->id)->first();
            if (! array_key_exists('expected_assignee_id', $choice)
                || ($choice['expected_assignee_id'] ?? null) !== $locked->assigned_to) {
                throw ValidationException::withMessages(['expected_assignee_id' => ['Assignee changed. Refresh the work order and choose again.']]);
            }
            $candidate = $this->suggestions->candidate($locked, $employee,
                $window?->planned_start_at ?? now(), $window?->planned_end_at ?? now()->addMinute());
            $reason = trim((string) ($choice['presence_attestation_reason'] ?? ''));
            if (! $candidate['can_assign'] && ! ($candidate['can_attest_presence'] && $reason !== '' && $by->role?->slug === 'maintenance_supervisor')) {
                throw ValidationException::withMessages(['employee_id' => [$candidate['blocked_reason'].' Refresh suggestions and choose again.']]);
            }
            if ($locked->status === MaintenanceWorkOrderStatus::InProgress && $locked->assigned_to !== $employeeId
                && trim((string) ($choice['handover_note'] ?? '')) === '') {
                throw ValidationException::withMessages(['handover_note' => ['Describe the handover before replacing an in-progress assignee.']]);
            }

            if ($locked->status === MaintenanceWorkOrderStatus::Open) {
                $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::Assigned);
            } elseif (! in_array($locked->status, [MaintenanceWorkOrderStatus::Assigned, MaintenanceWorkOrderStatus::InProgress], true)) {
                throw ValidationException::withMessages(['status' => ['A closed work order cannot be assigned.']]);
            }
            $previous = $locked->assigned_to;
            $locked->forceFill([
                'assigned_to' => $employeeId,
                ...($previous !== $employeeId ? [
                    'parts_reconciled_at' => null,
                    'parts_reconciled_by' => null,
                ] : []),
            ])->save();
            $previousEmployee = $previous ? Employee::withTrashed()->find($previous) : null;
            $from = $previousEmployee ? $previousEmployee->full_name.' ('.$previousEmployee->employee_no.')' : ($previous ? 'employee #'.$previous : 'unassigned');
            $to = $employee->full_name.' ('.$employee->employee_no.')';
            $assignmentLog = $this->recordLifecycleLog($locked, 'Assignee changed from '.$from.' to '.$to.'.'.(! empty($choice['presence_attestation_reason']) ? ' Presence attestation: '.$choice['presence_attestation_reason'] : '').(! empty($choice['handover_note']) ? ' Handover: '.$choice['handover_note'] : ''), $by);

            if ($previous !== $employeeId) {
                $payload = NotifyMaintenanceWorkOrder::payload(
                    $locked, 'Maintenance assigned: '.$locked->mwo_number,
                    $employee->user->role?->slug === 'employee'
                        ? 'Your supervisor starts this order. Record work and spare parts once it is in progress.'
                        : 'You have been assigned this maintenance work order. Review readiness before starting.',
                );
                if ($employee->user->role?->slug === 'employee') {
                    $payload['link_to'] = '/self-service/maintenance-work-orders/'.$locked->hash_id;
                }
                $this->notifications->send($employee->user, 'maintenance.assigned', $payload,
                    'maintenance:assigned:'.$locked->id.':'.$employeeId.':'.$assignmentLog->id);
            }

            return $this->show($locked);
        });
    }

    public function eligibleAssignees(): Builder
    {
        return Employee::query()->where('status', 'active')
            ->whereHas('department', fn (Builder $query) => $query->where('code', 'MAINT')->where('is_active', true))
            ->whereHas('user', fn (Builder $query) => $query->where('is_active', true)
                ->whereHas('role', fn (Builder $role) => $role->whereIn('slug', ['maintenance_tech', 'employee'])));
    }

    public function start(MaintenanceWorkOrder $wo, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            if ($locked->status === MaintenanceWorkOrderStatus::InProgress) {
                return $this->show($locked);
            }

            $employee = Employee::find($locked->assigned_to);
            $candidate = $employee ? $this->suggestions->candidate($locked, $employee, now(), now()->addMinute()) : null;
            $assignmentLog = $candidate && $candidate['can_attest_presence']
                ? $locked->logs()->with('logger.role')->where('description', 'like', 'Assignee changed from %')->latest('id')->first()
                : null;
            $attestation = $assignmentLog
                && str_contains($assignmentLog->description, ' Presence attestation:')
                && $assignmentLog->created_at->gte($candidate['presence']['shift_start'])
                && $assignmentLog->logger?->role?->slug === 'maintenance_supervisor';
            if (! $candidate || (! $candidate['can_assign'] && ! $attestation)) {
                throw ValidationException::withMessages(['employee_id' => [($candidate['blocked_reason'] ?? 'Assign a technician before starting.').' Refresh attendance and assignment before starting.']]);
            }

            $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::InProgress);

            $locked->forceFill([
                'started_at' => now(),
            ])->save();

            // Mark machine target as under maintenance
            if ($locked->maintainable_type === MaintainableType::Machine) {
                $machine = Machine::query()->lockForUpdate()->find($locked->maintainable_id);
                $this->assertNoMaintenanceSibling($locked);
                $this->assertNoCurrentProductionReservation($locked);
                if ($machine && (
                    $machine->status === MachineStatus::Running
                    || $machine->current_work_order_id !== null
                )) {
                    throw ValidationException::withMessages([
                        'maintainable_id' => [
                            'A maintenance work order cannot start while the machine is assigned to active production.',
                        ],
                    ]);
                }
                if ($machine) {
                    $locked->forceFill(['pre_maintenance_status' => $machine->status->value])->save();
                }
                if ($machine && $machine->status?->value !== 'maintenance') {
                    $this->machines->transitionStatus(
                        $machine,
                        MachineStatus::Maintenance,
                        'Maintenance work order '.$locked->mwo_number.' started',
                    );
                    $openBreakdown = MachineDowntime::query()
                        ->where('machine_id', $machine->id)
                        ->where('category', MachineDowntimeCategory::Breakdown->value)
                        ->whereNull('end_time')
                        ->whereNull('maintenance_order_id')
                        ->lockForUpdate()
                        ->first();
                    if ($openBreakdown) {
                        $openBreakdown->forceFill(['maintenance_order_id' => $locked->id])->save();
                    } elseif (! MachineDowntime::query()
                        ->where('maintenance_order_id', $locked->id)
                        ->whereNull('end_time')
                        ->exists()) {
                        MachineDowntime::create([
                            'machine_id' => $machine->id,
                            'maintenance_order_id' => $locked->id,
                            'start_time' => now(),
                            'category' => MachineDowntimeCategory::PlannedMaintenance->value,
                            'description' => 'Maintenance work order '.$locked->mwo_number,
                        ]);
                    }
                }
            }
            if ($locked->maintainable_type === MaintainableType::Mold) {
                $mold = Mold::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($mold) {
                    $this->assertNoMaintenanceSibling($locked);
                    $this->assertNoCurrentProductionReservation($locked);
                    $this->assertMoldNotInProduction($mold);
                    if ($mold->status === MoldStatus::Retired) {
                        throw ValidationException::withMessages([
                            'maintainable_id' => ['A retired mold cannot enter maintenance.'],
                        ]);
                    }
                    $locked->forceFill(['pre_maintenance_status' => $mold->status->value])->save();
                    $mold->forceFill(['status' => MoldStatus::Maintenance->value])->save();
                    DB::afterCommit(static fn () => ProductionDashboardService::forgetCache());
                    MoldHistory::create([
                        'mold_id' => $mold->id,
                        'event_type' => MoldEventType::MaintenanceStarted->value,
                        'description' => $locked->description,
                        'performed_by' => $by->name,
                        'event_date' => now()->toDateString(),
                        'shot_count_at_event' => (int) $mold->current_shot_count,
                    ]);
                }
            }

            // The fixed-asset register follows the existing maintenance
            // work-order lifecycle; no sensor or separate IoT status writer is
            // required for the dashboard to reflect an active MWO.
            $this->markLinkedAssetUnderMaintenance($locked);

            $this->recordLifecycleLog($locked, 'Maintenance started.', $by);

            return $this->show($locked);
        });
    }

    /**
     * @param  array{remarks?: string|null, downtime_minutes?: int|null}  $data
     */
    public function complete(MaintenanceWorkOrder $wo, array $data, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $data, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            if ($locked->verification_required) {
                throw ValidationException::withMessages(['status' => ['Submit repair evidence for independent verification instead of completing this order.']]);
            }
            $this->assertPartsReconciled($locked);
            $this->assertLatestPassedWorkRecord($locked);

            return $this->finalize($locked, $data, $by);
        });
    }

    private function assertLatestPassedWorkRecord(MaintenanceWorkOrder $order): void
    {
        $result = $order->logs()->whereNotNull('finding')->whereNotNull('action_taken')
            ->reorder()->orderByDesc('id')->value('result');
        if ($result !== 'passed') {
            throw ValidationException::withMessages([
                'work_record' => ['Record a passing work result before closing this order.'],
            ]);
        }
    }

    private function finalize(MaintenanceWorkOrder $locked, array $data, User $by): MaintenanceWorkOrder
    {
        $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::Completed);
        app(MaintenanceWindowService::class)->release($locked);

        $cost = (string) SparePartUsage::query()->where('work_order_id', $locked->id)->sum(DB::raw('total_cost - returned_cost'));
        $downtimeMinutes = $locked->maintainable_type === MaintainableType::Machine
            ? $this->closeMachineDowntime($locked)
            : 0;

        $locked->forceFill([
            'completed_at' => now(),
            'downtime_minutes' => $downtimeMinutes,
            'cost' => $cost,
            'remarks' => $data['remarks'] ?? $locked->remarks,
        ])->save();

        // Mold: reset shot count, log history, accumulate lifecycle counters
        if ($locked->maintainable_type === MaintainableType::Mold) {
            $mold = Mold::query()->lockForUpdate()->find($locked->maintainable_id);
            if ($mold) {
                $this->assertMoldNotInProduction($mold);
                $shotsBefore = (int) $mold->current_shot_count;
                $attrs = [
                    'current_shot_count' => 0,
                    // Lifecycle manager: stamp + accumulate maintenance cost/count.
                    'last_maintenance_at' => now()->toDateString(),
                    'maintenance_count' => (int) $mold->maintenance_count + 1,
                    'total_maintenance_cost' => bcadd(
                        (string) $mold->total_maintenance_cost,
                        (string) $cost,
                        2,
                    ),
                ];
                // Only a mold held in maintenance can be released by this
                // work order. Never free a mold that production still owns.
                if ($mold->status === MoldStatus::Maintenance && ! $this->hasMaintenanceSibling($locked)) {
                    $attrs['status'] = MoldStatus::Available->value;
                }
                $mold->forceFill($attrs)->save();
                MoldHistory::create([
                    'mold_id' => $mold->id,
                    'event_type' => MoldEventType::MaintenanceCompleted->value,
                    'description' => $locked->description.' (shot count reset from '.$shotsBefore.')',
                    'cost' => $cost,
                    'performed_by' => $by->name,
                    'event_date' => now()->toDateString(),
                    'shot_count_at_event' => 0,
                ]);
                DB::afterCommit(static function (): void {
                    ProductionDashboardService::forgetCache();
                });
            }
        }
        // Machine: close the maintenance downtime ledger row, restore to idle
        if ($locked->maintainable_type === MaintainableType::Machine) {
            $machine = Machine::query()->lockForUpdate()->find($locked->maintainable_id);
            if ($machine && $machine->status === MachineStatus::Maintenance && ! $this->hasMaintenanceSibling($locked)) {
                $this->machines->transitionStatus(
                    $machine,
                    MachineStatus::Idle,
                    'Maintenance work order '.$locked->mwo_number.' completed',
                );
            }
        }
        $this->restoreLinkedAssetAfterMaintenance($locked);

        // Recompute schedule next_due_at
        if ($locked->schedule_id) {
            $schedule = MaintenanceSchedule::find($locked->schedule_id);
            if ($schedule) {
                if ($locked->maintainable_type === MaintainableType::Machine
                    && $schedule->interval_type === MaintenanceScheduleInterval::Hours) {
                    $this->machineHours->recomputeForMachine((int) $locked->maintainable_id);
                }
                $this->schedules->recomputeNextDueAt($schedule, now());
            }
        }

        $this->recordLifecycleLog($locked, 'Maintenance completed.'.($cost > 0 ? ' Spare parts cost '.app(CurrencyDisplayService::class)->format($cost).'.' : ''), $by);

        return $this->show($locked);

    }

    public function verifierRole(MaintenanceWorkOrder $wo): string
    {
        return $wo->maintainable_type === MaintainableType::Machine && $wo->type === MaintenanceWorkOrderType::Corrective
            ? 'production_manager' : 'maintenance_supervisor';
    }

    public function submitRepair(MaintenanceWorkOrder $wo, array $data, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $data, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            abort_unless(($by->employee_id !== null && (int) $locked->assigned_to === (int) $by->employee_id)
                || MaintenanceWorkAccess::supervisorMayManage($locked, $by), 403);
            $this->stateMachine->assertInProgress($locked, 'submit repair evidence');
            $this->assertRepairEditable($locked);
            if (! $locked->verification_required) {
                throw ValidationException::withMessages(['status' => ['This historical order uses the Complete action.']]);
            }
            foreach (['diagnosis', 'repair_action', 'functional_test_result'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([$field => ['Repair evidence is required.']]);
                }
            }
            $this->assertLatestPassedWorkRecord($locked);
            $this->assertPartsReconciled($locked);
            $locked->forceFill([
                'diagnosis' => trim($data['diagnosis']),
                'repair_action' => trim($data['repair_action']),
                'functional_test_result' => trim($data['functional_test_result']),
                'repair_submitted_by' => $by->id,
                'repair_submitted_at' => now(),
            ])->save();
            $log = $this->recordLifecycleLog($locked, 'Repair submitted. Diagnosis: '.$locked->diagnosis
                .' Repair action: '.$locked->repair_action.' Functional test: '.$locked->functional_test_result, $by);
            $recipients = User::query()->where('is_active', true)->whereKeyNot($by->id)
                ->whereHas('role', fn ($q) => $q->where('slug', $this->verifierRole($locked)))->get();
            $this->notifications->send($recipients, 'maintenance.repair_submitted',
                NotifyMaintenanceWorkOrder::payload($locked,
                    'Repair verification needed: '.$locked->mwo_number, 'Review technician evidence and independently verify recovery.'),
                'maintenance:submitted:'.$locked->id.':'.$log->id);

            return $this->show($locked);
        });
    }

    public function verifyRepair(MaintenanceWorkOrder $wo, array $data, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $data, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            abort_unless($by->role?->slug === $this->verifierRole($locked), 403);
            abort_if($locked->repair_submitted_by === $by->id
                || ($by->employee_id !== null && (int) $locked->assigned_to === (int) $by->employee_id), 403);
            if ($locked->status === MaintenanceWorkOrderStatus::Completed && $locked->verified_by === $by->id
                && ($data['decision'] ?? '') === 'accept') {
                return $this->show($locked);
            }
            $this->stateMachine->assertInProgress($locked, 'verify repair');
            if (! $locked->verification_required || $locked->repair_submitted_at === null) {
                throw ValidationException::withMessages(['status' => ['Submit repair evidence before verification.']]);
            }
            if (($data['decision'] ?? '') === 'reject') {
                $reason = trim((string) ($data['reason'] ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages(['reason' => ['Explain why recovery was rejected.']]);
                }
                $locked->forceFill(['repair_submitted_at' => null])->save();
                $log = $this->recordLifecycleLog($locked, 'Repair rejected: '.$reason, $by);
                $log->forceFill([
                    'finding' => $reason,
                    'action_taken' => 'Returned repair for rework.',
                    'result' => 'needs_follow_up',
                ])->save();
                $technician = User::find($locked->repair_submitted_by);
                if ($technician) {
                    $this->notifications->send($technician, 'maintenance.repair_rejected',
                        NotifyMaintenanceWorkOrder::payload($locked,
                            'Repair rejected: '.$locked->mwo_number, $reason),
                        'maintenance:rejected:'.$locked->id.':'.$log->id);
                }

                return $this->show($locked);
            }
            if (($data['decision'] ?? '') !== 'accept') {
                throw ValidationException::withMessages(['decision' => ['Choose accept or reject.']]);
            }
            $this->assertPartsReconciled($locked);
            $locked->forceFill(['verified_by' => $by->id, 'verified_at' => now()])->save();

            return $this->finalize($locked, [], $by);
        });
    }

    public function reconcileParts(MaintenanceWorkOrder $wo, array $data, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $data, $by): MaintenanceWorkOrder {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $this->assertRepairEditable($locked);
            if (in_array($locked->status, [MaintenanceWorkOrderStatus::Completed, MaintenanceWorkOrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => ['Parts cannot be reconciled after this order is closed.']]);
            }

            $supervisor = $by->role?->slug === 'maintenance_supervisor';
            if ($supervisor) {
                $inspectionReason = trim((string) ($data['inspection_reason'] ?? ''));
                if (! $by->is_active || mb_strlen($inspectionReason) < 5) {
                    throw ValidationException::withMessages(['inspection_reason' => ['Provide an inspection reason for supervisor cancellation.']]);
                }
                if (! MaintenanceWorkAccess::supervisorMayManage($locked, $by)) {
                    $this->assertAssigneeUnavailable($locked);
                }
            } elseif ($by->role?->slug === 'employee') {
                abort_unless(MaintenanceWorkAccess::canRecord($locked, $by), 403);
            } elseif ($by->role?->slug !== 'maintenance_tech'
                || $by->employee_id === null || (int) $locked->assigned_to !== (int) $by->employee_id) {
                abort(403);
            }

            app(MaintenancePartRequestService::class)->assertReady($locked);
            $outstanding = $this->outstandingPartQuantity($locked, true);
            if (bccomp($outstanding, '0', 3) > 0
                && ! filter_var($data['confirm_consumed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages([
                    'confirm_consumed' => ['Confirm that all outstanding parts were consumed before continuing.'],
                ]);
            }

            if ($locked->parts_reconciled_at !== null && (int) $locked->parts_reconciled_by === (int) $by->id) {
                return $this->show($locked);
            }

            $locked->forceFill([
                'parts_reconciled_at' => now(),
                'parts_reconciled_by' => $by->id,
            ])->save();
            $description = bccomp($outstanding, '0', 3) > 0
                ? 'Parts reconciled: '.$outstanding.' outstanding quantity confirmed consumed.'
                : 'Parts reconciled: no outstanding parts.';
            if ($supervisor) {
                $description .= ' Supervisor inspection: '.trim((string) $data['inspection_reason']);
            }
            $this->recordLifecycleLog($locked, $description, $by);

            return $this->show($locked);
        });
    }

    public function assertRepairEditable(MaintenanceWorkOrder $wo): void
    {
        if ($wo->verification_required && $wo->repair_submitted_at !== null) {
            throw ValidationException::withMessages(['status' => ['Repair is awaiting verification. Evidence, parts, assignment and cancellation are frozen.']]);
        }
    }

    public function cancel(MaintenanceWorkOrder $wo, ?string $reason, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $reason, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            $this->assertRepairEditable($locked);
            if ($by->role?->slug === 'maintenance_supervisor') {
                if (! $by->is_active || mb_strlen(trim((string) $reason)) < 5) {
                    throw ValidationException::withMessages(['reason' => ['A supervisor cancellation requires a reason.']]);
                }
                if (! MaintenanceWorkAccess::supervisorMayManage($locked, $by)) {
                    $this->assertAssigneeUnavailable($locked);
                }
                if ((int) $locked->parts_reconciled_by !== (int) $by->id || $locked->parts_reconciled_at === null) {
                    throw ValidationException::withMessages(['parts_reconciliation' => ['Inspect and reconcile parts before cancelling as a supervisor.']]);
                }
                $this->assertPartsReconciled($locked, $by, true);
            } else {
                $this->assertPartsReconciled($locked, $by, true);
            }
            $locked->partRequests()->where('status', 'requested')->update(['status' => 'withdrawn', 'withdrawn_by' => $by->id, 'withdrawn_at' => now()]);
            $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::Cancelled);
            app(MaintenanceWindowService::class)->release($locked);

            $locked->forceFill([
                'remarks' => $reason ?: $locked->remarks,
            ])->save();
            if ($locked->maintainable_type === MaintainableType::Machine) {
                $machine = Machine::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($machine && $locked->started_at !== null
                    && $machine->status === MachineStatus::Maintenance
                    && $machine->current_work_order_id === null
                    && ! $this->hasMaintenanceSibling($locked)) {
                    $restore = in_array($locked->pre_maintenance_status, [MachineStatus::Idle->value, MachineStatus::Offline->value, MachineStatus::Breakdown->value], true)
                        ? MachineStatus::from($locked->pre_maintenance_status) : MachineStatus::Breakdown;
                    if (MachineDowntime::query()->where('maintenance_order_id', $locked->id)
                        ->whereNull('end_time')->where('category', MachineDowntimeCategory::Breakdown->value)->exists()) {
                        $restore = MachineStatus::Breakdown;
                    }
                    if ($restore === MachineStatus::Idle) {
                        $this->closeMachineDowntime($locked);
                    } else {
                        // Keep fault time open and let a replacement repair claim it.
                        MachineDowntime::query()->where('maintenance_order_id', $locked->id)
                            ->whereNull('end_time')->update([
                                'maintenance_order_id' => null,
                                'category' => MachineDowntimeCategory::Breakdown->value,
                            ]);
                    }
                    $this->machines->transitionStatus($machine, $restore,
                        'Maintenance work order '.$locked->mwo_number.' cancelled; previous equipment condition retained');
                }
            }
            if ($locked->maintainable_type === MaintainableType::Mold && $locked->started_at !== null) {
                $mold = Mold::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($mold && $mold->status === MoldStatus::Maintenance
                    && $locked->pre_maintenance_status === MoldStatus::Available->value
                    && $mold->current_work_order_id === null
                    && $mold->current_shot_count < $mold->max_shots_before_maintenance
                    && ! $this->hasMaintenanceSibling($locked)) {
                    $mold->forceFill(['status' => MoldStatus::Available->value])->save();
                    DB::afterCommit(static fn () => ProductionDashboardService::forgetCache());
                }
            }
            $this->restoreLinkedAssetAfterMaintenance($locked);
            // Cancellation does not count as maintenance performed. Leave the
            // schedule due so the next sweep can create a replacement WO.
            $this->recordLifecycleLog($locked, 'Cancelled'.($reason ? ': '.$reason : '.'), $by);

            return $this->show($locked);
        });
    }

    public function log(MaintenanceWorkOrder $wo, string $description, User $by): MaintenanceLog
    {
        return DB::transaction(function () use ($wo, $description, $by): MaintenanceLog {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            $this->assertRepairEditable($locked);
            $this->stateMachine->assertInProgress($locked, 'add a log entry');

            return $this->recordLifecycleLog($locked, $description, $by);
        });
    }

    public function recordWork(MaintenanceWorkOrder $wo, array $data, User $by): MaintenanceLog
    {
        return DB::transaction(function () use ($wo, $data, $by): MaintenanceLog {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            $this->assertRepairEditable($locked);
            $this->stateMachine->assertInProgress($locked, 'record work');
            if ($data['result'] === 'passed') {
                app(MaintenancePartRequestService::class)->assertReady($locked);
            }

            return MaintenanceLog::create([
                'work_order_id' => $locked->id,
                'description' => "Finding: {$data['finding']}\nAction taken: {$data['action_taken']}\nResult: ".str_replace('_', ' ', ucfirst($data['result'])),
                'finding' => $data['finding'],
                'action_taken' => $data['action_taken'],
                'result' => $data['result'],
                'logged_by' => $by->id,
                'created_at' => now(),
            ]);
        });
    }

    private function recordLifecycleLog(MaintenanceWorkOrder $wo, string $description, User $by): MaintenanceLog
    {
        return MaintenanceLog::create([
            'work_order_id' => $wo->id,
            'description' => $description,
            'logged_by' => $by->id,
            'created_at' => now(),
        ]);
    }

    private function outstandingPartQuantity(MaintenanceWorkOrder $wo, bool $lock = false): string
    {
        $query = SparePartUsage::query()->where('work_order_id', $wo->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['quantity', 'returned_quantity'])->reduce(
            static fn (string $sum, SparePartUsage $part): string => bcadd(
                $sum,
                bcsub((string) $part->quantity, (string) ($part->returned_quantity ?? '0'), 3),
                3,
            ),
            '0.000',
        );
    }

    private function assertPartsReconciled(MaintenanceWorkOrder $wo, ?User $by = null, bool $cancellation = false): void
    {
        if (! $cancellation) {
            app(MaintenancePartRequestService::class)->assertReady($wo);
        }
        if (bccomp($this->outstandingPartQuantity($wo, true), '0', 3) === 0) {
            return;
        }
        $attester = $wo->parts_reconciled_at !== null
            ? User::query()->with('role')->find($wo->parts_reconciled_by)
            : null;
        if ($attester === null) {
            throw ValidationException::withMessages([
                'parts_reconciliation' => ['Reconcile all outstanding parts before closing or handing off this order.'],
            ]);
        }
        if ($attester->role?->slug === 'maintenance_supervisor') {
            if (! $cancellation || $by === null || $attester->id !== $by->id) {
                throw ValidationException::withMessages([
                    'parts_reconciliation' => ['Supervisor inspection evidence is valid only for that supervisor to cancel this order.'],
                ]);
            }
        } elseif (! in_array($attester->role?->slug, ['maintenance_tech', 'employee'], true)
            || (int) $attester->employee_id !== (int) $wo->assigned_to) {
            throw ValidationException::withMessages([
                'parts_reconciliation' => ['The assigned technician must attest to outstanding parts before this transition.'],
            ]);
        }
    }

    private function assertAssigneeUnavailable(MaintenanceWorkOrder $wo): void
    {
        $employee = $wo->assigned_to === null
            ? null
            : Employee::query()->with('department', 'user.role')->find($wo->assigned_to);
        $candidate = $employee
            ? $this->suggestions->candidate($wo, $employee, now(), now()->addMinute())
            : null;
        if ($candidate && ($candidate['can_assign'] || $candidate['can_attest_presence'])) {
            throw ValidationException::withMessages([
                'assigned_to' => ['Supervisor physical reconciliation is allowed only when the assigned technician is unavailable.'],
            ]);
        }
    }

    /** The caller holds the target row lock, which serializes maintenance claims. */
    private function hasMaintenanceSibling(MaintenanceWorkOrder $wo): bool
    {
        return MaintenanceWorkOrder::query()
            ->where('maintainable_type', $wo->maintainable_type->value)
            ->where('maintainable_id', $wo->maintainable_id)
            ->whereKeyNot($wo->getKey())
            ->where('status', MaintenanceWorkOrderStatus::InProgress->value)
            ->exists();
    }

    private function assertNoCurrentProductionReservation(MaintenanceWorkOrder $wo): void
    {
        $window = \App\Modules\Maintenance\Models\MaintenanceWindow::confirmed()->where('work_order_id', $wo->id)->first();
        if ($window) {
            if (now()->lt($window->planned_start_at) || now()->gte($window->planned_end_at)) {
                throw ValidationException::withMessages(['planned_start_at' => ['Start within the confirmed maintenance window or ask the supervisor to reschedule.']]);
            }
            app(MaintenanceWindowService::class)->assertAvailable($wo, now(), $window->planned_end_at);
        }
        $column = $wo->maintainable_type === MaintainableType::Machine ? 'machine_id' : 'mold_id';
        if (\App\Modules\Production\Models\WorkOrder::query()
            ->where($column, $wo->maintainable_id)->where('status', 'in_progress')->exists()) {
            throw ValidationException::withMessages(['maintainable_id' => ['Active production still owns this equipment. Ask Production to pause the work order and release resources before starting maintenance.']]);
        }
        if (ProductionSchedule::query()
            ->where($column, $wo->maintainable_id)->where('reserves_capacity', true)
            ->where('scheduled_start', '<=', now())->where('scheduled_end', '>', now())->exists()) {
            throw ValidationException::withMessages(['maintainable_id' => ['Equipment is reserved for current production. Ask Production to release resources before starting maintenance.']]);
        }
    }

    private function assertNoMaintenanceSibling(MaintenanceWorkOrder $wo): void
    {
        if ($this->hasMaintenanceSibling($wo)) {
            throw ValidationException::withMessages([
                'maintainable_id' => ['Another maintenance work order is already in progress for this target.'],
            ]);
        }
    }

    private function assertMoldNotInProduction(Mold $mold): void
    {
        if ($mold->current_work_order_id !== null || $mold->status === MoldStatus::InUse) {
            throw ValidationException::withMessages([
                'maintainable_id' => ['A maintenance work order cannot take a mold with active or unresolved production ownership.'],
            ]);
        }
    }

    private function markLinkedAssetUnderMaintenance(MaintenanceWorkOrder $wo): void
    {
        $assetId = match ($wo->maintainable_type) {
            MaintainableType::Machine => Machine::query()->find($wo->maintainable_id)?->asset_id,
            MaintainableType::Mold => Mold::query()->find($wo->maintainable_id)?->asset_id,
            default => null,
        };
        if ($assetId === null) {
            return;
        }

        $asset = Asset::query()->lockForUpdate()->find($assetId);
        if (! $asset) {
            return;
        }
        if ($asset->status === AssetStatus::Disposed) {
            throw ValidationException::withMessages([
                'maintainable_id' => ['A disposed asset cannot enter maintenance.'],
            ]);
        }
        if ($asset->status === AssetStatus::Active) {
            $asset->forceFill(['status' => AssetStatus::UnderMaintenance->value])->save();
        }
    }

    private function restoreLinkedAssetAfterMaintenance(MaintenanceWorkOrder $wo): void
    {
        $type = $wo->maintainable_type;
        if (! in_array($type, [MaintainableType::Machine, MaintainableType::Mold], true)) {
            return;
        }
        $hasAnotherActive = MaintenanceWorkOrder::query()
            ->where('maintainable_type', $type->value)
            ->where('maintainable_id', $wo->maintainable_id)
            ->where('id', '<>', $wo->getKey())
            ->where('status', MaintenanceWorkOrderStatus::InProgress->value)
            ->exists();
        if ($hasAnotherActive) {
            return;
        }

        $serviceable = match ($type) {
            MaintainableType::Machine => Machine::query()->find($wo->maintainable_id)?->status !== MachineStatus::Maintenance,
            MaintainableType::Mold => Mold::query()->find($wo->maintainable_id)?->status === MoldStatus::Available,
        };
        if (! $serviceable) {
            return;
        }

        $assetId = match ($type) {
            MaintainableType::Machine => Machine::query()->find($wo->maintainable_id)?->asset_id,
            MaintainableType::Mold => Mold::query()->find($wo->maintainable_id)?->asset_id,
        };
        if ($assetId === null) {
            return;
        }
        $asset = Asset::query()->lockForUpdate()->find($assetId);
        if ($asset?->status === AssetStatus::UnderMaintenance) {
            $asset->forceFill(['status' => AssetStatus::Active->value])->save();
        }
    }

    private function closeMachineDowntime(MaintenanceWorkOrder $wo): int
    {
        $openRows = MachineDowntime::query()
            ->where('maintenance_order_id', $wo->id)
            ->whereNull('end_time')
            ->lockForUpdate()
            ->get();

        foreach ($openRows as $open) {
            $end = now();
            $open->update([
                'end_time' => $end,
                'duration_minutes' => (int) max(0, $open->start_time->diffInMinutes($end, true)),
            ]);
        }

        $intervals = MachineDowntime::query()
            ->where('maintenance_order_id', $wo->id)
            ->get(['start_time', 'end_time'])
            ->map(static fn (MachineDowntime $row): array => [
                $row->start_time->getTimestamp(),
                $row->end_time?->getTimestamp() ?? $row->start_time->getTimestamp(),
            ])
            ->all();
        usort($intervals, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $seconds = 0;
        $start = null;
        $end = null;
        foreach ($intervals as [$rowStart, $rowEnd]) {
            if ($start === null) {
                [$start, $end] = [$rowStart, $rowEnd];
            } elseif ($rowStart <= $end) {
                $end = max($end, $rowEnd);
            } else {
                $seconds += $end - $start;
                [$start, $end] = [$rowStart, $rowEnd];
            }
        }
        if ($start !== null) {
            $seconds += $end - $start;
        }

        return intdiv($seconds, 60);
    }
}
