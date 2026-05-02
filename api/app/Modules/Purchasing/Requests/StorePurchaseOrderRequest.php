<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'vendor_id'              => ['required'],
            'date'                   => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'is_vatable'             => ['nullable', 'boolean'],
            'remarks'                => ['nullable', 'string', 'max:1000'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.item_id'        => ['required'],
            'items.*.description'    => ['required', 'string', 'max:200'],
            'items.*.quantity'       => ['required', 'decimal:0,2', 'min:0.01'],
            'items.*.unit'           => ['nullable', 'string', 'max:20'],
            'items.*.unit_price'     => ['required', 'decimal:0,2', 'min:0'],
        ];
    }
}
