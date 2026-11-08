<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Durable outbox signal for reserved-quantity changes without a ledger movement. */
final class StockReservationChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $itemId,
        public readonly int $locationId,
        public readonly string $action,
    ) {}
}
