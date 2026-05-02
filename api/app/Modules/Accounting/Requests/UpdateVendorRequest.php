<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.vendors.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'              => ['sometimes', 'string', 'max:200'],
            'contact_person'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'             => ['sometimes', 'nullable', 'email', 'max:200'],
            'phone'             => ['sometimes', 'nullable', 'string', 'max:20'],
            'address'           => ['sometimes', 'nullable', 'string', 'max:500'],
            'tin'               => ['sometimes', 'nullable', 'string', 'max:20'],
            'payment_terms_days'=> ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active'         => ['sometimes', 'boolean'],
        ];
    }
}
