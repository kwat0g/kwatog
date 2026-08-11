<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Contracts;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\SupplierDispatchResult;

/**
 * Boundary for transmitting an approved purchase order to its supplier.
 *
 * Implementations must be idempotent for the supplied key. A gateway may
 * publish the PO to a pull-based portal or return an actionable manual step;
 * it must not claim that a human transmission occurred unless it can prove
 * that through its provider contract.
 */
interface SupplierDispatchGateway
{
    public function publish(PurchaseOrder $purchaseOrder, string $idempotencyKey): SupplierDispatchResult;
}
