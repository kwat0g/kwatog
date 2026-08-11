<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** Budget analytics: utilization as a gauge. New — Budgeting had only a ratio. */
final class BudgetWidgetAnalytics
{
    /** @return list<string> */
    public function handles(): array
    {
        return ['budget.utilization'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'budget.utilization') {
            return [];
        }

        $row = DB::table('budgets')
            ->whereIn('status', ['approved', 'active'])
            ->selectRaw('COALESCE(SUM(total_allocated), 0) AS allocated, COALESCE(SUM(total_spent), 0) AS spent')
            ->first();

        $allocated = (float) ($row->allocated ?? 0);

        // Utilization of nothing is unknown, not 0% — the same rule the
        // scalar path applies (DashboardWidgetDataService::budgetUtilization).
        if ($allocated <= 0.0) {
            return [];
        }

        return [
            'value' => round(((float) $row->spent / $allocated) * 100, 1),
            'target' => 100.0,
            'min' => 0.0,
            'max' => 100.0,
            'kind' => 'percent',
        ];
    }
}
