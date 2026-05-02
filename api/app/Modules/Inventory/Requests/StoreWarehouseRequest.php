<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.warehouse.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('warehouse')?->id;
        return [
            'name'      => ['required', 'string', 'min:2', 'max:100'],
            'code'      => ['required', 'string', 'min:1', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('warehouses', 'code')->ignore($id)],
            'address'   => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Warehouse code must use uppercase letters, digits, or hyphens only.',
        ];
    }
}
