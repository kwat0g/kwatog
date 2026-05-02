<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
        $row->fill($data)->save();
        $this->bust($row->agency);
        return $row->fresh();
    }

    public function deactivate(GovernmentContributionTable $row): GovernmentContributionTable
    {
        $row->is_active = false;
        $row->save();
        $this->bust($row->agency);
        return $row->fresh();
    }

    public function activate(GovernmentContributionTable $row): GovernmentContributionTable
    {
        $row->is_active = true;
        $row->save();
        $this->bust($row->agency);
        return $row->fresh();
    }

    private function bust(ContributionAgency|string|null $agency): void
    {
        if (! $agency) return;
        $key = $agency instanceof ContributionAgency ? $agency->value : (string) $agency;
        Cache::forget("gov_table:{$key}:active");
    }
}
