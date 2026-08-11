<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stages budget actual rebuilds durably. The minute is part of the key so a
 * repeated manual/scheduled run is allowed later, while concurrent duplicate
 * triggers in the same scheduler tick collapse to one request.
 */
class BudgetActualsSyncService
{
    public function __construct(private readonly OutboxService $outbox) {}

    public function request(?int $fiscalYearId = null): OutboxMessage
    {
        $target = $fiscalYearId === null ? 'active' : (string) $fiscalYearId;
        $dedupeKey = 'budget-actuals:'.$target.':'.now()->format('YmdHi');

        return DB::transaction(fn (): OutboxMessage => $this->outbox->record(
            new BudgetActualsSyncRequested(
                fiscalYearId: $fiscalYearId,
                requestId: (string) Str::uuid(),
            ),
            dedupeKey: $dedupeKey,
        ));
    }
}
