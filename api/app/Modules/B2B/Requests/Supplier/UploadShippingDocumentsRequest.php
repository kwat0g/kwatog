<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Modules\B2B\Enums\SupplierShippingDocumentType;
use Illuminate\Validation\Rule;

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
            'document_type' => ['required', Rule::enum(SupplierShippingDocumentType::class)],
            'file'          => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ];
    }
}
