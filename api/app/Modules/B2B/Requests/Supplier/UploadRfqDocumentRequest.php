<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Modules\Purchasing\Services\SupplierQuoteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadRfqDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('supplier_portal')->check();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'document_type' => ['required', Rule::in(SupplierQuoteService::SUPPLIER_DOCUMENT_TYPES)],
        ];
    }
}
