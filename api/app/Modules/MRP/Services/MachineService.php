<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Support\SearchOperator;
use App\Modules\MRP\Enums\MachineStatus;
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

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Machine::query()->withCount('compatibleMolds');

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
        return DB::transaction(fn () => Machine::create($data));
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

    public function transitionStatus(Machine $m, MachineStatus $to): Machine
    {
        $from = $m->status?->value ?? 'idle';
        $allowed = self::ALLOWED[$from] ?? [];
        if (! in_array($to->value, $allowed, true)) {
            throw new IllegalStatusTransitionException($from, $to->value);
        }
        return DB::transaction(function () use ($m, $to) {
            $m->update(['status' => $to->value]);
            // TODO Sprint 6 Task 56: dispatch MachineStatusChanged event so the
            // breakdown listener can pause the active WO and open a downtime row.
            return $m->fresh();
        });
    }
}
