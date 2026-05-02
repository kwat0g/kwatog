<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Enums\WarehouseZoneType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.warehouse.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'name'         => ['required', 'string', 'max:50'],
            'code'         => ['required', 'string', 'max:10'],
            'zone_type'    => ['required', Rule::in(WarehouseZoneType::values())],
        ];
    }
}
