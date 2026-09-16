<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Common\Support\HashIdFilter;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierQuoteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items = [];
        foreach ((array) $this->input('items', []) as $row) {
            $id = HashIdFilter::decode((string) ($row['request_for_quote_item_id'] ?? ''), RequestForQuoteItem::class) ?? (int) ($row['request_for_quote_item_id'] ?? 0);
            $items[] = [...$row, 'request_for_quote_item_id' => $id];
        }
        $this->merge(['items' => $items]);
    }

    public function authorize(): bool
    {
        return auth('supplier_portal')->check();
    }

    public function rules(): array
    {
        return [
            'vat_inclusive' => ['boolean'], 'vat_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'freight_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'other_charges' => ['nullable', 'decimal:0,2', 'min:0'], 'quote_valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.request_for_quote_item_id' => ['required', 'integer', 'distinct'],
            'items.*.response_status' => ['required', 'in:quoted,no_quote'],
            'items.*.offered_quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'decimal:0,4', 'gt:0'],
            'items.*.line_vat_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.line_freight_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.line_other_charges' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'min:0'], 'items.*.proposed_delivery_date' => ['nullable', 'date'],
            'items.*.compliance_status' => ['nullable', 'in:pending,compliant,exception,blocking'],
            'items.*.compliance_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
