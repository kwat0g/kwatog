<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

/** Durable refresh request. The handler always re-reads current master data. */
class BomPricingReviewRequested
{
    /** @param list<int> $productIds */
    public function __construct(public readonly array $productIds, public readonly string $reason) {}
}
