<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\OutboxService;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Exceptions\IllegalStatusTransitionException;
use App\Modules\MRP\Models\Machine;
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
        'idle'        => ['running', 'maintenance', 'breakdown', 'offline'],
        'running'     => ['idle', 'breakdown', 'maintenance'],
        'breakdown'   => ['maintenance', 'idle'],
        'maintenance' => ['idle'],
        'offline'     => ['idle'],
    ];

    /** @return array<string, list<string>> */
    public static function allowedTransitions(): array
    {
        return self::ALLOWED;
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Machine::query()->withCount('compatibleMolds');

        TrashedFilter::apply($q, $filters);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('machine_code', SearchOperator::like(), "%{$term}%")
                   ->orWhere('name', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->orderBy('machine_code')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Machine $m): Machine
    {
        return $m->load(['compatibleMolds:id,mold_code,name']);
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
            $m->update($data);
            return $m->fresh();
        });
    }

    public function delete(Machine $m): void
    {
        $m->delete();
    }

    public function transitionStatus(Machine $m, MachineStatus $to, ?string $reason = null): Machine
    {
        return DB::transaction(function () use ($m, $to, $reason) {
            // Route model binding may have happened before another request
            // changed the machine. Re-read and lock the row before validating
            // the transition so scheduler/resource changes cannot race this
            // lifecycle boundary.
            $row = Machine::query()->lockForUpdate()->findOrFail($m->id);
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
    }
}
