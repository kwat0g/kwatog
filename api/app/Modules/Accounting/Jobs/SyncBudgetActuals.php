<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Jobs;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\BudgetActualsSyncRun;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetConsumptionService;
use App\Modules\Accounting\Services\BudgetFiscalYearResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Rebuild live budget line actuals from the posted GL in bounded chunks. */
class SyncBudgetActuals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 120;

    public int $tries = 1;
    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(
        private readonly ?int $fiscalYearId = null,
        private readonly ?string $requestId = null,
    ) {}

    public function handle(
        ?BudgetConsumptionService $consumption = null,
        ?BudgetFiscalYearResolver $fiscalYears = null,
    ): void {
        $consumption ??= app(BudgetConsumptionService::class);
        $fiscalYears ??= app(BudgetFiscalYearResolver::class);
        $fiscalYear = $fiscalYears->resolve($this->fiscalYearId);
        $run = $this->markRunning($fiscalYear);

        try {
            $lineQuery = BudgetLineItem::query()
                ->whereHas('budget', fn ($query) => $query
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->whereIn('status', ['approved', 'active']));
            $totalLines = (clone $lineQuery)->count();
            $actuals = $consumption->lineActualsForFiscalYear((int) $fiscalYear->id);
            $processed = 0;

            if ($run) {
                $run->forceFill(['total_lines' => $totalLines])->save();
            }

            $lineQuery->orderBy('id')->chunkById(500, function (Collection $lines) use ($actuals, $run, &$processed): void {
                DB::transaction(function () use ($lines, $actuals, $run, &$processed): void {
                    foreach ($lines as $line) {
                        $actual = $actuals[(int) $line->getKey()] ?? Money::zero();
                        $line->forceFill([
                            'actual_total' => $actual,
                            'variance' => Money::sub((string) $line->annual_total, $actual),
                        ])->save();
                        $processed++;
                    }

                    if ($run) {
                        $run->forceFill(['processed_lines' => $processed])->save();
                    }
                });
            });

            $consumption->refreshHeadersForFiscalYear((int) $fiscalYear->id);
            if ($run) {
                $run->forceFill([
                    'status' => BudgetActualsSyncRun::STATUS_COMPLETED,
                    'processed_lines' => $processed,
                    'completed_at' => now(),
                    'last_error' => null,
                ])->save();
            }

            Log::info('[SyncBudgetActuals] Budget actuals sync completed.', [
                'fiscal_year_id' => $fiscalYear->id,
                'processed_lines' => $processed,
                'request_id' => $this->requestId,
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($exception);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed($exception);
        Log::error('[SyncBudgetActuals] job failed permanently.', [
            'fiscal_year_id' => $this->fiscalYearId,
            'request_id' => $this->requestId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function markRunning(FiscalYear $fiscalYear): ?BudgetActualsSyncRun
    {
        if (! $this->requestId) {
            return null;
        }

        return DB::transaction(function () use ($fiscalYear): BudgetActualsSyncRun {
            $run = BudgetActualsSyncRun::query()->lockForUpdate()->where('request_id', $this->requestId)->firstOrFail();
            $run->forceFill([
                'fiscal_year_id' => $fiscalYear->id,
                'status' => BudgetActualsSyncRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?? now(),
                'last_error' => null,
            ])->save();

            return $run;
        });
    }

    private function markFailed(Throwable $exception): void
    {
        if (! $this->requestId) {
            return;
        }

        BudgetActualsSyncRun::query()
            ->where('request_id', $this->requestId)
            ->update([
                'status' => BudgetActualsSyncRun::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 10000),
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
