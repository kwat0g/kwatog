<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Common\Support\HashIdFilter;
use App\Modules\Purchasing\Models\SupplierQuote;
use Illuminate\Foundation\Http\FormRequest;

class UploadRfqDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('supplier_portal')->check();
    }

    protected function prepareForValidation(): void
    {
        $quoteId = $this->input('quote_id');
        if ($quoteId !== null && $quoteId !== '') {
            $this->merge(['quote_id' => HashIdFilter::decode((string) $quoteId, SupplierQuote::class) ?? (int) $quoteId]);
        }
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'document_type' => ['required', 'in:quotation_pdf,resin_datasheet,certificate_of_analysis,safety_document,compliance_document'],
            'quote_id' => ['required_if:document_type,quotation_pdf', 'nullable', 'integer'],
        ];
    }
}
