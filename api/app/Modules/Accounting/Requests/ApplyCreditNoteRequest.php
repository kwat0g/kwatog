<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.credit_notes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount'     => ['required', 'numeric', 'gt:0'],
            // Typed so an array payload can't reach CreditNoteService::decode(),
            // whose (string) cast would TypeError into a 500. HashIDs resolved there.
            'invoice_id' => ['nullable', 'string'],
            'bill_id'    => ['nullable', 'string'],
        ];
    }
}
