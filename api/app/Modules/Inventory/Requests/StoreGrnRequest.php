<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Soft-deleted parents must fail validation, not reach the service.
            // A bare `exists:purchase_orders,id` ignores `deleted_at`, and the
            // service then dereferences a trashed row — measured as a 500 for
            // both an archived PO and an archived item.
            'purchase_order_id'              => [
                'required',
                'integer',
                Rule::exists('purchase_orders', 'id')->whereNull('deleted_at'),
            ],
            'received_date'                  => ['nullable', 'date'],
            'remarks'                        => ['nullable', 'string', 'max:1000'],
            'items'                          => ['required', 'array', 'min:1'],
            // purchase_order_items has no SoftDeletes — a plain exists is right.
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.item_id'                => [
                'required',
                'integer',
                Rule::exists('items', 'id')->whereNull('deleted_at'),
            ],
            // The boolean predicates MUST go through the closure form.
            // `Rule::exists()->where($col, false)` is string-serialised by
            // DatabaseRule::formatWheres() — `(string) false` is '' — so
            // PostgreSQL received `is_blocked = ''` and answered
            // `22P02 invalid input syntax for type boolean: ""`, making this
            // endpoint return 500 for EVERY request. ValidationRuleParser
            // ::prepareRule() keeps an Exists object unserialised only when
            // queryCallbacks() is non-empty, which is what a closure creates.
            'items.*.location_id'            => [
                'required',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->where('is_blocked', false)),
            ],
            // Upper bounds match the columns: grn_items.quantity_received is
            // numeric(15,3) and unit_cost numeric(15,4). Without them an
            // in-range-looking decimal overflows into a 22003 500.
            'items.*.quantity_received'      => ['required', 'decimal:0,3', 'min:0.001', 'max:999999999999.999'],
            'items.*.unit_cost'              => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999.9999'],
            'items.*.received_uom_code'      => ['nullable', 'string', 'max:20'],
            'items.*.lot_number'             => ['nullable', 'string', 'max:50'],
            'items.*.material_lot_number'    => ['nullable', 'string', 'max:50'],
            'items.*.supplier_lot_reference' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date'            => ['nullable', 'date'],
            'items.*.moisture_percentage'   => ['nullable', 'decimal:0,3', 'min:0', 'max:100'],
            'items.*.coa_document_path'     => ['nullable', 'string', 'max:500'],
            'items.*.coa_verified'          => ['prohibited'],
            'items.*.remarks'                => ['nullable', 'string', 'max:200'],
        ];
    }
}
