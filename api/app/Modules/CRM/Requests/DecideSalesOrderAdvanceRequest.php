<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideSalesOrderAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.invoices.collect') ?? false;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'reference_number' => ['nullable', 'string', 'max:120'],
        ];
    }
}
