<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'expected_revision' => ['required', 'string', 'size:64'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.invoices.update') ?? false;
    }
}
