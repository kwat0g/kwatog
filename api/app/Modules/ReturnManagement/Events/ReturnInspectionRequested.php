<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Events;

use App\Common\Events\ToleratesNewerModelState;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Narrow recovery request for one RMA → Quality inspection handoff.
 *
 * The receipt, the inspection retry and later operator actions all write to the
 * same RMA row. Its listener re-reads the current row and guards (status must
 * still be received/inspected, inspections are reused per product), so an RMA
 * that moved on after publication must not fail the request forever.
 */
class ReturnInspectionRequested implements ToleratesNewerModelState
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ReturnRequest $returnRequest,
        public readonly string $reasonCode = 'return_inspection_manual_required',
    ) {}
}
