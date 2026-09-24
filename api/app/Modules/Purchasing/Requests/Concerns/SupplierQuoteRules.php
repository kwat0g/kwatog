<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests\Concerns;

use App\Common\Support\HashIdFilter;
use App\Modules\Purchasing\Enums\VatTreatment;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use Illuminate\Validation\Rule;

/**
 * The quotation body shared by the supplier portal and the buyer's manual
 * capture, so both channels validate a quote identically.
 */
trait SupplierQuoteRules
{
    protected function decodeQuoteItems(): void
    {
        $items = [];
        foreach ((array) $this->input('items', []) as $row) {
            $id = HashIdFilter::decode((string) ($row['request_for_quote_item_id'] ?? ''), RequestForQuoteItem::class)
                ?? (int) ($row['request_for_quote_item_id'] ?? 0);
            $items[] = [...(array) $row, 'request_for_quote_item_id' => $id];
        }
        $this->merge(['items' => $items]);
    }

    /** @return array<string, mixed> */
    protected function quoteRules(): array
    {
        return [
            'vat_treatment' => ['required', Rule::enum(VatTreatment::class)],
            'freight_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'quote_valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.request_for_quote_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.response_status' => ['required', 'in:quoted,no_quote'],
            // Purchase-order quantities are 3 dp; an award carries the offer verbatim.
            'items.*.offered_quantity' => ['nullable', 'required_if:items.*.response_status,quoted', 'decimal:0,3', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'required_if:items.*.response_status,quoted', 'decimal:0,4', 'gt:0'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items.*.proposed_delivery_date' => ['nullable', 'date'],
        ];
    }
}
