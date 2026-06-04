<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class UploadShippingDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:commercial_invoice,packing_list,bill_of_lading,other'],
            'file'          => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ];
    }
}
