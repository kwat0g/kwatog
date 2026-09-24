<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxService;
use App\Modules\Assets\Events\MonthlyDepreciationRequested;
use App\Modules\Assets\Models\AssetDepreciationRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stage one monthly depreciation period through the durable outbox. */
class RequestMonthlyDepreciation extends Command
{
    protected $signature = 'assets:request-monthly-depreciation
        {--year= : Target year (requires --month)}
        {--month= : Target month 1..12 (requires --year)}
        {--force : Create a new request even when this period was already staged}';

    protected $description = 'Stage monthly asset depreciation durably (defaults to the previous month).';

    public function handle(OutboxService $outbox): int
    {
        $yearOption = $this->option('year');
        $monthOption = $this->option('month');

        if (($yearOption !== null) !== ($monthOption !== null)) {
            $this->error('Both --year and --month must be provided together.');

            return self::FAILURE;
        }

        if ($yearOption !== null) {
            $year = (int) $yearOption;
            $month = (int) $monthOption;
            if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
                $this->error('Target year must be 2020..2100 and target month must be 1..12.');

                return self::FAILURE;
            }
        } else {
            $target = CarbonImmutable::now()->subMonthNoOverflow();
            $year = $target->year;
            $month = $target->month;
        }

        if (CarbonImmutable::create($year, $month, 1)->startOfMonth()->gte(CarbonImmutable::now()->startOfMonth())) {
            $this->error('Depreciation can only be staged for a completed period.');

            return self::FAILURE;
        }

        $period = sprintf('%04d-%02d', $year, $month);
        $requestId = $period;
        $dedupeKey = 'assets-depreciation:'.$period;
        if ($this->option('force')) {
            $requestId .= ':'.Str::uuid();
            $dedupeKey .= ':'.$requestId;
        }

        /** @var OutboxMessage $message */
        $message = DB::transaction(function () use ($outbox, $year, $month, $requestId, $dedupeKey): OutboxMessage {
            AssetDepreciationRun::query()->insertOrIgnore([
                'period_year' => $year,
                'period_month' => $month,
                'posted_count' => 0,
                'total_amount' => '0.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $run = AssetDepreciationRun::query()
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->firstOrFail();

            return $outbox->record(
                new MonthlyDepreciationRequested($year, $month, $requestId),
                dedupeKey: $dedupeKey,
                chain: [
                    'chain' => 'assets',
                    'entity_type' => 'asset_depreciation_run',
                    'entity_id' => (int) $run->getKey(),
                    'entity_hash_id' => $run->hash_id,
                    'step' => 'monthly_depreciation',
                ],
            );
        });

        $this->info("Staged durable asset depreciation request for {$period} (outbox {$message->getKey()}).");

        return self::SUCCESS;
    }
}
