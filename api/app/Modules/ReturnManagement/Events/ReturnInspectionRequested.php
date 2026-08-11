<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Events;

use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Narrow recovery request for one RMA → Quality inspection handoff. */
class ReturnInspectionRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ReturnRequest $returnRequest,
        public readonly string $reasonCode = 'return_inspection_manual_required',
    ) {}
}
