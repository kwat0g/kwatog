<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\CreditNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditNoteRequest extends FormRequest
{
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
            'lines.*.amount'       => ['required', 'numeric', 'gt:0'],
        ];
    }
}
