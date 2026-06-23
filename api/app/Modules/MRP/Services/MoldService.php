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
            ->with('product:id,part_number,name,unit_of_measure')
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

    /**
     * Commission a mold into service. Records the date, flips to Available, and
     * auto-creates a shot-based preventive-maintenance schedule.
     */
    public function commission(Mold $m, ?string $performedBy = null): Mold
    {
        return DB::transaction(function () use ($m, $performedBy) {
            $m->update([
                'commissioned_at' => now()->toDateString(),
                'status'          => MoldStatus::Available->value,
            ]);
            MoldHistory::create([
                'mold_id'             => $m->id,
                'event_type'          => MoldEventType::Created->value,
                'description'         => 'Mold commissioned into service.',
                'performed_by'        => $performedBy,
                'event_date'          => now()->toDateString(),
                'shot_count_at_event' => $m->current_shot_count,
            ]);

            // Auto-create a shot-based PM schedule when the Maintenance module
            // is present. Guarded so MRP doesn't hard-depend on Maintenance.
            $interval = (int) ($m->maintenance_frequency_shots ?? $m->max_shots_before_maintenance);
            if ($interval > 0 && class_exists(\App\Modules\Maintenance\Models\MaintenanceSchedule::class)) {
                \App\Modules\Maintenance\Models\MaintenanceSchedule::firstOrCreate(
                    [
                        'maintainable_type' => 'mold',
                        'maintainable_id'   => $m->id,
                        'interval_type'     => 'shots',
                    ],
                    [
                        'schedule_type'  => 'preventive',
                        'interval_value' => $interval,
                        'description'    => "Auto PM: {$m->name} every {$interval} shots",
                        'is_active'      => true,
                        'next_due_at'    => null,
                    ],
                );
            }

            return $m->fresh();
        });
    }

    /**
     * Decommission a mold (retire it from active service).
     */
    public function decommission(Mold $m, ?string $reason = null, ?string $performedBy = null): Mold
    {
        return DB::transaction(function () use ($m, $reason, $performedBy) {
            $m->update([
                'decommissioned_at' => now()->toDateString(),
                'status'            => MoldStatus::Retired->value,
            ]);
            MoldHistory::create([
                'mold_id'             => $m->id,
                'event_type'          => MoldEventType::Retired->value,
                'description'         => $reason
                    ? "Decommissioned: {$reason}"
                    : 'Mold decommissioned / retired.',
                'cost'                => null,
                'performed_by'        => $performedBy,
                'event_date'          => now()->toDateString(),
                'shot_count_at_event' => $m->current_shot_count,
            ]);

            // Deactivate any PM schedules.
            if (class_exists(\App\Modules\Maintenance\Models\MaintenanceSchedule::class)) {
                \App\Modules\Maintenance\Models\MaintenanceSchedule::query()
                    ->where('maintainable_type', 'mold')
                    ->where('maintainable_id', $m->id)
                    ->update(['is_active' => false]);
            }

            return $m->fresh();
        });
    }

    /**
     * Record a maintenance event with cost — increments the cumulative counters
     * and stamps last_maintenance_at. Used when a mold MWO completes.
     */
    public function recordMaintenance(Mold $m, float $cost = 0.0, ?string $description = null, ?string $performedBy = null): Mold
    {
        return DB::transaction(function () use ($m, $cost, $description, $performedBy) {
            MoldHistory::create([
                'mold_id'             => $m->id,
                'event_type'          => MoldEventType::MaintenanceCompleted->value,
                'description'         => $description ?? 'Maintenance completed.',
                'cost'                => number_format($cost, 2, '.', ''),
                'performed_by'        => $performedBy,
                'event_date'          => now()->toDateString(),
                'shot_count_at_event' => $m->current_shot_count,
            ]);
            $m->update([
                'last_maintenance_at'    => now()->toDateString(),
                'maintenance_count'      => (int) $m->maintenance_count + 1,
                'total_maintenance_cost' => number_format((float) $m->total_maintenance_cost + $cost, 2, '.', ''),
            ]);
            return $m->fresh();
        });
    }

    /**
     * Monthly maintenance-cost trend for the mold over the last N months.
     *
     * @return array<int, array{month: string, cost: string, events: int}>
     */
    public function costTrend(Mold $m, int $months = 12): array
    {
        $since = now()->subMonths($months - 1)->startOfMonth();

        return MoldHistory::query()
            ->where('mold_id', $m->id)
            ->where('event_date', '>=', $since->toDateString())
            ->selectRaw("to_char(event_date, 'YYYY-MM') as month, COALESCE(SUM(cost),0) as cost, COUNT(*) as events")
            ->groupByRaw("to_char(event_date, 'YYYY-MM')")
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month'  => $r->month,
                'cost'   => number_format((float) $r->cost, 2, '.', ''),
                'events' => (int) $r->events,
            ])
            ->all();
    }
}
