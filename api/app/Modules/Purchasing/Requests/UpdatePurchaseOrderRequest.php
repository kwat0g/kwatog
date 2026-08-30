<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use App\Modules\SupplyChain\Enums\Incoterm;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'items.*.item_id' => Item::class,
            'items.*.purchase_request_item_id' => PurchaseRequestItem::class,
        ];
    }

    public function rules(): array
    {
        return [
            'date'                   => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'is_vatable'             => ['nullable', 'boolean'],
            'incoterm'               => ['nullable', Rule::enum(Incoterm::class)],
            'remarks'                => ['nullable', 'string', 'max:1000'],
            'items'                  => ['nullable', 'array', 'min:1'],
            'items.*.item_id'        => ['required_with:items', 'integer', 'exists:items,id'],
            'items.*.purchase_request_item_id' => ['nullable', 'integer', 'exists:purchase_request_items,id'],
            'items.*.description'    => ['required_with:items', 'string', 'min:2', 'max:200'],
            // Bounds must match StorePurchaseOrderRequest: update() re-runs
            // normalizeLines() and rewrites the same decimal(15,2) columns, so an
            // unbounded amend reached PostgreSQL as SQLSTATE[22003] / 500 exactly
            // as create did.
            'items.*.quantity'       => ['required_with:items', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'items.*.unit'           => ['nullable', 'string', 'max:20'],
            'items.*.unit_price'     => ['required_with:items', 'decimal:0,2', 'min:0', 'max:9999999.99'],
        ];
    }
}
