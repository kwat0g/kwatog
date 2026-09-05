<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('supplier_portal') !== null;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('item_id');
        if (is_string($raw)) {
            $this->merge(['item_id' => HashIdFilter::decode($raw, Item::class)]);
        }
    }

    public function rules(): array
    {
        return [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('items', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'supplier_item_code' => ['nullable', 'string', 'max:100'],
            'supplier_item_name' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999999.99'],
            'order_uom' => ['nullable', 'string', 'max:20'],
            'base_qty_per_order_unit' => ['required_with:order_uom', 'nullable', 'numeric', 'min:0.0001', 'max:1000000'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.exists' => 'Selected item does not exist or is inactive.',
            'base_qty_per_order_unit.required_with' => 'Conversion quantity is required when an order unit is provided.',
            'valid_until.after_or_equal' => 'Price validity date cannot be in the past.',
        ];
    }
}
