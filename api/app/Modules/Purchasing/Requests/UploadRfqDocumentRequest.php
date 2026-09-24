<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadRfqDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $vendor = $this->input('vendor_id');
        if ($vendor !== null && $vendor !== '') {
            $this->merge(['vendor_id' => HashIdFilter::decode((string) $vendor, Vendor::class) ?? 0]);
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
            'document_type' => ['required', Rule::in(RequestForQuoteService::INTERNAL_DOCUMENT_TYPES)],
            'vendor_id' => ['nullable', 'required_unless:document_type,requirement_document', 'integer', 'exists:vendors,id'],
        ];
    }
}
