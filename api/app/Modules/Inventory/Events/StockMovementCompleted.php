<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;

class StockMovementCompleted
{
    use Dispatchable;

    public function __construct(public readonly StockMovement $movement) {}
}
