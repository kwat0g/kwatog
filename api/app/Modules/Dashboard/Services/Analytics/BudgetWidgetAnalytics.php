<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetConsumptionService;

/** Budget analytics: utilization as a gauge. New — Budgeting had only a ratio. */
final class BudgetWidgetAnalytics
{
    public function __construct(private readonly BudgetConsumptionService $budgetConsumption) {}

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

        $fiscalYear = FiscalYear::query()->active()->current()->orderByDesc('year')->first();
        $totals = $this->budgetConsumption->dashboardTotals($fiscalYear);
        $allocated = (float) $totals['allocated'];

        // Utilization of nothing is unknown, not 0% — the same rule the
        // scalar path applies (DashboardWidgetDataService::budgetUtilization).
        if ($allocated <= 0.0) {
            return [];
        }

        return [
            'value' => $totals['utilization_pct'],
            'target' => 100.0,
            'min' => 0.0,
            'max' => 100.0,
            'kind' => 'percent',
        ];
    }
}
