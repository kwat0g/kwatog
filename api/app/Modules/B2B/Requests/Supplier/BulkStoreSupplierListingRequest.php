<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk variant of {@see StoreSupplierListingRequest}. The portal UI submits a
 * multi-select of catalog items under shared commercial terms, but the API
 * accepts a full per-row payload so callers may vary every field.
 */
class BulkStoreSupplierListingRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user('supplier_portal') !== null;
    }

    protected function hashIdFields(): array
    {
        return [
            'items.*.item_id' => Item::class,
        ];
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => [
                'required',
                'integer',
                Rule::exists('items', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'items.*.supplier_item_code' => ['nullable', 'string', 'max:100'],
            'items.*.supplier_item_name' => ['nullable', 'string', 'max:255'],
            'items.*.price' => ['required', 'numeric', 'min:0.01', 'max:99999999999.99'],
            'items.*.order_uom' => ['nullable', 'string', 'max:20'],
            'items.*.base_qty_per_order_unit' => ['required_with:items.*.order_uom', 'nullable', 'numeric', 'min:0.0001', 'max:1000000'],
            'items.*.lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'items.*.valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Select at least one item to submit.',
            'items.max' => 'Submit at most 200 items at a time.',
            'items.*.item_id.exists' => 'One of the selected items does not exist or is inactive.',
            'items.*.base_qty_per_order_unit.required_with' => 'Conversion quantity is required when an order unit is provided.',
            'items.*.valid_until.after_or_equal' => 'Price validity date cannot be in the past.',
        ];
    }
}
