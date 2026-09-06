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
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Support\MaintenanceWorkOrderStateMachine;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldEventType;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MoldHistory;
use App\Modules\MRP\Services\MachineService;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
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
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = MaintenanceWorkOrder::query()->with([
            'schedule:id,description,interval_type,interval_value',
            'assignee:id,first_name,last_name,employee_no',
            'creator:id,name',
        ]);

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
            'assignee:id,first_name,last_name,employee_no,position_id',
            'creator:id,name',
            'logs.logger:id,name',
            'spareParts.item:id,code,name,unit_of_measure',
        ]);
    }

    public function create(array $data, User $by, ?MaintenanceSchedule $fromSchedule = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($data, $by, $fromSchedule) {
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

            // Validate target
            $exists = match ($type) {
                MaintainableType::Machine => Machine::query()->whereKey($maintainableId)->exists(),
                MaintainableType::Mold => Mold::query()->whereKey($maintainableId)->exists(),
            };
            if (! $exists) {
                throw ValidationException::withMessages([
                    'maintainable_id' => ["Target {$type->value}#{$maintainableId} not found."],
                ]);
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

    public function assign(MaintenanceWorkOrder $wo, int $employeeId, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $employeeId, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            $employee = Employee::query()->whereKey($employeeId)->where('status', 'active')->first();
            if (! $employee) {
                throw ValidationException::withMessages([
                    'employee_id' => ['The selected assignee is not an active employee.'],
                ]);
            }

            $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::Assigned);
            $locked->forceFill([
                'assigned_to' => $employeeId,
            ])->save();
            $this->recordLifecycleLog($locked, 'Assigned to employee #'.$employeeId, $by);

            return $this->show($locked);
        });
    }

    public function start(MaintenanceWorkOrder $wo, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            if ($locked->status === MaintenanceWorkOrderStatus::InProgress) {
                return $this->show($locked);
            }

            $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::InProgress);

            $locked->forceFill([
                'started_at' => now(),
            ])->save();

            // Mark machine target as under maintenance
            if ($locked->maintainable_type === MaintainableType::Machine) {
                $machine = Machine::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($machine && $machine->status?->value !== 'maintenance') {
                    $this->machines->transitionStatus(
                        $machine,
                        MachineStatus::Maintenance,
                        'Maintenance work order '.$locked->mwo_number.' started',
                    );
                    MachineDowntime::create([
                        'machine_id' => $machine->id,
                        'maintenance_order_id' => $locked->id,
                        'start_time' => now(),
                        'category' => MachineDowntimeCategory::PlannedMaintenance->value,
                        'description' => 'Maintenance work order '.$locked->mwo_number,
                    ]);
                }
            }
            if ($locked->maintainable_type === MaintainableType::Mold) {
                $mold = Mold::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($mold) {
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
            $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::Completed);

            $cost = (string) SparePartUsage::query()->where('work_order_id', $locked->id)->sum('total_cost');

            $locked->forceFill([
                'completed_at' => now(),
                'downtime_minutes' => (int) ($data['downtime_minutes'] ?? 0),
                'cost' => $cost,
                'remarks' => $data['remarks'] ?? $locked->remarks,
            ])->save();

            // Mold: reset shot count, log history, accumulate lifecycle counters
            if ($locked->maintainable_type === MaintainableType::Mold) {
                $mold = Mold::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($mold) {
                    $shotsBefore = (int) $mold->current_shot_count;
                    $mold->forceFill([
                        'current_shot_count' => 0,
                        // Lifecycle manager: stamp + accumulate maintenance cost/count.
                        'last_maintenance_at' => now()->toDateString(),
                        'maintenance_count' => (int) $mold->maintenance_count + 1,
                        'total_maintenance_cost' => bcadd(
                            (string) $mold->total_maintenance_cost,
                            (string) $cost,
                            2,
                        ),
                    ])->save();
                    MoldHistory::create([
                        'mold_id' => $mold->id,
                        'event_type' => MoldEventType::MaintenanceCompleted->value,
                        'description' => $locked->description.' (shot count reset from '.$shotsBefore.')',
                        'cost' => $cost,
                        'performed_by' => $by->name,
                        'event_date' => now()->toDateString(),
                        'shot_count_at_event' => 0,
                    ]);
                }
            }
            // Machine: close the maintenance downtime ledger row, restore to idle
            if ($locked->maintainable_type === MaintainableType::Machine) {
                $this->closeMachineDowntime($locked);
                $machine = Machine::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($machine && $machine->status?->value === 'maintenance') {
                    $this->machines->transitionStatus(
                        $machine,
                        MachineStatus::Idle,
                        'Maintenance work order '.$locked->mwo_number.' completed',
                    );
                }
            }

            // Recompute schedule next_due_at
            if ($locked->schedule_id) {
                $schedule = MaintenanceSchedule::find($locked->schedule_id);
                if ($schedule) {
                    $this->schedules->recomputeNextDueAt($schedule, now());
                }
            }

            $this->recordLifecycleLog($locked, 'Maintenance completed.'.($cost > 0 ? ' Spare parts cost '.app(CurrencyDisplayService::class)->format($cost).'.' : ''), $by);

            return $this->show($locked);
        });
    }

    public function cancel(MaintenanceWorkOrder $wo, ?string $reason, User $by): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($wo, $reason, $by) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            $this->stateMachine->transition($locked, MaintenanceWorkOrderStatus::Cancelled);

            $locked->forceFill([
                'remarks' => $reason ?: $locked->remarks,
            ])->save();
            // Restore machine to idle if it was set to maintenance by us
            if ($locked->maintainable_type === MaintainableType::Machine) {
                $this->closeMachineDowntime($locked);
                $machine = Machine::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($machine && $machine->status?->value === 'maintenance') {
                    $this->machines->transitionStatus(
                        $machine,
                        MachineStatus::Idle,
                        'Maintenance work order '.$locked->mwo_number.' cancelled',
                    );
                }
            }
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
            $this->stateMachine->assertInProgress($locked, 'add a log entry');

            return $this->recordLifecycleLog($locked, $description, $by);
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

    private function closeMachineDowntime(MaintenanceWorkOrder $wo): void
    {
        $open = MachineDowntime::query()
            ->where('maintenance_order_id', $wo->id)
            ->whereNull('end_time')
            ->lockForUpdate()
            ->first();
        if (! $open) {
            return;
        }

        $end = now();
        $open->update([
            'end_time' => $end,
            'duration_minutes' => (int) max(0, $open->start_time->diffInMinutes($end, true)),
        ]);
    }
}
