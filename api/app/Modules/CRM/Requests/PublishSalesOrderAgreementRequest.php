<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishSalesOrderAgreementRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('crm.sales_orders.update') ?? false; }

    public function rules(): array
    {
        return ['terms' => ['required', 'string', 'max:10000', 'regex:/\S/'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']];
    }
}
