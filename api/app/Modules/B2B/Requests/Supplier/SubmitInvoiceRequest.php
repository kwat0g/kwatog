<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class SubmitInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bill_number'          => ['required', 'string', 'max:50'],
            'date'                 => ['required', 'date', 'before_or_equal:today'],
            'goods_receipt_note_id' => ['nullable', 'string'],
            'bill_ids'             => ['required_with:gross_total', 'array', 'min:1'],
            'bill_ids.*'           => ['required', 'string', 'distinct'],
            'gross_total'          => ['required_with:bill_ids', 'regex:/^\d+(\.\d{1,2})?$/'],
            'remarks'              => ['nullable', 'string', 'max:1000'],
            'file'                 => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }
}
