<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GovernmentContributionTableService
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Active brackets for an agency, ordered by bracket_min asc.
     *
     * Cached for hot-path payroll computation. Bust on update/deactivate.
     *
     * @return Collection<int, GovernmentContributionTable>
     */
    public function activeBrackets(string|ContributionAgency $agency): Collection
    {
        $key = $agency instanceof ContributionAgency ? $agency->value : $agency;

        return Cache::remember(
            "gov_table:{$key}:active",
            self::CACHE_TTL,
            fn () => GovernmentContributionTable::query()
                ->agency($key)
                ->active()
                ->orderBy('bracket_min')
                ->get(),
        );
    }

    /**
     * Brackets in force on a given date: the rows whose effective_date is the
     * latest <= $date for the agency. Falls back to the active set when no
     * dated rows are on/before $date (preserves legacy behaviour for agencies
     * seeded without history).
     *
     * @return Collection<int, GovernmentContributionTable>
     */
    public function bracketsEffectiveOn(string|ContributionAgency $agency, Carbon|string $date): Collection
    {
        $key = $agency instanceof ContributionAgency ? $agency->value : $agency;
        $on  = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $ver = (int) Cache::get("gov_table:{$key}:ver", 1);

        return Cache::remember(
            "gov_table:{$key}:v{$ver}:eff:{$on}",
            self::CACHE_TTL,
            function () use ($key, $on) {
                $effective = GovernmentContributionTable::query()
                    ->agency($key)
                    ->whereDate('effective_date', '<=', $on)
                    ->max('effective_date');

                if ($effective === null) {
                    return GovernmentContributionTable::query()
                        ->agency($key)->active()->orderBy('bracket_min')->get();
                }

                return GovernmentContributionTable::query()
                    ->agency($key)
                    ->whereDate('effective_date', $effective)
                    ->orderBy('bracket_min')
                    ->get();
            },
        );
    }

    /**
     * @return Collection<int, GovernmentContributionTable>
     */
    public function list(string|ContributionAgency $agency): Collection
    {
        $key = $agency instanceof ContributionAgency ? $agency->value : $agency;

        return GovernmentContributionTable::query()
            ->agency($key)
            ->orderBy('is_active', 'desc')
            ->orderBy('effective_date', 'desc')
            ->orderBy('bracket_min')
            ->get();
    }

    public function update(GovernmentContributionTable $row, array $data): GovernmentContributionTable
    {
        return DB::transaction(function () use ($row, $data) {
            $row->fill($data)->save();
            $this->bust($row->agency);
            return $row->fresh();
        });
    }

    public function deactivate(GovernmentContributionTable $row): GovernmentContributionTable
    {
        return DB::transaction(function () use ($row) {
            $row->is_active = false;
            $row->save();
            $this->bust($row->agency);
            return $row->fresh();
        });
    }

    public function activate(GovernmentContributionTable $row): GovernmentContributionTable
    {
        return DB::transaction(function () use ($row) {
            $row->is_active = true;
            $row->save();
            $this->bust($row->agency);
            return $row->fresh();
        });
    }

    private function bust(ContributionAgency|string|null $agency): void
    {
        if (! $agency) return;
        $key = $agency instanceof ContributionAgency ? $agency->value : (string) $agency;
        Cache::forget("gov_table:{$key}:active");
        Cache::put("gov_table:{$key}:ver", ((int) Cache::get("gov_table:{$key}:ver", 1)) + 1, 86400);
    }
}
