<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.warehouse.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('location')?->id;
        return [
            'zone_id'   => ['required', 'integer', 'exists:warehouse_zones,id'],
            'code'      => ['required', 'string', 'max:20', Rule::unique('warehouse_locations', 'code')->ignore($id)],
            'rack'      => ['nullable', 'string', 'max:10'],
            'bin'       => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
