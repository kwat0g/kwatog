<?php

declare(strict_types=1);

namespace App\Modules\B2B\Events;

use App\Modules\Accounting\Models\Bill;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierInvoiceSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Bill $bill) {}
}
