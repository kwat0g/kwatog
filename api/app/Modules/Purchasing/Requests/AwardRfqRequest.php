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
        $awards = [];
        foreach ((array) $this->input('awards', []) as $row) {
            $rfqLine = HashIdFilter::decode((string) ($row['request_for_quote_item_id'] ?? ''), RequestForQuoteItem::class) ?? (int) ($row['request_for_quote_item_id'] ?? 0);
            $quoteLine = HashIdFilter::decode((string) ($row['supplier_quote_item_id'] ?? ''), SupplierQuoteItem::class) ?? (int) ($row['supplier_quote_item_id'] ?? 0);
            $awards[] = [...$row, 'request_for_quote_item_id' => $rfqLine, 'supplier_quote_item_id' => $quoteLine];
        }
        $this->merge(['awards' => $awards]);
    }

    public function authorize(): bool { return $this->user()?->hasPermission('purchasing.rfq.award') ?? false; }
    public function rules(): array
    {
        return [
            'awards' => ['array'],
            'awards.*.request_for_quote_item_id' => ['required', 'integer'],
            'awards.*.supplier_quote_item_id' => ['required', 'integer'],
            'awards.*.awarded_quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'awards.*.award_reason' => ['required', 'string', 'max:4000'],
            'awards.*.single_response_justification' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
