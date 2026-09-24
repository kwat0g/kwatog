<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use Illuminate\Foundation\Http\FormRequest;

class AwardRfqRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $lines = [];
        foreach ((array) $this->input('lines', []) as $row) {
            $lines[] = [
                'request_for_quote_item_id' => HashIdFilter::decode((string) ($row['request_for_quote_item_id'] ?? ''), RequestForQuoteItem::class) ?? 0,
                'supplier_quote_item_id' => HashIdFilter::decode((string) ($row['supplier_quote_item_id'] ?? ''), SupplierQuoteItem::class) ?? 0,
            ];
        }
        $this->merge(['lines' => $lines]);
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'award_reason' => ['required', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.request_for_quote_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.supplier_quote_item_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Choose a supplier for at least one line, or cancel the RFQ.',
            'lines.*.request_for_quote_item_id.distinct' => 'Choose one supplier per line.',
        ];
    }
}
