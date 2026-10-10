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
        $isDispatchPhoto = $this->input('document_type') === SupplierShippingDocumentType::DispatchPhoto->value;

        return [
            'document_type' => ['required', Rule::enum(SupplierShippingDocumentType::class)],
            'supplier_shipment_id' => [$isDispatchPhoto ? 'required' : 'nullable', 'string'],
            'file'          => ['required', 'file', 'max:10240', $isDispatchPhoto ? 'mimes:jpg,jpeg,png,webp' : 'mimes:pdf,jpg,jpeg,png'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ];
    }
}
