<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectSupplierListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.supplier_listings.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required so the supplier knows what to correct.',
        ];
    }
}
