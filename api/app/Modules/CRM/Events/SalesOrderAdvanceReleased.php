<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Modules\CRM\Models\SalesOrder;

/** Durable signal that the order may enter MRP/production planning. */
final class SalesOrderAdvanceReleased
{
    public function __construct(public SalesOrder $salesOrder) {}
}
