<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Validation\ValidationException;

final class BudgetFiscalYearResolver
{
    /**
     * Resolve an explicit fiscal year or the one active for today's date.
     * Explicit IDs may target closed years for a deliberate historical rebuild;
     * an omitted target must never silently fall forward to a future year.
     */
    public function resolve(?int $fiscalYearId = null): FiscalYear
    {
        if ($fiscalYearId !== null) {
            if ($fiscalYearId < 1) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => 'The fiscal year must be a positive identifier.',
                ]);
            }

            $fiscalYear = FiscalYear::query()->find($fiscalYearId);
            if (! $fiscalYear) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => 'The selected fiscal year does not exist.',
                ]);
            }

            return $fiscalYear;
        }

        $fiscalYear = FiscalYear::query()
            ->active()
            ->current()
            ->orderByDesc('year')
            ->first();

        if (! $fiscalYear) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => 'No active fiscal year contains today\'s date.',
            ]);
        }

        return $fiscalYear;
    }
}
