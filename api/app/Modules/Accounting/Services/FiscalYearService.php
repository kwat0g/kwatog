<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class FiscalYearService
{
    /** Create a non-posting draft fiscal year after checking date overlap. */
    public function create(array $data): FiscalYear
    {
        return DB::transaction(function () use ($data): FiscalYear {
            $start = Carbon::parse((string) $data['start_date'])->toDateString();
            $end = Carbon::parse((string) $data['end_date'])->toDateString();
            $this->assertNoOverlap($start, $end);

            return FiscalYear::create([
                'year' => (int) $data['year'],
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'draft',
            ]);
        });
    }

    public function activate(FiscalYear $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($fiscalYear): FiscalYear {
            $locked = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->getKey());
            if ($locked->status !== 'draft') {
                throw new BusinessRuleException('Only a draft fiscal year can be activated.');
            }
            $this->assertNoOverlap($locked->start_date->toDateString(), $locked->end_date->toDateString(), $locked->id);
            $locked->forceFill(['status' => 'active'])->save();

            return $locked->fresh();
        });
    }

    public function close(FiscalYear $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($fiscalYear): FiscalYear {
            $locked = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->getKey());
            if ($locked->status !== 'active') {
                throw new BusinessRuleException('Only an active fiscal year can be closed.');
            }
            $locked->forceFill(['status' => 'closed'])->save();

            return $locked->fresh();
        });
    }

    private function assertNoOverlap(string $start, string $end, ?int $ignoreId = null): void
    {
        $query = FiscalYear::query()
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw new BusinessRuleException('Fiscal-year date ranges must not overlap.');
        }
    }
}
