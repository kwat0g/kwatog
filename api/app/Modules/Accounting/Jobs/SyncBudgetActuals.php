<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Jobs;

use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sync GL actuals from posted JournalEntryLine records into BudgetLineItem.
 *
 * For each BudgetLineItem in the given fiscal year, queries all posted journal
 * entry lines for the same GL account within the fiscal year's date range and
 * computes net movement (debit - credit). Updates BudgetLineItem.actual_total
 * with the computed sum.
 *
 * Scheduled monthly on the 1st at 03:00. Idempotent: re-running overwrites
 * actual_total with the current GL balance each time.
 */
class SyncBudgetActuals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 120;

    public int $tries = 1;
    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(
        private readonly ?int $fiscalYearId = null,
    ) {}

    public function handle(): void
    {
        $fiscalYear = $this->resolveFiscalYear();
        if (! $fiscalYear) {
            throw new \RuntimeException('[SyncBudgetActuals] No fiscal year found for the requested sync.');
        }

        $lineItems = BudgetLineItem::query()
            ->whereHas('budget', fn ($q) => $q->where('fiscal_year_id', $fiscalYear->id))
            ->with('budget.department')
            ->get();

        if ($lineItems->isEmpty()) {
            Log::info("[SyncBudgetActuals] No budget line items for fiscal year {$fiscalYear->id}.");

            return;
        }

        $processed = 0;

        DB::transaction(function () use ($lineItems, $fiscalYear, &$processed): void {
            foreach ($lineItems as $lineItem) {
                $netMovement = (float) JournalEntryLine::query()
                    ->where('account_id', $lineItem->account_id)
                    ->whereHas('journalEntry', function ($q) use ($fiscalYear) {
                        $q->where('status', 'posted')
                            ->whereBetween('date', [$fiscalYear->start_date, $fiscalYear->end_date]);
                    })
                    ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net_movement')
                    ->value('net_movement');

                $lineItem->actual_total = $netMovement;
                $lineItem->save();
                $processed++;
            }
        });

        $fiscalYearLabel = $fiscalYear->name ?? (string) $fiscalYear->id;
        Log::info("[SyncBudgetActuals] Synced {$processed} budget line item(s) for fiscal year {$fiscalYear->id} ({$fiscalYearLabel}).");
    }

    private function resolveFiscalYear(): ?FiscalYear
    {
        if ($this->fiscalYearId !== null) {
            return FiscalYear::find($this->fiscalYearId);
        }

        return FiscalYear::query()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->orderByDesc('year')
            ->first();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncBudgetActuals] job failed permanently.', [
            'fiscal_year_id' => $this->fiscalYearId,
            'error' => $exception->getMessage(),
        ]);
    }
}
