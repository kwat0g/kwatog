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
            'customer_id'          => ['nullable'],
            'vendor_id'            => ['nullable'],
            'invoice_id'           => ['nullable'],
            'bill_id'              => ['nullable'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.account_id'   => ['required'],
            'lines.*.description'  => ['required', 'string', 'max:200'],
            'lines.*.amount'       => ['required', 'numeric', 'gt:0'],
        ];
    }
}
