<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.adjust') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'item_id'     => Item::class,
            'location_id' => WarehouseLocation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'item_id'     => ['required', 'integer', 'exists:items,id'],
            'location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'direction'   => ['required', Rule::in(['in', 'out'])],
            'quantity'    => ['required', 'decimal:0,3', 'min:0.001'],
            'unit_cost'   => ['nullable', 'decimal:0,4', 'min:0'],
            'reason'      => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Please provide a reason of at least 10 characters (audit trail).',
        ];
    }
}
