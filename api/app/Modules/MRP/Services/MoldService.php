<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MoldEventType;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MoldHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MoldService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Mold::query()
            ->with('product:id,part_number,name')
            ->withCount('compatibleMachines');

        if (! empty($filters['product_id'])) {
            $pid = HashIdFilter::decode($filters['product_id'], Product::class);
            if ($pid) $q->where('product_id', $pid);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (isset($filters['nearing_limit']) && filter_var($filters['nearing_limit'], FILTER_VALIDATE_BOOLEAN)) {
            $q->whereRaw('current_shot_count >= max_shots_before_maintenance * 0.80');
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('mold_code', SearchOperator::like(), "%{$term}%")
                   ->orWhere('name', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->orderBy('mold_code')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Mold $m): Mold
    {
        return $m->load([
            'product:id,part_number,name,unit_of_measure',
            'compatibleMachines:id,machine_code,name,tonnage',
        ]);
    }

    public function create(array $data): Mold
    {
        return DB::transaction(function () use ($data) {
            $mold = Mold::create($data);
            MoldHistory::create([
                'mold_id'             => $mold->id,
                'event_type'          => MoldEventType::Created->value,
                'description'         => 'Mold created in system.',
                'event_date'          => now()->toDateString(),
                'shot_count_at_event' => 0,
            ]);
            return $mold->fresh();
        });
    }

    public function update(Mold $m, array $data): Mold
    {
        return DB::transaction(function () use ($m, $data) {
            $m->update($data);
            return $m->fresh();
        });
    }

    public function delete(Mold $m): void
    {
        $m->delete();
    }

    /**
     * Replace the compatibility set for a mold. Receives an array of integer
     * machine IDs (already decoded from hash_ids by the FormRequest).
     */
    public function syncCompatibility(Mold $m, array $machineIds): Mold
    {
        return DB::transaction(function () use ($m, $machineIds) {
            $m->compatibleMachines()->sync($machineIds);
            return $this->show($m->fresh());
        });
    }

    /**
     * Atomic shot increment with row lock. Crossing 80% fires
     * MoldShotLimitNearing; crossing 100% flips status to Maintenance and
     * fires MoldShotLimitReached. Both events broadcast on
     * production.dashboard for live alerts (Sprint 6 audit §1.7).
     */
    public function incrementShots(Mold $m, int $count): Mold
    {
        $fresh = DB::transaction(function () use ($m, $count) {
            $row = Mold::lockForUpdate()->find($m->id);
            $beforePct = $row->shot_percentage;
            $row->current_shot_count = $row->current_shot_count + $count;
            $row->lifetime_total_shots = $row->lifetime_total_shots + $count;

            if ($row->current_shot_count >= $row->max_shots_before_maintenance) {
                $row->status = MoldStatus::Maintenance->value;
                MoldHistory::create([
                    'mold_id'             => $row->id,
                    'event_type'          => MoldEventType::ShotLimitReached->value,
                    'description'         => 'Shot limit reached — automatically flagged for maintenance.',
                    'event_date'          => now()->toDateString(),
                    'shot_count_at_event' => $row->current_shot_count,
                ]);
            }
            $row->save();
            $row->refresh();

            // Pass back the row + the previous percentage so the caller can
            // decide which broadcast events to dispatch *after* commit.
            return [$row, $beforePct];
        });

        [$row, $beforePct] = $fresh;
        $afterPct = $row->shot_percentage;

        // Crossing 80% threshold (forward-only). Use after-commit hook so
        // listeners and broadcasters see the persisted row.
        DB::afterCommit(function () use ($row, $beforePct, $afterPct) {
            if ($beforePct < 80.0 && $afterPct >= 80.0) {
                event(new \App\Modules\MRP\Events\MoldShotLimitNearing($row));
            }
            if ($row->current_shot_count >= $row->max_shots_before_maintenance) {
                event(new \App\Modules\MRP\Events\MoldShotLimitReached($row));
            }
        });

        return $row;
    }

    /** Reset shot count after maintenance. Archives the prior count to history. */
    public function resetShotCount(Mold $m, ?string $performedBy = null): Mold
    {
        return DB::transaction(function () use ($m, $performedBy) {
            MoldHistory::create([
                'mold_id'             => $m->id,
                'event_type'          => MoldEventType::MaintenanceCompleted->value,
                'description'         => "Reset after maintenance (count was {$m->current_shot_count}).",
                'performed_by'        => $performedBy,
                'event_date'          => now()->toDateString(),
                'shot_count_at_event' => $m->current_shot_count,
            ]);
            $m->update([
                'current_shot_count' => 0,
                'status'             => MoldStatus::Available->value,
            ]);
            return $m->fresh();
        });
    }
}
