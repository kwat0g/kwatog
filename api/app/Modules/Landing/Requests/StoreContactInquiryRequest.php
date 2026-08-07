<?php

declare(strict_types=1);

namespace App\Modules\Landing\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public, unauthenticated by design — this is a marketing contact form.
 * Abuse is handled by `throttle:public-form` on the route plus the length caps
 * here, not by an auth check.
 */
class StoreContactInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            // Optional: job seekers and general enquiries have no company, and
            // requiring one only teaches people to type a placeholder.
            'company'   => ['nullable', 'string', 'max:150'],
            'email'     => ['required', 'string', 'email', 'max:150'],
            'phone'     => ['nullable', 'string', 'max:40'],
            'message'   => ['required', 'string', 'max:2000'],
        ];
    }
}
