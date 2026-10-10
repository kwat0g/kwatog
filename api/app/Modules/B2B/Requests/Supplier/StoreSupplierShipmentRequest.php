<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipped_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'estimated_arrival' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'return_request_id' => ['nullable', 'string'],
            'dispatch_photo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'string'],
            'items.*.quantity_shipped' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }
}
