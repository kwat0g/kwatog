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
            'bill_number' => ['required', 'string', 'max:50'],
            'date'        => ['required', 'date'],
            'due_date'    => ['nullable', 'date', 'after_or_equal:date'],
            'is_vatable'  => ['nullable', 'boolean'],
            'remarks'     => ['nullable', 'string', 'max:1000'],
            'file'        => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }
}
