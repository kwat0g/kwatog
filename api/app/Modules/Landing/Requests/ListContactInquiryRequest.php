<?php

declare(strict_types=1);

namespace App\Modules\Landing\Requests;

use App\Modules\Landing\Enums\ContactInquiryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListContactInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.inquiries.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_map(
                    static fn (ContactInquiryStatus $status): string => $status->value,
                    ContactInquiryStatus::cases(),
                )),
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // The service keeps the existing lower-bound clamp for legacy
            // callers; the request still rejects arrays and caps the upper
            // bound before anything reaches the query builder.
            'per_page' => ['sometimes', 'integer', 'max:100'],
        ];
    }
}
