<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductSellingPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.products.prices.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'base_selling_price' => ['present', 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'minimum_selling_price' => ['present', 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
        ];
    }
}
