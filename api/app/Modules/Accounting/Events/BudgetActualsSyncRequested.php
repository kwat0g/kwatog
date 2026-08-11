<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Durable request for a rerunnable budget-vs-actual rebuild. */
class BudgetActualsSyncRequested
{
    use Dispatchable;

    public function __construct(
        public readonly ?int $fiscalYearId,
        public readonly string $requestId,
    ) {}
}
