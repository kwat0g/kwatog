<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Modules\MRP\Models\MrpRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MrpRunService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = MrpRun::query()->with('user:id,name');

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['triggered_by'])) {
            $q->where('triggered_by', $filters['triggered_by']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        return $q->orderByDesc('run_at')->paginate($perPage);
    }

    public function latest(): ?MrpRun
    {
        return MrpRun::with('user:id,name')->orderByDesc('run_at')->first();
    }
}
