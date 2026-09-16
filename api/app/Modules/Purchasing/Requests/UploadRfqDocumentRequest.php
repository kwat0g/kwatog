<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;

final class UploadRfqDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $vendor = $this->input('vendor_id');
        if ($vendor !== null && $vendor !== '') {
            $this->merge(['vendor_id' => HashIdFilter::decode((string) $vendor, Vendor::class) ?? (int) $vendor]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'document_type' => ['required', 'in:requirement_document,quotation_pdf,resin_datasheet,certificate_of_analysis,safety_document,compliance_document'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
        ];
    }
}
