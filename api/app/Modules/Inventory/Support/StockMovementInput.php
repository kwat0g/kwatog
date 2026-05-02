<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\StockMovementType;

/**
 * Immutable input value object for StockMovementService::move().
 */
final class StockMovementInput
{
    public function __construct(
        public readonly StockMovementType $type,
        public readonly int $itemId,
        public readonly ?int $fromLocationId,
        public readonly ?int $toLocationId,
        public readonly string $quantity,        // positive decimal string
        public readonly ?string $unitCost,       // null for issues (use current WAC)
        public readonly ?string $referenceType,
        public readonly ?int $referenceId,
        public readonly ?string $remarks,
        public readonly ?int $createdBy,
    ) {}
}
