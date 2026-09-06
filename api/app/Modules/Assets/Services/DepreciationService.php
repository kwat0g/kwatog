<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Models\AssetDepreciationRun;
use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly fixed-asset depreciation runner.
 *
 * Each asset row is rounded once to centavos, and the consolidated journal is
 * built from those exact same rows. Period execution is serialized by the
 * locked asset set and recorded in asset_depreciation_runs so a retry cannot
 * create a second journal for an already-posted period.
 *
 * A posted period is repairable, not terminal: when an asset the original run
 * could not see (a backdated acquisition) lacks rows in a posted month,
 * `runForMonth` posts one supplemental journal covering only the missing
 * asset-months, and `runBackfillTo()` drives that engine chronologically so
 * `assertPriorPeriodsComplete` clears instead of trapping every later run.
 * A charge that legitimately rounds to 0.00 while a residual remains (the
 * declining-balance tail) is posted as a zero row so the completeness guard
 * stays satisfiable.
 */
class DepreciationService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{posted_count:int, total_amount:string, journal_entry_id:?int}
     */
    public function runForMonth(int $year, int $month, User $by, bool $allowBackfill = false): array
    {
        $this->assertSupportedPeriod($year, $month);

        return DB::transaction(function () use ($year, $month, $by, $allowBackfill): array {
            $periodStart = CarbonImmutable::create($year, $month, 1)->startOfMonth();
            $periodEnd = $periodStart->endOfMonth();
            $assets = $this->assetsInServiceFor($periodStart, $periodEnd);

            if (! $allowBackfill) {
                $this->assertPriorPeriodsComplete($assets, $periodStart);
            }

            $run = $this->lockOrCreateRun($year, $month);
            $existing = AssetDepreciation::query()
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->get();
            $existingByAsset = $existing->keyBy(fn (AssetDepreciation $row): int => (int) $row->asset_id);

            if ($run->journal_entry_id !== null) {
                $this->assertRunRowsMatch($existing, $run);

                $pending = $this->pendingRows($assets, $existingByAsset);
                if ($pending === []) {
                    return [
                        'posted_count' => (int) $run->posted_count,
                        'total_amount' => (string) $run->total_amount,
                        'journal_entry_id' => (int) $run->journal_entry_id,
                    ];
                }

                return $this->postSupplementalRows($pending, $year, $month, $periodEnd, $by);
            }

            $this->assertLegacyRowsSound($existing);

            $rows = [];
            $totalAmount = Money::zero();

            foreach ($assets as $asset) {
                if ($existingByAsset->has((int) $asset->getKey())) {
                    continue;
                }

                $row = $this->pendingRow($asset);
                if ($row === null) {
                    continue;
                }

                $rows[] = $row;
                $totalAmount = Money::add($totalAmount, $row['amount']);
            }

            if ($rows === []) {
                // A zero-value period has no journal identity to reconcile.
                // Do not retain an empty run marker that could mask a later
                // asset acquisition/backfill for the same period.
                $run->delete();

                return ['posted_count' => 0, 'total_amount' => Money::zero(), 'journal_entry_id' => null];
            }

            $je = $this->insertRowsAndJournal($rows, $totalAmount, $year, $month, $periodEnd, $by, false);

            $run->forceFill([
                'journal_entry_id' => $je?->id,
                'posted_count' => count($rows),
                'total_amount' => $totalAmount,
            ])->save();

            return [
                'posted_count' => count($rows),
                'total_amount' => $totalAmount,
                'journal_entry_id' => $je?->id,
            ];
        });
    }

    /**
     * Explicit rebuild path. It walks from the earliest asset acquisition
     * month to the requested completed period, making out-of-order work
     * deliberate and chronological instead of silently using a current
     * accumulated balance for an arbitrary historical month.
     *
     * @return array{posted_count:int, total_amount:string, journal_entry_id:?int, processed_periods:int}
     */
    public function runBackfillTo(int $year, int $month, User $by): array
    {
        $this->assertSupportedPeriod($year, $month);

        $periodEnd = CarbonImmutable::create($year, $month, 1)->endOfMonth();
        $earliestAcquisition = Asset::query()
            ->whereDate('acquisition_date', '<=', $periodEnd->toDateString())
            ->min('acquisition_date');

        if ($earliestAcquisition === null) {
            return [
                'posted_count' => 0,
                'total_amount' => Money::zero(),
                'journal_entry_id' => null,
                'processed_periods' => 0,
            ];
        }

        $cursor = CarbonImmutable::parse((string) $earliestAcquisition)->startOfMonth();
        $target = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $last = ['posted_count' => 0, 'total_amount' => Money::zero(), 'journal_entry_id' => null];
        $processed = 0;

        while ($cursor->lte($target)) {
            $last = $this->runForMonth($cursor->year, $cursor->month, $by, true);
            $processed++;
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $last + ['processed_periods' => $processed];
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assetsInServiceFor(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Collection
    {
        return Asset::query()
            ->whereDate('acquisition_date', '<=', $periodEnd->toDateString())
            ->where(function ($query) use ($periodStart): void {
                $query
                    ->where('status', '!=', AssetStatus::Disposed->value)
                    ->orWhere(function ($disposed) use ($periodStart): void {
                        $disposed
                            ->where('status', AssetStatus::Disposed->value)
                            ->whereDate('disposed_date', '>=', $periodStart->toDateString());
                    });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param Collection<int, Asset> $assets
     */
    private function assertPriorPeriodsComplete(Collection $assets, CarbonImmutable $periodStart): void
    {
        $previousPeriod = $periodStart->subMonthNoOverflow()->startOfMonth();

        foreach ($assets as $asset) {
            $depreciable = Money::clampMin(
                Money::sub((string) $asset->acquisition_cost, (string) $asset->salvage_value),
                Money::zero(),
            );
            if (! Money::gt($depreciable, Money::zero())
                || ! Money::lt((string) $asset->accumulated_depreciation, $depreciable)) {
                continue;
            }

            $cursor = CarbonImmutable::parse($asset->acquisition_date->toDateString())->startOfMonth();
            while ($cursor->lte($previousPeriod)) {
                if ($this->assetWasInServiceFor($asset, $cursor)) {
                    $complete = AssetDepreciation::query()
                        ->where('asset_id', $asset->getKey())
                        ->where('period_year', $cursor->year)
                        ->where('period_month', $cursor->month)
                        ->where(function ($query): void {
                            // A charge that legitimately rounds to 0.00 while a
                            // residual remains carries no journal of its own;
                            // the row itself is the completeness evidence.
                            $query
                                ->whereNotNull('journal_entry_id')
                                ->orWhere('depreciation_amount', '0.00');
                        })
                        ->exists();
                    if (! $complete) {
                        throw new BusinessRuleException(sprintf(
                            'Depreciation period %04d-%02d is missing for asset %s; run the explicit backfill workflow first.',
                            $cursor->year,
                            $cursor->month,
                            $asset->asset_code,
                        ));
                    }
                }
                $cursor = $cursor->addMonthNoOverflow();
            }
        }
    }

    /**
     * @return array{asset:Asset, amount:string, accumulated_after:string}|null
     */
    private function calculateRow(Asset $asset): ?array
    {
        $monthly = (string) $asset->monthly_depreciation;
        $depreciable = Money::clampMin(
            Money::sub((string) $asset->acquisition_cost, (string) $asset->salvage_value),
            Money::zero(),
        );
        $alreadyAccumulated = Money::round2((string) $asset->accumulated_depreciation);
        $remaining = Money::clampMin(Money::sub($depreciable, $alreadyAccumulated), Money::zero());

        if (! Money::gt($monthly, Money::zero()) || ! Money::gt($remaining, Money::zero())) {
            return null;
        }

        $amount = Money::lt($monthly, $remaining) ? Money::round2($monthly) : $remaining;

        return [
            'asset' => $asset,
            'amount' => $amount,
            'accumulated_after' => Money::add($alreadyAccumulated, $amount),
        ];
    }

    private function assetWasInServiceFor(Asset $asset, CarbonImmutable $periodStart): bool
    {
        $periodEnd = $periodStart->endOfMonth();
        if ($asset->acquisition_date->gt($periodEnd)) {
            return false;
        }

        return $asset->status !== AssetStatus::Disposed
            || $asset->disposed_date === null
            || $asset->disposed_date->gte($periodStart);
    }

    private function lockOrCreateRun(int $year, int $month): AssetDepreciationRun
    {
        AssetDepreciationRun::query()->insertOrIgnore([
            'period_year' => $year,
            'period_month' => $month,
            'posted_count' => 0,
            'total_amount' => Money::zero(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AssetDepreciationRun::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Journal identity for rows that predate the run marker. A nonzero row
     * without a journal, or nonzero rows split across journals, is corruption
     * and must be repaired by hand; a zero row may legitimately be
     * journal-less because a zero-value period has nothing to post.
     *
     * @param Collection<int, AssetDepreciation> $existing
     */
    private function assertLegacyRowsSound(Collection $existing): void
    {
        if ($existing->isEmpty()) {
            return;
        }

        $nonzero = $existing->filter(fn (AssetDepreciation $row): bool => ! Money::isZero((string) $row->depreciation_amount));

        if ($nonzero->contains(fn (AssetDepreciation $row): bool => $row->journal_entry_id === null)
            || $nonzero->pluck('journal_entry_id')->unique()->count() > 1) {
            throw new BusinessRuleException('Depreciation period has incomplete journal identity; repair it before rerunning.');
        }
    }

    /**
     * The rows this invocation still owes for the period: assets in service
     * that have no row yet.
     *
     * @param Collection<int, Asset> $assets
     * @param Collection<int, AssetDepreciation> $existingByAsset
     * @return array<int, array{asset:Asset, amount:string, accumulated_after:string}>
     */
    private function pendingRows(Collection $assets, Collection $existingByAsset): array
    {
        $rows = [];

        foreach ($assets as $asset) {
            if ($existingByAsset->has((int) $asset->getKey())) {
                continue;
            }

            $row = $this->pendingRow($asset);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array{asset:Asset, amount:string, accumulated_after:string}|null
     */
    private function pendingRow(Asset $asset): ?array
    {
        $row = $this->calculateRow($asset);
        if ($row !== null) {
            return $row;
        }

        // calculateRow refused because nothing remains; a monthly charge that
        // rounds to 0.00 while a residual remains still owes a zero row, or
        // the completeness guard would demand a month that can never be
        // posted and every later run would refuse.
        $monthly = (string) $asset->monthly_depreciation;
        if (Money::gt($monthly, Money::zero())) {
            return null;
        }

        $depreciable = Money::clampMin(
            Money::sub((string) $asset->acquisition_cost, (string) $asset->salvage_value),
            Money::zero(),
        );
        $remaining = Money::clampMin(Money::sub($depreciable, Money::round2((string) $asset->accumulated_depreciation)), Money::zero());
        if (! Money::gt($remaining, Money::zero())) {
            return null;
        }

        return [
            'asset' => $asset,
            'amount' => Money::zero(),
            'accumulated_after' => Money::round2((string) $asset->accumulated_depreciation),
        ];
    }

    /**
     * Repair arm for an already-posted period: post one supplemental journal
     * covering only the asset-months the period's original run could not see,
     * so the original consolidated entry is never edited. The run's own
     * summary keeps describing its own journal; the return describes what
     * this repair posted.
     *
     * @param array<int, array{asset:Asset, amount:string, accumulated_after:string}> $pending
     * @return array{posted_count:int, total_amount:string, journal_entry_id:?int}
     */
    private function postSupplementalRows(array $pending, int $year, int $month, CarbonImmutable $periodEnd, User $by): array
    {
        $totalAmount = Money::zero();
        foreach ($pending as $row) {
            $totalAmount = Money::add($totalAmount, $row['amount']);
        }

        $je = $this->insertRowsAndJournal($pending, $totalAmount, $year, $month, $periodEnd, $by, true);

        return [
            'posted_count' => count($pending),
            'total_amount' => $totalAmount,
            'journal_entry_id' => $je?->id,
        ];
    }

    /**
     * Insert already-computed rows with their journal (skipped when the
     * charges total zero — a zero-value period has no journal identity) and
     * advance each asset's register balance to its row's accumulated_after.
     *
     * @param array<int, array{asset:Asset, amount:string, accumulated_after:string}> $rows
     */
    private function insertRowsAndJournal(
        array $rows,
        string $totalAmount,
        int $year,
        int $month,
        CarbonImmutable $periodEnd,
        User $by,
        bool $supplemental,
    ): ?JournalEntry {
        $je = null;

        if (Money::gt($totalAmount, Money::zero())) {
            $depExp = Account::where('code', $this->settings->requiredString('accounting.accounts.depreciation_expense_code'))->firstOrFail();
            $accDep = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_accumulated_depreciation_code'))->firstOrFail();
            $periodLabel = sprintf('%04d-%02d', $year, $month);
            $je = $this->journals->create([
                'date' => $periodEnd->toDateString(),
                'description' => 'Asset depreciation — '.$periodLabel.($supplemental ? ' (supplemental backfill)' : ''),
                'reference_type' => 'asset_depreciation',
                'reference_id' => null,
                'lines' => [
                    ['account_id' => $depExp->id, 'debit' => $totalAmount, 'credit' => Money::zero(), 'description' => 'Monthly depreciation'],
                    ['account_id' => $accDep->id, 'debit' => Money::zero(), 'credit' => $totalAmount, 'description' => 'Monthly depreciation'],
                ],
            ], $by);
            $this->journals->post($je, $by);
        }

        foreach ($rows as $row) {
            AssetDepreciation::create([
                'asset_id' => $row['asset']->id,
                'period_year' => $year,
                'period_month' => $month,
                'depreciation_amount' => $row['amount'],
                'accumulated_after' => $row['accumulated_after'],
                'journal_entry_id' => $je?->id,
                'created_at' => now(),
            ]);
            $row['asset']->forceFill(['accumulated_depreciation' => $row['accumulated_after']])->save();
        }

        return $je;
    }

    /**
     * The run's own journal is the reconciliation anchor: rows posted by a
     * supplemental backfill or a disposal catch-up carry a different journal
     * and are legitimate neighbours of the run's rows for the same period,
     * not corruption.
     *
     * @param Collection<int, AssetDepreciation> $rows
     */
    private function assertRunRowsMatch(Collection $rows, AssetDepreciationRun $run): void
    {
        $posted = $rows->filter(fn (AssetDepreciation $row): bool => (int) $row->journal_entry_id === (int) $run->journal_entry_id);

        if ($posted->isEmpty()) {
            throw new BusinessRuleException('Depreciation run identity has no matching asset rows.');
        }

        $total = Money::zero();
        foreach ($posted as $row) {
            $total = Money::add($total, (string) $row->depreciation_amount);
        }
        if ($posted->count() !== (int) $run->posted_count
            || Money::cmp($total, (string) $run->total_amount) !== 0) {
            throw new BusinessRuleException('Depreciation run summary does not reconcile with its asset rows.');
        }
    }

    private function assertSupportedPeriod(int $year, int $month): void
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new BusinessRuleException('Depreciation period must use a year from 2020 to 2100 and a month from 1 to 12.');
        }

        $target = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        if ($target->gte(CarbonImmutable::now()->startOfMonth())) {
            throw new BusinessRuleException('Depreciation can only be posted for a completed period.');
        }
    }
}
