<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Narrow recovery request for one stock-movement → GL handoff. */
class StockMovementGlPostingRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly StockMovement $movement,
        public readonly string $reasonCode = 'movement_gl_posting_manual_required',
    ) {}
}
