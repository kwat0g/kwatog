<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use App\Modules\Purchasing\Models\RfqDocument;
use Illuminate\Foundation\Http\FormRequest;

final class ManualCaptureRfqQuoteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $vendor = $this->input('vendor_id');
        $document = $this->input('quotation_document_id');
        $items = [];
        foreach ((array) $this->input('items', []) as $row) {
            $id = HashIdFilter::decode((string) ($row['request_for_quote_item_id'] ?? ''), RequestForQuoteItem::class) ?? (int) ($row['request_for_quote_item_id'] ?? 0);
            $items[] = [...$row, 'request_for_quote_item_id' => $id];
        }
        $this->merge([
            'vendor_id' => $vendor !== null ? (HashIdFilter::decode((string) $vendor, Vendor::class) ?? (int) $vendor) : null,
            'quotation_document_id' => $document !== null ? (HashIdFilter::decode((string) $document, RfqDocument::class) ?? (int) $document) : null,
            'items' => $items,
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'quotation_document_id' => ['required', 'integer', 'exists:rfq_documents,id'],
            'vat_inclusive' => ['boolean'],
            'vat_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'freight_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'other_charges' => ['nullable', 'decimal:0,2', 'min:0'],
            'quote_valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.request_for_quote_item_id' => ['required', 'integer', 'distinct'],
            'items.*.response_status' => ['required', 'in:quoted,no_quote'],
            'items.*.offered_quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'decimal:0,4', 'gt:0'],
            'items.*.line_vat_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.line_freight_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.line_other_charges' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'items.*.proposed_delivery_date' => ['nullable', 'date'],
        ];
    }
}
