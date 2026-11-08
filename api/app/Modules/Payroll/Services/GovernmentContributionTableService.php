<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\CacheGeneration;
use App\Common\Support\FailOpenCache;
use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GovernmentContributionTableService
{
    private const CACHE_TTL = 300; // 5 minutes

    private const CACHE_VERSION_TTL = 604800;

    private const CACHE_FORMAT_VERSION = 2;

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
        $version = $this->cacheVersion($key);

        return $this->rememberBrackets(
            "gov_table:{$key}:rows-v".self::CACHE_FORMAT_VERSION.":v{$version}:active",
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
        $on = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $ver = $this->cacheVersion($key);

        return $this->rememberBrackets(
            "gov_table:{$key}:rows-v".self::CACHE_FORMAT_VERSION.":v{$ver}:eff:{$on}",
            function () use ($key, $on) {
                $effective = GovernmentContributionTable::query()
                    ->agency($key)
                    ->active()
                    ->whereDate('effective_date', '<=', $on)
                    ->max('effective_date');

                if ($effective === null) {
                    return GovernmentContributionTable::query()
                        ->agency($key)->active()->orderBy('bracket_min')->get();
                }

                return GovernmentContributionTable::query()
                    ->agency($key)
                    ->active()
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
            $locked = GovernmentContributionTable::query()->lockForUpdate()->findOrFail($row->id);
            $locked->fill($data);
            if ($locked->is_active) {
                $this->assertNoOverlap($locked);
            }
            $locked->save();
            $this->stageChange($locked->agency, 'updated', $locked->updated_at?->format('Y-m-d\TH:i:s.uP'));

            return $locked->fresh();
        });
    }

    public function deactivate(GovernmentContributionTable $row): GovernmentContributionTable
    {
        return DB::transaction(function () use ($row) {
            $locked = GovernmentContributionTable::query()->lockForUpdate()->findOrFail($row->id);
            $locked->is_active = false;
            $locked->save();
            $this->stageChange($locked->agency, 'status_changed', $locked->updated_at?->format('Y-m-d\TH:i:s.uP'));

            return $locked->fresh();
        });
    }

    public function activate(GovernmentContributionTable $row): GovernmentContributionTable
    {
        return DB::transaction(function () use ($row) {
            $locked = GovernmentContributionTable::query()->lockForUpdate()->findOrFail($row->id);
            $locked->is_active = true;
            $this->assertNoOverlap($locked);
            $locked->save();
            $this->stageChange($locked->agency, 'status_changed', $locked->updated_at?->format('Y-m-d\TH:i:s.uP'));

            return $locked->fresh();
        });
    }

    public function delete(GovernmentContributionTable $row): void
    {
        DB::transaction(function () use ($row): void {
            $locked = GovernmentContributionTable::query()->lockForUpdate()->findOrFail($row->id);
            $agency = $locked->agency;
            $locked->delete();
            $this->stageChange($agency, 'deleted', now()->format('Y-m-d\TH:i:s.uP'));
        });
    }

    public function restore(GovernmentContributionTable $row): GovernmentContributionTable
    {
        return DB::transaction(function () use ($row): GovernmentContributionTable {
            $locked = GovernmentContributionTable::withTrashed()
                ->lockForUpdate()
                ->findOrFail($row->id);
            $locked->restore();
            $this->stageChange($locked->agency, 'updated', $locked->updated_at?->format('Y-m-d\TH:i:s.uP'));

            return $locked->fresh();
        });
    }

    /** Rotate all statutory table generations after replacing the source database. */
    public function invalidateForDatabaseRestore(): bool
    {
        $rotated = true;
        foreach (ContributionAgency::values() as $agency) {
            $rotated = $this->bust($agency) && $rotated;
        }

        return $rotated;
    }

    private function assertNoOverlap(GovernmentContributionTable $candidate): void
    {
        if (bccomp((string) $candidate->bracket_max, (string) $candidate->bracket_min, 2) < 0) {
            throw new BusinessRuleException('Bracket maximum must be greater than or equal to bracket minimum.');
        }

        $agency = $candidate->getRawOriginal('agency');
        $effectiveDate = $candidate->getRawOriginal('effective_date');

        $overlap = GovernmentContributionTable::query()
            ->where('agency', $agency)
            ->whereDate('effective_date', $effectiveDate)
            ->where('is_active', true)
            ->whereKeyNot($candidate->id)
            ->get(['id', 'bracket_min', 'bracket_max'])
            ->first(function (GovernmentContributionTable $other) use ($candidate): bool {
                return bccomp((string) $candidate->bracket_min, (string) $other->bracket_max, 2) <= 0
                    && bccomp((string) $other->bracket_min, (string) $candidate->bracket_max, 2) <= 0;
            });

        if ($overlap) {
            throw new BusinessRuleException(sprintf(
                'Bracket %s-%s overlaps active bracket %s-%s for %s on %s.',
                $candidate->bracket_min,
                $candidate->bracket_max,
                $overlap->bracket_min,
                $overlap->bracket_max,
                $agency,
                $effectiveDate,
            ));
        }
    }

    private function bust(ContributionAgency|string|null $agency): bool
    {
        if (! $agency) {
            return true;
        }
        $key = $agency instanceof ContributionAgency ? $agency->value : (string) $agency;
        $rotated = CacheGeneration::rotateDefault(
            "gov_table:{$key}:ver",
            self::CACHE_VERSION_TTL,
            'payroll.government_tables.'.$key,
        );

        try {
            Cache::forget("gov_table:{$key}:active");
        } catch (Throwable $exception) {
            // The generation is already rotated; this removes only a legacy key.
            Log::warning('Government contribution legacy cache cleanup failed after commit.', [
                'agency' => $key,
                'exception' => $exception::class,
            ]);
        }

        return $rotated;
    }

    private function stageChange(ContributionAgency|string $agency, string $action, ?string $version): void
    {
        // Cache generation rotates first after commit so an event-driven
        // refetch cannot repopulate/read the prior statutory bracket set.
        DB::afterCommit(fn () => $this->bust($agency));
        app(GovernmentTableRealtimeService::class)->stage($action, $version);
    }

    /**
     * Cache only database attribute arrays; callers still receive hydrated
     * models, preserving the service contract without serializing Eloquent
     * state, relations, or connection metadata into Redis.
     *
     * @param  Closure(): Collection<int, GovernmentContributionTable>  $load
     * @return Collection<int, GovernmentContributionTable>
     */
    private function rememberBrackets(string $key, Closure $load): Collection
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = FailOpenCache::remember(
            Cache::store(),
            $key,
            self::CACHE_TTL,
            static fn (): array => $load()
                ->map(static fn (GovernmentContributionTable $row): array => $row->getAttributes())
                ->all(),
            'payroll.government_contribution_tables',
        );

        return collect($rows)->map(static function (array $attributes): GovernmentContributionTable {
            $row = new GovernmentContributionTable;
            $row->setRawAttributes($attributes, true);
            $row->exists = true;

            return $row;
        });
    }

    private function cacheVersion(string $agency): string
    {
        $key = "gov_table:{$agency}:ver";

        return CacheGeneration::currentDefault(
            $key, self::CACHE_VERSION_TTL, 'payroll.government_tables');
    }
}
