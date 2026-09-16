<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Durable lifecycle signal for RFQ portal, email, and internal inbox delivery.
 * The kind is an allow-listed business value, not a class name from storage.
 */
final class RfqLifecycleEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $rfqId,
        public readonly string $rfqHashId,
        public readonly string $kind,
        public readonly ?int $purchaseOrderId = null,
    ) {}
}
