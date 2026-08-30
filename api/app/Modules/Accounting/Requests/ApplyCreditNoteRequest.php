<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCreditNoteRequest extends FormRequest
{
    /** `credit_note_applications.amount` is decimal(15,2). */
    public const MAX_AMOUNT = '9999999999999.99';

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.credit_notes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            // Centavo contract — see StoreInvoiceRequest. An application amount
            // reaches Money::round2() and then the invoice/bill balance
            // comparison, so bare 'numeric' let '1e3' raise a BCMath ValueError
            // (500) and an unbounded value overflow decimal(15,2) (500).
            'amount'     => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            // Typed so an array payload can't reach CreditNoteService::decode(),
            // whose (string) cast would TypeError into a 500. HashIDs resolved there.
            'invoice_id' => ['nullable', 'string'],
            'bill_id'    => ['nullable', 'string'],
        ];
    }
}
