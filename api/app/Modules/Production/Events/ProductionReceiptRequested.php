<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Narrow, durable recovery request for one production-output receipt.
 *
 * WorkOrderOutputRecorded remains a broadcast event for live dashboards. This
 * event carries only the failed cross-module handoff so replay cannot repeat
 * unrelated output notifications.
 */
class ProductionReceiptRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkOrderOutput $output,
        public readonly string $reasonCode = 'automatic_production_receipt_failed',
    ) {}
}
