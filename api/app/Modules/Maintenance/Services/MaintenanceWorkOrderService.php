<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

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
use App\Modules\MRP\Enums\MoldEventType;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MoldHistory;
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
                MaintainableType::Mold    => Mold::query()->whereKey($maintainableId)->exists(),
            };
            if (! $exists) {
                throw ValidationException::withMessages([
                    'maintainable_id' => ["Target {$type->value}#{$maintainableId} not found."],
                ]);
            }

            $wo = MaintenanceWorkOrder::create([
                'mwo_number'        => $this->sequences->generate('maintenance_wo'),
                'maintainable_type' => $type->value,
                'maintainable_id'   => $maintainableId,
                'schedule_id'       => $fromSchedule?->id,
                'type'              => $fromSchedule
                    ? MaintenanceWorkOrderType::Preventive->value
                    : MaintenanceWorkOrderType::from((string) ($data['type'] ?? $this->settings->get('maintenance.work_order.default_type', '')))->value,
                'priority'          => MaintenancePriority::from((string) ($data['priority'] ?? $this->settings->get('maintenance.work_order.default_priority', '')))->value,
                'description'       => $fromSchedule?->description ?? $data['description'],
                'status'            => MaintenanceWorkOrderStatus::Open->value,
                'created_by'        => $by->id,
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
        $this->assertNotTerminal($wo);
        return DB::transaction(function () use ($wo, $employeeId, $by) {
            $wo->forceFill([
                'assigned_to' => $employeeId,
                'status'      => MaintenanceWorkOrderStatus::Assigned->value,
            ])->save();
            $this->log($wo, 'Assigned to employee #'.$employeeId, $by);
            return $this->show($wo);
        });
    }

    public function start(MaintenanceWorkOrder $wo, User $by): MaintenanceWorkOrder
    {
        if ($wo->status === MaintenanceWorkOrderStatus::InProgress) return $this->show($wo);
        $this->assertNotTerminal($wo);
        return DB::transaction(function () use ($wo, $by) {
            $wo->forceFill([
                'status'     => MaintenanceWorkOrderStatus::InProgress->value,
                'started_at' => now(),
            ])->save();

            // Mark machine target as under maintenance
            if ($wo->maintainable_type === MaintainableType::Machine) {
                $machine = Machine::find($wo->maintainable_id);
                if ($machine && $machine->status?->value !== 'maintenance') {
                    $machine->forceFill(['status' => 'maintenance'])->save();
                }
            }
            if ($wo->maintainable_type === MaintainableType::Mold) {
                $mold = Mold::find($wo->maintainable_id);
                if ($mold) {
                    MoldHistory::create([
                        'mold_id'             => $mold->id,
                        'event_type'          => MoldEventType::MaintenanceStarted->value,
                        'description'         => $wo->description,
                        'performed_by'        => $by->name,
                        'event_date'          => now()->toDateString(),
                        'shot_count_at_event' => (int) $mold->current_shot_count,
                    ]);
                }
            }

            $this->log($wo, 'Maintenance started.', $by);
            return $this->show($wo);
        });
    }

    /**
     * @param array{remarks?: string|null, downtime_minutes?: int|null} $data
     */
    public function complete(MaintenanceWorkOrder $wo, array $data, User $by): MaintenanceWorkOrder
    {
        $this->assertNotTerminal($wo);
        return DB::transaction(function () use ($wo, $data, $by) {
            $cost = (string) SparePartUsage::query()->where('work_order_id', $wo->id)->sum('total_cost');

            $wo->forceFill([
                'status'           => MaintenanceWorkOrderStatus::Completed->value,
                'completed_at'     => now(),
                'downtime_minutes' => (int) ($data['downtime_minutes'] ?? 0),
                'cost'             => $cost,
                'remarks'          => $data['remarks'] ?? $wo->remarks,
            ])->save();

            // Mold: reset shot count, log history, accumulate lifecycle counters
            if ($wo->maintainable_type === MaintainableType::Mold) {
                $mold = Mold::find($wo->maintainable_id);
                if ($mold) {
                    $shotsBefore = (int) $mold->current_shot_count;
                    $mold->forceFill([
                        'current_shot_count'     => 0,
                        // Lifecycle manager: stamp + accumulate maintenance cost/count.
                        'last_maintenance_at'    => now()->toDateString(),
                        'maintenance_count'      => (int) $mold->maintenance_count + 1,
                        'total_maintenance_cost' => number_format((float) $mold->total_maintenance_cost + (float) $cost, 2, '.', ''),
                    ])->save();
                    MoldHistory::create([
                        'mold_id'             => $mold->id,
                        'event_type'          => MoldEventType::MaintenanceCompleted->value,
                        'description'         => $wo->description.' (shot count reset from '.$shotsBefore.')',
                        'cost'                => $cost,
                        'performed_by'        => $by->name,
                        'event_date'          => now()->toDateString(),
                        'shot_count_at_event' => 0,
                    ]);
                }
            }
            // Machine: restore to idle
            if ($wo->maintainable_type === MaintainableType::Machine) {
                $machine = Machine::find($wo->maintainable_id);
                if ($machine && $machine->status?->value === 'maintenance') {
                    $machine->forceFill(['status' => 'idle'])->save();
                }
            }

            // Recompute schedule next_due_at
            if ($wo->schedule_id) {
                $schedule = MaintenanceSchedule::find($wo->schedule_id);
                if ($schedule) $this->schedules->recomputeNextDueAt($schedule, now());
            }

            $this->log($wo, 'Maintenance completed.'.($cost > 0 ? ' Spare parts cost '.app(\App\Common\Services\CurrencyDisplayService::class)->format($cost).'.' : ''), $by);
            return $this->show($wo);
        });
    }

    public function cancel(MaintenanceWorkOrder $wo, ?string $reason, User $by): MaintenanceWorkOrder
    {
        $this->assertNotTerminal($wo);
        return DB::transaction(function () use ($wo, $reason, $by) {
            $wo->forceFill([
                'status'  => MaintenanceWorkOrderStatus::Cancelled->value,
                'remarks' => $reason ?: $wo->remarks,
            ])->save();
            // Restore machine to idle if it was set to maintenance by us
            if ($wo->maintainable_type === MaintainableType::Machine) {
                $machine = Machine::find($wo->maintainable_id);
                if ($machine && $machine->status?->value === 'maintenance') {
                    $machine->forceFill(['status' => 'idle'])->save();
                }
            }
            // Recompute schedule next_due_at on cancel so running-hours-based
            // schedules don't re-trigger immediately on the next cron run.
            if ($wo->schedule_id) {
                $schedule = MaintenanceSchedule::find($wo->schedule_id);
                if ($schedule) $this->schedules->recomputeNextDueAt($schedule, now());
            }
            $this->log($wo, 'Cancelled'.($reason ? ': '.$reason : '.'), $by);
            return $this->show($wo);
        });
    }

    public function log(MaintenanceWorkOrder $wo, string $description, User $by): MaintenanceLog
    {
        return MaintenanceLog::create([
            'work_order_id' => $wo->id,
            'description'   => $description,
            'logged_by'     => $by->id,
            'created_at'    => now(),
        ]);
    }

    /**
     * Guard the assign/start/complete/cancel transitions. Every one is reachable
     * from a live SPA button, so a double-click on an already-closed WO must
     * surface as a 422 naming the field — not an unhandled 500.
     */
    private function assertNotTerminal(MaintenanceWorkOrder $wo): void
    {
        if ($wo->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => ["This work order is already {$wo->status->value} and can no longer be modified."],
            ]);
        }
    }
}
