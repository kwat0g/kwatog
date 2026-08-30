<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\CreditNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditNoteRequest extends FormRequest
{
    /** `credit_note_lines.amount` is decimal(15,2). */
    public const MAX_LINE_AMOUNT = '9999999999999.99';

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.credit_notes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'type'                 => ['required', Rule::in(CreditNoteType::values())],
            'date'                 => ['required', 'date'],
            'is_vatable'           => ['nullable', 'boolean'],
            'reason'               => ['nullable', 'string', 'max:1000'],
            // Typed so an array payload can't reach CreditNoteService::decode(),
            // whose (string) cast would TypeError into a 500. HashIDs resolved there.
            'customer_id'          => ['nullable', 'string'],
            'vendor_id'            => ['nullable', 'string'],
            'invoice_id'           => ['nullable', 'string'],
            'bill_id'              => ['nullable', 'string'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.account_id'   => ['required', 'string'],
            'lines.*.description'  => ['required', 'string', 'max:200'],
            // Centavo contract — see StoreInvoiceRequest for the three measured
            // behaviours bare 'numeric' left in place. CreditNoteService builds
            // its own GL lines and calls JournalEntryService::create() directly,
            // so the StoreJournalEntryRequest hardening never covered this path.
            'lines.*.amount'       => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_LINE_AMOUNT],
        ];
    }
}
