<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Accounting\Models\BudgetActualsSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stages budget actual rebuilds durably. The minute is part of the key so a
 * repeated manual/scheduled run is allowed later, while concurrent duplicate
 * triggers in the same scheduler tick collapse to one request.
 */
class BudgetActualsSyncService
{
    public function __construct(
        private readonly OutboxService $outbox,
        private readonly BudgetFiscalYearResolver $fiscalYears,
    ) {}

    public function request(?int $fiscalYearId = null): OutboxMessage
    {
        $fiscalYear = $this->fiscalYears->resolve($fiscalYearId);
        $dedupeKey = 'budget-actuals:'.$fiscalYear->id.':'.now()->format('YmdHi');

        return DB::transaction(function () use ($fiscalYear, $dedupeKey): OutboxMessage {
            $outbox = $this->outbox->record(
                new BudgetActualsSyncRequested(
                    fiscalYearId: (int) $fiscalYear->id,
                    requestId: (string) Str::uuid(),
                ),
                dedupeKey: $dedupeKey,
            );
            $payload = is_array($outbox->payload) ? $outbox->payload : [];
            $requestId = (string) ($payload['requestId'] ?? '');
            if ($requestId === '') {
                throw new \RuntimeException('Budget actuals sync outbox payload has no request ID.');
            }

            BudgetActualsSyncRun::query()->firstOrCreate(
                ['outbox_id' => (string) $outbox->getKey()],
                [
                    'id' => (string) Str::uuid(),
                    'request_id' => $requestId,
                    'fiscal_year_id' => $fiscalYear->id,
                    'status' => BudgetActualsSyncRun::STATUS_QUEUED,
                    'queued_at' => now(),
                ],
            );

            return $outbox;
        });
    }

    public function runForRequest(string $requestId): ?BudgetActualsSyncRun
    {
        return BudgetActualsSyncRun::query()->where('request_id', $requestId)->first();
    }

    public function runForOutbox(string $outboxId): ?BudgetActualsSyncRun
    {
        return BudgetActualsSyncRun::query()->where('outbox_id', $outboxId)->first();
    }

    public function latest(?int $fiscalYearId = null): ?BudgetActualsSyncRun
    {
        $fiscalYear = $this->fiscalYears->resolve($fiscalYearId);

        return BudgetActualsSyncRun::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->latest('created_at')
            ->first();
    }
}
