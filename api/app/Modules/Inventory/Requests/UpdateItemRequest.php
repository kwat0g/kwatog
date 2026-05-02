<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\ReorderMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.items.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('item')?->id;
        return [
            'code'                   => ['sometimes', 'required', 'string', 'regex:/^[A-Z0-9-]{2,30}$/',
                                          Rule::unique('items', 'code')->ignore($id)],
            'name'                   => ['sometimes', 'required', 'string', 'max:200'],
            'description'            => ['nullable', 'string', 'max:1000'],
            'category_id'            => ['sometimes', 'required', 'integer', 'exists:item_categories,id'],
            'item_type'              => ['sometimes', 'required', Rule::in(ItemType::values())],
            'unit_of_measure'        => ['sometimes', 'required', 'string', 'max:20'],
            'standard_cost'          => ['sometimes', 'required', 'decimal:0,4', 'min:0'],
            'reorder_method'         => ['sometimes', 'required', Rule::in(ReorderMethod::values())],
            'reorder_point'          => ['sometimes', 'required', 'decimal:0,3', 'min:0'],
            'safety_stock'           => ['sometimes', 'required', 'decimal:0,3', 'min:0'],
            'minimum_order_quantity' => ['nullable', 'decimal:0,3', 'min:0.001'],
            'lead_time_days'         => ['sometimes', 'required', 'integer', 'min:0', 'max:365'],
            'is_critical'            => ['nullable', 'boolean'],
            'is_active'              => ['nullable', 'boolean'],
        ];
    }
}
