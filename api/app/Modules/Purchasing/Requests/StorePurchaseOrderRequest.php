<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseRequest;
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
            'items.*.description'    => ['required', 'string', 'min:2', 'max:200'],
            'items.*.quantity'       => ['required', 'decimal:0,2', 'min:0.01'],
            'items.*.unit'           => ['nullable', 'string', 'max:20'],
            'items.*.unit_price'     => ['required', 'decimal:0,2', 'min:0'],
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
