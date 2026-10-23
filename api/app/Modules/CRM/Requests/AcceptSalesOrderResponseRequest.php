<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptSalesOrderResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->hasPermission('crm.sales_orders.confirm') ?? false)
            || ($this->user()?->hasPermission('crm.sales_orders.cancel') ?? false);
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
