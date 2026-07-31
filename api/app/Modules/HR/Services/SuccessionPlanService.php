<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Enums\SuccessionStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Models\SuccessionPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SuccessionPlanService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = SuccessionPlan::query()
            ->with(['position:id,title', 'incumbent:id,first_name,last_name', 'successor:id,first_name,last_name']);

        foreach (['readiness', 'priority', 'status'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }

        // The SPA sends hashed ids; these columns are bigint. Decode before the
        // comparison or Postgres rejects the string outright (22P02). An
        // undecodable hash must match nothing, not widen the result set.
        if (! empty($filters['position_id'])) {
            $q->where('position_id', HashIdFilter::decode($filters['position_id'], Position::class) ?? 0);
        }
        if (! empty($filters['successor_id'])) {
            $q->where('successor_id', HashIdFilter::decode($filters['successor_id'], Employee::class) ?? 0);
        }

        return $q->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(array $data): SuccessionPlan
    {
        return DB::transaction(function () use ($data) {
            $plan = new SuccessionPlan();
            $plan->fill($data);
            $plan->status = SuccessionStatus::Active;
            $plan->created_by = Auth::id();
            $plan->save();

            return $plan->fresh(['position:id,title', 'incumbent:id,first_name,last_name', 'successor:id,first_name,last_name']);
        });
    }

    public function update(SuccessionPlan $plan, array $data): SuccessionPlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan->fill(array_intersect_key($data, array_flip([
                'position_id', 'incumbent_id', 'successor_id',
                'readiness', 'priority', 'development_notes', 'target_date',
            ])));

            if (isset($data['status'])) {
                $plan->status = SuccessionStatus::from($data['status']);
            }

            $plan->save();
            return $plan->fresh(['position:id,title', 'incumbent:id,first_name,last_name', 'successor:id,first_name,last_name']);
        });
    }

    public function delete(SuccessionPlan $plan): void
    {
        $plan->delete();
    }
}
