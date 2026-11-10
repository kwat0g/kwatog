<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureSalesOrderAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.sales_orders.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'down_payment_amount' => ['nullable', 'decimal:0,2', 'gt:0'],
            'down_payment_percentage' => ['nullable', 'decimal:0,2', 'gt:0', 'lte:100'],
        ];
    }
}
