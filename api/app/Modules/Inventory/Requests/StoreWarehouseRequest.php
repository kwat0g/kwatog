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
            'name'      => ['required', 'string', 'max:100'],
            'code'      => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')->ignore($id)],
            'address'   => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
