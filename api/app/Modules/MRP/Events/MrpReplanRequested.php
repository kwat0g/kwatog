<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Durable request to replan a known set of active sales orders. */
class MrpReplanRequested
{
    use Dispatchable;

    /** @param list<int> $salesOrderIds */
    public function __construct(
        public readonly array $salesOrderIds,
        public readonly string $reason,
    ) {}
}
