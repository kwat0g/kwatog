<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.adjust') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'item_id'          => Item::class,
            'from_location_id' => WarehouseLocation::class,
            'to_location_id'   => WarehouseLocation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'item_id'          => ['required', 'integer', 'exists:items,id'],
            'from_location_id' => ['required', 'integer', 'exists:warehouse_locations,id', 'different:to_location_id'],
            'to_location_id'   => ['required', 'integer', 'exists:warehouse_locations,id'],
            'quantity'         => ['required', 'decimal:0,3', 'min:0.001'],
            'remarks'          => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_location_id.different' => 'Source and destination locations must differ.',
        ];
    }
}
