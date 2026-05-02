<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\ReorderMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.items.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code'                   => ['required', 'string', 'regex:/^[A-Z0-9-]{2,30}$/', 'unique:items,code'],
            'name'                   => ['required', 'string', 'max:200'],
            'description'            => ['nullable', 'string', 'max:1000'],
            'category_id'            => ['required', 'integer', 'exists:item_categories,id'],
            'item_type'              => ['required', Rule::in(ItemType::values())],
            'unit_of_measure'        => ['required', 'string', 'max:20'],
            'standard_cost'          => ['required', 'decimal:0,4', 'min:0'],
            'reorder_method'         => ['required', Rule::in(ReorderMethod::values())],
            'reorder_point'          => ['required', 'decimal:0,3', 'min:0'],
            'safety_stock'           => ['required', 'decimal:0,3', 'min:0'],
            'minimum_order_quantity' => ['nullable', 'decimal:0,3', 'min:0.001'],
            'lead_time_days'         => ['required', 'integer', 'min:0', 'max:365'],
            'is_critical'            => ['nullable', 'boolean'],
            'is_active'              => ['nullable', 'boolean'],
        ];
    }
}
