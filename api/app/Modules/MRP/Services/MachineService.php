<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Exceptions\IllegalStatusTransitionException;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Services\ProductionDashboardService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MachineService
{
    /**
     * Allowed status transitions. Sprint 6 Task 56 hooks side effects
     * (auto-pause WO, open downtime row) into Running → Breakdown.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'idle' => ['running', 'maintenance', 'breakdown', 'offline'],
        'running' => ['idle', 'breakdown', 'maintenance'],
        'breakdown' => ['maintenance', 'idle'],
        'maintenance' => ['idle', 'breakdown', 'offline'],
        'offline' => ['idle'],
    ];

    /** @return array<string, list<string>> */
    public static function allowedTransitions(): array
    {
        return self::ALLOWED;
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Machine::query()->with(['asset:id,asset_code,name'])->withCount('compatibleMolds');

        TrashedFilter::apply($q, $filters);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('machine_code', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhere('name', SearchOperator::like(), SearchOperator::contains($term));
            });
        }

        return $q->orderBy('machine_code')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Machine $m): Machine
    {
        return $m->load(['compatibleMolds:id,mold_code,name', 'asset:id,asset_code,name']);
    }

    public function create(array $data): Machine
    {
        return DB::transaction(function () use ($data) {
            // fresh() so column defaults (status idle, machine_type, ...) are
            // present on the returned instance — create() alone leaves the
            // in-memory model without them.
            return Machine::create($data)->fresh();
        });
    }

    public function update(Machine $m, array $data): Machine
    {
        return DB::transaction(function () use ($m, $data) {
            $m = Machine::query()->lockForUpdate()->findOrFail($m->id);
            if ($m->current_work_order_id && array_intersect(array_keys($data), ['tonnage', 'available_hours_per_day', 'machine_type', 'status'])) {
                throw new BusinessRuleException('Release active machine ownership before changing capacity.');
            }
            $m->update($data);

            return $m->fresh();
        });
    }

    public function delete(Machine $m): void
    {
        DB::transaction(function () use ($m): void {
            $machine = Machine::query()->lockForUpdate()->findOrFail($m->id);
            if ($machine->current_work_order_id) {
                throw new BusinessRuleException('Cannot archive an occupied machine.');
            }
            if (MaintenanceWorkOrder::where('maintainable_type', 'machine')->where('maintainable_id', $machine->id)->where('status', 'in_progress')->exists()) {
                throw new BusinessRuleException('Complete active maintenance before archiving equipment.');
            }
            if ($machine->asset_id !== null) {
                throw new BusinessRuleException('Disassociate this asset before archiving the machine.');
            }

            $machine->delete();
        });
    }

    public function transitionStatus(Machine $m, MachineStatus $to, ?string $reason = null): Machine
    {
        $changed = DB::transaction(function () use ($m, $to, $reason) {
            // Route model binding may have happened before another request
            // changed the machine. Re-read and lock the row before validating
            // the transition so scheduler/resource changes cannot race this
            // lifecycle boundary.
            $row = Machine::query()->lockForUpdate()->findOrFail($m->id);
            if ($row->current_work_order_id && in_array($to, [MachineStatus::Idle, MachineStatus::Maintenance], true)) {
                throw new BusinessRuleException('Pause and explicitly release owned production before changing machine service state.');
            }
            if ($to === MachineStatus::Idle && MaintenanceWorkOrder::query()->where('maintainable_type', 'machine')->where('maintainable_id', $row->id)->where('status', 'in_progress')->exists()) {
                throw new BusinessRuleException('Complete active maintenance before restoring the machine.');
            }
            $from = $row->status?->value ?? 'idle';
            $allowed = self::allowedTransitions()[$from] ?? [];
            if (! in_array($to->value, $allowed, true)) {
                throw new IllegalStatusTransitionException($from, $to->value);
            }

            $row->update(['status' => $to->value]);
            $changed = $row->fresh();

            // Durable publication is recorded with the status mutation. The
            // breakdown listener cannot be lost between commit and Redis
            // enqueue, and replay remains safe because it locks/rechecks.
            app(OutboxService::class)->record(
                new MachineStatusChanged($changed, $from, $to->value, $reason),
            );

            return $changed;
        });

        ProductionDashboardService::forgetCache();

        return $changed;
    }
}
