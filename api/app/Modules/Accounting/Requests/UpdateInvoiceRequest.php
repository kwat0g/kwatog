<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.invoices.update') ?? false;
    }
}
