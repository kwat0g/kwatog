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
        public readonly ?int $fromLocationId = null,
        public readonly ?int $toLocationId = null,
        public readonly string $quantity,        // positive decimal string
        public readonly ?string $unitCost = null,       // null for issues (use current WAC)
        public readonly ?string $referenceType = null,
        public readonly ?int $referenceId = null,
        public readonly ?string $remarks = null,
        public readonly ?int $createdBy = null,
    ) {}
}
