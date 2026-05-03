<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8 — Task 70. Monthly straight-line depreciation runner.
 *
 * Idempotent: UNIQUE (asset_id, period_year, period_month) means a re-run
 * for the same period skips already-recorded rows and posts no new JE.
 */
class DepreciationService
{
    public function __construct(
        private readonly JournalEntryService $journals,
    ) {}

    /**
     * @return array{posted_count:int, total_amount:string, journal_entry_id:?int}
     */
    public function runForMonth(int $year, int $month, User $by): array
    {
        return DB::transaction(function () use ($year, $month, $by) {
            $assets = Asset::query()
                ->whereIn('status', [AssetStatus::Active->value, AssetStatus::UnderMaintenance->value])
                ->where('acquisition_date', '<=', CarbonImmutable::create($year, $month, 1)->endOfMonth())
                ->get();

            $rows = [];
            $totalAmount = 0.0;

            foreach ($assets as $asset) {
                if (AssetDepreciation::where('asset_id', $asset->id)
                    ->where('period_year', $year)
                    ->where('period_month', $month)
                    ->exists()) {
                    continue; // idempotent — already done
                }

                $monthly = (float) $asset->monthly_depreciation;
                if ($monthly <= 0) continue;

                // Cap so we don't depreciate beyond cost - salvage
                $depreciable    = max(0.0, (float) $asset->acquisition_cost - (float) $asset->salvage_value);
                $alreadyAccum   = (float) $asset->accumulated_depreciation;
                $remaining      = max(0.0, $depreciable - $alreadyAccum);
                $thisMonth      = min($monthly, $remaining);
                if ($thisMonth <= 0) continue;

                $newAccum = $alreadyAccum + $thisMonth;
                $rows[] = [
                    'asset' => $asset,
                    'amount' => $thisMonth,
                    'accumulated_after' => $newAccum,
                ];
                $totalAmount += $thisMonth;
            }

            if (empty($rows)) {
                return ['posted_count' => 0, 'total_amount' => '0.00', 'journal_entry_id' => null];
            }

            // Post one consolidated JE for the period
            $depExp  = Account::where('code', '6080')->firstOrFail();
            $accDep  = Account::where('code', '1410')->firstOrFail();
            $lines = [
                ['account_id' => $depExp->id, 'debit'  => number_format($totalAmount, 2, '.', ''), 'credit' => '0.00', 'description' => 'Monthly depreciation'],
                ['account_id' => $accDep->id, 'debit'  => '0.00', 'credit' => number_format($totalAmount, 2, '.', ''),  'description' => 'Monthly depreciation'],
            ];
            $periodLabel = sprintf('%04d-%02d', $year, $month);
            $je = $this->journals->create([
                'date'           => CarbonImmutable::create($year, $month, 1)->endOfMonth()->toDateString(),
                'description'    => 'Asset depreciation — '.$periodLabel,
                'reference_type' => 'asset_depreciation',
                'reference_id'   => null,
                'lines'          => $lines,
            ], $by);
            $this->journals->post($je, $by);

            // Insert per-asset rows + bump accumulated_depreciation
            foreach ($rows as $row) {
                /** @var Asset $asset */
                $asset = $row['asset'];
                AssetDepreciation::create([
                    'asset_id'             => $asset->id,
                    'period_year'          => $year,
                    'period_month'         => $month,
                    'depreciation_amount'  => number_format($row['amount'], 2, '.', ''),
                    'accumulated_after'    => number_format($row['accumulated_after'], 2, '.', ''),
                    'journal_entry_id'     => $je->id,
                    'created_at'           => now(),
                ]);
                $asset->forceFill(['accumulated_depreciation' => number_format($row['accumulated_after'], 2, '.', '')])->save();
            }

            return [
                'posted_count'     => count($rows),
                'total_amount'     => number_format($totalAmount, 2, '.', ''),
                'journal_entry_id' => $je->id,
            ];
        });
    }
}
