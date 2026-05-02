<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreGrnRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.grn.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'purchase_order_id'              => PurchaseOrder::class,
            'items.*.purchase_order_item_id' => PurchaseOrderItem::class,
            'items.*.item_id'                => Item::class,
            'items.*.location_id'            => WarehouseLocation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'purchase_order_id'              => ['required', 'integer', 'exists:purchase_orders,id'],
            'received_date'                  => ['nullable', 'date'],
            'remarks'                        => ['nullable', 'string', 'max:1000'],
            'items'                          => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.item_id'                => ['required', 'integer', 'exists:items,id'],
            'items.*.location_id'            => ['required', 'integer', 'exists:warehouse_locations,id'],
            'items.*.quantity_received'      => ['required', 'decimal:0,3', 'min:0.001'],
            'items.*.unit_cost'              => ['nullable', 'decimal:0,4', 'min:0'],
            'items.*.remarks'                => ['nullable', 'string', 'max:200'],
        ];
    }
}
