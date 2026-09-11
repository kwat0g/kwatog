<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use App\Modules\SupplyChain\Enums\Incoterm;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'vendor_id'           => Vendor::class,
            'items.*.item_id'     => Item::class,
            'items.*.purchase_request_item_id' => PurchaseRequestItem::class,
            'purchase_request_id' => PurchaseRequest::class,
        ];
    }

    public function rules(): array
    {
        return [
            'vendor_id'              => ['required', 'integer', 'exists:vendors,id'],
            // POs must originate from an approved PR (PR → approved → PO).
            // The approved-status gate itself lives in PurchaseOrderService::create().
            'purchase_request_id'    => ['required', 'integer', 'exists:purchase_requests,id'],
            'date'                   => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:date'],
            'is_vatable'             => ['nullable', 'boolean'],
            'incoterm'               => ['nullable', Rule::enum(Incoterm::class)],
            'remarks'                => ['nullable', 'string', 'max:1000'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.item_id'        => ['required', 'integer', 'exists:items,id'],
            'items.*.purchase_request_item_id' => ['nullable', 'integer', 'exists:purchase_request_items,id'],
            'items.*.description'    => ['required', 'string', 'min:2', 'max:200'],
            // `decimal:0,2` already refuses 1.999 and 1e3. What was missing is an
            // upper bound: quantity/unit_price land in decimal(15,2) columns and
            // feed Money::mul() into decimal(15,2) line and header totals, so an
            // in-range-looking 1e17 reached PostgreSQL and returned SQLSTATE[22003]
            // as a 500. 10^13 is the real ceiling for decimal(15,2); the line
            // total is a product of two of these, so each factor is capped an
            // order below the column ceiling to keep quantity x unit_price inside it.
            'items.*.quantity'       => ['required', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'items.*.unit'           => ['nullable', 'string', 'max:20'],
            // Unit price must be > 0: every automatic path (auto-PO, conversion,
            // consolidation) refuses a zero price, so the manual form refusing it
            // too keeps the two consistent and stops a free line from becoming a
            // zero-value PO/GRN/bill downstream.
            'items.*.unit_price'     => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'A purchase order must have at least one line.',
            'purchase_request_id.required' => 'A purchase order must be created from an approved purchase request (PR).',
        ];
    }
}
