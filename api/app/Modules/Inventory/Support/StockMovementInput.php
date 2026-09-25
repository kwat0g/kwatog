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
        public readonly string $quantity,        // positive decimal string (required)
        public readonly ?int $fromLocationId = null,
        public readonly ?int $toLocationId = null,
        public readonly ?string $unitCost = null,       // null for issues (use current WAC)
        public readonly ?string $referenceType = null,
        public readonly ?int $referenceId = null,
        public readonly ?string $remarks = null,
        public readonly ?int $createdBy = null,
        public readonly bool $bypassCountFreeze = false,
        public readonly ?int $expectedFromVersion = null,
        public readonly ?int $expectedToVersion = null,
        public readonly ?string $lotNumber = null,
        public readonly ?string $expiryDate = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $idempotencyFingerprint = null,
        public readonly ?string $totalCostOverride = null,
        /** Exact durable delivery allocation consumed by a dispatch issue. */
        public readonly ?int $deliveryStockReservationId = null,
        /** Exact truck-return RRI authorized for a DeliveryReturn receipt. */
        public readonly ?int $deliveryReturnItemId = null,
        /** Exact ordinary customer-return RRI authorized for a customer receipt. */
        public readonly ?int $customerReturnItemId = null,
    ) {}
}
