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
            'invoice_id' => ['nullable'],
            'bill_id'    => ['nullable'],
        ];
    }
}
