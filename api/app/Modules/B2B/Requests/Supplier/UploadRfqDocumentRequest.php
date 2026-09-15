<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class UploadRfqDocumentRequest extends FormRequest
{
    public function authorize(): bool { return auth('supplier_portal')->check(); }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'document_type' => ['required', 'in:quotation_pdf,resin_datasheet,certificate_of_analysis,safety_document,compliance_document'],
            'quote_id' => ['nullable', 'string'],
        ];
    }
}
